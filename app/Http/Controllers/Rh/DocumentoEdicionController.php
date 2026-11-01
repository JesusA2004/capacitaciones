<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Services\Documentos\Versiones\DocumentoEdicionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * «Editar datos» e «Historial de versiones» de cualquier documento generado. Web y
 * API móvil usan este mismo controlador y DocumentoEdicionService; cada fuente
 * autoriza con la Policy y el alcance de su módulo.
 */
class DocumentoEdicionController extends Controller
{
    public function __construct(private readonly DocumentoEdicionService $edicion) {}

    public function campos(Request $request, string $tipo, int $id): JsonResponse
    {
        return response()->json($this->edicion->campos($tipo, $id, $request->string('clave')->toString() ?: null, $request->user()));
    }

    public function vistaPrevia(Request $request, string $tipo, int $id): Response
    {
        $datos = $request->validate(['clave' => ['nullable', 'string', 'max:100'], 'campos' => ['present', 'array']]);

        return response($this->edicion->vistaPrevia($tipo, $id, $datos['clave'] ?? null, $datos['campos'], $request->user()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Vista previa.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function emitir(Request $request, string $tipo, int $id): JsonResponse
    {
        $datos = $request->validate([
            'clave' => ['nullable', 'string', 'max:100'],
            'campos' => ['nullable', 'array'],
            'motivo' => ['nullable', 'string', 'max:500'],
            'rectificar' => ['sometimes', 'boolean'],
        ]);
        $resultado = $this->edicion->emitir($tipo, $id, $datos['clave'] ?? null, $datos['campos'] ?? null, $datos['motivo'] ?? null, (bool) ($datos['rectificar'] ?? false), $request->user());

        return response()->json([
            'creado' => $resultado->creado,
            'documento_id' => $resultado->documento->id,
            'message' => $resultado->creado ? 'Nueva versión generada; la anterior se conserva en el historial.' : 'Sin cambios: el PDF vigente ya corresponde a estos datos.',
        ], $resultado->creado ? 201 : 200);
    }

    public function versiones(Request $request, string $tipo, int $id): JsonResponse
    {
        return response()->json(['versiones' => $this->edicion->versiones($tipo, $id, $request->string('clave')->toString() ?: null, $request->user())]);
    }
}
