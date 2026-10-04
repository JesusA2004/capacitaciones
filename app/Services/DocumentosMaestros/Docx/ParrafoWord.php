<?php

namespace App\Services\DocumentosMaestros\Docx;

use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Un párrafo (<w:p>) de un DOCX visto como texto continuo.
 *
 * Segmentos: cada <w:t> aporta su texto, cada <w:tab/> aporta "\t" y cada
 * <w:br/> aporta "\n" (este último nunca se sustituye: separa líneas). Se
 * excluyen el texto de cuadros de texto anidados (son otros párrafos) y el
 * de revisiones borradas (<w:del>).
 *
 * reemplazar() sustituye un rango de caracteres aunque cruce varios runs:
 * el texto nuevo queda en el primer run del rango (hereda su formato) y los
 * demás runs del rango pierden solo la porción sustituida — nunca se
 * reconstruye el párrafo ni se pierde el formato de lo que no se tocó.
 */
class ParrafoWord
{
    /** @var list<array{nodo: DOMElement, texto: string, inicio: int, fin: int}>|null */
    private ?array $segmentos = null;

    public function __construct(
        public readonly DOMElement $nodo,
        private readonly DOMXPath $xpath,
        public readonly string $parte,
        public readonly int $indice,
    ) {}

    public function texto(): string
    {
        return implode('', array_map(fn (array $s): string => $s['texto'], $this->segmentos()));
    }

    public function referencia(): string
    {
        return sprintf('%s#%d', basename($this->parte, '.xml'), $this->indice);
    }

    /**
     * true si el párrafo está dentro de una celda de tabla.
     */
    public function enTabla(): bool
    {
        return $this->celda() !== null;
    }

    public function celda(): ?DOMElement
    {
        $nodo = $this->nodo->parentNode;

        while ($nodo !== null) {
            if ($nodo instanceof DOMElement && $nodo->localName === 'tc') {
                return $nodo;
            }

            if ($nodo instanceof DOMElement && $nodo->localName === 'txbxContent') {
                return null;
            }

            $nodo = $nodo->parentNode;
        }

        return null;
    }

    /**
     * Fila de tabla (<w:tr>) que contiene al párrafo, si existe.
     */
    public function fila(): ?DOMElement
    {
        $celda = $this->celda();
        $padre = $celda?->parentNode;

        return $padre instanceof DOMElement && $padre->localName === 'tr' ? $padre : null;
    }

    /**
     * Celda siguiente en la misma fila (para tablas "Etiqueta | valor").
     */
    public function celdaSiguiente(): ?DOMElement
    {
        $celda = $this->celda();
        $siguiente = $celda?->nextSibling;

        while ($siguiente !== null && ! ($siguiente instanceof DOMElement && $siguiente->localName === 'tc')) {
            $siguiente = $siguiente->nextSibling;
        }

        return $siguiente instanceof DOMElement ? $siguiente : null;
    }

    /**
     * Sustituye los caracteres [inicio, fin) del texto del párrafo.
     */
    public function reemplazar(int $inicio, int $fin, string $nuevo): void
    {
        $segmentos = $this->segmentos();
        $insertado = false;

        foreach ($segmentos as $segmento) {
            if ($segmento['fin'] <= $inicio || $segmento['inicio'] >= $fin) {
                continue;
            }

            $nodo = $segmento['nodo'];
            $local = $nodo->localName;

            if (! $insertado) {
                $insertado = true;

                if ($local === 't') {
                    $antes = mb_substr($segmento['texto'], 0, max(0, $inicio - $segmento['inicio']));
                    $despues = $fin < $segmento['fin'] ? mb_substr($segmento['texto'], $fin - $segmento['inicio']) : '';
                    $this->fijarTexto($nodo, $antes.$nuevo.$despues);
                } else {
                    // Un tabulador (blanco subrayado con tabs) se vuelve texto.
                    $t = $this->crearTexto($nodo, $nuevo);
                    $nodo->parentNode?->replaceChild($t, $nodo);
                }

                continue;
            }

            if ($local === 't') {
                $resto = $fin < $segmento['fin'] ? mb_substr($segmento['texto'], $fin - $segmento['inicio']) : '';
                $this->fijarTexto($nodo, $resto);
            } elseif ($local === 'tab') {
                $nodo->parentNode?->removeChild($nodo);
            }
        }

        $this->segmentos = null;
    }

    /**
     * Sustituye TODO el texto del párrafo (o lo crea si está vacío,
     * copiando las propiedades del run de referencia sin negritas).
     */
    public function fijarTodo(string $nuevo, ?DOMElement $runReferencia = null): void
    {
        $texto = $this->texto();

        if ($texto !== '' && $this->segmentos() !== []) {
            $this->reemplazar(0, mb_strlen($texto), $nuevo);

            return;
        }

        $documento = $this->nodo->ownerDocument;

        if ($documento === null) {
            return;
        }

        $run = $documento->createElementNS(DocumentoWord::NS, 'w:r');
        $propiedades = $this->propiedadesDeRun($runReferencia);

        if ($propiedades !== null) {
            $run->appendChild($propiedades);
        }

        $t = $documento->createElementNS(DocumentoWord::NS, 'w:t', '');
        $this->fijarTexto($t, $nuevo);
        $run->appendChild($t);
        $this->nodo->appendChild($run);
        $this->segmentos = null;
    }

    /**
     * Primer run con texto del párrafo (para copiar su formato).
     */
    public function primerRun(): ?DOMElement
    {
        foreach ($this->segmentos() as $segmento) {
            $run = $segmento['nodo']->parentNode;

            if ($run instanceof DOMElement && $run->localName === 'r') {
                return $run;
            }
        }

        return null;
    }

    /**
     * @return list<array{nodo: DOMElement, texto: string, inicio: int, fin: int}>
     */
    private function segmentos(): array
    {
        if ($this->segmentos !== null) {
            return $this->segmentos;
        }

        $segmentos = [];
        $offset = 0;

        foreach ($this->xpath->query('.//w:t|.//w:tab|.//w:br', $this->nodo) ?: [] as $nodo) {
            if (! $nodo instanceof DOMElement || ! $this->pertenece($nodo)) {
                continue;
            }

            // <w:tab> dentro de <w:pPr><w:tabs> es definición de tabulación, no texto.
            if ($nodo->localName === 'tab' && $nodo->parentNode instanceof DOMElement && $nodo->parentNode->localName === 'tabs') {
                continue;
            }

            $texto = match ($nodo->localName) {
                't' => $nodo->textContent,
                'tab' => "\t",
                default => "\n",
            };
            $largo = mb_strlen($texto);
            $segmentos[] = ['nodo' => $nodo, 'texto' => $texto, 'inicio' => $offset, 'fin' => $offset + $largo];
            $offset += $largo;
        }

        return $this->segmentos = $segmentos;
    }

    /**
     * El nodo pertenece a ESTE párrafo (no a un párrafo anidado en un cuadro
     * de texto) y no está en una revisión borrada.
     */
    private function pertenece(DOMElement $nodo): bool
    {
        $padre = $nodo->parentNode;

        while ($padre !== null && $padre !== $this->nodo) {
            if ($padre instanceof DOMElement && in_array($padre->localName, ['p', 'del', 'txbxContent'], true)) {
                return false;
            }

            $padre = $padre->parentNode;
        }

        return $padre === $this->nodo;
    }

    private function fijarTexto(DOMElement $t, string $texto): void
    {
        while ($t->firstChild !== null) {
            $t->removeChild($t->firstChild);
        }

        $documento = $t->ownerDocument;

        if ($documento !== null) {
            $t->appendChild($documento->createTextNode($texto));
        }

        $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
    }

    private function crearTexto(DOMNode $referencia, string $texto): DOMElement
    {
        $documento = $referencia->ownerDocument;
        assert($documento !== null);
        $t = $documento->createElementNS(DocumentoWord::NS, 'w:t');
        $this->fijarTexto($t, $texto);

        return $t;
    }

    private function propiedadesDeRun(?DOMElement $run): ?DOMNode
    {
        $fuente = null;

        if ($run !== null) {
            foreach ($run->childNodes as $hijo) {
                if ($hijo instanceof DOMElement && $hijo->localName === 'rPr') {
                    $fuente = $hijo;
                }
            }
        }

        // Sin run de referencia: las propiedades de la marca de párrafo.
        if ($fuente === null) {
            foreach ($this->xpath->query('./w:pPr/w:rPr', $this->nodo) ?: [] as $rPr) {
                $fuente = $rPr;
            }
        }

        if (! $fuente instanceof DOMElement) {
            return null;
        }

        $copia = $fuente->cloneNode(true);

        if ($copia instanceof DOMElement) {
            foreach (['b', 'bCs'] as $negrita) {
                foreach (iterator_to_array($copia->childNodes) as $hijo) {
                    if ($hijo instanceof DOMElement && $hijo->localName === $negrita) {
                        $copia->removeChild($hijo);
                    }
                }
            }
        }

        return $copia;
    }
}
