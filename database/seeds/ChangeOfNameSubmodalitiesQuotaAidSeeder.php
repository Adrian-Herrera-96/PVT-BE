<?php

use Illuminate\Database\Seeder;
use Muserpol\Models\ProcedureModality;

class ChangeOfNameSubmodalitiesQuotaAidSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $sub_modalities = ProcedureModality::whereProcedureTypeId(4)->orderBy('id')->get(); // Obtener las submodalidades de Auxilio mortuorio
        $changes = ['Fallecimiento del (la) Titular', 'Fallecimiento del (la) Cónyuge', 'Fallecimiento del (la) Viudo (a)'];

        foreach($sub_modalities as $index => $modality) {
            if(isset($changes[$index])) {
                $modality->name = $changes[$index];
                $modality->save();
            }
        }
    }
}
