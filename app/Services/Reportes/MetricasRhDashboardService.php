<?php

namespace App\Services\Reportes;

use App\Enums\EstadoAltaDigital;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstadoUsuario;
use App\Enums\Genero;
use App\Enums\TipoMovimientoLaboral;
use App\Models\AltaDigital;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\EmployeeDocument;
use App\Models\MovimientoLaboral;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Models\Vacante;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Cumpleanos\CumpleanosService;
use App\Services\Expedientes\ExpedienteService;
use App\Services\Headcount\HeadcountService;
use App\Services\MatrizComercial\MatrizComercialService;
use App\Services\Vacaciones\VacacionesService;
use App\Services\Vacantes\VacantesListadoService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Metricas del dashboard RH (reemplaza al dashboard de cumplimiento de
 * capacitacion en App\Services\Reportes\MetricasDashboardService, que se
 * deja intacta y sin usar: capacitacion no se elimino, ver
 * docs/CAPACITACION_PROXIMAMENTE.md). Los tres metodos publicos devuelven
 * exactamente lo que consumen Dashboard/Global.vue, Dashboard/Sucursal.vue y
 * Dashboard/Colaborador.vue.
 *
 * Altas/vacaciones/solicitudes todavia no tienen tablas propias (llegan en
 * checkpoints siguientes, ver docs/PORTAL_RH.md): sus tarjetas se devuelven
 * con `disponible: false` en vez de inventar un numero, para que el
 * frontend muestre "Próximamente" en lugar de una cifra falsa.
 */
class MetricasRhDashboardService
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly ExpedienteService $expediente,
        private readonly HeadcountService $headcount,
        private readonly MatrizComercialService $matriz,
        private readonly CumpleanosService $cumpleanos,
        private readonly VacacionesService $vacaciones,
        private readonly VacantesListadoService $vacantesListado,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function global(User $usuario): array
    {
        return $this->paraAlcance($usuario);
    }

    /**
     * @return array<string, mixed>
     */
    public function sucursal(User $usuario): array
    {
        return $this->paraAlcance($usuario);
    }

    /**
     * @return array<string, mixed>
     */
    private function paraAlcance(User $usuario): array
    {
        $colaboradoresVisibles = $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query(), $usuario)
            ->with(['sucursalPrincipal:id,nombre,empresa_id', 'sucursalPrincipal.empresa:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre'])
            ->get();

        $idsVisibles = $colaboradoresVisibles->pluck('id');
        // Mismo alcance como subconsulta: evita mandar miles de ids en un
        // IN (...) a las consultas de documentos.
        $subconsultaVisibles = $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query(), $usuario)->select('colaboradores.id');

        // Solo se necesitan conteos por estado: agregado en SQL en vez de
        // hidratar todos los documentos de la plantilla.
        $documentosPorEstado = $this->conteoDocumentosPorEstado($subconsultaVisibles);

        [$expedientesCompletos, $expedientesIncompletos] = $this->contarExpedientes($colaboradoresVisibles);

        // Única fuente de "vacantes disponibles" (CLAUDE.md §13): la misma
        // consulta que alimenta el módulo de Vacantes — nunca cuenta una
        // automática cuya plaza ya no falta ni suma su columna guardada.
        $vacantesAbiertas = $this->vacantesListado->consulta($usuario)->get();
        $plazasVacantesAbiertas = array_sum(array_column($this->vacantesListado->filas($vacantesAbiertas), 'plazas_disponibles'));

        $candidatosActivos = $this->alcance->limitarPorSucursal(
            Candidato::query()->whereNotIn('estado', $this->estadosCandidatoTerminales()),
            $usuario,
        );

        $matrizResumen = $this->matriz->resumen();

        return [
            'cards' => [
                'colaboradores_activos' => $colaboradoresVisibles->where('estatus', EstadoUsuario::Activo)->count(),
                'altas_en_proceso' => $this->altasEnProceso($usuario),
                'bajas_del_mes' => $this->bajasDelMes($subconsultaVisibles),
                'expedientes_completos' => $expedientesCompletos,
                'expedientes_incompletos' => $expedientesIncompletos,
                'documentos_pendientes' => array_sum(array_map(fn (EstadoDocumento $e): int => $documentosPorEstado[$e->value] ?? 0, $this->estadosPendientes())),
                'solicitudes_pendientes' => $this->solicitudesPendientes($usuario),
                'vacaciones_pendientes' => $this->vacacionesPendientes($usuario),
                'vacantes_disponibles' => (int) $plazasVacantesAbiertas,
                'plazas_automaticas' => $vacantesAbiertas->where('generada_automaticamente', true)->count(),
                'candidatos_activos' => (clone $candidatosActivos)->count(),
                'rutas_cubiertas' => $matrizResumen['cubiertas'],
                'rutas_sin_cubrir' => $matrizResumen['sin_cubrir'],
                'cumpleanos_proximos' => $this->cumpleanos->proximosCumpleanos($usuario, 7)->count(),
            ],
            'graficas' => [
                'colaboradoresPorEmpresa' => $this->agruparPor($colaboradoresVisibles, function (Colaborador $u) {
                    $sucursal = $u->sucursalPrincipal;

                    return $sucursal === null || $sucursal->empresa === null ? 'Sin empresa' : $sucursal->empresa->nombre;
                }),
                'colaboradoresPorSucursal' => $this->agruparPor($colaboradoresVisibles, fn (Colaborador $u) => $u->sucursalPrincipal === null ? 'Sin sucursal' : $u->sucursalPrincipal->nombre),
                'colaboradoresPorDepartamento' => $this->agruparPor($colaboradoresVisibles, fn (Colaborador $u) => $u->departamento === null ? 'Sin departamento' : $u->departamento->nombre),
                'colaboradoresPorPuesto' => $this->agruparPor($colaboradoresVisibles, fn (Colaborador $u) => $u->puesto === null ? 'Sin puesto' : $u->puesto->nombre),
                'expedientesEstado' => [
                    ['clave' => 'completos', 'etiqueta' => 'Completos', 'valor' => $expedientesCompletos],
                    ['clave' => 'incompletos', 'etiqueta' => 'Incompletos', 'valor' => $expedientesIncompletos],
                ],
                'documentosPorEstado' => collect(EstadoDocumento::cases())
                    ->map(fn (EstadoDocumento $estado) => [
                        'clave' => $estado->value,
                        'etiqueta' => $estado->etiqueta(),
                        'valor' => $documentosPorEstado[$estado->value] ?? 0,
                    ])
                    ->filter(fn (array $fila) => $fila['valor'] > 0)
                    ->values(),
                'vacantesPorPuesto' => $this->vacantesPorPuesto($vacantesAbiertas),
                'solicitudesPorEstado' => $this->solicitudesPorEstado($usuario),
                'candidatosPorEtapa' => $this->agruparPor(
                    (clone $candidatosActivos)->get(['id', 'estado']),
                    fn (Candidato $c) => $c->estado->etiqueta(),
                ),
                'coberturaRutas' => [
                    ['clave' => 'cubiertas', 'etiqueta' => 'Cubiertas', 'valor' => $matrizResumen['cubiertas']],
                    ['clave' => 'sin_cubrir', 'etiqueta' => 'Sin cubrir', 'valor' => $matrizResumen['sin_cubrir']],
                ],
            ],
            'proximosAniversarios' => $this->proximosAniversarios($colaboradoresVisibles),
            'documentosPendientesRevision' => $this->documentosPendientesRevision($subconsultaVisibles),
            'alertas' => $this->alertas($expedientesIncompletos, $documentosPorEstado),
        ];
    }

    /**
     * @return array<int, EstadoCandidato>
     */
    private function estadosCandidatoTerminales(): array
    {
        return [EstadoCandidato::Contratado, EstadoCandidato::NoSeleccionado, EstadoCandidato::NoViable, EstadoCandidato::NoRespondio, EstadoCandidato::Desistio];
    }

    private function altasEnProceso(User $usuario): int
    {
        return $this->alcance->limitarPorSucursal(
            AltaDigital::query()->whereNotIn('estado', [
                EstadoAltaDigital::ConvertidaAColaborador->value,
                EstadoAltaDigital::Rechazada->value,
                EstadoAltaDigital::Cancelada->value,
            ]),
            $usuario,
        )->count();
    }

    private function solicitudesPendientes(User $usuario): int
    {
        return $this->alcance->limitarPorSucursal(
            SolicitudInterna::query()->whereIn('estado', [
                EstadoSolicitudInterna::Enviada->value,
                EstadoSolicitudInterna::EnRevision->value,
                EstadoSolicitudInterna::RequiereCorreccion->value,
            ]),
            $usuario,
        )->count();
    }

    private function vacacionesPendientes(User $usuario): int
    {
        return $this->alcance->limitarPorSucursal(
            SolicitudInterna::query()
                ->where('tipo', 'vacaciones')
                ->whereIn('estado', [
                    EstadoSolicitudInterna::Enviada->value,
                    EstadoSolicitudInterna::EnRevision->value,
                    EstadoSolicitudInterna::RequiereCorreccion->value,
                ]),
            $usuario,
        )->count();
    }

    /**
     * Array plano por el mismo motivo que proximosAniversarios(): count()
     * infiere int<0, max>, que no es covariante con Collection<..., int>.
     *
     * @return array<int, array{clave: string, etiqueta: string, valor: int}>
     */
    private function solicitudesPorEstado(User $usuario): array
    {
        $totales = $this->alcance->limitarPorSucursal(SolicitudInterna::query(), $usuario)
            ->toBase()
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $resultado = [];

        foreach (EstadoSolicitudInterna::cases() as $estado) {
            $total = (int) ($totales[$estado->value] ?? 0);

            if ($total > 0) {
                $resultado[] = ['clave' => $estado->value, 'etiqueta' => $estado->etiqueta(), 'valor' => $total];
            }
        }

        return $resultado;
    }

    /**
     * @param  EloquentCollection<int, Vacante>  $vacantes
     * @return Collection<int, array{etiqueta: string, valor: int}>
     */
    private function vacantesPorPuesto(EloquentCollection $vacantes): Collection
    {
        $vacantes->loadMissing('puesto:id,nombre');

        return $this->agruparPor($vacantes, fn (Vacante $v) => $v->puesto->nombre ?? 'Sin puesto');
    }

    /**
     * @return array{miExpediente: array{porcentaje: float, pendientes: int}, misDocumentosPendientes: array<int, array{id: int, colaborador: string|null, tipo: string, status: string, creado_en: string|null}>, avisosPendientes: array{disponible: bool}, misVacaciones: array{dias_disponibles: int}, misSolicitudes: array{pendientes: int}}
     */
    public function colaborador(User $usuario): array
    {
        $colaborador = $usuario->colaborador;
        $resumen = $colaborador !== null
            ? $this->expediente->resumenCompletitud($colaborador)
            : ['porcentaje' => 0.0, 'requeridos_total' => 0, 'requeridos_aprobados' => 0, 'pendientes' => 0, 'rechazados' => 0];

        return [
            'miExpediente' => [
                'porcentaje' => $resumen['porcentaje'],
                'pendientes' => $resumen['pendientes'] + $resumen['rechazados'],
            ],
            'misDocumentosPendientes' => $this->documentosPendientesRevision(collect([$usuario->colaborador_id]), soloPropios: true),
            'misVacaciones' => ['dias_disponibles' => $this->vacaciones->saldo($usuario)['dias_disponibles']],
            'misSolicitudes' => [
                'pendientes' => SolicitudInterna::query()
                    ->where('user_id', $usuario->id)
                    ->whereIn('estado', [
                        EstadoSolicitudInterna::Enviada->value,
                        EstadoSolicitudInterna::EnRevision->value,
                        EstadoSolicitudInterna::RequiereCorreccion->value,
                    ])
                    ->count(),
            ],
            'avisosPendientes' => ['disponible' => false],
        ];
    }

    /**
     * KPIs de rotación de personal para el dashboard RH: altas, bajas,
     * plantilla, % de rotación, composición por género, eficiencia de
     * headcount y tendencia mensual — filtrable por sucursal y rango de
     * fechas (en vivo, sin recargar la página, ver
     * App\Http\Controllers\DashboardController::rotacion()).
     *
     * % de rotación = bajas del periodo / plantilla actual × 100 (misma
     * fórmula que el índice de rotación histórico de dirección, ver
     * claude/rotacion/).
     *
     * @param  array{sucursal_id?: int|string|null, departamento_id?: int|string|null, desde?: string|null, hasta?: string|null}  $filtros
     * @return array<string, mixed>
     */
    public function rotacion(User $usuario, array $filtros = []): array
    {
        $hasta = ! empty($filtros['hasta']) ? Carbon::parse($filtros['hasta'])->endOfDay() : now()->endOfDay();
        // Por defecto, 90 días rodantes (no "lo que va del mes"): el día 1
        // o 2 de cada mes esa ventana casi no tiene datos y el dashboard se
        // ve vacío sin que nada esté roto. RH puede acotar el rango con los
        // filtros si quiere ver solo el mes en curso.
        $desde = ! empty($filtros['desde']) ? Carbon::parse($filtros['desde'])->startOfDay() : $hasta->copy()->subDays(90)->startOfDay();
        $sucursalId = ! empty($filtros['sucursal_id']) ? (int) $filtros['sucursal_id'] : null;
        $departamentoId = ! empty($filtros['departamento_id']) ? (int) $filtros['departamento_id'] : null;

        $sucursalesVisiblesIds = $this->alcance->tieneAlcanceGlobal($usuario) ? null : $this->alcance->sucursalesVisiblesIds($usuario);

        $colaboradoresQuery = $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query(), $usuario)
            ->when($sucursalId !== null, fn ($q) => $q->where('sucursal_principal_id', $sucursalId))
            ->when($departamentoId !== null, fn ($q) => $q->where('departamento_id', $departamentoId));

        $plantillaActual = (clone $colaboradoresQuery)->where('estatus', EstadoUsuario::Activo)->count();

        // Conteo agrupado en SQL (antes se hidrataba toda la plantilla).
        $totalesPorDepartamento = (clone $colaboradoresQuery)
            ->where('estatus', EstadoUsuario::Activo)
            ->toBase()
            ->selectRaw('departamento_id, count(*) as total')
            ->groupBy('departamento_id')
            ->pluck('total', 'departamento_id');
        $nombresDepartamento = Departamento::query()
            ->whereIn('id', $totalesPorDepartamento->keys()->filter())
            ->pluck('nombre', 'id');

        $porDepartamento = $totalesPorDepartamento
            ->map(fn ($total, $departamentoId) => [
                'etiqueta' => sprintf('%s', $nombresDepartamento[$departamentoId] ?? 'Sin departamento'),
                'valor' => (int) $total,
            ])
            ->groupBy('etiqueta')
            ->map(fn (Collection $grupo, string $etiqueta) => ['etiqueta' => $etiqueta, 'valor' => (int) $grupo->sum('valor')])
            ->sortByDesc('valor')
            ->values();

        $altas = $this->movimientosEnPeriodo(TipoMovimientoLaboral::Alta, 'sucursal_nueva_id', $desde, $hasta, $sucursalId, $sucursalesVisiblesIds);
        $bajas = $this->movimientosEnPeriodo(TipoMovimientoLaboral::Baja, 'sucursal_anterior_id', $desde, $hasta, $sucursalId, $sucursalesVisiblesIds);

        $genero = (clone $colaboradoresQuery)
            ->where('estatus', EstadoUsuario::Activo)
            ->selectRaw('genero, count(*) as total')
            ->groupBy('genero')
            ->pluck('total', 'genero');

        $eficiencia = $this->headcount->totalesGenerales($sucursalId !== null ? collect([$sucursalId]) : $sucursalesVisiblesIds);

        return [
            'periodo' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()],
            'plantilla_actual' => $plantillaActual,
            'altas' => $altas->count(),
            'bajas' => $bajas->count(),
            'rotacion_porcentaje' => $plantillaActual > 0 ? round(($bajas->count() / $plantillaActual) * 100, 2) : 0.0,
            'eficiencia' => $eficiencia,
            // "Sin especificar" se calcula por resta (plantilla - masculino -
            // femenino) en vez de leer su propia clave del pluck(): un
            // `genero` NULL en la base de datos se agrupa bajo una clave que
            // PHP no representa de forma consistente (null se castea a ''
            // como índice de arreglo), así que sumar varias claves candidatas
            // corre el riesgo de contar el mismo grupo dos veces.
            'genero' => (function () use ($genero, $plantillaActual): array {
                $masculino = (int) ($genero[Genero::Masculino->value] ?? 0);
                $femenino = (int) ($genero[Genero::Femenino->value] ?? 0);

                return [
                    ['etiqueta' => Genero::Masculino->etiqueta(), 'valor' => $masculino],
                    ['etiqueta' => Genero::Femenino->etiqueta(), 'valor' => $femenino],
                    ['etiqueta' => Genero::SinEspecificar->etiqueta(), 'valor' => max($plantillaActual - $masculino - $femenino, 0)],
                ];
            })(),
            'altasPorSucursal' => $this->agruparMovimientosPorSucursal($altas, 'sucursalNueva'),
            'bajasPorSucursal' => $this->agruparMovimientosPorSucursal($bajas, 'sucursalAnterior'),
            'tendenciaMensual' => $this->tendenciaMensual($sucursalId, $sucursalesVisiblesIds),
            'porDepartamento' => $porDepartamento,
        ];
    }

    /**
     * @param  Collection<int, int>|null  $sucursalesVisiblesIds
     * @return Collection<int, MovimientoLaboral>
     */
    private function movimientosEnPeriodo(
        TipoMovimientoLaboral $tipo,
        string $columnaSucursal,
        CarbonInterface $desde,
        CarbonInterface $hasta,
        ?int $sucursalId,
        ?Collection $sucursalesVisiblesIds,
    ): Collection {
        return MovimientoLaboral::query()
            ->where('tipo_movimiento', $tipo->value)
            ->whereBetween('fecha_movimiento', [$desde, $hasta])
            ->when($sucursalId !== null, fn ($q) => $q->where($columnaSucursal, $sucursalId))
            ->when($sucursalesVisiblesIds !== null, fn ($q) => $q->whereIn($columnaSucursal, $sucursalesVisiblesIds))
            ->with('sucursalNueva:id,nombre', 'sucursalAnterior:id,nombre')
            ->get();
    }

    /**
     * @param  Collection<int, MovimientoLaboral>  $movimientos
     * @param  'sucursalNueva'|'sucursalAnterior'  $relacion
     * @return Collection<int, array{etiqueta: string, valor: int}>
     */
    private function agruparMovimientosPorSucursal(Collection $movimientos, string $relacion): Collection
    {
        return $movimientos
            ->groupBy(fn (MovimientoLaboral $m) => $m->{$relacion}->nombre ?? 'Sin sucursal')
            ->map(fn (Collection $grupo, string $etiqueta) => ['etiqueta' => $etiqueta, 'valor' => $grupo->count()])
            ->sortByDesc('valor')
            ->values();
    }

    /**
     * Últimos 6 meses (incluye el actual): altas/bajas por mes, para la
     * gráfica de tendencia. La plantilla histórica exacta no se reconstruye
     * (no hay snapshots mensuales guardados) — solo altas/bajas, que sí son
     * reconstruibles de forma exacta desde `movimientos_laborales`.
     *
     * @param  Collection<int, int>|null  $sucursalesVisiblesIds
     * @return array<int, array{mes: string, altas: int, bajas: int}>
     */
    private function tendenciaMensual(?int $sucursalId, ?Collection $sucursalesVisiblesIds): array
    {
        // Dos consultas (altas y bajas de los 6 meses) agrupadas por mes en
        // PHP, en vez de 12 count() — agrupar por mes en SQL cambia de
        // sintaxis entre MariaDB (producción) y SQLite (pruebas).
        $inicioPeriodo = now()->subMonths(5)->startOfMonth();
        $finPeriodo = now()->endOfMonth();

        $porMes = function (TipoMovimientoLaboral $tipo, string $columnaSucursal) use ($inicioPeriodo, $finPeriodo, $sucursalId, $sucursalesVisiblesIds): array {
            $conteo = [];

            MovimientoLaboral::query()
                ->where('tipo_movimiento', $tipo->value)
                ->whereBetween('fecha_movimiento', [$inicioPeriodo, $finPeriodo])
                ->when($sucursalId !== null, fn ($q) => $q->where($columnaSucursal, $sucursalId))
                ->when($sucursalesVisiblesIds !== null, fn ($q) => $q->whereIn($columnaSucursal, $sucursalesVisiblesIds))
                ->pluck('fecha_movimiento')
                ->each(function ($fecha) use (&$conteo): void {
                    $mes = Carbon::parse($fecha)->format('Y-m');
                    $conteo[$mes] = ($conteo[$mes] ?? 0) + 1;
                });

            return $conteo;
        };

        $altas = $porMes(TipoMovimientoLaboral::Alta, 'sucursal_nueva_id');
        $bajas = $porMes(TipoMovimientoLaboral::Baja, 'sucursal_anterior_id');
        $meses = [];

        for ($i = 5; $i >= 0; $i--) {
            $inicio = now()->subMonths($i)->startOfMonth();
            $clave = $inicio->format('Y-m');

            $meses[] = ['mes' => $inicio->translatedFormat('M Y'), 'altas' => $altas[$clave] ?? 0, 'bajas' => $bajas[$clave] ?? 0];
        }

        return $meses;
    }

    /**
     * @return array<int, EstadoDocumento>
     */
    private function estadosPendientes(): array
    {
        return [EstadoDocumento::Pendiente, EstadoDocumento::EnRevision, EstadoDocumento::RequiereCorreccion];
    }

    /**
     * @param  Collection<int, Colaborador>  $colaboradores
     * @return array{0: int, 1: int}
     */
    private function contarExpedientes(Collection $colaboradores): array
    {
        $completos = 0;
        $incompletos = 0;

        foreach ($this->expediente->resumenesCompletitud($colaboradores->pluck('id')) as $resumen) {
            if ($resumen['requeridos_total'] > 0 && $resumen['porcentaje'] >= 100.0) {
                $completos++;
            } else {
                $incompletos++;
            }
        }

        return [$completos, $incompletos];
    }

    /**
     * Cuenta desde `movimientos_laborales` (tipo=baja), no desde
     * `users.deleted_at`: una baja hecha vía Solicitudes
     * (App\Services\Solicitudes\BajaColaboradorService) NO hace soft-delete
     * del usuario (solo bloquea acceso, el expediente sigue activo), así
     * que contar por `deleted_at` la dejaba fuera. `movimientos_laborales`
     * se registra igual desde ambos caminos (baja administrativa directa y
     * baja vía solicitud) — única fuente de verdad.
     *
     * @param  Collection<int, int>|Builder<Colaborador>  $idsVisibles
     */
    private function bajasDelMes(Collection|Builder $idsVisibles): int
    {
        return MovimientoLaboral::query()
            ->where('tipo_movimiento', TipoMovimientoLaboral::Baja->value)
            ->whereIn('colaborador_id', $idsVisibles)
            ->whereBetween('fecha_movimiento', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();
    }

    /**
     * @template TItem of object
     *
     * @param  Collection<int, TItem>  $colaboradores
     * @param  callable(TItem): string  $clasificador
     * @return Collection<int, array{etiqueta: string, valor: int}>
     */
    private function agruparPor(Collection $colaboradores, callable $clasificador): Collection
    {
        return $colaboradores
            ->groupBy($clasificador)
            ->map(fn (Collection $grupo, string $etiqueta) => ['etiqueta' => $etiqueta, 'valor' => $grupo->count()])
            ->sortByDesc('valor')
            ->values();
    }

    /**
     * Colaboradores cuyo aniversario laboral (mismo dia/mes de fecha_ingreso)
     * cae dentro de los proximos 30 dias, ordenados por cercania.
     *
     * Se devuelve un array plano (no Collection): los generics de Collection
     * no son covariantes en PHPStan, y esta forma exacta (tras el filter que
     * acota "dias" a un rango) no puede declararse de forma estable como
     * Collection<...>. Ver https://phpstan.org/blog/whats-up-with-template-covariant.
     *
     * @param  Collection<int, Colaborador>  $colaboradores
     * @return array<int, array{id: int, nombre: string, fecha: string, dias: int, anios: int}>
     */
    private function proximosAniversarios(Collection $colaboradores): array
    {
        $hoy = now()->startOfDay();

        // Prefiltro barato por "mes-día" (con la plantilla completa, hacer
        // Carbon::parse + diffInDays para cada persona costaba >1.5 s): solo
        // los que caen en la ventana de 31 días (o nacieron un 29/feb) pasan
        // al cálculo exacto de abajo, que no cambia.
        $ventana = [];
        for ($d = 0; $d <= 31; $d++) {
            $ventana[$hoy->copy()->addDays($d)->format('m-d')] = true;
        }
        $ventana['02-29'] = true;

        return $colaboradores
            ->filter(fn (Colaborador $u) => isset($ventana[substr(sprintf('%s', $u->getRawOriginal('fecha_ingreso')), 5, 5)]))
            ->map(function (Colaborador $u) use ($hoy) {
                $proximo = Carbon::parse($u->fecha_ingreso)->year($hoy->year);

                if ($proximo->lt($hoy)) {
                    $proximo = $proximo->addYear();
                }

                return [
                    'id' => $u->id,
                    'nombre' => $u->nombreCompleto(),
                    'fecha' => $proximo->toDateString(),
                    'dias' => (int) $hoy->diffInDays($proximo),
                    'anios' => $proximo->year - Carbon::parse($u->fecha_ingreso)->year,
                ];
            })
            ->filter(fn (array $item) => $item['dias'] <= 30)
            ->sortBy('dias')
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * Array plano por el mismo motivo que proximosAniversarios(): el status
     * del enum vuelve un tipo union literal que no puede declararse de forma
     * estable como Collection<...>.
     *
     * @param  Collection<int, int>|Builder<Colaborador>  $idsVisibles
     * @return array<int, array{id: int, colaborador: string|null, tipo: string, status: string, creado_en: string|null}>
     */
    private function documentosPendientesRevision(Collection|Builder $idsVisibles, bool $soloPropios = false): array
    {
        return EmployeeDocument::query()
            ->whereIn('colaborador_id', $idsVisibles)
            ->whereIn('status', $this->estadosPendientes())
            ->with(['colaborador:id,name,apellidos', 'tipo:id,nombre'])
            ->orderByDesc('created_at')
            ->limit($soloPropios ? 10 : 6)
            ->get()
            ->map(fn (EmployeeDocument $doc) => [
                'id' => $doc->id,
                'colaborador' => $soloPropios ? null : trim(($doc->colaborador->name ?? '').' '.($doc->colaborador->apellidos ?? '')),
                'tipo' => $doc->tipo->nombre ?? '—',
                'status' => (string) $doc->status->value,
                'creado_en' => $doc->created_at?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Número de documentos de expediente por estado (todas las versiones,
     * igual que antes), agregado en SQL.
     *
     * @param  Builder<Colaborador>  $colaboradoresVisibles  subconsulta de ids en alcance
     * @return array<string, int> estado => total
     */
    private function conteoDocumentosPorEstado(Builder $colaboradoresVisibles): array
    {
        $conteo = [];

        $filas = EmployeeDocument::query()
            ->whereIn('colaborador_id', $colaboradoresVisibles)
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->get();

        foreach ($filas as $fila) {
            $conteo[sprintf('%s', $fila->status)] = (int) $fila->total;
        }

        return $conteo;
    }

    /**
     * @param  array<string, int>  $documentosPorEstado
     * @return Collection<int, array{tono: string, mensaje: string}>
     */
    private function alertas(int $expedientesIncompletos, array $documentosPorEstado): Collection
    {
        $alertas = collect();

        if ($expedientesIncompletos > 0) {
            $alertas->push([
                'tono' => 'warning',
                'mensaje' => "{$expedientesIncompletos} expediente(s) incompleto(s) requieren seguimiento.",
            ]);
        }

        $rechazados = $documentosPorEstado[EstadoDocumento::Rechazado->value] ?? 0;

        if ($rechazados > 0) {
            $alertas->push([
                'tono' => 'danger',
                'mensaje' => "{$rechazados} documento(s) rechazado(s) pendientes de que el colaborador vuelva a subirlos.",
            ]);
        }

        $enRevision = $documentosPorEstado[EstadoDocumento::EnRevision->value] ?? 0;

        if ($enRevision > 0) {
            $alertas->push([
                'tono' => 'info',
                'mensaje' => "{$enRevision} documento(s) esperando revisión de RH.",
            ]);
        }

        return $alertas;
    }
}
