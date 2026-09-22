<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Api\V1\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\AutorizarPrestamoRequest;
use App\Http\Requests\CicloLaboral\DecisionRequest;
use App\Models\Prestamo;
use App\Models\SolicitudInterna;
use App\Services\Nomina\PrestamoAutorizacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Préstamos personales para RH/Dirección: autorización con monto y plazo
 * (tras el visto bueno del jefe), contrato + pagaré y resguardo. El saldo es
 * informativo: el sistema no ejecuta descuentos de nómina.
 */
class PrestamoController extends Controller
{
    use RespondePaginado;

    public function __construct(private readonly PrestamoAutorizacionService $prestamos) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('prestamos.ver'), 403);

        return $this->paginado(
            $this->prestamos->listar($request->user(), $request->only(['estado', 'colaborador_id', 'per_page'])),
            fn (Prestamo $p) => $this->prestamos->aArray($p),
        );
    }

    public function show(Prestamo $prestamo): JsonResponse
    {
        $this->authorize('ver', $prestamo);

        return response()->json(['data' => $this->prestamos->aArray($prestamo, true)]);
    }

    public function autorizar(AutorizarPrestamoRequest $request, SolicitudInterna $solicitud): JsonResponse
    {
        $this->authorize('autorizarSolicitud', [Prestamo::class, $solicitud]);

        $prestamo = $this->prestamos->autorizar($solicitud, $request->validated(), $request->user());

        return response()->json(['data' => $this->prestamos->aArray($prestamo, true)], 201);
    }

    public function rechazar(DecisionRequest $request, SolicitudInterna $solicitud): JsonResponse
    {
        $this->authorize('autorizarSolicitud', [Prestamo::class, $solicitud]);
        $request->validate(['motivo' => ['required']]);

        $solicitud = $this->prestamos->rechazar($solicitud, $request->user(), (string) $request->validated('motivo'));

        return response()->json(['data' => ['solicitud_id' => $solicitud->id, 'estado' => $solicitud->estado->value]]);
    }

    public function generarDocumentos(Request $request, Prestamo $prestamo): JsonResponse
    {
        $this->authorize('gestionar', $prestamo);

        return response()->json(['data' => $this->prestamos->generarDocumentos($prestamo, $request->user())]);
    }

    public function resguardar(Request $request, Prestamo $prestamo): JsonResponse
    {
        $this->authorize('resguardar', $prestamo);

        return response()->json(['data' => $this->prestamos->aArray($this->prestamos->resguardar($prestamo, $request->user()), true)]);
    }
}
