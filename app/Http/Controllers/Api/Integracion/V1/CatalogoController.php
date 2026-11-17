<?php

namespace App\Http\Controllers\Api\Integracion\V1;

use App\Enums\HabilidadIntegracion;
use App\Http\Middleware\AutenticarClienteIntegracion;
use App\Http\Resources\Api\Integracion\V1\DepartamentoIntegracionResource;
use App\Http\Resources\Api\Integracion\V1\EmpresaIntegracionResource;
use App\Http\Resources\Api\Integracion\V1\PuestoIntegracionResource;
use App\Http\Resources\Api\Integracion\V1\SucursalIntegracionResource;
use App\Services\Integraciones\Sistemas\CatalogosIntegracionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Capacidades del token y catálogos (empresas, sucursales, puestos,
 * departamentos). Delgado: todo vive en CatalogosIntegracionService.
 */
class CatalogoController extends ControladorIntegracion
{
    public function __construct(private readonly CatalogosIntegracionService $catalogos) {}

    /**
     * Qué puede hacer ESTE token (sólo exige un token válido).
     */
    public function capacidades(Request $request): JsonResponse
    {
        $cliente = AutenticarClienteIntegracion::cliente($request);

        return $this->responder($request, [
            'cliente' => ['clave' => $cliente->clave, 'nombre' => $cliente->nombre],
            'habilidades' => array_map(fn (string $h): array => [
                'clave' => $h,
                'etiqueta' => HabilidadIntegracion::tryFrom($h)?->etiqueta() ?? $h,
            ], $cliente->habilidades),
            'empresas' => $cliente->todas_las_empresas ? 'todas' : ($cliente->empresasPermitidas() ?? []),
            'limite_por_minuto' => $cliente->limitePorMinuto(),
        ], registros: 1);
    }

    public function empresas(Request $request): JsonResponse
    {
        $cliente = AutenticarClienteIntegracion::cliente($request);

        return $this->responder($request, EmpresaIntegracionResource::collection($this->catalogos->empresas($cliente))->resolve($request));
    }

    public function sucursales(Request $request): JsonResponse
    {
        $cliente = AutenticarClienteIntegracion::cliente($request);
        $errores = [];
        $empresaId = $this->entero($request, 'empresa_id', 1, PHP_INT_MAX, $errores);
        $desde = $this->fechaHora($request, 'actualizado_desde', $errores);

        if ($errores !== []) {
            return $this->invalido($errores);
        }

        if ($empresaId !== null && ! $this->catalogos->empresaVisible($cliente, $empresaId)) {
            return $this->error('La empresa no existe o no está permitida para este cliente.', 'empresa_no_encontrada', 404);
        }

        return $this->responder($request, SucursalIntegracionResource::collection($this->catalogos->sucursales($cliente, $empresaId, $desde))->resolve($request));
    }

    public function puestos(Request $request): JsonResponse
    {
        AutenticarClienteIntegracion::cliente($request);
        $errores = [];
        $desde = $this->fechaHora($request, 'actualizado_desde', $errores);

        if ($errores !== []) {
            return $this->invalido($errores);
        }

        return $this->responder($request, PuestoIntegracionResource::collection($this->catalogos->puestos($desde))->resolve($request));
    }

    public function departamentos(Request $request): JsonResponse
    {
        AutenticarClienteIntegracion::cliente($request);
        $errores = [];
        $desde = $this->fechaHora($request, 'actualizado_desde', $errores);

        if ($errores !== []) {
            return $this->invalido($errores);
        }

        return $this->responder($request, DepartamentoIntegracionResource::collection($this->catalogos->departamentos($desde))->resolve($request));
    }
}
