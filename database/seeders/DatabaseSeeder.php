<?php

namespace Database\Seeders;

use App\Services\Organigrama\JefeDirectoService;
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
        // A) CATÁLOGOS PRODUCTIVOS REALES: idempotentes, sin personas.
        $this->call([
            RolesYPermisosSeeder::class,
            EmpresaSeeder::class,
            SucursalSeeder::class,
            DepartamentoSeeder::class,
            PuestoJerarquiaSeeder::class,
            // Departamentos/puestos reales de BASE_GENERAL sin equivalente
            // canónico (Jurídico, Mantenimiento, Abogado…). Después de la
            // estructura confirmada, de la que cuelgan.
            CatalogosBaseGeneralSeeder::class,
            DocumentTypeSeeder::class,
            MatrizComercialSeeder::class,
            // Necesita sucursales y puestos ya sembrados (empata por nombre).
            PlantillaAutorizadaSeeder::class,
            CursoInduccionSeeder::class,
            BirthdayPhraseSeeder::class,
            MotivosRechazoCandidatoSeeder::class,
        ]);

        // B) DEMO: nunca en producción.
        if (app()->environment(['local', 'testing']) || config('features.seed_demo_data')) {
            $this->call(DemoSeeder::class);
        }

        // Jefe directo = organigrama: todos quedan con el jefe que les toca
        // por puesto y sucursal (JefeDirectoService), nunca uno a mano.
        app(JefeDirectoService::class)->sincronizar();
    }
}
