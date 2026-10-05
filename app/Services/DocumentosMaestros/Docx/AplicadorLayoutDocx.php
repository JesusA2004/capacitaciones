<?php

namespace App\Services\DocumentosMaestros\Docx;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Aplica un diseño (preset de layout, ver LayoutDocumentoService) a un DOCX
 * ya llenado, ANTES de convertirlo a PDF. Nunca toca el texto jurídico:
 *
 *  - Fondo de página: imagen anclada DETRÁS del texto (`behindDoc`,
 *    `wrapNone`, posición relativa a la PÁGINA, x=0 y=0, tamaño de la hoja)
 *    insertada en el primer párrafo existente de cada encabezado de cada
 *    sección. No es una imagen inline: no ocupa lugar en el flujo, no
 *    empuja texto ni cambia saltos de página, y se repite en todas las
 *    páginas porque vive en el encabezado. Si una sección no tiene
 *    encabezado se le crea uno vacío (un solo párrafo dentro del margen
 *    del encabezado: no altera el cuerpo).
 *  - Logo/fondo heredado del original: con `header_logo_enabled = false`
 *    se retiran los dibujos anclados de los encabezados (el fondo viejo de
 *    Jurídico) para que no queden dos fondos/logos encimados.
 *  - Márgenes de página (si el preset los define) y, en párrafos NORMALES
 *    del cuerpo, sangría izquierda/derecha, espacio antes/después y
 *    justificado. No se tocan: títulos centrados o a la derecha, listas
 *    numeradas, celdas de tablas, cuadros de texto, párrafos con imagen ni
 *    párrafos que ya traen su propia sangría (configuración propia).
 */
class AplicadorLayoutDocx
{
    private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_WP = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';

    private const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const NS_PIC = 'http://schemas.openxmlformats.org/drawingml/2006/picture';

    private const NS_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const NS_CT = 'http://schemas.openxmlformats.org/package/2006/content-types';

    private const REL_HEADER = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/header';

    private const REL_IMAGE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';

    private const CT_HEADER = 'application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml';

    /** 1 cm = 566.93 twips; 1 twip = 635 EMU. */
    private const TWIPS_POR_CM = 566.929;

    private const EMU_POR_TWIP = 635;

    /** @var array<string, string> */
    private array $entradas = [];

    /**
     * @param  array{
     *     page?: array{margins_cm?: array{top?: float, right?: float, bottom?: float, left?: float, header?: float}|null},
     *     paragraph?: array{left_indent_cm?: float|null, right_indent_cm?: float|null, space_before_pt?: float|null, space_after_pt?: float|null, justify?: bool, keep_lines?: bool},
     *     background?: array{apply_to?: string, fit?: string, opacity?: int, pages?: list<int>}|null,
     *     header_logo_enabled?: bool,
     * }  $layout
     * @param  array{bytes: string, extension: string, width: int, height: int, sha256: string}|null  $fondo
     */
    public function aplicar(string $docx, array $layout, ?array $fondo): string
    {
        $this->leer($docx);
        $documento = $this->dom('word/document.xml');
        $xpath = $this->xpath($documento);

        if (($layout['header_logo_enabled'] ?? true) === false) {
            $this->retirarDibujosDeEncabezados();
        }

        foreach ($xpath->query('//w:body//w:sectPr | //w:body/w:sectPr') ?: [] as $sectPr) {
            if ($sectPr instanceof DOMElement) {
                $this->aplicarMargenes($sectPr, $layout['page']['margins_cm'] ?? null);
            }
        }

        $this->aplicarParrafos($xpath, $layout['paragraph'] ?? []);

        $aplicarFondo = $fondo !== null && in_array($layout['background']['apply_to'] ?? 'all_pages', ['all_pages', 'first_page'], true);

        if ($aplicarFondo) {
            $this->insertarFondo($documento, $xpath, $fondo, $layout['background'] ?? []);
        }

        $this->entradas['word/document.xml'] = (string) $documento->saveXML();

        return $this->escribir();
    }

    /**
     * Geometría de la primera sección (para validar el área segura).
     *
     * @return array{page_w_mm: float, page_h_mm: float, top_mm: float, right_mm: float, bottom_mm: float, left_mm: float}|null
     */
    public function geometria(string $docx): ?array
    {
        $this->leer($docx);
        $xpath = $this->xpath($this->dom('word/document.xml'));
        $sectPr = $this->primero($xpath, '//w:body/w:sectPr');

        if (! $sectPr instanceof DOMElement) {
            return null;
        }

        $pgSz = $this->hijo($sectPr, 'pgSz');
        $pgMar = $this->hijo($sectPr, 'pgMar');
        $mm = fn (?DOMElement $e, string $attr, float $defecto): float => $e !== null && $e->getAttributeNS(self::NS_W, $attr) !== ''
            ? round((float) $e->getAttributeNS(self::NS_W, $attr) / self::TWIPS_POR_CM * 10, 1)
            : $defecto;

        return [
            'page_w_mm' => $mm($pgSz, 'w', 215.9),
            'page_h_mm' => $mm($pgSz, 'h', 279.4),
            'top_mm' => $mm($pgMar, 'top', 25.0),
            'right_mm' => $mm($pgMar, 'right', 30.0),
            'bottom_mm' => $mm($pgMar, 'bottom', 25.0),
            'left_mm' => $mm($pgMar, 'left', 30.0),
        ];
    }

    private function leer(string $docx): void
    {
        $temporal = tempnam(sys_get_temp_dir(), 'lay');

        if ($temporal === false) {
            throw new RuntimeException('No se pudo crear un temporal para el DOCX.');
        }

        file_put_contents($temporal, $docx);
        $zip = new ZipArchive;

        try {
            if ($zip->open($temporal) !== true) {
                throw new RuntimeException('El DOCX no se pudo abrir para aplicar el diseño.');
            }

            $this->entradas = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $this->entradas[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
            }

            $zip->close();
        } finally {
            @unlink($temporal);
        }
    }

    private function escribir(): string
    {
        $temporal = tempnam(sys_get_temp_dir(), 'lay');

        if ($temporal === false) {
            throw new RuntimeException('No se pudo crear un temporal para el DOCX.');
        }

        @unlink($temporal);
        $zip = new ZipArchive;

        if ($zip->open($temporal, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo escribir el DOCX con el diseño aplicado.');
        }

        try {
            $nombres = array_keys($this->entradas);
            usort($nombres, fn (string $a, string $b): int => [$a !== '[Content_Types].xml'] <=> [$b !== '[Content_Types].xml']);

            foreach ($nombres as $nombre) {
                $zip->addFromString($nombre, $this->entradas[$nombre]);
            }

            $zip->close();

            return (string) file_get_contents($temporal);
        } finally {
            @unlink($temporal);
        }
    }

    private function dom(string $parte): DOMDocument
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;

        if (! isset($this->entradas[$parte]) || ! $dom->loadXML($this->entradas[$parte], LIBXML_NONET | LIBXML_PARSEHUGE)) {
            throw new RuntimeException("No se pudo leer {$parte} del DOCX.");
        }

        return $dom;
    }

    private function xpath(DOMDocument $dom): DOMXPath
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::NS_W);
        $xpath->registerNamespace('r', self::NS_R);
        $xpath->registerNamespace('wp', self::NS_WP);
        $xpath->registerNamespace('rel', self::NS_REL);
        $xpath->registerNamespace('ct', self::NS_CT);

        return $xpath;
    }

    private function primero(DOMXPath $xpath, string $expresion): ?DOMElement
    {
        $nodos = $xpath->query($expresion);
        $nodo = $nodos !== false ? $nodos->item(0) : null;

        return $nodo instanceof DOMElement ? $nodo : null;
    }

    private function hijo(DOMElement $padre, string $local): ?DOMElement
    {
        foreach ($padre->childNodes as $nodo) {
            if ($nodo instanceof DOMElement && $nodo->namespaceURI === self::NS_W && $nodo->localName === $local) {
                return $nodo;
            }
        }

        return null;
    }

    /**
     * Quita los dibujos/imágenes de los encabezados (el fondo y logo del
     * original) conservando los párrafos y su texto.
     */
    private function retirarDibujosDeEncabezados(): void
    {
        foreach (array_keys($this->entradas) as $parte) {
            if (preg_match('#^word/header\d*\.xml$#', $parte) !== 1) {
                continue;
            }

            $dom = $this->dom($parte);
            $xpath = $this->xpath($dom);

            foreach (iterator_to_array($xpath->query('//w:r[w:drawing or w:pict]') ?: []) as $run) {
                if ($run instanceof DOMElement) {
                    $run->parentNode?->removeChild($run);
                }
            }

            $this->entradas[$parte] = (string) $dom->saveXML();
        }
    }

    /**
     * @param  array{top?: float, right?: float, bottom?: float, left?: float, header?: float}|null  $margenes
     */
    private function aplicarMargenes(DOMElement $sectPr, ?array $margenes): void
    {
        if ($margenes === null || $margenes === []) {
            return;
        }

        $pgMar = $this->hijo($sectPr, 'pgMar');

        if ($pgMar === null) {
            return;
        }

        foreach (['top' => 'top', 'right' => 'right', 'bottom' => 'bottom', 'left' => 'left', 'header' => 'header'] as $clave => $atributo) {
            if (isset($margenes[$clave])) {
                $pgMar->setAttributeNS(self::NS_W, 'w:'.$atributo, (string) (int) round((float) $margenes[$clave] * self::TWIPS_POR_CM));
            }
        }
    }

    /**
     * @param  array{left_indent_cm?: float|null, right_indent_cm?: float|null, space_before_pt?: float|null, space_after_pt?: float|null, justify?: bool, keep_lines?: bool}  $reglas
     */
    private function aplicarParrafos(DOMXPath $xpath, array $reglas): void
    {
        if ($reglas === []) {
            return;
        }

        // Párrafos del cuerpo fuera de tablas y de cuadros de texto.
        $parrafos = $xpath->query('/w:document/w:body/w:p | /w:document/w:body/w:sdt/w:sdtContent/w:p') ?: [];

        foreach ($parrafos as $p) {
            if (! $p instanceof DOMElement || ! $this->esParrafoNormal($xpath, $p)) {
                continue;
            }

            $pPr = $this->pPr($p);

            if (isset($reglas['left_indent_cm']) || isset($reglas['right_indent_cm'])) {
                $ind = $this->hijo($pPr, 'ind') ?? $this->insertarEnPpr($pPr, 'ind');

                if (isset($reglas['left_indent_cm'])) {
                    $ind->setAttributeNS(self::NS_W, 'w:left', (string) (int) round((float) $reglas['left_indent_cm'] * self::TWIPS_POR_CM));
                }

                if (isset($reglas['right_indent_cm'])) {
                    $ind->setAttributeNS(self::NS_W, 'w:right', (string) (int) round((float) $reglas['right_indent_cm'] * self::TWIPS_POR_CM));
                }
            }

            if (isset($reglas['space_before_pt']) || isset($reglas['space_after_pt'])) {
                $spacing = $this->hijo($pPr, 'spacing') ?? $this->insertarEnPpr($pPr, 'spacing');

                if (isset($reglas['space_before_pt'])) {
                    $spacing->setAttributeNS(self::NS_W, 'w:before', (string) (int) round((float) $reglas['space_before_pt'] * 20));
                }

                if (isset($reglas['space_after_pt'])) {
                    $spacing->setAttributeNS(self::NS_W, 'w:after', (string) (int) round((float) $reglas['space_after_pt'] * 20));
                }
            }

            // Un párrafo no se parte entre dos hojas (no deja colas sueltas).
            if (($reglas['keep_lines'] ?? false) === true && $this->hijo($pPr, 'keepLines') === null) {
                $this->insertarEnPpr($pPr, 'keepLines');
            }

            if (($reglas['justify'] ?? false) === true) {
                $jc = $this->hijo($pPr, 'jc') ?? $this->insertarEnPpr($pPr, 'jc');
                $jc->setAttributeNS(self::NS_W, 'w:val', 'both');
            }
        }
    }

    private function esParrafoNormal(DOMXPath $xpath, DOMElement $p): bool
    {
        $pPr = $this->hijo($p, 'pPr');

        // Con imagen/dibujo: lo decide su propio formato.
        $dibujos = $xpath->query('.//w:drawing | .//w:pict', $p);

        if ($dibujos !== false && $dibujos->length > 0) {
            return false;
        }

        // Párrafo vacío (separador): no cambia nada al darle sangría, pero
        // tampoco se le quita su espaciado (es parte del ritmo del original).
        if (trim($p->textContent) === '') {
            return false;
        }

        if ($pPr === null) {
            return true;
        }

        if ($this->hijo($pPr, 'numPr') !== null || $this->hijo($pPr, 'sectPr') !== null) {
            return false;
        }

        $jc = $this->hijo($pPr, 'jc')?->getAttributeNS(self::NS_W, 'val');

        if (in_array($jc, ['center', 'right', 'end'], true)) {
            return false;
        }

        $estilo = mb_strtolower((string) $this->hijo($pPr, 'pStyle')?->getAttributeNS(self::NS_W, 'val'));

        if ($estilo !== '' && preg_match('/t[ií]tulo|heading|title|encabezado|firma|lista|list/u', $estilo) === 1) {
            return false;
        }

        // Ya trae su propia sangría izquierda: configuración propia del bloque.
        $ind = $this->hijo($pPr, 'ind');
        $izquierda = $ind !== null ? (int) ($ind->getAttributeNS(self::NS_W, 'left') ?: $ind->getAttributeNS(self::NS_W, 'start') ?: '0') : 0;

        return $izquierda === 0;
    }

    private function pPr(DOMElement $p): DOMElement
    {
        $pPr = $this->hijo($p, 'pPr');

        if ($pPr !== null) {
            return $pPr;
        }

        $pPr = $p->ownerDocument?->createElementNS(self::NS_W, 'w:pPr');

        if (! $pPr instanceof DOMElement) {
            throw new RuntimeException('No se pudo crear el formato del párrafo.');
        }

        $p->insertBefore($pPr, $p->firstChild);

        return $pPr;
    }

    /**
     * Inserta un hijo de w:pPr respetando el orden del esquema (los que
     * importan aquí: spacing < ind < jc, y todos antes de rPr/sectPr).
     */
    private function insertarEnPpr(DOMElement $pPr, string $local): DOMElement
    {
        $orden = ['pStyle', 'keepNext', 'keepLines', 'pageBreakBefore', 'framePr', 'widowControl', 'numPr', 'suppressLineNumbers', 'pBdr', 'shd', 'tabs', 'suppressAutoHyphens', 'kinsoku', 'wordWrap', 'overflowPunct', 'topLinePunct', 'autoSpaceDE', 'autoSpaceDN', 'bidi', 'adjustRightInd', 'snapToGrid', 'spacing', 'ind', 'contextualSpacing', 'mirrorIndents', 'suppressOverlap', 'jc', 'textDirection', 'textAlignment', 'textboxTightWrap', 'outlineLvl', 'divId', 'cnfStyle', 'rPr', 'sectPr', 'pPrChange'];
        $posicion = array_search($local, $orden, true);
        $nuevo = $pPr->ownerDocument?->createElementNS(self::NS_W, 'w:'.$local);

        if (! $nuevo instanceof DOMElement) {
            throw new RuntimeException('No se pudo crear el formato del párrafo.');
        }

        foreach ($pPr->childNodes as $hijo) {
            if ($hijo instanceof DOMElement && array_search($hijo->localName, $orden, true) > $posicion) {
                $pPr->insertBefore($nuevo, $hijo);

                return $nuevo;
            }
        }

        $pPr->appendChild($nuevo);

        return $nuevo;
    }

    /**
     * @param  array{bytes: string, extension: string, width: int, height: int, sha256: string}  $fondo
     * @param  array{apply_to?: string, fit?: string, opacity?: int}  $config
     */
    private function insertarFondo(DOMDocument $documento, DOMXPath $xpath, array $fondo, array $config): void
    {
        $extension = strtolower($fondo['extension']) === 'jpg' ? 'jpeg' : strtolower($fondo['extension']);
        $medio = sprintf('media/fondo-%s.%s', substr($fondo['sha256'], 0, 16), $extension);
        $this->entradas['word/'.$medio] = $fondo['bytes'];
        $this->asegurarTipoContenido($extension, 'image/'.$extension);
        $soloPrimera = ($config['apply_to'] ?? 'all_pages') === 'first_page';
        $idDibujo = 9100;

        foreach (iterator_to_array($xpath->query('//w:body//w:sectPr | //w:body/w:sectPr') ?: []) as $sectPr) {
            if (! $sectPr instanceof DOMElement) {
                continue;
            }

            $pgSz = $this->hijo($sectPr, 'pgSz');
            $anchoTw = (int) ($pgSz?->getAttributeNS(self::NS_W, 'w') ?: 12240);
            $altoTw = (int) ($pgSz?->getAttributeNS(self::NS_W, 'h') ?: 15840);
            $geometria = $this->geometriaFondo($anchoTw * self::EMU_POR_TWIP, $altoTw * self::EMU_POR_TWIP, $fondo['width'], $fondo['height'], (string) ($config['fit'] ?? 'stretch'));

            if ($soloPrimera) {
                // Primera página: encabezado «first» propio (titlePg); las
                // demás conservan su encabezado sin fondo.
                $this->asegurarTitlePg($sectPr);
                $partes = [$this->asegurarEncabezado($documento, $sectPr, 'first')];
            } else {
                $partes = [];

                foreach (['default', 'first', 'even'] as $tipo) {
                    $existe = $this->referenciaEncabezado($sectPr, $tipo);

                    if ($existe !== null || $tipo === 'default') {
                        $partes[] = $existe ?? $this->asegurarEncabezado($documento, $sectPr, 'default');
                    }
                }
            }

            foreach (array_unique($partes) as $parte) {
                $this->insertarDibujoEnEncabezado($parte, $medio, $geometria, (int) ($config['opacity'] ?? 100), $idDibujo++);
            }
        }
    }

    /**
     * Tamaño y posición del fondo en EMU según el ajuste: stretch = la hoja
     * exacta; contain = cabe completo, centrado; cover = cubre la hoja,
     * centrado (lo que sobra queda fuera de la página).
     *
     * @return array{x: int, y: int, cx: int, cy: int}
     */
    private function geometriaFondo(int $paginaCx, int $paginaCy, int $imgW, int $imgH, string $fit): array
    {
        if ($fit === 'stretch' || $imgW <= 0 || $imgH <= 0) {
            return ['x' => 0, 'y' => 0, 'cx' => $paginaCx, 'cy' => $paginaCy];
        }

        $escala = $fit === 'cover'
            ? max($paginaCx / $imgW, $paginaCy / $imgH)
            : min($paginaCx / $imgW, $paginaCy / $imgH);
        $cx = (int) round($imgW * $escala);
        $cy = (int) round($imgH * $escala);

        return ['x' => (int) round(($paginaCx - $cx) / 2), 'y' => (int) round(($paginaCy - $cy) / 2), 'cx' => $cx, 'cy' => $cy];
    }

    private function referenciaEncabezado(DOMElement $sectPr, string $tipo): ?string
    {
        foreach ($sectPr->childNodes as $nodo) {
            if ($nodo instanceof DOMElement && $nodo->localName === 'headerReference' && $nodo->getAttributeNS(self::NS_W, 'type') === $tipo) {
                $destino = $this->destinoRelacion('word/_rels/document.xml.rels', $nodo->getAttributeNS(self::NS_R, 'id'));

                return $destino !== null ? 'word/'.$destino : null;
            }
        }

        return null;
    }

    private function asegurarTitlePg(DOMElement $sectPr): void
    {
        if ($this->hijo($sectPr, 'titlePg') !== null) {
            return;
        }

        $titlePg = $sectPr->ownerDocument?->createElementNS(self::NS_W, 'w:titlePg');

        if (! $titlePg instanceof DOMElement) {
            return;
        }

        // titlePg va después de pgNumType/cols/formProt/vAlign/noEndnote.
        $despues = null;

        foreach (['textDirection', 'bidi', 'rtlGutter', 'docGrid', 'printerSettings'] as $local) {
            $despues ??= $this->hijo($sectPr, $local);
        }

        $despues !== null ? $sectPr->insertBefore($titlePg, $despues) : $sectPr->appendChild($titlePg);
    }

    /**
     * Devuelve la parte del encabezado del tipo dado; si no existe la crea
     * (un solo párrafo vacío) y la referencia en la sección.
     */
    private function asegurarEncabezado(DOMDocument $documento, DOMElement $sectPr, string $tipo): string
    {
        $existente = $this->referenciaEncabezado($sectPr, $tipo);

        if ($existente !== null) {
            return $existente;
        }

        $n = 1;

        while (isset($this->entradas[sprintf('word/header%d.xml', $n)])) {
            $n++;
        }

        $parte = sprintf('word/header%d.xml', $n);
        $this->entradas[$parte] = sprintf('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:hdr xmlns:w="%s" xmlns:r="%s"><w:p><w:pPr><w:spacing w:before="0" w:after="0" w:line="240" w:lineRule="auto"/></w:pPr></w:p></w:hdr>', self::NS_W, self::NS_R);
        $this->asegurarOverride('/'.$parte, self::CT_HEADER);
        $rId = $this->agregarRelacion('word/_rels/document.xml.rels', self::REL_HEADER, basename($parte));

        $referencia = $documento->createElementNS(self::NS_W, 'w:headerReference');
        $referencia->setAttributeNS(self::NS_W, 'w:type', $tipo);
        $referencia->setAttributeNS(self::NS_R, 'r:id', $rId);
        // headerReference va al inicio de sectPr.
        $sectPr->insertBefore($referencia, $sectPr->firstChild);

        return $parte;
    }

    /**
     * @param  array{x: int, y: int, cx: int, cy: int}  $g
     */
    private function insertarDibujoEnEncabezado(string $parte, string $medio, array $g, int $opacidad, int $idDibujo): void
    {
        $rels = sprintf('word/_rels/%s.rels', basename($parte));
        $rId = $this->agregarRelacion($rels, self::REL_IMAGE, $medio);
        $dom = $this->dom($parte);
        $xpath = $this->xpath($dom);
        $parrafo = $this->primero($xpath, '//w:p');

        if (! $parrafo instanceof DOMElement) {
            $raiz = $dom->documentElement;

            if ($raiz === null) {
                throw new RuntimeException("El encabezado {$parte} está vacío.");
            }

            $parrafo = $dom->createElementNS(self::NS_W, 'w:p');
            $raiz->appendChild($parrafo);
        }

        $alpha = $opacidad < 100 ? sprintf('<a:alphaModFix amt="%d"/>', max(0, min(100, $opacidad)) * 1000) : '';
        $xml = sprintf(
            '<w:r xmlns:w="%1$s" xmlns:r="%2$s" xmlns:wp="%3$s" xmlns:a="%4$s" xmlns:pic="%5$s"><w:rPr><w:noProof/></w:rPr><w:drawing>'
            .'<wp:anchor distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="0" behindDoc="1" locked="1" layoutInCell="1" allowOverlap="1">'
            .'<wp:simplePos x="0" y="0"/><wp:positionH relativeFrom="page"><wp:posOffset>%6$d</wp:posOffset></wp:positionH><wp:positionV relativeFrom="page"><wp:posOffset>%7$d</wp:posOffset></wp:positionV>'
            .'<wp:extent cx="%8$d" cy="%9$d"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapNone/><wp:docPr id="%10$d" name="Fondo de página"/>'
            .'<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1" noSelect="1" noMove="1" noResize="1"/></wp:cNvGraphicFramePr>'
            .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="%10$d" name="Fondo de página"/><pic:cNvPicPr><a:picLocks noChangeAspect="1"/></pic:cNvPicPr></pic:nvPicPr>'
            .'<pic:blipFill><a:blip r:embed="%11$s">%12$s</a:blip><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            .'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="%8$d" cy="%9$d"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic>'
            .'</wp:anchor></w:drawing></w:r>',
            self::NS_W, self::NS_R, self::NS_WP, self::NS_A, self::NS_PIC,
            $g['x'], $g['y'], $g['cx'], $g['cy'], $idDibujo, $rId, $alpha,
        );

        $fragmento = new DOMDocument;
        $fragmento->loadXML($xml);
        $run = $fragmento->documentElement;

        if ($run === null) {
            throw new RuntimeException('No se pudo construir el fondo de página.');
        }

        $importado = $dom->importNode($run, true);
        $pPr = $this->hijo($parrafo, 'pPr');
        $this->insertarDespues($parrafo, $importado, $pPr);
        $this->entradas[$parte] = (string) $dom->saveXML();
    }

    private function insertarDespues(DOMElement $padre, DOMNode $nuevo, ?DOMElement $referencia): void
    {
        if ($referencia === null) {
            $padre->insertBefore($nuevo, $padre->firstChild);

            return;
        }

        $referencia->nextSibling !== null ? $padre->insertBefore($nuevo, $referencia->nextSibling) : $padre->appendChild($nuevo);
    }

    private function destinoRelacion(string $rels, string $id): ?string
    {
        if (! isset($this->entradas[$rels])) {
            return null;
        }

        $dom = $this->dom($rels);

        foreach ($dom->getElementsByTagNameNS(self::NS_REL, 'Relationship') as $rel) {
            if ($rel->getAttribute('Id') === $id) {
                return $rel->getAttribute('Target');
            }
        }

        return null;
    }

    private function agregarRelacion(string $rels, string $tipo, string $destino): string
    {
        $dom = new DOMDocument;

        if (isset($this->entradas[$rels])) {
            $dom->loadXML($this->entradas[$rels], LIBXML_NONET);
        } else {
            $dom->loadXML(sprintf('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="%s"/>', self::NS_REL));
        }

        $usados = [];

        foreach ($dom->getElementsByTagNameNS(self::NS_REL, 'Relationship') as $rel) {
            if ($rel->getAttribute('Type') === $tipo && $rel->getAttribute('Target') === $destino) {
                return $rel->getAttribute('Id');
            }

            $usados[$rel->getAttribute('Id')] = true;
        }

        $n = 1;

        while (isset($usados['rIdPeople'.$n])) {
            $n++;
        }

        $id = 'rIdPeople'.$n;
        $rel = $dom->createElementNS(self::NS_REL, 'Relationship');
        $rel->setAttribute('Id', $id);
        $rel->setAttribute('Type', $tipo);
        $rel->setAttribute('Target', $destino);
        $dom->documentElement?->appendChild($rel);
        $this->entradas[$rels] = (string) $dom->saveXML();

        return $id;
    }

    private function asegurarTipoContenido(string $extension, string $mime): void
    {
        $dom = $this->dom('[Content_Types].xml');

        foreach ($dom->getElementsByTagNameNS(self::NS_CT, 'Default') as $default) {
            if (strtolower($default->getAttribute('Extension')) === $extension) {
                return;
            }
        }

        $nuevo = $dom->createElementNS(self::NS_CT, 'Default');
        $nuevo->setAttribute('Extension', $extension);
        $nuevo->setAttribute('ContentType', $mime);
        $dom->documentElement?->insertBefore($nuevo, $dom->documentElement->firstChild);
        $this->entradas['[Content_Types].xml'] = (string) $dom->saveXML();
    }

    private function asegurarOverride(string $parte, string $mime): void
    {
        $dom = $this->dom('[Content_Types].xml');

        foreach ($dom->getElementsByTagNameNS(self::NS_CT, 'Override') as $override) {
            if ($override->getAttribute('PartName') === $parte) {
                return;
            }
        }

        $nuevo = $dom->createElementNS(self::NS_CT, 'Override');
        $nuevo->setAttribute('PartName', $parte);
        $nuevo->setAttribute('ContentType', $mime);
        $dom->documentElement?->appendChild($nuevo);
        $this->entradas['[Content_Types].xml'] = (string) $dom->saveXML();
    }
}
