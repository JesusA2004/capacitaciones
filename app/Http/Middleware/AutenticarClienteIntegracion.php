<?php

namespace App\Http\Middleware;

use App\Models\ClienteIntegracion;
use App\Services\Integraciones\TokenIntegracionService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticación servidor-a-servidor por `Authorization: Bearer <token>` de
 * un ClienteIntegracion (no Sanctum, no sesión): 401 sin token válido, 403 sin
 * la habilidad pedida. Los intentos fallidos se limitan por IP para que
 * nadie pruebe tokens al azar. Nunca escribe el token en el log.
 */
class AutenticarClienteIntegracion
{
    public const ATRIBUTO = 'cliente_integracion';

    private const FALLOS_POR_MINUTO = 20;

    public function __construct(private readonly TokenIntegracionService $tokens) {}

    public static function con(string $habilidad): string
    {
        return sprintf('%s:%s', self::class, $habilidad);
    }

    public static function cliente(Request $request): ClienteIntegracion
    {
        $cliente = $request->attributes->get(self::ATRIBUTO);

        abort_unless($cliente instanceof ClienteIntegracion, 401);

        return $cliente;
    }

    public function handle(Request $request, Closure $next, string $habilidad): Response
    {
        $llaveFallos = sprintf('integracion-fallos:%s', (string) $request->ip());

        if (RateLimiter::tooManyAttempts($llaveFallos, self::FALLOS_POR_MINUTO)) {
            $espera = RateLimiter::availableIn($llaveFallos);

            return $this->error('Demasiados intentos fallidos de autenticación. Intenta más tarde.', 'limite_excedido', 429, ['Retry-After' => (string) $espera]);
        }

        $cliente = $this->tokens->autenticar($request->bearerToken());

        if ($cliente === null) {
            RateLimiter::hit($llaveFallos, 60);

            return $this->error('Token de integración ausente o no válido.', 'no_autenticado', 401, ['WWW-Authenticate' => 'Bearer']);
        }

        if (! $cliente->puede($habilidad)) {
            return $this->error('El token de integración no tiene permiso para este recurso.', 'sin_permiso', 403);
        }

        $request->attributes->set(self::ATRIBUTO, $cliente);
        $this->tokens->registrarUso($cliente, $request->ip());

        return $next($request);
    }

    /**
     * @param  array<string, string>  $encabezados
     */
    private function error(string $mensaje, string $codigo, int $estado, array $encabezados = []): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'codigo' => $codigo], $estado, $encabezados);
    }
}
