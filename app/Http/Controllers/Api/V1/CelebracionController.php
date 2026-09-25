<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BirthdayGreeting;
use App\Models\BirthdayWallMessage;
use App\Services\Celebraciones\CelebracionService;
use App\Services\Celebraciones\TarjetaAniversarioService;
use App\Services\Cumpleanos\BirthdayCardService;
use App\Services\Cumpleanos\MuroCumpleanosService;
use App\Services\Expedientes\DocumentoStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Celebraciones para la app móvil (docs/API_MOVIL.md → "Celebraciones"):
 * cumpleaños y aniversarios laborales con felicitaciones PRIVADAS. Toda la
 * regla (quién ve qué mensajes) vive en BirthdayGreetingPolicy y en
 * MuroCumpleanosService::mensajesVisibles().
 */
class CelebracionController extends Controller
{
    public function __construct(
        private readonly CelebracionService $celebraciones,
        private readonly MuroCumpleanosService $mensajes,
        private readonly DocumentoStorageService $fotos,
        private readonly TarjetaAniversarioService $tarjetaAniversario,
        private readonly BirthdayCardService $tarjetaCumpleanos,
    ) {}

    public function activas(Request $request): JsonResponse
    {
        $usuario = $request->user();

        return response()->json([
            'data' => $this->celebraciones->activas($usuario)
                ->filter(fn (BirthdayGreeting $c) => $usuario->can('view', $c))
                ->map(fn (BirthdayGreeting $c) => $this->celebraciones->aArray($c, $usuario))
                ->values(),
        ]);
    }

    public function show(Request $request, BirthdayGreeting $celebracion): JsonResponse
    {
        $this->authorize('view', $celebracion);

        return response()->json(['data' => $this->celebraciones->aArray($celebracion, $request->user())]);
    }

    /**
     * Homenajeado / RH: todas. Cualquier otro: SOLO la suya (filtrado en la
     * consulta, nunca en la app).
     */
    public function mensajes(Request $request, BirthdayGreeting $celebracion): JsonResponse
    {
        $this->authorize('view', $celebracion);
        $usuario = $request->user();

        $pagina = $this->mensajes->mensajesVisibles($celebracion, $usuario)
            ->latest()
            ->latest('id')
            ->paginate(min(50, max(1, $request->integer('per_page', 30))));

        return response()->json([
            'data' => collect($pagina->items())->map(fn (BirthdayWallMessage $m) => $this->mensajes->mensajeArray($m, $usuario))->values(),
            'meta' => [
                'current_page' => $pagina->currentPage(),
                'last_page' => $pagina->lastPage(),
                'per_page' => $pagina->perPage(),
                'total' => $pagina->total(),
                'solo_propio' => ! $this->mensajes->puedeVerTodos($usuario, $celebracion),
            ],
        ]);
    }

    public function store(Request $request, BirthdayGreeting $celebracion): JsonResponse
    {
        $this->authorize('escribir', $celebracion);

        $datos = $request->validate([
            'mensaje' => ['nullable', 'string', 'max:500'],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $mensaje = $this->mensajes->publicar($celebracion, $request->user(), $datos['mensaje'] ?? null, $request->file('foto'));

        return response()->json(['data' => $this->mensajes->mensajeArray($mensaje, $request->user())], 201);
    }

    public function update(Request $request, BirthdayGreeting $celebracion, BirthdayWallMessage $mensaje): JsonResponse
    {
        abort_unless($mensaje->birthday_greeting_id === $celebracion->id, 404);
        abort_unless($mensaje->user_id === $request->user()->id, 403);

        $datos = $request->validate(['mensaje' => ['required', 'string', 'max:500']]);
        $mensaje = $this->mensajes->actualizar($mensaje, $request->user(), $datos['mensaje']);

        return response()->json(['data' => $this->mensajes->mensajeArray($mensaje, $request->user())]);
    }

    public function destroy(Request $request, BirthdayGreeting $celebracion, BirthdayWallMessage $mensaje): JsonResponse
    {
        abort_unless($mensaje->birthday_greeting_id === $celebracion->id, 404);
        abort_unless($this->mensajes->puedeEliminar($request->user(), $mensaje), 403);

        $this->mensajes->eliminar($mensaje, $request->user());

        return response()->json(['message' => 'Felicitación eliminada.']);
    }

    public function tarjeta(Request $request, BirthdayGreeting $celebracion): HttpResponse
    {
        $this->authorize('view', $celebracion);
        $celebracion = $this->celebraciones->tarjeta($celebracion);

        return $celebracion->esAniversario()
            ? $this->tarjetaAniversario->descargar($celebracion, enLinea: true)
            : $this->tarjetaCumpleanos->descargar($celebracion);
    }

    public function foto(Request $request, BirthdayGreeting $celebracion): HttpResponse
    {
        $this->authorize('view', $celebracion);
        $ruta = $celebracion->colaborador->foto_path;
        abort_if($ruta === null, 404);

        return $this->fotos->respuesta($ruta, ['Content-Type' => 'image/jpeg', 'Content-Disposition' => 'inline; filename="foto.jpg"']);
    }

    /**
     * Foto del autor de un mensaje: solo para quien puede ver ese mensaje.
     */
    public function mensajeFoto(Request $request, BirthdayGreeting $celebracion, BirthdayWallMessage $mensaje): HttpResponse
    {
        $this->authorize('view', $celebracion);
        abort_unless($mensaje->birthday_greeting_id === $celebracion->id, 404);
        abort_unless($mensaje->user_id === $request->user()->id || $this->mensajes->puedeVerTodos($request->user(), $celebracion), 404);

        return $this->mensajes->foto($mensaje);
    }

    public function autorFoto(Request $request, BirthdayGreeting $celebracion, BirthdayWallMessage $mensaje): HttpResponse
    {
        $this->authorize('view', $celebracion);
        abort_unless($mensaje->birthday_greeting_id === $celebracion->id, 404);
        abort_unless($mensaje->user_id === $request->user()->id || $this->mensajes->puedeVerTodos($request->user(), $celebracion), 404);

        $ruta = ($mensaje->autorColaborador ?? $mensaje->autor->colaborador)?->foto_path;
        abort_if($ruta === null, 404);

        return $this->fotos->respuesta($ruta, ['Content-Type' => 'image/jpeg', 'Content-Disposition' => 'inline; filename="foto.jpg"']);
    }
}
