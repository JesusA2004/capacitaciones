<?php

namespace App\Http\Controllers\Api\Integracion\V1;

use App\Http\Middleware\AutenticarClienteIntegracion;
use App\Http\Resources\Api\Integracion\V1\RotacionPeriodoIntegracionResource;
use App\Services\Integraciones\Sistemas\CatalogosIntegracionService;
use App\Services\Integraciones\Sistemas\PeriodosIntegracion;
use App\Services\Integraciones\Sistemas\RotacionIntegracionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Rotación por periodo (rango, semanal, bloques de 4 semanas o mes
 * calendario) y, con `desglose=sucursal`, por sucursal. Un cálculo idéntico
 * se reutiliza unos segundos (config integraciones.sistemas.rotacion_cache_segundos).
 */
class RotacionController extends ControladorIntegracion
{
    public function __construct(
        private readonly RotacionIntegracionService $rotacion,
        private readonly CatalogosIntegracionService $catalogos,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $cliente = AutenticarClienteIntegracion::cliente($request);
        $errores = [];
        $rango = $this->rango($request, $errores);
        $empresaId = $this->entero($request, 'empresa_id', 1, PHP_INT_MAX, $errores);
        $sucursalId = $this->entero($request, 'sucursal_id', 1, PHP_INT_MAX, $errores);
        $agrupacion = $request->query('agrupacion', PeriodosIntegracion::RANGO);
        $desglose = $request->query('desglose', 'sucursal');

        if (! is_string($agrupacion) || ! in_array($agrupacion, PeriodosIntegracion::AGRUPACIONES, true)) {
            $errores['agrupacion'] = [sprintf('agrupacion debe ser una de: %s.', implode(', ', PeriodosIntegracion::AGRUPACIONES))];
            $agrupacion = PeriodosIntegracion::RANGO;
        }

        if (! in_array($desglose, ['sucursal', 'total'], true)) {
            $errores['desglose'] = ['desglose debe ser sucursal o total.'];
        }

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
        $porSucursal = $desglose === 'sucursal';
        $sucursales = $this->catalogos->idsSucursales($cliente, $empresaId, $sucursalId);
        $maximo = max(1, (int) config('integraciones.sistemas.periodos_maximos', 120));
        $segundos = max(0, (int) config('integraciones.sistemas.rotacion_cache_segundos', 60));
        $calcular = fn (): array => $this->rotacion->calcular($sucursales, $agrupacion, $desde, $hasta, $porSucursal, $maximo);
        $llave = sprintf('integracion:rotacion:%s', sha1(implode('|', [$agrupacion, $desde->toDateString(), $hasta->toDateString(), $porSucursal ? 's' : 't', $sucursales->implode(','), now()->toDateString()])));

        try {
            $resultado = $segundos > 0 ? Cache::remember($llave, $segundos, $calcular) : $calcular();
        } catch (InvalidArgumentException $e) {
            return $this->invalido(['desde' => [$e->getMessage()]]);
        }

        return $this->responder($request, RotacionPeriodoIntegracionResource::collection($resultado['periodos'])->resolve($request), [
            'agrupacion' => $resultado['agrupacion'],
            'desde' => $resultado['desde'],
            'hasta' => $resultado['hasta'],
            'fuente_plantilla' => $resultado['fuente_plantilla'],
            'formula' => 'rotacion_pct = bajas / plantilla_promedio * 100; plantilla_promedio = (plantilla_inicial + plantilla_final) / 2, salvo mes_calendario (plantilla promedio oficial del Tablero de Rotación)',
        ]);
    }
}
