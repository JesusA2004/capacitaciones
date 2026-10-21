<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FinalizarRegistroRequest;
use App\Http\Requests\Api\V1\GuardarDatosRegistroRequest;
use App\Services\Incorporacion\RegistroAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registro completo en la app antes de entrar (siempre de la cuenta en
 * sesión, nunca por id). Reglas en App\Services\Incorporacion\RegistroAppService;
 * los documentos se suben con los endpoints de siempre
 * (POST /colaborador/incorporacion/documentos/{tipo}/subir).
 */
class RegistroAppController extends Controller
{
    public function __construct(private readonly RegistroAppService $registro) {}

    public function show(Request $request): JsonResponse
    {
        abort_if($request->user()->colaborador === null, 422, 'Tu cuenta no tiene un colaborador enlazado.');

        return response()->json(['data' => $this->registro->estado($request->user())]);
    }

    public function datos(GuardarDatosRegistroRequest $request): JsonResponse
    {
        $datos = $request->validated('datos');
        $medicos = $request->validated('datos_medicos');

        return response()->json(['data' => $this->registro->guardarDatos(
            $request->user(),
            is_array($datos) ? $datos : [],
            is_array($medicos) ? [
                'condicion_medica' => isset($medicos['condicion_medica']) ? (string) $medicos['condicion_medica'] : null,
                'alergias' => isset($medicos['alergias']) ? (string) $medicos['alergias'] : null,
            ] : null,
        )]);
    }

    /**
     * Cierra el registro. `documentos_sin_tener` (opcional): los documentos
     * marcados «No tengo este documento» → una carta compromiso
     * (`carta_compromiso` en la respuesta).
     */
    public function finalizar(FinalizarRegistroRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->registro->finalizar($request->user(), $request->documentosSinTener())]);
    }
}
