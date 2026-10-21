<?php

namespace App\Http\Middleware;

use App\Services\Incorporacion\RegistroAppService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registro completo antes de entrar (RegistroAppService): mientras la cuenta
 * registrada por QR no termina su registro, la API solo responde lo que el
 * asistente necesita (sesión, registro, incorporación documental, foto,
 * datos faltantes) y lo demás da 403 «registro_incompleto».
 *
 * Compatibilidad: solo se exige a la app que declara entender el registro
 * (cabecera X-People-Registro). Las APKs ya instaladas no la mandan y
 * siguen con su flujo de siempre (checklist de incorporación) sin romperse.
 */
class ExigirRegistroApp
{
    /** Rutas que el asistente de registro necesita. */
    private const PERMITIDAS = [
        'api.v1.logout',
        'api.v1.me',
        'api.v1.cambiar-contrasena',
        'api.v1.reautenticar',
        'api.v1.mobile.bootstrap',
        'api.v1.broadcasting.auth',
        'api.v1.dispositivos.*',
        'api.v1.colaborador.registro.*',
        'api.v1.colaborador.incorporacion.*',
        // Vista previa de la carta compromiso («No tengo este documento»).
        'api.v1.colaborador.carta-compromiso.*',
        'api.v1.colaborador.datos-faltantes',
        'api.v1.colaborador.perfil',
        'api.v1.colaborador.foto',
        'api.v1.colaborador.foto.*',
    ];

    public function __construct(private readonly RegistroAppService $registro) {}

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario === null
            || ! $request->hasHeader(RegistroAppService::CABECERA_CLIENTE)
            || $request->routeIs(...self::PERMITIDAS)
            || ! $this->registro->aplica($usuario)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Termina tu registro (datos y documentos) para entrar a la app.',
            'codigo' => 'registro_incompleto',
        ], 403);
    }
}
