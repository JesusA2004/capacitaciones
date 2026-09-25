<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Antes sembraba un catálogo genérico de puestos por departamento
 * ("Generalista de RH", "Soporte Técnico", "Ejecutivo de Ventas"…) que no
 * corresponde a la estructura real de Mr. Lana. La única fuente de puestos
 * es ahora PuestoJerarquiaSeeder; esta clase se conserva para no romper a
 * quien la llame y solo delega.
 */
class PuestoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PuestoJerarquiaSeeder::class);
    }
}
