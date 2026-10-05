<?php

namespace App\Services\DocumentosMaestros\Calidad;

use App\Services\DocumentosMaestros\Docx\DocumentoWord;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;
use ZipArchive;

/**
 * ¿Qué fuentes usa un documento Word y existen en ESTE servidor?
 *
 * Una fuente faltante no rompe el DOCX (Word en la PC de RH la tiene),
 * pero al convertir a PDF el conversor la sustituye y el documento oficial
 * cambia de tipografía y de cortes de línea. Por eso:
 *
 *  - se extraen las fuentes realmente usadas: runs de documento, encabezados
 *    y pies; estilos usados (con su cadena basedOn); valores por defecto del
 *    documento; fuentes del tema (minorHAnsi → Calibri…); símbolos (w:sym);
 *  - se comparan contra las familias instaladas (Windows: tabla `name` de
 *    cada TTF/OTF/TTC; Linux: fc-list);
 *  - una fuente faltante marca el QA visual como fallido para el PDF
 *    definitivo, con la acción concreta ("instalar la fuente X").
 */
class DiagnosticoFuentesService
{
    /** Sustitutos de métrica compatible conocidos (mismo ancho de glifos). */
    private const SUSTITUTOS = [
        'arial' => 'Liberation Sans',
        'times new roman' => 'Liberation Serif',
        'courier new' => 'Liberation Mono',
        'calibri' => 'Carlito',
        'cambria' => 'Caladea',
    ];

    /** Familias genéricas que todo conversor resuelve sin cambiar el diseño. */
    private const IGNORADAS = ['symbol', 'wingdings', 'ms mincho', 'ms gothic', 'simsun', 'mangal', 'arial unicode ms'];

    /**
     * @return list<array{fuente: string, disponible: bool, sustitucion: string|null}>
     */
    public function diagnosticar(string $docx): array
    {
        $disponibles = $this->disponibles();
        $resultado = [];

        foreach ($this->fuentesUsadas($docx) as $fuente) {
            $clave = mb_strtolower($fuente);
            $existe = isset($disponibles[$clave]) || in_array($clave, self::IGNORADAS, true);
            $sustituto = self::SUSTITUTOS[$clave] ?? null;

            $resultado[] = [
                'fuente' => $fuente,
                'disponible' => $existe,
                'sustitucion' => ! $existe && $sustituto !== null && isset($disponibles[mb_strtolower($sustituto)]) ? $sustituto : null,
            ];
        }

        return $resultado;
    }

    /**
     * @return list<string>
     */
    public function fuentesUsadas(string $docx): array
    {
        $partes = $this->partes($docx);

        if (! isset($partes['word/document.xml'])) {
            return [];
        }

        $tema = $this->fuentesTema($partes['word/theme/theme1.xml'] ?? null);
        $fuentes = [];
        $estilosUsados = ['Normal' => true];

        foreach ($partes as $nombre => $xml) {
            if (preg_match('#^word/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$#', $nombre) !== 1) {
                continue;
            }

            $xpath = $this->xpath($xml);

            if ($xpath === null) {
                continue;
            }

            foreach ($xpath->query('//w:rFonts') ?: [] as $nodo) {
                if ($nodo instanceof DOMElement) {
                    $this->agregarRFonts($nodo, $tema, $fuentes);
                }
            }

            foreach ($xpath->query('//w:sym[@w:font]') ?: [] as $nodo) {
                if ($nodo instanceof DOMElement) {
                    $fuentes[$nodo->getAttributeNS(DocumentoWord::NS, 'font')] = true;
                }
            }

            foreach ($xpath->query('//w:pStyle/@w:val|//w:rStyle/@w:val|//w:tblStyle/@w:val') ?: [] as $atributo) {
                $estilosUsados[(string) $atributo->nodeValue] = true;
            }
        }

        $this->agregarEstilos($partes['word/styles.xml'] ?? null, array_keys($estilosUsados), $tema, $fuentes);

        $lista = array_values(array_filter(array_map('strval', array_keys($fuentes)), fn (string $f): bool => trim($f) !== ''));
        sort($lista);

        return $lista;
    }

    /**
     * Familias instaladas (minúsculas → true). Se cachea un día: instalar
     * una fuente requiere limpiar caché (php artisan cache:clear).
     *
     * @return array<string, true>
     */
    public function disponibles(): array
    {
        $forzadas = config('documentos_maestros.validacion_visual.fuentes_disponibles');

        if (is_array($forzadas)) {
            return array_fill_keys(array_map(fn (mixed $f): string => mb_strtolower((string) $f), $forzadas), true);
        }

        /** @var array<string, true> $familias */
        $familias = Cache::remember('documentos-maestros:fuentes:'.PHP_OS_FAMILY, now()->addDay(), fn (): array => PHP_OS_FAMILY === 'Windows' ? $this->familiasWindows() : $this->familiasFontconfig());

        return $familias;
    }

    /**
     * @param  array<string, string>  $tema
     * @param  array<string, true>  $fuentes
     */
    private function agregarRFonts(DOMElement $nodo, array $tema, array &$fuentes): void
    {
        foreach (['ascii', 'hAnsi'] as $atributo) {
            $valor = $nodo->getAttributeNS(DocumentoWord::NS, $atributo);

            if ($valor !== '') {
                $fuentes[$valor] = true;
            }

            $deTema = $nodo->getAttributeNS(DocumentoWord::NS, $atributo.'Theme');

            if ($deTema !== '' && isset($tema[str_starts_with($deTema, 'major') ? 'major' : 'minor'])) {
                $fuentes[$tema[str_starts_with($deTema, 'major') ? 'major' : 'minor']] = true;
            }
        }
    }

    /**
     * @param  list<string>  $usados
     * @param  array<string, string>  $tema
     * @param  array<string, true>  $fuentes
     */
    private function agregarEstilos(?string $xml, array $usados, array $tema, array &$fuentes): void
    {
        $xpath = $xml !== null ? $this->xpath($xml) : null;

        if ($xpath === null) {
            // Sin styles.xml: Word usa la fuente menor del tema.
            if (isset($tema['minor'])) {
                $fuentes[$tema['minor']] = true;
            }

            return;
        }

        foreach ($xpath->query('/w:styles/w:docDefaults//w:rFonts') ?: [] as $nodo) {
            if ($nodo instanceof DOMElement) {
                $this->agregarRFonts($nodo, $tema, $fuentes);
            }
        }

        $porId = [];

        foreach ($xpath->query('/w:styles/w:style') ?: [] as $estilo) {
            if ($estilo instanceof DOMElement) {
                $porId[$estilo->getAttributeNS(DocumentoWord::NS, 'styleId')] = $estilo;
            }
        }

        $visitados = [];
        $pendientes = $usados;

        while ($pendientes !== []) {
            $id = (string) array_pop($pendientes);

            if (isset($visitados[$id]) || ! isset($porId[$id])) {
                continue;
            }

            $visitados[$id] = true;

            foreach ($xpath->query('.//w:rFonts', $porId[$id]) ?: [] as $nodo) {
                if ($nodo instanceof DOMElement) {
                    $this->agregarRFonts($nodo, $tema, $fuentes);
                }
            }

            foreach ($xpath->query('./w:basedOn/@w:val|./w:link/@w:val', $porId[$id]) ?: [] as $base) {
                $pendientes[] = (string) $base->nodeValue;
            }
        }
    }

    /**
     * @return array<string, string> major|minor → familia latina del tema
     */
    private function fuentesTema(?string $xml): array
    {
        if ($xml === null || $xml === '') {
            return [];
        }

        $tema = [];

        foreach (['major' => 'majorFont', 'minor' => 'minorFont'] as $clave => $nodo) {
            if (preg_match('#<a:'.$nodo.'>\s*<a:latin typeface="([^"]+)"#', $xml, $m) === 1) {
                $tema[$clave] = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }

        return $tema;
    }

    /**
     * @return array<string, string>
     */
    private function partes(string $docx): array
    {
        $temporal = tempnam(sys_get_temp_dir(), 'fnt');

        if ($temporal === false) {
            return [];
        }

        file_put_contents($temporal, $docx);
        $partes = [];

        try {
            $zip = new ZipArchive;

            if ($zip->open($temporal) !== true) {
                return [];
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nombre = (string) $zip->getNameIndex($i);

                if (preg_match('#^word/([a-z]+\d*\.xml|theme/theme1\.xml)$#', $nombre) === 1) {
                    $partes[$nombre] = (string) $zip->getFromIndex($i);
                }
            }

            $zip->close();
        } finally {
            @unlink($temporal);
        }

        return $partes;
    }

    private function xpath(string $xml): ?DOMXPath
    {
        $dom = new DOMDocument;

        if (! @$dom->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE)) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', DocumentoWord::NS);

        return $xpath;
    }

    /**
     * @return array<string, true>
     */
    private function familiasWindows(): array
    {
        $carpetas = array_filter([
            (getenv('WINDIR') ?: 'C:\\Windows').'\\Fonts',
            getenv('LOCALAPPDATA') ? getenv('LOCALAPPDATA').'\\Microsoft\\Windows\\Fonts' : null,
        ]);
        $familias = [];

        foreach ($carpetas as $carpeta) {
            foreach (glob($carpeta.'\\*.*') ?: [] as $archivo) {
                if (! in_array(strtolower(pathinfo($archivo, PATHINFO_EXTENSION)), ['ttf', 'otf', 'ttc'], true)) {
                    continue;
                }

                foreach (LectorNombreFuente::familias($archivo) as $familia) {
                    $familias[mb_strtolower($familia)] = true;
                }
            }
        }

        return $familias;
    }

    /**
     * @return array<string, true>
     */
    private function familiasFontconfig(): array
    {
        try {
            $resultado = Process::timeout(30)->run(['fc-list', ':', 'family']);
        } catch (Throwable) {
            return [];
        }

        if (! $resultado->successful()) {
            return [];
        }

        $familias = [];

        foreach (preg_split('/\R/', $resultado->output()) ?: [] as $linea) {
            foreach (explode(',', $linea) as $familia) {
                $familia = trim(str_replace('\\-', '-', $familia));

                if ($familia !== '') {
                    $familias[mb_strtolower($familia)] = true;
                }
            }
        }

        return $familias;
    }
}
