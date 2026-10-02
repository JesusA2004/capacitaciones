<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SubirDocumentoIncorporacionRequest;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Services\Incorporacion\IncorporacionService;
use App\Services\Navigation\NavigationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Checklist de incorporacion documental del colaborador autenticado (app
 * movil). A proposito nunca expone el expediente completo (comentarios de
 * RH, quien reviso, sueldos, rutas del NAS, expedientes de otros
 * colaboradores): solo el estado de cada documento requerido y las
 * acciones que el colaborador puede tomar. Ver docs/API_MOVIL.md y
 * App\Services\Incorporacion\IncorporacionService.
 */
class IncorporacionController extends Controller
{
    public function __construct(private readonly IncorporacionService $incorporacion) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->esPropio($request, 'colaborador.incorporacion.ver'), 403);

        return response()->json($this->incorporacion->estadoIncorporacion($this->colaboradorDe($request)));
    }

    /** Alias de index() por si la app lo necesita bajo otro nombre. */
    public function resumen(Request $request): JsonResponse
    {
        return $this->index($request);
    }

    public function subirDocumento(SubirDocumentoIncorporacionRequest $request, DocumentType $documentoRequerido): JsonResponse
    {
        $usuario = $request->user();

        try {
            $this->incorporacion->subirDocumento($this->colaboradorDe($request), $documentoRequerido, $request->file('archivo'), $usuario->id);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['archivo' => $e->getMessage()]);
        }

        return response()->json($this->incorporacion->estadoIncorporacion($this->colaboradorDe($request)));
    }

    public function solicitarCambio(Request $request, DocumentType $documento): JsonResponse
    {
        $usuario = $request->user();

        abort_unless($this->esPropio($request, 'colaborador.incorporacion.documentos.solicitar-cambio'), 403);

        try {
            $this->incorporacion->solicitarCambio($this->colaboradorDe($request), $documento);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['documento' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Solicitud de cambio enviada a RH']);
    }

    /**
     * Su propio expediente lo ve cualquier persona en «Mi espacio» (gerente,
     * regional, RH también son colaboradores) — mismo gate que la web
     * (`modo-colaborador`, NavigationService), no solo el rol colaborador.
     */
    private function esPropio(Request $request, string $permiso): bool
    {
        $usuario = $request->user();

        return $usuario->can($permiso) || Gate::forUser($usuario)->allows(NavigationService::GATE_MODO_COLABORADOR);
    }

    private function colaboradorDe(Request $request): Colaborador
    {
        $colaborador = $request->user()->colaborador;

        abort_if($colaborador === null, 422, 'Tu cuenta no tiene un colaborador enlazado.');

        return $colaborador;
    }
}
