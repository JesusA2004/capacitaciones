<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Avisos\CrearAvisoRequest;
use App\Models\Aviso;
use App\Services\Avisos\AvisoService;
use App\Services\DocumentosMaestros\DocumentosMaestrosAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Avisos de RH a la empresa (mensaje + imagen, a todos o a un colaborador).
 * Toda la lógica vive en App\Services\Avisos\AvisoService; este controlador
 * solo autoriza, valida y transforma. Buscar colaborador reutiliza
 * DocumentosMaestrosAdminService::buscarColaboradores() (mismo criterio y
 * alcance organizacional que "Probar con colaborador"), sin duplicarlo.
 */
class AvisoController extends Controller
{
    public const PERMISO = 'avisos.enviar';

    public function __construct(
        private readonly AvisoService $avisos,
        private readonly DocumentosMaestrosAdminService $admin,
    ) {}

    public function index(Request $request): Response
    {
        $this->exigir($request);

        return Inertia::render('Rh/Avisos/Index', [
            'avisos' => $this->avisos->listar(),
        ]);
    }

    public function store(CrearAvisoRequest $request): RedirectResponse
    {
        $this->exigir($request);

        $this->avisos->crear($request->validated(), $request->file('imagen'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Aviso enviado.']);
    }

    public function buscarColaboradores(Request $request): JsonResponse
    {
        $this->exigir($request);
        $datos = $request->validate(['q' => ['nullable', 'string', 'max:80']]);

        return response()->json(['data' => $this->admin->buscarColaboradores($request->user(), (string) ($datos['q'] ?? ''))]);
    }

    public function imagen(Request $request, Aviso $aviso): StreamedResponse
    {
        $this->exigir($request);

        return $this->avisos->imagen($aviso);
    }

    private function exigir(Request $request): void
    {
        abort_unless($request->user()?->can(self::PERMISO), 403);
    }
}
