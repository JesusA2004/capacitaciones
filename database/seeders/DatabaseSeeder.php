<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Solo datos requeridos reales/idempotentes corren siempre (incluida
     * producción). Orden importante: catalogo organizacional antes que
     * roles/usuarios/matriz, que referencian sucursales, departamentos y
     * puestos.
     *
     * Los datos de DEMOSTRACIÓN (cuentas @mrlana.test con contraseña
     * conocida, dashboard/solicitudes de ejemplo, gestores demo) NUNCA
     * corren aquí: viven en DemoSeeder y solo se ejecutan en local/testing
     * o con SEED_DEMO_DATA=true — ver docs/MIGRACION_PRODUCCION_2026_09.md.
     */
    public function run(): void
    {
        $this->call([
            RolesYPermisosSeeder::class,
            EmpresaSeeder::class,
            SucursalSeeder::class,
            DepartamentoSeeder::class,
            PuestoJerarquiaSeeder::class,
            DocumentTypeSeeder::class,
            MatrizComercialSeeder::class,
            CursoInduccionSeeder::class,
            BirthdayPhraseSeeder::class,
        ]);

        if (app()->environment(['local', 'testing']) || config('features.seed_demo_data')) {
            $this->call(DemoSeeder::class);
        }
    }
}
