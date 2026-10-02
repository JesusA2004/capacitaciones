<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Headcount\HeadcountImportService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Plantilla autorizada real (Excel de headcount en claude/headcount/, ver
 * docs/HEADCOUNT_Y_VACANTES.md): sin esto todas las sucursales arrancan con
 * plantilla 0. Corre también en producción: solo crea los pares
 * (sucursal, puesto) que aún no existen — nunca pisa lo que RH ya capturó.
 * Para forzar el Excel completo está `php artisan headcount:importar`.
 */
class PlantillaAutorizadaSeeder extends Seeder
{
    public const RUTA_EXCEL = 'claude/headcount/HEADCOUNT GENERAL MR LANA 28-08-2026..xlsx';

    public function run(): void
    {
        $ruta = base_path(self::RUTA_EXCEL);

        if (! is_file($ruta)) {
            // Sin Excel no se inventa plantilla: se avisa y RH la captura en
            // Sucursales → detalle.
            Log::warning('PlantillaAutorizadaSeeder: no existe el Excel de headcount; la plantilla autorizada queda sin cargar.', ['ruta' => $ruta]);

            return;
        }

        $usuario = User::query()->role('super_admin')->first();
        $resultado = app(HeadcountImportService::class)->importar($ruta, $usuario, soloFaltantes: true);

        foreach ([...$resultado['sucursales_sin_match'], ...$resultado['puestos_sin_match'], ...$resultado['conflictos']] as $pendiente) {
            // Auditable: nunca se ignora en silencio (ver CLAUDE.md).
            Log::warning('PlantillaAutorizadaSeeder: pendiente de revisar en el Excel de headcount.', ['detalle' => $pendiente]);
        }

        if ($resultado['creados'] > 0) {
            app(VacanteAutoGenerationService::class)->sincronizarTodo();
        }
    }
}
