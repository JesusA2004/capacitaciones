<?php

namespace Database\Seeders;

use App\Enums\EstadoUsuario;
use App\Enums\Genero;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
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

        // Sucursales reales (ver claude/headcount/ y SucursalSeeder) — antes
        // este seeder usaba "Corporativo Monterrey"/"CDMX" genéricas que no
        // existen en la operación real, así que no debían seguir en el
        // sistema como si fueran sucursales verdaderas.
        $sucursalUno = Sucursal::where('clave', 'IXT01')->first();
        $sucursalDos = Sucursal::where('clave', 'CUE01')->first();
        $recursosHumanos = Departamento::where('nombre', 'Recursos Humanos')->first();
        $operaciones = Departamento::where('nombre', 'Operaciones')->first();
        $coordinadorCapacitacion = Puesto::where('nombre', 'Coordinador de Capacitación')->first();
        $gerenteSucursal = Puesto::where('nombre', 'Gerente de Sucursal')->first();

        // Todo colaborador ACTIVO debe tener puesto (sección 5/8 de la
        // reestructuración): antes varios usuarios demo se creaban con
        // `puesto` null (rh_admin, auditor, subgerente, etc.), lo que
        // rompía tanto el organigrama como el cálculo de plantilla actual
        // de headcount (App\Services\Headcount\HeadcountService cuenta
        // solo colaboradores con puesto_id).
        $generalistaRh = Puesto::where('nombre', 'Generalista de RH')->first();
        $gerenteRh = Puesto::where('nombre', 'Gerente de Recursos Humanos')->first();
        $directorComercial = Puesto::where('nombre', 'Director comercial')->first();
        $subgerentePuesto = Puesto::where('nombre', 'Subgerente')->first();
        $coordinadoraRegionalPuesto = Puesto::where('nombre', 'Coordinadora regional')->first();
        $coordinadoraPuesto = Puesto::where('nombre', 'Coordinadora')->first();
        $supervisorOperaciones = Puesto::where('nombre', 'Supervisor de Operaciones')->first();
        $gestorFijo = Puesto::where('nombre', 'Gestor fijo')->first();
        $gestorVolante = Puesto::where('nombre', 'Gestor volante')->first();

        $usuarios = [
            [
                'datos' => ['name' => 'Ana', 'apellidos' => 'Martínez Ruiz', 'email' => 'superadmin@mrlana.test', 'numero_empleado' => 'EMP-0001', 'genero' => Genero::Femenino],
                'sucursal' => $sucursalUno, 'departamento' => $recursosHumanos, 'puesto' => $coordinadorCapacitacion,
                'roles' => ['super_admin'],
            ],
            [
                'datos' => ['name' => 'Luis', 'apellidos' => 'Hernández Gómez', 'email' => 'admin.capacitacion@mrlana.test', 'numero_empleado' => 'EMP-0002', 'genero' => Genero::Masculino],
                'sucursal' => $sucursalUno, 'departamento' => $recursosHumanos, 'puesto' => $coordinadorCapacitacion,
                'roles' => ['administrador_capacitacion'],
            ],
            [
                'datos' => ['name' => 'Carla', 'apellidos' => 'Villegas Soto', 'email' => 'instructor@mrlana.test', 'numero_empleado' => 'EMP-0003', 'genero' => Genero::Femenino],
                'sucursal' => $sucursalUno, 'departamento' => $recursosHumanos, 'puesto' => $coordinadorCapacitacion,
                'roles' => ['instructor'],
            ],
            [
                'datos' => ['name' => 'Jorge', 'apellidos' => 'Ramírez Peña', 'email' => 'gerente.sucursal@mrlana.test', 'numero_empleado' => 'EMP-0004', 'genero' => Genero::Masculino],
                'sucursal' => $sucursalDos, 'departamento' => $operaciones, 'puesto' => $gerenteSucursal,
                'roles' => ['gerente_sucursal'],
            ],
            [
                'datos' => ['name' => 'Paola', 'apellidos' => 'Cordero Luna', 'email' => 'supervisor@mrlana.test', 'numero_empleado' => 'EMP-0005', 'genero' => Genero::Femenino],
                'sucursal' => $sucursalDos, 'departamento' => $operaciones, 'puesto' => $gerenteSucursal,
                'roles' => ['supervisor'],
            ],
            [
                'datos' => ['name' => 'Miguel', 'apellidos' => 'Torres Aguilar', 'email' => 'colaborador1@mrlana.test', 'numero_empleado' => 'EMP-0006', 'genero' => Genero::Masculino],
                'sucursal' => $sucursalUno, 'departamento' => $operaciones, 'puesto' => $gestorFijo,
                'roles' => ['colaborador'],
            ],
            [
                'datos' => ['name' => 'Daniela', 'apellidos' => 'Flores Nava', 'email' => 'colaborador2@mrlana.test', 'numero_empleado' => 'EMP-0007', 'genero' => Genero::Femenino],
                'sucursal' => $sucursalDos, 'departamento' => $operaciones, 'puesto' => $gestorVolante,
                'roles' => ['colaborador'],
            ],
            [
                'datos' => ['name' => 'Roberto', 'apellidos' => 'Salinas Ibarra', 'email' => 'auditor@mrlana.test', 'numero_empleado' => 'EMP-0008', 'genero' => Genero::Masculino],
                'sucursal' => $sucursalUno, 'departamento' => $recursosHumanos, 'puesto' => $generalistaRh,
                'roles' => ['auditor'],
            ],
            [
                'datos' => ['name' => 'Sofía', 'apellidos' => 'Reyes Marín', 'email' => 'rh.admin@mrlana.test', 'numero_empleado' => 'EMP-0009', 'genero' => Genero::Femenino],
                'sucursal' => $sucursalUno, 'departamento' => $recursosHumanos, 'puesto' => $gerenteRh,
                'roles' => ['rh_admin'],
            ],
            [
                'datos' => ['name' => 'Iván', 'apellidos' => 'Cabrera Lomelí', 'email' => 'rh.auxiliar@mrlana.test', 'numero_empleado' => 'EMP-0010', 'genero' => Genero::Masculino],
                'sucursal' => $sucursalUno, 'departamento' => $recursosHumanos, 'puesto' => $generalistaRh,
                'roles' => ['rh_auxiliar'],
            ],
            [
                'datos' => ['name' => 'Fernanda', 'apellidos' => 'Ochoa Del Río', 'email' => 'director.comercial@mrlana.test', 'numero_empleado' => 'EMP-0011', 'genero' => Genero::Femenino],
                'sucursal' => $sucursalUno, 'departamento' => $operaciones, 'puesto' => $directorComercial,
                'roles' => ['director_comercial'],
            ],
            [
                'datos' => ['name' => 'Héctor', 'apellidos' => 'Bravo Núñez', 'email' => 'gerente.regional@mrlana.test', 'numero_empleado' => 'EMP-0012', 'genero' => Genero::Masculino],
                'sucursal' => $sucursalDos, 'departamento' => $operaciones, 'puesto' => $gerenteSucursal,
                'roles' => ['gerente_regional'],
            ],
            [
                'datos' => ['name' => 'Claudia', 'apellidos' => 'Estrada Peña', 'email' => 'gerente@mrlana.test', 'numero_empleado' => 'EMP-0013', 'genero' => Genero::Femenino],
                'sucursal' => $sucursalDos, 'departamento' => $operaciones, 'puesto' => $gerenteSucursal,
                'roles' => ['gerente'],
            ],
            [
                'datos' => ['name' => 'Ricardo', 'apellidos' => 'Zamora Vidal', 'email' => 'subgerente@mrlana.test', 'numero_empleado' => 'EMP-0014', 'genero' => Genero::Masculino],
                'sucursal' => $sucursalDos, 'departamento' => $operaciones, 'puesto' => $subgerentePuesto,
                'roles' => ['subgerente'],
            ],
            [
                'datos' => ['name' => 'Adriana', 'apellidos' => 'Cortés Beltrán', 'email' => 'coordinadora.regional@mrlana.test', 'numero_empleado' => 'EMP-0015', 'genero' => Genero::Femenino],
                'sucursal' => $sucursalUno, 'departamento' => $operaciones, 'puesto' => $coordinadoraRegionalPuesto,
                'roles' => ['coordinadora_regional'],
            ],
            [
                'datos' => ['name' => 'Brenda', 'apellidos' => 'Nájera Solís', 'email' => 'coordinadora@mrlana.test', 'numero_empleado' => 'EMP-0016', 'genero' => Genero::Femenino],
                'sucursal' => $sucursalUno, 'departamento' => $operaciones, 'puesto' => $coordinadoraPuesto,
                'roles' => ['coordinadora'],
            ],
            [
                'datos' => ['name' => 'Diego', 'apellidos' => 'Ponce Aranda', 'email' => 'jefe.directo@mrlana.test', 'numero_empleado' => 'EMP-0017', 'genero' => Genero::Masculino],
                'sucursal' => $sucursalDos, 'departamento' => $operaciones, 'puesto' => $supervisorOperaciones,
                'roles' => ['jefe_directo'],
            ],
        ];

        $movimientos = app(MovimientoLaboralService::class);
        $sistema = User::query()->where('email', 'superadmin@mrlana.test')->first();

        foreach ($usuarios as $indice => $definicion) {
            $yaExistia = User::where('email', $definicion['datos']['email'])->exists();

            $usuario = User::firstOrCreate(
                ['email' => $definicion['datos']['email']],
                [
                    'name' => $definicion['datos']['name'],
                    'apellidos' => $definicion['datos']['apellidos'],
                    'genero' => $definicion['datos']['genero'],
                    'numero_empleado' => $definicion['datos']['numero_empleado'],
                    'password' => $passwordDesarrollo,
                    'email_verified_at' => now(),
                    'sucursal_principal_id' => $definicion['sucursal']?->id,
                    'departamento_id' => $definicion['departamento']?->id,
                    'puesto_id' => $definicion['puesto']?->id,
                    'fecha_ingreso' => now()->subMonths(1 + ($indice % 36)),
                    'fecha_nacimiento' => $this->fechaNacimientoDemo($indice),
                    ...$this->contactoEmergenciaDemo($indice),
                    'estatus' => EstadoUsuario::Activo,
                    'zona_horaria' => 'America/Mexico_City',
                ],
            );

            if ($usuario->genero === null) {
                $usuario->update(['genero' => $definicion['datos']['genero']]);
            }

            // Backfill para bases ya sembradas antes de que este seeder
            // capturara estos datos (ver docs/CUMPLEANOS.md): sin esto, el
            // modulo de cumpleanos y el contacto de emergencia quedaban
            // vacios en cualquier entorno sembrado con una version anterior.
            if ($usuario->fecha_nacimiento === null) {
                $usuario->update(['fecha_nacimiento' => $this->fechaNacimientoDemo($indice)]);
            }

            if ($usuario->contacto_emergencia_telefono === null) {
                $usuario->update($this->contactoEmergenciaDemo($indice));
            }

            $usuario->syncRoles($definicion['roles']);

            // Historial de alta (para el KPI de rotación del dashboard, ver
            // MetricasRhDashboardService::rotacion()): solo si el usuario es
            // nuevo de este seeder Y todavía no tiene un movimiento de alta
            // (evita duplicar si el seeder corre de nuevo).
            if (! $yaExistia && $sistema !== null && ! $usuario->movimientosLaborales()->where('tipo_movimiento', 'alta')->exists()) {
                $movimientos->registrarAlta($usuario, $sistema);
            }
        }
    }

    /**
     * Fecha de nacimiento determinista por indice: los primeros 3
     * colaboradores caen en el mes actual (para que el calendario nunca se
     * vea vacio en un ambiente recien sembrado) y el resto se reparte entre
     * los demas meses del anio, con edades razonables (25 a 54 anios).
     */
    private function fechaNacimientoDemo(int $indice): Carbon
    {
        $anioNacimiento = now()->year - (25 + ($indice % 30));
        $mesNacimiento = $indice < 3 ? now()->month : (($indice + now()->month) % 12) + 1;
        $diaNacimiento = 1 + (($indice * 5) % 27);

        return Carbon::create($anioNacimiento, $mesNacimiento, $diaNacimiento);
    }

    /**
     * Contacto de emergencia determinista por indice (dato de demostración,
     * no una persona real): la app móvil de RH lo muestra en el detalle del
     * colaborador para saber a quién llamar si el colaborador no contesta.
     *
     * @return array{contacto_emergencia_nombre: string, contacto_emergencia_telefono: string}
     */
    private function contactoEmergenciaDemo(int $indice): array
    {
        $nombres = [
            'María Elena Ruiz', 'José Luis Pérez', 'Guadalupe Torres',
            'Francisco Javier Gómez', 'Rosa María Sánchez', 'Antonio Hernández',
            'Leticia Ramírez', 'Juan Carlos Domínguez',
        ];

        return [
            'contacto_emergencia_nombre' => $nombres[$indice % count($nombres)],
            'contacto_emergencia_telefono' => sprintf('55%08d', 10000000 + ($indice * 137)),
        ];
    }
}
