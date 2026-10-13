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
     * @return array{detectados: int, mapeados: int, firmas: int, intencionales: int, pendientes: list<array{referencia: string, tipo: string, contexto: string}>, campos: list<string>, medios: list<string>}
     */
    public function analizar(DocumentoWord $master, array $instancias, array $definicion): array
    {
        $ignorar = array_values(array_map('strval', (array) ($definicion['ignorar'] ?? [])));
        $ejemplos = array_values(array_map('strval', (array) ($definicion['ejemplos'] ?? [])));
        $fechasFijas = array_map('strval', (array) ($definicion['fechas_fijas'] ?? []));
        // Blancos que RH marcó en el editor como firma, huella, testigo o
        // «no lleva dato»: se quedan en blanco a propósito.
        $sinDato = [];

        foreach ((array) ($definicion['zonas_sin_dato'] ?? []) as $zona) {
            if (is_array($zona) && isset($zona['referencia'], $zona['contexto'])) {
                $sinDato[(string) $zona['referencia'].'|'.(string) $zona['contexto']] = true;
            }
        }

        $pendientes = [];
        $firmas = 0;
        $intencionales = 0;

        foreach ($master->parrafos() as $parrafo) {
            $texto = $parrafo->texto();
            $sinMarcadores = (string) preg_replace('/\{\{[a-z0-9_]+@\d+\}\}/', '', $texto);

            if (preg_match_all('/_{4,}/u', $sinMarcadores, $coincidencias, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($coincidencias[0] as [$blanco, $offset]) {
                    $izquierda = substr($sinMarcadores, max(0, $offset - 60), min(60, $offset));

                    $contexto = trim(mb_convert_encoding($izquierda, 'UTF-8', 'UTF-8')).' [____]';

                    if ($this->esFirma($sinMarcadores, $izquierda) || isset($sinDato[$parrafo->referencia().'|'.$contexto])) {
                        $firmas++;

                        continue;
                    }

                    // Blanco que el registro jurídico deja vacío A PROPÓSITO
                    // (`ignorar`, con su razón en las observaciones).
                    if ($this->ignorado($texto, $ignorar)) {
                        $intencionales++;

                        continue;
                    }

                    $pendientes[] = [
                        'referencia' => $parrafo->referencia(),
                        'tipo' => 'blanco_sin_mapear',
                        'contexto' => $contexto,
                    ];
                }
            }

            // Líneas hechas con espacios/tabuladores SUBRAYADOS (sin «_»):
            // también son espacios para un dato.
            foreach ($parrafo->blancosSubrayados() as [$inicio, $fin]) {
                $blanco = mb_substr($texto, $inicio, $fin - $inicio);

                if (str_contains($blanco, '____')) {
                    continue; // ya contado como blanco de guiones bajos
                }

                $izquierda = (string) preg_replace('/\{\{[a-z0-9_]+@\d+\}\}/', '', mb_substr($texto, max(0, $inicio - 60), min(60, $inicio)));
                $contexto = trim($izquierda).' [____]';

                if ($this->esFirma($sinMarcadores, $izquierda) || isset($sinDato[$parrafo->referencia().'|'.$contexto])) {
                    $firmas++;

                    continue;
                }

                if ($this->ignorado($texto, $ignorar)) {
                    $intencionales++;

                    continue;
                }

                $pendientes[] = ['referencia' => $parrafo->referencia(), 'tipo' => 'blanco_sin_mapear', 'contexto' => $contexto];
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
            'detectados' => count($instancias) + $firmas + $intencionales + count($pendientes),
            'mapeados' => count($instancias),
            'firmas' => $firmas,
            'intencionales' => $intencionales,
            'pendientes' => $pendientes,
            'campos' => $campos,
            'medios' => $master->medios(),
        ];
    }

    private function esFirma(string $parrafo, string $izquierda): bool
    {
        return self::esLineaDeFirma($parrafo, $izquierda);
    }

    /**
     * Mismo criterio para el detector de pendientes y el de espacios del
     * editor: una línea sola (solo guiones bajos) o un blanco precedido de
     * «Firma:»/«Huella:» es para la firma física, no un dato.
     */
    public static function esLineaDeFirma(string $parrafo, string $izquierda): bool
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
