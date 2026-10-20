<?php

namespace Database\Seeders;

use App\Services\Bajas\CatalogoBajasService;
use Illuminate\Database\Seeder;

/**
 * Catálogo del módulo de bajas (config/bajas.php): 3 formas de salida, 17
 * causales RV/NR/RE y los documentos requeridos por forma y causal.
 * Idempotente y sin Faker (corre en producción): agrega lo que falte por
 * clave y nunca pisa lo que RH editó o desactivó.
 */
class CausalesRotacionSeeder extends Seeder
{
    public function run(CatalogoBajasService $catalogo): void
    {
        $catalogo->sembrar();
    }
}
