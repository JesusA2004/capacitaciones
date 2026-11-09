<?php

use App\Models\Puesto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Regla de la dueña del negocio (2026-11-09): el Subgerente de Sucursal y el
 * Subgerente Regional son APOYO (config organigrama.puestos_apoyo); nunca son
 * jefe directo mientras su superior tenga titular o cobertura. Gestores y
 * volantes pasan a reportar al Gerente de Sucursal. No cambia ningún «reporta
 * a» ni expediente: solo recalcula `colaboradores.jefe_id` (cada cambio queda
 * en la bitácora, motivo «Derivado del organigrama»). Idempotente; una base sin
 * puestos la llenan los seeders.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Puesto::query()->where('nombre', 'Dirección Comercial')->doesntExist()) {
            return;
        }

        Artisan::call('organigrama:sincronizar-jefes');
    }

    public function down(): void
    {
        // Sin reversa: el jefe se deriva del organigrama con la regla vigente.
    }
};
