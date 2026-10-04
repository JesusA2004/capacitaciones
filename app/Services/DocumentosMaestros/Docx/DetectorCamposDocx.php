<?php

namespace App\Services\DocumentosMaestros\Docx;

/**
 * Revisa un MASTER ya preparado antes de activarlo: ¿quedó algún blanco,
 * dato de ejemplo o fecha concreta sin mapear? El resultado es el reporte
 * "22 detectados · 20 mapeados · 2 pendientes" que ve RH en Documentos
 * maestros. Un master con pendientes no se activa.
 *
 * No todo blanco es un dato: las líneas de firma ("Firma: ______", una
 * línea sola bajo "EL TRABAJADOR") se quedan en blanco a propósito para la
 * firma física — se reportan aparte como firmas, nunca se rellenan.
 */
class DetectorCamposDocx
{
    private const MESES = 'enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre';

    /**
     * @param  array<int, array{campo: string, original: string, opcional: bool}>  $instancias
     * @param  array<string, mixed>  $definicion  Entrada de config('documentos_maestros.documentos').
     * @return array{detectados: int, mapeados: int, firmas: int, pendientes: list<array{referencia: string, tipo: string, contexto: string}>, campos: list<string>, medios: list<string>}
     */
    public function analizar(DocumentoWord $master, array $instancias, array $definicion): array
    {
        $ignorar = array_values(array_map('strval', (array) ($definicion['ignorar'] ?? [])));
        $ejemplos = array_values(array_map('strval', (array) ($definicion['ejemplos'] ?? [])));
        $fechasFijas = array_map('strval', (array) ($definicion['fechas_fijas'] ?? []));
        $pendientes = [];
        $firmas = 0;

        foreach ($master->parrafos() as $parrafo) {
            $texto = $parrafo->texto();
            $sinMarcadores = (string) preg_replace('/\{\{[a-z0-9_]+@\d+\}\}/', '', $texto);

            if (preg_match_all('/_{4,}/u', $sinMarcadores, $coincidencias, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($coincidencias[0] as [$blanco, $offset]) {
                    $izquierda = substr($sinMarcadores, max(0, $offset - 60), min(60, $offset));

                    if ($this->esFirma($sinMarcadores, $izquierda) || $this->ignorado($texto, $ignorar)) {
                        $firmas++;

                        continue;
                    }

                    $pendientes[] = [
                        'referencia' => $parrafo->referencia(),
                        'tipo' => 'blanco_sin_mapear',
                        'contexto' => trim(mb_convert_encoding($izquierda, 'UTF-8', 'UTF-8')).' [____]',
                    ];
                }
            }

            foreach ($ejemplos as $ejemplo) {
                if ($ejemplo !== '' && mb_stripos($texto, $ejemplo) !== false) {
                    $pendientes[] = ['referencia' => $parrafo->referencia(), 'tipo' => 'dato_de_ejemplo', 'contexto' => $ejemplo];
                }
            }

            if (preg_match_all('/\b\d{1,2}\s+de\s+('.self::MESES.')\s+(de|del)\s+20\d{2}\b/iu', $sinMarcadores, $fechas) > 0) {
                foreach ($fechas[0] as $fecha) {
                    if (! in_array($fecha, $fechasFijas, true)) {
                        $pendientes[] = ['referencia' => $parrafo->referencia(), 'tipo' => 'fecha_concreta', 'contexto' => $fecha];
                    }
                }
            }
        }

        $campos = array_values(array_unique(array_map(fn (array $i): string => $i['campo'], $instancias)));

        return [
            'detectados' => count($instancias) + $firmas + count($pendientes),
            'mapeados' => count($instancias),
            'firmas' => $firmas,
            'pendientes' => $pendientes,
            'campos' => $campos,
            'medios' => $master->medios(),
        ];
    }

    private function esFirma(string $parrafo, string $izquierda): bool
    {
        // Línea sola (solo guiones bajos, opcionalmente "C." o espacios).
        if (preg_match('/^\s*(C\.\s*)?_+\s*$/u', $parrafo) === 1 && ! str_starts_with(trim($parrafo), 'C.')) {
            return true;
        }

        return preg_match('/(firma|huella)\s*:?\s*$/iu', $izquierda) === 1;
    }

    /**
     * @param  list<string>  $ignorar
     */
    private function ignorado(string $texto, array $ignorar): bool
    {
        foreach ($ignorar as $patron) {
            if ($patron !== '' && str_contains($texto, $patron)) {
                return true;
            }
        }

        return false;
    }
}
