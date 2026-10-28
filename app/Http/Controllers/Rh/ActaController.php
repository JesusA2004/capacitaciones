<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoActa;
use App\Enums\TipoActa;
use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\ArchivoLaboralRequest;
use App\Http\Requests\CicloLaboral\GuardarActaRequest;
use App\Models\ActaAdministrativa;
use App\Models\ActaAnexo;
use App\Models\Colaborador;
use App\Services\Actas\ActaPdfService;
use App\Services\Actas\ActaService;
use App\Services\DocumentosMaestros\DocumentosMaestrosAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Actas administrativas (PRO-ADP-08) en la web: levantar el acta (motivo,
 * hechos, testigos, manifestaciones), generar el formato oficial, firma o
 * negativa, anexos, seguimiento y cierre. Mismo ActaService que la API
 * móvil (Api\V1\Rh\ActaController); el formato sigue el flujo de
 * «Documentos del proceso» (tipo `acta`).
 */
class ActaController extends Controller
{
    public function __construct(
        private readonly ActaService $actas,
        private readonly DocumentosMaestrosAdminService $admin,
        private readonly ActaPdfService $pdf,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('actas.ver'), 403);
        $filtros = $request->only(['tipo', 'estado', 'colaborador_id', 'per_page']);

        return Inertia::render('Rh/Actas/Index', [
            'actas' => $this->actas->listar($request->user(), $filtros)
                ->through(fn (ActaAdministrativa $a): array => $this->actas->filaIndice($a, $request->user())),
            'filtros' => $filtros,
            'tipos' => array_map(fn (TipoActa $t): array => ['value' => $t->value, 'label' => $t->etiqueta()], TipoActa::cases()),
            'estados' => array_map(fn (EstadoActa $e): array => ['value' => $e->value, 'label' => $e->etiqueta()], EstadoActa::cases()),
            'puedeCrear' => $request->user()->can('actas.crear'),
            'catalogos' => ActaService::catalogos(),
        ]);
    }

    /** Buscador del diálogo «Levantar acta» (mismo criterio y alcance que el resto). */
    public function buscarColaboradores(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('actas.crear'), 403);
        $datos = $request->validate(['q' => ['nullable', 'string', 'max:80']]);

        return response()->json(['data' => $this->admin->buscarColaboradores($request->user(), (string) ($datos['q'] ?? ''))]);
    }

    /** Testigos: activos de su alcance, los de su sucursal primero (ActaService::buscarTestigos). */
    public function buscarTestigos(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('actas.crear'), 403);
        $datos = $request->validate(['q' => ['nullable', 'string', 'max:80'], 'excluir' => ['nullable', 'integer']]);

        return response()->json(['data' => $this->actas->buscarTestigos($request->user(), (string) ($datos['q'] ?? ''), isset($datos['excluir']) ? (int) $datos['excluir'] : null)]);
    }

    /**
     * Vista previa del PDF REAL del acta (mismo servicio y datos que el
     * final). No guarda nada.
     */
    public function vistaPrevia(Request $request, ActaAdministrativa $acta): HttpResponse
    {
        $this->authorize('descargar', $acta);
        abort_unless(ActaPdfService::aplica($acta), 404);

        if (($motivo = $this->pdf->faltante($acta)) !== null) {
            abort(409, $motivo);
        }

        return response($this->pdf->vistaPrevia($acta), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="Vista previa %s.pdf"', $acta->folio),
            'Cache-Control' => 'no-store',
        ]);
    }

    public function store(GuardarActaRequest $request, Colaborador $colaborador): RedirectResponse
    {
        $this->authorize('crearActa', $colaborador);
        $acta = $this->actas->crear($colaborador, $request->validated(), $request->user());

        // El borrador sigue en «Participantes» (paso 3 de la captura).
        return to_route('rh.actas.show', ['acta' => $acta, 'paso' => 3])->with('toast', ['type' => 'success', 'message' => sprintf('Borrador %s guardado. Continúa con los participantes.', $acta->folio)]);
    }

    public function show(Request $request, ActaAdministrativa $acta): Response
    {
        $this->authorize('ver', $acta);

        return Inertia::render('Rh/Actas/Show', [
            'acta' => $this->actas->aArray($acta, true),
            'puedeGestionar' => $request->user()->can('gestionar', $acta),
            // Acción por acción (misma policy que cada endpoint).
            'permisos' => $this->actas->permisosDe($request->user(), $acta),
            // Qué falta para poder cerrarla (null = se puede cerrar).
            'faltaParaCerrar' => $acta->estado->esEditable() ? null : $this->actas->faltanteParaCerrar($acta),
            'puedeRegistrarEntrega' => $request->user()->can('registrarEntrega', $acta),
            'catalogos' => ActaService::catalogos(),
        ]);
    }

    public function update(GuardarActaRequest $request, ActaAdministrativa $acta): RedirectResponse
    {
        $this->authorize('editar', $acta);
        $this->actas->actualizar($acta, $request->validated(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Acta actualizada.']);
    }

    public function anexo(ArchivoLaboralRequest $request, ActaAdministrativa $acta): RedirectResponse
    {
        $this->authorize('subirAnexo', $acta);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);
        $extra = $request->validate([
            'fecha_identificacion' => ['nullable', 'string', 'max:160'],
            'relacion_hechos' => ['nullable', 'string', 'max:500'],
        ]);
        $this->actas->agregarAnexo($acta, $archivo, $request->validated('descripcion'), $request->user(), $extra['fecha_identificacion'] ?? null, $extra['relacion_hechos'] ?? null);

        return back()->with('toast', ['type' => 'success', 'message' => 'Anexo guardado en el expediente.']);
    }

    /** Constancia de entrega de copia (sección XII del acta circunstanciada). */
    public function entrega(Request $request, ActaAdministrativa $acta): RedirectResponse
    {
        // RH o quien levantó el acta (la entrega ocurre después de imprimirla).
        abort_unless($request->user()->can('registrarEntrega', $acta), 403, 'Solo RH o quien levantó el acta registran la entrega de la copia.');
        $datos = $request->validate([
            'estado' => ['required', 'in:entregada,no_posible'],
            'motivo' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->actas->registrarEntrega($acta, $datos['estado'], $datos['motivo'] ?? null, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Constancia de entrega registrada.']);
    }

    public function descargarAnexo(ActaAdministrativa $acta, ActaAnexo $anexo): StreamedResponse
    {
        $this->authorize('descargarAnexo', $acta);

        return $this->actas->descargarAnexo($acta, $anexo);
    }

    public function negativaFirma(Request $request, ActaAdministrativa $acta): RedirectResponse
    {
        $this->authorize('registrarFirma', $acta);
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:1000']], ['motivo.required' => 'Describe cómo se negó el colaborador a firmar.']);
        $this->actas->registrarNegativaFirma($acta, $datos['motivo'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Negativa a firmar registrada.']);
    }

    public function seguimiento(Request $request, ActaAdministrativa $acta): RedirectResponse
    {
        $this->authorize('seguimiento', $acta);
        $datos = $request->validate(['nota' => ['required', 'string', 'max:2000']], ['nota.required' => 'Escribe la nota de seguimiento.']);
        $this->actas->agregarSeguimiento($acta, $datos['nota'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Seguimiento registrado.']);
    }

    public function cerrar(Request $request, ActaAdministrativa $acta): RedirectResponse
    {
        $this->authorize('cerrar', $acta);
        $this->actas->cerrar($acta, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Acta cerrada.']);
    }
}
