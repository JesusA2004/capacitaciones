<?php

namespace App\Services\Reportes;

use App\Enums\EstadoAltaDigital;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstadoUsuario;
use App\Enums\EstadoVacante;
use App\Enums\Genero;
use App\Enums\TipoMovimientoLaboral;
use App\Models\AltaDigital;
use App\Models\Candidato;
use App\Models\Colaborador;
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
use Carbon\CarbonInterface;
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

        $documentos = EmployeeDocument::query()->whereIn('colaborador_id', $idsVisibles)->get(['id', 'colaborador_id', 'status', 'document_type_id', 'created_at']);

        [$expedientesCompletos, $expedientesIncompletos] = $this->contarExpedientes($colaboradoresVisibles);

        $vacantesAbiertas = $this->alcance->limitarPorSucursal(
            Vacante::query()->whereNotIn('estado', [EstadoVacante::Cubierta->value, EstadoVacante::Cancelada->value]),
            $usuario,
        )->get(['id', 'estado', 'generada_automaticamente', 'plazas_disponibles', 'puesto_id']);

        $candidatosActivos = $this->alcance->limitarPorSucursal(
            Candidato::query()->whereNotIn('estado', $this->estadosCandidatoTerminales()),
            $usuario,
        );

        $matrizResumen = $this->matriz->resumen();

        return [
            'cards' => [
                'colaboradores_activos' => $colaboradoresVisibles->where('estatus', EstadoUsuario::Activo)->count(),
                'altas_en_proceso' => $this->altasEnProceso($usuario),
                'bajas_del_mes' => $this->bajasDelMes($idsVisibles),
                'expedientes_completos' => $expedientesCompletos,
                'expedientes_incompletos' => $expedientesIncompletos,
                'documentos_pendientes' => $documentos->whereIn('status', $this->estadosPendientes())->count(),
                'solicitudes_pendientes' => $this->solicitudesPendientes($usuario),
                'vacaciones_pendientes' => $this->vacacionesPendientes($usuario),
                'vacantes_disponibles' => (int) $vacantesAbiertas->sum('plazas_disponibles'),
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
                        'valor' => $documentos->where('status', $estado)->count(),
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
            'documentosPendientesRevision' => $this->documentosPendientesRevision($idsVisibles),
            'alertas' => $this->alertas($expedientesIncompletos, $documentos),
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
        return $this->alcance->limitarPorSucursal(SolicitudInterna::query(), $usuario)
            ->get(['id', 'estado'])
            ->groupBy(fn (SolicitudInterna $s) => $s->estado->value)
            ->map(fn (Collection $grupo, string $clave) => [
                'clave' => $clave,
                'etiqueta' => sprintf('%s', $grupo->first()->estado->etiqueta()),
                'valor' => $grupo->count(),
            ])
            ->values()
            ->all();
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

        $porDepartamento = (clone $colaboradoresQuery)
            ->where('estatus', EstadoUsuario::Activo)
            ->with('departamento:id,nombre')
            ->get()
            ->groupBy(fn (Colaborador $u) => $u->departamento->nombre ?? 'Sin departamento')
            ->map(fn (Collection $grupo, string $etiqueta) => ['etiqueta' => $etiqueta, 'valor' => $grupo->count()])
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
        $meses = [];

        for ($i = 5; $i >= 0; $i--) {
            $inicio = now()->subMonths($i)->startOfMonth();
            $fin = $inicio->copy()->endOfMonth();

            $altas = MovimientoLaboral::query()
                ->where('tipo_movimiento', TipoMovimientoLaboral::Alta->value)
                ->whereBetween('fecha_movimiento', [$inicio, $fin])
                ->when($sucursalId !== null, fn ($q) => $q->where('sucursal_nueva_id', $sucursalId))
                ->when($sucursalesVisiblesIds !== null, fn ($q) => $q->whereIn('sucursal_nueva_id', $sucursalesVisiblesIds))
                ->count();

            $bajas = MovimientoLaboral::query()
                ->where('tipo_movimiento', TipoMovimientoLaboral::Baja->value)
                ->whereBetween('fecha_movimiento', [$inicio, $fin])
                ->when($sucursalId !== null, fn ($q) => $q->where('sucursal_anterior_id', $sucursalId))
                ->when($sucursalesVisiblesIds !== null, fn ($q) => $q->whereIn('sucursal_anterior_id', $sucursalesVisiblesIds))
                ->count();

            $meses[] = ['mes' => $inicio->translatedFormat('M Y'), 'altas' => $altas, 'bajas' => $bajas];
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

        foreach ($colaboradores as $colaborador) {
            $resumen = $this->expediente->resumenCompletitud($colaborador);

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
     * @param  Collection<int, int>  $idsVisibles
     */
    private function bajasDelMes(Collection $idsVisibles): int
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

        return $colaboradores
            ->filter(fn (Colaborador $u) => $u->fecha_ingreso !== null)
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
     * @param  Collection<int, int>  $idsVisibles
     * @return array<int, array{id: int, colaborador: string|null, tipo: string, status: string, creado_en: string|null}>
     */
    private function documentosPendientesRevision(Collection $idsVisibles, bool $soloPropios = false): array
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
     * @param  Collection<int, EmployeeDocument>  $documentos
     * @return Collection<int, array{tono: string, mensaje: string}>
     */
    private function alertas(int $expedientesIncompletos, Collection $documentos): Collection
    {
        $alertas = collect();

        if ($expedientesIncompletos > 0) {
            $alertas->push([
                'tono' => 'warning',
                'mensaje' => "{$expedientesIncompletos} expediente(s) incompleto(s) requieren seguimiento.",
            ]);
        }

        $rechazados = $documentos->where('status', EstadoDocumento::Rechazado)->count();

        if ($rechazados > 0) {
            $alertas->push([
                'tono' => 'danger',
                'mensaje' => "{$rechazados} documento(s) rechazado(s) pendientes de que el colaborador vuelva a subirlos.",
            ]);
        }

        $enRevision = $documentos->where('status', EstadoDocumento::EnRevision)->count();

        if ($enRevision > 0) {
            $alertas->push([
                'tono' => 'info',
                'mensaje' => "{$enRevision} documento(s) esperando revisión de RH.",
            ]);
        }

        return $alertas;
    }
}
