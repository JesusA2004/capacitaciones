<?php

namespace Database\Seeders;

use App\Services\RecepcionDocumental\CatalogoReglasRecepcion;
use Illuminate\Database\Seeder;

/**
 * Siembra el catálogo de «lo que recibo» de Recepción documental con los
 * valores por defecto de config/recepcion_documental.php. Idempotente:
 * nunca pisa una entrada que RH ya configuró (la pantalla también lo
 * siembra sola la primera vez). Sin Faker: corre en producción.
 */
class CatalogoRecepcionDocumentalSeeder extends Seeder
{
    public function run(CatalogoReglasRecepcion $catalogo): void
    {
        $catalogo->sembrar();
    }
}
