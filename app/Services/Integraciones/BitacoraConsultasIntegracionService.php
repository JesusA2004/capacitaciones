<?php

namespace App\Services\Integraciones;

use App\Http\Middleware\AutenticarClienteIntegracion;
use App\Models\ClienteIntegracion;
use App\Models\ConsultaIntegracion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Bitácora de cada consulta de un cliente de integración (tabla
 * consultas_integracion): quién, qué ruta, con qué filtros, qué estado
 * respondió, cuántos registros y cuánto tardó. Nunca guarda el token, el
 * cuerpo de la respuesta ni valores de parámetros desconocidos o largos.
 *
 * Un fallo al escribir la bitácora nunca rompe la consulta: se registra en el
 * log (Log::warning) y se sigue.
 */
class BitacoraConsultasIntegracionService
{
    /** Atributo de la petición donde el controlador deja cuántos registros devolvió. */
    public const ATRIBUTO_REGISTROS = 'integracion_registros';

    /**
     * Parámetros cuyo valor se guarda (filtros); de cualquier otro sólo
     * queda el nombre. El cursor se guarda truncado.
     *
     * @var list<string>
     */
    private const PARAMETROS = [
        'actualizado_desde', 'agrupacion', 'desde', 'hasta', 'empresa_id', 'sucursal_id', 'estatus', 'incluir',
        'por_pagina', 'tipo', 'numero_empleado', 'desglose', 'contrato', 'per_page', 'updated_since',
    ];

    public function registrar(Request $request, Response $response, float $inicio): void
    {
        if (! (bool) config('integraciones.sistemas.bitacora', true)) {
            return;
        }

        $cliente = $request->attributes->get(AutenticarClienteIntegracion::ATRIBUTO);

        // Sin cliente (401) no hay a quién atribuir la consulta: esos intentos
        // ya se limitan por IP en el middleware de autenticación.
        if (! $cliente instanceof ClienteIntegracion) {
            return;
        }

        try {
            $registros = $request->attributes->get(self::ATRIBUTO_REGISTROS);

            ConsultaIntegracion::query()->create([
                'cliente_integracion_id' => $cliente->id,
                'metodo' => $request->getMethod(),
                'ruta' => $request->route()?->getName(),
                'url' => mb_substr(sprintf('/%s', ltrim($request->path(), '/')), 0, 255),
                'parametros' => $this->parametros($request),
                'estado' => $response->getStatusCode(),
                'registros' => is_int($registros) ? $registros : null,
                'duracion_ms' => (int) round((microtime(true) - $inicio) * 1000),
                'ip' => $request->ip(),
            ]);
        } catch (Throwable $e) {
            Log::warning('No se pudo registrar la consulta de integración en la bitácora.', [
                'cliente' => $cliente->clave,
                'ruta' => $request->route()?->getName(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, string|null>|null
     */
    private function parametros(Request $request): ?array
    {
        $salida = [];

        foreach ($request->query() as $nombre => $valor) {
            $nombre = mb_substr((string) $nombre, 0, 40);

            if ($nombre === 'cursor') {
                $salida[$nombre] = is_string($valor) ? sprintf('%s…', mb_substr($valor, 0, 12)) : null;
            } elseif (in_array($nombre, self::PARAMETROS, true) && is_scalar($valor)) {
                $salida[$nombre] = mb_substr((string) $valor, 0, 100);
            } else {
                $salida[$nombre] = null;
            }

            if (count($salida) >= 20) {
                break;
            }
        }

        return $salida === [] ? null : $salida;
    }
}
