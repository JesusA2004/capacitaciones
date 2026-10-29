<?php

namespace App\Http\Controllers\Api\V1\Integraciones;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AutenticarClienteIntegracion;
use App\Http\Resources\Api\V1\Integraciones\ColaboradorAuditoriaResource;
use App\Http\Resources\Api\V1\Integraciones\SucursalAuditoriaResource;
use App\Services\Integraciones\Auditoria\ColaboradoresAuditoriaService;
use App\Services\Integraciones\Auditoria\CursorAuditoria;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * API servidor-a-servidor para el sistema Auditoría (contrato
 * «people-auditoria/1»). Sólo lectura; independiente de la API móvil del
 * colaborador. Delgado: la consulta vive en ColaboradoresAuditoriaService.
 */
class AuditoriaController extends Controller
{
    public const CONTRATO = 'people-auditoria/1';

    private const FORMATO_FECHA = '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})?)?$/';

    public function __construct(private readonly ColaboradoresAuditoriaService $servicio) {}

    public function catalogo(Request $request): JsonResponse
    {
        $cliente = AutenticarClienteIntegracion::cliente($request);

        return response()->json([
            'contrato' => self::CONTRATO,
            'generado_en' => now()->toIso8601String(),
            'capacidades' => ['colaboradores', 'delta_updated_since', 'sucursales'],
            'empresas' => $this->servicio->empresas($cliente),
        ]);
    }

    public function colaboradores(Request $request): JsonResponse
    {
        $cliente = AutenticarClienteIntegracion::cliente($request);
        $errores = [];

        $empresaId = $this->entero($request->query('empresa_id'), 'empresa_id', 1, PHP_INT_MAX, $errores);
        $porPagina = $this->entero($request->query('per_page'), 'per_page', 1, ColaboradoresAuditoriaService::POR_PAGINA_MAXIMO, $errores) ?? ColaboradoresAuditoriaService::POR_PAGINA;
        $desde = $this->fecha($request->query('updated_since'), $errores);

        if ($errores !== []) {
            return $this->invalido($errores);
        }

        if ($empresaId !== null && ! $this->servicio->empresaVisible($cliente, $empresaId)) {
            return $this->error('La empresa no existe o no está permitida para este cliente.', 'empresa_no_encontrada', 404);
        }

        $filtros = sprintf('%s|%s', $empresaId ?? '', $desde?->utc()->format('Y-m-d H:i:s') ?? '');
        $cursor = $request->query('cursor');
        $posicion = null;

        if ($cursor !== null && $cursor !== '') {
            $posicion = is_string($cursor) && strlen($cursor) <= 500 ? CursorAuditoria::decodificar($cursor, $filtros) : null;

            if ($posicion === null) {
                return $this->invalido(['cursor' => ['El cursor no es válido o pertenece a otros filtros; reinicia la consulta sin cursor.']]);
            }
        }

        $pagina = $this->servicio->pagina($cliente, $empresaId, $desde, $posicion['u'] ?? null, $posicion['i'] ?? null, $porPagina);

        return response()->json([
            'data' => ColaboradorAuditoriaResource::collection($pagina['filas'])->resolve($request),
            'meta' => [
                'next_cursor' => $pagina['siguiente'] !== null ? CursorAuditoria::codificar($pagina['siguiente'], $filtros) : null,
                'has_more' => $pagina['siguiente'] !== null,
                'per_page' => $porPagina,
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    public function colaborador(Request $request, string $colaborador): JsonResponse
    {
        $cliente = AutenticarClienteIntegracion::cliente($request);
        $fila = preg_match('/^\d{1,18}$/', $colaborador) === 1 ? $this->servicio->uno($cliente, (int) $colaborador) : null;

        if ($fila === null) {
            return $this->error('El colaborador no existe o no está disponible para este cliente.', 'no_encontrado', 404);
        }

        return response()->json(['data' => (new ColaboradorAuditoriaResource($fila))->resolve($request)]);
    }

    public function sucursales(Request $request): JsonResponse
    {
        $cliente = AutenticarClienteIntegracion::cliente($request);
        $errores = [];
        $empresaId = $this->entero($request->query('empresa_id'), 'empresa_id', 1, PHP_INT_MAX, $errores);

        if ($errores !== []) {
            return $this->invalido($errores);
        }

        if ($empresaId !== null && ! $this->servicio->empresaVisible($cliente, $empresaId)) {
            return $this->error('La empresa no existe o no está permitida para este cliente.', 'empresa_no_encontrada', 404);
        }

        return response()->json([
            'data' => SucursalAuditoriaResource::collection($this->servicio->sucursales($cliente, $empresaId))->resolve($request),
        ]);
    }

    /**
     * @param  array<string, list<string>>  $errores
     */
    private function entero(mixed $valor, string $campo, int $minimo, int $maximo, array &$errores): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (! is_string($valor) || preg_match('/^\d{1,18}$/', $valor) !== 1 || (int) $valor < $minimo || (int) $valor > $maximo) {
            $errores[$campo] = [$maximo === PHP_INT_MAX
                ? sprintf('%s debe ser un número entero mayor o igual a %d.', $campo, $minimo)
                : sprintf('%s debe ser un número entero entre %d y %d.', $campo, $minimo, $maximo)];

            return null;
        }

        return (int) $valor;
    }

    /**
     * @param  array<string, list<string>>  $errores
     */
    private function fecha(mixed $valor, array &$errores): ?CarbonImmutable
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        // Un «+06:00» sin codificar en la URL llega como espacio: se repone.
        if (is_string($valor)) {
            $valor = (string) preg_replace('/(T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?) (\d{2}:?\d{2})$/', '$1+$2', $valor);
        }

        if (is_string($valor) && preg_match(self::FORMATO_FECHA, $valor) === 1) {
            try {
                return CarbonImmutable::parse($valor, 'UTC');
            } catch (Throwable) {
                // Cae al error de abajo.
            }
        }

        $errores['updated_since'] = ['updated_since debe ser una fecha ISO-8601 (p. ej. 2026-10-29T08:00:00-06:00).'];

        return null;
    }

    /**
     * @param  array<string, list<string>>  $errores
     */
    private function invalido(array $errores): JsonResponse
    {
        return response()->json(['message' => 'Parámetros no válidos.', 'codigo' => 'parametros_invalidos', 'errors' => $errores], 422);
    }

    private function error(string $mensaje, string $codigo, int $estado): JsonResponse
    {
        return response()->json(['message' => $mensaje, 'codigo' => $codigo], $estado);
    }
}
