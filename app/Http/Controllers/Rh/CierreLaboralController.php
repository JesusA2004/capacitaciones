<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\ArchivoLaboralRequest;
use App\Http\Requests\CicloLaboral\DecisionAprobacionRequest;
use App\Http\Requests\CicloLaboral\ProgramarPagoRequest;
use App\Http\Requests\CicloLaboral\SolicitarCierreRequest;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Services\CierreLaboral\CierreLaboralService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Cierre laboral (Etapa 6) en la web. Delgado: autoriza el objeto con la
 * Policy y delega cada paso a CierreLaboralService (el mismo de la API).
 */
class CierreLaboralController extends Controller
{
    public function __construct(private readonly CierreLaboralService $cierres) {}

    public function store(SolicitarCierreRequest $request, Colaborador $colaborador): RedirectResponse
    {
        $this->authorize('iniciarCierre', $colaborador);
        $cierre = $this->cierres->solicitar($colaborador, $request->safe()->except('evidencias'), $request->user(), array_values($request->file('evidencias', [])));

        return $this->ok($cierre->estado->value === 'pendiente_rh' ? 'Baja solicitada: pendiente la autorización final de RH.' : 'Baja solicitada: pendiente la preautorización del superior.');
    }

    public function preautorizar(DecisionAprobacionRequest $request, CierreLaboral $cierre): RedirectResponse
    {
        $this->authorize('ver', $cierre);
        $this->cierres->preautorizar($cierre, $request->user(), $request->comentario());

        return $this->ok('Baja preautorizada: pendiente la autorización final de RH.');
    }

    public function autorizar(DecisionAprobacionRequest $request, CierreLaboral $cierre): RedirectResponse
    {
        $this->authorize('ver', $cierre);
        $this->cierres->autorizarRh($cierre, $request->user(), $request->comentario());

        return $this->ok('Baja autorizada por RH. Sigue el finiquito.');
    }

    public function rechazar(DecisionAprobacionRequest $request, CierreLaboral $cierre): RedirectResponse
    {
        $this->authorize('ver', $cierre);
        $this->cierres->rechazar($cierre, $request->user(), $request->motivo());

        return $this->ok('Baja rechazada.');
    }

    public function devolver(DecisionAprobacionRequest $request, CierreLaboral $cierre): RedirectResponse
    {
        $this->authorize('ver', $cierre);
        $this->cierres->devolver($cierre, $request->user(), $request->motivo());

        return $this->ok('Baja devuelta para corrección.');
    }

    public function aviso(ArchivoLaboralRequest $request, CierreLaboral $cierre): RedirectResponse
    {
        $this->authorize('operar', $cierre);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);
        $this->cierres->registrarAviso($cierre, $archivo, $request->user());

        return $this->ok('Renuncia / aviso registrado.');
    }

    public function calcularFiniquito(Request $request, CierreLaboral $cierre): RedirectResponse
    {
        $this->authorize('calcularFiniquito', $cierre);
        $datos = $request->validate(['sueldo_mensual' => ['required', 'numeric', 'min:0'], 'sueldo_pendiente' => ['nullable', 'numeric', 'min:0']]);
        $this->cierres->calcularFiniquito($cierre, $request->user(), (float) $datos['sueldo_mensual'], (float) ($datos['sueldo_pendiente'] ?? 0));

        return $this->ok('Finiquito calculado: revísalo y autorízalo.');
    }

    public function autorizarFiniquito(Request $request, CierreLaboral $cierre): RedirectResponse
    {
        $this->authorize('revisarFiniquito', $cierre);
        $this->cierres->autorizarFiniquito($cierre, $request->user());

        return $this->ok('Finiquito autorizado: el regional de coordinación programa el pago.');
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
