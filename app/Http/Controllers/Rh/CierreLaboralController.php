<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoCierreLaboral;
use App\Enums\FormaSalida;
use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\ArchivoLaboralRequest;
use App\Http\Requests\CicloLaboral\DecisionAprobacionRequest;
use App\Http\Requests\CicloLaboral\EntregaFiniquitoRequest;
use App\Http\Requests\CicloLaboral\FiniquitoCierreRequest;
use App\Http\Requests\CicloLaboral\ProgramarPagoRequest;
use App\Http\Requests\CicloLaboral\SolicitarCierreRequest;
use App\Http\Requests\Rh\AjustarConceptoFiniquitoRequest;
use App\Models\CausalRotacion;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\FiniquitoConcepto;
use App\Services\CierreLaboral\CausalRotacionService;
use App\Services\CierreLaboral\CierreLaboralService;
use App\Services\Finiquitos\FiniquitoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Cierre laboral (Etapa 6) en la web. Delgado: autoriza el objeto con la
 * Policy y delega cada paso a CierreLaboralService (el mismo de la API).
 */
class CierreLaboralController extends Controller
{
    public function __construct(
        private readonly CierreLaboralService $cierres,
        private readonly FiniquitoService $finiquitos,
        private readonly CausalRotacionService $causales,
    ) {}

    /**
     * Panorama de bajas y finiquitos (índice web, grupo «7 · Cierres
     * laborales»). Lista los cierres dentro del alcance con el estado del
     * cierre y del finiquito; la acción se ejecuta en la ficha per-persona.
     * NO expone acciones del flujo legacy (preautorizar / autorizar baja /
     * programar pago / cita / autorizar finiquito): el panorama es de lectura.
     */
    public function index(Request $request): InertiaResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('cierres.ver'), 403);

        $puedeVerFiniquito = $usuario->can('finiquitos.ver');
        $listado = $this->cierres
            ->panorama($usuario, $request->only(['estado', 'busqueda', 'per_page']))
            ->withQueryString();
        $finiquitos = $this->cierres->finiquitosDeCierres($listado->getCollection());

        $listado->getCollection()->transform(fn (CierreLaboral $c): array => $this->cierres->filaPanorama(
            $c,
            $c->solicitud_interna_id !== null ? $finiquitos->get($c->solicitud_interna_id) : null,
            $puedeVerFiniquito,
        ));

        return Inertia::render('Rh/Cierres/Index', [
            'cierres' => $listado,
            'filtros' => [
                'estado' => $request->query('estado'),
                'busqueda' => (string) $request->query('busqueda', ''),
            ],
            'estados' => array_map(fn (EstadoCierreLaboral $e): array => ['value' => $e->value, 'label' => $e->etiqueta()], EstadoCierreLaboral::cases()),
            'puede_ver_finiquito' => $puedeVerFiniquito,
            // Pestaña «Causales de rotación» (subcatálogo del tipo de baja):
            // solo para quien la administra.
            'causales' => $usuario->can('administrar', CausalRotacion::class) ? $this->causales->listado() : null,
            // Formas de salida en su orden (RV, NR, RE) con el tipo de baja equivalente.
            'tipos_baja' => array_map(fn (FormaSalida $f): array => ['value' => $f->tipoBaja()->value, 'label' => $f->etiqueta(), 'forma' => $f->value, 'token' => $f->token()], FormaSalida::cases()),
            'seccion' => $request->query('seccion') === 'causales' ? 'causales' : 'bajas',
        ]);
    }

    public function store(SolicitarCierreRequest $request, Colaborador $colaborador): RedirectResponse
    {
        $this->authorize('iniciarCierre', $colaborador);
        $cierre = $this->cierres->solicitar($colaborador, $request->validated(), $request->user());
        $folio = $cierre->baja()->value('folio');

        return $this->ok(sprintf('Baja aplicada%s: sus accesos ya se suspendieron y quedó «En revisión RH». Siguen los documentos de la baja y el finiquito.', is_string($folio) ? sprintf(' (%s)', $folio) : ''));
    }

    public function aviso(ArchivoLaboralRequest $request, CierreLaboral $cierre): RedirectResponse
    {
        $this->authorize('operar', $cierre);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);
        $this->cierres->registrarAviso($cierre, $archivo, $request->user());

        return $this->ok('Renuncia / aviso registrado.');
    }

    public function calcularFiniquito(FiniquitoCierreRequest $request, CierreLaboral $cierre): RedirectResponse
    {
        $this->authorize('calcularFiniquito', $cierre);
        $finiquito = $this->cierres->calcularFiniquito($cierre, $request->user(), $request->sueldoMensual(), $request->sueldoPendiente());

        return $this->ok(sprintf('Finiquito calculado: neto $%s. Revísalo y ajusta los montos si hace falta.', number_format((float) $finiquito->neto, 2)));
    }

    /**
     * Desglose editable del finiquito dentro del cierre (mismo
     * FiniquitoService que la API): ajuste de un concepto automático con
     * motivo, o renglón propio de RH (percepción/deducción). El total lo
     * recalcula siempre el servidor.
     */
    public function ajustarConceptoFiniquito(AjustarConceptoFiniquitoRequest $request, CierreLaboral $cierre): RedirectResponse
    {
        $this->authorize('calcularFiniquito', $cierre);
        $this->finiquitos->ajustarConceptoAutomatico($this->cierres->exigirFiniquito($cierre), (string) $request->validated('concepto_clave'), (float) $request->validated('importe'), (string) $request->validated('motivo'), $request->user());

        return $this->ok('Monto ajustado; el total del finiquito se recalculó.');
    }

    public function agregarConceptoFiniquito(FiniquitoCierreRequest $request, CierreLaboral $cierre): RedirectResponse
    {
        $this->authorize('calcularFiniquito', $cierre);
        $request->validate(['tipo' => ['required'], 'concepto' => ['required'], 'importe' => ['required'], 'observaciones' => ['required']], ['observaciones.required' => 'Indica el motivo del renglón.']);
        $this->finiquitos->agregarConcepto($this->cierres->exigirFiniquito($cierre), $request->safe()->only(['tipo', 'concepto', 'cantidad', 'importe', 'observaciones']), $request->user());

        return $this->ok('Renglón agregado; el total del finiquito se recalculó.');
    }

    public function actualizarConceptoFiniquito(FiniquitoCierreRequest $request, CierreLaboral $cierre, FiniquitoConcepto $concepto): RedirectResponse
    {
        $this->authorize('calcularFiniquito', $cierre);
        abort_unless($concepto->finiquito_calculo_id === $this->cierres->exigirFiniquito($cierre)->id, 404);
        $this->finiquitos->actualizarConcepto($concepto, $request->safe()->only(['tipo', 'concepto', 'cantidad', 'importe', 'observaciones']), $request->user());

        return $this->ok('Renglón actualizado; el total del finiquito se recalculó.');
    }

    public function eliminarConceptoFiniquito(Request $request, CierreLaboral $cierre, FiniquitoConcepto $concepto): RedirectResponse
    {
        $this->authorize('calcularFiniquito', $cierre);
        abort_unless($concepto->finiquito_calculo_id === $this->cierres->exigirFiniquito($cierre)->id, 404);
        $this->finiquitos->eliminarConcepto($concepto, $request->user());

        return $this->ok('Renglón eliminado; el total del finiquito se recalculó.');
    }

    /**
     * PDF del finiquito con los montos actuales (no guarda nada).
     */
    public function vistaPreviaFiniquito(CierreLaboral $cierre): Response
    {
        $this->authorize('calcularFiniquito', $cierre);

        return response($this->finiquitos->vistaPreviaPdf($this->cierres->exigirFiniquito($cierre)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="finiquito-vista-previa.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * «Registrar entrega de finiquito»: congela el finiquito y cierra el
     * cierre laboral (no hay paso de autorizar finiquito).
     */
    public function entregarFiniquito(EntregaFiniquitoRequest $request, CierreLaboral $cierre): RedirectResponse
    {
        $this->authorize('entregarFiniquito', $cierre);
        $this->cierres->registrarEntregaFiniquito($cierre, $request->user(), $request->datosEntrega());

        return $this->ok('Finiquito entregado: el cierre laboral quedó completo. El expediente y el historial se conservan.');
    }

    public function programarPago(ProgramarPagoRequest $request, CierreLaboral $cierre): RedirectResponse
    {
        $this->authorize('ver', $cierre);
        $this->cierres->programarPago($cierre, $request->user(), $request->datosPago());

        return $this->ok('Pago programado: el gerente cita al excolaborador.');
    }

    public function cita(Request $request, CierreLaboral $cierre): RedirectResponse
    {
        $this->authorize('operar', $cierre);
        $datos = $request->validate(['fecha' => ['required', 'date']]);
        $this->cierres->registrarCita($cierre, $request->user(), (string) $datos['fecha']);

        return $this->ok('Cita registrada.');
    }

    public function finiquitoFirmado(ArchivoLaboralRequest $request, CierreLaboral $cierre): RedirectResponse
    {
        $this->authorize('operar', $cierre);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);
        $this->cierres->registrarFiniquitoFirmado($cierre, $archivo, $request->user());

        return $this->ok('Finiquito firmado registrado.');
    }

    public function confirmarPago(Request $request, CierreLaboral $cierre): RedirectResponse
    {
        $this->authorize('confirmarPago', $cierre);
        $datos = $request->validate(['referencia' => ['required', 'string', 'max:120']]);
        $this->cierres->confirmarPago($cierre, $request->user(), (string) $datos['referencia']);

        return $this->ok('Pago confirmado.');
    }

    public function cerrar(Request $request, CierreLaboral $cierre): RedirectResponse
    {
        $this->authorize('ejecutarBaja', $cierre);
        $this->cierres->cerrar($cierre, $request->user());

        return $this->ok('Cierre laboral completo. El expediente y el historial se conservan.');
    }

    public function cancelar(DecisionAprobacionRequest $request, CierreLaboral $cierre): RedirectResponse
    {
        $this->authorize('gestionar', $cierre);
        $this->cierres->cancelar($cierre, $request->user(), $request->motivo());

        return $this->ok('Cierre cancelado.');
    }

    private function ok(string $mensaje): RedirectResponse
    {
        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }
}
