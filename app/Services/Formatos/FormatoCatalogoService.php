<?php

namespace App\Services\Formatos;

use App\Enums\TipoPlantillaDocumento;
use App\Models\Colaborador;
use App\Models\DocumentTemplate;
use App\Services\Plantillas\PlantillaDocumentoService;

/**
 * Catálogo de plantillas activas listas para generar un formato: usado por
 * el panel web (Rh\FormatoController) y por la API móvil de RH
 * (Api\V1\Rh\FormatoController) para no repetir la consulta ni el cálculo
 * de variables/último uso entre ambos.
 */
class FormatoCatalogoService
{
    public function __construct(
        private readonly PlantillaDocumentoService $generador,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listar(): array
    {
        return DocumentTemplate::query()
            ->where('activo', true)
            ->withCount('documentosGenerados')
            ->with(['documentosGenerados' => fn ($query) => $query->latest()->limit(1)])
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'tipo', 'descripcion'])
            ->map(fn (DocumentTemplate $plantilla) => [
                'id' => $plantilla->id,
                'nombre' => $plantilla->nombre,
                'tipo' => $plantilla->tipo->value,
                'tipo_etiqueta' => $plantilla->tipo->etiqueta(),
                'descripcion' => $plantilla->descripcion,
                'variables' => $this->generador->variablesEnPlantilla($plantilla),
                'veces_generado' => $plantilla->documentos_generados_count,
                'ultimo_uso' => $plantilla->documentosGenerados->first()?->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Elige automáticamente la DocumentTemplate activa de tipo $tipo que le
     * corresponde a $colaborador, en orden de especificidad decreciente:
     * 1) puesto exacto, 2) departamento, 3) sucursal, 4) empresa, 5) global
     * (ninguno de los cuatro asignado). Se detiene en el primer criterio que
     * tenga una plantilla activa; nunca combina criterios entre sí. Usada
     * para resolver automáticamente qué plantilla usar (p. ej. un contrato)
     * sin que RH tenga que elegirla manualmente — ver Rh\FormatoController.
     *
     * @return array{plantilla: DocumentTemplate|null, criterio: string|null, criterio_etiqueta: string|null}
     */
    public function elegirPlantilla(TipoPlantillaDocumento $tipo, Colaborador $colaborador): array
    {
        $empresaId = $colaborador->sucursalPrincipal?->empresa_id;

        $criterios = [
            ['columna' => 'puesto_id', 'valor' => $colaborador->puesto_id, 'criterio' => 'puesto', 'etiqueta' => 'Se usó la plantilla del puesto.'],
            ['columna' => 'departamento_id', 'valor' => $colaborador->departamento_id, 'criterio' => 'departamento', 'etiqueta' => 'Se usó la plantilla del departamento.'],
            ['columna' => 'sucursal_id', 'valor' => $colaborador->sucursal_principal_id, 'criterio' => 'sucursal', 'etiqueta' => 'Se usó la plantilla de la sucursal.'],
            ['columna' => 'empresa_id', 'valor' => $empresaId, 'criterio' => 'empresa', 'etiqueta' => 'Se usó la plantilla de la empresa.'],
        ];

        foreach ($criterios as $criterio) {
            $valor = $criterio['valor'];

            if ($valor === null) {
                continue;
            }

            $plantilla = DocumentTemplate::query()
                ->where('activo', true)
                ->where('tipo', $tipo->value)
                ->where($criterio['columna'], $valor)
                ->first();

            if ($plantilla !== null) {
                return ['plantilla' => $plantilla, 'criterio' => $criterio['criterio'], 'criterio_etiqueta' => $criterio['etiqueta']];
            }
        }

        $global = DocumentTemplate::query()
            ->where('activo', true)
            ->where('tipo', $tipo->value)
            ->whereNull('puesto_id')
            ->whereNull('departamento_id')
            ->whereNull('sucursal_id')
            ->whereNull('empresa_id')
            ->first();

        if ($global !== null) {
            return [
                'plantilla' => $global,
                'criterio' => 'global',
                'criterio_etiqueta' => 'Se usó la plantilla global (sin puesto, departamento, sucursal ni empresa asignados).',
            ];
        }

        return ['plantilla' => null, 'criterio' => null, 'criterio_etiqueta' => null];
    }
}
