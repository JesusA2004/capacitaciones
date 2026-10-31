<?php

use App\Models\Puesto;
use App\Services\Headcount\PlazasEmpresaService;
use App\Services\Organigrama\SincronizadorOrganigramaService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Al desplegar deja lista la cadena Gerente Regional → Subgerente Regional
 * (reutiliza «Sub Gerente Regional»), las plazas y vacantes de los puestos de
 * empresa sin titular ni cobertura, y los jefes recalculados. Una base nueva
 * (sin puestos) no hace nada: la llenan los seeders. Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Puesto::query()->where('nombre', 'Dirección Comercial')->doesntExist()) {
            return;
        }

        app(SincronizadorOrganigramaService::class)->sincronizar();
        app(PlazasEmpresaService::class)->asegurar();
        app(VacanteAutoGenerationService::class)->sincronizarTodo();
        Artisan::call('organigrama:sincronizar-jefes');
    }

    public function down(): void
    {
        // Sin reversa: las plazas y vacantes creadas las administra RH.
    }
};
