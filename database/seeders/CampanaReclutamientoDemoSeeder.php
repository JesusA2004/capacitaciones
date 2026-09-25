<?php

namespace Database\Seeders;

use App\Enums\CanalReclutamiento;
use App\Models\CampanaReclutamiento;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Gasto demo de campañas de reclutamiento del mes actual (docs de referencia:
 * App\Services\Reclutamiento\CampanaReclutamientoService). Idempotente por
 * la marca característica "(demo-camp)" en observaciones.
 */
class CampanaReclutamientoDemoSeeder extends Seeder
{
    private const MARCA = '(demo-camp)';

    public function run(): void
    {
        if (CampanaReclutamiento::where('observaciones', 'like', '%'.self::MARCA)->exists()) {
            return;
        }

        $mes = (int) now()->month;
        $anio = (int) now()->year;

        $sucursal = Sucursal::where('clave', 'IXT01')->first();
        $gestorFijo = Puesto::where('nombre', 'Gestor')->first();
        $rhAdmin = User::where('email', 'rh.admin@mrlana.test')->first();

        CampanaReclutamiento::create([
            'mes' => $mes,
            'anio' => $anio,
            'canal' => CanalReclutamiento::Meta->value,
            'empresa_id' => $sucursal?->empresa_id,
            'sucursal_id' => $sucursal?->id,
            'departamento_id' => $gestorFijo?->departamento_id,
            'puesto_id' => $gestorFijo?->id,
            'monto' => 10000,
            'candidatos_generados' => null,
            'observaciones' => 'Campaña de Meta Ads dirigida a Gestor '.self::MARCA,
            'created_by' => $rhAdmin?->id,
        ]);

        CampanaReclutamiento::create([
            'mes' => $mes,
            'anio' => $anio,
            'canal' => CanalReclutamiento::Indeed->value,
            'empresa_id' => null,
            'sucursal_id' => null,
            'departamento_id' => null,
            'puesto_id' => null,
            'monto' => 6000,
            'candidatos_generados' => null,
            'observaciones' => 'Campaña general de Indeed, sin puesto específico '.self::MARCA,
            'created_by' => $rhAdmin?->id,
        ]);

        CampanaReclutamiento::create([
            'mes' => $mes,
            'anio' => $anio,
            'canal' => CanalReclutamiento::Computrabajo->value,
            'empresa_id' => $sucursal?->empresa_id,
            'sucursal_id' => $sucursal?->id,
            'departamento_id' => null,
            'puesto_id' => null,
            'monto' => 4000,
            'candidatos_generados' => 5,
            'observaciones' => 'Campaña de Computrabajo, gasto general de la sucursal '.self::MARCA,
            'created_by' => $rhAdmin?->id,
        ]);
    }
}
