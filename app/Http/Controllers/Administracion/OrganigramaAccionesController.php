<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\MoverEnOrganigramaRequest;
use App\Http\Requests\Administracion\TraspasoPuestoRequest;
use App\Models\Colaborador;
use App\Services\Organigrama\MovimientoOrganigramaService;
use App\Services\Organigrama\TraspasoPuestoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Acciones por nodo del organigrama (panel lateral): subir / bajar / mover
 * bajo… y «Cambiar de puesto (traspaso)». Delgado: toda la regla vive en
 * MovimientoOrganigramaService y TraspasoPuestoService.
 */
class OrganigramaAccionesController extends Controller
{
    public function __construct(
        private readonly MovimientoOrganigramaService $movimientos,
        private readonly TraspasoPuestoService $traspasos,
    ) {}

    public function opciones(Request $request, Colaborador $colaborador): JsonResponse
    {
        $this->authorize('moverEnOrganigrama', $colaborador);

        return response()->json($this->movimientos->opciones($colaborador));
    }

    public function vistaPrevia(MoverEnOrganigramaRequest $request, Colaborador $colaborador): JsonResponse
    {
        return response()->json($this->movimientos->vistaPrevia($colaborador, $request->destino()));
    }

    public function mover(MoverEnOrganigramaRequest $request, Colaborador $colaborador): RedirectResponse
    {
        $this->movimientos->aplicar($colaborador, $request->destino(), $request->string('accion')->toString(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Listo: %s ya está en su nueva plaza y su jefe directo se recalculó.', $colaborador->nombreCompleto())]);
    }

    public function opcionesTraspaso(Request $request): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario !== null && $usuario->can('organigrama.ver') && $usuario->can('organigrama.mover_personas'), 403);

        return response()->json($this->traspasos->opciones($usuario));
    }

    public function vistaPreviaTraspaso(TraspasoPuestoRequest $request): JsonResponse
    {
        return response()->json($this->traspasos->vistaPrevia($request->colaboradores(), $request->destino()));
    }

    public function traspasar(TraspasoPuestoRequest $request): RedirectResponse
    {
        $resultado = $this->traspasos->aplicar($request->colaboradores(), $request->destino(), $request->comentario(), $request->user());

        $mensaje = $resultado['personas'] === 1
            ? 'Listo: el traspaso quedó registrado y su jefe directo se recalculó.'
            : sprintf('Listo: %d personas traspasadas; sus jefes directos se recalcularon.', $resultado['personas']);

        if ($resultado['rutas_liberadas'] > 0) {
            $mensaje .= sprintf(' %s en la Matriz comercial.', $resultado['rutas_liberadas'] === 1 ? 'Quedó 1 ruta sin cubrir' : sprintf('Quedaron %d rutas sin cubrir', $resultado['rutas_liberadas']));
        }

        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }
}
