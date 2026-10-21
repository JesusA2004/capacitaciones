<?php

namespace App\Http\Middleware;

use App\Services\Expedientes\BloqueoDocumentosService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloqueo de la app por documentos faltantes (BloqueoDocumentosService):
 * con documentos requeridos sin subir y sin carta compromiso dentro de
 * plazo, la API solo responde lo necesario para subirlos (sesión, perfil,
 * expediente, incorporación documental, carta compromiso…) y lo demás da
 * 403 «documentos_pendientes». Se levanta solo al subir el último.
 *
 * Compatibilidad: solo se exige a la app que declara entender este bloqueo
 * (cabecera X-People-Documentos). Las APKs ya instaladas no la mandan y no
 * tienen la pantalla para resolverlo: no se les rompe la app.
 */
class ExigirDocumentosPendientes
{
    public const CABECERA_CLIENTE = 'X-People-Documentos';

    /** Rutas que la pantalla «Documentos pendientes» necesita. */
    private const PERMITIDAS = [
        'api.v1.logout',
        'api.v1.me',
        'api.v1.cambiar-contrasena',
        'api.v1.reautenticar',
        'api.v1.mobile.bootstrap',
        'api.v1.broadcasting.auth',
        'api.v1.dispositivos.*',
        'api.v1.notificaciones.*',
        'api.v1.colaborador.perfil',
        'api.v1.colaborador.foto',
        'api.v1.colaborador.foto.*',
        'api.v1.colaborador.registro.*',
        'api.v1.colaborador.incorporacion.*',
        'api.v1.colaborador.carta-compromiso.*',
        'api.v1.colaborador.expediente',
        'api.v1.colaborador.expediente.*',
        'api.v1.colaborador.documentos-pendientes',
        'api.v1.colaborador.datos-faltantes',
    ];

    public function __construct(private readonly BloqueoDocumentosService $bloqueo) {}

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario === null
            || ! $request->hasHeader(self::CABECERA_CLIENTE)
            || ! (bool) config('expedientes.bloqueo_documentos_faltantes', true)
            || $request->routeIs(...self::PERMITIDAS)) {
            return $next($request);
        }

        $estado = $this->bloqueo->estado($usuario);

        if (! $estado['activo']) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Tu acceso está limitado: sube tus documentos pendientes para continuar.',
            'code' => 'documentos_pendientes',
            // Mismo nombre de campo que «registro_incompleto» (ExigirRegistroApp).
            'codigo' => 'documentos_pendientes',
            'bloqueo' => $estado,
        ], 403);
    }
}
