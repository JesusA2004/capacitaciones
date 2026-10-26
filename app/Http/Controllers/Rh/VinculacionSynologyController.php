<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\VinculacionSynologyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Expediente → «Ligar expediente de Synology». Delgado: todo vive en
 * VinculacionSynologyService. Ligar UN expediente es operación de RH
 * (permiso `expedientes.editar` + alcance sobre el colaborador), no una
 * importación masiva (esas son solo de super_admin). Nadie liga su propio
 * expediente.
 */
class VinculacionSynologyController extends Controller
{
    public function __construct(
        private readonly VinculacionSynologyService $vinculacion,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function carpetas(Request $request, Colaborador $colaborador): JsonResponse
    {
        $this->exigir($request, $colaborador);
        $datos = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'refrescar' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'data' => [
                ...$this->vinculacion->buscar($colaborador, $request->user(), (string) ($datos['q'] ?? ''), $request->boolean('refrescar')),
                'tiene_expediente' => $this->vinculacion->tieneExpedienteLigado($colaborador),
            ],
        ]);
    }

    public function vistaPrevia(Request $request, Colaborador $colaborador): JsonResponse
    {
        $this->exigir($request, $colaborador);
        $datos = $request->validate(['clave' => ['required', 'string', 'size:40']]);

        return response()->json(['data' => $this->vinculacion->vistaPrevia($colaborador, $request->user(), (string) $datos['clave'])]);
    }

    public function ligar(Request $request, Colaborador $colaborador): RedirectResponse
    {
        $this->exigir($request, $colaborador);
        $datos = $request->validate(['clave' => ['required', 'string', 'size:40']]);

        $resultado = $this->vinculacion->ligar($colaborador, $request->user(), (string) $datos['clave']);

        $mensaje = $resultado['pdfs_registrados'] + $resultado['pdfs_duplicados'] > 0
            ? sprintf('Expediente «%s» ligado: %d PDF registrado(s).', $resultado['carpeta'], $resultado['pdfs_registrados'] + $resultado['pdfs_duplicados'])
            : sprintf('Carpeta «%s» ligada como expediente.', $resultado['carpeta']);

        if ($resultado['errores'] > 0 || $resultado['pdfs_conflicto'] > 0) {
            return back()->with('toast', ['type' => 'warning', 'message' => sprintf('%s %d archivo(s) no se pudieron registrar; quedó en el registro de errores.', $mensaje, $resultado['errores'] + $resultado['pdfs_conflicto'])]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }

    private function exigir(Request $request, Colaborador $colaborador): void
    {
        $usuario = $request->user();

        abort_unless(
            $usuario->can('expedientes.editar')
                && $usuario->colaborador_id !== $colaborador->id
                && $this->alcance->puedeVerExpediente($usuario, $colaborador),
            403,
        );
    }
}
