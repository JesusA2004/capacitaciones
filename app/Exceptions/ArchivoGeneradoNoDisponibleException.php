<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * El REGISTRO existe (recibo, documento generado…) pero su archivo no está
 * disponible: es un error operativo recuperable, nunca un 404 genérico
 * ("No encontramos esta página"). La web muestra qué pasó y, si el usuario
 * tiene permiso, el botón para regenerarlo (página normal, no un error del
 * navegador); la API responde 409 con un
 * código estable para que la app muestre un mensaje humano.
 */
class ArchivoGeneradoNoDisponibleException extends RuntimeException
{
    public const CODIGO = 'archivo_no_disponible';

    public function __construct(
        public readonly string $titulo,
        public readonly string $mensaje,
        public readonly ?string $urlReparar = null,
        public readonly ?string $urlVolver = null,
    ) {
        parent::__construct($mensaje);
    }

    /**
     * Se registra como aviso operativo (sin traza): no es un bug del código.
     */
    public function report(): void
    {
        Log::warning('Archivo generado no disponible.', ['titulo' => $this->titulo, 'url' => request()->path()]);
    }

    public function render(Request $request): Response|JsonResponse
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'message' => $this->mensaje,
                'codigo' => self::CODIGO,
                'puede_reparar' => $this->urlReparar !== null,
            ], 409);
        }

        return Inertia::render('ArchivoNoDisponible', [
            'titulo' => $this->titulo,
            'mensaje' => $this->mensaje,
            'urlReparar' => $this->urlReparar,
            'urlVolver' => $this->urlVolver,
        ])->toResponse($request);
    }
}
