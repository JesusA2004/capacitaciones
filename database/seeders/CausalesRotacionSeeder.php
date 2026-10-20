<?php

namespace Database\Seeders;

use App\Services\CierreLaboral\CausalRotacionService;
use Illuminate\Database\Seeder;

/**
 * Catálogo inicial de causales de rotación (config/causales_rotacion.php):
 * el motivo de cada uno de los tres tipos de baja. Idempotente y sin Faker
 * (corre en producción): agrega las que falten por clave y nunca pisa lo que
 * RH editó o desactivó en Bajas y finiquitos → «Causales de rotación».
 */
class CausalesRotacionSeeder extends Seeder
{
    public function run(CausalRotacionService $causales): void
    {
        $causales->sembrarIniciales();
    }
}
