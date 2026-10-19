<?php

namespace App\Services\Expedientes\MigracionInicial;

use App\Services\Expedientes\MigracionInicial\Contracts\LectorMigracion;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lee el ROSTER NOMINAL: el segundo archivo (opcional) que aporta el PUESTO
 * y el DEPARTAMENTO de cada colaborador, cruzados contra la base por
 * sucursales (SEMANA 41) a través de CombinadorRosterNominal. La base solo
 * trae identidad; el puesto/departamento SIEMPRE salen de aquí.
 *
 * Lee la PRIMERA hoja con datos y reconoce los encabezados por NOMBRE
 * (sinónimos de NormalizadorFilaMigracion), nunca por posición: necesita al
 * menos «Puesto» y una forma de identidad (clave o nombre completo).
 *
 * OJO (pendiente de confirmar con el archivo real): este lector asume un
 * roster COLUMNAR simple (una fila por persona, un encabezado por columna).
 * El export NOI/CONTPAQ que se evaluó (docs/onboarding/REPORTE TRABA.XLSX)
 * viene en PARES de filas con los encabezados en las filas 12-13: ese layout
 * NO lo entiende este lector y requiere confirmar la estructura real antes
 * de ampliarlo. No se asume forma: lo que no cuadra se reporta.
 *
 * No implementa LectorMigracion (no produce la estructura canónica de
 * colaboradores, solo el índice puesto/departamento por identidad).
 */
class LectorRosterNominal
{
    /**
     * @return array{entradas: list<array{clave: string|null, nombre_clave: string, puesto: string|null, departamento: string|null, correo_corporativo: string|null, telefono_corporativo: string|null}>, sin_reconocer: list<string>}
     */
    public function leer(string $ruta): array
    {
        try {
            $lector = IOFactory::createReaderForFile($ruta);
            $lector->setReadDataOnly(true);
            $libro = $lector->load($ruta);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['roster' => 'No se pudo leer el roster nominal: '.$e->getMessage()]);
        }

        // Primera hoja (no la activa: el libro puede guardarse parado en una
        // hoja de resumen). Hasta 20 filas: el directorio de gerencias trae
        // su encabezado en la fila 11, debajo del título y la fecha de corte.
        $datos = $libro->getSheet(0)->toArray(null, true, false, false);
        [$filaEncabezado, $mapa, $sinReconocer] = NormalizadorFilaMigracion::mapaEncabezados($datos, 20);

        $tieneIdentidad = isset($mapa['clave']) || isset($mapa['nombre_completo']) || (isset($mapa['nombre']) && isset($mapa['apellido_paterno']));

        if (! isset($mapa['puesto']) || ! $tieneIdentidad) {
            throw ValidationException::withMessages(['roster' => 'El roster nominal debe traer al menos «Puesto» y la identidad (una «Clave» o el «Nombre completo»).']);
        }

        $entradas = [];

        foreach ($datos as $indice => $renglon) {
            if ($indice <= $filaEncabezado || ! is_array($renglon)) {
                continue;
            }

            $valor = fn (string $campo): ?string => isset($mapa[$campo])
                ? Normalizador::texto(is_scalar($renglon[$mapa[$campo]] ?? null) ? (string) $renglon[$mapa[$campo]] : null)
                : null;

            $clave = $valor('clave');
            $nombreCompleto = $valor('nombre_completo')
                ?? trim(implode(' ', array_filter([$valor('nombre'), $valor('apellido_paterno'), $valor('apellido_materno')])));
            $puesto = $valor('puesto');
            $departamento = $valor('departamento');

            // Sin identidad, o renglón separador sin puesto («▎ SUCURSAL
            // ATLACOMULCO · 2 colaboradores»): no aporta nada al cruce.
            if (($clave === null && $nombreCompleto === '') || $puesto === null) {
                continue;
            }

            $entradas[] = [
                'clave' => $clave !== null ? (string) preg_replace('/\.0+$/', '', $clave) : null,
                'nombre_clave' => Normalizador::clave($nombreCompleto),
                'puesto' => $puesto,
                'departamento' => $departamento,
                // Contacto CORPORATIVO (directorio de gerencias) para el
                // módulo Directorio: misma persona, no un registro nuevo.
                'correo_corporativo' => Normalizador::correo($valor('correo_corporativo')),
                'telefono_corporativo' => Normalizador::telefono($valor('telefono_corporativo')),
            ];
        }

        return ['entradas' => $entradas, 'sin_reconocer' => $sinReconocer];
    }
}
