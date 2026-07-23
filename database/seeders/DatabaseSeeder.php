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
     * Orden importante: catalogo organizacional antes que roles/usuarios,
     * ya que los usuarios de demostracion referencian sucursales, departamentos y puestos.
     */
    public function run(): void
    {
        $this->call([
            RolesYPermisosSeeder::class,
            EmpresaSeeder::class,
            SucursalSeeder::class,
            DepartamentoSeeder::class,
            PuestoSeeder::class,
            DocumentTypeSeeder::class,
            UsuarioDemoSeeder::class,
            CursoInduccionSeeder::class,
            DashboardDemoSeeder::class,
        ]);
    }
}
