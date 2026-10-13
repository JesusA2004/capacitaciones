<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| El Monitorista (de cámaras) reporta directo a Dirección Comercial, como
| la lámina 18 del PDF de Inducción (antes colgaba de Responsable de
| Sistemas). Solo mueve ese puesto; el jefe directo se deriva solo del
| organigrama.
*/
return new class extends Migration
{
    public function up(): void
    {
        $direccionComercial = DB::table('puestos')->where('nombre', 'Dirección Comercial')->value('id');

        if ($direccionComercial !== null) {
            DB::table('puestos')->where('nombre', 'Monitorista')->update(['puesto_superior_id' => $direccionComercial]);
        }
    }

    public function down(): void
    {
        $sistemas = DB::table('puestos')->where('nombre', 'Responsable de Sistemas')->value('id');

        if ($sistemas !== null) {
            DB::table('puestos')->where('nombre', 'Monitorista')->update(['puesto_superior_id' => $sistemas]);
        }
    }
};
