<?php

namespace App\Services\Seguimiento;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente HTTP de SOLO LECTURA hacia la API v1 de Reportes (servidor a
 * servidor, token Sanctum de un ApiConsumer). Única puerta de PEOPLE hacia
 * Reportes: timeouts, reintentos ante fallas transitorias, caché de las
 * respuestas correctas y errores traducidos a un mensaje amable
 * (ReportesNoDisponibleException) — nunca truena la pantalla.
 *
 * Config: config/seguimiento.php → reportes.*. Contrato: docs/SEGUIMIENTO.md.
 */
class ReportesApiCliente
{
    private const PREFIJO_CACHE = 'seguimiento:reportes:v1:';

    public function estaConfigurado(): bool
    {
        return $this->url() !== '' && $this->token() !== '';
    }

    /**
     * GET a /api/v1/{ruta}, con caché de las respuestas correctas.
     *
     * @param  array<string, scalar|null>  $consulta
     * @return array<string, mixed>
     *
     * @throws ReportesNoDisponibleException
     */
    public function get(string $ruta, array $consulta = []): array
    {
        if (! $this->estaConfigurado()) {
            throw new ReportesNoDisponibleException('La conexión con Reportes todavía no está configurada. Pide a Sistemas que capture REPORTES_API_URL y REPORTES_API_TOKEN.');
        }

        $consulta = array_filter($consulta, fn ($valor): bool => $valor !== null && $valor !== '');
        ksort($consulta);
        $ruta = ltrim($ruta, '/');
        $minutos = max(0, (int) config('seguimiento.reportes.cache_minutos', 30));
        $llave = sprintf('%s%s', self::PREFIJO_CACHE, md5(sprintf('%s|%s|%s', $this->url(), $ruta, http_build_query($consulta))));

        if ($minutos === 0) {
            return $this->pedir($ruta, $consulta);
        }

        /** @var array<string, mixed> $datos */
        $datos = Cache::remember($llave, now()->addMinutes($minutos), fn (): array => $this->pedir($ruta, $consulta));

        return $datos;
    }

    /**
     * Comprueba la conexión (sin caché): health público + capabilities con token.
     *
     * @return array{ok: bool, mensaje: string, capacidades: array<string, mixed>|null}
     */
    public function probar(): array
    {
        try {
            $capacidades = $this->pedir('capabilities', []);

            return ['ok' => true, 'mensaje' => 'Conexión con Reportes correcta.', 'capacidades' => $capacidades];
        } catch (ReportesNoDisponibleException $e) {
            return ['ok' => false, 'mensaje' => $e->getMessage(), 'capacidades' => null];
        }
    }

    /**
     * @param  array<string, scalar|null>  $consulta
     * @return array<string, mixed>
     *
     * @throws ReportesNoDisponibleException
     */
    private function pedir(string $ruta, array $consulta): array
    {
        if (! $this->estaConfigurado()) {
            throw new ReportesNoDisponibleException('La conexión con Reportes todavía no está configurada. Pide a Sistemas que capture REPORTES_API_URL y REPORTES_API_TOKEN.');
        }

        $url = sprintf('%s/api/v1/%s', $this->url(), $ruta);

        try {
            $respuesta = Http::acceptJson()
                ->withToken($this->token())
                ->withUserAgent('MR-LANA-PEOPLE/seguimiento')
                ->timeout(max(1, (int) config('seguimiento.reportes.timeout', 60)))
                ->connectTimeout(max(1, (int) config('seguimiento.reportes.timeout_conexion', 5)))
                ->withOptions(['verify' => (bool) config('seguimiento.reportes.verificar_ssl', true)])
                ->retry(
                    max(1, 1 + (int) config('seguimiento.reportes.reintentos', 2)),
                    max(0, (int) config('seguimiento.reportes.espera_reintento_ms', 400)),
                    fn (Throwable $e): bool => $e instanceof ConnectionException
                        || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError())),
                    throw: false,
                )
                ->get($url, $consulta);
        } catch (ConnectionException $e) {
            Log::warning('Seguimiento: Reportes no responde.', ['ruta' => $ruta, 'error' => $e->getMessage()]);

            throw new ReportesNoDisponibleException('Reportes no responde en este momento. Intenta de nuevo en unos minutos; si sigue igual, avísalo a Sistemas.');
        } catch (Throwable $e) {
            Log::warning('Seguimiento: error inesperado al consultar Reportes.', ['ruta' => $ruta, 'error' => $e->getMessage()]);

            throw new ReportesNoDisponibleException('No se pudo consultar Reportes por un error inesperado. Avísalo a Sistemas.');
        }

        return $this->interpretar($respuesta, $ruta);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ReportesNoDisponibleException
     */
    private function interpretar(Response $respuesta, string $ruta): array
    {
        $estado = $respuesta->status();

        if ($respuesta->successful()) {
            $datos = $respuesta->json();

            if (! is_array($datos)) {
                Log::warning('Seguimiento: Reportes devolvió una respuesta que no es JSON.', ['ruta' => $ruta, 'estado' => $estado]);

                throw new ReportesNoDisponibleException('Reportes devolvió una respuesta que PEOPLE no reconoce. Avísalo a Sistemas.', $estado);
            }

            /** @var array<string, mixed> $datos */
            return $datos;
        }

        $motivo = $respuesta->json('message');
        $motivo = is_string($motivo) ? trim($motivo) : '';

        Log::warning('Seguimiento: Reportes rechazó la consulta.', ['ruta' => $ruta, 'estado' => $estado, 'mensaje' => $motivo]);

        $mensaje = match (true) {
            $estado === 401 => 'Reportes no reconoce el token de PEOPLE (vencido o revocado). Pide a Sistemas que emita uno nuevo.',
            $estado === 403 => $motivo !== '' ? sprintf('Reportes no autorizó la consulta: %s', $motivo) : 'Reportes no autorizó la consulta para el token de PEOPLE.',
            $estado === 404 => 'Reportes no encontró el periodo, la sucursal o el colaborador solicitado.',
            $estado === 422 => $motivo !== '' ? sprintf('Reportes rechazó la consulta: %s', $motivo) : 'Reportes rechazó los parámetros de la consulta.',
            $estado === 429 => 'Reportes está recibiendo demasiadas consultas. Espera un minuto e inténtalo de nuevo.',
            $estado >= 500 => 'Reportes tuvo un error interno al preparar estos datos. Intenta más tarde.',
            default => sprintf('Reportes respondió con un error inesperado (%d).', $estado),
        };

        throw new ReportesNoDisponibleException($mensaje, $estado);
    }

    private function url(): string
    {
        return rtrim(trim((string) config('seguimiento.reportes.url', '')), '/');
    }

    private function token(): string
    {
        return trim((string) config('seguimiento.reportes.token', ''));
    }
}
