<?php

namespace Database\Seeders;

use App\Enums\EstadoUsuario;
use App\Enums\Genero;
use App\Enums\MotivoCobertura;
use App\Enums\TipoAsignacionNodoComercial;
use App\Enums\TipoNodoComercial;
use App\Models\AsignacionNodoComercial;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\MatrizComercial\MatrizComercialService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Plantilla demo completa para el organigrama (solo local/testing, ver
 * DemoSeeder), con la estructura real que definió dirección:
 *
 *  - En CADA sucursal: 1 Gerente de Sucursal, 1 Subgerente, 1 Coordinadora,
 *    1 Gestor por cada ruta de cobro de su zona (con esa ruta asignada en la
 *    matriz comercial) y 1 Gestor volante.
 *  - Solo los gestores tienen ruta: se cierran las rutas demo que hubieran
 *    quedado asignadas a cualquier otro puesto.
 *  - Corporativo: 1 Monitorista, 5 Analistas de Mesa de Control, 1 Tesorero
 *    (el Contador queda vacante a propósito).
 *  - Coberturas de ejemplo: la gerencia de Cuernavaca está vacante y la cubre
 *    el gerente de Córdoba; la región Q3 no tiene gerente regional y la cubre
 *    la de Q1.
 *
 * Idempotente: solo crea lo que falta. Los colaboradores se crean sin cuenta
 * de acceso (válido en el sistema; basta para el organigrama). Nunca usa
 * Faker: nombres de listas fijas.
 */
class OrganigramaDemoSeeder extends Seeder
{
    private const NOMBRES_F = ['Alejandra', 'Beatriz', 'Carmen', 'Diana', 'Elena', 'Fabiola', 'Gabriela', 'Hilda', 'Irma', 'Josefina', 'Karina', 'Lorena', 'Mariana', 'Norma', 'Olivia', 'Patricia', 'Rocío', 'Silvia', 'Teresa', 'Verónica', 'Yolanda', 'Zulema'];

    private const NOMBRES_M = ['Arturo', 'Bernardo', 'César', 'Daniel', 'Eduardo', 'Felipe', 'Gerardo', 'Hugo', 'Ignacio', 'Javier', 'Leonardo', 'Manuel', 'Noé', 'Octavio', 'Pablo', 'Raúl', 'Sergio', 'Tomás', 'Ulises', 'Víctor', 'Rodrigo', 'Alfredo'];

    private const APELLIDOS = ['García', 'Hernández', 'López', 'Martínez', 'González', 'Pérez', 'Rodríguez', 'Sánchez', 'Ramírez', 'Cruz', 'Flores', 'Gómez', 'Morales', 'Vázquez', 'Jiménez', 'Reyes', 'Díaz', 'Torres', 'Gutiérrez', 'Ruiz', 'Mendoza', 'Aguilar', 'Ortiz', 'Castillo', 'Romero', 'Álvarez', 'Chávez', 'Rivera', 'Juárez', 'Domínguez'];

    /** Rutas que no son de cobro de un gestor (gerencia, subgerencia, volante). */
    private const PATRON_RUTA_NO_GESTOR = '/GERENCIA|SUBGERE|GTE|VOLANTE/i';

    /** Sucursal cuya gerencia está vacante y la cubre otro gerente. */
    private const SUCURSAL_CUBIERTA = 'CUE01';

    private const SUCURSAL_QUE_CUBRE = 'COR01';

    private int $contador = 0;

    /** @var Collection<string, Puesto> */
    private Collection $puestos;

    /** @var Collection<string, Departamento> */
    private Collection $departamentos;

    public function run(): void
    {
        $this->puestos = Puesto::query()->where('activo', true)->get()->keyBy('nombre');
        $this->departamentos = Departamento::all()->keyBy('nombre');
        $this->contador = Colaborador::withTrashed()->where('numero_empleado', 'like', 'ORG-%')->count();

        $gestor = $this->puestos->get('Gestor');

        if ($gestor === null) {
            return;
        }

        $matriz = app(MatrizComercialService::class);
        $this->soloGestoresConRuta($matriz, $gestor);

        $zonas = NodoComercial::query()
            ->where('tipo', TipoNodoComercial::Zona->value)
            ->whereNotNull('sucursal_id')
            ->where('activa', true)
            ->get()
            ->keyBy('sucursal_id');

        // El Corporativo no es sucursal operativa: no lleva gerente, gestores
        // ni coordinadora (sus puestos generales se asignan abajo).
        foreach (Sucursal::query()->where('activo', true)->where('clave', '!=', 'CORP01')->orderBy('nombre')->get() as $sucursal) {
            if ($sucursal->clave !== self::SUCURSAL_CUBIERTA) {
                $this->asegurar('Gerente de Sucursal', $sucursal, 'Ventas');
            }

            $this->asegurar('Subgerente', $sucursal, 'Ventas');
            $this->asegurar('Coordinadora', $sucursal, 'Operaciones', genero: Genero::Femenino);
            $this->asegurar('Gestor volante', $sucursal, 'Ventas');

            $zona = $zonas->get($sucursal->id);

            if ($zona !== null) {
                $this->gestoresPorRuta($matriz, $gestor, $sucursal, $zona);
            }
        }

        // Puestos generales: viven en el Corporativo, nunca en una sucursal.
        $corporativo = Sucursal::query()->where('clave', 'CORP01')->first();
        $this->asegurar('Monitorista', $corporativo, 'Sistemas', cantidad: 1);
        $this->asegurar('Tesorero', $corporativo, 'Contraloría', cantidad: 1);
        $this->asegurar('Analista de Mesa de Control', $corporativo, 'Mesa de Control', cantidad: 5);

        $this->coberturasDeEjemplo();
    }

    /**
     * Solo los gestores tienen ruta (la cartera que cobran): cualquier ruta
     * demo que haya quedado asignada a otro puesto se libera.
     */
    private function soloGestoresConRuta(MatrizComercialService $matriz, Puesto $gestor): void
    {
        $asignaciones = AsignacionNodoComercial::query()
            ->where('activo', true)
            ->whereHas('colaborador', fn ($query) => $query->where(fn ($q) => $q->whereNull('puesto_id')->orWhere('puesto_id', '!=', $gestor->id)))
            ->with(['nodo', 'colaborador'])
            ->get();

        foreach ($asignaciones as $asignacion) {
            if ($asignacion->nodo === null || $asignacion->colaborador === null) {
                continue;
            }

            if ($asignacion->tipo_asignacion === TipoAsignacionNodoComercial::Gestor) {
                $matriz->asignarResponsable($asignacion->nodo, null);

                continue;
            }

            $matriz->quitarAsignacion($asignacion->nodo, $asignacion->colaborador, $asignacion->tipo_asignacion);
        }
    }

    /**
     * Un gestor por cada ruta de cobro activa de la zona: primero se usan
     * los gestores de la sucursal que aún no tienen ruta; si faltan, se crean.
     */
    private function gestoresPorRuta(MatrizComercialService $matriz, Puesto $gestor, Sucursal $sucursal, NodoComercial $zona): void
    {
        $rutas = NodoComercial::query()
            ->where('parent_id', $zona->id)
            ->where('tipo', TipoNodoComercial::Ruta->value)
            ->where('activa', true)
            ->orderBy('orden')
            ->get()
            ->reject(fn (NodoComercial $ruta) => preg_match(self::PATRON_RUTA_NO_GESTOR, $ruta->nombre) === 1);

        $conRuta = AsignacionNodoComercial::query()
            ->where('activo', true)
            ->where('tipo_asignacion', TipoAsignacionNodoComercial::Gestor->value)
            ->pluck('colaborador_id')
            ->all();

        $libres = Colaborador::query()
            ->where('estatus', EstadoUsuario::Activo->value)
            ->where('puesto_id', $gestor->id)
            ->where('sucursal_principal_id', $sucursal->id)
            ->whereNotIn('id', $conRuta)
            ->orderBy('id')
            ->get();

        foreach ($rutas as $ruta) {
            if ($ruta->responsable_colaborador_id !== null) {
                continue;
            }

            $colaborador = $libres->shift() ?? $this->crear($gestor, $sucursal, 'Ventas');
            $matriz->asignarResponsable($ruta, $colaborador);
        }
    }

    /**
     * Garantiza `$cantidad` colaboradores activos en el puesto (por sucursal
     * si se indica, o en total si es corporativo).
     */
    private function asegurar(string $nombrePuesto, ?Sucursal $sucursal, string $departamento, int $cantidad = 1, ?Genero $genero = null): void
    {
        $puesto = $this->puestos->get($nombrePuesto);

        if ($puesto === null) {
            return;
        }

        $actuales = Colaborador::query()
            ->where('estatus', EstadoUsuario::Activo->value)
            ->where('puesto_id', $puesto->id)
            ->when($sucursal !== null, fn ($query) => $query->where('sucursal_principal_id', $sucursal?->id))
            ->count();

        for ($i = $actuales; $i < $cantidad; $i++) {
            $this->crear($puesto, $sucursal ?? Sucursal::query()->where('clave', 'CORP01')->first(), $departamento, $genero);
        }
    }

    private function crear(Puesto $puesto, ?Sucursal $sucursal, string $departamento, ?Genero $genero = null): Colaborador
    {
        $n = ++$this->contador;
        $genero ??= $n % 2 === 0 ? Genero::Femenino : Genero::Masculino;
        $nombres = $genero === Genero::Femenino ? self::NOMBRES_F : self::NOMBRES_M;

        return Colaborador::withTrashed()->firstOrCreate(
            ['numero_empleado' => sprintf('ORG-%04d', $n)],
            [
                'name' => $nombres[$n % count($nombres)],
                'apellidos' => sprintf('%s %s', self::APELLIDOS[$n % count(self::APELLIDOS)], self::APELLIDOS[($n * 7 + 3) % count(self::APELLIDOS)]),
                'genero' => $genero,
                'sucursal_principal_id' => $sucursal?->id,
                'departamento_id' => $this->departamentos->get($departamento)?->id,
                'puesto_id' => $puesto->id,
                'fecha_ingreso' => now()->subMonths(2 + ($n % 60))->toDateString(),
                'fecha_nacimiento' => now()->subYears(23 + ($n % 30))->subDays($n * 11 % 365)->toDateString(),
                'estatus' => EstadoUsuario::Activo,
            ],
        );
    }

    /**
     * Cuernavaca sin gerente → la cubre el gerente de Córdoba. Q3 sin
     * gerente regional → la cubre la gerente regional de Q1.
     */
    private function coberturasDeEjemplo(): void
    {
        $gerenteSucursal = $this->puestos->get('Gerente de Sucursal');
        $cubierta = Sucursal::query()->where('clave', self::SUCURSAL_CUBIERTA)->first();
        $origen = Sucursal::query()->where('clave', self::SUCURSAL_QUE_CUBRE)->first();

        if ($gerenteSucursal !== null && $cubierta !== null && $origen !== null) {
            $gerenteOrigen = Colaborador::query()
                ->where('estatus', EstadoUsuario::Activo->value)
                ->where('puesto_id', $gerenteSucursal->id)
                ->where('sucursal_principal_id', $origen->id)
                ->first();

            if ($gerenteOrigen !== null) {
                CoberturaPuesto::query()->firstOrCreate(
                    ['puesto_id' => $gerenteSucursal->id, 'sucursal_id' => $cubierta->id, 'activa' => true],
                    [
                        'colaborador_id' => $gerenteOrigen->id,
                        'motivo' => MotivoCobertura::Baja,
                        'nota' => 'Cubre mientras se contrata y capacita al nuevo gerente de Cuernavaca (dato de demostración).',
                        'fecha_inicio' => now()->subWeeks(3)->toDateString(),
                    ],
                );
            }
        }

        $gerenteRegional = $this->puestos->get('Gerente regional');
        $q3 = NodoComercial::query()->where('tipo', TipoNodoComercial::Region->value)->where('nombre', 'Región Q3')->first();

        if ($gerenteRegional === null || $q3 === null) {
            return;
        }

        $titular = Colaborador::query()
            ->where('estatus', EstadoUsuario::Activo->value)
            ->where('puesto_id', $gerenteRegional->id)
            ->first();

        if ($titular !== null) {
            CoberturaPuesto::query()->firstOrCreate(
                ['puesto_id' => $gerenteRegional->id, 'region_id' => $q3->id, 'activa' => true],
                [
                    'colaborador_id' => $titular->id,
                    'motivo' => MotivoCobertura::Baja,
                    'nota' => 'Q3 quedó sin gerente regional; la gerente de Q1 cubre ambas regiones (dato de demostración).',
                    'fecha_inicio' => now()->subMonths(2)->toDateString(),
                ],
            );
        }
    }
}
