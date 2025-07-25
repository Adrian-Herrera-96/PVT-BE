<?php

namespace Muserpol\Models\RetirementFund;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RetFunSubmittedDocument extends Model
{
    use SoftDeletes;
    protected $table = "ret_fun_submitted_documents";
    public function retirement_fund()
    {
        return $this->belongsTo('Muserpol\Models\RetirementFund\RetirementFund');
    }

    public function procedure_requirement()
    {
        return $this->belongsTo('Muserpol\Models\ProcedureRequirement')->withTrashed();
    }

}
