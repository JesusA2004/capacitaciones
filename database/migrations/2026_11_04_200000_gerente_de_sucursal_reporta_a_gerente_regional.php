<?php

use App\Models\Puesto;
use App\Services\Organigrama\SincronizadorOrganigramaService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Corrige lo que dejó 2026_11_03_210000 (ya pudo correr en producción): el
 * Gerente de Sucursal reporta al Gerente Regional y el Subgerente Regional le
 * reporta a él como apoyo. Solo cambia «reporta a» y recalcula jefes; no toca
 * expedientes ni asigna personas. Lo que no cuadra (más de un titular, titular sin
 * rol) queda en el log. Idempotente; una base sin puestos la llenan los seeders.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Puesto::query()->where('nombre', 'Dirección Comercial')->doesntExist()) {
            return;
        }

        $reporte = app(SincronizadorOrganigramaService::class)->sincronizar();

        foreach ($reporte['conflictos'] as $conflicto) {
            Log::warning('Organigrama: revisar al desplegar la jerarquía regional.', ['conflicto' => $conflicto]);
        }

        Artisan::call('organigrama:sincronizar-jefes');
    }

    public function down(): void
    {
        // Sin reversa: la jerarquía anterior contradice la regla confirmada.
    }
};
