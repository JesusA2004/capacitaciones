<?php

namespace App\Http\Controllers;

use App\Http\Requests\Directorio\ActualizarFilaDirectorioRequest;
use App\Models\Colaborador;
use App\Services\Directorio\DirectorioColaboradoresService;
use App\Services\Directorio\DirectorioEdicionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Directorio de la empresa (correo/teléfono corporativo por colaborador):
 * visible a cualquier colaborador autenticado, acotado al alcance del
 * módulo «Directorio» (su sucursal, región o toda la empresa) y solo con
 * contacto corporativo — ver la regla en DirectorioColaboradoresService. Mismo service que
 * Api\V1\DirectorioController (app móvil).
 *
 * Edición rápida por fila (solo `directorio.editar`, nunca la propia fila):
 * DirectorioEdicionService, que escribe con el mismo service del expediente.
 */
class DirectorioController extends Controller
{
    public function __construct(
        private readonly DirectorioColaboradoresService $directorio,
        private readonly DirectorioEdicionService $edicion,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        $colaboradores = $this->directorio->todos($usuario);

        // Lista completa: la búsqueda y las vistas Por puesto / Por área /
        // Por sucursal se resuelven al instante en pantalla.
        return Inertia::render('Directorio/Index', [
            'colaboradores' => $colaboradores,
            'veTodosLosContactos' => $this->directorio->veTodosLosContactos($usuario),
            'filtros' => $request->only(['busqueda', 'vista']),
            // Solo para quien puede editar; los demás reciben null y ven el
            // directorio igual que siempre.
            'edicion' => $this->edicion->puedeEditarDirectorio($usuario) ? [
                'opciones' => $this->directorio->opciones(),
                'editables' => $this->edicion->idsEditables(
                    $usuario,
                    array_map(fn (array $fila): int => (int) $fila['id'], $colaboradores),
                ),
            ] : null,
        ]);
    }

    public function actualizar(ActualizarFilaDirectorioRequest $request, Colaborador $colaborador): JsonResponse
    {
        $this->edicion->actualizar($colaborador, $request->validated(), $request->user());

        return response()->json([
            'mensaje' => 'Cambios guardados.',
            // Null si con el cambio dejó de aparecer en el directorio.
            'colaborador' => $this->directorio->filaWebDe((int) $colaborador->id, $request->user()),
        ]);
    }
}
