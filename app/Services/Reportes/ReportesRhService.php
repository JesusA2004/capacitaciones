<?php

namespace App\Services\Reportes;

use App\Enums\EstadoCandidato;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstadoUsuario;
use App\Enums\EstadoVacante;
use App\Enums\EstatusImss;
use App\Enums\TipoMovimientoLaboral;
use App\Enums\TipoSolicitudInterna;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\EmployeeDocument;
use App\Models\MovimientoLaboral;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Models\Vacante;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\ExpedienteService;
use App\Services\Vacaciones\VacacionesService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Un único punto de verdad para todos los reportes de /rh/reportes: cada
 * reporte es una función pura que recibe (usuario, filtros) y devuelve una
 * tabla genérica {titulo, columnas, filas}. La página Inertia, el export a
 * Excel (App\Exports\ReporteRhExport) y el PDF (resources/views/pdf/reporte-rh.blade.php)
 * consumen exactamente la misma tabla — no hay una consulta para pantalla y
 * otra distinta para exportar (ver sección 11 del encargo: "no meter
 * consultas complejas en controladores").
 *
 * @phpstan-type Fila array<int, string|int|float|null>
 * @phpstan-type Reporte array{titulo: string, columnas: array<int, string>, filas: array<int, Fila>}
 */
class ReportesRhService
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly ExpedienteService $expediente,
        private readonly VacacionesService $vacaciones,
    ) {}

    /**
     * Catálogo de reportes disponibles, agrupado para el selector de la UI.
     *
     * @return array<int, array{grupo: string, opciones: array<int, array{value: string, label: string}>}>
     */
    public function catalogo(): array
    {
        return [
            ['grupo' => 'Plantilla', 'opciones' => [
                ['value' => 'empleados_total', 'label' => 'Número de empleados'],
                ['value' => 'empleados_por_empresa', 'label' => 'Empleados por empresa'],
                ['value' => 'empleados_por_sucursal', 'label' => 'Empleados por sucursal'],
                ['value' => 'empleados_por_puesto', 'label' => 'Empleados por puesto'],
                ['value' => 'empleados_imss', 'label' => 'Empleados con/sin IMSS'],
                ['value' => 'empleados_periodo_prueba', 'label' => 'Empleados en periodo de prueba'],
            ]],
            ['grupo' => 'Altas y bajas', 'opciones' => [
                ['value' => 'altas_por_mes', 'label' => 'Altas por mes'],
                ['value' => 'bajas_por_mes', 'label' => 'Bajas por mes'],
                ['value' => 'rotacion', 'label' => 'Rotación (altas vs. bajas)'],
            ]],
            ['grupo' => 'Reclutamiento', 'opciones' => [
                ['value' => 'vacantes_abiertas', 'label' => 'Vacantes abiertas'],
                ['value' => 'vacantes_cubiertas', 'label' => 'Vacantes cubiertas'],
                ['value' => 'candidatos_viables', 'label' => 'Candidatos viables'],
                ['value' => 'candidatos_por_sucursal', 'label' => 'Candidatos por sucursal'],
                ['value' => 'candidatos_por_puesto', 'label' => 'Candidatos por puesto'],
            ]],
            ['grupo' => 'Expedientes y documentos', 'opciones' => [
                ['value' => 'expedientes_estado', 'label' => 'Expedientes completos/incompletos'],
                ['value' => 'documentos_pendientes', 'label' => 'Documentos pendientes'],
                ['value' => 'documentos_rechazados', 'label' => 'Documentos rechazados'],
            ]],
            ['grupo' => 'Vacaciones, permisos y solicitudes', 'opciones' => [
                ['value' => 'vacaciones_disponibles', 'label' => 'Vacaciones disponibles'],
                ['value' => 'vacaciones_solicitudes', 'label' => 'Vacaciones solicitadas'],
                ['value' => 'solicitudes_pendientes', 'label' => 'Solicitudes pendientes'],
                ['value' => 'incapacidades', 'label' => 'Incapacidades'],
            ]],
            ['grupo' => 'Fechas relevantes', 'opciones' => [
                ['value' => 'cumpleanos', 'label' => 'Cumpleaños próximos'],
                ['value' => 'aniversarios', 'label' => 'Aniversarios laborales próximos'],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Reporte
     */
    public function generar(string $clave, User $usuario, array $filtros): array
    {
        return match ($clave) {
            'empleados_total' => $this->empleadosTotal($usuario, $filtros),
            'empleados_por_empresa' => $this->empleadosAgrupados($usuario, $filtros, fn (Colaborador $u) => $u->empresa()->nombre ?? 'Sin empresa', 'Empleados por empresa', 'Empresa'),
            'empleados_por_sucursal' => $this->empleadosAgrupados($usuario, $filtros, fn (Colaborador $u) => $u->sucursalPrincipal->nombre ?? 'Sin sucursal', 'Empleados por sucursal', 'Sucursal'),
            'empleados_por_puesto' => $this->empleadosAgrupados($usuario, $filtros, fn (Colaborador $u) => $u->puesto->nombre ?? 'Sin puesto', 'Empleados por puesto', 'Puesto'),
            'empleados_imss' => $this->empleadosImss($usuario, $filtros),
            'empleados_periodo_prueba' => $this->empleadosPeriodoPrueba($usuario, $filtros),
            'altas_por_mes' => $this->altasPorMes($usuario, $filtros),
            'bajas_por_mes' => $this->bajasPorMes($usuario, $filtros),
            'rotacion' => $this->rotacion($usuario, $filtros),
            'vacantes_abiertas' => $this->vacantesPorEstado($usuario, $filtros, [EstadoVacante::Abierta, EstadoVacante::EnReclutamiento, EstadoVacante::ConCandidatos, EstadoVacante::EnRevision], 'Vacantes abiertas'),
            'vacantes_cubiertas' => $this->vacantesPorEstado($usuario, $filtros, [EstadoVacante::Cubierta], 'Vacantes cubiertas'),
            'candidatos_viables' => $this->candidatosPorEstado($usuario, $filtros, [EstadoCandidato::Preseleccion, EstadoCandidato::Entrevista, EstadoCandidato::Psicometricos, EstadoCandidato::EstudioSocioeconomico, EstadoCandidato::Pruebas, EstadoCandidato::ValidacionDocumental, EstadoCandidato::OfertaAprobacion, EstadoCandidato::ListoParaContratacion], 'Candidatos viables'),
            'candidatos_por_sucursal' => $this->candidatosAgrupados($usuario, $filtros, fn (Candidato $c) => $c->sucursal->nombre ?? 'Sin sucursal', 'Candidatos por sucursal', 'Sucursal'),
            'candidatos_por_puesto' => $this->candidatosAgrupados($usuario, $filtros, fn (Candidato $c) => $c->puestoObjetivo->nombre ?? 'Sin puesto', 'Candidatos por puesto', 'Puesto'),
            'expedientes_estado' => $this->expedientesEstado($usuario, $filtros),
            'documentos_pendientes' => $this->documentosPorEstado($usuario, $filtros, [EstadoDocumento::Pendiente, EstadoDocumento::EnRevision, EstadoDocumento::RequiereCorreccion], 'Documentos pendientes'),
            'documentos_rechazados' => $this->documentosPorEstado($usuario, $filtros, [EstadoDocumento::Rechazado], 'Documentos rechazados'),
            'vacaciones_disponibles' => $this->vacacionesDisponibles($usuario, $filtros),
            'vacaciones_solicitudes' => $this->vacacionesSolicitudes($usuario, $filtros),
            'solicitudes_pendientes' => $this->solicitudesInternas($usuario, $filtros, [EstadoSolicitudInterna::Enviada, EstadoSolicitudInterna::EnRevision, EstadoSolicitudInterna::RequiereCorreccion], 'Solicitudes pendientes'),
            'incapacidades' => $this->solicitudesInternas($usuario, $filtros, null, 'Incapacidades', soloIncapacidades: true),
            'cumpleanos' => $this->fechasProximas($usuario, $filtros, 'fecha_nacimiento', 'Cumpleaños próximos (30 días)'),
            'aniversarios' => $this->fechasProximas($usuario, $filtros, 'fecha_ingreso', 'Aniversarios laborales próximos (30 días)'),
            default => ['titulo' => 'Reporte no encontrado', 'columnas' => [], 'filas' => []],
        };
    }

    /**
     * @param  Builder<Colaborador>  $query
     * @param  array<string, mixed>  $filtros
     * @return Builder<Colaborador>
     */
    private function aplicarFiltrosColaborador(Builder $query, array $filtros): Builder
    {
        return $query
            ->when($filtros['empresa_id'] ?? null, fn (Builder $q, $v) => $q->whereHas('sucursalPrincipal', fn (Builder $s) => $s->where('empresa_id', $v)))
            ->when($filtros['sucursal_id'] ?? null, fn (Builder $q, $v) => $q->where('sucursal_principal_id', $v))
            ->when($filtros['departamento_id'] ?? null, fn (Builder $q, $v) => $q->where('departamento_id', $v))
            ->when($filtros['puesto_id'] ?? null, fn (Builder $q, $v) => $q->where('puesto_id', $v))
            ->when($filtros['colaborador_id'] ?? null, fn (Builder $q, $v) => $q->where('id', $v));
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, Colaborador>
     */
    private function colaboradoresVisibles(User $usuario, array $filtros): Collection
    {
        $query = $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query(), $usuario)
            ->with(['user:id,colaborador_id', 'sucursalPrincipal:id,nombre,empresa_id', 'sucursalPrincipal.empresa:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre']);

        return $this->aplicarFiltrosColaborador($query, $filtros)->get();
    }

    /**
     * IDs de `users` (cuentas de acceso) de los colaboradores visibles —
     * necesario porque `solicitudes_internas`/`solicitudes_vacaciones`
     * todavía identifican a la persona por `user_id` (Parte B de la
     * separación Usuario/Colaborador pendiente de migrar estos servicios):
     * un colaborador sin cuenta de acceso simplemente no puede tener
     * solicitudes todavía.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, int>
     */
    private function idsUsuariosVisibles(User $usuario, array $filtros): Collection
    {
        return $this->colaboradoresVisibles($usuario, $filtros)
            ->pluck('user.id')
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Reporte
     */
    private function empleadosTotal(User $usuario, array $filtros): array
    {
        $colaboradores = $this->colaboradoresVisibles($usuario, $filtros);

        return [
            'titulo' => 'Número de empleados',
            'columnas' => ['Estatus', 'Total'],
            'filas' => [
                ['Activos', $colaboradores->where('estatus', EstadoUsuario::Activo)->count()],
                ['Inactivos', $colaboradores->where('estatus', EstadoUsuario::Inactivo)->count()],
                ['Suspendidos', $colaboradores->where('estatus', EstadoUsuario::Suspendido)->count()],
                ['Total', $colaboradores->count()],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @param  callable(Colaborador): string  $clasificador
     * @return Reporte
     */
    private function empleadosAgrupados(User $usuario, array $filtros, callable $clasificador, string $titulo, string $columna): array
    {
        $filas = $this->colaboradoresVisibles($usuario, $filtros)
            ->groupBy($clasificador)
            ->map(fn (Collection $grupo, string $etiqueta) => [$etiqueta, $grupo->count()])
            ->sortByDesc(fn ($fila) => $fila[1])
            ->values()
            ->all();

        return ['titulo' => $titulo, 'columnas' => [$columna, 'Total'], 'filas' => $filas];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Reporte
     */
    private function empleadosImss(User $usuario, array $filtros): array
    {
        $colaboradores = $this->colaboradoresVisibles($usuario, $filtros);

        return [
            'titulo' => 'Empleados con/sin IMSS',
            'columnas' => ['Estatus IMSS', 'Total'],
            'filas' => collect(EstatusImss::cases())
                ->map(fn (EstatusImss $estatus) => [$estatus->etiqueta(), $colaboradores->where('estatus_imss', $estatus)->count()])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Reporte
     */
    private function empleadosPeriodoPrueba(User $usuario, array $filtros): array
    {
        $hoy = now();

        $filas = $this->colaboradoresVisibles($usuario, $filtros)
            ->filter(fn (Colaborador $u) => $u->periodo_prueba_fin !== null && Carbon::parse($u->periodo_prueba_fin)->isAfter($hoy))
            ->map(fn (Colaborador $u) => [
                $u->nombreCompleto(),
                $u->sucursalPrincipal->nombre ?? '—',
                $u->periodo_prueba_inicio?->toDateString(),
                $u->periodo_prueba_fin?->toDateString(),
            ])
            ->values()
            ->all();

        return ['titulo' => 'Empleados en periodo de prueba', 'columnas' => ['Colaborador', 'Sucursal', 'Inicio', 'Fin'], 'filas' => $filas];
    }

    /**
     * IDs de colaboradores visibles para $usuario SEGÚN SU ALCANCE
     * (AlcanceOrganizacionalService), incluyendo bajas (withTrashed): altas
     * y bajas necesitan poder "ver" tanto colaboradores activos como dados
     * de baja dentro del alcance — filtrar primero por User::query() (que
     * excluye soft-deleted) y luego intersectar con onlyTrashed() nunca
     * encuentra nada, porque un ID nunca puede estar en ambos conjuntos a
     * la vez (ver CLAUDE.md, bug de reportes de bajas).
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, int>
     */
    private function idsVisiblesConBajas(User $usuario, array $filtros): Collection
    {
        $query = $this->alcance->limitarColaboradoresPorAlcance(Colaborador::withTrashed(), $usuario);

        return $this->aplicarFiltrosColaborador($query, $filtros)->pluck('id');
    }

    /**
     * Altas por mes desde el histórico inmutable de MovimientoLaboral (tipo
     * "alta"), no desde `users.created_at`: created_at es cuándo se creó la
     * fila en BD (puede no coincidir con la fecha de ingreso real, p. ej. en
     * una carga masiva/migración), mientras que el movimiento de alta guarda
     * `fecha_movimiento` = fecha_ingreso del colaborador en el momento en que
     * causó alta (ver MovimientoLaboralService::registrarAlta) — la fuente
     * correcta según la definición de negocio de "cuándo entró alguien".
     *
     * @param  array<string, mixed>  $filtros
     * @return Reporte
     */
    private function altasPorMes(User $usuario, array $filtros): array
    {
        $fechas = MovimientoLaboral::query()
            ->whereIn('colaborador_id', $this->idsVisiblesConBajas($usuario, $filtros))
            ->where('tipo_movimiento', TipoMovimientoLaboral::Alta->value)
            ->pluck('fecha_movimiento');

        return $this->porMes($fechas, 'Altas por mes');
    }

    /**
     * Bajas por mes desde MovimientoLaboral (tipo "baja"), registrado por
     * UsuarioController::destroy() y BajaColaboradorService en el mismo
     * instante en que se da de baja al colaborador — a diferencia de
     * `users.deleted_at` intersectado con un listado de IDs que por
     * construcción nunca incluye bajas (ver idsVisiblesConBajas()).
     *
     * @param  array<string, mixed>  $filtros
     * @return Reporte
     */
    private function bajasPorMes(User $usuario, array $filtros): array
    {
        $fechas = MovimientoLaboral::query()
            ->whereIn('colaborador_id', $this->idsVisiblesConBajas($usuario, $filtros))
            ->where('tipo_movimiento', TipoMovimientoLaboral::Baja->value)
            ->pluck('fecha_movimiento');

        return $this->porMes($fechas, 'Bajas por mes');
    }

    /**
     * @param  Collection<int, Carbon|string|null>  $fechas
     * @return Reporte
     */
    private function porMes(Collection $fechas, string $titulo): array
    {
        $desde = now()->subMonths(11)->startOfMonth();

        $meses = collect(range(0, 11))->map(fn (int $i) => $desde->copy()->addMonths($i));

        $conteos = $fechas
            ->filter()
            ->map(fn ($f) => Carbon::parse($f)->format('Y-m'))
            ->countBy();

        $filas = $meses->map(fn (CarbonImmutable $mes) => [
            $mes->translatedFormat('F Y'),
            (int) ($conteos[$mes->format('Y-m')] ?? 0),
        ])->all();

        return ['titulo' => $titulo, 'columnas' => ['Mes', 'Total'], 'filas' => $filas];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Reporte
     */
    private function rotacion(User $usuario, array $filtros): array
    {
        $altas = $this->altasPorMes($usuario, $filtros)['filas'];
        $bajas = $this->bajasPorMes($usuario, $filtros)['filas'];

        $filas = [];

        foreach ($altas as $i => $fila) {
            $totalAltas = (int) $fila[1];
            $totalBajas = (int) ($bajas[$i][1] ?? 0);
            $filas[] = [$fila[0], $totalAltas, $totalBajas, $totalAltas - $totalBajas];
        }

        return ['titulo' => 'Rotación (altas vs. bajas)', 'columnas' => ['Mes', 'Altas', 'Bajas', 'Neto'], 'filas' => $filas];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<Vacante>
     */
    private function vacantesVisibles(User $usuario, array $filtros): Builder
    {
        return $this->alcance->limitarPorSucursal(Vacante::query(), $usuario)
            ->when($filtros['empresa_id'] ?? null, fn (Builder $q, $v) => $q->where('empresa_id', $v))
            ->when($filtros['sucursal_id'] ?? null, fn (Builder $q, $v) => $q->where('sucursal_id', $v))
            ->when($filtros['puesto_id'] ?? null, fn (Builder $q, $v) => $q->where('puesto_id', $v))
            ->with(['sucursal:id,nombre', 'puesto:id,nombre']);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @param  array<int, EstadoVacante>  $estados
     * @return Reporte
     */
    private function vacantesPorEstado(User $usuario, array $filtros, array $estados, string $titulo): array
    {
        $filas = $this->vacantesVisibles($usuario, $filtros)
            ->whereIn('estado', $estados)
            ->get()
            ->map(fn (Vacante $v) => [$v->puesto->nombre ?? '—', $v->sucursal->nombre ?? '—', $v->estado->etiqueta(), $v->fecha_apertura->toDateString()])
            ->all();

        return ['titulo' => $titulo, 'columnas' => ['Puesto', 'Sucursal', 'Estado', 'Fecha de apertura'], 'filas' => $filas];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<Candidato>
     */
    private function candidatosVisibles(User $usuario, array $filtros): Builder
    {
        return $this->alcance->limitarPorSucursal(Candidato::query(), $usuario)
            ->when($filtros['empresa_id'] ?? null, fn (Builder $q, $v) => $q->where('empresa_id', $v))
            ->when($filtros['sucursal_id'] ?? null, fn (Builder $q, $v) => $q->where('sucursal_id', $v))
            ->when($filtros['puesto_id'] ?? null, fn (Builder $q, $v) => $q->where('puesto_objetivo_id', $v))
            ->with(['sucursal:id,nombre', 'puestoObjetivo:id,nombre']);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @param  array<int, EstadoCandidato>  $estados
     * @return Reporte
     */
    private function candidatosPorEstado(User $usuario, array $filtros, array $estados, string $titulo): array
    {
        $filas = $this->candidatosVisibles($usuario, $filtros)
            ->whereIn('estado', $estados)
            ->get()
            ->map(fn (Candidato $c) => [$c->nombreCompleto(), $c->puestoObjetivo->nombre ?? '—', $c->sucursal->nombre ?? '—', $c->estado->etiqueta()])
            ->all();

        return ['titulo' => $titulo, 'columnas' => ['Candidato', 'Puesto objetivo', 'Sucursal', 'Estado'], 'filas' => $filas];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @param  callable(Candidato): string  $clasificador
     * @return Reporte
     */
    private function candidatosAgrupados(User $usuario, array $filtros, callable $clasificador, string $titulo, string $columna): array
    {
        $filas = $this->candidatosVisibles($usuario, $filtros)->get()
            ->groupBy($clasificador)
            ->map(fn (Collection $grupo, string $etiqueta) => [$etiqueta, $grupo->count()])
            ->sortByDesc(fn ($fila) => $fila[1])
            ->values()
            ->all();

        return ['titulo' => $titulo, 'columnas' => [$columna, 'Total'], 'filas' => $filas];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Reporte
     */
    private function expedientesEstado(User $usuario, array $filtros): array
    {
        $filas = $this->colaboradoresVisibles($usuario, $filtros)
            ->map(function (Colaborador $u) {
                $resumen = $this->expediente->resumenCompletitud($u);
                $completo = $resumen['requeridos_total'] > 0 && $resumen['porcentaje'] >= 100.0;

                return [$u->nombreCompleto(), $u->sucursalPrincipal->nombre ?? '—', $completo ? 'Completo' : 'Incompleto', round($resumen['porcentaje'], 1)];
            })
            ->all();

        return ['titulo' => 'Expedientes completos/incompletos', 'columnas' => ['Colaborador', 'Sucursal', 'Estado', 'Porcentaje'], 'filas' => $filas];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<EmployeeDocument>
     */
    private function documentosVisibles(User $usuario, array $filtros): Builder
    {
        $idsVisibles = $this->colaboradoresVisibles($usuario, $filtros)->pluck('id');

        return EmployeeDocument::query()
            ->whereIn('colaborador_id', $idsVisibles)
            ->when($filtros['tipo_documento'] ?? null, fn (Builder $q, $v) => $q->where('document_type_id', $v))
            ->with(['colaborador:id,name,apellidos', 'tipo:id,nombre']);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @param  array<int, EstadoDocumento>  $estados
     * @return Reporte
     */
    private function documentosPorEstado(User $usuario, array $filtros, array $estados, string $titulo): array
    {
        $filas = $this->documentosVisibles($usuario, $filtros)
            ->whereIn('status', $estados)
            ->get()
            ->map(fn (EmployeeDocument $d) => [trim(($d->colaborador->name ?? '').' '.($d->colaborador->apellidos ?? '')), $d->tipo->nombre ?? '—', $d->status->etiqueta(), $d->created_at?->toDateString()])
            ->all();

        return ['titulo' => $titulo, 'columnas' => ['Colaborador', 'Tipo de documento', 'Estado', 'Fecha'], 'filas' => $filas];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Reporte
     */
    private function vacacionesDisponibles(User $usuario, array $filtros): array
    {
        $filas = $this->colaboradoresVisibles($usuario, $filtros)
            ->map(function (Colaborador $u) {
                // solicitudes_vacaciones/solicitudes_internas todavía
                // identifican a la persona por user_id (Parte B pendiente):
                // un colaborador sin cuenta de acceso no puede tener
                // vacaciones registradas todavía.
                $saldo = $u->user !== null
                    ? $this->vacaciones->saldo($u->user)
                    : ['dias_generados' => 0, 'dias_usados' => 0, 'dias_en_solicitud' => 0, 'dias_disponibles' => 0];

                return [$u->nombreCompleto(), $u->sucursalPrincipal->nombre ?? '—', $saldo['dias_generados'], $saldo['dias_usados'], $saldo['dias_disponibles']];
            })
            ->all();

        return ['titulo' => 'Vacaciones disponibles', 'columnas' => ['Colaborador', 'Sucursal', 'Generados', 'Usados', 'Disponibles'], 'filas' => $filas];
    }

    /**
     * Lee de `solicitudes_internas` (tipo=vacaciones), el flujo unificado
     * vigente — nunca de la tabla legacy `SolicitudVacaciones` (ver
     * docs/SOLICITUDES_UNIFICADAS.md y ExpedienteTest, "el expediente
     * muestra el historial de vacaciones desde solicitudes_internas, no
     * desde la tabla legacy"): este reporte no debe dar una cifra distinta
     * a la que ve un colaborador en su propio expediente.
     *
     * @param  array<string, mixed>  $filtros
     * @return Reporte
     */
    private function vacacionesSolicitudes(User $usuario, array $filtros): array
    {
        $idsVisibles = $this->idsUsuariosVisibles($usuario, $filtros);

        $filas = SolicitudInterna::query()
            ->whereIn('user_id', $idsVisibles)
            ->where('tipo', TipoSolicitudInterna::Vacaciones->value)
            ->when($filtros['estado'] ?? null, fn (Builder $q, $v) => $q->where('estado', $v))
            ->when($filtros['fecha_inicio'] ?? null, fn (Builder $q, $v) => $q->where('fecha_inicio', '>=', $v))
            ->when($filtros['fecha_fin'] ?? null, fn (Builder $q, $v) => $q->where('fecha_fin', '<=', $v))
            ->with('usuario:id,name,apellidos')
            ->get()
            ->map(fn (SolicitudInterna $s) => [
                trim(($s->usuario->name ?? '').' '.($s->usuario->apellidos ?? '')),
                $s->fecha_inicio?->toDateString(),
                $s->fecha_fin?->toDateString(),
                $s->dias_solicitados,
                $s->estado->etiqueta(),
            ])
            ->all();

        return ['titulo' => 'Vacaciones solicitadas', 'columnas' => ['Colaborador', 'Inicio', 'Fin', 'Días', 'Estado'], 'filas' => $filas];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @param  array<int, EstadoSolicitudInterna>|null  $estados
     * @return Reporte
     */
    private function solicitudesInternas(User $usuario, array $filtros, ?array $estados, string $titulo, bool $soloIncapacidades = false): array
    {
        $idsVisibles = $this->idsUsuariosVisibles($usuario, $filtros);

        $filas = SolicitudInterna::query()
            ->whereIn('user_id', $idsVisibles)
            ->when($estados !== null, fn (Builder $q) => $q->whereIn('estado', $estados))
            ->when($soloIncapacidades, fn (Builder $q) => $q->where('tipo', 'incapacidad'))
            ->when($filtros['tipo_solicitud'] ?? null, fn (Builder $q, $v) => $q->where('tipo', $v))
            ->when($filtros['fecha_inicio'] ?? null, fn (Builder $q, $v) => $q->where('created_at', '>=', $v))
            ->when($filtros['fecha_fin'] ?? null, fn (Builder $q, $v) => $q->where('created_at', '<=', $v))
            ->with('usuario:id,name,apellidos')
            ->get()
            ->map(fn (SolicitudInterna $s) => [
                $s->folio,
                trim(($s->usuario->name ?? '').' '.($s->usuario->apellidos ?? '')),
                $s->tipo->etiqueta(),
                $s->estado->etiqueta(),
                $s->created_at?->toDateString(),
            ])
            ->all();

        return ['titulo' => $titulo, 'columnas' => ['Folio', 'Colaborador', 'Tipo', 'Estado', 'Fecha'], 'filas' => $filas];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Reporte
     */
    private function fechasProximas(User $usuario, array $filtros, string $campo, string $titulo): array
    {
        $hoy = now()->startOfDay();

        $filas = $this->colaboradoresVisibles($usuario, $filtros)
            ->filter(fn (Colaborador $u) => $u->{$campo} !== null)
            ->map(function (Colaborador $u) use ($campo, $hoy) {
                $proximo = Carbon::parse($u->{$campo})->year($hoy->year);

                if ($proximo->lt($hoy)) {
                    $proximo = $proximo->addYear();
                }

                return [$u, $proximo, (int) $hoy->diffInDays($proximo)];
            })
            ->filter(fn (array $item) => $item[2] <= 30)
            ->sortBy(fn (array $item) => $item[2])
            ->map(fn (array $item) => [$item[0]->nombreCompleto(), $item[0]->sucursalPrincipal->nombre ?? '—', $item[1]->toDateString(), $item[2]])
            ->values()
            ->all();

        return ['titulo' => $titulo, 'columnas' => ['Colaborador', 'Sucursal', 'Fecha', 'Días restantes'], 'filas' => $filas];
    }
}
