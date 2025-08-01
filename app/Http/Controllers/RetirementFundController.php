<?php
namespace Muserpol\Http\Controllers;

use Illuminate\Http\Request;
use Muserpol\Models\Affiliate;
use Muserpol\Models\ProcedureRequirement;
use Muserpol\Models\ProcedureModality;
use Muserpol\Models\Kinship;
use Muserpol\Models\City;
use Muserpol\Models\RetirementFund\RetirementFund;
use Muserpol\Models\RetirementFund\RetFunSubmittedDocument;
use Muserpol\Models\RetirementFund\RetFunBeneficiary;
use Muserpol\Models\RetirementFund\RetFunAdvisor;
use Auth;
use Log;
use Validator;
use Muserpol\Models\Address;
use Muserpol\Models\Spouse;
use Muserpol\Models\ObservationType;
use Muserpol\Models\RetirementFund\RetFunLegalGuardian;
use Muserpol\Models\RetirementFund\RetFunAdvisorBeneficiary;
use Muserpol\Models\RetirementFund\RetFunLegalGuardianBeneficiary;
use DateTime;
use Muserpol\User;
use Carbon\Carbon;
use Yajra\Datatables\DataTables;
use Muserpol\Models\RetirementFund\RetFunIncrement;
use Session;
use Muserpol\Helpers\Util;
use Illuminate\Auth\EloquentUserProvider;
use Muserpol\Models\RetirementFund\RetFunProcedure;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Support\Facades\Redirect;
use Muserpol\Models\DiscountType;
use Muserpol\Models\ProcedureType;
use Muserpol\Models\RetirementFund\RetFunState;
use Muserpol\Models\RetirementFund\RetFunRecord;
use DB;
use Muserpol\Models\Workflow\WorkflowState;
use Muserpol\Models\Role;
use Muserpol\Models\Workflow\WorkflowRecord;
use Muserpol\Models\Contribution\ContributionType;
use Muserpol\Models\Contribution\Reimbursement;
use Muserpol\Models\RetirementFund\RetFunCorrelative;
use Muserpol\Models\InfoLoan;
use Muserpol\Helpers\ID;
use Muserpol\Models\Testimony;
use Illuminate\Support\Collection;
use Muserpol\Models\FinancialEntity;
use Muserpol\Models\KinshipBeneficiary;
use Ramsey\Uuid\Uuid;

class RetirementFundController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {

        $modalities =  ProcedureModality::all()->pluck('name');
        $cities =  City::all()->pluck('name');
        $wf_states =  WorkflowState::where('module_id', 3)->get()->pluck('first_shortened');
        $data = [
            'modalities' => $modalities,
            'cities' => $cities,
            'wf_states' => $wf_states,
        ];
        return view('ret_fun.index', $data);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }
    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $first_name = $request->beneficiary_first_name;
        $second_name = $request->beneficiary_second_name;
        $last_name = $request->beneficiary_last_name;
        $mothers_last_name = $request->beneficiary_mothers_last_name;
        $surname_husband = $request->beneficiary_surname_husband;
        $identity_card = $request->beneficiary_identity_card;
        $city_id = $request->beneficiary_city_identity_card;
        $birth_date = $request->beneficiary_birth_date;
        $kinship = $request->beneficiary_kinship;
        $kinship_beneficiary = $request->kinship_beneficiary;
        $gender = $request->beneficiary_gender;
        $legal_representative = $request->beneficiary_legal_representative;
        $account_type = $request->input('accountType');
        //*********START VALIDATOR************//
        $rules = [];
        $biz_rules = [];

        $has_ret_fun = false;
        $ret_fun = RetirementFund::where('affiliate_id', $request->affiliate_id)->where('code', 'NOT LIKE', '%A')->count();
        if ($ret_fun >= 2) {
            $has_ret_fun = true;
            $biz_rules = [
                'ret_fun_double'  =>  $has_ret_fun ? 'required' : '',
            ];
            $validator = Validator::make($request->all(), $biz_rules);
            if ($validator->fails()) {
                return Redirect::back()->withErrors($validator);
            }
        }

        $rules = [
            'ret_fun_modality' =>  'required',
            'accountType'   =>  'required',
            'applicant_first_name'  =>  'required',
            'applicant_identity_card'   =>  'required',
        ];


        // $requirements = ProcedureRequirement::where('procedure_modality_id', $request->ret_fun_modality)->whereNull('deleted_at')->select('id', 'number')->orderBy('number', 'asc')->get();
        // $array_requirements = [];
        // foreach ($requirements as $requirement) {
        //     $array_requirements[$requirement->number] = 0;
        // }

        // foreach ($requirements as $requirement) {
        //     if ($request->input('document' . $requirement->id) == 'checked') {
        //         $array_requirements[$requirement->number]++;
        //     }
        // }
        //return $array_requirements;
        // foreach ($array_requirements as $key => $requirement) {

        //     if ($requirement == 0 && $key != 0) {
        //         $biz_rules = [
        //             'no_document' . $key   =>  'required'
        //         ];
        //     }
        //     if ($requirement > 1) {
        //         $biz_rules = [
        //             'double_document' . $key  =>  'required'
        //         ];
        //     }
        //     $rules = array_merge($rules, $biz_rules);
        // }


        $has_lastname = false;
        $legal_has_lastname = false;
        if ($request->applicant_last_name == '' && $request->applicant_mothers_last_name == '')
            $has_lastname = true;
        if ($account_type == ID::applicant()->legal_guardian_id) {
            if ($request->legal_guardian_last_name == '' && $request->legal_guardian_mothers_last_name == '')
                $legal_has_lastname = true;
        }
        $correct_role = false;
        $wf_state = WorkflowState::where('module_id', 3)->where('role_id', Util::getRol()->id)->first();
        if (isset($wf_state->id)) {
            $correct_role = true;
        }

        $biz_rules = [
            'has_lastname'  =>  $has_lastname ? 'required' : '',
            'legal_guardian_first_name' => $account_type == ID::applicant()->legal_guardian_id ? 'required' : '',
            'legal_has_lastname' => $legal_has_lastname ? 'required' : '',
            'correct_role' => !$correct_role ? 'required' : '',
            //'legal_guardian_identity_card'  =>  $account_type==3 ? 'required' : '',
            //'legal_guardian_number_authority'   => $account_type==3 ? 'required' : '',
            //'legal_guardian_notary_of_public_faith' => $account_type==3 ? 'required' : '',
            //'legal_guardian_notary'  => $account_type==3 ? 'required' : '',
            //'advisor_name_court'    =>  $account_type==2 ? 'required' : '',
            //'advisor_resolution_number'    =>  $account_type==2 ? 'required' : '',
            //'advisor_resolution_date'   => $account_type==2 ? 'required' : '',
        ];

        $rules = array_merge($rules, $biz_rules);


        for ($i = 0; is_array($first_name) && $i < sizeof($first_name); $i++) {
            $beneficiary_has_lastname = false;
            if ($request->beneficiary_last_name[$i] == '' && $request->beneficiary_mothers_last_name[$i] == '')
                $beneficiary_has_lastname = true;

            $biz_rules = [
                'beneficiary_first_name.' . $i =>  'required',
                //'beneficiary_identity_card.'.$i  =>  'required',
                //'beneficiary_kinship.'.$i    =>  'required',
                'beneficiary_has_lastname.' . $i   =>  $beneficiary_has_lastname ? 'required' : '',
            ];
            $rules = array_merge($rules, $biz_rules);
        }
        $rules = array_merge($rules, $biz_rules);

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return redirect(route('create_ret_fun', $request->affiliate_id))
                ->withErrors($validator)
                ->withInput();
            // return Redirect::back()->withErrors($validator)->withInput();
            //return response()->json($validator->errors(), 406);
        }

        //*********END VALIDATOR************//


        $requirements = ProcedureRequirement::select('id')->get();

        $procedure = \Muserpol\Models\RetirementFund\RetFunProcedure::where('is_enabled', true)->select('id')->first();


        $validator = Validator::make($request->all(), [
            //'applicant_first_name' => 'required|max:5',
        ]);
        //custom this validator
        $validator->after(function ($validator) {
            if (false)
                $validator->errors()->add('Modalidad', 'el campo modalidad no puede ser tramitada este mes');
        });
        if ($validator->fails()) {
            return $validator->errors();
        }

        $nextcode = RetirementFund::where('affiliate_id', $request->affiliate_id)->where('code', 'LIKE', '%A')->first();
        if (isset($nextcode->id)) {
            $code = str_replace("A", "", $nextcode->code);
        } else {

            //$ret_fund  = RetirementFund::select('id','code')->orderby('id','desc')->first();
            $ret_fund = RetirementFund::select('id', 'code')
                ->limit(10)
                ->orderBy('id', 'desc')
                ->get();

            // $ret_fun_code = $this->getLastCode($ret_fund);
            $ret_fun_code = Util::getLastCode(RetirementFund::class);
            //  $this->getLastCode($ret_fund);
            $code = Util::getNextCode($ret_fun_code);
        }


        $retirement_fund = new RetirementFund();
        $this->authorize('create', $retirement_fund);
        $retirement_fund->user_id = Auth::user()->id;
        $retirement_fund->affiliate_id = $request->affiliate_id;
        $retirement_fund->procedure_modality_id = $request->ret_fun_modality;
        $retirement_fund->ret_fun_procedure_id = $procedure->id;
        $retirement_fund->city_start_id = Auth::user()->city_id;
        $retirement_fund->city_end_id = $request->city_end_id;
        $retirement_fund->reception_date = Carbon::now();
        $retirement_fund->code = $code;
        $retirement_fund->uuid = Uuid::uuid1()->toString();
        $retirement_fund->workflow_id = 4;
        $wf_state = WorkflowState::where('role_id', Util::getRol()->id)->whereIn('sequence_number', [0, 1])->first();
        if (!$wf_state) {
            return;
        }
        $retirement_fund->wf_state_current_id = $wf_state->id;
        $retirement_fund->subtotal_ret_fun = 0;
        $retirement_fund->total_ret_fun = 0;
        $retirement_fund->reception_date = date('Y-m-d');
        $retirement_fund->inbox_state = true;
        $retirement_fund->ret_fun_state_id = ID::state()->en_proceso;
        $retirement_fund->save();
        $reception_code = Util::getNextAreaCode($retirement_fund->id);




        $af = Affiliate::find($request->affiliate_id);
        $af->date_derelict = Util::verifyMonthYearDate($request->date_derelict) ? Util::parseMonthYearDate($request->date_derelict) : $request->date_derelict;
        $af->date_entry = Util::verifyMonthYearDate($request->date_entry) ? Util::parseMonthYearDate($request->date_entry) : $request->date_entry;
        if($request->date_entry_reinstatement != null ) {
            $af->date_entry_reinstatement = Util::verifyMonthYearDate($request->date_entry_reinstatement) ? Util::parseMonthYearDate($request->date_entry_reinstatement) : $request->date_entry_reinstatement;
        }
        if($request->date_derelict_reinstatement != null ) {
            $af->date_derelict_reinstatement = Util::verifyMonthYearDate($request->date_derelict_reinstatement) ? Util::parseMonthYearDate($request->date_derelict_reinstatement) : $request->date_derelict_reinstatement;
        }
        switch ($request->ret_fun_modality) {
            case 1:
            case 4:
                $af->affiliate_state_id = ID::affiliateState()->fallecido;

                $af->date_death = Util::verifyBarDate($request->date_death) ? Util::parseBarDate($request->date_death) : $request->date_death;
                $af->reason_death = $request->reason_death;
                break;
            case 63:
            case 2:
            case 3:
            case 5:
            case 6:
            case 7:
            case 62:
            case 24:
                $af->affiliate_state_id = ID::affiliateState()->jubilado;
                break;
            default:
                $this->info("error");
                break;
        }
        $af->save();

        //$cite = RetFunIncrement::getCite(Auth::user()->id,Session::get('rol_id'),$retirement_fund->id);
       //Guarda los requisitos requeridos
        if($request->required_requirements){
            $required_requirements = [];
            foreach ($request->required_requirements as $number) {
                foreach ($number as $req) {
                    if (isset($req['status']) && $req['status'] == 'checked') {
                        $required_requirements[] = $req;
                    }
                }
            }
            foreach ($required_requirements  as  $requirement) {
                $submit = new RetFunSubmittedDocument();
                $submit->retirement_fund_id = $retirement_fund['id'];
                $submit->procedure_requirement_id = $requirement['procedureRequirementId'];
                $submit->comment = $requirement['comment'];
                $submit->is_uploaded = $requirement['isUploaded'];
                $submit->save();
            }
        }
        if ($request->aditional_requirements) {
            $additional_requirements = [];
            foreach ($request->aditional_requirements as $adr) {
                $additional_requirements[] = json_decode($adr);
            }
            foreach ($additional_requirements  as  $requirement) {
                $submit = new RetFunSubmittedDocument();
                $submit->retirement_fund_id = $retirement_fund->id;
                $submit->procedure_requirement_id = $requirement->procedureRequirementId;
                $submit->comment = null;
                $submit->is_uploaded = $requirement->isUploaded;
                $submit->save();
            }
        }


        $beneficiary = new RetFunBeneficiary();
        $beneficiary->retirement_fund_id = $retirement_fund->id;
        $beneficiary->kinship_id = $request->applicant_kinship;
        $beneficiary->identity_card = mb_strtoupper($request->applicant_identity_card);
        $beneficiary->last_name = mb_strtoupper(trim($request->applicant_last_name));
        $beneficiary->mothers_last_name = mb_strtoupper(trim($request->applicant_mothers_last_name));
        $beneficiary->first_name = mb_strtoupper(trim($request->applicant_first_name));
        $beneficiary->second_name = mb_strtoupper(trim($request->applicant_second_name));
        $beneficiary->surname_husband = mb_strtoupper(trim($request->applicant_surname_husband));
        $beneficiary->birth_date = Util::verifyBarDate($request->applicant_birth_date) ? Util::parseBarDate($request->applicant_birth_date) : $request->applicant_birth_date;
        $beneficiary->gender = $request->applicant_gender;
        $beneficiary->phone_number = trim(implode(",", $request->applicant_phone_number ?? []));
        $beneficiary->cell_phone_number = trim(implode(",", $request->applicant_cell_phone_number ?? []));
        $beneficiary->type = ID::beneficiary()->solicitante;
        $beneficiary->save();
        if ($account_type == ID::applicant()->beneficiary_id && $request->ret_fun_modality != ID::retFun()->fallecimiento_id && $request->ret_fun_modality != ID::retFunGlobalPay()->fallecimiento_id && $request->ret_fun_modality != ID::retFunDevPay()->fallecimiento_id) {
            Util::updateAffiliatePersonalInfo($retirement_fund->affiliate_id, $beneficiary);
        }
        if ($account_type == ID::applicant()->beneficiary_id && ($request->ret_fun_modality == ID::retFun()->fallecimiento_id || $request->ret_fun_modality == ID::retFunGlobalPay()->fallecimiento_id || $request->ret_fun_modality == ID::retFunDevPay()->fallecimiento_id) && $beneficiary->kinship_id == ID::kinship()->conyuge) {
            Util::updateCreateSpousePersonalInfo($retirement_fund->affiliate_id, $beneficiary);
        }

        if ($account_type == ID::applicant()->advisor_id) {
            $advisor = new RetFunAdvisor();
            //$advisor->retirement_fund_id = $retirement_fund->id;
            $advisor->kinship_id = null;
            $advisor->identity_card = $request->applicant_identity_card;
            $advisor->last_name = strtoupper(trim($request->applicant_last_name));
            $advisor->mothers_last_name = strtoupper(trim($request->applicant_mothers_last_name));
            $advisor->first_name = strtoupper(trim($request->applicant_first_name));
            $advisor->second_name = strtoupper(trim($request->applicant_second_name));
            $advisor->surname_husband = strtoupper(trim($request->applicant_surname_husband));
            //$advisor->gender = "M";
            $advisor->phone_number = trim(implode(",", $request->applicant_phone_number ?? []));
            $advisor->cell_phone_number = trim(implode(",", $request->applicant_cell_phone_number ?? []));
            $advisor->name_court = $request->advisor_name_court;
            $advisor->resolution_number = $request->advisor_resolution_number;
            $advisor->resolution_date = Util::verifyBarDate($request->advisor_resolution_date) ? Util::parseBarDate($request->advisor_resolution_date) : $request->advisor_resolution_date;
            $advisor->type = "Natural";
            $advisor->save();

            $advisor_beneficiary = new RetFunAdvisorBeneficiary();
            $advisor_beneficiary->ret_fun_beneficiary_id = $beneficiary->id;
            $advisor_beneficiary->ret_fun_advisor_id = $advisor->id;
            $advisor_beneficiary->save();
        }

        if ($account_type == ID::applicant()->legal_guardian_id) {
            $legal_guardian = new RetFunLegalGuardian();
            $legal_guardian->retirement_fund_id = $retirement_fund->id;
            $legal_guardian->identity_card = strtoupper(trim($request->legal_guardian_identity_card));
            $legal_guardian->last_name = strtoupper(trim($request->legal_guardian_last_name));
            $legal_guardian->mothers_last_name = strtoupper(trim($request->legal_guardian_mothers_last_name));
            $legal_guardian->first_name = strtoupper(trim($request->legal_guardian_first_name));
            $legal_guardian->second_name = strtoupper(trim($request->legal_guardian_second_name));
            $legal_guardian->surname_husband = strtoupper(trim($request->legal_guardian_surname_husband));
            $legal_guardian->phone_number = trim(implode(",", $request->applicant_phone_number ?? []));
            $legal_guardian->cell_phone_number = trim(implode(",", $request->applicant_cell_phone_number ?? []));
            $legal_guardian->number_authority = $request->legal_guardian_number_authority;
            $legal_guardian->notary_of_public_faith = $request->legal_guardian_notary_of_public_faith;
            $legal_guardian->notary = $request->legal_guardian_notary;
            $legal_guardian->date_authority = Util::verifyBarDate($request->legal_guardian_date_authority) ? Util::parseBarDate($request->legal_guardian_date_authority) : $request->legal_guardian_date_authority;
            $legal_guardian->gender = $request->legal_guardian_gender;
            $legal_guardian->save();
            $beneficiary_legal_guardian = new RetFunLegalGuardianBeneficiary();
            $beneficiary_legal_guardian->ret_fun_beneficiary_id = $beneficiary->id;
            $beneficiary_legal_guardian->ret_fun_legal_guardian_id = $legal_guardian->id;
            $beneficiary_legal_guardian->save();
            //$beneficiary->type = "N";
            //actualiza datos del afiliado
            if ($request->ret_fun_modality != ID::retFun()->fallecimiento_id && $request->ret_fun_modality != ID::retFunGlobalPay()->fallecimiento_id && $request->ret_fun_modality != ID::retFunDevPay()->fallecimiento_id) {
                Util::updateAffiliatePersonalInfo($retirement_fund->affiliate_id, $beneficiary);
            }
            //actualiza datos de la cónyuge
            if (($request->ret_fun_modality == ID::retFun()->fallecimiento_id || $request->ret_fun_modality == ID::retFunGlobalPay()->fallecimiento_id || $request->ret_fun_modality == ID::retFunDevPay()->fallecimiento_id) && $beneficiary->kinship_id == ID::kinship()->conyuge) {
                Util::updateCreateSpousePersonalInfo($retirement_fund->affiliate_id, $beneficiary);
            }
        }
        if ($request->beneficiary_city_address_id || $request->beneficiary_zone || $request->beneficiary_street || $request->beneficiary_number_address) {
            $address = new Address();
            $address->city_address_id = $request->beneficiary_city_address_id ?? 1;
            $address->zone = $request->beneficiary_zone;
            $address->street = $request->beneficiary_street;
            $address->number_address = $request->beneficiary_number_address;
            $address->save();
            if ($request->ret_fun_modality == ID::retFun()->fallecimiento_id || $request->ret_fun_modality == ID::retFunGlobalPay()->fallecimiento_id || $request->ret_fun_modality == ID::retFunGlobalPay()->fallecimiento_id) { } else {
                $retirement_fund->affiliate->address()->save($address);
            }
            $beneficiary->address()->save($address);
        }
        $legal_guardian_count = 0;
        $advisor_count = 0;
        for ($i = 0; is_array($first_name) && $i < sizeof($first_name); $i++) {
            if ($first_name[$i] != "" && ($last_name[$i] != "" || $mothers_last_name[$i] != "")) {
                $beneficiary = new RetFunBeneficiary();
                $beneficiary->retirement_fund_id = $retirement_fund->id;
                $beneficiary->kinship_id = $kinship[$i] ?? null;
                $beneficiary->identity_card = $identity_card[$i];
                $beneficiary->last_name = strtoupper(trim($last_name[$i]));
                $beneficiary->mothers_last_name = strtoupper(trim($mothers_last_name[$i]));
                $beneficiary->first_name = strtoupper(trim($first_name[$i]));
                $beneficiary->second_name = strtoupper(trim($second_name[$i]));
                $beneficiary->surname_husband = strtoupper(trim($surname_husband[$i]));
                $beneficiary->birth_date = Util::verifyBarDate($birth_date[$i]) ? Util::parseBarDate($birth_date[$i]) : $birth_date[$i];;
                $beneficiary->gender = $gender[$i];
                //$beneficiary->civil_status = $request->
                //$beneficiary->phone_number = $request->;
                //$beneficiary->cell_phone_number = $request->;
                $beneficiary->type = ID::beneficiary()->normal;
                $beneficiary->save();
                switch ($legal_representative[$i]) {
                    case 1:
                        $advisor = new RetFunAdvisor();
                        //$advisor->retirement_fund_id = $retirement_fund->id;
                        $advisor->kinship_id = null;
                        $advisor->identity_card = $request->beneficiary_advisor_identity_card[$advisor_count];
                        $advisor->last_name = strtoupper(trim($request->beneficiary_advisor_last_name[$advisor_count]));
                        $advisor->mothers_last_name = strtoupper(trim($request->beneficiary_advisor_mothers_last_name[$advisor_count]));
                        $advisor->first_name = strtoupper(trim($request->beneficiary_advisor_first_name[$advisor_count]));
                        $advisor->second_name = strtoupper(trim($request->beneficiary_advisor_second_name[$advisor_count]));
                        $advisor->surname_husband = strtoupper(trim($request->beneficiary_advisor_surname_husband[$advisor_count]));
                        $advisor->gender = strtoupper(trim($request->beneficiary_advisor_gender[$advisor_count]));
                        // $advisor->phone_number = trim(implode(",", $request->beneficiary_advisor_phone_number ?? []));
                        // $advisor->cell_phone_number = trim(implode(",", $request->beneficiary_advisor_cell_phone_number ?? []));
                        $advisor->name_court = $request->beneficiary_advisor_name_court[$advisor_count];
                        $advisor->resolution_number = $request->beneficiary_advisor_resolution_number[$advisor_count];
                        $advisor->resolution_date = Util::verifyBarDate($request->beneficiary_advisor_resolution_date[$advisor_count]) ? Util::parseBarDate($request->beneficiary_advisor_resolution_date[$advisor_count]) : $request->beneficiary_advisor_resolution_date[$advisor_count];
                        $advisor->type = "Natural";
                        $advisor->save();

                        $advisor_beneficiary = new RetFunAdvisorBeneficiary();
                        $advisor_beneficiary->ret_fun_beneficiary_id = $beneficiary->id;
                        $advisor_beneficiary->ret_fun_advisor_id = $advisor->id;
                        $advisor_beneficiary->kinship_beneficiary_id = $kinship_beneficiary[$i] ?? null;
                        $advisor_beneficiary->save();
                        $advisor_count++;
                        break;
                    case 2:
                        $legal_guardian = new RetFunLegalGuardian();
                        $legal_guardian->retirement_fund_id = $retirement_fund->id; // is necessary?
                        $legal_guardian->identity_card = strtoupper(trim($request->beneficiary_legal_guardian_identity_card[$legal_guardian_count]));
                        $legal_guardian->first_name = strtoupper(trim($request->beneficiary_legal_guardian_first_name[$legal_guardian_count]));
                        $legal_guardian->second_name = strtoupper(trim($request->beneficiary_legal_guardian_second_name[$legal_guardian_count]));
                        $legal_guardian->last_name = strtoupper(trim($request->beneficiary_legal_guardian_last_name[$legal_guardian_count]));
                        $legal_guardian->mothers_last_name = strtoupper(trim($request->beneficiary_legal_guardian_mothers_last_name[$legal_guardian_count]));
                        $legal_guardian->surname_husband = strtoupper(trim($request->beneficiary_legal_guardian_surname_husband[$legal_guardian_count]));
                        /** !! TODO
                         * phone and cellphone numbers
                         */
                        $legal_guardian->phone_number = trim(implode(",", $request->applicant_phone_number ?? []));
                        $legal_guardian->cell_phone_number = trim(implode(",", $request->applicant_cell_phone_number ?? []));

                        $legal_guardian->gender = $request->beneficiary_legal_guardian_gender[$legal_guardian_count];
                        $legal_guardian->number_authority = $request->beneficiary_legal_guardian_number_authority[$legal_guardian_count];
                        $legal_guardian->notary_of_public_faith = $request->beneficiary_legal_guardian_notary_of_public_faith[$legal_guardian_count];
                        $legal_guardian->notary = $request->beneficiary_legal_guardian_notary_of_public_faith[$legal_guardian_count];
                        $legal_guardian->date_authority = Util::verifyBarDate($request->beneficiary_legal_guardian_date_authority[$legal_guardian_count]) ? Util::parseBarDate($request->beneficiary_legal_guardian_date_authority[$legal_guardian_count]) : $request->beneficiary_legal_guardian_date_authority[$legal_guardian_count];
                        $legal_guardian->save();
                        $legal_guardian_count++;
                        /**
                         * 😡
                         * TODO
                         */

                        $beneficiary_legal_guardian = new RetFunLegalGuardianBeneficiary();
                        $beneficiary_legal_guardian->ret_fun_beneficiary_id = $beneficiary->id;
                        $beneficiary_legal_guardian->ret_fun_legal_guardian_id = $legal_guardian->id;
                        $beneficiary_legal_guardian->save();
                        break;
                    default:
                        break;
                }
            }
        }
        $data = [];

        return redirect('ret_fun/' . $retirement_fund->id);
    }
    /**
     * Display the specified resource.
     *
     * @param  \Muserpol\RetirementFund  $retirementFund
     * @return \Illuminate\Http\Response
     */
    //public function show(RetirementFund $retirementFund)
    public function show($id)
    {
        //         $data = [

        //         ];
        //         return \PDF::loadView('ret_fun.print.legal_dictum', $data)
        // 				->setPaper('letter')
        // 				->setOption('encoding', 'utf-8')
        //                 ->stream("dictamenLegal.pdf");

        //         return 123;
        //         $retirement_fund = RetirementFund::find($id);
        //         $affiliate = Affiliate::find($retirement_fund->affiliate_id);
        //         $discounts = $retirement_fund->discount_types(); //DiscountType::where('retirement_fund_id',$retirement_fund->id)->orderBy('discount_type_id','ASC')->get();
        //         $loans = InfoLoan::where('affiliate_id',$affiliate->id)->get();
        //         $body = "Por consiguiente, habiendo sido remitido el presente trámite al Área Legal Unidad de
        //         Otorgación del Fondo de Retiro Policial Solidario, autorizado por Jefatura de la Unidad de
        //         Otorgación del Fondo de Retiro Policial Solidario, conforme a los Art. 2, 3, 5, 10, 26, 27, 28,
        //         32, 36, 37, 38, 41, 42, 44, 45, 48, 49, 50, 70, 71, 72, 73, 74 y la Disposición Transitoria
        //         Segunda, del Reglamento de Fondo de Retiro Policial Solidario, aprobado mediante
        //         Resolución de Directorio N° 31/2017 en fecha 24 de agosto de 2017 y modificado mediante
        //         Resolución de Directorio N° 36/2017 en fecha 20 de septiembre de 2017. Se DICTAMINA en
        //         mérito a la documentación de respaldo contenida en el presente, ";

        //         $flagy = 0;
        //         if($discounts->count()>0)
        //             $body .= "proceder a realizar el descuento de ";

        //         $discount = $discounts->where('discount_type_id','1')->first();

        //         if(isset($discount->id)){
        //             $body.="Bs ".Util::formatMoney($discount->pivot->amount)." (".Util::convertir($discount->pivot->amount).") por concepto de anticipo de Fondo de Retiro Policial de conformidad a la nota Nro. ".$discount->pivot->note_code." de fecha ".Util::getStringDate($discount->pivot->date);
        //         }

        //         $discounts = $retirement_fund->discount_types();

        //         if(isset($discount->id)){
        //             $body .= $this->getFlagy(3,2);
        //             // if($flagy == 1)
        //             // $body .= " y la suma de ";
        //             $body.="Bs ".Util::formatMoney($discount->pivot->amount)." (".Util::convertir($discount->pivot->amount).") por concepto de saldo de deuda con la MUSERPOL de conformidad al contrato de préstamo Nro. ".$discount->code." y nota ".$discount->note_code." de fecha ".Util::getStringDate($discount->date);
        //         }
        //         //
        //         $discounts = $retirement_fund->discount_types();
        //         $discount = $discounts->where('discount_type_id','3')->first();
        //         $loans = InfoLoan::where('affiliate_id',$affiliate->id)->get();

        //         $body.="Bs ".Util::formatMoney($discount->pivot->amount)." (".Util::convertir($discount->pivot->amount).") por concepto de garantía de préstamo a favor de";// los señores. ".$discount->code." y nota ".$discount->note_code." de fecha ".$discount->date;
        //         $num_loans = $loans->count();
        //         if($num_loans==1)
        //             $body .= "l señore ";
        //         else
        //             $body .= " los señores ";
        //         $i=0;
        //         foreach($loans as $loan){
        //             $i++;
        //             if($i!=1)
        //             {
        //                 if($num_loans-$i==0)
        //                     $body .= " y ";
        //                 else
        //                     $body .= ", ";
        //             }
        //             $body.= $loan->affiliate_guarantor->fullName()." con C.I. N° ".$loan->affiliate_guarantor->identity_card." en la suma de Bs ".Util::formatMoney($loan->amount)." (".Util::convertir($discount->pivot->amount);
        //         }
        //         $body .= " en conformidad al contrato de préstamo Nro. ".$discount->pivot->code." y la nota ".$discount->pivot->note_code." de fecha ". Util::getStringDate($retirement_fund->reception_date) ." de la Dirección de Estrategias Sociales e Inversiones. Reconocer los derechos y se otorgue el beneficio del Fondo de Retiro Policial Solidario por <b>".strtoupper($retirement_fund->procedure_modality->name)."</b> a favor de:<br><br>";
        //         $body .= $affiliate->degree->shortened." ".$affiliate->fullName()." con C.I. N° ".$affiliate->identity_card."., el monto de Bs ".Util::formatMoney($retirement_fund->total_ret_fun)." (".Util::convertir($retirement_fund->total_ret_fun).").";
        //         return $body;
        // return "123";

        $retirement_fund = RetirementFund::with(['discount_types' => function ($query) {
            $query->orderBy('id');
        }])->where('id', $id)->first();

        $this->authorize('view', $retirement_fund);

        $affiliate = Affiliate::find($retirement_fund->affiliate_id);
        $affiliate->phone_number = explode(',', $affiliate->phone_number);
        $affiliate->cell_phone_number = explode(',', $affiliate->cell_phone_number);
        if (!sizeOf($affiliate->address) > 0) {
            $affiliate->address[] = array('zone' => null, 'street' => null, 'number_address' => null, 'city_address_id' => null);
        }

        $beneficiaries = RetFunBeneficiary::with('address')->where('retirement_fund_id', $retirement_fund->id)->with(['kinship', 'city_identity_card'])->orderByDesc('type')->orderBy('id')->get();
        foreach ($beneficiaries as $b) {
            $b->phone_number = explode(',', $b->phone_number);
            $b->cell_phone_number = explode(',', $b->cell_phone_number);
            if (!sizeOf($b->address) > 0 && $b->type == 'S') {
                $b->address[] = array('zone' => null, 'street' => null, 'number_address' => null);
            }
            //1 => tutor
            //2 => Apoderado
            $b->legal_representative = null;
            if ($beneficiary_advisor = $b->ret_fun_advisors->first()) {
                $b->legal_representative = 1;
                $b->advisor_identity_card = $beneficiary_advisor->identity_card;
                $b->advisor_first_name = $beneficiary_advisor->first_name;
                $b->advisor_second_name = $beneficiary_advisor->second_name;
                $b->advisor_last_name = $beneficiary_advisor->last_name;
                $b->advisor_mothers_last_name = $beneficiary_advisor->mothers_last_name;
                $b->advisor_surname_husband = $beneficiary_advisor->surname_husband;
                $b->advisor_birth_date = $beneficiary_advisor->birth_date;
                $b->advisor_gender = $beneficiary_advisor->gender;
                $b->advisor_name_court = $beneficiary_advisor->name_court;
                $b->advisor_resolution_number = $beneficiary_advisor->resolution_number;
                $b->advisor_resolution_date = $beneficiary_advisor->resolution_date;
                $kinship = $beneficiary_advisor->kinship_beneficiaries($b->id)->first();
                $b->kinship_beneficiary_id = $kinship ? $kinship->id : null;
            }
            if ($beneficiary_legal_guardian =  $b->legal_guardian->first()) {
                $b->legal_representative = 2;
                $b->legal_guardian_identity_card = $beneficiary_legal_guardian->identity_card;
                $b->legal_guardian_first_name = $beneficiary_legal_guardian->first_name;
                $b->legal_guardian_second_name = $beneficiary_legal_guardian->second_name;
                $b->legal_guardian_last_name = $beneficiary_legal_guardian->last_name;
                $b->legal_guardian_mothers_last_name = $beneficiary_legal_guardian->mothers_last_name;
                $b->legal_guardian_surname_husband = $beneficiary_legal_guardian->surname_husband;
                $b->legal_guardian_gender = $beneficiary_legal_guardian->gender;
                $b->legal_guardian_number_authority = $beneficiary_legal_guardian->number_authority;
                $b->legal_guardian_notary_of_public_faith = $beneficiary_legal_guardian->notary_of_public_faith;
                $b->legal_guardian_notary = $beneficiary_legal_guardian->notary;
                $b->legal_guardian_date_authority = $beneficiary_legal_guardian->date_authority;
            }
        }
        $applicant = RetFunBeneficiary::where('type', 'S')->where('retirement_fund_id', $retirement_fund->id)->first();

        if ($applicant) {
            $beneficiary_avdisor = RetFunAdvisorBeneficiary::where('ret_fun_beneficiary_id', $applicant->id)->first();
            $beneficiary_guardian = RetFunLegalGuardianBeneficiary::where('ret_fun_beneficiary_id', $applicant->id)->first();
        } else {
            $beneficiary_avdisor = null;
            $beneficiary_guardian = null;
        }
        
        if (isset($beneficiary_avdisor->id))
            $advisor = RetFunAdvisor::find($beneficiary_avdisor->ret_fun_advisor_id);
        else
            $advisor = new RetFunAdvisor();

        if (isset($beneficiary_guardian->id))
            $guardian = RetFunLegalGuardian::find($beneficiary_guardian->ret_fun_legal_guardian_id);
        else
            $guardian = new RetFunLegalGuardian();

        $procedures_modalities_ids = ProcedureModality::join('procedure_types', 'procedure_types.id', '=', 'procedure_modalities.procedure_type_id')->where('procedure_types.module_id', '=', 3)->get()->pluck('id'); //3 por el module 3 de fondo de retiro
        //return $procedures_modalities_ids;
        $procedures_modalities = ProcedureModality::whereIn('procedure_type_id', $procedures_modalities_ids)->get();
        $file_modalities = ProcedureModality::get();
        $requirements = ProcedureRequirement::where('procedure_modality_id', $retirement_fund->procedure_modality_id)->get();
        $documents = RetFunSubmittedDocument::where('retirement_fund_id', $id)->orderBy('procedure_requirement_id', 'ASC')->get();
        $cities = City::get();
        $kinships = Kinship::get();
        $kinship_beneficiaries = KinshipBeneficiary::get();

        $cities_pluck = City::all()->where('id','<>',10)->pluck('name', 'id');
        $birth_cities = City::all()->pluck('name', 'id');
        $financial_entities = FinancialEntity::all()->pluck('name', 'id');

        $states = RetFunState::get();

        $ret_fun_records = RetFunRecord::where('ret_fun_id', $id)->orderBy('id', 'desc')->get();
        //return $retirement_fund->ret_fun_state->name;

        ///proof
        $user = User::find(Auth::user()->id);
        $procedure_types = ProcedureType::where('module_id', 3)->get();
        $procedure_requirements = ProcedureRequirement::select('procedure_requirements.id', 'procedure_documents.name as document', 'number', 'procedure_modality_id as modality_id')
            ->leftJoin('procedure_documents', 'procedure_requirements.procedure_document_id', '=', 'procedure_documents.id')
            ->orderBy('procedure_requirements.procedure_modality_id', 'ASC')
            ->orderBy('procedure_requirements.number', 'ASC')
            ->get();
        $modalities = ProcedureModality::where('procedure_type_id', '<=', '21')->select('id', 'name', 'procedure_type_id')->get();

        $observation_types = ObservationType::where('module_id', 3)->get();

        //selected documents
        $submitted = RetFunSubmittedDocument::select('ret_fun_submitted_documents.id', 'procedure_requirements.number', 'ret_fun_submitted_documents.procedure_requirement_id', 'ret_fun_submitted_documents.comment', 'ret_fun_submitted_documents.is_valid', 'ret_fun_submitted_documents.is_uploaded', 'procedure_documents.name')
            ->leftJoin('procedure_requirements', 'ret_fun_submitted_documents.procedure_requirement_id', '=', 'procedure_requirements.id')
            ->join('procedure_documents', 'procedure_requirements.procedure_document_id', '=', 'procedure_documents.id')
            ->orderby('procedure_requirements.number', 'ASC')
            ->where('ret_fun_submitted_documents.retirement_fund_id', $id);
        
        // return $submitted->get();
        // ->pluck('ret_fun_submitted_documents.procedure_requirement_id','procedure_requirements.number');
        /**for validate doc*/
        $rol = Util::getRol();
        $module = Role::find($rol->id)->module;
        $wf_current_state = WorkflowState::where('role_id', $rol->id)->where('module_id', '=', $module->id)->first();
        $can_validate = $wf_current_state->id == $retirement_fund->wf_state_current_id;
        $can_cancel = ($retirement_fund->user_id == $user->id && $retirement_fund->inbox_state == true);

        //workflow record
        $workflow_records = $retirement_fund->wf_records()->orderBy('date', 'desc')->get();

        $first_wf_state = RetFunRecord::whereRaw("message like '%creo el Tr%'")->first();
        /*if ($first_wf_state) {
            $re = '/(?<= usuario )(.*)(?= cr.* )/mi';
            $str = $first_wf_state->message;
            preg_match_all($re, $str, $matches, PREG_SET_ORDER, 0);
            $user_name = $matches[0][0];
            $rol = User::where('username', '=', $user_name)->first()->roles->first();
            $first_wf_state = WorkflowState::where('role_id', $rol->id)->first();
        }*/


        // dd($first_wf_state);

        $wf_states = WorkflowState::where('module_id', '=', $module->id)->where('sequence_number', '>', ($first_wf_state->sequence_number ?? 1))->orderBy('sequence_number')->get();

        $correlatives = RetFunCorrelative::where('retirement_fund_id', $retirement_fund->id)->get();
        $steps = [];
        $data = $retirement_fund->getReceptionSummary();
        $is_editable = ID::getNonEditableId();
        if (isset($retirement_fund->id) && ($retirement_fund->procedure_modality_id == 4 || $retirement_fund->procedure_modality_id == 2))
            $is_editable = ID::getEditableId();



        $wf_sequences_back = DB::table("wf_states")
            ->where("wf_states.module_id", "=", $module->id)
            ->where('wf_states.sequence_number', '<', WorkflowState::find($retirement_fund->wf_state_current_id)->sequence_number)
            ->whereNull('wf_states.deleted_at')
            ->select(
                'wf_states.id as wf_state_id',
                'wf_states.first_shortened as wf_state_name'
            )
            ->get();


        //para devolver hacia adelante 735
        $return_sequence = $retirement_fund->wf_records->first();
        if($return_sequence <> null && $return_sequence->record_type_id == 4 && $return_sequence->wf_state_id == $retirement_fund->wf_state_current_id){
            $wf_back = DB::table("wf_states")
                ->where("wf_states.module_id", $module->id)
                ->where('wf_states.id', $return_sequence->old_wf_state_id)
                ->select(
                    'wf_states.id as wf_state_id',
                    'wf_states.first_shortened as wf_state_name'
                )
                ->get();
            $wf_sequences_back = $wf_sequences_back->merge($wf_back);
        }
        //
        //summary individuals account
        $ret_fun_index = $retirement_fund->procedureIndex();
        if ($ret_fun_index == 0) {
            $fields = [
                'date_entry' => 'El campo "Fecha de Ingreso a la Institucional Policial" en Información Policial no puede estar vacío',
                'date_derelict' => 'El campo "Fecha de Desvinculación" en Información Policial no puede estar vacío',
            ];
        } else if ($ret_fun_index == 1) {
            $fields = [
                'date_entry_reinstatement' => 'El campo "Fecha de Ingreso a la Institucional Policial (reincorporación)" en Información Policial no puede estar vacío',
                'date_derelict_reinstatement' => 'El campo "Fecha de Desvinculación (reincorporación)" en Información Policial no puede estar vacío',
            ];
        }

        foreach ($fields as $field => $message) {
            if (!$affiliate->$field) {
                Session::flash('message', $message);
                return redirect('affiliate/' . $affiliate->id);
            }
        }
        $dates_global = $affiliate->getDatesGlobal($ret_fun_index == 1);
        $group_dates = [];
        $total_dates = Util::sumTotalContributions($dates_global);
        $dates = array(
            'id' => 0,
            'dates' => $dates_global,
            'name' => "Alta y Baja de la Policía Nacional Boliviana",
            'operator' => '**',
            'description' => "Fechas de Alta y Baja de la Policía Nacional Boliviana",
            'years' => intval($total_dates / 12),
            'months' => $total_dates % 12,
        );
        $group_dates[] = $dates;
        foreach (ContributionType::orderBy('id')->get() as $c) {
            // if($c->id != 1){
            $contributionsWithType = $affiliate->getContributionsWithType($c->id, $ret_fun_index == 1);
            if (sizeOf($contributionsWithType) > 0) {
                $sub_total_dates = Util::sumTotalContributions($contributionsWithType);
                $dates = array(
                    'id' => $c->id,
                    'dates' => $contributionsWithType,
                    'name' => $c->name,
                    'operator' => $c->operator,
                    'description' => $c->description,
                    'years' => intval($sub_total_dates / 12),
                    'months' => $sub_total_dates % 12,
                );
                if ($c->operator == '-') {
                    eval('$total_dates = ' . $total_dates . $c->operator . $sub_total_dates . ';');
                }
                $group_dates[] = $dates;
            }
            // }
        }
        $contributions = array(
            'contribution_types' => $group_dates,
            'years' => intval($total_dates / 12),
            'months' => $total_dates % 12
        );

        $contributions_select = $affiliate->contributions()
            ->select('id', 'month_year', 'retirement_fund', 'total', 'breakdown_id', 'contribution_type_id')->orderbyDesc('month_year')->get();

        // foreach ($contributions_select as $c) {
        //     $c->contribution_type_id = Util::classificationContribution($c->contribution_type_id, $c->breakdown_id, $c->total);
        // }
        $contribution_types = ContributionType::select('id', 'name')->orderBy('id')->get();
        $date_entry = $affiliate->date_entry;
        $date_derelict = $affiliate->date_derelict;

        $ret_fun_index = $retirement_fund->procedureIndex();
        // summary qualification
        $last_base_wage = $affiliate->getLastBaseWage($ret_fun_index == 1);
        $total_average_salary_quotable = $affiliate->selectedContributions() > 0 ? 0 : $affiliate->getTotalAverageSalaryQuotable(true, $ret_fun_index == 1)['total_average_salary_quotable'];

        $array_discounts = array();
        $array = DiscountType::where('module_id', 3)->get()->pluck('id');
        $results = array(array());
        foreach ($array as $element) {
            foreach ($results as $combination) {
                array_push($results, array_merge(array($element), $combination));
            }
        }
        foreach ($results as $value) {
            $sw = false;
            foreach ($value as $id) {
                //siempre tendra id
                if ($retirement_fund->discount_types()->find($id)) {
                    if (($retirement_fund->discount_types()->find($id)->pivot->amount > 0)) {
                        $sw = true;
                    }
                }
            }
            if ($sw) {
                $temp_total_discount = 0;
                foreach ($value as $id) {
                    if ($retirement_fund->discount_types()->find($id)) {
                        $temp_total_discount = $temp_total_discount + $retirement_fund->discount_types()->find($id)->pivot->amount;
                    }
                }
                $name = join(' - ', DiscountType::whereIn('id', $value)->orderBy('id', 'asc')->get()->pluck('name')->toArray());
                array_push($array_discounts, array('name' => $name, 'amount' => $temp_total_discount));
            }
        }
        if ($affiliate->hasAvailability()) {

            $availability = ContributionType::find(12);
            $array_discounts_availability = [];
            foreach ($array_discounts as $value) {
                array_push($array_discounts_availability, array('name' => ('Fondo de Retiro ' . ($value['name'] ? ' - ' . $value['name'] : '')), 'amount' => ($retirement_fund->subtotal_ret_fun - $value['amount'])));
            }
        } else {
            $array_discounts_availability = [];
            foreach ($array_discounts as $value) {
                array_push($array_discounts_availability, array('name' => ('Fondo de Retiro ' . ($value['name'] ? ' - ' . $value['name'] : '')), 'amount' => ($retirement_fund->subtotal_ret_fun - $value['amount'])));
            }
        }




        $data = [
            'retirement_fund' => $retirement_fund,
            'affiliate' =>  $affiliate,
            'beneficiaries' =>  $beneficiaries,
            'applicant' => $applicant,
            'advisor'  =>  $advisor,
            'legal_guardian'    =>  $guardian,
            'procedure_modalities' => $procedures_modalities,
            'file_modalities'   =>  $file_modalities,
            'documents' => $documents,
            'cities'    =>  $cities,
            'kinships'   =>  $kinships,
            'kinship_beneficiaries' => $kinship_beneficiaries,
            'cities_pluck' => $cities_pluck,
            'birth_cities' => $birth_cities,
            'states'    =>  $states,
            'financial_entities'    =>  $financial_entities,
            'ret_fun_records' => $ret_fun_records,
            'requirements'  =>  $procedure_requirements,
            'user'  =>  $user,
            'procedure_types'   =>  $procedure_types,
            'modalities'    =>  $modalities,
            'observation_types' => $observation_types,
            'observations' => $retirement_fund->ret_fun_observations,
            'submitted' =>  $submitted->pluck('ret_fun_submitted_documents.procedure_requirement_id', 'procedure_requirements.number'),
            'submit_documents' => $submitted->get(),
            'can_validate' =>  $can_validate,
            'can_cancel' =>  $can_cancel,
            'workflow_records' =>  $workflow_records,
            'first_wf_state' =>  $first_wf_state,
            'wf_states' =>  $wf_states,
            'is_editable'  =>  $is_editable,
            'wf_sequences_back'  =>  $wf_sequences_back,
            'all_contributions' => json_encode($contributions),
            'contributions_select' => $contributions_select,
            'contribution_types' => $contribution_types,
            'date_entry' => Util::parseMonthYearDate($date_entry),
            'date_derelict' => Util::parseMonthYearDate($date_derelict),
            'last_base_wage' => $last_base_wage,
            'total_average_salary_quotable' => $total_average_salary_quotable,
            'array_discounts_availability' => $array_discounts_availability,
        ];
        return view('ret_fun.show', $data);
    }
    private function getFlagy($num, $pos)
    {
        if ($num == ($pos + 1))
            return ", ";
        if ($num == ($pos + 2))
            return " y la suma de ";
        return;
    }
    /**
     * Show the form for editing the specified resource.
     *
     * @param  \Muserpol\RetirementFund  $retirementFund
     * @return \Illuminate\Http\Response
     */
    public function edit(RetirementFund $retirementFund)
    {
        //
    }
    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Muserpol\RetirementFund  $retirementFund
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, RetirementFund $retirementFund)
    {
        //
    }
    /**
     * Remove the specified resource from storage.
     *
     * @param  \Muserpol\RetirementFund  $retirementFund
     * @return \Illuminate\Http\Response
     */
    public function destroy(RetirementFund $retirementFund)
    {
        //
    }
    //funcion para agregar uuid a los registros que tienen null
    public static function add_uuid(){
        $ret_funs=RetirementFund::withTrashed()->get();
        foreach ($ret_funs as $ret_fun) {
            $ret_fun->uuid=Uuid::uuid1()->toString();
            $ret_fun->save();
        }
        return $ret_fun;
    }
    public function getAllRetFun(DataTables $datatables)
    {
        $retirement_funds = RetirementFund::with([
            'affiliate:id,identity_card,city_identity_card_id,first_name,second_name,last_name,mothers_last_name,surname_husband,gender,degree_id,degree_id,date_death,date_entry,date_derelict,date_last_contribution',
            'city_start:id,name,first_shortened',
            'wf_state:id,name,first_shortened',
            'procedure_modality:id,name,shortened,procedure_type_id',
            'procedure_modality.procedure_type:id,name',
            'workflow:id,name',
            'ret_fun_correlative',
            'ret_fun_beneficiaries'
        ])->select(
            'id',
            'code',
            'reception_date',
            'affiliate_id',
            'city_start_id',
            'inbox_state',
            'total',
            'wf_state_current_id',
            'procedure_modality_id',
            'ret_fun_procedure_id',
            'workflow_id',
            'total_availability',
            'subtotal_ret_fun'
        )
            ->where('code', 'not like', '%A')
            ->orderByDesc(DB::raw("split_part(code, '/',1)::integer"));
        return $datatables->eloquent($retirement_funds)
            ->addColumn('type', function ($ret_fun) {
                return $ret_fun->procedure_modality->procedure_type->name ?? null;
            })
            ->editColumn('inbox_state', function ($ret_fun) {
                return $ret_fun->inbox_state ? 'Validado' : 'Pendiente';
            })
            ->addColumn('phone_number', function ($ret_fun) {
                return optional($ret_fun->ret_fun_beneficiaries->where('type', 'S')->first())->phone_number;
            })
            ->addColumn('cell_phone_number', function ($ret_fun) {
                return optional($ret_fun->ret_fun_beneficiaries->where('type', 'S')->first())->cell_phone_number;
            })
            ->addColumn('file_date', function ($ret_fun) {
                return $this->getCorrelativeDate($ret_fun, 20);
            })
            ->addColumn('review_date', function ($ret_fun) {
                return $this->getCorrelativeDate($ret_fun, 21);
            })
            ->addColumn('individuals_account_date', function ($ret_fun) {
                return $this->getCorrelativeDate($ret_fun, 22);
            })
            ->addColumn('qualification_date', function ($ret_fun) {
                return $this->getCorrelativeDate($ret_fun, 23);
            })
            ->addColumn('dictum_date', function ($ret_fun) {
                return $this->getCorrelativeDate($ret_fun, 25);
            })
            ->addColumn('headship_date', function ($ret_fun) {
                return $this->getCorrelativeDate($ret_fun, 24);
            })
            ->addColumn('resolution_date', function ($ret_fun) {
                return $this->getCorrelativeDate($ret_fun, 26);
            })
            ->addColumn('liquidation_date', function ($ret_fun) {
                return $this->getCorrelativeDate($ret_fun, ID::wf_state()->liquidationFR);
            })
            ->addColumn('action', function ($ret_fun) {
                return Util::getRol()->id != 69? "<a href='/ret_fun/" . $ret_fun->id . "' class='btn btn-default'><i class='fa fa-eye'></i></a>":"";
            })
            ->make(true);
    }

    private function getCorrelativeDate($ret_fun, $stateId)
    {
        return optional($ret_fun->ret_fun_correlative->firstWhere('wf_state_id', $stateId))->date;
    }

    public function generateProcedure(Affiliate $affiliate)
    {

        $this->authorize('create', RetirementFund::class);
        $user = Auth::User();
        $affiliate = Affiliate::select('affiliates.id', 'identity_card', 'city_identity_card_id', 'registration', 'first_name', 'second_name', 'last_name', 'mothers_last_name', 'surname_husband', 'birth_date', 'gender', 'degrees.name as degree', 'civil_status', 'affiliate_states.name as affiliate_state', 'phone_number', 'cell_phone_number', 'date_derelict', 'date_entry', 'date_death', 'reason_death')
            ->leftJoin('degrees', 'affiliates.id', '=', 'degrees.id')
            ->leftJoin('affiliate_states', 'affiliates.affiliate_state_id', '=', 'affiliate_states.id')
            ->find($affiliate->id);
        # 3 id of ret_fun
        $procedure_types = ProcedureType::where('module_id', 3)->get();
        $procedure_requirements = ProcedureRequirement::select('procedure_requirements.id', 'procedure_documents.name as document', 'number', 'procedure_modality_id as modality_id','procedure_requirements.deleted_at')
            ->leftJoin('procedure_documents', 'procedure_requirements.procedure_document_id', '=', 'procedure_documents.id')
            ->whereNull('procedure_requirements.deleted_at')
            ->orderBy('procedure_requirements.procedure_modality_id', 'ASC')
            ->orderBy('procedure_requirements.number', 'ASC')
            ->get();

        $spouse = Spouse::where('affiliate_id', $affiliate->id)->first();
        if (!isset($spouse->id))
            $spouse = new Spouse();
        $modalities = ProcedureModality::where('procedure_type_id', '<=', '21')->select('id', 'name', 'procedure_type_id')->get();

        $kinships = Kinship::get();
        $kinship_beneficiaries = KinshipBeneficiary::get();

        $cities = City::get();

        $searcher = new SearcherController();

        $has_ret_fun = $affiliate->retirement_funds()->where('code','not like','%A%')->count() > 0 ? 1 : 0;

        $data = [
            'user' => $user,
            //'requirements' => $procedure_requirements,
            'procedure_types'    => $procedure_types,
            'modalities'    => $modalities,
            'affiliate'  => $affiliate,
            'kinships'  =>  $kinships,
            'kinship_beneficiaries'  =>  $kinship_beneficiaries,
            'cities'    =>  $cities,
            'ret'    =>  $cities,
            'spouse' =>  $spouse,
            'searcher'  =>  $searcher,
            'has_ret_fun' => $has_ret_fun
        ];

        //return $data;
        return view('ret_fun.create', $data);
    }

    public function storeLegalReview(Request $request)
    {
        $this->authorize('update', new RetFunSubmittedDocument);
        DB::transaction(function () use ($request) {
            foreach ($request->submit_documents as $document_array) {

                foreach ($document_array as $document) {
                    $submit_document = RetFunSubmittedDocument::find($document['id']);
                    $submit_document->is_valid = $document['status'];
                    $submit_document->comment = $document['comment'];
                    $submit_document->save();
                }
            }
            return $request;
        });
    }
    public function updateBeneficiaries(Request $request, $id)
    {
        $retirement_fund = RetirementFund::find($id);
        $this->authorize('update', new RetFunBeneficiary);
        $i = 0;
        $ben = 0;
        $beneficiaries_array_request = [];
        foreach (array_pluck($request->all(), 'id') as $value) {
            if ($value) {
                array_push($beneficiaries_array_request, $value);
            }
        }
        /* delete beneficiaries */
        $beneficiaries = $retirement_fund->ret_fun_beneficiaries;
        foreach ($beneficiaries as $ben) {
            $index = array_search($ben->id, $beneficiaries_array_request);
            if ($index === false) {
                $ben->delete();
            }
        }
        /*update info beneficiaries*/
        $beneficiaries = $retirement_fund->ret_fun_beneficiaries->toArray();
        foreach ($request->all() as $new_ben) {
            $found = [];
            if (isset($new_ben['id'])) {
                $found = array_filter($beneficiaries, function ($var) use ($new_ben) {
                    return ($var['id'] == $new_ben['id']);
                });
            }
            if ($found) {
                $old_ben = RetFunBeneficiary::find($new_ben['id']);
                $old_ben->city_identity_card_id = $new_ben['city_identity_card_id'];
                $old_ben->kinship_id = $new_ben['kinship_id'];
                $old_ben->identity_card = mb_strtoupper(trim($new_ben['identity_card']));
                $old_ben->last_name = mb_strtoupper(trim($new_ben['last_name']));
                $old_ben->mothers_last_name = mb_strtoupper(trim($new_ben['mothers_last_name']));
                $old_ben->first_name = mb_strtoupper(trim($new_ben['first_name']));
                $old_ben->second_name = mb_strtoupper(trim($new_ben['second_name']));
                $old_ben->surname_husband = mb_strtoupper(trim($new_ben['surname_husband']));
                $old_ben->birth_date = Util::verifyBarDate($new_ben['birth_date']) ? Util::parseBarDate($new_ben['birth_date']) : $new_ben['birth_date'];
                $old_ben->gender = $new_ben['gender'];
                $old_ben->state = $new_ben['state'] ?? false;

                if (is_null($new_ben['legal_representative'])) {
                    if ($ben_advisor = $old_ben->ret_fun_advisors->first()) {
                        // delete
                        $advisor_beneficiary = RetFunAdvisorBeneficiary::where('ret_fun_beneficiary_id', $old_ben->id)->where('ret_fun_advisor_id', $ben_advisor->id)->first();
                        $advisor_beneficiary->delete();
                    }
                    if ($ben_legal_guardian = $old_ben->legal_guardian->first()) {
                        //delete
                        $ben_legal_guardian = RetFunLegalGuardianBeneficiary::where('ret_fun_beneficiary_id', $old_ben->id)->where('ret_fun_legal_guardian_id', $ben_legal_guardian->id)->first();
                        $ben_legal_guardian->delete();
                    }
                } else {
                    switch ($new_ben['legal_representative']) {
                        //tutor
                        case 1:
                            //exists
                            $ben_advisor = $old_ben->ret_fun_advisors->first();
                            if (!$ben_advisor){
                                $ben_advisor = new RetFunAdvisor();
                            }
                            $ben_advisor->city_identity_card_id = isset($new_ben['advisor_city_identity_card_id']) ? intval($new_ben['advisor_city_identity_card_id'])  : null;
                            $ben_advisor->kinship_id = null;
                            $ben_advisor->identity_card = $new_ben['advisor_identity_card'] ?? null;
                            $ben_advisor->last_name = strtoupper(trim($new_ben['advisor_last_name'] ?? null));
                            $ben_advisor->mothers_last_name = strtoupper(trim($new_ben['advisor_mothers_last_name'] ?? null));
                            $ben_advisor->first_name = strtoupper(trim($new_ben['advisor_first_name'] ?? null));
                            $ben_advisor->second_name = strtoupper(trim($new_ben['advisor_second_name'] ?? null));
                            $ben_advisor->birth_date = Util::verifyBarDate($new_ben['advisor_birth_date']) ? Util::parseBarDate($new_ben['advisor_birth_date']) : $new_ben['advisor_birth_date'];
                            $ben_advisor->surname_husband = strtoupper(trim($new_ben['advisor_surname_husband'] ?? null));
                            $ben_advisor->gender = strtoupper(trim($new_ben['advisor_gender'] ?? null));
                            // $ben_advisor->phone_number = trim(implode(",", $new_ben['advisor_phone_number'] ?? []));
                            // $ben_advisor->cell_phone_number = trim(implode(",", $new_ben['advisor_cell_phone_number'] ?? []));
                            $ben_advisor->name_court = $new_ben['advisor_name_court'] ?? null;
                            $ben_advisor->resolution_number = $new_ben['advisor_resolution_number'] ?? null;
                            $ben_advisor->resolution_date = isset($new_ben['advisor_resolution_date']) ? (Util::verifyBarDate($new_ben['advisor_resolution_date']) ? Util::parseBarDate($new_ben['advisor_resolution_date']) : $new_ben['advisor_resolution_date']) : null;
                            $ben_advisor->type = "Natural";
                            $ben_advisor->save();
                            if (!empty($new_ben['kinship_beneficiary_id'])) {
                                $advisor_beneficiary = RetFunAdvisorBeneficiary::where('ret_fun_beneficiary_id', $old_ben->id)
                                    ->where('ret_fun_advisor_id', $ben_advisor->id)
                                    ->first();
                                if (!$advisor_beneficiary) {
                                    $advisor_beneficiary = new RetFunAdvisorBeneficiary();
                                    $advisor_beneficiary->ret_fun_beneficiary_id = $old_ben->id;
                                    $advisor_beneficiary->ret_fun_advisor_id = $ben_advisor->id;
                                    $advisor_beneficiary->kinship_beneficiary_id = $new_ben['kinship_beneficiary_id'];
                                    $advisor_beneficiary->save();
                                } else {
                                    $advisor_beneficiary->kinship_beneficiary_id = $new_ben['kinship_beneficiary_id'];
                                    $advisor_beneficiary->save();
                                }
                            }

                            break;
                        //apoderado
                        case 2:
                            if ($ben_legal_guardian = $old_ben->legal_guardian->first()) { } else {
                                $ben_legal_guardian = new RetFunLegalGuardian();
                                $ben_legal_guardian->retirement_fund_id = $retirement_fund->id; // is necessary?
                            }
                            $ben_legal_guardian->identity_card = strtoupper(trim($new_ben['legal_guardian_identity_card'] ?? null));
                            $ben_legal_guardian->city_identity_card_id = isset($new_ben['legal_guardian_city_identity_card_id']) ? intval($new_ben['legal_guardian_city_identity_card_id']) : null;
                            $ben_legal_guardian->first_name = strtoupper(trim($new_ben['legal_guardian_first_name'] ?? null));
                            $ben_legal_guardian->second_name = strtoupper(trim($new_ben['legal_guardian_second_name'] ?? null));
                            $ben_legal_guardian->last_name = strtoupper(trim($new_ben['legal_guardian_last_name'] ?? null));
                            $ben_legal_guardian->mothers_last_name = strtoupper(trim($new_ben['legal_guardian_mothers_last_name'] ?? null));
                            $ben_legal_guardian->surname_husband = strtoupper(trim($new_ben['legal_guardian_surname_husband'] ?? null));

                            $ben_legal_guardian->gender = $new_ben['legal_guardian_gender'] ?? null;
                            $ben_legal_guardian->number_authority = $new_ben['legal_guardian_number_authority'] ?? null;
                            $ben_legal_guardian->notary_of_public_faith = $new_ben['legal_guardian_notary_of_public_faith'] ?? null;
                            $ben_legal_guardian->notary = $new_ben['legal_guardian_notary'] ?? null;
                            $ben_legal_guardian->date_authority = isset($new_ben['legal_guardian_date_authority']) ? (Util::verifyBarDate($new_ben['legal_guardian_date_authority']) ? Util::parseBarDate($new_ben['legal_guardian_date_authority']) : $new_ben['legal_guardian_date_authority']) : null;
                            $ben_legal_guardian->save();
                            if ($old_ben->legal_guardian->first()) { } else {
                                $ben_legal_guardian_new = new RetFunLegalGuardianBeneficiary();
                                $ben_legal_guardian_new->ret_fun_beneficiary_id = $old_ben->id;
                                $ben_legal_guardian_new->ret_fun_legal_guardian_id = $ben_legal_guardian->id;
                                $ben_legal_guardian_new->save();
                            }
                            break;
                        default:
                            break;
                    }
                }

                if ($old_ben->type == 'S' && $retirement_fund->procedure_modality_id !=  ID::retFun()->fallecimiento_id && $retirement_fund->procedure_modality_id !=  ID::retFunGlobalPay()->fallecimiento_id && $retirement_fund->procedure_modality_id !=  ID::retFunDevPay()->fallecimiento_id) {
                    $update_affilaite = Affiliate::find($retirement_fund->affiliate_id);
                    $update_affilaite->identity_card = $old_ben->identity_card;
                    $update_affilaite->first_name = $old_ben->first_name;
                    $update_affilaite->second_name = $old_ben->second_name;
                    $update_affilaite->last_name = $old_ben->last_name;
                    $update_affilaite->mothers_last_name = $old_ben->mothers_last_name;
                    $update_affilaite->gender = $old_ben->gender;
                    $update_affilaite->birth_date = Util::verifyBarDate($old_ben->birth_date) ? Util::parseBarDate($old_ben->birth_date) : $old_ben->birth_date;
                    $update_affilaite->city_identity_card_id = $old_ben->city_identity_card_id;
                    $update_affilaite->surname_husband = $old_ben->surname_husband;
                    $update_affilaite->save();
                }
                if ($old_ben->type == 'S') {
                    $old_ben->phone_number = trim(implode(",", $new_ben['phone_number']));
                    $old_ben->cell_phone_number = trim(implode(",", $new_ben['cell_phone_number']));

                    //$old_ben->cell_phone_number = trim(implode(",", $new_ben['cell_phone_number']));
                    /*Actualizar direccion  */
                    if (sizeOf($old_ben->address) > 0) {
                        $address_id = $old_ben->address()->first()->id;
                        $address = Address::find($address_id);
                        if ($new_ben['address'][0]['zone'] || $new_ben['address'][0]['street'] || $new_ben['address'][0]['number_address']) {
                            $address->city_address_id = $new_ben['address'][0]['city_address_id'] ?? 1;
                            $address->zone = $new_ben['address'][0]['zone'];
                            $address->street = $new_ben['address'][0]['street'];
                            $address->number_address = $new_ben['address'][0]['number_address'];
                            $address->save();
                            if ($retirement_fund->procedure_modality_id != ID::retFun()->fallecimiento_id) {
                                $update_affilaite = Affiliate::find($retirement_fund->affiliate_id);
                                if ($update_affilaite->address->contains($address->id)) { } else {
                                    $update_affilaite->address()->save($address);
                                }
                            }
                        } else {
                            if ($retirement_fund->procedure_modality_id != ID::retFun()->fallecimiento_id) {
                                $update_affilaite = Affiliate::find($retirement_fund->affiliate_id);
                                $update_affilaite->address()->detach($address->id);
                            }
                            $old_ben->address()->detach($address->id);
                            $address->delete();
                        }
                    } else {
                        if ($new_ben['address']) {
                            $address = new Address();
                            $address->city_address_id = $new_ben['address'][0]['city_address_id'] ?? 1;
                            $address->zone = $new_ben['address'][0]['zone'];
                            $address->street = $new_ben['address'][0]['street'];
                            $address->number_address = $new_ben['address'][0]['number_address'];
                            $address->save();
                            $old_ben->address()->save($address);
                            if ($retirement_fund->procedure_modality_id != ID::retFun()->fallecimiento_id) {
                                $update_affilaite = Affiliate::find($retirement_fund->affiliate_id);
                                $update_affilaite->address()->save($address);
                            }
                        }
                    }
                }
                $old_ben->save();
            } else {
                $beneficiary = new RetFunBeneficiary();
                $beneficiary->retirement_fund_id = $id;
                $beneficiary->city_identity_card_id = $new_ben['city_identity_card_id'];
                $beneficiary->kinship_id = $new_ben['kinship_id'];
                $beneficiary->identity_card = mb_strtoupper(trim($new_ben['identity_card']));
                $beneficiary->last_name = mb_strtoupper(trim($new_ben['last_name']));
                $beneficiary->mothers_last_name = mb_strtoupper(trim($new_ben['mothers_last_name']));
                $beneficiary->first_name = mb_strtoupper(trim($new_ben['first_name']));
                $beneficiary->second_name = mb_strtoupper(trim($new_ben['second_name']));
                $beneficiary->surname_husband = mb_strtoupper(trim($new_ben['surname_husband']));
                $beneficiary->birth_date = Util::verifyBarDate($new_ben['birth_date']) ? Util::parseBarDate($new_ben['birth_date']) : $new_ben['birth_date'];
                $beneficiary->gender = $new_ben['gender'];
                $beneficiary->state = $new_ben['state'];
                // $old_ben->state = $new_ben['state'];
                // $beneficiary->phone_number = trim(implode(",", $request->applicant_phone_number));
                // $beneficiary->cell_phone_number = trim(implode(",", $request->applicant_cell_phone_number));
                $beneficiary->type = ID::beneficiary()->normal;
                $beneficiary->save();

                switch ($new_ben['legal_representative']) {
                    //tutor
                    case 1:
                        //exists
                        $ben_advisor = new RetFunAdvisor();

                        $ben_advisor->city_identity_card_id = $new_ben['advisor_city_identity_card_id'];
                        $ben_advisor->kinship_id = null;
                        $ben_advisor->identity_card = $new_ben['advisor_identity_card'];
                        $ben_advisor->last_name = strtoupper(trim($new_ben['advisor_last_name']));
                        $ben_advisor->mothers_last_name = strtoupper(trim($new_ben['advisor_mothers_last_name']));
                        $ben_advisor->first_name = strtoupper(trim($new_ben['advisor_first_name']));
                        $ben_advisor->second_name = strtoupper(trim($new_ben['advisor_second_name']));
                        $ben_advisor->surname_husband = strtoupper(trim($new_ben['advisor_surname_husband']));
                        $ben_advisor->gender = strtoupper(trim($new_ben['advisor_gender']));
                        // $ben_advisor->phone_number = trim(implode(",", $new_ben['advisor_phone_number'] ?? []));
                        // $ben_advisor->cell_phone_number = trim(implode(",", $new_ben['advisor_cell_phone_number'] ?? []));
                        $ben_advisor->name_court = $new_ben['advisor_name_court'];
                        $ben_advisor->resolution_number = $new_ben['advisor_resolution_number'];
                        $ben_advisor->resolution_date = Util::verifyBarDate($new_ben['advisor_resolution_date']) ? Util::parseBarDate($new_ben['advisor_resolution_date']) : $new_ben['advisor_resolution_date'];
                        $ben_advisor->type = "Natural";
                        $ben_advisor->save();

                        $advisor_beneficiary = new RetFunAdvisorBeneficiary();
                        $advisor_beneficiary->ret_fun_beneficiary_id = $beneficiary->id;
                        $advisor_beneficiary->ret_fun_advisor_id = $ben_advisor->id;
                        $advisor_beneficiary->kinship_beneficiary_id = $new_ben['kinship_beneficiary_id'] ?? null;
                        $advisor_beneficiary->save();
                        break;
                    //apoderado
                    case 2:

                        $ben_legal_guardian = new RetFunLegalGuardian();
                        $ben_legal_guardian->retirement_fund_id = $retirement_fund->id; // is necessary?
                        // }
                        $ben_legal_guardian->identity_card = strtoupper(trim($new_ben['legal_guardian_identity_card']));
                        $ben_legal_guardian->city_identity_card_id = $new_ben['legal_guardian_city_identity_card_id'];
                        $ben_legal_guardian->first_name = strtoupper(trim($new_ben['legal_guardian_first_name']));
                        $ben_legal_guardian->second_name = strtoupper(trim($new_ben['legal_guardian_second_name']));
                        $ben_legal_guardian->last_name = strtoupper(trim($new_ben['legal_guardian_last_name']));
                        $ben_legal_guardian->mothers_last_name = strtoupper(trim($new_ben['legal_guardian_mothers_last_name']));
                        $ben_legal_guardian->surname_husband = strtoupper(trim($new_ben['legal_guardian_surname_husband']));

                        $ben_legal_guardian->gender = $new_ben['legal_guardian_gender'];
                        $ben_legal_guardian->number_authority = $new_ben['legal_guardian_number_authority'];
                        $ben_legal_guardian->notary_of_public_faith = $new_ben['legal_guardian_notary_of_public_faith'];
                        $ben_legal_guardian->notary = $new_ben['legal_guardian_notary_of_public_faith'];
                        $ben_legal_guardian->date_authority = Util::verifyBarDate($new_ben['legal_guardian_date_authority']) ? Util::parseBarDate($new_ben['legal_guardian_date_authority']) : $new_ben['legal_guardian_date_authority'];
                        $ben_legal_guardian->save();

                        $ben_legal_guardian_new = new RetFunLegalGuardianBeneficiary();
                        $ben_legal_guardian_new->ret_fun_beneficiary_id = $beneficiary->id;
                        $ben_legal_guardian_new->ret_fun_legal_guardian_id = $ben_legal_guardian->id;
                        $ben_legal_guardian_new->save();

                        break;
                    default:
                        break;
                }
                // }


            }
        }
        $beneficiaries = RetirementFund::find($id)->ret_fun_beneficiaries()->with(['kinship', 'city_identity_card', 'address'])->orderByDesc('type')->orderBy('id')->get();
        foreach ($beneficiaries as $b) {
            $b->phone_number = explode(',', $b->phone_number);
            $b->cell_phone_number = explode(',', $b->cell_phone_number);
            if (!sizeOf($b->address) > 0 && $b->type == 'S') {
                $b->address[] = array('zone' => null, 'street' => null, 'number_address' => null);
            }

            $b->legal_representative = null;
            if ($beneficiary_advisor = $b->ret_fun_advisors->first()) {
                $b->legal_representative = 1;
                $b->advisor_identity_card = $beneficiary_advisor->identity_card;
                $b->advisor_city_identity_card_id = $beneficiary_advisor->city_identity_card_id;
                $b->advisor_first_name = $beneficiary_advisor->first_name;
                $b->advisor_second_name = $beneficiary_advisor->second_name;
                $b->advisor_last_name = $beneficiary_advisor->last_name;
                $b->advisor_mothers_last_name = $beneficiary_advisor->mothers_last_name;
                $b->advisor_surname_husband = $beneficiary_advisor->surname_husband;
                $b->advisor_birth_date = $beneficiary_advisor->birth_date;
                $b->advisor_gender = $beneficiary_advisor->gender;
                $b->advisor_name_court = $beneficiary_advisor->name_court;
                $b->advisor_resolution_number = $beneficiary_advisor->resolution_number;
                $b->advisor_resolution_date = $beneficiary_advisor->resolution_date;
                $kinship = $beneficiary_advisor->kinship_beneficiaries($b->id)->first();
                $b->kinship_beneficiary_id = $kinship ? $kinship->id : null;
            }
            if ($beneficiary_legal_guardian = $b->legal_guardian->first()) {
                $b->legal_representative = 2;
                $b->legal_guardian_identity_card = $beneficiary_legal_guardian->identity_card;
                $b->legal_guardian_city_identity_card_id = $beneficiary_legal_guardian->city_identity_card_id;
                $b->legal_guardian_first_name = $beneficiary_legal_guardian->first_name;
                $b->legal_guardian_second_name = $beneficiary_legal_guardian->second_name;
                $b->legal_guardian_last_name = $beneficiary_legal_guardian->last_name;
                $b->legal_guardian_mothers_last_name = $beneficiary_legal_guardian->mothers_last_name;
                $b->legal_guardian_surname_husband = $beneficiary_legal_guardian->surname_husband;
                $b->legal_guardian_gender = $beneficiary_legal_guardian->gender;
                $b->legal_guardian_number_authority = $beneficiary_legal_guardian->number_authority;
                $b->legal_guardian_notary_of_public_faith = $beneficiary_legal_guardian->notary_of_public_faith;
                $b->legal_guardian_notary = $beneficiary_legal_guardian->notary;
                $b->legal_guardian_date_authority = $beneficiary_legal_guardian->date_authority;
            }
        }
        $data = [
            'beneficiaries' => $beneficiaries,
        ];
        return $data;
    }
    public function updateBeneficiaryTestimony(Request $request, $ret_fun_id)
    {
        $ret_fun = RetirementFund::find($ret_fun_id);
        $affiliate = $ret_fun->affiliate;

        $testimonies_array_request =  array();
        foreach (array_pluck($request->all(), 'id') as $key => $value) {
            if ($value) {
                array_push($testimonies_array_request, $value);
            }
        }
        $testimonies = $affiliate->testimony;
        foreach ($testimonies as $key => $t) {
            $index = array_search($t->id, $testimonies_array_request);
            if ($index === false) {
                $t->delete();
            }
        }
        foreach ($request->all() as $key => $t) {
            if ($t['id'] == 'new') {
                $testimony = new Testimony();
            } else {
                $testimony = Testimony::find($t['id']);
            }
            $testimony->user_id = Util::getAuthUser()->id;
            $testimony->affiliate_id = $affiliate->id;
            $testimony->document_type = $t['document_type'];
            $testimony->number = $t['number'];
            $testimony->date = $t['date'];
            $testimony->court = $t['court'];
            $testimony->place = $t['place'];
            $testimony->notary = $t['notary'];
            $testimony->save();
            $ids_ben = array();
            foreach ($t['ret_fun_beneficiaries'] as $ben) {
                array_push($ids_ben, $ben['id']);
            }
            $testimony->ret_fun_beneficiaries()->sync($ids_ben);
        }
        return;
    }
    public function getTestimonies($ret_fun_id)
    {
        $ret_fun = RetirementFund::find($ret_fun_id);
        $affiliate = $ret_fun->affiliate;
        $testimonies = $affiliate->testimony()->with('ret_fun_beneficiaries')->get();
        return $testimonies;
    }
    public function updateInformation(Request $request)
    {
        $retirement_fund = RetirementFund::find($request->id);
        $this->authorize('update', $retirement_fund);
        $retirement_fund->city_end_id = $request->city_end_id;
        $retirement_fund->city_start_id = $request->city_start_id;
        $retirement_fund->reception_date = $request->reception_date;
        $retirement_fund->ret_fun_state_id = $request->ret_fun_state_id;
        if ($retirement_fund->ret_fun_state_id == ID::state()->eliminado) {
            $count_delete = RetirementFund::where('code','like',$retirement_fund->code.'%')->count('code');
            $retirement_fund->code .= str_repeat("A", $count_delete);
            $retirement_fund->deleted_at = now();
        }
        $retirement_fund->save();
        $datos = array('retirement_fund' => $retirement_fund, 'procedure_modality' => $retirement_fund->procedure_modality, 'city_start' => $retirement_fund->city_start, 'city_end' => $retirement_fund->city_end);
        return $datos;
    }
    //--**CALCULOS DE CALIFICACIÓN**--//
    public function qualification($ret_fun_id)
    {
        $retirement_fund = RetirementFund::find($ret_fun_id);
        // $this->authorize('qualify', $retirement_fund);
        $affiliate = $retirement_fund->affiliate;

        $current_procedure = RetFunProcedure::where('is_enabled', '=', true)->first();
        if (!$current_procedure) {
            return "error: Verifique si existen procedures activos";
        }

        $ret_fun_index = $retirement_fund->procedureIndex();
        $dates_global = $affiliate->getDatesGlobal($ret_fun_index == 1);
        /*  qualification*/
        // $c=ContributionType::find(1);
        $group_dates = [];
        $total_dates = Util::sumTotalContributions($dates_global);
        $dates = array(
            'id' => 0,
            'dates' => $dates_global,
            'name' => "Alta y Baja de la Policía Nacional Boliviana",
            'operator' => '**',
            'description' => "Fechas de Alta y Baja de la Policía Nacional Boliviana",
            'years' => intval($total_dates / 12),
            'months' => $total_dates % 12,
        );
        $group_dates[] = $dates;
        foreach (ContributionType::orderBy('id')->get() as $c) {
            // if($c->id != 1){
            $contributionsWithType = $affiliate->getContributionsWithType($c->id, $ret_fun_index == 1);
            if (sizeOf($contributionsWithType) > 0) {
                $sub_total_dates = Util::sumTotalContributions($contributionsWithType);
                $dates = array(
                    'id' => $c->id,
                    'dates' => $contributionsWithType,
                    'name' => $c->name,
                    'operator' => $c->operator,
                    'description' => $c->description,
                    'years' => intval($sub_total_dates / 12),
                    'months' => $sub_total_dates % 12,
                );
                if ($c->operator == '-') {
                    eval('$total_dates = ' . $total_dates . $c->operator . $sub_total_dates . ';');
                }
                $group_dates[] = $dates;
            }
            // }
        }
        $contributions = array(
            'contribution_types' => $group_dates,
            'years' => intval($total_dates / 12),
            'months' => $total_dates % 12
        );

        $total_availability_aporte = null;
        $total_availability_aporte_frps = null;
        if ($affiliate->hasAvailability()) {
            $availability = $affiliate->getContributionsAvailability();
            $total_availability_aporte = array_sum(array_column($availability, 'total'));
            $total_availability_aporte_frps = array_sum(array_column($availability, 'retirement_fund'));
        }


        $data = [
            'retirement_fund' => $retirement_fund,
            'affiliate' => $affiliate,
            'current_procedure' => $current_procedure,
            'all_contributions' => json_encode($contributions),
            'total_availability_aporte' => $total_availability_aporte,
            'total_availability_aporte_frps' => $total_availability_aporte_frps,
        ];
        $data = array_merge($data, $affiliate->getTotalAverageSalaryQuotable(true, $ret_fun_index == 1));
        return view('ret_fun.qualification', $data);
    }
    //--**OBTIENE LOS DATOS DE DATOS ECONOMICOS **--//
    public function getAverageQuotable(Request $request, $id)
    {
        $rules = [
            'service_years' => 'required|numeric|min:0|max:100',
            'service_months' => 'required|numeric|min:0|max:12',
        ];
        $messages = [];

        try {
            $validator = Validator::make($request->all(), $rules, $messages)->validate();
        } catch (ValidationException $exception) {
            return response()->json([
                'status' => 'error',
                'msg' => 'Error',
                'errors' => $exception->errors(),
            ], 403);
        }
        $current_procedure = Util::getRetFunCurrentProcedure();
        $retirement_fund = RetirementFund::find($id);
        $isReinstatement = $retirement_fund->procedureIndex() == 1;

        $affiliate = $retirement_fund->affiliate;
        $affiliate->service_years = $request->service_years;
        $affiliate->service_months = $request->service_months;
        $affiliate->save();
        $lastBaseWage = $affiliate->getLastBaseWage($isReinstatement);
        $total_quotes = $affiliate->getTotalQuotes($isReinstatement);
        $total_salary_quotable = $affiliate->getTotalAverageSalaryQuotable(true, $isReinstatement);
        $procedure_type_id =$retirement_fund->procedure_modality->procedure_type->id;
        $global_pay = false;
        $temp = [];
        $validate_limit_average = false;
        if($total_salary_quotable['total_average_salary_quotable']>$current_procedure->limit_average){
            $validate_limit_average = true;
        }
        if($procedure_type_id == 21){//DA
            $data = [
                'total_aporte' => $total_salary_quotable['total_retirement_fund'],
                'global_pay' => true,
                'total_quotes' => $total_quotes,
                'lastBaseWage' => $lastBaseWage,
                'total_salary_quotable' => $total_salary_quotable,
                'validate_limit_average' => false,
            ];
        }else{
            if ($total_quotes >= $current_procedure->contributions_number && $procedure_type_id == 2) { } else {
                $global_pay = true;
                $total_aporte = $total_salary_quotable['total_retirement_fund'];
                $yield = $total_aporte + (($total_aporte * $current_procedure->annual_yield) / 100);
                //$yield = Util::compoundInterest($total_salary_quotable['contributions'], $affiliate);
                $administrative_expenses = 0;
                $less_administrative_expenses = $yield;
                $temp = [
                    'total_aporte' => $total_aporte,
                    'yield' => $yield,
                    'administrative_expenses' => $administrative_expenses,
                    'less_administrative_expenses' => $less_administrative_expenses,
                ];
            }
            $data = [
                'global_pay' => $global_pay,
                'total_quotes' => $total_quotes,
                'lastBaseWage' => $lastBaseWage,
                'total_salary_quotable' => $total_salary_quotable,
                'validate_limit_average' => $validate_limit_average,
            ];
        }
        $data =  array_merge($data, $temp);
        return $data;
    }
    private function compoundInterest($contributions, Affiliate $affiliate)
    {
        $total = 0;
        $date_entry = Carbon::createFromFormat('m/Y', $affiliate->date_entry);
        $date_last_contribution = Carbon::createFromFormat('m/Y', $affiliate->date_last_contribution);
        $months_entry = ($date_entry->format('Y') * 12) + $date_entry->format('m');
        $months_dereliect = ($date_last_contribution->format('Y') * 12) + $date_last_contribution->format('m');
        $frecuency = 0;
        $interest_rate = 1.05; //replace by procedure interest rate
        foreach ($contributions as $contribution) {
            $subtotal = round($contribution->total * pow($interest_rate, (($months_dereliect - ($months_entry + $frecuency))) / 12), 2);
            $frecuency++;
            $total = round($total + $subtotal, 2);
        }
        return $total;
    }
    public function getDataQualificationCertification(DataTables $datatables, $retirement_fund_id)
    {
        $retirement_fund = RetirementFund::find($retirement_fund_id);
        $ret_fund_index = $retirement_fund->procedureIndex();
        $affiliate = $retirement_fund->affiliate;
        $contributions = $affiliate->getContributionsPlus(true, $ret_fund_index == 1);
        return $datatables->of($contributions)
            ->editColumn('month_year', function ($contribution) {
                return Util::getDateFormat($contribution->month_year);
            })
            ->editColumn('base_wage', function ($contribution) {
                return $contribution->base_wage;
            })
            ->editColumn('seniority_bonus', function ($contribution) {
                return $contribution->seniority_bonus;
            })
            ->editColumn('total', function ($contribution) {
                return $contribution->total;
            })
            ->editColumn('retirement_fund', function ($contribution) {
                return $contribution->retirement_fund;
            })
            ->editColumn('quotable_salary', function ($contribution) {
                $quotable_salary = $contribution->seniority_bonus + $contribution->base_wage;
                return $quotable_salary;
            })
            ->addIndexColumn()
            ->make(true);
    }
    public function getDataQualificationAvailability(DataTables $datatables, $retirement_fund_id)
    {
        $retirement_fund = RetirementFund::find($retirement_fund_id);
        $affiliate = $retirement_fund->affiliate;
        $contributions = $affiliate->getContributionsAvailability();
        return $datatables->of($contributions)
            ->editColumn('month_year', function ($contribution) {
                return Util::getDateFormat($contribution->month_year);
            })
            ->editColumn('base_wage', function ($contribution) {
                return $contribution->base_wage;
            })
            ->editColumn('seniority_bonus', function ($contribution) {
                return $contribution->seniority_bonus;
            })
            ->editColumn('total', function ($contribution) {
                return $contribution->total;
            })
            ->editColumn('retirement_fund', function ($contribution) {
                return $contribution->retirement_fund;
            })
            ->editColumn('quotable_salary', function ($contribution) {
                $quotable_salary = $contribution->seniority_bonus + $contribution->base_wage;
                return $quotable_salary;
            })
            ->addIndexColumn()
            ->make(true);
    }
    public function qualificationCertification($id)
    {
        $retirement_fund = RetirementFund::find($id);
        $number_contributions = Util::getRetFunCurrentProcedure()->contributions_number;
        $number = Util::getNextAreaCode($retirement_fund->id);
        if ($retirement_fund) {
            $affiliate = $retirement_fund->affiliate;
            $data = [
                'retirement_fund' => $retirement_fund,
                'number_contributions' => $number_contributions,
            ];
            $data = array_merge($data, $affiliate->getTotalAverageSalaryQuotable());
            return view('ret_fun.qualification_certification', $data);
        } else {
            // return redirect('ret_fun');
        }
    }
    //--**METODO PARA GUARDAR LOS DATOS ECONOMICOS***--//
    public function saveAverageQuotable(Request $request, $id)
    {
        
        $retirement_fund = RetirementFund::find($id);
        $affiliate = $retirement_fund->affiliate;
        $ret_fund_index = $retirement_fund->procedureIndex();
        $total_quotes = $affiliate->getTotalQuotes($ret_fund_index == 1);
        $getTotalAverageSalaryQuotable=$affiliate->getTotalAverageSalaryQuotable(true, $ret_fund_index == 1);
        $current_procedure = Util::getRetFunCurrentProcedure();
        $number_contributions = $current_procedure->contributions_number;
        
        //DEVOLUCIÓN DE APORTES
        if($retirement_fund->procedure_modality->procedure_type->id == 21){
            $total_aporte = $getTotalAverageSalaryQuotable['total_retirement_fund'];
            $total_average_salary_quotable = $getTotalAverageSalaryQuotable['total_average_salary_quotable'];
            $retirement_fund->average_quotable = $total_average_salary_quotable;
            $retirement_fund->used_limit_average = $total_average_salary_quotable;
            $sub_total_ret_fun =  $total_aporte;
            $total_ret_fun = $total_aporte;
        }
        //PAGO DE FONDO DE RETIRO
        if ($total_quotes >= $number_contributions && $retirement_fund->procedure_modality->procedure_type->id == 2) {
            if($getTotalAverageSalaryQuotable['total_average_salary_quotable']>$current_procedure->limit_average){
                $total_average_salary_quotable = $current_procedure->limit_average;
                $retirement_fund->used_limit_average = $current_procedure->limit_average;
            }else{
                $total_average_salary_quotable = $getTotalAverageSalaryQuotable['total_average_salary_quotable'];
                $retirement_fund->used_limit_average = $getTotalAverageSalaryQuotable['total_average_salary_quotable'];
            }
            $retirement_fund->average_quotable = $getTotalAverageSalaryQuotable['total_average_salary_quotable'];
            $sub_total_ret_fun = ($total_quotes / 12) * $total_average_salary_quotable;
            $total_ret_fun = ($total_quotes / 12) * $total_average_salary_quotable;
        }
        //PAGO GLOBAL DE APORTES
        if($retirement_fund->procedure_modality->procedure_type->id == 1){
            $total_aporte = $getTotalAverageSalaryQuotable['total_retirement_fund'];
            $yield = $total_aporte + (($total_aporte * $current_procedure->annual_yield) / 100);
            $administrative_expenses = 0;
            $less_administrative_expenses = $yield - $administrative_expenses;
            $sub_total_ret_fun = $less_administrative_expenses;
            $total_ret_fun = $less_administrative_expenses;
            $retirement_fund->average_quotable = $total_aporte;
            $retirement_fund->used_limit_average = $total_aporte;
        }

        $retirement_fund->save();

        $discounts = $retirement_fund->discount_types()->whereIn('discount_types.id', [1, 2, 3, 11])->get();
        $guarantors = InfoLoan::where('retirement_fund_id', $retirement_fund->id)->get();
        foreach ($guarantors as $value) {
            $value->full_name = $value->affiliate_guarantor->fullName();
            $value->identity_card = $value->affiliate_guarantor->identity_card;
        }
        $data = [
            'guarantors' => $guarantors,
            'discounts' => $discounts,
            'sub_total_ret_fun' => $sub_total_ret_fun,
            'total_ret_fun' => $total_ret_fun,
        ];
        return $data;
    }
    //--**METODO PARA ALMACENAR EL TOTAL**//
    public function saveTotalRetFun(Request $request, $id)
    {
        $retirement_fund = RetirementFund::find($id);
        $ret_fund_index = $retirement_fund->procedureIndex();
        $affiliate = $retirement_fund->affiliate;
        $isReinstatement = $ret_fund_index == 1;
        $total_quotes = $affiliate->getTotalQuotes($isReinstatement);
        $current_procedure = Util::getRetFunCurrentProcedure();
        $totalAverageSalaryQuotable = $affiliate->getTotalAverageSalaryQuotable(true, $isReinstatement);
        $number_contributions = $current_procedure->contributions_number;
        if($retirement_fund->procedure_modality->procedure_type->id == 21){
            $total_aporte= $totalAverageSalaryQuotable['total_retirement_fund'];
            $sub_total_ret_fun = $total_aporte;
        }else{
            if ($total_quotes >= $number_contributions && $retirement_fund->procedure_modality->procedure_type->id == 2 ) {
                $total_average_salary_quotable = $totalAverageSalaryQuotable['total_average_salary_quotable'];
                $sub_total_ret_fun = ($total_quotes / 12) * $total_average_salary_quotable;
            } else {//PGA
                $total_aporte = $totalAverageSalaryQuotable['total_retirement_fund'];
                // $yield = Util::compoundInterest($affiliate->getContributionsPlus(), $affiliate);
                $yield = $total_aporte + (($total_aporte * $current_procedure->annual_yield) / 100);
                $administrative_expenses = 0;
                $less_administrative_expenses = $yield;
                $sub_total_ret_fun = $less_administrative_expenses;
            }
        }

        $advance_payment = $request->advancePayment ?? 0;
        $retention_loan_payment = $request->retentionLoanPayment ?? 0;
        $retention_guarantor = $request->retentionGuarantor ?? 0;
        $retention_judicial = $request->judicialRetentionAmount ?? 0;

        $total_ret_fun = $sub_total_ret_fun - $advance_payment - $retention_loan_payment - $retention_guarantor - $retention_judicial;

        $retirement_fund->subtotal_ret_fun = $sub_total_ret_fun;
        $retirement_fund->total_ret_fun = $total_ret_fun;
        if (!$affiliate->hasAvailability()) {
            $retirement_fund->total = $total_ret_fun;
        }

        //mejorar
        $discount_type = DiscountType::where('shortened', 'anticipo')->first();
        if ($advance_payment >= 0) {
            if ($retirement_fund->discount_types->contains($discount_type->id)) {
                $retirement_fund->discount_types()->updateExistingPivot($discount_type->id, ['amount' => $advance_payment, 'date' => $request->advancePaymentDate, 'code' => $request->advancePaymentCode, 'note_code' => $request->advancePaymentNoteCode, 'note_code_date' => $request->advancePaymentNoteCodeDate]);
            } else {
                $retirement_fund->discount_types()->save($discount_type, ['amount' => $advance_payment, 'date' => $request->advancePaymentDate, 'code' => $request->advancePaymentCode, 'note_code' => $request->advancePaymentNoteCode, 'note_code_date' => $request->advancePaymentNoteCodeDate]);
            }
        } else {
            $retirement_fund->discount_types()->detach($discount_type->id);
        }
        $discount_type = DiscountType::where('shortened', 'prestamo')->first();
        if ($retention_loan_payment >= 0) {
            if ($retirement_fund->discount_types->contains($discount_type->id)) {
                $retirement_fund->discount_types()->updateExistingPivot($discount_type->id, ['amount' => $retention_loan_payment, 'date' => $request->retentionLoanPaymentDate, 'code' => $request->retentionLoanPaymentCode, 'note_code' => $request->retentionLoanPaymentNoteCode, 'note_code_date' => $request->retentionLoanPaymentNoteCodeDate]);
            } else {
                $retirement_fund->discount_types()->save($discount_type, ['amount' => $retention_loan_payment, 'date' => $request->retentionLoanPaymentDate, 'code' => $request->retentionLoanPaymentCode, 'note_code' => $request->retentionLoanPaymentNoteCode, 'note_code_date' => $request->retentionLoanPaymentNoteCodeDate]);
            }
        } else {
            $retirement_fund->discount_types()->detach($discount_type->id);
        }
        $discount_type = DiscountType::where('shortened', 'garantes')->first();
        if ($retention_guarantor >= 0) {
            if ($retirement_fund->discount_types->contains($discount_type->id)) {
                $retirement_fund->discount_types()->updateExistingPivot($discount_type->id, ['amount' => $retention_guarantor, 'date' => $request->retentionGuarantorDate, 'code' => $request->retentionGuarantorCode, 'note_code' => $request->retentionGuarantorNoteCode, 'note_code_date' => $request->retentionGuarantorNoteCodeDate]);
            } else {
                $retirement_fund->discount_types()->save($discount_type, ['amount' => $retention_guarantor, 'date' => $request->retentionGuarantorDate, 'code' => $request->retentionGuarantorCode, 'note_code' => $request->retentionGuarantorNoteCode, 'note_code_date' => $request->retentionGuarantorNoteCodeDate]);
            }
            if (sizeOf($request->guarantors)) {
                /*
                TODO
                crear eliminacion de garantes
                 */
                $loans = InfoLoan::where('affiliate_id', '=', $affiliate->id)->where('retirement_fund_id', '=', $retirement_fund->id)->get();
                foreach ($loans as $value) {
                    $value->delete();
                }
                foreach ($request->guarantors as $value) {
                    $loan = new InfoLoan();
                    $loan->affiliate_id = $affiliate->id;
                    $loan->retirement_fund_id = $retirement_fund->id;
                    $loan->code = 'some code';
                    $loan->date = Carbon::now();
                    $loan->affiliate_guarantor_id = $value['id'];
                    $loan->amount = Util::parseMoney($value['amount']);
                    $loan->save();
                }
            }
        } else {
            $retirement_fund->discount_types()->detach($discount_type->id);
            /*
                TODO
                crear eliminacion de garantes
             */
            $loans = InfoLoan::where('affiliate_id', '=', $affiliate->id)->where('retirement_fund_id', '=', $retirement_fund->id)->get();
            foreach ($loans as $value) {
                $value->delete();
            }
        }

        $discount_type = DiscountType::where('shortened', 'Retención según Resolución Judicial')->where('module_id', 3)->first();
        if ($retention_judicial >= 0 && $retention_judicial !== null) {
            if ($retirement_fund->discount_types->contains($discount_type->id)) {
                $retirement_fund->discount_types()->updateExistingPivot($discount_type->id, ['amount' => $retention_judicial, 'date' => $request->judicialRetentionDate, 'code' => $request->judicialRetentionDocument]);
            } else {
                $retirement_fund->discount_types()->save($discount_type, ['amount' => $retention_judicial, 'date' => $request->judicialRetentionDate, 'code' => $request->judicialRetentionDocument]);
            }
        }
        // fin mejorar

        $total_ret_fun = $sub_total_ret_fun - $advance_payment - $retention_loan_payment - $retention_guarantor - $retention_judicial;

        $retirement_fund->subtotal_ret_fun = $sub_total_ret_fun;
        $retirement_fund->total_ret_fun = $total_ret_fun;

        $retirement_fund->save();
        $beneficiaries = $retirement_fund->ret_fun_beneficiaries()->orderByDesc('type')->orderBy('id')->with('kinship')->get();
        //create function search spouse
        $spouse_id = ID::kinship()->conyuge;
        $spouse = $beneficiaries->filter(function ($item) use ($spouse_id) {
            return $item->kinship->id == $spouse_id;
        });
        if (sizeOf($spouse) > 0) {
            $has_spouse = true;
            $total_spouse = $total_ret_fun / 2;
            $total_spouse_percentage = 100 / 2;
            $total_derechohabientes_percentage = round($total_spouse_percentage / sizeOf($beneficiaries), 2);
            $total_spouse_percentage = round($total_spouse_percentage + $total_derechohabientes_percentage, 2);
            $total_spouse = $total_ret_fun / 2;
            $total_derechohabientes = round(($total_spouse / sizeOf($beneficiaries)), 2);
            $total_spouse = round(($total_spouse + $total_derechohabientes), 2);
        } else {
            $has_spouse = false;
            $total_derechohabientes = round($total_ret_fun / sizeOf($beneficiaries), 2);
            $total_derechohabientes_percentage = round(100 / sizeOf($beneficiaries), 2);
        }
        $one_spouse = 1;
        foreach ($beneficiaries as $beneficiary) {
            $beneficiary->full_name = $beneficiary->fullName();
            if ($beneficiary->kinship->id == $spouse_id) {
                if ($one_spouse <= 1) {
                    // recalculate
                    if ($request->reload) {
                        $beneficiary->temp_percentage = $total_spouse_percentage;
                        $beneficiary->temp_amount = $total_spouse;
                    } else {
                        $beneficiary->temp_percentage = $beneficiary->percentage ? $beneficiary->percentage : $total_spouse_percentage;
                        $beneficiary->temp_amount = $beneficiary->amount_ret_fun ? $beneficiary->amount_ret_fun : $total_spouse;
                    }
                } else {
                    return response('error', 500);
                }
                $one_spouse++;
            } else {
                //recalculate
                if ($request->reload) {
                    $beneficiary->temp_percentage = $total_derechohabientes_percentage;
                    $beneficiary->temp_amount = $total_derechohabientes;
                } else {
                    $beneficiary->temp_percentage = $beneficiary->percentage ? $beneficiary->percentage : $total_derechohabientes_percentage;
                    $beneficiary->temp_amount = $beneficiary->amount_ret_fun ? $beneficiary->amount_ret_fun : $total_derechohabientes;
                }
            }
        }
        $data = [
            'total_ret_fun' => $total_ret_fun,
            'sub_total_ret_fun' => $sub_total_ret_fun,
            'has_spouse' => $has_spouse,
            'beneficiaries' => $beneficiaries,
        ];
        return $data;
    }
    public function savePercentages(Request $request, $id)
    {
        $retirement_fund = RetirementFund::find($id);
        $affiliate = $retirement_fund->affiliate;
        foreach ($request->beneficiaries as $beneficiary) {
            $new_beneficiary = $retirement_fund->ret_fun_beneficiaries()->where('id', $beneficiary['id'])->first();
            if (!$new_beneficiary) {
                return response("error al buscar al beneficiario", 500);
            }
            $new_beneficiary->percentage = $beneficiary['temp_percentage'];
            $new_beneficiary->amount_ret_fun = $beneficiary['temp_amount'];
            if (!$affiliate->hasAvailability()) {
                $new_beneficiary->amount_total = $beneficiary['temp_amount'];
            }
            $new_beneficiary->save();
        }
        $availability = $affiliate->getContributionsWithType(12);
        $has_availability = sizeOf($availability) > 0;
        $total = (float)$retirement_fund->total_ret_fun;
        $beneficiaries = $retirement_fund->ret_fun_beneficiaries()->orderByDesc('type')->orderBy('id')->with('kinship')->get();

        $array_discounts = array();

        $array = DiscountType::where('module_id', 3)->get()->pluck('id');
        $results = array(array());
        foreach ($array as $element) {
            foreach ($results as $combination) {
                array_push($results, array_merge(array($element), $combination));
            }
        }
        foreach ($results as $value) {
            $sw = true;
            foreach ($value as $id) {
                //siempre tendra id
                // if (!$retirement_fund->discount_types()->find($id)) {
                if (!($retirement_fund->discount_types()->find($id)->pivot->amount > 0)) {
                    $sw = false;
                }
            }
            if ($sw) {
                $temp_total_discount = 0;
                foreach ($value as $id) {
                    $temp_total_discount = $temp_total_discount + $retirement_fund->discount_types()->find($id)->pivot->amount;
                }
                $name = join(' - ', DiscountType::whereIn('id', $value)->orderBy('id', 'asc')->get()->pluck('name')->toArray());
                array_push($array_discounts, array('name' => $name, 'amount' => $temp_total_discount));
            }
        }
        if ($has_availability) {
            $availability = ContributionType::find(12);
            $subtotal_availability = ($retirement_fund->subtotal_availability);
            // $total_annual_yield = ($subtotal_availability * Util::getRetFunCurrentProcedure()->annual_yield) / 100;
            $total_annual_yield = 0;
            $total_availability = round(($subtotal_availability + $total_annual_yield), 2);
            //$total = (float)$total;

            $spouse_id = ID::kinship()->conyuge;
            $spouse = $beneficiaries->filter(function ($item) use ($spouse_id) {
                return $item->kinship->id == $spouse_id;
            });
            if (sizeOf($spouse) > 0) {
                $total_spouse = $total_availability / 2;
                $total_derechohabientes = round(($total_spouse / sizeOf($beneficiaries)), 2);
                $total_spouse = round(($total_spouse + ($total_spouse / sizeOf($beneficiaries))), 2);
            } else {
                $total_derechohabientes = round($total_availability / sizeOf($beneficiaries), 2);
            }
            $one_spouse = 1;
            foreach ($beneficiaries as $beneficiary) {
                $beneficiary->full_name = $beneficiary->fullName();
                if ($beneficiary->kinship->id == $spouse_id) {
                    if ($one_spouse <= 1) {
                        if ($request->reload) {
                            $beneficiary->temp_amount_availability = $total_spouse;
                        } else {
                            $beneficiary->temp_amount_availability = $beneficiary->amount_availability ? $beneficiary->amount_availability : $total_spouse;
                        }
                    } else {
                        return response('error', 500);
                    }
                    $one_spouse++;
                } else {
                    if ($request->reload) {
                        $beneficiary->temp_amount_availability = $total_derechohabientes;
                    } else {
                        $beneficiary->temp_amount_availability = $beneficiary->amount_availability ? $beneficiary->amount_availability : $total_derechohabientes;
                    }
                }
            }

            /* added availability */
            $array_discounts_availability = [];
            foreach ($array_discounts as $value) {
                array_push($array_discounts_availability, array('name' => ('Fondo de Retiro' . ($value['name'] ? ' - ' . $value['name'] : '')), 'amount' => ($retirement_fund->subtotal_ret_fun - $value['amount'])));
            }
        } else {
            $array_discounts_availability = [];
            foreach ($array_discounts as $value) {
                array_push($array_discounts_availability, array('name' => ('Fondo de Retiro ' . ($value['name'] ? ' - ' . $value['name'] : '')), 'amount' => ($retirement_fund->subtotal_ret_fun - $value['amount'])));
            }
        }
        $data = [
            'has_availability' => $has_availability,
            'subtotal_availability' => $subtotal_availability ?? 0,
            'total_annual_yield' => $total_annual_yield ?? 0,
            'total_availability' => $total_availability ?? 0,
            'total' => $total ?? 0,
            'beneficiaries' => $beneficiaries,
            'array_discounts' => $array_discounts_availability,
        ];
        return $data;
    }
    //--**METODO PARA GUARDAR LOS PORCENTAJES DE DIPONIBILIDAD DE LOS BENEFICIARIOS**---
    public function savePercentagesAvailability(Request $request, $id)
    {
        $retirement_fund = RetirementFund::find($id);
        $affiliate = $retirement_fund->affiliate;

        /**added function calculate sub_total_availability */
        $subtotal_availability = ($retirement_fund->subtotal_availability);
        $total_annual_yield = ($subtotal_availability * Util::getRetFunCurrentProcedure()->annual_yield) / 100;
        $total_availability = $subtotal_availability;
        $retirement_fund->total_availability =  $total_availability;
        $retirement_fund->total = ($retirement_fund->total_ret_fun ?? 0);
        $retirement_fund->save();
        /**added function calculate sub_total_availability */
        foreach ($request->beneficiaries as $beneficiary) {
            $new_beneficiary = $retirement_fund->ret_fun_beneficiaries()->where('id', $beneficiary['id'])->first();
            if (!$new_beneficiary) {
                return response("error al buscar al beneficiario", 500);
            }
            $new_beneficiary->amount_availability = $beneficiary['temp_amount_availability'];
            $new_beneficiary->save();
        }
        $beneficiaries = $retirement_fund->ret_fun_beneficiaries()->orderByDesc('type')->orderBy('id')->with('kinship')->get();
        foreach ($beneficiaries as $beneficiary) {
            if ($request->reload) {
                $beneficiary->temp_amount_total = round(($beneficiary->amount_ret_fun), 2);
            } else {
                $beneficiary->temp_amount_total = $beneficiary->amount_total ? $beneficiary->amount_total : round(($beneficiary->amount_ret_fun), 2);
            }
            $beneficiary->full_name = $beneficiary->fullName();
        }
        $data = [
            'beneficiaries' => $beneficiaries,
        ];
        return $data;
    }
    public function saveTotalRetFunAvailability(Request $request, $id)
    {
        $retirement_fund = RetirementFund::find($id);
        $affiliate = $retirement_fund->affiliate;

        foreach ($request->beneficiaries as $beneficiary) {
            $new_beneficiary = $retirement_fund->ret_fun_beneficiaries()->where('id', $beneficiary['id'])->first();
            if (!$new_beneficiary) {
                return response("error al buscar al beneficiario", 500);
            }
            $new_beneficiary->amount_total = $beneficiary['temp_amount_total'];
            $new_beneficiary->save();
        }

        $data = [];
        return $data;
    }

    public function saveMessageContributionType(Request $request, $ret_fun_id)
    {
        $retirement_fund = RetirementFund::find($ret_fun_id);
        $contribution_type = ContributionType::find($request->contributionTypeId);

        if ($request->message) {
            if ($retirement_fund->contribution_types->contains($contribution_type)) {
                $retirement_fund->contribution_types()->updateExistingPivot($contribution_type->id, ['user_id' => Auth::user()->id, 'message' => $request->message]);
            } else {
                $retirement_fund->contribution_types()->save($contribution_type, ['user_id' => Auth::user()->id, 'message' => $request->message]);
            }
        } else {
            $retirement_fund->contribution_types()->detach($contribution_type);
        }
        return $retirement_fund;
    }
    public function saveCertificationNote(Request $request, $ret_fun_id)
    {
        $retirement_fund = RetirementFund::find($ret_fun_id);
        Session::put('size', $request->size);
        if ($request->note) {
            $wf_state = WorkflowState::where('role_id', Util::getRol()->id)->first();
            Util::getNextAreaCode($ret_fun_id);
            $ret_fun_correlative = RetFunCorrelative::where('retirement_fund_id', $ret_fun_id)->where('wf_state_id', $wf_state->id)->first();
            $ret_fun_correlative->note = $request->note;
            $ret_fun_correlative->save();
        }
        return $retirement_fund;
    }

    public function editRequirements(Request $request, $id)
    {
        DB::transaction(function () use ($request, $id) {
            $requirements = [];
            foreach ($request->requirements as $requirement) {
                $requirements = array_merge($requirements, $requirement);
            }
            $retirement_fund = RetirementFund::select('id', 'procedure_modality_id')->find($id);
            // Obtener documentos actuales indexados por procedure_requirement_id
            $existingDocs = $retirement_fund->submitted_documents->keyBy('procedure_requirement_id');
            $onlyDeleted = $retirement_fund->submitted_documents()->onlyTrashed()->get()->keyBy('procedure_requirement_id');
            // Nueva colección con todos los nuevos requisitos del request
            $newRequirements = collect($request->requirements)
                ->flatten(1)
                ->filter(function ($r) {
                    return $r['status'];
                });

            $additionalRequirements = collect($request->aditional_requirements);

            // Unimos ambos arreglos por procedure_requirement_id
            $incoming = $newRequirements->concat($additionalRequirements)
                ->mapWithKeys(function ($r) {
                    return [
                        $r['procedureRequirementId'] => [
                            'is_uploaded' => $r['isUploaded'],
                            'comment' => $r['comment'] ?? null,
                        ]
                    ];
                });

            // Determinar los IDs actuales y los nuevos
            $currentIds = $existingDocs->keys();
            $incomingIds = $incoming->keys();

            // 1. Eliminar los que ya no existen en el request
            $toDelete = $currentIds->diff($incomingIds);
            RetFunSubmittedDocument::where('retirement_fund_id', $retirement_fund->id)
                ->whereIn('procedure_requirement_id', $toDelete)
                ->delete();

            // 2. Crear o actualizar
            foreach ($incoming as $procedureRequirementId => $data) {
                if ($onlyDeleted->has($procedureRequirementId)) {
                    // Si el documento está eliminado, restaurarlo
                    $doc = $onlyDeleted->get($procedureRequirementId);
                    $doc->restore();
                } else {
                    $doc = $existingDocs->get($procedureRequirementId) ?? new RetFunSubmittedDocument();
                    $doc->retirement_fund_id = $retirement_fund->id;
                    $doc->procedure_requirement_id = $procedureRequirementId;
                    $doc->is_uploaded = $data['is_uploaded'];
                }
                $doc->comment = $data['comment'];
                $doc->save();
            }

            return ['deleted' => $toDelete];
        });
    }
    private function getLastCode($retirement_funds)
    {
        $num = 0;
        $year = 0;
        if (count($retirement_funds) == 0)
            return "";
        foreach ($retirement_funds as $retirement_fund) {
            $code = str_replace('A', '', $retirement_fund->code);
            if ($code != "") {
                $code = explode('/', $code);
                if ($code[1] > $year)
                    $year = $code[1];
                if ($code[0] > $num)
                    $num = $code[0];
            }
        }
        return $num . "/" . $year;
    }

    public function dictamenLegal($id)
    {
        $retirement_fund = RetirementFund::find($id);
        $actual_date = date('d-m-Y');
        $cite = "D.B.E/A.B.E./GMQ/N°";
        $applicant = RetFunBeneficiary::where('retirement_fund_id', $retirement_fund->id)->where('type', 'S')->get();
        $beneficiaries = RetFunBeneficiary::where('retirement_fund_id', $retirement_fund->id)->where('type', 'N')->orderBy('id')->get();
        //return $retirement_fund->affiliate_id;
        $affiliate = Affiliate::find($retirement_fund->affiliate_id);
        $correlatives = RetFunCorrelative::where('retirement_fund_id', $retirement_fund->id)->get();

        $data = [
            'actual_date'   =>  $actual_date,   //fecha actual (hoy)
            'cite'  =>  $cite,      //codigo identificador de usuario por area
            'beneficiaries'   => $beneficiaries,    //beneficiarios
            'applicant' =>  $applicant,     //persona que hace el trámite
            'affiliate' =>  $affiliate,     //policia afiliado
            'correlarives'  =>  $correlatives,  //codigos de documentos de cada area
        ];
        return $data;
    }
    public function getCorrelative($ret_fun_id, $wf_state_id)
    {
        $correlative = RetFunCorrelative::where('retirement_fund_id', $ret_fun_id)->where('wf_state_id', $wf_state_id)->first();

        if ($correlative) {
            return $correlative;
        }
        return null;
    }
    public function info($ret_fund_id)
    {
        if ($ret_fund_id) {
            $ret_fun = RetirementFund::find($ret_fund_id);
            return $ret_fun;
        }
        return null;
    }
    public function createJudicialRetention(Request $request, $retirement_fund_id) {
        static $DISCOUNT_TYPE_RETENTION = 11;
        $retirement_fund = RetirementFund::find($retirement_fund_id);
        $discount_type = DiscountType::where('id', $DISCOUNT_TYPE_RETENTION)->first();
        if(!$retirement_fund || !$discount_type)
            return response()->json([
                'error' => "No existe el trámite o el tipo de descuento"
            ], 409);
        $discount_type_retirement_fund = $retirement_fund->discount_types()
            ->wherePivot('discount_type_id', $discount_type->id)
            ->wherePivot('retirement_fund_id', $retirement_fund_id)
            ->wherePivot('deleted_at', null)
            ->count();
        if($discount_type_retirement_fund > 0)
            return response()->json([
                'error' => "ya existe la retención"
            ], 409);
        $retirement_fund->discount_types()->save($discount_type, ['amount' => 0, 'date' => null, 'code' => null, 'note_code' => $request->detail, 'note_code_date' => Carbon::now(), ]);
        $discount = $retirement_fund->discount_types()->whereIn('discount_types.id', [$discount_type->id])->get();
        return response()->json([
            'message' => 'Registro exitoso',
            'data' => $discount
        ]);
    }
    public function obtainJudicialRetention($retirement_fund_id) {
        static $DISCOUNT_TYPE_RETENTION = 11;
        $retirement_fund = RetirementFund::find($retirement_fund_id);
        $discount_type = DiscountType::where('id', $DISCOUNT_TYPE_RETENTION)->first();
        if($retirement_fund && $discount_type) {
            $discounts = $retirement_fund->discount_types()
                ->wherePivot('discount_type_id', $discount_type->id)
                ->wherePivot('retirement_fund_id', $retirement_fund_id)
                ->wherePivot('deleted_at', null)
                ->get()
                ->pluck('pivot');

            if(count($discounts) > 0) {
                return response()->json([
                    'message' => 'Obtención exitosa',
                    'data' => $discounts
                ]);
            }
        }
        return response()->json([
            'error' => 'No existe la retención',
            'data' => []
        ], 200);
    }
    public function modifyJudicialRetention(Request $request, $retirement_fund_id) {
        static $DISCOUNT_TYPE_RETENTION = 11;
        $retirement_fund = RetirementFund::find($retirement_fund_id);
        $discount_type = DiscountType::where('id', $DISCOUNT_TYPE_RETENTION)->first();
        if($retirement_fund && $discount_type) {
            $updated = $retirement_fund->discount_types()->updateExistingPivot($DISCOUNT_TYPE_RETENTION, [ 'note_code' => $request->detail ]);
            return response()->json([
                'message' => 'Modificación de la retención exitosa',
                'data' => $updated
            ]);
        }
        return response()->json([
            'error' => 'No se pudo modificar la retención',
        ], 409);
    }
    public function cancelJudicialRetention($retirement_fund) {
        static $DISCOUNT_TYPE_RETENTION = 11;
        $retirement_fund = RetirementFund::find($retirement_fund);
        $discount_type = DiscountType::where('id', $DISCOUNT_TYPE_RETENTION)->first();
        if($retirement_fund && $discount_type) {
            $deleted = $retirement_fund->discount_types()->updateExistingPivot($DISCOUNT_TYPE_RETENTION, ['deleted_at' => now()]);
            return response()->json([
                'message' => 'Se ha eliminado la retención exitosamente',
                'data' => $deleted
            ]);
        }
        return response()->json([
            'error' => 'No se pudo eliminar la retención'
        ], 409);
    }
}
