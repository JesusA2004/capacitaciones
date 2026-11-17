<?php

namespace App\Http\Middleware;

use App\Services\Integraciones\BitacoraConsultasIntegracionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registra en la bitácora (consultas_integracion) cada consulta de un cliente
 * de integración, ya con la respuesta enviada (terminate). Va ANTES de
 * AutenticarClienteIntegracion en la pila para ver también los 403 y 429.
 */
class RegistrarConsultaIntegracion
{
    private const INICIO = 'integracion_inicio';

    public function __construct(private readonly BitacoraConsultasIntegracionService $bitacora) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::INICIO, microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $inicio = $request->attributes->get(self::INICIO);

        $this->bitacora->registrar($request, $response, is_float($inicio) ? $inicio : microtime(true));
    }
}
