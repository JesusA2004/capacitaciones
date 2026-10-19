<?php

namespace App\Services\Expedientes\MigracionInicial;

use App\Services\Expedientes\MigracionInicial\Contracts\LectorMigracion;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lee el formato NUEVO de base por sucursales («BASE DE DATOS MR. LANA
 * PEOPLE SEMANA 41»): una hoja «Sucursales» (operativas) y una hoja
 * «Corporativo». Ambas traen SOLO la identidad del colaborador
 * (nombre/apellidos, CURP/RFC/NSS, correo/teléfonos, fechas, sexo, estatus,
 * contacto de emergencia, datos médicos, clave legacy, sucursal). El puesto
 * y el departamento NO salen de aquí: vienen del roster nominal
 * (CombinadorRosterNominal) y, sin match, quedan null (nunca se inventan).
 *
 * Reconoce encabezados por NOMBRE reutilizando el mapa de sinónimos de
 * NormalizadorFilaMigracion (mismo reconocimiento que BASE_GENERAL) y emite
 * exactamente la misma estructura canónica, concatenando ambas hojas en
 * `filas`. Cada fila queda etiquetada con su hoja de origen.
 *
 * Reglas de la hoja Corporativo: sucursal = «Corporativo» fija (no pasa por
 * los alias de sucursal operativa); empresa = la monoempresa de config. En
 * la hoja Sucursales la sucursal se toma del valor crudo de la fila y la
 * resuelve después InventarioNasHistorico::resolverSucursal (whitelist +
 * alias). Empresa monoempresa por config en ambas (decisión: «Mr. Lana»).
 */
class LectorBaseSemana41 implements LectorMigracion
{
    /** Campos que SIEMPRE vienen del roster nominal, nunca de la base. */
    private const SOLO_ROSTER = ['puesto', 'departamento'];

    /**
     * @return array{filas: list<array<string, mixed>>, columnas: array<string, string>, sin_reconocer: list<string>, hoja: string, formato: string}
     */
    public function leer(string $ruta): array
    {
        $hojas = $this->hojasConfiguradas();
        $empresa = (string) config('expedientes.migracion_inicial.empresa', 'Mr. Lana');

        try {
            $lector = IOFactory::createReaderForFile($ruta);
            $lector->setReadDataOnly(true);
            $libro = $lector->load($ruta);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo leer el Excel: '.$e->getMessage()]);
        }

        $filas = [];
        $columnas = [];
        $sinReconocer = [];
        $hojasLeidas = [];

        foreach ($hojas as $clave => $nombreHoja) {
            $hoja = $libro->getSheetByName($nombreHoja);

            if ($hoja === null) {
                continue;
            }

            $esCorporativo = $clave === 'corporativo';
            [$filasHoja, $columnasHoja, $sinReconocerHoja] = $this->leerHoja($hoja, $nombreHoja, $esCorporativo, $empresa);

            $filas = [...$filas, ...$filasHoja];
            $columnas = [...$columnas, ...$columnasHoja];
            $sinReconocer = [...$sinReconocer, ...$sinReconocerHoja];
            $hojasLeidas[] = $nombreHoja;
        }

        if ($hojasLeidas === []) {
            throw ValidationException::withMessages(['archivo' => 'El Excel no tiene ninguna de las hojas esperadas del formato por sucursales ('.implode(', ', array_values($hojas)).').']);
        }

        return [
            'filas' => $filas,
            'columnas' => $columnas,
            'sin_reconocer' => array_values(array_unique($sinReconocer)),
            'hoja' => implode(' + ', $hojasLeidas),
            'formato' => 'semana41',
        ];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>, 2: list<string>}
     */
    private function leerHoja(Worksheet $hoja, string $nombreHoja, bool $esCorporativo, string $empresa): array
    {
        $datos = $hoja->toArray(null, true, false, false);
        [$filaEncabezado, $mapa, $sinReconocer] = NormalizadorFilaMigracion::mapaEncabezados($datos);

        if (! isset($mapa['nombre']) || ! isset($mapa['apellido_paterno'])) {
            throw ValidationException::withMessages(['archivo' => sprintf('No se encontraron los encabezados «Nombre» y «Apellido paterno» en la hoja «%s».', $nombreHoja)]);
        }

        // En la hoja Sucursales del Excel real la sucursal viene en la
        // columna «Clasificación» (ORIZABA, CORDOBA, Atlacomulc…): solo aquí
        // se lee como sucursal; la resuelve después la whitelist + alias.
        if (! $esCorporativo && ! isset($mapa['sucursal'])) {
            foreach ((array) ($datos[$filaEncabezado] ?? []) as $columna => $celda) {
                if (is_scalar($celda) && Normalizador::clave((string) $celda) === 'CLASIFICACION') {
                    $mapa['sucursal'] = (int) $columna;
                    $sinReconocer = array_values(array_filter($sinReconocer, fn (string $e) => Normalizador::clave($e) !== 'CLASIFICACION'));

                    break;
                }
            }
        }

        $filas = [];

        foreach ($datos as $indice => $renglon) {
            if ($indice <= $filaEncabezado || ! is_array($renglon)) {
                continue;
            }

            $original = [];

            foreach ($mapa as $campo => $columna) {
                // Puesto/departamento SIEMPRE del roster: la base no los aporta.
                if (in_array($campo, self::SOLO_ROSTER, true)) {
                    continue;
                }

                $valor = $renglon[$columna] ?? null;
                $original[$campo] = is_string($valor) ? trim($valor) : $valor;
            }

            if (array_filter($original, fn ($v) => $v !== null && $v !== '') === []) {
                continue;
            }

            // Corporativo: sucursal fija (no pasa por alias operativa).
            if ($esCorporativo) {
                $original['sucursal'] = 'Corporativo';
            }

            // Monoempresa: si la fila no trae empresa, se asume la de config.
            if (($original['empresa'] ?? null) === null || trim((string) $original['empresa']) === '') {
                $original['empresa'] = $empresa;
            }

            $original['puesto'] = null;
            $original['departamento'] = null;
            $original['hoja_origen'] = $nombreHoja;

            $filas[] = NormalizadorFilaMigracion::normalizarFila($original, $indice + 1);
        }

        $columnas = array_map(fn (int $c) => (string) ($datos[$filaEncabezado][$c] ?? ''), array_diff_key($mapa, array_flip(self::SOLO_ROSTER)));

        return [$filas, $columnas, $sinReconocer];
    }

    /**
     * @return array<string, string> clave lógica => nombre real de la hoja
     */
    private function hojasConfiguradas(): array
    {
        $hojas = (array) config('expedientes.migracion_inicial.hojas_semana41', ['sucursales' => 'Sucursales', 'corporativo' => 'Corporativo']);

        return array_map(fn ($n) => (string) $n, $hojas);
    }
}
