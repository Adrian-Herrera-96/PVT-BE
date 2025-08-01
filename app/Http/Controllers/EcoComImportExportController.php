<?php

namespace Muserpol\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Muserpol\Imports\EcoComImportSenasir;
use Muserpol\Models\EconomicComplement\EconomicComplement;
use Muserpol\Models\EconomicComplement\EcoComUpdatedPension;
use Muserpol\Models\EconomicComplement\EcoComFixedPension;
use Muserpol\Models\EconomicComplement\EcoComRegulation;
use Muserpol\Imports\EcoComImportAPS;
use Muserpol\Helpers\Util;
use Muserpol\Imports\EcoComImportPagoFuturo;
use Muserpol\Imports\EcoComUpdatePaidBank;
use Muserpol\Models\Affiliate;
use DB;
use Muserpol\Models\ObservationType;
use Muserpol\Models\DiscountType;
use Muserpol\User;
use Muserpol\Helpers\ID;
use Auth;
use Muserpol\Models\EconomicComplement\EcoComProcedure;
use Carbon\Carbon;

class EcoComImportExportController extends Controller
{
    public function importSenasir(Request $request)
    {
        if ($request->refresh != 'true') {
            $uploadedFile = $request->file('image');
            $filename = 'senasir.' . $uploadedFile->getClientOriginalExtension();
            Storage::disk('local')->putFileAs(
                'senasir/' . now()->year,
                $uploadedFile,
                $filename
            );
        }
        Excel::import(new EcoComImportSenasir, 'senasir/' . now()->year . '/senasir.xlsx');
        $eco_com_procedure_id = Util::getEcoComCurrentProcedure()->first();
        $no_import = EconomicComplement::with('eco_com_beneficiary')->select('economic_complements.*')
            ->leftJoin('affiliates', 'economic_complements.affiliate_id', '=', 'affiliates.id')
            ->where('eco_com_procedure_id', $eco_com_procedure_id)
            ->where('rent_type', '<>', 'Automatico')
            ->where('rent_type', '<>', 'Manual')
            ->where('affiliates.pension_entity_id', 5)
            ->get();
        return array_merge(session()->get('senasir_data'), ['not_found' => $no_import]);

        // return session()->get('senasir_data');
    }
    public function importAPS(Request $request)
    {
        $success = 0;
        $not_found = collect([]);
        $not_found_db = collect([]);
        $not_has_eco_com = collect([]);
        $sw_refresh = false;
        $eco_com_procedure_id = Util::getEcoComCurrentProcedure()->first();
        // $sw_override = false;
        if ($request->refresh == 'true') {
            $sw_refresh = true;
        }
        // if ($request->override == 'true') {
        //     $sw_override = true;
        // }
        switch ($request->type) {
            case 'vejez':
                $data = $this->uploadAndGetData($sw_refresh, $request->file('vejez'), 'vejez');
                $collect = collect([]);
                $process = collect([]);
                foreach ($data as $d1) {
                    $temp = $d1;
                    // [34] PTC_DERECHOHABIENTE
                    if ((is_null($d1[34]) || $d1[34] == 'C') && !$process->contains($d1[0])) {
                        foreach ($data as $d2) {
                            // if ($d1[3] == $d2[3] && $d1[10] == $d2[10] && ($d2[34] == 'C' || is_null($d2[34])) && $d1[0] != $d2[0]) {
                            if ($d1[3] == $d2[3] && ($d2[34] == 'C' || is_null($d2[34])) && $d1[0] != $d2[0]) {
                                $temp[13] =  Util::verifyAndParseNumber($temp[13]) + Util::verifyAndParseNumber($d2[13]); //TOTAL_CC
                                $temp[19] =  Util::verifyAndParseNumber($temp[19]) + Util::verifyAndParseNumber($d2[19]); //TOTAL_FSA
                                $temp[25] =  Util::verifyAndParseNumber($temp[25]) + Util::verifyAndParseNumber($d2[25]); //TOTAL_FS
                                $process->push($d2[0]);
                            }
                        }
                        $temp[13] = Util::verifyAndParseNumber($temp[13]);
                        $temp[19] = Util::verifyAndParseNumber($temp[19]);
                        $temp[25] = Util::verifyAndParseNumber($temp[25]);
                        $collect->push($temp);
                    }
                }
                $eco_coms = $this->getEcoComsWithProcedure($eco_com_procedure_id);
                foreach ($eco_coms as $e) {
                    foreach ($collect as $c) {
                        if ($c[3] == $e->affiliate->nua) {
                            // Por solicitud de CE los casos de inclusión no se toman en cuenta en la importación
                            if ($e->eco_com_reception_type_id != ID::ecoCom()->inclusion) {
                                $updatedPension = null;
                                if (is_null($e->eco_com_updated_pension)) {
                                    $updatedPension = new EcoComUpdatedPension();
                                    $updatedPension->user_id = Auth::user()->id;
                                    $updatedPension->economic_complement_id = $e->id;
                                } else {
                                    $updatedPension = EcoComUpdatedPension::find($e->eco_com_updated_pension->id);
                                }
                                if ($updatedPension->rent_type == null || $updatedPension->rent_type != 'Manual') {
                                    $updatedPension->rent_type = 'Automatico';
                                    $updatedPension->aps_total_cc = round($c[13], 2) ?? 0;
                                    $updatedPension->aps_total_fsa = round($c[19], 2) ?? 0;
                                    $updatedPension->aps_total_fs = round($c[25], 2) ?? 0;
                                    $updatedPension->save();
                                    $updatedPension->calculateTotalRentAps();
                                    $success++;
                                }
                            }
                        }
                    }
                }
                foreach ($collect as $c) {
                    $ci_aps = explode("-", ltrim($c[10], "0"))[0];
                    $affiliate = Affiliate::whereRaw("split_part(ltrim(trim(affiliates.identity_card),'0'), '-', 1) ='" . ltrim(trim($ci_aps), '0') . "'")
                        ->where('nua', $c[3])->first();
                    if ($affiliate) {
                        if (!$affiliate->hasEconomicComplementWithProcedure($eco_com_procedure_id)) {
                            $not_has_eco_com->push($affiliate);
                        }
                    } else {
                        $not_found_db->push($c);
                    }
                }
                $not_found = $this->getEcoComWithoutPensionWithProcedure($eco_com_procedure_id);
                break;
            case 'invalidez':                
                $data = $this->uploadAndGetData($sw_refresh, $request->file('invalidez'), 'invalidez');
                $collect = collect([]);
                $process = collect([]);
                foreach ($data as $d1) {
                    $temp = $d1;
                    if (!$process->contains($d1[0])) {
                        foreach ($data as $d2) {
                            if ($d1[3] == $d2[3] && $d1[0] != $d2[0]) {
                                $temp[16] =  Util::verifyAndParseNumber($temp[16]) + Util::verifyAndParseNumber($d2[16]);
                                $process->push($d2[0]);
                            }
                        }
                        $temp[16] = Util::verifyAndParseNumber($temp[16]);
                        $collect->push($temp);
                    }
                }
                $eco_coms = $this->getEcoComsWithProcedure($eco_com_procedure_id);
                foreach ($eco_coms as $e) {
                    foreach ($collect as $c) {
                        if ($c[3] == $e->affiliate->nua) {
                            // Por solicitud de CE los casos de inclusión no se toman en cuenta en la importación
                            if ($e->eco_com_reception_type_id != ID::ecoCom()->inclusion) {
                                if (is_null($e->eco_com_updated_pension)) {
                                    $updatedPension = new EcoComUpdatedPension();
                                    $updatedPension->user_id = Auth::user()->id;
                                    $updatedPension->economic_complement_id = $e->id;
                                } else {
                                    $updatedPension = EcoComUpdatedPension::find($e->eco_com_updated_pension->id);
                                }
                                if ($updatedPension->rent_type == null || $updatedPension->rent_type != 'Manual') {
                                    $updatedPension->rent_type = 'Automatico';
                                    $updatedPension->aps_disability = round($c[16], 2) ?? 0;
                                    $updatedPension->save();
                                    $updatedPension->calculateTotalRentAps();
                                    $success++;
                                }
                            }
                        }
                    }
                }
                $temp = 0;
                foreach ($collect as $c) {
                    if ($temp > 0) {
                        $ci_aps = explode("-", ltrim($c[10], "0"))[0];
                        $affiliate = Affiliate::whereRaw("split_part(ltrim(trim(affiliates.identity_card),'0'), '-', 1) ='" . ltrim(trim($ci_aps), '0') . "'")
                            ->where('nua', $c[3])->first();
                        if ($affiliate) {
                            if (!$affiliate->hasEconomicComplementWithProcedure($eco_com_procedure_id)) {
                                $not_has_eco_com->push($affiliate);
                            }
                        } else {
                            $not_found_db->push($c);
                        }
                    }
                    $temp++;
                }
                $not_found = $this->getEcoComWithoutPensionWithProcedure($eco_com_procedure_id);
                break;

            case 'muerte':
                $data = $this->uploadAndGetData($sw_refresh, $request->file('muerte'), 'muerte');
                $collect = collect([]);
                $process = collect([]);
                foreach ($data as $d1) {
                    $temp = $d1;
                    if ((is_null($d1[27]) || $d1[27] == 'C') && !$process->contains($d1[0])) {
                        foreach ($data as $d2) {
                            // if ($d1[3] == $d2[3] && $d1[11] == $d2[11] && ($d2[27] == 'C' || is_null($d2[27])) && $d1[0] != $d2[0]) {
                            if ($d1[3] == $d2[3] && ($d2[27] == 'C' || is_null($d2[27])) && $d1[0] != $d2[0]) {
                                $temp[16] =  Util::verifyAndParseNumber($temp[16]) + Util::verifyAndParseNumber($d2[16]);
                                $process->push($d2[0]);
                            }
                        }
                        $temp[16] = Util::verifyAndParseNumber($temp[16]);
                        $collect->push($temp);
                    }
                }
                $eco_coms = $this->getEcoComsWithProcedure($eco_com_procedure_id);
                foreach ($eco_coms as $e) {
                    foreach ($collect as $c) {
                        if ($c[3] == $e->affiliate->nua) {
                            // Por solicitud de CE los casos de inclusión no se toman en cuenta en la importación
                            if ($e->eco_com_reception_type_id != ID::ecoCom()->inclusion) {
                                if (is_null($e->eco_com_updated_pension)) {
                                    $updatedPension = new EcoComUpdatedPension();
                                    $updatedPension->user_id = Auth::user()->id;
                                    $updatedPension->economic_complement_id = $e->id;
                                } else {
                                    $updatedPension = EcoComUpdatedPension::find($e->eco_com_updated_pension->id);
                                }
                                if ($updatedPension->rent_type == null || $updatedPension->rent_type != 'Manual') {
                                    $updatedPension->rent_type = 'Automatico';
                                    $updatedPension->aps_total_death = round($c[17], 2) ?? 0;
                                    $updatedPension->save();
                                    $updatedPension->calculateTotalRentAps();
                                    $success++;
                                }
                            }
                        }
                    }
                }
                $temp = 0;
                foreach ($collect as $c) {
                    if ($temp > 0) {
                        $ci_aps = ltrim($c[11], "0");
                        $affiliate = Affiliate::whereRaw("split_part(ltrim(trim(affiliates.identity_card),'0'), '-', 1) ='" . ltrim(trim($ci_aps), '0') . "'")
                            ->where('nua', $c[3])->first();
                        if ($affiliate) {
                            if (!$affiliate->hasEconomicComplementWithProcedure($eco_com_procedure_id)) {
                                $not_has_eco_com->push($affiliate);
                            }
                        } else {
                            $not_found_db->push($c);
                        }
                    }
                    $temp++;
                }
                $not_found = $this->getEcoComWithoutPensionWithProcedure($eco_com_procedure_id);
                break;
            default:
                # code...
                break;
        }
        $data = [
            'success' => $success,
            'csvTotal' => $collect->count() - 1,
            'notHasEcoCom' => $not_has_eco_com,
            'notFoundDB' => $not_found_db,
            'notFound' => $not_found,
        ];
        return $data;
    }
    private function getEcoComsWithProcedure(int $procedure)
    {
        $eco_coms = EconomicComplement::with('affiliate')->with('eco_com_updated_pension')
            ->select('economic_complements.*')
            ->leftJoin('affiliates', 'economic_complements.affiliate_id', '=', 'affiliates.id')
            ->leftJoin('eco_com_updated_pensions', 'economic_complements.id', '=', 'eco_com_updated_pensions.economic_complement_id')
            ->where('affiliates.pension_entity_id', '<>', 5)
            ->where('eco_com_procedure_id', $procedure)
            ->NotHasEcoComState(1, 6)
            ->get();
        return $eco_coms;
    }
    private function getEcoComWithoutPensionWithProcedure(int $procedure)
    {
        $not_found = EconomicComplement::with('eco_com_beneficiary')->with('eco_com_updated_pension')
            ->select('economic_complements.*')
            ->leftJoin('affiliates', 'economic_complements.affiliate_id', '=', 'affiliates.id')
            ->leftJoin('eco_com_updated_pensions', 'economic_complements.id', '=', 'eco_com_updated_pensions.economic_complement_id')
            ->where('eco_com_procedure_id', $procedure)
            ->where('affiliates.pension_entity_id', '<>', 5)
            ->where('economic_complements.rent_type', '<>', 'Automatico')
            ->where('economic_complements.rent_type', '<>', 'Manual')
            ->where(function ($query) {
                $query->whereNull('eco_com_updated_pensions.total_rent')
                    ->orWhere('eco_com_updated_pensions.total_rent', '=', 0);
            })
            ->get();
        return $not_found;
    }
    private function uploadAndGetData(string $refresh, $file, string $name) {
        if (!$refresh) {
            $filename = 'aps-'.$name.'.' . $file->getClientOriginalExtension();
            Storage::disk('local')->putFileAs(
                'aps/' . now()->year,
                $file,
                $filename
            );
        };
        return $data = Excel::toCollection(new EcoComImportAPS, 'aps/' . now()->year . '/aps-'.$name.'.csv')[0];
    }
    public function importPagoFuturo(Request $request)
    { DB::beginTransaction();
        $contribution_created = 0;
        $contribution_updated = 0;
        $tramit_number = 0;
        $total_contribution = 0;
        $not_updated = collect([]);
        $data = [
            'tramit_number' => $tramit_number,
            'contribution_created'=>$contribution_created,
            'contribution_updated'=>$contribution_updated,
            'total_contribution'=>$total_contribution,
            'not_updated'=> $not_updated
        ];
        $current_procedures = $request->ecoComProcedureId;
        $pago_futuro_id = 31;
        $contribution_discontinued_id = 41;
        $procedures_without_observation = [];
        try{
          $affiliate_has_not_contributions = DB::table('observables')->select('observables.observable_id')->join('affiliates','observables.observable_id','affiliates.id')->join('economic_complements','affiliates.id','economic_complements.affiliate_id')->where('observable_type', 'affiliates')->where('observation_type_id', $contribution_discontinued_id)->whereNull('observables.deleted_at')->whereNull('economic_complements.deleted_at')->where('economic_complements.eco_com_procedure_id','=',$current_procedures)->distinct()->get();
          $observation_disc_con = ObservationType::find($contribution_discontinued_id);
          $eco_com_all = EconomicComplement::select('economic_complements.id',
          'economic_complements.affiliate_id',
          'economic_complements.code',
          'economic_complements.eco_com_procedure_id',
          'economic_complements.wf_current_state_id',
          'economic_complements.eco_com_state_id',
          'economic_complements.eco_com_modality_id',
          'economic_complements.deleted_at')
          ->with('eco_com_updated_pension')
          ->leftJoin('eco_com_updated_pensions', 'economic_complements.id', '=', 'eco_com_updated_pensions.economic_complement_id')
          ->where('economic_complements.eco_com_procedure_id', $current_procedures)
          ->where('economic_complements.wf_current_state_id',3) // 3 - Area Tecnica Complemento Economico
          ->where('economic_complements.eco_com_state_id',16) // 16 - En proceso de revisión
          ->whereNotIn('economic_complements.eco_com_modality_id',[3,10,12,11]) // 4 rentas de orfandad 
          ->whereNull('economic_complements.deleted_at')->get();
          $hash_eco_com_all = [];
            foreach ($eco_com_all as $result) {
                $hash_eco_com_all[$result->affiliate_id] = $result;
            }
            foreach($affiliate_has_not_contributions as $affiliate_discontinued){
                $eco_com_disc_con = $eco_com_all->where('affiliate_id', $affiliate_discontinued->observable_id)->first();
                if($eco_com_disc_con){
                    if (!$eco_com_disc_con->hasObservationType($contribution_discontinued_id)) {
                        array_push($procedures_without_observation, ['affiliate_id'=>$eco_com_disc_con->affiliate_id, 'code'=>$eco_com_disc_con->code]);
                    }
                }
            }
          if(count($procedures_without_observation) > 0){
            return response()->json([
                'status' => 'error',
                'errors' => ['Los siguientes trámites no tienen la observación '.$observation_disc_con->name],
                'data' => [
                    'procedures_without_observation' => $procedures_without_observation,
                ]
            ], 422);
          }
        $affiliates = DB::table('observables')->select('observables.observable_id', 'affiliates.pension_entity_id')->join('affiliates','observables.observable_id','affiliates.id')->join('economic_complements','affiliates.id','economic_complements.affiliate_id')->where('observable_type', 'affiliates')->where('observation_type_id', $pago_futuro_id)->whereNull('observables.deleted_at')->whereNull('economic_complements.deleted_at')->where('economic_complements.eco_com_procedure_id','=',$current_procedures)->distinct()->get();
        $observation = ObservationType::find($pago_futuro_id);
        foreach ($affiliates as $affiliate) {
            $affiliate_id = $affiliate->observable_id;
            // $eco_com = $eco_com_all->where('affiliate_id', $affiliate_id)->first();
            $eco_com = null;
            if (isset($hash_eco_com_all[$affiliate_id])) {
                $eco_com = $hash_eco_com_all[$affiliate_id];
            }
            //$pension_entity_id = Affiliate::find($affiliate_id)->pension_entity_id;
            $pension_entity_id = $affiliate->pension_entity_id;
            if ($eco_com) {
                if (!($pension_entity_id == 5) && !($pension_entity_id == null)){
                         if (!$eco_com->hasObservationType($pago_futuro_id)) {
                             $eco_com->observations()->save($observation, [
                                 'user_id' => Auth::user()->id,
                                 'date' => now(),
                                 'message' => "Observación generada desde el afiliado.",
                                 'enabled' => true
                             ]);
                          }
                        if ($eco_com->eco_com_updated_pension != null) {
                            if ($eco_com->eco_com_updated_pension->total_rent > 0) {
                                $eco_com->eco_com_updated_pension->calculateTotalRentAps();
                                $total_rent = $eco_com->eco_com_updated_pension->total_rent;
                                if ($total_rent > 0) {
                                    $total = round($total_rent * 2.03 / 100, 2);
                                    $aux = $total * 6;
                                    $discount_type = DiscountType::findOrFail(7);
                                    //registro o actualizacion del descuento
                                    if ($eco_com->discount_types->contains($discount_type->id)) {
                                        $eco_com->discount_types()->updateExistingPivot($discount_type->id, ['amount' => $aux, 'date' => now()]);
                                    } else {
                                        $eco_com->discount_types()->save($discount_type, ['amount' => $aux, 'date' => now()]);
                                    }
                                    //registro de aportes en la tabla contribution_passives
                                    $user_id = Auth::user()->id;
                                    $import_contribution = DB::select("select import_contribution_eco_com($user_id,$current_procedures,$eco_com->id)");
                                    DB::commit();
                                    if (!is_null($import_contribution[0]->import_contribution_eco_com)) {
                                        $import_contribution = explode(',', $import_contribution[0]->import_contribution_eco_com);
                                        $tramit_number = $tramit_number + $import_contribution[0];
                                        $contribution_created = $contribution_created + $import_contribution[1];
                                        $contribution_updated = $contribution_updated + $import_contribution[2];
                                        $total_contribution = $contribution_created + $contribution_updated;
                                        $data = [
                                            'tramit_number' => $tramit_number,
                                            'contribution_created' => $contribution_created,
                                            'contribution_updated' => $contribution_updated,
                                            'total_contribution' => $total_contribution
                                        ];
                                        if (filter_var($import_contribution[3], FILTER_VALIDATE_BOOLEAN)) {
                                            $month = Carbon::parse($import_contribution[4]);
                                            $month = $month->formatLocalized('%B');
                                            return response()->json([
                                                'status' => 'error',
                                                'errors' => ['El afiliado con Nup:' . $affiliate_id . ' tiene registro de aportes en el mes de ' . $month . ' con origen Senasir. DEBE SUBSANAR EL ERROR Y VOLVER A EJECUTAR LA FUNCIÓN DE PAGO A FUTURO'],
                                                'data' => $data
                                            ], 422);
                                        }
                                    }
                                }
                            } else {
                                $not_updated->push($eco_com->code);
                            }
                        } else {
                            $not_updated->push($eco_com->code);
                        }
                }else{
                    return response()->json([
                        'status' => 'error',
                        'errors' => ['ERROR: El afiliado con Nup:'.$affiliate_id.' tiene registrado como Ente Gestor Senasir ò no se tiene un registro. DEBE SUBSANAR EL ERROR Y VOLVER A EJECUTAR LA FUNCIÓN DE PAGO A FUTURO'],
                        'data'=> $data

                    ], 422);
                }
            }
        }
        $data = [
            'tramit_number' => $tramit_number,
            'contribution_created'=>$contribution_created,
            'contribution_updated'=>$contribution_updated,
            'total_contribution'=>$total_contribution,
            'not_updated'=> $not_updated
        ];
        session()->put('pago_futuro_data', $data);
        return session()->get('pago_futuro_data');
       }catch (\Exception $e) {
            DB::rollback();
            return $e;
       }
    }
    public function updatePaidBank(Request $request)
    {
        if ($request->refresh != 'true') {
            $uploadedFile = $request->file('image');
            $filename = 'pago_banco.' . $uploadedFile->getClientOriginalExtension();
            Storage::disk('local')->putFileAs(
                'pago_banco/' . now()->year,
                $uploadedFile,
                $filename
            );
        }
        Excel::import(new EcoComUpdatePaidBank, 'pago_banco/' . now()->year . '/pago_banco.csv');
        return session()->get('pago_banco_data');
    }
}
