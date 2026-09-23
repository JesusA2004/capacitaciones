<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoUsuario;
use App\Http\Controllers\Controller;
use App\Models\BirthdayGreeting;
use App\Models\BirthdayWallMessage;
use App\Models\User;
use App\Services\Cumpleanos\MuroCumpleanosService;
use App\Services\Expedientes\DocumentoStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Muro de felicitaciones de cumpleaños para cualquier colaborador activo de
 * la app (docs/CUMPLEANOS.md, "Muro de felicitaciones"). Abrir/cerrar el muro
 * es de RH (`Api\V1\Rh\CumpleanosController`); aquí se ve y se participa.
 */
class MuroCumpleanosController extends Controller
{
    public function __construct(
        private readonly MuroCumpleanosService $muros,
        private readonly DocumentoStorageService $fotos,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $usuario = $this->participante($request);

        return response()->json([
            'data' => $this->muros->visibles()->map(fn (BirthdayGreeting $g) => $this->muros->aArray($g, $usuario))->values(),
        ]);
    }

    public function show(Request $request, BirthdayGreeting $greeting): JsonResponse
    {
        $usuario = $this->participante($request);
        abort_unless($greeting->muroPublicado(), 404);

        return response()->json(['data' => $this->muros->aArray($greeting, $usuario)]);
    }

    public function mensajes(Request $request, BirthdayGreeting $greeting): JsonResponse
    {
        $usuario = $this->participante($request);
        abort_unless($greeting->muroPublicado(), 404);

        $pagina = $greeting->mensajesMuro()
            ->with(['autor.colaborador.puesto:id,nombre'])
            ->latest()
            ->latest('id')
            ->paginate(min(50, max(1, $request->integer('per_page', 20))));

        return response()->json([
            'data' => collect($pagina->items())->map(fn (BirthdayWallMessage $m) => $this->muros->mensajeArray($m, $usuario))->values(),
            'meta' => [
                'current_page' => $pagina->currentPage(),
                'last_page' => $pagina->lastPage(),
                'per_page' => $pagina->perPage(),
                'total' => $pagina->total(),
            ],
        ]);
    }

    public function publicar(Request $request, BirthdayGreeting $greeting): JsonResponse
    {
        $usuario = $this->participante($request);
        abort_unless($greeting->muroPublicado(), 404);

        $datos = $request->validate([
            'mensaje' => ['nullable', 'string', 'max:500'],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $mensaje = $this->muros->publicar($greeting, $usuario, $datos['mensaje'] ?? null, $request->file('foto'));

        return response()->json(['data' => $this->muros->mensajeArray($mensaje, $usuario)], 201);
    }

    public function eliminar(Request $request, BirthdayGreeting $greeting, BirthdayWallMessage $mensaje): JsonResponse
    {
        $usuario = $this->participante($request);
        abort_unless($mensaje->birthday_greeting_id === $greeting->id, 404);
        abort_unless($this->muros->puedeEliminar($usuario, $mensaje), 403);

        $this->muros->eliminar($mensaje);

        return response()->json(['message' => 'Mensaje eliminado.']);
    }

    public function foto(Request $request, BirthdayGreeting $greeting, BirthdayWallMessage $mensaje): HttpResponse
    {
        $this->participante($request);
        abort_unless($mensaje->birthday_greeting_id === $greeting->id && $greeting->muroPublicado(), 404);

        return $this->muros->foto($mensaje);
    }

    /** Foto del cumpleañero, SOLO mientras su muro esté publicado. */
    public function fotoCumpleanero(Request $request, BirthdayGreeting $greeting): HttpResponse
    {
        $this->participante($request);
        abort_unless($greeting->muroPublicado(), 404);
        $colaborador = $greeting->colaborador;
        abort_unless($colaborador->foto_path !== null, 404);

        return $this->fotos->respuesta($colaborador->foto_path, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="foto.jpg"',
        ]);
    }

    /** Participa cualquier cuenta con colaborador ACTIVO (una cuenta de servicio o dada de baja, no). */
    private function participante(Request $request): User
    {
        /** @var User $usuario */
        $usuario = $request->user();
        abort_unless($usuario->colaborador?->estatus === EstadoUsuario::Activo, 403);

        return $usuario;
    }
}
