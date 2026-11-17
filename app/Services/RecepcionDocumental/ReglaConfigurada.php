<?php

namespace App\Services\RecepcionDocumental;

use App\Models\RecepcionDocumentalCatalogo;
use App\Models\Sucursal;
use App\Services\RecepcionDocumental\Reglas\ReglaRecepcion;

/**
 * Una regla de Recepción documental + su entrada del catálogo configurable.
 * Calcula lo esperado del mes aplicando lo que RH eligió: solo los
 * documentos marcados en la entrada y, si se acotó, solo las sucursales /
 * empresas elegidas. Las reglas devuelven todo lo que saben producir; el
 * filtro vive aquí, en un solo lugar.
 */
final class ReglaConfigurada
{
    public function __construct(
        public readonly ReglaRecepcion $regla,
        public readonly RecepcionDocumentalCatalogo $entrada,
    ) {}

    public function activa(): bool
    {
        return $this->entrada->activa && $this->entrada->claves() !== [];
    }

    /**
     * Lo esperado en el periodo con la configuración de la entrada.
     *
     * @return list<EsperadoRecepcion>
     */
    public function esperados(PeriodoRecepcion $periodo): array
    {
        return self::filtrar(
            $this->regla->esperados($periodo),
            $this->entrada->claves(),
            $this->entrada->sucursales(),
            $this->entrada->empresas(),
        );
    }

    /**
     * @param  list<EsperadoRecepcion>  $esperados
     * @param  list<string>  $documentos
     * @param  list<int>  $sucursales  [] = todas
     * @param  list<int>  $empresas  [] = todas
     * @return list<EsperadoRecepcion>
     */
    public static function filtrar(array $esperados, array $documentos, array $sucursales, array $empresas): array
    {
        if ($documentos === [] || $esperados === []) {
            return [];
        }

        $empresaDe = [];

        if ($empresas !== []) {
            $ids = array_values(array_unique(array_filter(array_map(fn (EsperadoRecepcion $e): ?int => $e->sucursalId, $esperados))));

            foreach (Sucursal::query()->whereIn('id', $ids)->get(['id', 'empresa_id']) as $sucursal) {
                $empresaDe[$sucursal->id] = $sucursal->empresa_id !== null ? (int) $sucursal->empresa_id : null;
            }
        }

        $filtrados = [];

        foreach ($esperados as $esperado) {
            if (! in_array($esperado->documento(), $documentos, true)) {
                continue;
            }

            if ($sucursales !== [] && ! in_array($esperado->sucursalId, $sucursales, true)) {
                continue;
            }

            if ($empresas !== [] && ! in_array($esperado->sucursalId !== null ? ($empresaDe[$esperado->sucursalId] ?? null) : null, $empresas, true)) {
                continue;
            }

            $filtrados[] = $esperado;
        }

        return $filtrados;
    }
}
