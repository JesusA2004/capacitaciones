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
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Módulo de Bajas y Causales en la web (docs/BAJAS_Y_CAUSALES.md):
 * bandeja, solicitud del gerente (4 bloques), expediente de la baja con
 * documentos, bitácora y acciones de RH / Jurídico. Delgado: autoriza con
 * BajaPolicy y delega TODO a BajaService (el mismo de la API v1).
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

        // RH entra a su bandeja «En revisión RH»; Jurídico a «En revisión
        // Jurídico»; el resto ve sus bajas abiertas.
        if (! isset($filtros['estatus'])) {
            $filtros['estatus'] = match (true) {
                $usuario->can(BajaService::PERMISO_GESTIONAR) => EstatusBaja::EnRevisionRh->value,
                $usuario->can(BajaService::PERMISO_JURIDICO) && ! $usuario->can(BajaService::PERMISO_VER) => EstatusBaja::EnRevisionJuridico->value,
                default => 'abiertas',
            };
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
                ...array_map(fn (EstatusBaja $e): array => ['value' => $e->value, 'label' => $e->etiqueta()], EstatusBaja::cases()),
                ['value' => 'todas', 'label' => 'Todas'],
            ],
            'formas' => array_map(fn (FormaSalida $f): array => ['value' => $f->value, 'label' => $f->etiqueta(), 'token' => $f->token()], FormaSalida::cases()),
            'sucursales' => Sucursal::query()->whereIn('id', $this->tablero->sucursalesVisibles($usuario)->all())->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (Sucursal $s): array => ['value' => $s->id, 'label' => $s->nombre])->values()->all(),
            'puede' => [
                'solicitar' => $usuario->can('create', Baja::class),
                'gestionar' => $usuario->can(BajaService::PERMISO_GESTIONAR),
                'tablero' => $usuario->can(BajaService::PERMISO_TABLERO),
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
            'baja' => null,
            'colaborador' => $preseleccion,
            ...$this->opcionesFormulario($request),
        ]);
    }

    public function store(GuardarBajaRequest $request): RedirectResponse
    {
        $this->authorize('create', Baja::class);
        $baja = $this->bajas->guardar(null, $request->datos(), $request->user());

        if ($request->boolean('enviar')) {
            $this->bajas->enviar($baja, $request->user());

            return to_route('rh.bajas.show', $baja)->with('toast', ['type' => 'success', 'message' => sprintf('Baja %s enviada a RH. Los accesos de la persona se suspendieron.', $baja->folio)]);
        }

        return to_route('rh.bajas.edit', $baja)->with('toast', ['type' => 'success', 'message' => sprintf('Borrador %s guardado.', $baja->folio)]);
    }

    public function edit(Request $request, Baja $baja): Response|RedirectResponse
    {
        $this->authorize('update', $baja);

        if (! $baja->estatus->editablePorSolicitante()) {
            return to_route('rh.bajas.show', $baja);
        }

        return Inertia::render('Rh/Bajas/Formulario', [
            'baja' => $this->bajas->formulario($baja),
            'colaborador' => null,
            ...$this->opcionesFormulario($request),
        ]);
    }

    public function update(GuardarBajaRequest $request, Baja $baja): RedirectResponse
    {
        $this->authorize('update', $baja);
        $baja = $this->bajas->guardar($baja, $request->datos(), $request->user());

        if ($request->boolean('enviar')) {
            $this->bajas->enviar($baja, $request->user());

            return to_route('rh.bajas.show', $baja)->with('toast', ['type' => 'success', 'message' => sprintf('Baja %s enviada a RH.', $baja->folio)]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Borrador guardado.']);
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

    public function enviar(Request $request, Baja $baja): RedirectResponse
    {
        $this->authorize('update', $baja);
        $this->bajas->enviar($baja, $request->user());

        return $this->ok('Baja enviada a RH.');
    }

    public function validar(Request $request, Baja $baja): RedirectResponse
    {
        $this->authorize('gestionar', $baja);
        $baja = $this->bajas->validar($baja, $request->user());

        return $this->ok($baja->requiereJuridico() && $baja->juridico_en === null ? 'Baja validada. La causal requiere Jurídico: envíala antes de cerrar.' : 'Baja validada: ya puedes cerrarla cuando estén los documentos.');
    }

    public function enviarJuridico(Request $request, Baja $baja): RedirectResponse
    {
        $this->authorize('gestionar', $baja);
        $this->bajas->enviarJuridico($baja, $request->user());

        return $this->ok('Baja enviada a Jurídico.');
    }

    public function aprobarJuridico(MotivoBajaRequest $request, Baja $baja): RedirectResponse
    {
        $this->authorize('revisarJuridico', $baja);
        $this->bajas->aprobarJuridico($baja, $request->user(), $request->motivo());

        return $this->ok('Revisión de Jurídico registrada: la baja regresa a RH para el cierre.');
    }

    public function rechazar(MotivoBajaRequest $request, Baja $baja): RedirectResponse
    {
        $this->authorize('rechazar', $baja);
        $this->bajas->rechazar($baja, $request->user(), $request->motivo());

        return $this->ok('Baja rechazada: regresa al gerente con el motivo.');
    }

    public function cambiarCausal(MotivoBajaRequest $request, Baja $baja): RedirectResponse
    {
        $this->authorize('gestionar', $baja);
        $this->bajas->cambiarCausal($baja, $request->user(), (int) $request->validated('causal_rotacion_id'), $request->motivo());

        return $this->ok('Causal corregida; el cambio quedó en la bitácora.');
    }

    public function actualizar(ActualizarBajaRhRequest $request, Baja $baja): RedirectResponse
    {
        $this->authorize('gestionar', $baja);
        $this->bajas->actualizarPorRh($baja, $request->user(), $request->datos());

        return $this->ok('Datos de la baja actualizados.');
    }

    public function cerrar(Request $request, Baja $baja): RedirectResponse
    {
        $this->authorize('gestionar', $baja);
        $this->bajas->cerrar($baja, $request->user());

        return $this->ok('Baja cerrada: la persona quedó inactiva y su acceso bloqueado.');
    }

    public function reabrir(MotivoBajaRequest $request, Baja $baja): RedirectResponse
    {
        $this->authorize('gestionar', $baja);
        $this->bajas->reabrir($baja, $request->user(), $request->motivo());

        return $this->ok('Baja reabierta (motivo en la bitácora).');
    }

    public function cancelar(MotivoBajaRequest $request, Baja $baja): RedirectResponse
    {
        $this->authorize('update', $baja);
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
