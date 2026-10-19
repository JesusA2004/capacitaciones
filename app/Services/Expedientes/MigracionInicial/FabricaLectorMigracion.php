<?php

namespace App\Services\Expedientes\MigracionInicial;

use App\Services\Expedientes\MigracionInicial\Contracts\LectorMigracion;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Detecta el formato del Excel de migración inicial por el NOMBRE de sus
 * hojas (sin cargar los datos) y devuelve la estructura canónica ya lista
 * para el PlanificadorMigracion:
 *   - hoja BASE_GENERAL          → LectorExcelMigracion (formato único);
 *   - hojas Sucursales/Corporativo → LectorBaseSemana41 (formato nuevo),
 *     combinado con el roster nominal si se adjuntó un segundo archivo.
 *
 * Así la pantalla y el comando aceptan el .xlsx de 2 hojas directo, sin
 * conversión manual. El puesto/departamento del formato nuevo solo existen
 * si se adjuntó el roster; sin él, las filas quedarán «en revisión».
 */
class FabricaLectorMigracion
{
    public function __construct(
        private readonly LectorExcelMigracion $baseGeneral,
        private readonly LectorBaseSemana41 $semana41,
        private readonly LectorRosterNominal $rosterNominal,
        private readonly CombinadorRosterNominal $combinador,
    ) {}

    /**
     * @return array{filas: list<array<string, mixed>>, columnas: array<string, string>, sin_reconocer: list<string>, hoja: string, formato: string}
     */
    public function leer(string $ruta, ?string $rutaRoster = null): array
    {
        $lector = $this->lectorPara($ruta);
        $estructura = $lector->leer($ruta);

        if ($estructura['formato'] === 'semana41' && $rutaRoster !== null && $rutaRoster !== '') {
            $estructura = $this->combinador->combinar($estructura, $this->rosterNominal->leer($rutaRoster));
        }

        return $estructura;
    }

    private function lectorPara(string $ruta): LectorMigracion
    {
        $hojas = array_map(fn (string $h) => Normalizador::clave($h), $this->nombresDeHojas($ruta));

        $hojaBase = Normalizador::clave((string) config('expedientes.migracion_inicial.hoja', 'BASE_GENERAL'));

        if (in_array($hojaBase, $hojas, true)) {
            return $this->baseGeneral;
        }

        $hojasSemana41 = array_map(fn ($h) => Normalizador::clave((string) $h), (array) config('expedientes.migracion_inicial.hojas_semana41', ['sucursales' => 'Sucursales', 'corporativo' => 'Corporativo']));

        if (array_intersect($hojasSemana41, $hojas) !== []) {
            return $this->semana41;
        }

        throw ValidationException::withMessages(['archivo' => 'No se reconoció el formato del Excel: no tiene la hoja «BASE_GENERAL» ni las hojas del formato por sucursales (Sucursales / Corporativo).']);
    }

    /**
     * Nombres de las hojas para detectar el formato. Se lee el libro solo
     * para los nombres (setReadDataOnly); el lector elegido vuelve a cargarlo
     * con sus propias opciones. Los archivos de migración son pequeños.
     *
     * @return list<string>
     */
    private function nombresDeHojas(string $ruta): array
    {
        try {
            $lector = IOFactory::createReaderForFile($ruta);
            $lector->setReadDataOnly(true);

            return array_values($lector->load($ruta)->getSheetNames());
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo leer el Excel: '.$e->getMessage()]);
        }
    }
}
