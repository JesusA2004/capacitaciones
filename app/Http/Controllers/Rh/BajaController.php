<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EntregaAvisoRescision;
use App\Enums\EstatusBaja;
use App\Enums\FormaSalida;
use App\Enums\Recontratable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bajas\ActualizarBajaRhRequest;
use App\Http\Requests\Bajas\AdjuntarDocumentoBajaRequest;
use App\Http\Requests\Bajas\GuardarBajaRequest;
use App\Http\Requests\Bajas\MotivoBajaRequest;
use App\Models\Baja;
use App\Models\BajaDocumento;
use App\Models\Colaborador;
use App\Models\DocumentoRequeridoBaja;
use App\Models\Sucursal;
use App\Services\Bajas\BajaConsultaService;
use App\Services\Bajas\BajaDocumentosService;
use App\Services\Bajas\BajaService;
use App\Services\Bajas\CatalogoBajasService;
use App\Services\Bajas\TableroRotacionService;
use App\Services\CierreLaboral\CausalRotacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Módulo «Bajas» en la web (docs/BAJAS_Y_CAUSALES.md): bandeja, registro
 * DIRECTO de la baja (gerente de su personal o RH) con vista previa de los
 * documentos y del finiquito, expediente de la baja con documentos,
 * bitácora y acciones de RH / Jurídico. Delgado: autoriza con BajaPolicy y
 * delega TODO a BajaService (el mismo de la API v1).
 */
class BajaController extends Controller
{
    public function __construct(
        private readonly BajaService $bajas,
        private readonly BajaConsultaService $consulta,
        private readonly BajaDocumentosService $documentos,
        private readonly CatalogoBajasService $catalogo,
        private readonly CausalRotacionService $causales,
        private readonly TableroRotacionService $tablero,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Baja::class);
        $usuario = $request->user();
        $filtros = $request->only(['estatus', 'sucursal_id', 'forma', 'busqueda', 'per_page']);

        // Jurídico entra a «En revisión Jurídico»; RH y gerentes a las bajas
        // abiertas (en trámite + en Jurídico).
        if (! isset($filtros['estatus'])) {
            $filtros['estatus'] = $usuario->can(BajaService::PERMISO_JURIDICO) && ! $usuario->can(BajaService::PERMISO_VER)
                ? EstatusBaja::EnRevisionJuridico->value
                : 'abiertas';
        }

        return Inertia::render('Rh/Bajas/Index', [
            'bajas' => $this->consulta->bandeja($usuario, $filtros),
            'filtros' => [
                'estatus' => (string) $filtros['estatus'],
                'sucursal_id' => is_numeric($filtros['sucursal_id'] ?? null) ? (int) $filtros['sucursal_id'] : null,
                'forma' => is_string($filtros['forma'] ?? null) ? $filtros['forma'] : null,
                'busqueda' => (string) ($filtros['busqueda'] ?? ''),
            ],
            'estatus' => [
                ['value' => 'abiertas', 'label' => 'Abiertas'],
                ...array_map(fn (EstatusBaja $e): array => ['value' => $e->value, 'label' => $e->etiqueta()], EstatusBaja::vigentes()),
                ['value' => 'todas', 'label' => 'Todas'],
            ],
            'formas' => array_map(fn (FormaSalida $f): array => ['value' => $f->value, 'label' => $f->etiqueta(), 'token' => $f->token()], FormaSalida::cases()),
            'sucursales' => Sucursal::query()->whereIn('id', $this->tablero->sucursalesVisibles($usuario)->all())->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (Sucursal $s): array => ['value' => $s->id, 'label' => $s->nombre])->values()->all(),
            'resumen' => $this->consulta->resumen($usuario),
            'puede' => [
                'solicitar' => $usuario->can('create', Baja::class),
                'gestionar' => $usuario->can(BajaService::PERMISO_GESTIONAR),
                'tablero' => $usuario->can(BajaService::PERMISO_TABLERO),
                'recopilacion' => $usuario->can('cierres.ver'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Baja::class);
        $preseleccion = null;

        if (is_numeric($request->query('colaborador'))) {
            $colaborador = Colaborador::query()->with(['sucursalPrincipal:id,nombre', 'puesto:id,nombre'])->where('id', (int) $request->query('colaborador'))->first();

            if ($colaborador !== null && $this->bajas->puedeSolicitarPara($request->user(), $colaborador)) {
                $preseleccion = [
                    'id' => $colaborador->id,
                    'nombre' => $colaborador->nombreCompleto(),
                    'numero_empleado' => $colaborador->numero_empleado,
                    'puesto' => $colaborador->puesto?->nombre,
                    'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                    'fecha_ingreso' => $colaborador->fecha_ingreso?->toDateString(),
                ];
            }
        }

        return Inertia::render('Rh/Bajas/Formulario', [
            'colaborador' => $preseleccion,
            ...$this->opcionesFormulario($request),
        ]);
    }

    /**
     * Registra la baja y la aplica de inmediato (cierre laboral, acceso
     * suspendido, documentos generados, finiquito propuesto). Desde el ciclo
     * laboral (`volver=ciclo`) regresa a esa pantalla.
     */
    public function store(GuardarBajaRequest $request): RedirectResponse
    {
        $this->authorize('create', Baja::class);
        $baja = $this->bajas->registrar($request->datos(), $request->user());
        $mensaje = sprintf('Baja %s registrada: los accesos de %s se suspendieron y sus documentos quedaron generados.', $baja->folio, $baja->nombre_colaborador);

        if ($request->input('volver') === 'ciclo') {
            return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
        }

        return to_route('rh.bajas.show', $baja)->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }

    /**
     * Vista previa (PDF inline, no-store) del finiquito que se propondrá al
     * registrar la baja con estos datos. No guarda nada.
     */
    public function previaFiniquito(GuardarBajaRequest $request): HttpResponse
    {
        $this->authorize('vistaPreviaFiniquito', Baja::class);

        return response($this->bajas->vistaPreviaFiniquito($request->datos(), $request->user()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Vista previa - Finiquito.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function show(Request $request, Baja $baja): Response
    {
        $this->authorize('view', $baja);
        $usuario = $request->user();

        return Inertia::render('Rh/Bajas/Show', [
            'baja' => $this->bajas->detalle($baja, $usuario),
            'causales' => $usuario->can(BajaService::PERMISO_GESTIONAR) ? $this->catalogo->opciones() : [],
            'recontratable' => array_map(fn (Recontratable $r): array => ['value' => $r->value, 'label' => $r->etiqueta()], Recontratable::cases()),
            'entregas' => array_map(fn (EntregaAvisoRescision $e): array => ['value' => $e->value, 'label' => $e->etiqueta()], EntregaAvisoRescision::cases()),
            'limites' => $this->limites(),
        ]);
    }

    public function enviarJuridico(Request $request, Baja $baja): RedirectResponse
    {
        $this->authorize('enviarJuridico', $baja);
        $this->bajas->enviarJuridico($baja, $request->user());

        return $this->ok('Baja enviada a Jurídico.');
    }

    public function aprobarJuridico(MotivoBajaRequest $request, Baja $baja): RedirectResponse
    {
        $this->authorize('revisarJuridico', $baja);
        $this->bajas->aprobarJuridico($baja, $request->user(), $request->motivo());

        return $this->ok('Revisión de Jurídico registrada: la baja regresa a RH para el cierre.');
    }

    public function devolverJuridico(MotivoBajaRequest $request, Baja $baja): RedirectResponse
    {
        $this->authorize('revisarJuridico', $baja);
        $this->bajas->devolverJuridico($baja, $request->user(), $request->motivo());

        return $this->ok('Baja devuelta a RH con tus observaciones.');
    }

    public function cambiarCausal(MotivoBajaRequest $request, Baja $baja): RedirectResponse
    {
        $this->authorize('cambiarCausal', $baja);
        $this->bajas->cambiarCausal($baja, $request->user(), (int) $request->validated('causal_rotacion_id'), $request->motivo());

        return $this->ok('Causal corregida; el cambio quedó en la bitácora.');
    }

    public function actualizar(ActualizarBajaRhRequest $request, Baja $baja): RedirectResponse
    {
        $this->authorize('editar', $baja);
        $this->bajas->actualizarPorRh($baja, $request->user(), $request->datos());

        return $this->ok('Datos de la baja actualizados.');
    }

    public function cerrar(Request $request, Baja $baja): RedirectResponse
    {
        $this->authorize('cerrar', $baja);
        $this->bajas->cerrar($baja, $request->user());

        return $this->ok('Baja cerrada: la persona quedó inactiva y su acceso bloqueado.');
    }

    public function reabrir(MotivoBajaRequest $request, Baja $baja): RedirectResponse
    {
        $this->authorize('reabrir', $baja);
        $this->bajas->reabrir($baja, $request->user(), $request->motivo());

        return $this->ok('Baja reabierta (motivo en la bitácora).');
    }

    public function cancelar(MotivoBajaRequest $request, Baja $baja): RedirectResponse
    {
        $this->authorize('cancelar', $baja);
        $this->bajas->cancelar($baja, $request->user(), $request->motivo());

        return $this->ok('La baja quedó como «No procede».');
    }

    public function adjuntar(AdjuntarDocumentoBajaRequest $request, Baja $baja): RedirectResponse
    {
        $this->authorize('view', $baja);
        abort_unless(in_array('adjuntar', $this->bajas->acciones($baja, $request->user()), true), 403, 'No puedes adjuntar documentos a esta baja.');
        $requerido = is_numeric($request->validated('documento_requerido_id')) ? DocumentoRequeridoBaja::query()->where('id', (int) $request->validated('documento_requerido_id'))->first() : null;
        $archivo = $request->file('archivo');
        abort_if($archivo === null || is_array($archivo), 422, 'Elige un archivo.');
        $this->documentos->adjuntar($baja, $requerido, $archivo, $request->user());

        return $this->ok('Documento adjunto.');
    }

    public function quitarDocumento(Request $request, Baja $baja, BajaDocumento $documento): RedirectResponse
    {
        $this->authorize('view', $baja);
        abort_unless($documento->baja_id === $baja->id, 404);
        abort_unless(in_array('adjuntar', $this->bajas->acciones($baja, $request->user()), true), 403, 'No puedes modificar los documentos de esta baja.');
        $this->documentos->quitar($documento, $request->user());

        return $this->ok('Documento retirado de la baja (queda en la bitácora).');
    }

    public function verDocumento(Request $request, Baja $baja, BajaDocumento $documento): StreamedResponse
    {
        $this->authorize('view', $baja);
        abort_unless($documento->baja_id === $baja->id, 404);

        return $this->documentos->respuesta($documento, $request->boolean('descargar'));
    }

    public function colaboradores(Request $request): JsonResponse
    {
        $this->authorize('create', Baja::class);

        return response()->json(['data' => $this->consulta->buscarColaboradores($request->user(), (string) $request->query('q', ''))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function opcionesFormulario(Request $request): array
    {
        return [
            'formas' => $this->catalogo->opciones($this->causales->formasPermitidas($request->user())),
            'documentos_catalogo' => DocumentoRequeridoBaja::query()->where('activo', true)->orderBy('orden')->get()
                ->map(fn (DocumentoRequeridoBaja $d): array => [
                    'id' => $d->id,
                    'nombre' => $d->nombre,
                    'forma' => $d->forma_salida?->value,
                    'causal_id' => $d->causal_rotacion_id,
                    'obligatorio' => $d->obligatorio_para_cerrar,
                    'sistema' => $d->documento_sistema,
                ])->values()->all(),
            'recontratable' => array_map(fn (Recontratable $r): array => ['value' => $r->value, 'label' => $r->etiqueta()], Recontratable::cases()),
            'entregas' => array_map(fn (EntregaAvisoRescision $e): array => ['value' => $e->value, 'label' => $e->etiqueta()], EntregaAvisoRescision::cases()),
            'es_rh' => $request->user()->can(BajaService::PERMISO_GESTIONAR),
            'limites' => $this->limites(),
        ];
    }

    /**
     * @return array<string, int|list<string>>
     */
    private function limites(): array
    {
        return [
            'comentario_minimo' => (int) config('bajas.reglas.comentario_minimo', 20),
            'comentario_maximo' => (int) config('bajas.reglas.comentario_maximo', 500),
            'dias_futuro_maximo' => (int) config('bajas.reglas.dias_futuro_maximo', 15),
            'dias_prescripcion' => (int) config('bajas.reglas.dias_prescripcion_rescision', 30),
            'dias_habiles_tribunal' => (int) config('bajas.reglas.dias_habiles_aviso_tribunal', 5),
            'max_kb' => (int) config('bajas.documentos.max_kb', 15360),
            'mimes' => array_values(array_map('strval', (array) config('bajas.documentos.mimes', []))),
        ];
    }

    private function ok(string $mensaje): RedirectResponse
    {
        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }
}
