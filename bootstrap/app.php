<?php

use App\Http\Middleware\EnsureCuentaActiva;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\ExigirCambioContrasena;
use App\Http\Middleware\ExigirDocumentosPendientes;
use App\Http\Middleware\ExigirRegistroApp;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\DiagnosticoAcceso;
use App\Support\LimitesSubida;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException as SymfonyFileNotFoundException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 'experiencia_modo' es una preferencia de navegador (qué modo de
        // navegación eligió el usuario, ver App\Services\Navigation\NavigationService),
        // mismo criterio que 'sidebar_state': no es dato
        // sensible, no necesita ir cifrada.
        $middleware->encryptCookies(except: ['sidebar_state', 'experiencia_modo']);

        $middleware->alias([
            'feature' => EnsureFeatureEnabled::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'contrasena.temporal' => ExigirCambioContrasena::class,
            'registro.app' => ExigirRegistroApp::class,
            'documentos.pendientes' => ExigirDocumentosPendientes::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            EnsureCuentaActiva::class,
            ExigirCambioContrasena::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // El webhook de Zoom lo llama Zoom directamente (sin sesión de
        // Laravel ni token CSRF): su propia verificación de firma
        // (X-Zm-Signature) es la protección real. Ver
        // App\Http\Controllers\Reuniones\ZoomWebhookController.
        $middleware->validateCsrfTokens(except: ['webhooks/zoom']);

        // El mapa de campos del editor documental lleva textos donde los
        // espacios SON contenido (prefijo « — Tel. » de una variable
        // compuesta): no se recortan. ExpresionesDocumentoService valida
        // cada pieza por su cuenta.
        $middleware->trimStrings(except: [
            fn (Request $request) => $request->is('rh/documentos-maestros/*/campos'),
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Red de seguridad para descargas/vistas previas/imágenes: si el
        // archivo físico ya no está (o el NAS no responde al LEER), nunca un
        // 500 con UnableToRetrieveMetadata/FileNotFound. API → 404 JSON;
        // web → regresa con aviso. Solo errores de lectura: una falla al
        // ESCRIBIR (subir un archivo) sigue siendo un error real.
        $exceptions->render(function (UnableToRetrieveMetadata|UnableToReadFile|FileNotFoundException|SymfonyFileNotFoundException $e, Request $request) {
            Log::warning('Archivo no disponible al leerlo.', ['url' => $request->path(), 'error' => $e->getMessage()]);

            $mensaje = 'El archivo fuente de este documento ya no está disponible.';

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $mensaje], 404);
            }

            return back()->with('toast', ['type' => 'error', 'message' => $mensaje]);
        });

        // Páginas de error corporativas (resources/js/pages/Error.vue) SOLO
        // con APP_DEBUG=false: nunca se muestra la pantalla de excepción de
        // Laravel (trazas, consultas, rutas, headers, cookies) en producción.
        // La API y las peticiones JSON conservan su respuesta JSON. Si la
        // página Inertia no se puede pintar (p. ej. build faltante), queda la
        // vista Blade resources/views/errors/minimal.blade.php.
        $exceptions->respond(function (SymfonyResponse $respuesta, Throwable $e, Request $request) {
            $estado = $respuesta->getStatusCode();

            // 413 desde Laravel = ValidatePostSize: la petición superó el
            // post_max_size REAL del PHP que la atendió (Nginx contesta su
            // propio 413 «Request Entity Too Large» sin llegar aquí). Se
            // registra con los valores de ESTE proceso (FPM, no CLI) para
            // ubicar la capa exacta en storage/logs/laravel.log.
            if ($e instanceof PostTooLargeException) {
                Log::warning('Subida rechazada por PHP: el cuerpo supera post_max_size (413 desde Laravel, no desde Nginx).', [
                    'ruta' => $request->path(),
                    'content_length' => $request->server('CONTENT_LENGTH'),
                    ...LimitesSubida::resumen(),
                ]);
            }

            if (config('app.debug') || $request->is('api/*') || $request->expectsJson()) {
                return $respuesta;
            }

            if ($estado === 419) {
                return back()->with('toast', ['type' => 'error', 'message' => 'Tu sesión expiró. Intenta de nuevo.']);
            }

            if (! in_array($estado, [403, 404, 409, 413, 429, 500, 503], true)) {
                return $respuesta;
            }

            // 500: identificador corto para que Sistemas lo ubique en el log.
            $referencia = null;
            if ($estado === 500) {
                $referencia = strtoupper(Str::random(8));
                Log::error(sprintf('Error 500 referencia %s', $referencia), [
                    'ruta' => $request->path(),
                    'metodo' => $request->method(),
                    'usuario_id' => $request->user()?->id,
                    'excepcion' => $e::class,
                    'mensaje' => $e->getMessage(),
                ]);
            }

            // Una ACCIÓN (POST/PUT/PATCH/DELETE) que falla con 403/409/500 no
            // tira la pantalla completa: regresa a donde estaba con el motivo
            // (el formulario/modal sigue ahí y nada falla en silencio).
            if ($request->header('X-Inertia') && ! $request->isMethod('GET') && in_array($estado, [403, 409, 500], true)) {
                $motivo = trim($e->getMessage());
                $mensaje = match ($estado) {
                    403 => $motivo !== '' && $motivo !== 'This action is unauthorized.' ? $motivo : 'No tienes permiso para realizar esta acción.',
                    409 => $motivo !== '' ? $motivo : 'Esta acción todavía no está disponible.',
                    default => sprintf('Ocurrió un error inesperado. Referencia: %s. Avísalo a Sistemas.', $referencia),
                };

                return back()->with('toast', ['type' => 'error', 'message' => $mensaje]);
            }

            $props = ['status' => $estado];

            if ($referencia !== null) {
                $props['mensaje'] = sprintf('Ocurrió un error inesperado. Referencia: %s.', $referencia);
            }

            // Nunca la página cruda «413 Content Too Large»: se dice cuánto
            // acepta realmente el servidor por subida.
            if ($estado === 413) {
                $props['mensaje'] = sprintf(
                    'El archivo es más grande de lo que el servidor acepta en una sola subida (PHP: post_max_size %s, upload_max_filesize %s). Pide a Sistemas que revise la configuración de PHP-FPM.',
                    LimitesSubida::resumen()['post_max_size'],
                    LimitesSubida::resumen()['upload_max_filesize'],
                );
            }

            // 409 = "todavía no disponible" (p. ej. formato de permiso antes de
            // que RH lo autorice): el motivo es texto propio, se muestra tal cual.
            if ($estado === 409 && trim($e->getMessage()) !== '') {
                $props['mensaje'] = trim($e->getMessage());
            }

            // Diagnóstico de acceso: solo super_admin/sistemas lo reciben.
            if ($estado === 403) {
                $props['diagnostico'] = DiagnosticoAcceso::para($request, $e);
            }

            try {
                return Inertia::render('Error', $props)->toResponse($request)->setStatusCode($estado);
            } catch (Throwable) {
                return $respuesta;
            }
        });
    })->create();
