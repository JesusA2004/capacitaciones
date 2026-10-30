<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contratos\SubirContratoFirmadoRequest;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Services\Contratos\ContratoFirmadoService;
use App\Services\Contratos\ContratoLaboralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class ContratoController extends Controller
{
    public function __construct(private readonly ContratoLaboralService $contratos) {}

    /**
     * Contratos vigentes que vencen en los próximos N días (default 30).
     */
    public function porVencer(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('contratos.ver'), 403);

        $dias = max(1, min(365, $request->integer('dias', 30)));

        return response()->json([
            'data' => $this->contratos->porVencer($request->user(), $dias)->map(fn (ContratoLaboral $c) => $this->contratos->aArray($c, $request->user()))->values(),
            'dias' => $dias,
        ]);
    }

    public function delColaborador(Request $request, Colaborador $colaborador): JsonResponse
    {
        $this->authorize('verContratos', $colaborador);

        return response()->json([
            'data' => $colaborador->contratos()->with(['colaborador', 'evaluacion', 'documento'])->get()->map(fn (ContratoLaboral $c) => $this->contratos->aArray($c, $request->user()))->values(),
        ]);
    }

    /**
     * «Subir contrato firmado» desde la app: mismo ContratoFirmadoService que
     * la web (permiso contratos.subir_firmado o documentos.subir, alcance y
     * la regla del contrato de un Gerente de Sucursal). Aditivo: las APK
     * instaladas no lo usan todavía.
     */
    public function subirFirmado(SubirContratoFirmadoRequest $request, Colaborador $colaborador, ContratoFirmadoService $firmados): JsonResponse
    {
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);

        $documento = $firmados->subir($colaborador, (string) $request->validated('clave'), $archivo, $request->user());

        return response()->json([
            'message' => 'Contrato firmado cargado. RH lo revisará en Validación de documentos.',
            'data' => [
                'id' => $documento->id,
                'tipo' => $documento->tipo->clave,
                'tipo_nombre' => $documento->tipo->nombre,
                'version' => $documento->version,
                'estado' => $documento->status->value,
            ],
        ], 201);
    }
}
