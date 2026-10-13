<?php

namespace App\Services\DocumentosMaestros\Docx;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Marcas EDITORIALES del original de Jurídico: el marcatextos (resaltado
 * verde, amarillo…) con el que alguien señaló en Word qué partes cambian,
 * y el sombreado de TEXTO usado con el mismo fin. No forman parte del
 * documento legal y nunca deben salir en el PDF final.
 *
 * El ORIGINAL nunca se toca: limpiar() devuelve una COPIA NORMALIZADA de la
 * que se prepara el master. Solo cambian las partes que tenían marcas
 * (document.xml, encabezados, pies, notas); el resto del paquete (estilos,
 * numeración, imágenes, relaciones) se copia byte por byte.
 *
 * Qué se considera marca editorial (y qué NO):
 *  - `<w:highlight>` en las propiedades de un run (o de la marca de
 *    párrafo): SIEMPRE — Word solo lo usa como marcatextos.
 *  - `<w:shd>` en las propiedades de un RUN con relleno de color (no blanco
 *    ni automático): sombreado de texto como marcador.
 *  - NUNCA: sombreado de celdas (`w:tcPr`), de párrafos (`w:pPr` directo),
 *    tablas, imágenes, cuadros de diseño ni colores de fuente — son diseño.
 *
 * @phpstan-type Marca array{tipo: 'resaltado'|'sombreado', color: string, texto: string, parte: string}
 */
class LimpiadorMarcasEditoriales
{
    private const PARTES = '#^word/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$#';

    /** Rellenos que no se ven (no son marca). */
    private const SIN_COLOR = ['', 'auto', 'ffffff', 'none'];

    /**
     * Marcas del documento, sin modificarlo.
     *
     * @return list<Marca>
     */
    public function analizar(string $docx): array
    {
        return $this->procesar($docx, false)['marcas'];
    }

    /**
     * Copia normalizada sin marcas editoriales.
     *
     * @return array{docx: string, marcas: list<Marca>}
     */
    public function limpiar(string $docx): array
    {
        return $this->procesar($docx, true);
    }

    /**
     * @return array{docx: string, marcas: list<Marca>}
     */
    private function procesar(string $docx, bool $aplicar): array
    {
        $entradas = $this->entradas($docx);
        $marcas = [];
        $reemplazos = [];

        foreach ($entradas as $nombre => $xml) {
            if (preg_match(self::PARTES, $nombre) !== 1 || (! str_contains($xml, '<w:highlight') && ! str_contains($xml, '<w:shd'))) {
                continue;
            }

            $dom = new DOMDocument;
            $dom->preserveWhiteSpace = true;

            if (! $dom->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE)) {
                continue;
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', DocumentoWord::NS);
            $nodos = $this->marcasDe($xpath);

            if ($nodos === []) {
                continue;
            }

            foreach ($this->agrupar($nodos, $xpath) as $grupo) {
                $marcas[] = [...$grupo, 'parte' => $nombre];
            }

            if ($aplicar) {
                foreach ($nodos as $nodo) {
                    $nodo['nodo']->parentNode?->removeChild($nodo['nodo']);
                }

                $reemplazos[$nombre] = (string) $dom->saveXML();
            }
        }

        return ['docx' => $reemplazos === [] ? $docx : $this->empaquetar($entradas, $reemplazos), 'marcas' => $marcas];
    }

    /**
     * Nodos de marca editorial, en orden de documento.
     *
     * @return list<array{nodo: DOMElement, tipo: 'resaltado'|'sombreado', color: string}>
     */
    private function marcasDe(DOMXPath $xpath): array
    {
        $nodos = [];
        $lista = $xpath->query('//w:rPr/w:highlight | //w:rPr/w:shd');

        if ($lista === false) {
            return [];
        }

        foreach ($lista as $nodo) {
            if (! $nodo instanceof DOMElement) {
                continue;
            }

            if ($nodo->localName === 'highlight') {
                $color = strtolower($nodo->getAttributeNS(DocumentoWord::NS, 'val'));

                if ($color !== 'none' && $color !== '') {
                    $nodos[] = ['nodo' => $nodo, 'tipo' => 'resaltado', 'color' => $color];
                }

                continue;
            }

            $relleno = strtolower($nodo->getAttributeNS(DocumentoWord::NS, 'fill'));

            if (! in_array($relleno, self::SIN_COLOR, true)) {
                $nodos[] = ['nodo' => $nodo, 'tipo' => 'sombreado', 'color' => '#'.$relleno];
            }
        }

        return $nodos;
    }

    /**
     * Runs marcados consecutivos del mismo párrafo = UNA marca (lo que una
     * persona ve como un solo resaltado), con su texto para mostrarlo.
     *
     * @param  list<array{nodo: DOMElement, tipo: 'resaltado'|'sombreado', color: string}>  $nodos
     * @return list<array{tipo: 'resaltado'|'sombreado', color: string, texto: string}>
     */
    private function agrupar(array $nodos, DOMXPath $xpath): array
    {
        $grupos = [];
        $actual = null;
        $anterior = null;

        foreach ($nodos as $marca) {
            $run = $marca['nodo']->parentNode?->parentNode;

            if (! $run instanceof DOMElement) {
                continue;
            }

            $texto = $run->localName === 'r' ? $this->textoRun($run, $xpath) : '';
            $parrafo = $run->parentNode;
            $contiguo = $actual !== null && $anterior !== null
                && $anterior['parrafo'] === $parrafo
                && $actual['tipo'] === $marca['tipo']
                && $actual['color'] === $marca['color']
                && $this->siguienteRun($anterior['run']) === $run;

            if ($contiguo) {
                $actual['texto'] .= $texto;
            } else {
                if ($actual !== null) {
                    $grupos[] = $this->cerrarGrupo($actual);
                }

                $actual = ['tipo' => $marca['tipo'], 'color' => $marca['color'], 'texto' => $texto];
            }

            $anterior = ['parrafo' => $parrafo, 'run' => $run];
        }

        if ($actual !== null) {
            $grupos[] = $this->cerrarGrupo($actual);
        }

        return $grupos;
    }

    /**
     * @param  array{tipo: 'resaltado'|'sombreado', color: string, texto: string}  $grupo
     * @return array{tipo: 'resaltado'|'sombreado', color: string, texto: string}
     */
    private function cerrarGrupo(array $grupo): array
    {
        return ['tipo' => $grupo['tipo'], 'color' => $grupo['color'], 'texto' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $grupo['texto'])), 0, 80)];
    }

    private function textoRun(DOMElement $run, DOMXPath $xpath): string
    {
        $texto = '';
        $nodos = $xpath->query('./w:t | ./w:tab', $run);

        if ($nodos !== false) {
            foreach ($nodos as $nodo) {
                if ($nodo instanceof DOMElement) {
                    $texto .= $nodo->localName === 'tab' ? ' ' : $nodo->textContent;
                }
            }
        }

        return $texto;
    }

    private function siguienteRun(DOMElement $run): ?DOMElement
    {
        $nodo = $run->nextSibling;

        while ($nodo !== null) {
            if ($nodo instanceof DOMElement) {
                // Marcadores de revisión/ortografía entre runs no cortan la marca.
                if (in_array($nodo->localName, ['proofErr', 'bookmarkStart', 'bookmarkEnd'], true)) {
                    $nodo = $nodo->nextSibling;

                    continue;
                }

                return $nodo->localName === 'r' ? $nodo : null;
            }

            $nodo = $nodo->nextSibling;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function entradas(string $docx): array
    {
        $temporal = tempnam(sys_get_temp_dir(), 'mrk');

        if ($temporal === false) {
            throw new RuntimeException('No se pudo crear un archivo temporal para leer el DOCX.');
        }

        file_put_contents($temporal, $docx);

        try {
            $zip = new ZipArchive;

            if ($zip->open($temporal) !== true) {
                throw new RuntimeException('El archivo no es un DOCX válido (no se pudo abrir el paquete).');
            }

            $entradas = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entradas[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
            }

            $zip->close();

            return $entradas;
        } finally {
            @unlink($temporal);
        }
    }

    /**
     * @param  array<string, string>  $entradas
     * @param  array<string, string>  $reemplazos
     */
    private function empaquetar(array $entradas, array $reemplazos): string
    {
        $temporal = tempnam(sys_get_temp_dir(), 'mrk');

        if ($temporal === false) {
            throw new RuntimeException('No se pudo crear un archivo temporal para escribir el DOCX.');
        }

        @unlink($temporal);
        $zip = new ZipArchive;

        if ($zip->open($temporal, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo escribir el DOCX.');
        }

        try {
            $nombres = array_keys($entradas);
            usort($nombres, fn (string $a, string $b): int => [$a !== '[Content_Types].xml'] <=> [$b !== '[Content_Types].xml']);

            foreach ($nombres as $nombre) {
                $zip->addFromString($nombre, $reemplazos[$nombre] ?? $entradas[$nombre]);
            }

            $zip->close();

            return (string) file_get_contents($temporal);
        } finally {
            @unlink($temporal);
        }
    }
}
