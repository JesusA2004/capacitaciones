<?php

namespace App\Services\Seguimiento;

use App\Enums\EstadoUsuario;
use App\Enums\MetodoVinculoReportes;
use App\Enums\TipoVinculoReportes;
use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\VinculoReportes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Correspondencia AUDITABLE PEOPLE ↔ Reportes (tabla vinculos_reportes),
 * a nivel PERSONA y SUCURSAL (docs/SEGUIMIENTO.md §3):
 *
 *   - Colaborador de PEOPLE ↔ una PERSONA de Reportes (todos sus employee
 *     ids: altas por NOI, por Lendus, variantes de nombre, una por ruta).
 *     La fila guarda un employee id; el vínculo vale para la persona que lo
 *     contiene, así que los vínculos hechos antes a un employee id siguen
 *     valiendo para su persona (sin migrar datos).
 *   - Sucursal de PEOPLE ↔ una SUCURSAL de Reportes con TODAS sus rutas: se
 *     guarda una fila por ruta (branch) y una ruta nueva de esa sucursal la
 *     hereda sola. Las rutas cambian (un gestor pasa de una a otra) pero
 *     siempre pertenecen a la misma sucursal.
 *
 * Nunca vincula solo: PEOPLE SUGIERE (mismo nombre, y si hay homónimos,
 * además la misma sucursal) y una persona con seguimiento.vincular confirma.
 * Cambiar un vínculo desactiva el anterior (quién, cuándo, por qué): el
 * historial no se borra.
 *
 * @phpstan-import-type Persona from DirectorioReportesService
 */
class VinculoReportesService
{
    /** Motivos de Reportes para un registro que no es persona. */
    private const MOTIVOS_NO_PERSONA = [
        'vacante' => 'Vacante',
        'cartera_vencida' => 'Cartera vencida',
        'codigo_de_promotor' => 'Código de promotor',
        'cuenta_operativa' => 'Cuenta operativa',
        'nombre_incompleto' => 'Nombre incompleto',
        'sin_nombre' => 'Sin nombre',
    ];

    public function __construct(
        private readonly ReportesCatalogoService $catalogo,
        private readonly DirectorioReportesService $directorio,
    ) {}

    /**
     * Vincula una sucursal de PEOPLE con una SUCURSAL de Reportes: todas sus
     * rutas de una vez (el nombre se toma de Reportes, nunca del formulario).
     *
     * @return int rutas vinculadas
     *
     * @throws ReportesNoDisponibleException
     */
    public function vincularSucursalReportes(User $usuario, Sucursal $sucursal, string $codigo, MetodoVinculoReportes $metodo): int
    {
        $grupo = collect($this->sucursalesConRutas())->firstWhere('codigo', $codigo);

        if ($grupo === null || $grupo['rutas'] === []) {
            throw ValidationException::withMessages(['codigo' => 'Esa sucursal ya no existe en Reportes (o no tiene rutas activas).']);
        }

        return DB::transaction(function () use ($usuario, $sucursal, $grupo, $metodo): int {
            $vinculadas = 0;

            foreach ($grupo['rutas'] as $ruta) {
                $this->vincularSucursal($usuario, $sucursal, $ruta['id'], $ruta['nombre'], $grupo['codigo'], $metodo);
                $vinculadas++;
            }

            return $vinculadas;
        });
    }

    /**
     * Vincula UNA ruta (branch) con una sucursal de PEOPLE (compatibilidad).
     *
     * @throws ReportesNoDisponibleException
     */
    public function vincularSucursalDesdeReportes(User $usuario, Sucursal $sucursal, int $branchId, MetodoVinculoReportes $metodo): VinculoReportes
    {
        $branch = collect($this->catalogo->branches())->firstWhere('id', $branchId);

        if ($branch === null) {
            throw ValidationException::withMessages(['reportes_id' => 'Esa sucursal/ruta ya no existe en Reportes (o está inactiva).']);
        }

        return $this->vincularSucursal($usuario, $sucursal, $branchId, $branch['nombre'], $branch['codigo'] !== '' ? $branch['codigo'] : null, $metodo);
    }

    /**
     * Vincula un colaborador de PEOPLE con la PERSONA de Reportes que
     * contiene $reportesId (cualquiera de sus employee ids).
     *
     * @throws ReportesNoDisponibleException
     */
    public function vincularColaboradorDesdeReportes(User $usuario, Colaborador $colaborador, int $reportesId, MetodoVinculoReportes $metodo): VinculoReportes
    {
        $persona = $this->personaDe($reportesId);

        if ($persona === null) {
            throw ValidationException::withMessages(['reportes_id' => 'Esa persona ya no existe en Reportes (o está inactiva).']);
        }

        return $this->vincularPersona($usuario, $colaborador, $persona, $metodo);
    }

    /**
     * Pantalla «Vínculos con Reportes»: personas y sucursales del directorio
     * (en caché: abre sin esperar a Reportes). «Con datos» = actividad en el
     * mes más reciente, solo si esa actividad ya está en caché.
     *
     * @return array<string, mixed>
     *
     * @throws ReportesNoDisponibleException
     */
    public function pantallaDesdeReportes(?int $mesId): array
    {
        $actividad = $mesId === null ? null : $this->catalogo->actividadSemanalEnCache($mesId);
        $empleadosConDatos = [];
        $rutasConDatos = [];

        foreach (DatoReportes::lista(DatoReportes::ruta($actividad, 'employees.data')) as $fila) {
            array_push($empleadosConDatos, ...DatoReportes::enteros(DatoReportes::ruta($fila, 'employee.employee_ids')));
        }

        foreach (DatoReportes::lista(DatoReportes::ruta($actividad, 'branches.data')) as $fila) {
            $id = DatoReportes::entero($fila['branch_id'] ?? null);

            if ($id !== null) {
                $rutasConDatos[] = $id;
            }
        }

        return [
            ...$this->pantalla($empleadosConDatos, $rutasConDatos),
            'con_datos_disponible' => $actividad !== null,
            'actualizado_en' => $this->directorio->directorio()['actualizado_en'],
        ];
    }

    /**
     * Confirma, de una vez, TODAS las sugerencias vigentes de un tipo (las
     * recalcula aquí; nunca confía en lo que mande el navegador).
     *
     * @return int vínculos creados
     *
     * @throws ReportesNoDisponibleException
     */
    public function confirmarSugerencias(User $usuario, TipoVinculoReportes $tipo): int
    {
        $pantalla = $this->pantalla();
        $creados = 0;

        if ($tipo === TipoVinculoReportes::Sucursal) {
            foreach ($pantalla['sucursales'] as $fila) {
                $sucursalId = DatoReportes::entero(DatoReportes::ruta($fila, 'sugerencia.sucursal_id'));
                $sucursal = $sucursalId === null ? null : Sucursal::query()->where('id', $sucursalId)->first();

                if ($sucursal !== null) {
                    $creados += $this->vincularSucursalReportes($usuario, $sucursal, DatoReportes::texto($fila['codigo'] ?? null), MetodoVinculoReportes::SugerenciaConfirmada);
                }
            }

            return $creados;
        }

        foreach ($pantalla['personas'] as $fila) {
            $colaboradorId = DatoReportes::entero(DatoReportes::ruta($fila, 'sugerencia.colaborador_id'));
            $colaborador = $colaboradorId === null ? null : Colaborador::query()->where('id', $colaboradorId)->first();
            $persona = $this->personaDe((int) DatoReportes::entero($fila['reportes_id'] ?? null));

            if ($colaborador !== null && $persona !== null) {
                $this->vincularPersona($usuario, $colaborador, $persona, MetodoVinculoReportes::SugerenciaConfirmada);
                $creados++;
            }
        }

        return $creados;
    }

    /**
     * Ruta (branch) de Reportes => sucursal de PEOPLE: los vínculos activos
     * de cada ruta y, para una ruta sin vínculo, la sucursal de PEOPLE a la
     * que ya está vinculada su sucursal de Reportes (si es una sola).
     *
     * @return array<int, int>
     */
    public function sucursalPorBranch(): array
    {
        $mapa = [];

        VinculoReportes::query()->activos()->deTipo(TipoVinculoReportes::Sucursal)
            ->whereNotNull('sucursal_id')
            ->get(['reportes_id', 'sucursal_id'])
            ->each(function (VinculoReportes $v) use (&$mapa): void {
                $mapa[$v->reportes_id] = (int) $v->sucursal_id;
            });

        $sucursalDeRuta = $this->directorio->sucursalDeRuta();
        $peoplePorCodigo = $this->sucursalPeoplePorCodigo($mapa, $sucursalDeRuta);

        foreach ($sucursalDeRuta as $rutaId => $codigo) {
            if (! isset($mapa[$rutaId]) && isset($peoplePorCodigo[$codigo])) {
                $mapa[$rutaId] = $peoplePorCodigo[$codigo];
            }
        }

        return $mapa;
    }

    /**
     * employee id de Reportes => colaborador de PEOPLE (vínculos activos),
     * extendido a TODOS los employee ids de la persona vinculada.
     *
     * @return array<int, int>
     */
    public function colaboradorPorEmpleado(): array
    {
        $directo = [];

        VinculoReportes::query()->activos()->deTipo(TipoVinculoReportes::Colaborador)
            ->whereNotNull('colaborador_id')
            ->orderBy('id')
            ->get(['reportes_id', 'colaborador_id'])
            ->each(function (VinculoReportes $v) use (&$directo): void {
                $directo[$v->reportes_id] = (int) $v->colaborador_id;
            });

        $personaPorEmpleado = $this->directorio->personaPorEmpleado();
        $idsPorPersona = $this->directorio->idsPorPersona();
        $mapa = $directo;

        foreach ($directo as $empleadoId => $colaboradorId) {
            $personaId = $personaPorEmpleado[$empleadoId] ?? null;

            foreach ($personaId === null ? [] : ($idsPorPersona[$personaId] ?? []) as $id) {
                $mapa[$id] ??= $colaboradorId;
            }
        }

        return $mapa;
    }

    public function empleadoDeColaborador(int $colaboradorId): ?int
    {
        $vinculo = VinculoReportes::query()->activos()->deTipo(TipoVinculoReportes::Colaborador)
            ->where('colaborador_id', $colaboradorId)
            ->first(['reportes_id']);

        return $vinculo?->reportes_id;
    }

    public function vincularSucursal(User $usuario, Sucursal $sucursal, int $branchId, string $nombre, ?string $clave, MetodoVinculoReportes $metodo): VinculoReportes
    {
        return DB::transaction(function () use ($usuario, $sucursal, $branchId, $nombre, $clave, $metodo): VinculoReportes {
            $actual = VinculoReportes::query()->activos()->deTipo(TipoVinculoReportes::Sucursal)
                ->where('reportes_id', $branchId)
                ->lockForUpdate()
                ->first();

            if ($actual !== null && $actual->sucursal_id === $sucursal->id) {
                return $actual;
            }

            if ($actual !== null) {
                $this->desactivar($actual, $usuario, sprintf('Reasignado a la sucursal %s.', $sucursal->nombre));
            }

            return VinculoReportes::query()->create([
                'tipo' => TipoVinculoReportes::Sucursal->value,
                'sucursal_id' => $sucursal->id,
                'reportes_id' => $branchId,
                'reportes_clave' => $clave === null ? null : mb_substr($clave, 0, 100),
                'reportes_nombre' => mb_substr($nombre !== '' ? $nombre : sprintf('Ruta %d', $branchId), 0, 200),
                'metodo' => $metodo->value,
                'vinculado_por' => $usuario->id,
            ]);
        });
    }

    /**
     * Vincula por un employee id ya conocido (sin consultar el catálogo): si
     * el directorio lo agrupa en una persona, el vínculo vale para ella.
     */
    public function vincularColaborador(User $usuario, Colaborador $colaborador, int $empleadoId, string $nombre, ?string $clave, MetodoVinculoReportes $metodo): VinculoReportes
    {
        $personaId = $this->directorio->personaPorEmpleado()[$empleadoId] ?? null;
        $ids = $personaId === null ? [$empleadoId] : ($this->directorio->idsPorPersona()[$personaId] ?? [$empleadoId]);

        return $this->vincularPersona($usuario, $colaborador, [
            'id' => $empleadoId,
            'ids' => $ids,
            'nombre' => $nombre,
            'variantes' => [],
            'claves' => $clave === null ? [] : [$clave],
            'puesto' => null,
            'es_persona' => true,
            'motivo' => null,
            'sucursal_codigo' => null,
            'sucursal' => null,
            'rutas' => [],
        ], $metodo);
    }

    /**
     * Uno a uno entre colaborador y PERSONA: cierra los vínculos de otros
     * colaboradores con cualquier employee id de la persona y los de este
     * colaborador con otras personas.
     *
     * @param  Persona  $persona
     */
    public function vincularPersona(User $usuario, Colaborador $colaborador, array $persona, MetodoVinculoReportes $metodo): VinculoReportes
    {
        return DB::transaction(function () use ($usuario, $colaborador, $persona, $metodo): VinculoReportes {
            $deLaPersona = VinculoReportes::query()->activos()->deTipo(TipoVinculoReportes::Colaborador)
                ->whereIn('reportes_id', $persona['ids'])
                ->lockForUpdate()
                ->get();
            $propio = null;

            foreach ($deLaPersona as $vinculo) {
                if ($vinculo->colaborador_id === $colaborador->id) {
                    $propio ??= $vinculo;

                    continue;
                }

                $this->desactivar($vinculo, $usuario, sprintf('Reasignado a %s.', $colaborador->nombreCompleto()));
            }

            VinculoReportes::query()->activos()->deTipo(TipoVinculoReportes::Colaborador)
                ->where('colaborador_id', $colaborador->id)
                ->whereNotIn('reportes_id', $persona['ids'])
                ->lockForUpdate()
                ->get()
                ->each(fn (VinculoReportes $v) => $this->desactivar($v, $usuario, sprintf('Se vinculó a %s de Reportes.', $persona['nombre'])));

            if ($propio !== null) {
                return $propio;
            }

            return VinculoReportes::query()->create([
                'tipo' => TipoVinculoReportes::Colaborador->value,
                'colaborador_id' => $colaborador->id,
                'reportes_id' => $persona['id'],
                'reportes_clave' => $persona['claves'] === [] ? null : mb_substr(implode(', ', $persona['claves']), 0, 100),
                'reportes_nombre' => mb_substr($persona['nombre'] !== '' ? $persona['nombre'] : sprintf('Persona %d', $persona['id']), 0, 200),
                'metodo' => $metodo->value,
                'vinculado_por' => $usuario->id,
            ]);
        });
    }

    /**
     * Desvincular: en un gestor, el vínculo con TODA su persona; en una
     * sucursal, todas las rutas de esa sucursal de Reportes que apuntan a la
     * misma sucursal de PEOPLE.
     */
    public function desvincular(User $usuario, VinculoReportes $vinculo, ?string $motivo): void
    {
        if (! $vinculo->activo) {
            throw ValidationException::withMessages(['vinculo' => 'Este vínculo ya estaba desactivado.']);
        }

        $motivo = $motivo !== null && trim($motivo) !== '' ? trim($motivo) : 'Desvinculado a mano.';
        $relacionados = [$vinculo->reportes_id];

        if ($vinculo->tipo === TipoVinculoReportes::Colaborador) {
            $personaId = $this->directorio->personaPorEmpleado()[$vinculo->reportes_id] ?? null;
            $relacionados = $personaId === null ? $relacionados : ($this->directorio->idsPorPersona()[$personaId] ?? $relacionados);
        } else {
            $sucursalDeRuta = $this->directorio->sucursalDeRuta();
            $codigo = $sucursalDeRuta[$vinculo->reportes_id] ?? null;

            if ($codigo !== null) {
                $relacionados = array_keys(array_filter($sucursalDeRuta, fn (string $c): bool => $c === $codigo));
            }
        }

        DB::transaction(function () use ($usuario, $vinculo, $motivo, $relacionados): void {
            VinculoReportes::query()->activos()->deTipo($vinculo->tipo)
                ->whereIn('reportes_id', $relacionados)
                ->when($vinculo->tipo === TipoVinculoReportes::Colaborador, fn ($q) => $q->where('colaborador_id', $vinculo->colaborador_id))
                ->when($vinculo->tipo === TipoVinculoReportes::Sucursal, fn ($q) => $q->where('sucursal_id', $vinculo->sucursal_id))
                ->lockForUpdate()
                ->get()
                ->each(fn (VinculoReportes $v) => $this->desactivar($v, $usuario, $motivo));
        });
    }

    /**
     * Opciones del buscador de colaboradores (se piden al abrirlo y al
     * escribir): primero los de la sucursal indicada, luego el resto.
     *
     * @return list<array{value: int, label: string, descripcion: string|null}>
     */
    public function buscarColaboradores(string $termino, ?int $sucursalId, int $limite = 60): array
    {
        $palabras = array_values(array_filter(explode(' ', DatoReportes::nombreComparable($termino)), fn (string $p): bool => $p !== ''));

        $consulta = Colaborador::query()
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])
            ->select(['id', 'name', 'apellidos', 'puesto_id', 'sucursal_principal_id', 'estatus']);

        foreach ($palabras as $palabra) {
            $consulta->where(fn ($q) => $q->where('name', 'like', sprintf('%%%s%%', $palabra))->orWhere('apellidos', 'like', sprintf('%%%s%%', $palabra)));
        }

        if ($sucursalId !== null) {
            $consulta->orderByRaw('CASE WHEN sucursal_principal_id = ? THEN 0 ELSE 1 END', [$sucursalId]);
        }

        return array_values($consulta->orderBy('name')->orderBy('apellidos')->limit($limite)->get()
            ->map(fn (Colaborador $c): array => [
                'value' => $c->id,
                'label' => $c->nombreCompleto(),
                'descripcion' => $this->descripcionColaborador($c),
            ])->all());
    }

    /**
     * Datos de la pantalla: personas (con su sucursal y rutas) y sucursales
     * de Reportes (con sus rutas), cada una con su vínculo o sugerencia.
     *
     * @param  list<int>  $empleadosConDatos
     * @param  list<int>  $rutasConDatos
     * @return array{personas: list<array<string, mixed>>, no_personas: list<array<string, mixed>>, sucursales: list<array<string, mixed>>, opciones_sucursales: list<array{value: int, label: string}>, totales: array<string, int>}
     *
     * @throws ReportesNoDisponibleException
     */
    public function pantalla(array $empleadosConDatos = [], array $rutasConDatos = []): array
    {
        $directorio = $this->directorio->directorio();
        $empleadosConDatos = array_flip($empleadosConDatos);
        $rutasConDatos = array_flip($rutasConDatos);
        $sucursales = Sucursal::query()->orderBy('nombre')->get(['id', 'nombre']);
        $nombresSucursal = $sucursales->pluck('nombre', 'id');

        // ── Sucursales de Reportes (todas sus rutas) ↔ sucursal de PEOPLE.
        $vinculosRuta = VinculoReportes::query()->activos()->deTipo(TipoVinculoReportes::Sucursal)
            ->with('vinculadoPor:id,name')->get()->keyBy('reportes_id');
        $sucursalPorNombre = $this->indicePorNombre($sucursales->map(fn (Sucursal $s): array => ['id' => $s->id, 'nombre' => $s->nombre]));

        $filasSucursales = [];
        $peoplePorCodigo = [];

        foreach ($this->sucursalesConRutas($directorio['sucursales']) as $grupo) {
            $rutas = [];
            $destinos = [];
            $ultimo = null;

            foreach ($grupo['rutas'] as $ruta) {
                $vinculo = $vinculosRuta->get($ruta['id']);

                if ($vinculo !== null && $vinculo->sucursal_id !== null) {
                    $destinos[(int) $vinculo->sucursal_id] = true;
                    $ultimo = $ultimo === null || $vinculo->id > $ultimo->id ? $vinculo : $ultimo;
                }

                $rutas[] = [
                    'id' => $ruta['id'],
                    'nombre' => $ruta['nombre'],
                    'sucursal_id' => $vinculo?->sucursal_id,
                    'con_datos' => isset($rutasConDatos[$ruta['id']]),
                ];
            }

            $vinculadas = count(array_filter($rutas, fn (array $r): bool => $r['sucursal_id'] !== null));
            $destino = count($destinos) === 1 ? (int) array_key_first($destinos) : null;
            $sugerida = $destino === null && $destinos === [] ? $this->sugerirSucursal($grupo['nombre'], $sucursalPorNombre) : null;

            if ($destino !== null) {
                $peoplePorCodigo[$grupo['codigo']] = $destino;
            }

            $filasSucursales[] = [
                'codigo' => $grupo['codigo'],
                'nombre' => $grupo['nombre'],
                'sin_sucursal_en_reportes' => $grupo['suelta'],
                'rutas' => $rutas,
                'con_datos' => array_filter($rutas, fn (array $r): bool => $r['con_datos']) !== [],
                'vinculo' => $destino === null || $ultimo === null ? null : [
                    'id' => $ultimo->id,
                    'sucursal_id' => $destino,
                    'sucursal' => (string) ($nombresSucursal[$destino] ?? ''),
                    'rutas_vinculadas' => $vinculadas,
                    'metodo' => $ultimo->metodo->etiqueta(),
                    'vinculado_por' => $ultimo->vinculadoPor?->name,
                    'vinculado_en' => $ultimo->created_at?->toIso8601String(),
                ],
                // Rutas repartidas en varias sucursales de PEOPLE: se resuelve a mano.
                'mixta' => count($destinos) > 1,
                // Ruta nueva de una sucursal ya vinculada: se sugiere completarla.
                'sugerencia' => $sugerida !== null
                    ? ['sucursal_id' => $sugerida, 'sucursal' => (string) ($nombresSucursal[$sugerida] ?? '')]
                    : ($destino !== null && $vinculadas < count($rutas) ? ['sucursal_id' => $destino, 'sucursal' => (string) ($nombresSucursal[$destino] ?? '')] : null),
            ];
        }

        // Sucursal de PEOPLE de cada sucursal de Reportes (vinculada o sugerida), para sugerir personas.
        $peopleSugeridaPorCodigo = $peoplePorCodigo;

        foreach ($filasSucursales as $fila) {
            $sugeridaId = DatoReportes::entero(DatoReportes::ruta($fila, 'sugerencia.sucursal_id'));

            if ($sugeridaId !== null) {
                $peopleSugeridaPorCodigo[$fila['codigo']] ??= $sugeridaId;
            }
        }

        // ── Personas de Reportes ↔ colaborador de PEOPLE.
        $colaboradores = Colaborador::query()
            ->orderBy('name')
            ->get(['id', 'name', 'apellidos', 'sucursal_principal_id', 'estatus']);
        $porId = $colaboradores->keyBy('id');
        $colaboradorPorFirma = [];

        foreach ($colaboradores as $c) {
            $colaboradorPorFirma[DatoReportes::firmaNombre($c->nombreCompleto())][] = $c->id;
        }

        $vinculosPersona = VinculoReportes::query()->activos()->deTipo(TipoVinculoReportes::Colaborador)
            ->with(['colaborador:id,name,apellidos', 'vinculadoPor:id,name'])->orderByDesc('id')->get();
        $porEmpleado = $vinculosPersona->groupBy('reportes_id');
        $colaboradoresVinculados = $vinculosPersona->pluck('colaborador_id')->filter()->map(fn ($id): int => (int) $id)->flip();

        $personas = array_values(array_filter($directorio['personas'], fn (array $p): bool => $p['es_persona']));
        $firmasReportes = array_count_values(array_map(fn (array $p): string => DatoReportes::firmaNombre($p['nombre']), $personas));

        $filasPersonas = [];

        foreach ($personas as $persona) {
            $suyos = collect($persona['ids'])->flatMap(fn (int $id) => $porEmpleado->get($id) ?? collect())->sortByDesc('id')->values();
            $vinculo = $suyos->first();
            $sucursalPeople = $persona['sucursal_codigo'] === null ? null : ($peopleSugeridaPorCodigo[$persona['sucursal_codigo']] ?? null);
            [$sugerido, $motivo, $homonimos] = $vinculo === null
                ? $this->sugerirColaborador($persona, $colaboradorPorFirma[DatoReportes::firmaNombre($persona['nombre'])] ?? [], $firmasReportes, $porId, $colaboradoresVinculados, $sucursalPeople)
                : [null, null, false];

            $filasPersonas[] = [
                'reportes_id' => $persona['id'],
                'registros' => count($persona['ids']),
                'nombre' => $persona['nombre'],
                'variantes' => array_values(array_filter($persona['variantes'], fn (string $v): bool => $v !== $persona['nombre'])),
                'claves' => $persona['claves'],
                'puesto' => $persona['puesto'],
                'sucursal' => $persona['sucursal'],
                'sucursal_people' => $sucursalPeople === null ? null : (string) ($nombresSucursal[$sucursalPeople] ?? ''),
                'rutas' => array_column($persona['rutas'], 'nombre'),
                'con_datos' => array_filter($persona['ids'], fn (int $id): bool => isset($empleadosConDatos[$id])) !== [],
                'vinculo' => $vinculo === null ? null : [
                    'id' => $vinculo->id,
                    'colaborador_id' => $vinculo->colaborador_id,
                    'colaborador' => $vinculo->colaborador?->nombreCompleto(),
                    'metodo' => $vinculo->metodo->etiqueta(),
                    'vinculado_por' => $vinculo->vinculadoPor?->name,
                    'vinculado_en' => $vinculo->created_at?->toIso8601String(),
                ],
                // Vínculos de antes (por employee id) que apuntan a colaboradores distintos.
                'conflicto' => $suyos->pluck('colaborador_id')->unique()->count() > 1,
                'sugerencia' => $sugerido === null ? null : [
                    'colaborador_id' => $sugerido,
                    'colaborador' => $porId->get($sugerido)?->nombreCompleto(),
                    'motivo' => $motivo,
                ],
                'homonimos' => $homonimos,
            ];
        }

        $noPersonas = [];

        foreach ($directorio['personas'] as $persona) {
            if (! $persona['es_persona']) {
                $noPersonas[] = [
                    'reportes_id' => $persona['id'],
                    'nombre' => $persona['nombre'],
                    'motivo' => self::MOTIVOS_NO_PERSONA[$persona['motivo'] ?? ''] ?? 'No es persona',
                    'sucursal' => $persona['sucursal'],
                ];
            }
        }

        $sinVincularPrimero = fn (array $a, array $b): int => [($a['vinculo'] !== null), ($a['sugerencia'] === null), ! $a['con_datos'], $a['nombre']]
            <=> [($b['vinculo'] !== null), ($b['sugerencia'] === null), ! $b['con_datos'], $b['nombre']];
        usort($filasPersonas, $sinVincularPrimero);
        usort($filasSucursales, $sinVincularPrimero);

        return [
            'personas' => $filasPersonas,
            'no_personas' => $noPersonas,
            'sucursales' => $filasSucursales,
            'opciones_sucursales' => array_values($sucursales->map(fn (Sucursal $s): array => ['value' => $s->id, 'label' => $s->nombre])->all()),
            'totales' => [
                'personas' => count($filasPersonas),
                'personas_vinculadas' => count(array_filter($filasPersonas, fn (array $f): bool => $f['vinculo'] !== null)),
                'sucursales' => count(array_filter($filasSucursales, fn (array $f): bool => ! $f['sin_sucursal_en_reportes'])),
                'sucursales_vinculadas' => count(array_filter($filasSucursales, fn (array $f): bool => ! $f['sin_sucursal_en_reportes'] && $f['vinculo'] !== null)),
                'rutas' => array_sum(array_map(fn (array $f): int => count($f['rutas']), $filasSucursales)),
                'sugerencias_personas' => count(array_filter($filasPersonas, fn (array $f): bool => $f['sugerencia'] !== null)),
                'sugerencias_sucursales' => count(array_filter($filasSucursales, fn (array $f): bool => $f['sugerencia'] !== null)),
                'no_personas' => count($noPersonas),
            ],
        ];
    }

    /**
     * Sugerencia de colaborador para una persona: mismas palabras del nombre
     * (sin acentos ni orden), libre, y ÚNICA en ambos lados; si en PEOPLE hay
     * homónimos, se desempata por la sucursal. Activos antes que bajas.
     *
     * @param  Persona  $persona
     * @param  list<int>  $candidatos
     * @param  array<string, int>  $firmasReportes
     * @param  Collection<int, Colaborador>  $porId
     * @param  Collection<int, int>  $vinculados
     * @return array{0: int|null, 1: string|null, 2: bool}
     */
    private function sugerirColaborador(array $persona, array $candidatos, array $firmasReportes, Collection $porId, Collection $vinculados, ?int $sucursalPeople): array
    {
        $libres = array_values(array_filter($candidatos, fn (int $id): bool => ! $vinculados->has($id)));
        $homonimosReportes = ($firmasReportes[DatoReportes::firmaNombre($persona['nombre'])] ?? 0) > 1;

        if ($libres === []) {
            return [null, null, $homonimosReportes || count($candidatos) > 1];
        }

        $activos = array_values(array_filter($libres, fn (int $id): bool => $porId->get($id)?->estatus === EstadoUsuario::Activo));
        $libres = $activos !== [] ? $activos : $libres;

        if (! $homonimosReportes && count($libres) === 1) {
            return [$libres[0], 'Mismo nombre', false];
        }

        if ($sucursalPeople !== null) {
            $deLaSucursal = array_values(array_filter($libres, fn (int $id): bool => (int) $porId->get($id)?->sucursal_principal_id === $sucursalPeople));

            if (count($deLaSucursal) === 1) {
                return [$deLaSucursal[0], 'Mismo nombre y sucursal', true];
            }
        }

        return [null, null, true];
    }

    /**
     * Sucursales de Reportes con sus rutas; las rutas sin sucursal en
     * Reportes se ofrecen cada una por separado (código RUTA_{id}).
     *
     * @param  list<array{codigo: string|null, nombre: string, rutas: list<array{id: int, nombre: string}>}>|null  $sucursales
     * @return list<array{codigo: string, nombre: string, suelta: bool, rutas: list<array{id: int, nombre: string}>}>
     *
     * @throws ReportesNoDisponibleException
     */
    private function sucursalesConRutas(?array $sucursales = null): array
    {
        $resultado = [];

        foreach ($sucursales ?? $this->directorio->directorio()['sucursales'] as $sucursal) {
            if ($sucursal['codigo'] !== null) {
                $resultado[] = ['codigo' => $sucursal['codigo'], 'nombre' => $sucursal['nombre'], 'suelta' => false, 'rutas' => $sucursal['rutas']];

                continue;
            }

            foreach ($sucursal['rutas'] as $ruta) {
                $resultado[] = ['codigo' => sprintf('RUTA_%d', $ruta['id']), 'nombre' => $ruta['nombre'], 'suelta' => true, 'rutas' => [$ruta]];
            }
        }

        return $resultado;
    }

    /**
     * @param  array<int, int>  $mapaRutas  ruta => sucursal de PEOPLE
     * @param  array<int, string>  $sucursalDeRuta  ruta => código de sucursal de Reportes
     * @return array<string, int> código => sucursal de PEOPLE (solo si es una sola)
     */
    private function sucursalPeoplePorCodigo(array $mapaRutas, array $sucursalDeRuta): array
    {
        $destinos = [];

        foreach ($mapaRutas as $rutaId => $sucursalId) {
            $codigo = $sucursalDeRuta[$rutaId] ?? null;

            if ($codigo !== null) {
                $destinos[$codigo][$sucursalId] = true;
            }
        }

        $resultado = [];

        foreach ($destinos as $codigo => $ids) {
            if (count($ids) === 1) {
                $resultado[$codigo] = (int) array_key_first($ids);
            }
        }

        return $resultado;
    }

    /**
     * @return Persona|null
     *
     * @throws ReportesNoDisponibleException
     */
    private function personaDe(int $empleadoId): ?array
    {
        foreach ($this->directorio->directorio()['personas'] as $persona) {
            if ($persona['id'] === $empleadoId || in_array($empleadoId, $persona['ids'], true)) {
                return $persona;
            }
        }

        return null;
    }

    private function descripcionColaborador(Colaborador $colaborador): ?string
    {
        $partes = array_values(array_filter([$colaborador->puesto?->nombre, $colaborador->sucursalPrincipal?->nombre], fn (?string $p): bool => $p !== null && $p !== ''));

        return $partes === [] ? null : implode(' · ', $partes);
    }

    private function desactivar(VinculoReportes $vinculo, User $usuario, string $motivo): void
    {
        $vinculo->forceFill([
            'activo' => false,
            'desactivado_por' => $usuario->id,
            'desactivado_en' => now(),
            'motivo_desactivacion' => mb_substr($motivo, 0, 255),
        ])->save();
    }

    /**
     * Sugerencia de sucursal de PEOPLE para una sucursal de Reportes: nombre
     * idéntico (sin acentos) o que empiece con el de la sucursal de PEOPLE.
     *
     * @param  array<string, int>  $sucursalPorNombre
     */
    private function sugerirSucursal(string $nombre, array $sucursalPorNombre): ?int
    {
        $comparable = DatoReportes::nombreComparable($nombre);

        if (isset($sucursalPorNombre[$comparable])) {
            return $sucursalPorNombre[$comparable];
        }

        $coincidencias = array_values(array_filter(
            $sucursalPorNombre,
            fn (string $nombreSucursal): bool => $nombreSucursal !== '' && str_starts_with($comparable, sprintf('%s ', $nombreSucursal)),
            ARRAY_FILTER_USE_KEY,
        ));

        return count($coincidencias) === 1 ? $coincidencias[0] : null;
    }

    /**
     * @param  Collection<int, array{id: int, nombre: string}>  $filas
     * @return array<string, int>
     */
    private function indicePorNombre(Collection $filas): array
    {
        $indice = [];

        foreach ($filas as $fila) {
            $indice[DatoReportes::nombreComparable($fila['nombre'])] = $fila['id'];
        }

        return $indice;
    }
}
