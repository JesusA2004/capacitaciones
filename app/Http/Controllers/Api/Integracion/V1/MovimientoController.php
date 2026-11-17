<?php

namespace App\Http\Controllers\Api\Integracion\V1;

use App\Http\Middleware\AutenticarClienteIntegracion;
use App\Http\Resources\Api\Integracion\V1\AltaIntegracionResource;
use App\Http\Resources\Api\Integracion\V1\BajaIntegracionResource;
use App\Services\Integraciones\Sistemas\CatalogosIntegracionService;
use App\Services\Integraciones\Sistemas\MovimientosIntegracionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Altas y bajas por rango de fechas (obligatorio y acotado).
 */
class MovimientoController extends ControladorIntegracion
{
    public function __construct(
        private readonly MovimientosIntegracionService $movimientos,
        private readonly CatalogosIntegracionService $catalogos,
    ) {}

    public function altas(Request $request): JsonResponse
    {
        return $this->consultar($request, fn (Collection $sucursales, CarbonImmutable $desde, CarbonImmutable $hasta): array => AltaIntegracionResource::collection($this->movimientos->altas($sucursales, $desde, $hasta))->resolve($request));
    }

    public function bajas(Request $request): JsonResponse
    {
        return $this->consultar($request, fn (Collection $sucursales, CarbonImmutable $desde, CarbonImmutable $hasta): array => BajaIntegracionResource::collection($this->movimientos->bajas($sucursales, $desde, $hasta))->resolve($request));
    }

    /**
     * @param  callable(Collection<int, int>, CarbonImmutable, CarbonImmutable): array<int|string, mixed>  $obtener
     */
    private function consultar(Request $request, callable $obtener): JsonResponse
    {
        $cliente = AutenticarClienteIntegracion::cliente($request);
        $errores = [];
        $rango = $this->rango($request, $errores);
        $empresaId = $this->entero($request, 'empresa_id', 1, PHP_INT_MAX, $errores);
        $sucursalId = $this->entero($request, 'sucursal_id', 1, PHP_INT_MAX, $errores);

        if ($errores !== [] || $rango === null) {
            return $this->invalido($errores);
        }

        if ($empresaId !== null && ! $this->catalogos->empresaVisible($cliente, $empresaId)) {
            return $this->error('La empresa no existe o no está permitida para este cliente.', 'empresa_no_encontrada', 404);
        }

        if ($sucursalId !== null && ! $this->catalogos->sucursalVisible($cliente, $sucursalId)) {
            return $this->error('La sucursal no existe o no está permitida para este cliente.', 'sucursal_no_encontrada', 404);
        }

        [$desde, $hasta] = $rango;
        $data = $obtener($this->catalogos->idsSucursales($cliente, $empresaId, $sucursalId), $desde, $hasta);

        return $this->responder($request, $data, ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(), 'total' => count($data)]);
    }
}
