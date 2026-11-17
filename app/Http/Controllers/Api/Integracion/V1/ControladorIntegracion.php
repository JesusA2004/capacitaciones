<?php

namespace App\Http\Controllers\Api\Integracion\V1;

use App\Http\Controllers\Controller;
use App\Services\Integraciones\BitacoraConsultasIntegracionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Base de /api/integracion/v1 (servidor-a-servidor, sólo lectura). Una sola
 * forma de responder:
 *
 *  - Éxito: `{contrato, generado_en, data, meta?}` con ETag (de `data` y
 *    `meta` sin la hora del servidor): un `If-None-Match` igual responde 304
 *    sin cuerpo. Datos personales: `Cache-Control: private, no-cache`;
 *    sensibles (identificación, salario): `no-store`.
 *  - Error: `{message, codigo, errors?}` (401, 403, 404, 422, 429).
 */
abstract class ControladorIntegracion extends Controller
{
    public const CONTRATO = 'people-integracion/1';

    private const FORMATO_FECHA_HORA = '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})?)?$/';

    /**
     * @param  array<int|string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    protected function responder(Request $request, array $data, array $meta = [], bool $sensible = false, ?int $registros = null): JsonResponse
    {
        $request->attributes->set(BitacoraConsultasIntegracionService::ATRIBUTO_REGISTROS, $registros ?? count($data));
        $etag = sprintf('"%s"', sha1((string) json_encode([$data, array_diff_key($meta, ['server_time' => true])])));
        $cache = $sensible ? 'no-store, private' : 'private, no-cache';

        if (! $sensible && in_array($etag, $request->getETags(), true)) {
            return response()->json(null, 304)->setContent('')->header('ETag', $etag)->header('Cache-Control', $cache);
        }

        $cuerpo = ['contrato' => self::CONTRATO, 'generado_en' => now()->toIso8601String(), 'data' => $data];

        if ($meta !== []) {
            $cuerpo['meta'] = $meta;
        }

        $respuesta = response()->json($cuerpo)->header('Cache-Control', $cache);

        return $sensible ? $respuesta : $respuesta->header('ETag', $etag);
    }

    /**
     * @param  array<string, list<string>>  $errores
     */
    protected function invalido(array $errores): JsonResponse
    {
        return response()->json(['message' => 'Parámetros no válidos.', 'codigo' => 'parametros_invalidos', 'errors' => $errores], 422);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function error(string $mensaje, string $codigo, int $estado, array $extra = []): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'codigo' => $codigo, ...$extra], $estado);
    }

    /**
     * @param  array<string, list<string>>  $errores
     */
    protected function entero(Request $request, string $campo, int $minimo, int $maximo, array &$errores): ?int
    {
        $valor = $request->query($campo);

        if ($valor === null || $valor === '') {
            return null;
        }

        if (! is_string($valor) || preg_match('/^\d{1,18}$/', $valor) !== 1 || (int) $valor < $minimo || (int) $valor > $maximo) {
            $errores[$campo] = [sprintf('%s debe ser un número entero entre %d y %d.', $campo, $minimo, $maximo)];

            return null;
        }

        return (int) $valor;
    }

    /**
     * Fecha Y-m-d (zona de México, como todas las fechas laborales de People).
     *
     * @param  array<string, list<string>>  $errores
     */
    protected function fecha(Request $request, string $campo, bool $requerida, array &$errores): ?CarbonImmutable
    {
        $valor = $request->query($campo);

        if ($valor === null || $valor === '') {
            if ($requerida) {
                $errores[$campo] = [sprintf('%s es obligatorio (formato AAAA-MM-DD).', $campo)];
            }

            return null;
        }

        $fecha = is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $valor) : false;

        if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
            $errores[$campo] = [sprintf('%s debe ser una fecha válida con formato AAAA-MM-DD.', $campo)];

            return null;
        }

        return $fecha;
    }

    /**
     * Marca de tiempo ISO-8601 (sincronización incremental `actualizado_desde`).
     *
     * @param  array<string, list<string>>  $errores
     */
    protected function fechaHora(Request $request, string $campo, array &$errores): ?CarbonImmutable
    {
        $valor = $request->query($campo);

        if ($valor === null || $valor === '') {
            return null;
        }

        // Un «+06:00» sin codificar en la URL llega como espacio: se repone.
        if (is_string($valor)) {
            $valor = (string) preg_replace('/(T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?) (\d{2}:?\d{2})$/', '$1+$2', $valor);

            if (preg_match(self::FORMATO_FECHA_HORA, $valor) === 1) {
                try {
                    return CarbonImmutable::parse($valor, 'UTC');
                } catch (Throwable) {
                    // Cae al error de abajo.
                }
            }
        }

        $errores[$campo] = [sprintf('%s debe ser una fecha ISO-8601 (p. ej. 2026-11-17T08:00:00-06:00).', $campo)];

        return null;
    }

    /**
     * Rango [desde, hasta] obligatorio y acotado (config integraciones.sistemas.rango_maximo_dias).
     *
     * @param  array<string, list<string>>  $errores
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    protected function rango(Request $request, array &$errores): ?array
    {
        $desde = $this->fecha($request, 'desde', true, $errores);
        $hasta = $this->fecha($request, 'hasta', true, $errores);

        if ($desde === null || $hasta === null) {
            return null;
        }

        $maximo = max(1, (int) config('integraciones.sistemas.rango_maximo_dias', 731));

        if ($hasta->lt($desde)) {
            $errores['hasta'] = ['hasta debe ser igual o posterior a desde.'];

            return null;
        }

        if ($desde->diffInDays($hasta) + 1 > $maximo) {
            $errores['hasta'] = [sprintf('El rango no puede pasar de %d días.', $maximo)];

            return null;
        }

        return [$desde, $hasta];
    }
}
