<?php

namespace App\Services\DocumentosMaestros\Docx;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * DOCX abierto en memoria para leer/modificar su texto SIN reconstruirlo:
 * se editan únicamente los nodos de texto de document.xml, headers y
 * footers; imágenes, estilos, numeración, secciones, relaciones y demás
 * entradas del paquete se copian byte por byte al guardar.
 *
 * Word fragmenta un mismo texto en varios runs (<w:r>) por ortografía,
 * revisiones o formato; ParrafoWord trabaja sobre el texto concatenado del
 * párrafo con un mapa de offsets a cada nodo, así que "JUAN PÉREZ" se
 * encuentra y se sustituye aunque esté partido en tres <w:t>.
 */
class DocumentoWord
{
    public const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** @var array<string, string> entrada del zip → bytes originales */
    private array $entradas = [];

    /** @var array<string, DOMDocument> parte XML de texto → DOM editable */
    private array $partes = [];

    /** @var array<string, DOMXPath> */
    private array $xpaths = [];

    public static function desdeBytes(string $bytes): self
    {
        $temporal = tempnam(sys_get_temp_dir(), 'docx');

        if ($temporal === false) {
            throw new RuntimeException('No se pudo crear un archivo temporal para leer el DOCX.');
        }

        file_put_contents($temporal, $bytes);

        try {
            $zip = new ZipArchive;

            if ($zip->open($temporal) !== true) {
                throw new RuntimeException('El archivo no es un DOCX válido (no se pudo abrir el paquete).');
            }

            $documento = new self;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nombre = (string) $zip->getNameIndex($i);
                $documento->entradas[$nombre] = (string) $zip->getFromIndex($i);
            }

            $zip->close();
        } finally {
            @unlink($temporal);
        }

        if (! isset($documento->entradas['word/document.xml'])) {
            throw new RuntimeException('El DOCX no contiene word/document.xml.');
        }

        foreach (array_keys($documento->entradas) as $nombre) {
            if (preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $nombre) === 1) {
                $dom = new DOMDocument;
                $dom->preserveWhiteSpace = true;

                if (! $dom->loadXML($documento->entradas[$nombre], LIBXML_NONET | LIBXML_PARSEHUGE)) {
                    throw new RuntimeException("No se pudo leer la parte {$nombre} del DOCX.");
                }

                $xpath = new DOMXPath($dom);
                $xpath->registerNamespace('w', self::NS);
                $documento->partes[$nombre] = $dom;
                $documento->xpaths[$nombre] = $xpath;
            }
        }

        // document.xml primero, luego encabezados y pies (orden estable).
        uksort($documento->partes, fn (string $a, string $b): int => [$a !== 'word/document.xml', $a] <=> [$b !== 'word/document.xml', $b]);

        return $documento;
    }

    /**
     * Párrafos de todas las partes de texto, en orden de lectura. Los
     * párrafos dentro de cuadros de texto también aparecen (cada uno con su
     * propio texto; el párrafo contenedor no incluye el texto del cuadro).
     *
     * @return list<ParrafoWord>
     */
    public function parrafos(): array
    {
        $parrafos = [];

        foreach ($this->partes as $nombre => $dom) {
            $indice = 0;

            foreach ($this->xpaths[$nombre]->query('//w:p') ?: [] as $nodo) {
                if ($nodo instanceof DOMElement) {
                    $parrafos[] = new ParrafoWord($nodo, $this->xpaths[$nombre], $nombre, ++$indice);
                }
            }
        }

        return $parrafos;
    }

    /**
     * Texto plano completo (para búsquedas, huellas y reportes).
     */
    public function texto(): string
    {
        return implode("\n", array_map(fn (ParrafoWord $p): string => $p->texto(), $this->parrafos()));
    }

    /**
     * Imágenes embebidas (logo, fondos) — solo para el reporte de que se
     * conservaron.
     *
     * @return list<string>
     */
    public function medios(): array
    {
        return array_values(array_filter(array_keys($this->entradas), fn (string $n): bool => str_starts_with($n, 'word/media/')));
    }

    /**
     * XML crudo de cada parte de texto ya modificada (lo usa el rellenador,
     * que trabaja con cadenas para no volver a parsear en cada generación).
     *
     * @return array<string, string>
     */
    public function xmlPartes(): array
    {
        $xml = [];

        foreach ($this->partes as $nombre => $dom) {
            $xml[$nombre] = (string) $dom->saveXML();
        }

        return $xml;
    }

    /**
     * Empaqueta el DOCX. $reemplazos permite sustituir el XML de partes
     * concretas (rellenador); el resto de entradas se copian tal cual.
     *
     * @param  array<string, string>  $reemplazos
     */
    public function guardar(array $reemplazos = []): string
    {
        $temporal = tempnam(sys_get_temp_dir(), 'docx');

        if ($temporal === false) {
            throw new RuntimeException('No se pudo crear un archivo temporal para escribir el DOCX.');
        }

        @unlink($temporal);
        $zip = new ZipArchive;

        if ($zip->open($temporal, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo escribir el DOCX.');
        }

        try {
            // [Content_Types].xml primero, como lo escribe Word.
            $nombres = array_keys($this->entradas);
            usort($nombres, fn (string $a, string $b): int => [$a !== '[Content_Types].xml'] <=> [$b !== '[Content_Types].xml']);

            foreach ($nombres as $nombre) {
                $contenido = $reemplazos[$nombre] ?? (isset($this->partes[$nombre]) ? (string) $this->partes[$nombre]->saveXML() : $this->entradas[$nombre]);
                $zip->addFromString($nombre, $contenido);
            }

            $zip->close();

            return (string) file_get_contents($temporal);
        } finally {
            @unlink($temporal);
        }
    }
}
