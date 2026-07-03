<?php

namespace Database\Seeders;

use App\Enums\EstadoUsuario;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Usuarios de demostracion EXCLUSIVOS para entornos de desarrollo.
 *
 * Contraseña de todos los usuarios de este seeder: "Capacitacion2026!"
 * Nunca usar estas cuentas ni esta contraseña en un ambiente productivo.
 */
class UsuarioDemoSeeder extends Seeder
{
    public function run(): void
    {
        $passwordDesarrollo = Hash::make('Capacitacion2026!');

        $monterrey = Sucursal::where('clave', 'MTY01')->first();
        $cdmx = Sucursal::where('clave', 'CDMX01')->first();
        $recursosHumanos = Departamento::where('nombre', 'Recursos Humanos')->first();
        $operaciones = Departamento::where('nombre', 'Operaciones')->first();
        $coordinadorCapacitacion = Puesto::where('nombre', 'Coordinador de Capacitación')->first();
        $gerenteSucursal = Puesto::where('nombre', 'Gerente de Sucursal')->first();

        $usuarios = [
            [
                'datos' => ['name' => 'Ana', 'apellidos' => 'Martínez Ruiz', 'email' => 'superadmin@mrlana.test', 'numero_empleado' => 'EMP-0001'],
                'sucursal' => $monterrey, 'departamento' => $recursosHumanos, 'puesto' => $coordinadorCapacitacion,
                'roles' => ['super_admin'],
            ],
            [
                'datos' => ['name' => 'Luis', 'apellidos' => 'Hernández Gómez', 'email' => 'admin.capacitacion@mrlana.test', 'numero_empleado' => 'EMP-0002'],
                'sucursal' => $monterrey, 'departamento' => $recursosHumanos, 'puesto' => $coordinadorCapacitacion,
                'roles' => ['administrador_capacitacion'],
            ],
            [
                'datos' => ['name' => 'Carla', 'apellidos' => 'Villegas Soto', 'email' => 'instructor@mrlana.test', 'numero_empleado' => 'EMP-0003'],
                'sucursal' => $monterrey, 'departamento' => $recursosHumanos, 'puesto' => $coordinadorCapacitacion,
                'roles' => ['instructor'],
            ],
            [
                'datos' => ['name' => 'Jorge', 'apellidos' => 'Ramírez Peña', 'email' => 'gerente.sucursal@mrlana.test', 'numero_empleado' => 'EMP-0004'],
                'sucursal' => $cdmx, 'departamento' => $operaciones, 'puesto' => $gerenteSucursal,
                'roles' => ['gerente_sucursal'],
            ],
            [
                'datos' => ['name' => 'Paola', 'apellidos' => 'Cordero Luna', 'email' => 'supervisor@mrlana.test', 'numero_empleado' => 'EMP-0005'],
                'sucursal' => $cdmx, 'departamento' => $operaciones, 'puesto' => $gerenteSucursal,
                'roles' => ['supervisor'],
            ],
            [
                'datos' => ['name' => 'Miguel', 'apellidos' => 'Torres Aguilar', 'email' => 'colaborador1@mrlana.test', 'numero_empleado' => 'EMP-0006'],
                'sucursal' => $monterrey, 'departamento' => $operaciones, 'puesto' => null,
                'roles' => ['colaborador'],
            ],
            [
                'datos' => ['name' => 'Daniela', 'apellidos' => 'Flores Nava', 'email' => 'colaborador2@mrlana.test', 'numero_empleado' => 'EMP-0007'],
                'sucursal' => $cdmx, 'departamento' => $operaciones, 'puesto' => null,
                'roles' => ['colaborador'],
            ],
            [
                'datos' => ['name' => 'Roberto', 'apellidos' => 'Salinas Ibarra', 'email' => 'auditor@mrlana.test', 'numero_empleado' => 'EMP-0008'],
                'sucursal' => $monterrey, 'departamento' => $recursosHumanos, 'puesto' => null,
                'roles' => ['auditor'],
            ],
        ];

        foreach ($usuarios as $definicion) {
            $usuario = User::firstOrCreate(
                ['email' => $definicion['datos']['email']],
                [
                    'name' => $definicion['datos']['name'],
                    'apellidos' => $definicion['datos']['apellidos'],
                    'numero_empleado' => $definicion['datos']['numero_empleado'],
                    'password' => $passwordDesarrollo,
                    'email_verified_at' => now(),
                    'sucursal_principal_id' => $definicion['sucursal']?->id,
                    'departamento_id' => $definicion['departamento']?->id,
                    'puesto_id' => $definicion['puesto']?->id,
                    'fecha_ingreso' => now()->subMonths(fake()->numberBetween(1, 36)),
                    'estatus' => EstadoUsuario::Activo,
                    'zona_horaria' => 'America/Mexico_City',
                ],
            );

            $usuario->syncRoles($definicion['roles']);
        }
    }
}
