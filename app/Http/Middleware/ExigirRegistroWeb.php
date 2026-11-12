<?php

namespace App\Http\Middleware;

use App\Services\Incorporacion\RegistroAppService;
use App\Services\Incorporacion\SolicitudEmpleo\SolicitudEmpleoRegistroService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registro completo antes de entrar, en la WEB (quien no puede instalar la
 * APK: iPhone, Mac). Mismo criterio que la app (RegistroAppService::aplica):
 * mientras la cuenta registrada por QR no termina su registro, cualquier
 * pantalla la regresa al asistente (mi-registro) — salvo lo que el asistente
 * usa: sus endpoints, la Solicitud de empleo, la foto, la contraseña y cerrar
 * sesión. Una petición JSON recibe 403 «registro_incompleto».
 *
 * El equivalente de la API (ExigirRegistroApp) va por cabecera de la app y no
 * cambia. El asistente web implementa la Solicitud de empleo, así que se
 * declara igual que la app nueva (cabecera X-People-Solicitud-Empleo): el
 * cierre la exige (SolicitudEmpleoRegistroService::exigidaEnRegistro).
 */
class ExigirRegistroWeb
{
    /** Rutas web que el asistente necesita. */
    private const PERMITIDAS = [
        'mi-registro.*',
        'logout',
        'contrasena-temporal.*',
        'security.edit',
        'user-password.update',
        'portal.solicitud-empleo',
        'portal.solicitud-empleo.*',
        // Foto de perfil = fotografía para expediente (subir y verla).
        'portal.foto',
        'rh.expedientes.foto',
        // Públicas: la pantalla del QR y la descarga de la app.
        'incorporacion.qr',
        'incorporacion.qr.*',
        'app.*',
    ];

    public function __construct(private readonly RegistroAppService $registro) {}

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario === null || $request->is('api/*') || ! $this->registro->aplica($usuario)) {
            return $next($request);
        }

        $request->headers->set(SolicitudEmpleoRegistroService::CABECERA_CLIENTE, '1');

        if ($request->routeIs(...self::PERMITIDAS)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'message' => 'Termina tu registro (datos y documentos) para entrar.',
                'codigo' => 'registro_incompleto',
            ], 403);
        }

        return redirect()->route('mi-registro.show');
    }
}
