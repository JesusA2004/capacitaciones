<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\ActualizarPlantillaDocumentalRequest;
use App\Http\Requests\CicloLaboral\GuardarPlantillaDocumentalRequest;
use App\Models\DocumentTemplate;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\DocumentosLaborales\PlantillaDocumentalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Carga/configuración de plantillas del motor documental (claves y
 * versiones). Los textos jurídicos los aporta RH/Jurídico.
 */
class PlantillaDocumentalController extends Controller
{
    public function __construct(
        private readonly PlantillaDocumentalService $plantillas,
        private readonly MotorDocumentalService $motor,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('plantillas_documentales.ver'), 403);

        $plantillas = DocumentTemplate::query()
            ->whereNotNull('clave')
            ->when($request->string('clave')->toString(), fn ($q, string $c) => $q->where('clave', $c))
            ->when($request->boolean('solo_activas'), fn ($q) => $q->where('activo', true))
            ->orderBy('clave')
            ->orderByDesc('version')
            ->get();

        return response()->json([
            'data' => $plantillas->map(fn (DocumentTemplate $p) => $this->plantillas->aArray($p))->values(),
            'catalogo' => collect((array) config('contratos.plantillas'))->map(fn ($info, $clave) => [
                'clave' => $clave,
                'nombre' => is_array($info) ? ($info['nombre'] ?? $clave) : $clave,
                'configurada' => $this->motor->tienePlantillaActiva((string) $clave),
            ])->values(),
        ]);
    }

    public function variables(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('plantillas_documentales.ver'), 403);

        return response()->json(['data' => $this->motor->variablesDisponibles()]);
    }

    public function store(GuardarPlantillaDocumentalRequest $request): JsonResponse
    {
        $archivo = $request->file('archivo');
        $plantilla = $this->plantillas->crearVersion($request->safe()->except('archivo'), is_array($archivo) ? null : $archivo, $request->user());

        return response()->json(['data' => $this->plantillas->aArray($plantilla)], 201);
    }

    public function update(ActualizarPlantillaDocumentalRequest $request, DocumentTemplate $plantilla): JsonResponse
    {
        abort_if($plantilla->clave === null, 404);

        return response()->json(['data' => $this->plantillas->aArray($this->plantillas->actualizar($plantilla, $request->validated(), $request->user()))]);
    }
}
