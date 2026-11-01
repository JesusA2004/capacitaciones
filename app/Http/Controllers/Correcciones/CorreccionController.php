<?php

namespace App\Http\Controllers\Correcciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Correcciones\AplicarCorreccionRequest;
use App\Http\Requests\Correcciones\VistaPreviaCorreccionRequest;
use App\Models\Colaborador;
use App\Services\Correcciones\CorreccionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Delgado sobre CorreccionService. Lo hereda el controlador de la API móvil: una
 * sola forma de respuesta y ninguna regla aquí.
 */
class CorreccionController extends Controller
{
    public function __construct(protected readonly CorreccionService $correcciones) {}

    public function mostrar(Request $request, string $dominio, int $id): JsonResponse
    {
        return response()->json(['data' => $this->correcciones->describir($dominio, $id, $request->user())]);
    }

    public function vistaPrevia(VistaPreviaCorreccionRequest $request, string $dominio, int $id): JsonResponse
    {
        return response()->json(['data' => $this->correcciones->previsualizar($dominio, $id, $request->valores(), $request->opciones(), $request->user())]);
    }

    public function aplicar(AplicarCorreccionRequest $request, string $dominio, int $id): JsonResponse
    {
        $correccion = $this->correcciones->aplicar(
            $dominio,
            $id,
            $request->valores(),
            (string) $request->input('motivo', ''),
            (string) $request->input('huella'),
            (string) $request->input('clave_idempotencia'),
            $request->opciones(),
            $request->user(),
        );

        return response()->json(['data' => $this->correcciones->aArray($correccion, $request->user())], 201);
    }

    public function historial(Request $request, string $dominio, int $id): JsonResponse
    {
        return response()->json(['data' => $this->correcciones->historial($dominio, $id, $request->user())]);
    }

    public function historialColaborador(Request $request, Colaborador $colaborador): JsonResponse
    {
        return response()->json(['data' => $this->correcciones->historialDeColaborador($colaborador, $request->user())]);
    }
}
