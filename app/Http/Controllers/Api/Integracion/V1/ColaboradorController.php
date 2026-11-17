<?php

namespace App\Http\Controllers\Api\Integracion\V1;

use App\Enums\HabilidadIntegracion;
use App\Http\Middleware\AutenticarClienteIntegracion;
use App\Http\Resources\Api\Integracion\V1\ColaboradorIntegracionResource;
use App\Models\ClienteIntegracion;
use App\Services\Integraciones\Auditoria\CursorAuditoria;
use App\Services\Integraciones\Sistemas\CatalogosIntegracionService;
use App\Services\Integraciones\Sistemas\PlantillaIntegracionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plantilla de colaboradores con paginación por cursor (actualizado_en, id)
 * y sincronización incremental (`actualizado_desde`). Los datos sensibles
 * sólo con `incluir=identificacion|salario` Y la habilidad separada.
 */
class ColaboradorController extends ControladorIntegracion
{
    public function __construct(
        private readonly PlantillaIntegracionService $plantilla,
        private readonly CatalogosIntegracionService $catalogos,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $cliente = AutenticarClienteIntegracion::cliente($request);
        $errores = [];
        $porDefecto = max(1, (int) config('integraciones.sistemas.por_pagina', 200));
        $maximo = max($porDefecto, (int) config('integraciones.sistemas.por_pagina_maximo', 1000));
        $empresaId = $this->entero($request, 'empresa_id', 1, PHP_INT_MAX, $errores);
        $sucursalId = $this->entero($request, 'sucursal_id', 1, PHP_INT_MAX, $errores);
        $porPagina = $this->entero($request, 'por_pagina', 1, $maximo, $errores) ?? $porDefecto;
        $desde = $this->fechaHora($request, 'actualizado_desde', $errores);
        $estatus = $request->query('estatus', PlantillaIntegracionService::ESTATUS_TODOS);
        $numero = $request->query('numero_empleado');

        if (! is_string($estatus) || ! in_array($estatus, [PlantillaIntegracionService::ESTATUS_ACTIVOS, PlantillaIntegracionService::ESTATUS_BAJAS, PlantillaIntegracionService::ESTATUS_TODOS], true)) {
            $errores['estatus'] = ['estatus debe ser activos, bajas o todos.'];
            $estatus = PlantillaIntegracionService::ESTATUS_TODOS;
        }

        if ($numero !== null && (! is_string($numero) || $numero === '' || mb_strlen($numero) > 50)) {
            $errores['numero_empleado'] = ['numero_empleado debe ser un texto de 1 a 50 caracteres.'];
        }

        $incluir = $this->incluir($request, $cliente, $errores);

        if ($incluir instanceof JsonResponse) {
            return $incluir;
        }

        if ($errores !== []) {
            return $this->invalido($errores);
        }

        if ($empresaId !== null && ! $this->catalogos->empresaVisible($cliente, $empresaId)) {
            return $this->error('La empresa no existe o no está permitida para este cliente.', 'empresa_no_encontrada', 404);
        }

        if ($sucursalId !== null && ! $this->catalogos->sucursalVisible($cliente, $sucursalId)) {
            return $this->error('La sucursal no existe o no está permitida para este cliente.', 'sucursal_no_encontrada', 404);
        }

        $filtros = [
            'empresa_id' => $empresaId,
            'sucursal_id' => $sucursalId,
            'estatus' => $estatus,
            'actualizado_desde' => $desde,
            'numero_empleado' => is_string($numero) ? $numero : null,
        ];
        // El cursor va ligado a TODOS los filtros: uno de otra consulta se rechaza.
        $huella = sprintf(
            'integracion-v1|colaboradores|%s|%s|%s|%s|%s|%s',
            $empresaId ?? '',
            $sucursalId ?? '',
            $estatus,
            $desde?->utc()->format('Y-m-d H:i:s') ?? '',
            $filtros['numero_empleado'] ?? '',
            implode(',', $incluir),
        );
        $cursor = $request->query('cursor');
        $posicion = null;

        if ($cursor !== null && $cursor !== '') {
            $posicion = is_string($cursor) && strlen($cursor) <= 500 ? CursorAuditoria::decodificar($cursor, $huella) : null;

            if ($posicion === null) {
                return $this->invalido(['cursor' => ['El cursor no es válido o pertenece a otros filtros; reinicia la consulta sin cursor.']]);
            }
        }

        $pagina = $this->plantilla->pagina($cliente, $filtros, $incluir, $posicion, $porPagina);
        $request->attributes->set(ColaboradorIntegracionResource::ATRIBUTO_INCLUIR, $incluir);
        $siguiente = $pagina['siguiente'] !== null ? CursorAuditoria::codificar($pagina['siguiente'], $huella) : null;

        return $this->responder($request, ColaboradorIntegracionResource::collection($pagina['filas'])->resolve($request), [
            'siguiente_cursor' => $siguiente,
            'hay_mas' => $siguiente !== null,
            'por_pagina' => $porPagina,
            'server_time' => now()->toIso8601String(),
        ], $incluir !== []);
    }

    public function show(Request $request, string $colaborador): JsonResponse
    {
        $cliente = AutenticarClienteIntegracion::cliente($request);
        $errores = [];
        $incluir = $this->incluir($request, $cliente, $errores);

        if ($incluir instanceof JsonResponse) {
            return $incluir;
        }

        if ($errores !== []) {
            return $this->invalido($errores);
        }

        $fila = preg_match('/^\d{1,18}$/', $colaborador) === 1 ? $this->plantilla->uno($cliente, (int) $colaborador, $incluir) : null;

        if ($fila === null) {
            return $this->error('El colaborador no existe o no está disponible para este cliente.', 'no_encontrado', 404);
        }

        $request->attributes->set(ColaboradorIntegracionResource::ATRIBUTO_INCLUIR, $incluir);

        return $this->responder($request, (new ColaboradorIntegracionResource($fila))->resolve($request), sensible: $incluir !== [], registros: 1);
    }

    /**
     * `incluir=identificacion,salario`: cada uno exige su habilidad (403 si
     * falta; nunca se omite en silencio).
     *
     * @param  array<string, list<string>>  $errores
     * @return list<string>|JsonResponse
     */
    private function incluir(Request $request, ClienteIntegracion $cliente, array &$errores): array|JsonResponse
    {
        $valor = $request->query('incluir');

        if ($valor === null || $valor === '') {
            return [];
        }

        if (! is_string($valor)) {
            $errores['incluir'] = ['incluir debe ser una lista separada por comas: identificacion, salario.'];

            return [];
        }

        $habilidades = [
            PlantillaIntegracionService::INCLUIR_IDENTIFICACION => HabilidadIntegracion::ColaboradoresIdentificacion,
            PlantillaIntegracionService::INCLUIR_SALARIO => HabilidadIntegracion::ColaboradoresSalario,
        ];
        $pedidos = array_values(array_unique(array_filter(array_map('trim', explode(',', $valor)), fn (string $v): bool => $v !== '')));
        sort($pedidos);

        foreach ($pedidos as $pedido) {
            if (! isset($habilidades[$pedido])) {
                $errores['incluir'] = [sprintf('«%s» no se puede incluir; usa identificacion o salario.', $pedido)];

                return [];
            }

            if (! $cliente->puede($habilidades[$pedido]->value)) {
                return $this->error('El token de integración no tiene permiso para estos datos sensibles.', 'sin_permiso', 403, ['habilidad_requerida' => $habilidades[$pedido]->value]);
            }
        }

        return $pedidos;
    }
}
