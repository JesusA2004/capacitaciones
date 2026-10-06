<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoCandidato;
use App\Enums\FuenteCandidato;
use App\Enums\ResultadoEtapaCandidato;
use App\Enums\ResultadoReferencia;
use App\Enums\TipoContratacion;
use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\ContratarCandidatoRequest;
use App\Http\Requests\CicloLaboral\DecisionAprobacionRequest;
use App\Http\Requests\Reclutamiento\DescartarCandidatoRequest;
use App\Http\Requests\Reclutamiento\EnviarPsicometricasRequest;
use App\Http\Requests\Reclutamiento\EvaluarFiltroCandidatoRequest;
use App\Http\Requests\Reclutamiento\RegistrarEntrevistaRequest;
use App\Http\Requests\Reclutamiento\RegistrarReferenciaRequest;
use App\Http\Requests\Reclutamiento\RegistrarSocioeconomicoRequest;
use App\Http\Requests\Reclutamiento\ResultadosPsicometricasRequest;
use App\Http\Requests\Rh\ActualizarEstadoCandidatoRequest;
use App\Http\Requests\Rh\StoreCandidatoRequest;
use App\Http\Requests\Rh\StoreSeguimientoCandidatoRequest;
use App\Http\Requests\Rh\SubirCvCandidatoRequest;
use App\Http\Requests\Rh\UpdateCandidatoRequest;
use App\Models\Candidato;
use App\Models\CandidatoEvidencia;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\Reclutamiento\CandidatoPresenter;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use App\Services\Reclutamiento\ContratacionCandidatoService;
use App\Services\Reclutamiento\CvStorageService;
use App\Services\Vacantes\VacantesListadoService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reclutamiento web. Delgado: valida (FormRequest), autoriza la vista
 * (Policy) y delega TODO avance a CandidatoWorkflowService /
 * ContratacionCandidatoService — los mismos services que usa la API móvil.
 */
class CandidatoController extends Controller
{
    private const FILTROS = ['empresa_id', 'sucursal_id', 'departamento_id', 'puesto_objetivo_id', 'vacante_id', 'responsable_rh_id', 'fuente', 'busqueda', 'fecha_inicio', 'fecha_fin', 'mes'];

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly CvStorageService $cvStorage,
        private readonly CandidatoWorkflowService $workflow,
        private readonly ContratacionCandidatoService $contratacion,
        private readonly CicloLaboralService $ciclo,
        private readonly CandidatoPresenter $presenter,
        private readonly VacantesListadoService $vacantesListado,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Candidato::class);

        $candidatos = $this->queryFiltrada($request)
            ->with(['ultimoSeguimiento.registradoPor:id,name,apellidos', 'ultimoCambioEstado'])
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Rh/Candidatos/Index', [
            'candidatos' => $candidatos,
            'filtros' => $request->only(self::FILTROS),
            'opciones' => $this->opciones($request->user()),
            'kpis' => $this->kpis($request),
        ]);
    }

    /**
     * KPIs del header de Candidatos: recibidos en el periodo, en proceso,
     * finalistas (esperando o con autorización de RH), contratados, tasa de
     * conversión y tiempo promedio de contratación. Respeta alcance y filtros.
     *
     * @return array<string, int|float|null>
     */
    private function kpis(Request $request): array
    {
        $usuario = $request->user();
        [$inicioPeriodo, $finPeriodo] = $this->rangoMes($request->string('mes')->toString());

        $base = fn () => $this->alcance
            ->limitarPorSucursal(Candidato::query(), $usuario)
            ->when($request->integer('empresa_id'), fn ($q, $v) => $q->where('empresa_id', $v))
            ->when($request->integer('sucursal_id'), fn ($q, $v) => $q->where('sucursal_id', $v))
            ->when($request->integer('departamento_id'), fn ($q, $v) => $q->where('departamento_id', $v))
            ->when($request->integer('puesto_objetivo_id'), fn ($q, $v) => $q->where('puesto_objetivo_id', $v))
            ->when($request->string('fuente')->toString(), fn ($q, string $v) => $q->where('fuente', $v));

        $recibidosPeriodo = $base()->whereBetween('created_at', [$inicioPeriodo, $finPeriodo])->count();
        $enProceso = $base()->whereIn('estado', array_map(fn (EstadoCandidato $e) => $e->value, EstadoCandidato::abiertos()))->count();
        $finalistas = $base()->whereIn('estado', [EstadoCandidato::AutorizacionRhPendiente->value, EstadoCandidato::AutorizadoRh->value])->count();

        $contratados = $base()
            ->where('estado', EstadoCandidato::Contratado->value)
            ->whereNotNull('contratado_en')
            ->whereBetween('contratado_en', [$inicioPeriodo, $finPeriodo])
            ->get(['id', 'created_at', 'contratado_en']);

        $dias = $contratados
            ->map(fn (Candidato $c) => $c->created_at !== null && $c->contratado_en !== null ? $c->created_at->diffInDays($c->contratado_en) : null)
            ->filter(fn ($d) => $d !== null);

        return [
            'recibidos_periodo' => $recibidosPeriodo,
            'en_proceso' => $enProceso,
            'finalistas' => $finalistas,
            'contratados_periodo' => $contratados->count(),
            'tasa_conversion' => $recibidosPeriodo > 0 ? round($contratados->count() / $recibidosPeriodo, 4) : 0.0,
            'tiempo_promedio_contratacion_dias' => $dias->isEmpty() ? null : round((float) $dias->avg(), 1),
        ];
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function rangoMes(string $mes): array
    {
        if ($mes !== '' && preg_match('/^\d{4}-\d{2}$/', $mes) === 1) {
            $inicio = Carbon::parse("{$mes}-01")->startOfDay();

            return [$inicio, $inicio->copy()->endOfMonth()];
        }

        return [now()->startOfMonth(), now()->copy()->endOfMonth()];
    }

    public function exportarExcel(Request $request): HttpResponse
    {
        $this->authorize('viewAny', Candidato::class);

        [$columnas, $filas] = $this->tabla($request);

        return Excel::download(new ReporteRhExport('Candidatos', $columnas, $filas), 'candidatos-'.now()->format('Y-m-d').'.xlsx');
    }

    public function exportarPdf(Request $request): HttpResponse
    {
        $this->authorize('viewAny', Candidato::class);

        [$columnas, $filas] = $this->tabla($request);

        return Pdf::loadView('pdf.reporte-rh', ['titulo' => 'Candidatos', 'columnas' => $columnas, 'filas' => $filas])
            ->setPaper('letter', 'landscape')
            ->download('candidatos-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int|null>>}
     */
    private function tabla(Request $request): array
    {
        $candidatos = $this->queryFiltrada($request)->orderByDesc('created_at')->get();

        $columnas = ['Nombre', 'Correo', 'Teléfono', 'Puesto objetivo', 'Empresa', 'Sucursal', 'Responsable RH', 'Estado', 'Fecha de registro'];

        $filas = $candidatos->map(fn (Candidato $c) => [
            trim("{$c->nombre} {$c->apellidos}"),
            $c->correo,
            $c->telefono,
            $c->puestoObjetivo?->nombre,
            $c->empresa?->nombre,
            $c->sucursal?->nombre,
            $c->responsableRh ? trim("{$c->responsableRh->name} {$c->responsableRh->apellidos}") : null,
            $c->estado->etiqueta(),
            $c->created_at?->toDateString(),
        ])->all();

        return [$columnas, $filas];
    }

    /**
     * @return Builder<Candidato>
     */
    private function queryFiltrada(Request $request): Builder
    {
        // El tablero web agrupa por estado en columnas: no filtra por estado.
        return $this->presenter->consulta($request->user(), $request->except('estado'));
    }

    public function show(Request $request, Candidato $candidato): Response
    {
        $this->authorize('view', $candidato);

        return Inertia::render('Rh/Candidatos/Show', [
            'candidato' => $this->presenter->detalle($candidato),
            'ciclo' => $this->ciclo->obtenerEstado($candidato, $request->user()),
            'opciones' => $this->opciones($request->user()),
        ]);
    }

    public function store(StoreCandidatoRequest $request): RedirectResponse
    {
        $this->workflow->registrar($request->validated(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Candidato registrado correctamente.']);
    }

    public function update(UpdateCandidatoRequest $request, Candidato $candidato): RedirectResponse
    {
        $this->workflow->actualizar($candidato, $request->validated(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Candidato actualizado correctamente.']);
    }

    public function subirCv(SubirCvCandidatoRequest $request, Candidato $candidato): RedirectResponse
    {
        if ($candidato->cv_path) {
            $this->cvStorage->eliminar($candidato->cv_path);
        }

        $archivo = $request->file('cv');
        $nombreInterno = $this->cvStorage->nombreInterno($archivo->getClientOriginalName());
        $ruta = $this->cvStorage->rutaCv($candidato->id, $nombreInterno);
        $this->cvStorage->guardar($archivo, $ruta);

        $candidato->update([
            'cv_disk' => config('reclutamiento.disk'),
            'cv_path' => $ruta,
            'cv_original_name' => $archivo->getClientOriginalName(),
            'cv_mime' => $archivo->getMimeType() ?? $archivo->getClientMimeType(),
            'cv_size' => $archivo->getSize(),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'CV cargado correctamente.']);
    }

    public function descargarCv(Candidato $candidato): StreamedResponse
    {
        $this->authorize('view', $candidato);

        abort_unless($candidato->cv_path !== null, 404);

        return $this->cvStorage->respuesta($candidato->cv_path, [
            'Content-Disposition' => 'attachment; filename="'.$candidato->cv_original_name.'"',
        ]);
    }

    /** Vista previa embebida (iframe/img, nunca descarga forzada) — mismo patrón que descargarEvidencia(). */
    public function previsualizarCv(Candidato $candidato): StreamedResponse
    {
        $this->authorize('view', $candidato);

        abort_unless($candidato->cv_path !== null, 404);

        return $this->cvStorage->respuesta($candidato->cv_path, [
            'Content-Disposition' => 'inline; filename="'.$candidato->cv_original_name.'"',
        ]);
    }

    /**
     * Evidencia privada (socioeconómico/psicométricas): Policy + alcance,
     * nunca URL pública.
     */
    public function descargarEvidencia(Candidato $candidato, CandidatoEvidencia $evidencia): StreamedResponse
    {
        $this->authorize('view', $candidato);
        abort_unless($evidencia->candidato_id === $candidato->id, 404);

        return $this->cvStorage->respuesta($evidencia->path, [
            'Content-Type' => $evidencia->mime ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$evidencia->original_name.'"',
        ]);
    }

    /**
     * Tablero: arrastrar solo CIERRA el proceso (salida con motivo).
     */
    public function actualizarEstado(ActualizarEstadoCandidatoRequest $request, Candidato $candidato): RedirectResponse
    {
        $estado = EstadoCandidato::from($request->validated('estado'));
        $this->workflow->descartar($candidato, $request->user(), $estado, (string) ($request->validated('nota') ?? ''));

        return back()->with('toast', ['type' => 'success', 'message' => 'Proceso del candidato cerrado.']);
    }

    public function evaluarPerfil(EvaluarFiltroCandidatoRequest $request, Candidato $candidato): RedirectResponse
    {
        $this->workflow->evaluarPerfil($candidato, $request->user(), $request->boolean('viable'), $request->validated('observaciones'));

        return $this->ok('Revisión de perfil registrada.');
    }

    public function registrarEntrevista(RegistrarEntrevistaRequest $request, Candidato $candidato): RedirectResponse
    {
        $this->workflow->registrarEntrevista($candidato, $request->user(), $request->validated());

        return $this->ok('Entrevista registrada.');
    }

    public function enviarPsicometricas(EnviarPsicometricasRequest $request, Candidato $candidato): RedirectResponse
    {
        $this->workflow->enviarPsicometricas($candidato, $request->user(), (string) $request->validated('link'));

        return $this->ok('Link de psicométricas registrado.');
    }

    public function resultadosPsicometricas(ResultadosPsicometricasRequest $request, Candidato $candidato): RedirectResponse
    {
        $this->workflow->registrarResultadosPsicometricas($candidato, $request->user(), (string) $request->validated('resumen'), array_values($request->file('archivos', [])));

        return $this->ok('Resultados psicométricos registrados y entregados al gerente.');
    }

    public function revisarPsicometricas(EvaluarFiltroCandidatoRequest $request, Candidato $candidato): RedirectResponse
    {
        $this->workflow->revisarPsicometricas($candidato, $request->user(), $request->boolean('viable'), $request->validated('observaciones'));

        return $this->ok('Revisión de psicométricas registrada.');
    }

    public function registrarSocioeconomico(RegistrarSocioeconomicoRequest $request, Candidato $candidato): RedirectResponse
    {
        $this->workflow->registrarSocioeconomico($candidato, $request->user(), $request->safe()->except('evidencias'), array_values($request->file('evidencias', [])));

        return $this->ok('Estudio socioeconómico registrado.');
    }

    public function registrarReferencia(RegistrarReferenciaRequest $request, Candidato $candidato): RedirectResponse
    {
        $this->workflow->registrarReferencia($candidato, $request->user(), $request->validated());

        return $this->ok('Referencia registrada.');
    }

    public function concluirReferencias(EvaluarFiltroCandidatoRequest $request, Candidato $candidato): RedirectResponse
    {
        $this->workflow->concluirReferencias($candidato, $request->user(), $request->boolean('viable'), $request->validated('observaciones'));

        return $this->ok('Validación de referencias concluida.');
    }

    public function preautorizar(DecisionAprobacionRequest $request, Candidato $candidato): RedirectResponse
    {
        $this->workflow->preautorizar($candidato, $request->user(), $request->comentario());

        return $this->ok('Preautorizado: queda pendiente la autorización final de RH.');
    }

    public function autorizarRh(DecisionAprobacionRequest $request, Candidato $candidato): RedirectResponse
    {
        $this->workflow->autorizarRh($candidato, $request->user(), $request->comentario());

        return $this->ok('Contratación autorizada por RH. Ya puedes generar el QR.');
    }

    public function rechazarRh(DecisionAprobacionRequest $request, Candidato $candidato): RedirectResponse
    {
        $this->workflow->rechazarRh($candidato, $request->user(), $request->motivo());

        return $this->ok('Contratación rechazada.');
    }

    public function devolverRh(DecisionAprobacionRequest $request, Candidato $candidato): RedirectResponse
    {
        $this->workflow->devolverRh($candidato, $request->user(), $request->motivo());

        return $this->ok('Devuelto al gerente para corrección.');
    }

    public function descartar(DescartarCandidatoRequest $request, Candidato $candidato): RedirectResponse
    {
        $this->workflow->descartar($candidato, $request->user(), EstadoCandidato::from((string) $request->validated('estado')), (string) $request->validated('motivo'));

        return $this->ok('Proceso del candidato cerrado.');
    }

    /**
     * Etapa 1 → 2: crea al colaborador (sin duplicar persona) y el QR
     * ligado a él. El token plano solo vive en la sesión de quien lo generó.
     */
    public function iniciarContratacion(ContratarCandidatoRequest $request, Candidato $candidato): RedirectResponse
    {
        ['invitacion' => $invitacion, 'token' => $token] = $this->contratacion->iniciarContratacion($candidato, $request->validated(), $request->user());

        // Misma clave/vigencia que IncorporacionInvitacionController::show() lee.
        $request->session()->put("incorporacion_invitacion_token_{$invitacion->id}", [
            'token' => $token,
            'expira_en' => now()->addMinutes(5)->timestamp,
        ]);

        return redirect()->route('rh.incorporacion.invitaciones.show', $invitacion)
            ->with('toast', ['type' => 'success', 'message' => 'Contratación iniciada: comparte el QR con el candidato. No vuelve a mostrarse.']);
    }

    public function agregarSeguimiento(StoreSeguimientoCandidatoRequest $request, Candidato $candidato): RedirectResponse
    {
        $candidato->seguimientos()->create([
            ...$request->validated(),
            'fecha' => $request->validated('fecha') ?? now(),
            'registrado_por' => $request->user()?->id,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Seguimiento agregado.']);
    }

    public function destroy(Candidato $candidato): RedirectResponse
    {
        $this->authorize('delete', $candidato);

        if ($candidato->cv_path) {
            $this->cvStorage->eliminar($candidato->cv_path);
        }

        $candidato->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Candidato eliminado correctamente.']);
    }

    private function ok(string $mensaje): RedirectResponse
    {
        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }

    /**
     * @return array<string, mixed>
     */
    private function opciones(User $usuario): array
    {
        return [
            'empresas' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
            'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
            'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            'puestos' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre', 'departamento_id']),
            // Única fuente de verdad de "vacante real" (plazas_autorizadas −
            // ocupadas > 0): App\Services\Vacantes\VacantesListadoService,
            // la misma que usa el listado de Vacantes (CLAUDE.md §2). Nunca
            // una query propia aquí.
            'vacantes' => $this->vacantesListado->consulta($usuario)
                ->with(['puesto:id,nombre', 'sucursal:id,nombre'])
                ->orderByDesc('fecha_apertura')
                ->get(['id', 'puesto_id', 'sucursal_id', 'estado']),
            'responsables' => User::query()->role(['rh_admin', 'rh_auxiliar'])->orderBy('name')->get(['id', 'name', 'apellidos']),
            'gerentes' => User::query()->permission(CandidatoWorkflowService::PERMISO_GERENTE)->whereNull('acceso_bloqueado_en')->orderBy('name')->get(['id', 'name', 'apellidos']),
            'estados' => array_map(fn (EstadoCandidato $e) => ['value' => $e->value, 'etiqueta' => $e->etiqueta(), 'salida' => $e->esSalida()], EstadoCandidato::cases()),
            'salidas' => array_map(fn (EstadoCandidato $e) => ['value' => $e->value, 'etiqueta' => $e->etiqueta()], array_values(array_filter(EstadoCandidato::salidas(), fn (EstadoCandidato $e) => $e !== EstadoCandidato::RechazadoRh))),
            'fuentes' => array_map(fn (FuenteCandidato $f) => ['value' => $f->value, 'etiqueta' => $f->etiqueta()], FuenteCandidato::cases()),
            'resultados' => array_map(fn (ResultadoEtapaCandidato $r) => ['value' => $r->value, 'etiqueta' => $r->etiqueta()], ResultadoEtapaCandidato::cases()),
            'resultadosReferencia' => array_map(fn (ResultadoReferencia $r) => ['value' => $r->value, 'etiqueta' => $r->etiqueta()], ResultadoReferencia::cases()),
            'tiposContratacion' => array_map(fn (TipoContratacion $t) => ['value' => $t->value, 'etiqueta' => $t->etiqueta()], TipoContratacion::seleccionables()),
            'transicionesPermitidas' => $this->transicionesPermitidas(),
        ];
    }

    /**
     * Mapa {estadoOrigen: string[]} de destinos válidos desde el tablero
     * (solo salidas): fuente única para que el kanban no duplique la regla.
     *
     * @return array<string, array<int, string>>
     */
    private function transicionesPermitidas(): array
    {
        $mapa = [];

        foreach (EstadoCandidato::cases() as $origen) {
            $mapa[$origen->value] = array_values(array_map(
                fn (EstadoCandidato $destino) => $destino->value,
                array_filter(EstadoCandidato::cases(), fn (EstadoCandidato $destino) => $origen->puedeTransicionarA($destino)),
            ));
        }

        return $mapa;
    }
}
