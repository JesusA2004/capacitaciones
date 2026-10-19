<?php

namespace App\Services\Expedientes\MigracionInicial;

use App\Services\Expedientes\MigracionInicial\Contracts\LectorMigracion;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lee la hoja BASE_GENERAL del Excel de migración inicial del formato ÚNICO
 * (MR_LANA_PEOPLE_BASE_GENERAL_MIGRACION_FINAL.xlsx,
 * docs/MIGRACION_INICIAL_EXPEDIENTES.md). Los encabezados se reconocen por
 * NOMBRE (sin acentos/mayúsculas/signos) con sinónimos, nunca por posición
 * (NormalizadorFilaMigracion::mapaEncabezados). Solo se lee esa hoja:
 * CONTACTOS_SIN_MATCH, CHECKLIST_DOCUMENTOS, CATALOGO_SUCURSALES y
 * CONFIG_MIGRACION_NAS no se importan. Celdas vacías → null.
 *
 * «Nombre», «Apellido paterno» y «Apellido materno» ya vienen separados: el
 * «Nombre completo» solo se usa para validar/emparejar, nunca para volver a
 * partir nombres y apellidos. Cada fila conserva su número real de Excel y
 * los valores ORIGINALES (para el reporte) junto con los normalizados.
 *
 * El reconocimiento de encabezados y la normalización de cada fila viven en
 * NormalizadorFilaMigracion: los comparte con LectorBaseSemana41 (formato
 * nuevo por sucursales), para no duplicar reglas de negocio.
 */
class LectorExcelMigracion implements LectorMigracion
{
    /**
     * @return array{filas: list<array<string, mixed>>, columnas: array<string, string>, sin_reconocer: list<string>, hoja: string, formato: string}
     */
    public function leer(string $ruta): array
    {
        $hojaNombre = (string) config('expedientes.migracion_inicial.hoja', 'BASE_GENERAL');

        try {
            $lector = IOFactory::createReaderForFile($ruta);
            $lector->setReadDataOnly(true);
            $lector->setLoadSheetsOnly([$hojaNombre]);
            $libro = $lector->load($ruta);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo leer el Excel: '.$e->getMessage()]);
        }

        $hoja = $libro->getSheetByName($hojaNombre);

        if ($hoja === null) {
            throw ValidationException::withMessages(['archivo' => "El Excel no tiene la hoja «{$hojaNombre}»."]);
        }

        $datos = $hoja->toArray(null, true, false, false);
        [$filaEncabezado, $mapa, $sinReconocer] = NormalizadorFilaMigracion::mapaEncabezados($datos);

        if (! isset($mapa['nombre']) || ! isset($mapa['apellido_paterno'])) {
            throw ValidationException::withMessages(['archivo' => 'No se encontraron los encabezados «Nombre» y «Apellido paterno» en la hoja '.$hojaNombre.'.']);
        }

        $filas = [];

        foreach ($datos as $indice => $renglon) {
            if ($indice <= $filaEncabezado || ! is_array($renglon)) {
                continue;
            }

            $original = [];

            foreach ($mapa as $campo => $columna) {
                $valor = $renglon[$columna] ?? null;
                $original[$campo] = is_string($valor) ? trim($valor) : $valor;
            }

            if (array_filter($original, fn ($v) => $v !== null && $v !== '') === []) {
                continue;
            }

            $filas[] = NormalizadorFilaMigracion::normalizarFila($original, $indice + 1);
        }

        return [
            'filas' => $filas,
            'columnas' => array_map(fn (int $c) => (string) ($datos[$filaEncabezado][$c] ?? ''), $mapa),
            'sin_reconocer' => $sinReconocer,
            'hoja' => $hojaNombre,
            'formato' => 'base_general',
        ];
    }
}
