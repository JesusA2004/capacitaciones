<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Models\TareaRh;
use App\Services\Tareas\DescarteTareasService;
use App\Services\Tareas\TareaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bandeja de trabajo (pendientes con objeto relacionado, prioridad y
 * leído/resuelto). Cada usuario ve lo asignado a su cuenta y lo asignado a
 * permisos que tiene, dentro de su alcance organizacional.
 */
class TareaController extends Controller
{
    use RespondePaginado;

    public function __construct(private readonly TareaService $tareas) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            // «descartadas» (aditivo): las que la persona quitó de su lista.
            'estado' => ['nullable', 'in:abiertas,resueltas,todas,descartadas'],
            'tipo' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->paginado(
            $this->tareas->bandeja($request->user(), $filtros),
            fn (TareaRh $t) => $this->tareas->aArray($t),
            ['conteos' => $this->tareas->conteos($request->user())],
        );
    }

    public function leer(TareaRh $tarea): JsonResponse
    {
        $this->authorize('ver', $tarea);

        return response()->json(['data' => $this->tareas->aArray($this->tareas->marcarLeida($tarea))]);
    }

    public function resolver(Request $request, TareaRh $tarea): JsonResponse
    {
        $this->authorize('resolver', $tarea);

        return response()->json(['data' => $this->tareas->aArray($this->tareas->resolverManual($tarea, $request->user()))]);
    }

    /**
     * «Quitar de mi lista» (aditivo, docs/PENDIENTES.md): solo para quien
     * lo descarta; a los demás destinatarios les sigue llegando.
     */
    public function descartar(Request $request, TareaRh $tarea, DescarteTareasService $descartes): JsonResponse
    {
        $this->authorize('descartar', $tarea);
        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:'.(int) config('pendientes.motivo_max', 255)]]);
        $descartes->descartar($tarea, $request->user(), $datos['motivo'] ?? null);

        return response()->json(['data' => [...$this->tareas->aArray($tarea), 'descartada' => true]]);
    }

    public function restaurar(Request $request, TareaRh $tarea, DescarteTareasService $descartes): JsonResponse
    {
        $this->authorize('descartar', $tarea);
        $descartes->restaurar($tarea, $request->user());

        return response()->json(['data' => [...$this->tareas->aArray($tarea), 'descartada' => false]]);
    }
}
