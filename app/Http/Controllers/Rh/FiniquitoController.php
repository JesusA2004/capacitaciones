<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\ActualizarAjustesFiniquitoRequest;
use App\Http\Requests\Rh\CalcularFiniquitoRequest;
use App\Http\Requests\Rh\SubirFiniquitoFirmadoRequest;
use App\Models\FiniquitoCalculo;
use App\Models\SolicitudInterna;
use App\Services\Finiquitos\FiniquitoService;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FiniquitoController extends Controller
{
    public function __construct(private readonly FiniquitoService $finiquitos) {}

    public function calcular(CalcularFiniquitoRequest $request, SolicitudInterna $solicitud): RedirectResponse
    {
        $this->authorize('calcular', [FiniquitoCalculo::class, $solicitud]);

        $this->finiquitos->calcular($solicitud, $request->user(), (float) $request->validated('sueldo_mensual'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Finiquito calculado.']);
    }

    public function recalcular(CalcularFiniquitoRequest $request, SolicitudInterna $solicitud): RedirectResponse
    {
        $finiquito = $this->finiquitoDe($solicitud);
        $this->authorize('editarAjustes', $finiquito);

        $this->finiquitos->recalcular($finiquito, $request->user(), (float) $request->validated('sueldo_mensual'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Finiquito recalculado.']);
    }

    public function actualizarAjustes(ActualizarAjustesFiniquitoRequest $request, SolicitudInterna $solicitud): RedirectResponse
    {
        $finiquito = $this->finiquitoDe($solicitud);
        $this->authorize('editarAjustes', $finiquito);

        $this->finiquitos->actualizarAjustes($finiquito, $request->user(), $request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Ajustes del finiquito guardados.']);
    }

    public function revisar(SolicitudInterna $solicitud): RedirectResponse
    {
        $finiquito = $this->finiquitoDe($solicitud);
        $this->authorize('revisar', $finiquito);

        $this->finiquitos->aprobarCalculo($finiquito, request()->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Finiquito revisado: la baja ya puede aprobarse.']);
    }

    public function generarPdf(SolicitudInterna $solicitud): RedirectResponse
    {
        $finiquito = $this->finiquitoDe($solicitud);
        $this->authorize('ver', $finiquito);

        $this->finiquitos->generarPdf($finiquito);

        return back()->with('toast', ['type' => 'success', 'message' => 'PDF de finiquito generado.']);
    }

    public function descargarPdf(SolicitudInterna $solicitud): StreamedResponse
    {
        $finiquito = $this->finiquitoDe($solicitud);
        $this->authorize('ver', $finiquito);

        return $this->finiquitos->descargarPdf($finiquito);
    }

    public function subirFirmado(SubirFiniquitoFirmadoRequest $request, SolicitudInterna $solicitud): RedirectResponse
    {
        $finiquito = $this->finiquitoDe($solicitud);
        $this->authorize('subirFirmado', $finiquito);

        $this->finiquitos->subirFirmado($finiquito, $request->file('archivo'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Documento firmado guardado.']);
    }

    private function finiquitoDe(SolicitudInterna $solicitud): FiniquitoCalculo
    {
        $finiquito = $solicitud->finiquitoCalculo;

        abort_if($finiquito === null, 404, 'Esta baja todavía no tiene un cálculo de finiquito.');

        return $finiquito;
    }
}
