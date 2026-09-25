<?php

namespace App\Http\Controllers;

use App\Models\BirthdayGreeting;
use App\Models\BirthdayWallMessage;
use App\Services\Celebraciones\CelebracionService;
use App\Services\Celebraciones\FechasCelebracion;
use App\Services\Celebraciones\TarjetaAniversarioService;
use App\Services\Cumpleanos\BirthdayCardService;
use App\Services\Cumpleanos\MuroCumpleanosService;
use App\Services\Expedientes\DocumentoStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Pantalla web de una celebración (/celebraciones/{id}) — el destino de
 * las notificaciones — y el aviso "Hoy celebramos" del inicio. Espejo de
 * Api\V1\CelebracionController: mismos servicios y la misma privacidad de
 * mensajes (docs/CELEBRACIONES.md).
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

    /**
     * Celebraciones de hoy para la tarjeta compacta del inicio.
     */
    public function hoy(Request $request): JsonResponse
    {
        $usuario = $request->user();

        return response()->json([
            'data' => $this->celebraciones->activas($usuario)
                ->filter(fn (BirthdayGreeting $c) => $c->fecha->isSameDay(FechasCelebracion::hoy()) && $usuario->can('view', $c))
                ->map(fn (BirthdayGreeting $c) => $this->celebraciones->aArray($c, $usuario, api: false) + ['url' => route('celebraciones.show', $c->id)])
                ->values(),
        ]);
    }

    public function show(Request $request, BirthdayGreeting $celebracion): Response
    {
        $this->authorize('view', $celebracion);
        $usuario = $request->user();

        return Inertia::render('Celebraciones/Show', [
            'celebracion' => $this->celebraciones->aArray($celebracion, $usuario, api: false),
            'mensajes' => $this->mensajes->mensajesVisibles($celebracion, $usuario)
                ->latest()
                ->latest('id')
                ->limit(300)
                ->get()
                ->map(fn (BirthdayWallMessage $m) => $this->mensajes->mensajeArray($m, $usuario, api: false))
                ->values(),
        ]);
    }

    public function storeMensaje(Request $request, BirthdayGreeting $celebracion): RedirectResponse
    {
        $this->authorize('escribir', $celebracion);

        $datos = $request->validate([
            'mensaje' => ['nullable', 'string', 'max:500'],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $this->mensajes->publicar($celebracion, $request->user(), $datos['mensaje'] ?? null, $request->file('foto'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Tu felicitación fue enviada.']);
    }

    public function updateMensaje(Request $request, BirthdayGreeting $celebracion, BirthdayWallMessage $mensaje): RedirectResponse
    {
        abort_unless($mensaje->birthday_greeting_id === $celebracion->id, 404);
        abort_unless($mensaje->user_id === $request->user()->id, 403);

        $datos = $request->validate(['mensaje' => ['required', 'string', 'max:500']]);
        $this->mensajes->actualizar($mensaje, $request->user(), $datos['mensaje']);

        return back()->with('toast', ['type' => 'success', 'message' => 'Felicitación actualizada.']);
    }

    public function destroyMensaje(Request $request, BirthdayGreeting $celebracion, BirthdayWallMessage $mensaje): RedirectResponse
    {
        abort_unless($mensaje->birthday_greeting_id === $celebracion->id, 404);
        abort_unless($this->mensajes->puedeEliminar($request->user(), $mensaje), 403);

        $this->mensajes->eliminar($mensaje, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Felicitación eliminada.']);
    }

    public function tarjeta(Request $request, BirthdayGreeting $celebracion): HttpResponse
    {
        $this->authorize('view', $celebracion);
        $celebracion = $this->celebraciones->tarjeta($celebracion);

        return $celebracion->esAniversario()
            ? $this->tarjetaAniversario->descargar($celebracion, enLinea: ! $request->boolean('descargar'))
            : $this->tarjetaCumpleanos->descargar($celebracion);
    }

    public function foto(Request $request, BirthdayGreeting $celebracion): HttpResponse
    {
        $this->authorize('view', $celebracion);
        $ruta = $celebracion->colaborador->foto_path;
        abort_if($ruta === null, 404);

        return $this->fotos->respuesta($ruta, ['Content-Type' => 'image/jpeg', 'Content-Disposition' => 'inline; filename="foto.jpg"']);
    }

    public function mensajeFoto(Request $request, BirthdayGreeting $celebracion, BirthdayWallMessage $mensaje): HttpResponse
    {
        $this->autorizarMensaje($request, $celebracion, $mensaje);

        return $this->mensajes->foto($mensaje);
    }

    public function autorFoto(Request $request, BirthdayGreeting $celebracion, BirthdayWallMessage $mensaje): HttpResponse
    {
        $this->autorizarMensaje($request, $celebracion, $mensaje);
        $ruta = ($mensaje->autorColaborador ?? $mensaje->autor->colaborador)?->foto_path;
        abort_if($ruta === null, 404);

        return $this->fotos->respuesta($ruta, ['Content-Type' => 'image/jpeg', 'Content-Disposition' => 'inline; filename="foto.jpg"']);
    }

    private function autorizarMensaje(Request $request, BirthdayGreeting $celebracion, BirthdayWallMessage $mensaje): void
    {
        $this->authorize('view', $celebracion);
        abort_unless($mensaje->birthday_greeting_id === $celebracion->id, 404);
        abort_unless($mensaje->user_id === $request->user()->id || $this->mensajes->puedeVerTodos($request->user(), $celebracion), 404);
    }
}
