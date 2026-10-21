<?php

namespace App\Services\DocumentosMaestros\Docx;

use App\Models\Empresa;
use Closure;
use DOMElement;
use DOMNode;

/**
 * Representante legal y bloque de firmas de los formatos de Jurídico
 * (contratos de capacitación inicial, indeterminados, confidencialidad, no
 * competencia…), aplicado al DOCX YA RELLENADO — sirve para cualquier
 * versión guardada sin volver a normalizar ni validar el master.
 *
 * Estos formatos traen el nombre del representante como TEXTO FIJO
 * («representada por la señora Lesli Maribel Rodríguez Herrera…», «LA C.
 * LESLI MARIBEL…») porque forma parte de la declaración notarial. Reglas:
 *
 *  - DECLARACIONES: el nombre SÍ se imprime. Si la empresa tiene capturado
 *    a su representante (Administración → Empresas) se usa ese; si no, se
 *    conserva el del formato de Jurídico. Nunca se blanquea.
 *  - FIRMAS: el renglón del nombre del representante se sustituye por la
 *    línea de firma (negra) con «REPRESENTANTE LEGAL» debajo — ahí firma y
 *    escribe su nombre a pluma. Del lado del trabajador la misma línea con
 *    su nombre completo debajo: ambos lados simétricos.
 *  - Contratos: «EL C. / LA C.» delante del nombre del trabajador concuerda
 *    con su género y siempre lleva espacio tras «C.».
 *
 * Nunca imprime la cuenta de quien captura (memoria
 * «firma-empresa-representante-legal»): el único nombre de la empresa que
 * aparece es el de su representante legal en las declaraciones.
 */
class FirmasContratoDocx
{
    public const ETIQUETA_PATRON = 'REPRESENTANTE LEGAL';

    /** Orden de los hijos de <w:pPr> (CT_PPrBase): Word rechaza otro orden. */
    private const ORDEN_PPR = [
        'pStyle', 'keepNext', 'keepLines', 'pageBreakBefore', 'framePr', 'widowControl', 'numPr', 'suppressLineNumbers',
        'pBdr', 'shd', 'tabs', 'suppressAutoHyphens', 'kinsoku', 'wordWrap', 'overflowPunct', 'topLinePunct', 'autoSpaceDE',
        'autoSpaceDN', 'bidi', 'adjustRightInd', 'snapToGrid', 'spacing', 'ind', 'contextualSpacing', 'mirrorIndents',
        'suppressOverlap', 'jc', 'textDirection', 'textAlignment', 'textboxTightWrap', 'outlineLvl', 'divId', 'cnfStyle',
        'rPr', 'sectPr', 'pPrChange',
    ];

    /**
     * @param  list<string>  $nombresConocidos  Nombres del representante escritos en los formatos.
     * @param  string  $representante  Representante capturado en la empresa ('' = se conserva el del formato).
     * @param  string  $trabajador  Nombre completo del trabajador (como se imprime en el contrato).
     * @param  string  $genero  'Femenino' | 'Masculino' | '' (desconocido: no se toca el artículo).
     * @param  bool  $esContrato  Solo en contratos se ajusta «EL C./LA C.» y el renglón de firma del trabajador.
     */
    public function aplicar(string $docx, array $nombresConocidos, string $representante, string $trabajador, string $genero, bool $esContrato = true): string
    {
        $representante = $this->limpiarNombre($representante);
        $trabajador = $this->limpiarNombre($trabajador);
        $nombres = $this->nombres([...$nombresConocidos, $representante]);

        if ($nombres === [] && (! $esContrato || $trabajador === '')) {
            return $docx;
        }

        $documento = DocumentoWord::desdeBytes($docx);
        $porNodo = [];
        $firmas = [];
        $cambios = 0;

        foreach ($documento->parrafos() as $parrafo) {
            $porNodo[spl_object_id($parrafo->nodo)] = $parrafo;

            if ($parrafo->enTabla() && $this->esRenglonDelRepresentante($parrafo->texto(), $nombres)) {
                $firmas[] = $parrafo;

                continue;
            }

            $cambios += $this->representanteEnTexto($parrafo, $nombres, $representante);

            if ($esContrato && $trabajador !== '') {
                $cambios += $this->tratamientoDelTrabajador($parrafo, $trabajador, $genero);
            }

            if ($esContrato) {
                $cambios += $this->puntuacionDeclaracion($parrafo);
            }
        }

        foreach ($firmas as $firma) {
            $this->bloqueDeFirmas($firma, $porNodo, $esContrato ? $trabajador : '');
            $cambios++;
        }

        return $cambios > 0 ? $documento->guardar() : $docx;
    }

    /**
     * Nombres del representante escritos en los formatos: el de la
     * configuración (sale de los propios formatos de Jurídico 2026) y los
     * capturados en Empresas.
     *
     * @return list<string>
     */
    public static function nombresConocidos(): array
    {
        $nombres = [(string) config('documentos_maestros.empresa_defecto.representante_legal_nombre', '')];

        foreach (Empresa::query()->whereNotNull('representante_legal_nombre')->pluck('representante_legal_nombre') as $nombre) {
            $nombres[] = (string) $nombre;
        }

        return array_values(array_filter($nombres, fn (string $n): bool => trim($n) !== ''));
    }

    /**
     * @param  list<string>  $nombres
     * @return list<string> En mayúsculas, sin repetir, del más largo al más corto.
     */
    private function nombres(array $nombres): array
    {
        $limpios = array_values(array_unique(array_filter(
            array_map(fn (string $n): string => mb_strtoupper($this->limpiarNombre($n)), $nombres),
            fn (string $n): bool => mb_strlen($n) >= 5,
        )));
        usort($limpios, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $limpios;
    }

    private function limpiarNombre(string $nombre): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $nombre));
    }

    /**
     * Renglón de firma del representante: el párrafo es SOLO su nombre (con
     * o sin «C.», «LA C.», «EL C.», saltos de línea o punto final).
     *
     * @param  list<string>  $nombres
     */
    private function esRenglonDelRepresentante(string $texto, array $nombres): bool
    {
        $limpio = mb_strtoupper($this->limpiarNombre($texto));
        $limpio = (string) preg_replace('/^(?:(?:EL|LA)\s+)?C\.\s*/u', '', $limpio);
        $limpio = rtrim($limpio, ' .');

        return $limpio !== '' && in_array($limpio, $nombres, true);
    }

    /**
     * Declaraciones: el nombre fijo del formato se cambia por el capturado
     * en la empresa (mismo uso de mayúsculas que el formato). Sin
     * representante capturado se conserva el texto de Jurídico.
     *
     * @param  list<string>  $nombres
     */
    private function representanteEnTexto(ParrafoWord $parrafo, array $nombres, string $representante): int
    {
        if ($representante === '') {
            return 0;
        }

        $cambios = 0;

        foreach ($nombres as $nombre) {
            if ($nombre === mb_strtoupper($representante)) {
                continue;
            }

            $texto = $parrafo->texto();
            $posiciones = [];
            $desde = 0;

            while (($posicion = mb_strpos(mb_strtoupper($texto), $nombre, $desde)) !== false) {
                $posiciones[] = $posicion;
                $desde = $posicion + mb_strlen($nombre);
            }

            // De la última a la primera: los offsets anteriores siguen valiendo.
            foreach (array_reverse($posiciones) as $posicion) {
                $original = mb_substr($texto, $posicion, mb_strlen($nombre));
                $nuevo = $original === mb_strtoupper($original) ? mb_strtoupper($representante) : $representante;
                $parrafo->reemplazar($posicion, $posicion + mb_strlen($nombre), $nuevo);
                $cambios++;
            }
        }

        return $cambios;
    }

    /**
     * «EL C. / LA C.» delante del nombre del trabajador según su género,
     * «C.NOMBRE» con su espacio y el «NOMBRE-.» que trae la hoja de firmas
     * del original (errata del formato).
     */
    private function tratamientoDelTrabajador(ParrafoWord $parrafo, string $trabajador, string $genero): int
    {
        $articulo = match (mb_strtolower(trim($genero))) {
            'femenino' => 'LA',
            'masculino' => 'EL',
            default => null,
        };
        $nombre = preg_quote(mb_strtoupper($trabajador), '/');

        if (! str_contains(mb_strtoupper($parrafo->texto()), mb_strtoupper($trabajador))) {
            return 0;
        }

        $correcciones = [];

        if ($articulo !== null) {
            $correcciones[] = ['/\b(?:EL|LA) C\.[ \x{00A0}]*(?='.$nombre.')/u', sprintf('%s C. ', $articulo)];
        }

        $correcciones[] = ['/(?<!\p{L})C\.(?='.$nombre.')/u', 'C. '];
        // «POR LA OTRA PARTE            EL C. ____»: el hueco de espacios
        // que dejaba el formato para escribir a mano.
        $correcciones[] = ['/[ \x{00A0}]{2,}(?=(?:EL |LA )?C\.[ \x{00A0}]*'.$nombre.')/u', ' '];
        $correcciones[] = ['/(?<='.$nombre.')-(?=\.)/u', ''];
        // Erratas de los originales alrededor del nombre: «…CAPITAL VARIABLE
        // NOMBRE» (falta la «Y»), «Y  NOMBRE» y «NOMBRE  en lo sucesivo».
        $correcciones[] = ['/(?<=CAPITAL VARIABLE)[ \x{00A0}]+(?='.$nombre.')/u', ' Y '];
        $correcciones[] = ['/(?<=\bY)[ \x{00A0}]{2,}(?='.$nombre.')/u', ' '];
        $correcciones[] = ['/(?<='.$nombre.')[ \x{00A0}]+(?=en lo sucesivo)/u', ', '];

        return $this->corregir($parrafo, $correcciones);
    }

    /**
     * Declaración del trabajador en los convenios («Ser una persona física,
     * llamarse…»): el formato pegaba el dato a los dos puntos
     * («nacionalidad:Mexicana»), dejaba espacio antes de «;» / «,» (resto
     * de la línea «____ _ ;») y decía «de 31 años edad». También la
     * comilla que le falta a «EL RECEPTOR" en el ubicado en».
     */
    private function puntuacionDeclaracion(ParrafoWord $parrafo): int
    {
        $texto = ltrim($parrafo->texto());

        // «…lo firman en Ixtlahuaca, Estado de México A 5 de abril de 2026».
        if (str_starts_with($texto, 'Leído que fue')) {
            // También restos de la línea («en _Subida al club…__ el día») y la
            // fecha en mayúsculas («a 21 DE OCTUBRE DE 2026»).
            return $this->corregir($parrafo, [
                ['/(?<=[\p{L}.])[ \x{00A0}]+A[ \x{00A0}]+(?=\d{1,2} de )/u', ', a '],
                ['/_+/u', ''],
                ['/(?<=\p{L})[ \x{00A0}]+(?=a (?:los )?\d{1,2} (?:de |días))/u', ', '],
                ['/\d{1,2} DE \p{Lu}+ DE \d{4}/u', fn (string $fecha): string => mb_strtolower($fecha)],
            ]);
        }

        // Hoja de firmas: «celebrado entre celebrado entre», «de fecha 21 DE
        // OCTUBRE DE 2026  celebrado» (mayúsculas y sin coma).
        if (str_starts_with($texto, 'La presente hoja de firmas')) {
            return $this->corregir($parrafo, [
                ['/celebrado entre celebrado entre/u', 'celebrado entre'],
                ['/(?<=de fecha )\d{1,2} DE \p{Lu}+ DE \d{4}/u', fn (string $fecha): string => mb_strtolower($fecha)],
                ['/(?<=\d{4})[ \x{00A0}]+(?=celebrado)/u', ', '],
            ]);
        }

        if (str_starts_with($texto, 'EL RECEPTOR" en el ubicado en')) {
            return $this->corregir($parrafo, [['/^(\s*)EL RECEPTOR"/u', '$1"EL RECEPTOR"']]);
        }

        if (! str_starts_with($texto, 'Ser una persona física')) {
            return 0;
        }

        return $this->corregir($parrafo, [
            ['/(?<=haber nacido); (?=en)/u', ' '],
            ['/(?<=\p{L}):(?=[^\s\x{00A0}_])/u', ': '],
            ['/[ \x{00A0}]+(?=[,;])/u', ''],
            ['/(?<=años) (?=edad)/u', ' de '],
        ]);
    }

    /**
     * Aplica correcciones [patrón, reemplazo] sobre el texto del párrafo,
     * una por pasada (cada reemplazo cambia los offsets), conservando el
     * formato de los runs. Un patrón con «$1» se expande con su grupo.
     *
     * @param  list<array{0: string, 1: string|Closure(string): string}>  $correcciones
     */
    private function corregir(ParrafoWord $parrafo, array $correcciones): int
    {
        $cambios = 0;

        for ($i = 0; $i < 30; $i++) {
            $texto = $parrafo->texto();
            $arreglo = null;

            foreach ($correcciones as [$patron, $reemplazo]) {
                preg_match_all($patron, $texto, $coincidencias, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

                foreach ($coincidencias as $coincidencia) {
                    [$encontrado, $byte] = $coincidencia[0];
                    $nuevo = $reemplazo instanceof Closure
                        ? (string) $reemplazo($encontrado)
                        : (string) preg_replace_callback('/\$(\d)/', fn (array $g): string => (string) ($coincidencia[(int) $g[1]][0] ?? ''), $reemplazo);

                    if ($encontrado !== $nuevo) {
                        $arreglo = [$byte, $encontrado, $nuevo];

                        break 2;
                    }
                }
            }

            if ($arreglo === null) {
                break;
            }

            [$byte, $encontrado, $nuevo] = $arreglo;
            $inicio = mb_strlen(substr($texto, 0, $byte));
            $parrafo->reemplazar($inicio, $inicio + mb_strlen($encontrado), $nuevo);
            $cambios++;
        }

        return $cambios;
    }

    /**
     * Firma de la empresa: línea negra + «REPRESENTANTE LEGAL» (el nombre se
     * escribe a pluma). Firma del trabajador, en la misma fila y a la misma
     * altura: línea negra + su nombre completo.
     *
     * @param  array<int, ParrafoWord>  $porNodo
     */
    private function bloqueDeFirmas(ParrafoWord $firma, array $porNodo, string $trabajador): void
    {
        $celda = $firma->celda();
        $fila = $firma->fila();

        if ($celda === null) {
            return;
        }

        $siguiente = $this->parrafoSiguiente($firma->nodo);
        $siguienteTexto = $siguiente !== null ? ($porNodo[spl_object_id($siguiente)] ?? null)?->texto() : null;

        if ($siguiente !== null && $siguienteTexto !== null && preg_match('/^\s*REPRESENTANTE\s+LEGAL\s*$/iu', $siguienteTexto) === 1) {
            $firma->nodo->parentNode?->removeChild($firma->nodo);
            $etiqueta = $siguiente;
        } else {
            $firma->fijarTodo(self::ETIQUETA_PATRON);
            $etiqueta = $firma->nodo;
        }

        $this->quitarBordes($celda);
        [$otra, $renglon] = $trabajador !== '' && $fila !== null ? $this->renglonDelTrabajador($fila, $celda, $porNodo, $trabajador) : [null, null];
        // Mismo largo de línea en ambos lados (el de la celda más angosta).
        $ancho = min($this->anchoCelda($celda), $otra !== null ? $this->anchoCelda($otra) : PHP_INT_MAX);

        $this->lineaDeFirma($etiqueta, $ancho);

        if ($otra !== null && $renglon !== null) {
            $this->quitarBordes($otra);
            $this->lineaDeFirma($renglon, $ancho);
            $this->igualarNegritas($etiqueta, $renglon);
        }

        if ($fila !== null) {
            $this->encabezadoConFirmas($fila);
        }
    }

    /**
     * «EL PATRÓN / EL TRABAJADOR» nunca se queda solo al final de una hoja
     * con las líneas en la siguiente: las filas de arriba se mantienen con
     * la fila de las firmas.
     */
    private function encabezadoConFirmas(DOMElement $fila): void
    {
        $documento = $fila->ownerDocument;
        $anterior = $fila->previousSibling;

        while ($documento !== null && $anterior !== null) {
            if ($anterior instanceof DOMElement && $anterior->localName === 'tr') {
                foreach ($anterior->getElementsByTagNameNS(DocumentoWord::NS, 'p') as $parrafo) {
                    $pPr = $this->hijo($parrafo, 'pPr');

                    if ($pPr === null) {
                        $pPr = $documento->createElementNS(DocumentoWord::NS, 'w:pPr');
                        $parrafo->insertBefore($pPr, $parrafo->firstChild);
                    }

                    $this->fijarHijo($pPr, $documento->createElementNS(DocumentoWord::NS, 'w:keepNext'));
                }
            }

            $anterior = $anterior->previousSibling;
        }
    }

    /**
     * Ambos renglones con el mismo peso: si «REPRESENTANTE LEGAL» va en
     * negritas, el nombre del trabajador también (y al revés).
     */
    private function igualarNegritas(DOMElement $etiqueta, DOMElement $renglon): void
    {
        $documento = $renglon->ownerDocument;
        $negritas = false;

        foreach ($etiqueta->getElementsByTagNameNS(DocumentoWord::NS, 'r') as $run) {
            $b = ($rPr = $this->hijo($run, 'rPr')) !== null ? $this->hijo($rPr, 'b') : null;
            $negritas = $b !== null && ! in_array($b->getAttributeNS(DocumentoWord::NS, 'val'), ['0', 'false'], true);

            break;
        }

        if ($documento === null) {
            return;
        }

        foreach (iterator_to_array($renglon->getElementsByTagNameNS(DocumentoWord::NS, 'r')) as $run) {
            $rPr = $this->hijo($run, 'rPr');

            if ($rPr === null) {
                $rPr = $documento->createElementNS(DocumentoWord::NS, 'w:rPr');
                $run->insertBefore($rPr, $run->firstChild);
            }

            $this->quitarHijo($rPr, 'b');

            if ($negritas) {
                $b = $documento->createElementNS(DocumentoWord::NS, 'w:b');
                $antesDe = null;

                foreach ($rPr->childNodes as $hijo) {
                    if ($hijo instanceof DOMElement && ! in_array($hijo->localName, ['rStyle', 'rFonts'], true)) {
                        $antesDe = $hijo;

                        break;
                    }
                }

                $rPr->insertBefore($b, $antesDe);
            }
        }
    }

    /**
     * Renglón del trabajador en la misma fila de la firma de la empresa: el
     * párrafo con su nombre o, si el formato solo trae la línea «____»,
     * esa línea. Queda con su nombre completo.
     *
     * @param  array<int, ParrafoWord>  $porNodo
     * @return array{0: DOMElement|null, 1: DOMElement|null}
     */
    private function renglonDelTrabajador(DOMElement $fila, DOMElement $celda, array $porNodo, string $trabajador): array
    {
        foreach ($this->celdasHermanas($fila, $celda) as $otra) {
            $parrafos = array_values(array_filter(
                array_map(fn (DOMElement $p): ?ParrafoWord => $porNodo[spl_object_id($p)] ?? null, $this->parrafosDe($otra)),
                fn (?ParrafoWord $p): bool => $p !== null,
            ));
            $renglon = null;

            foreach ($parrafos as $parrafo) {
                if (str_contains(mb_strtoupper($parrafo->texto()), mb_strtoupper($trabajador))) {
                    $renglon = $parrafo;

                    break;
                }
            }

            $lineas = array_values(array_filter($parrafos, fn (ParrafoWord $p): bool => preg_match('/^[\s_]*_{5,}[\s_]*$/u', $p->texto()) === 1));
            $renglon ??= $lineas[0] ?? null;

            if ($renglon === null) {
                continue;
            }

            // La línea vieja de «____» se vacía: la nueva va justo arriba del nombre.
            foreach ($lineas as $linea) {
                if ($linea !== $renglon) {
                    $linea->fijarTodo('');
                }
            }

            $renglon->fijarTodo(mb_strtoupper($trabajador));

            return [$otra, $renglon->nodo];
        }

        return [null, null];
    }

    /**
     * Ancho de la celda en twips (w:tcW); sin dato, el de una columna de
     * firmas típica.
     */
    private function anchoCelda(DOMElement $celda): int
    {
        $tcPr = $this->hijo($celda, 'tcPr');
        $tcW = $tcPr !== null ? $this->hijo($tcPr, 'tcW') : null;
        $ancho = $tcW !== null && $tcW->getAttributeNS(DocumentoWord::NS, 'type') === 'dxa' ? (int) $tcW->getAttributeNS(DocumentoWord::NS, 'w') : 0;

        return $ancho > 0 ? $ancho : 3400;
    }

    private function parrafoSiguiente(DOMElement $nodo): ?DOMElement
    {
        $siguiente = $nodo->nextSibling;

        while ($siguiente !== null && ! ($siguiente instanceof DOMElement && $siguiente->localName === 'p')) {
            $siguiente = $siguiente->nextSibling;
        }

        return $siguiente instanceof DOMElement ? $siguiente : null;
    }

    /**
     * @return list<DOMElement>
     */
    private function celdasHermanas(DOMElement $fila, DOMElement $celda): array
    {
        $celdas = [];

        foreach ($fila->childNodes as $hijo) {
            if ($hijo instanceof DOMElement && $hijo->localName === 'tc' && $hijo !== $celda) {
                $celdas[] = $hijo;
            }
        }

        return $celdas;
    }

    /**
     * @return list<DOMElement>
     */
    private function parrafosDe(DOMElement $celda): array
    {
        $parrafos = [];

        foreach ($celda->childNodes as $hijo) {
            if ($hijo instanceof DOMElement && $hijo->localName === 'p') {
                $parrafos[] = $hijo;
            }
        }

        return $parrafos;
    }

    /**
     * Los formatos de confidencialidad / no competencia dibujaban la línea
     * con bordes inferiores sobre párrafos vacíos (no siempre se ven y
     * nunca quedaban parejos): se quitan y la línea es la del renglón.
     */
    private function quitarBordes(DOMElement $celda): void
    {
        foreach ($this->parrafosDe($celda) as $parrafo) {
            $pPr = $this->hijo($parrafo, 'pPr');

            if ($pPr !== null) {
                $this->quitarHijo($pPr, 'pBdr');
            }
        }
    }

    /**
     * Línea de firma negra («____», que todo conversor dibuja igual) justo
     * ARRIBA del renglón, centrada y del mismo largo en ambos lados; el
     * renglón (cargo o nombre) queda centrado, sin sangría ni subrayado. Se
     * reutiliza el párrafo vacío de arriba (no crece la hoja); si no hay,
     * se inserta uno.
     */
    private function lineaDeFirma(DOMElement $renglon, int $anchoTwips): void
    {
        $documento = $renglon->ownerDocument;

        if ($documento === null) {
            return;
        }

        $rPrBase = null;

        foreach ($renglon->getElementsByTagNameNS(DocumentoWord::NS, 'r') as $run) {
            $rPrBase = $this->hijo($run, 'rPr');

            break;
        }

        $anterior = $renglon->previousSibling;

        while ($anterior !== null && ! ($anterior instanceof DOMElement && $anterior->localName === 'p')) {
            $anterior = $anterior->previousSibling;
        }

        if ($anterior instanceof DOMElement && trim($anterior->textContent) === '' && $anterior->getElementsByTagNameNS(DocumentoWord::NS, 'drawing')->length === 0) {
            $linea = $anterior;

            foreach (iterator_to_array($linea->childNodes) as $hijo) {
                if (! ($hijo instanceof DOMElement && $hijo->localName === 'pPr')) {
                    $linea->removeChild($hijo);
                }
            }
        } else {
            $linea = $documento->createElementNS(DocumentoWord::NS, 'w:p');
            $pPrRenglon = $this->hijo($renglon, 'pPr');

            if ($pPrRenglon !== null) {
                $linea->appendChild($pPrRenglon->cloneNode(true));
            }

            $renglon->parentNode?->insertBefore($linea, $renglon);
            // La hoja no crece: se retira un párrafo vacío del final de la
            // misma celda (si queda alguno), así la fila mide lo mismo.
            $this->quitarUltimoVacio($renglon->parentNode, [$linea, $renglon]);
        }

        $run = $documento->createElementNS(DocumentoWord::NS, 'w:r');
        $rPr = $rPrBase !== null ? $rPrBase->cloneNode(true) : null;

        if ($rPr instanceof DOMElement) {
            foreach (['b', 'bCs', 'u', 'i', 'iCs', 'color', 'highlight', 'shd'] as $quitar) {
                $this->quitarHijo($rPr, $quitar);
            }

            $run->appendChild($rPr);
        }

        $t = $documento->createElementNS(DocumentoWord::NS, 'w:t');
        $t->appendChild($documento->createTextNode(str_repeat('_', $this->guiones($anchoTwips, $rPrBase))));
        $run->appendChild($t);
        $linea->appendChild($run);

        foreach ([$linea, $renglon] as $parrafo) {
            $pPr = $this->hijo($parrafo, 'pPr');

            if ($pPr === null) {
                $pPr = $documento->createElementNS(DocumentoWord::NS, 'w:pPr');
                $parrafo->insertBefore($pPr, $parrafo->firstChild);
            }

            $this->quitarHijo($pPr, 'pBdr');
            $ind = $documento->createElementNS(DocumentoWord::NS, 'w:ind');

            foreach (['left' => '0', 'right' => '0', 'firstLine' => '0'] as $atributo => $valor) {
                $ind->setAttributeNS(DocumentoWord::NS, 'w:'.$atributo, $valor);
            }

            $this->fijarHijo($pPr, $ind);
            $jc = $documento->createElementNS(DocumentoWord::NS, 'w:jc');
            $jc->setAttributeNS(DocumentoWord::NS, 'w:val', 'center');
            $this->fijarHijo($pPr, $jc);
        }

        // El renglón (cargo o nombre) sin subrayado: la línea es la de arriba.
        foreach (iterator_to_array($renglon->getElementsByTagNameNS(DocumentoWord::NS, 'rPr')) as $rPrRenglon) {
            $this->quitarHijo($rPrRenglon, 'u');
        }
    }

    /**
     * @param  list<DOMElement>  $conservar
     */
    private function quitarUltimoVacio(?DOMNode $celda, array $conservar): void
    {
        if (! $celda instanceof DOMElement) {
            return;
        }

        $parrafos = $this->parrafosDe($celda);

        foreach (array_reverse($parrafos) as $parrafo) {
            if (in_array($parrafo, $conservar, true)) {
                return;
            }

            if (count($parrafos) > 2 && trim($parrafo->textContent) === '' && $parrafo->getElementsByTagNameNS(DocumentoWord::NS, 'drawing')->length === 0) {
                $celda->removeChild($parrafo);

                return;
            }
        }
    }

    /**
     * Guiones bajos que caben en el 80 % del ancho de la celda con el tamaño
     * de letra del renglón (un «_» mide ~½ em).
     */
    private function guiones(int $anchoTwips, ?DOMElement $rPr): int
    {
        $sz = $rPr !== null ? $this->hijo($rPr, 'sz') : null;
        $puntos = $sz !== null ? max(6, (int) $sz->getAttributeNS(DocumentoWord::NS, 'val') / 2) : 11;

        return max(15, min(40, (int) floor(($anchoTwips / 20) * 0.8 / ($puntos * 0.5))));
    }

    /**
     * Sustituye (o inserta en su lugar del esquema) un hijo de <w:pPr>.
     */
    private function fijarHijo(DOMElement $pPr, DOMElement $nuevo): void
    {
        $nombre = (string) $nuevo->localName;
        $existente = $this->hijo($pPr, $nombre);

        if ($existente !== null) {
            $pPr->replaceChild($nuevo, $existente);

            return;
        }

        $posicion = array_search($nombre, self::ORDEN_PPR, true);
        $antesDe = null;

        foreach ($pPr->childNodes as $hijo) {
            if ($hijo instanceof DOMElement && array_search($hijo->localName, self::ORDEN_PPR, true) > $posicion) {
                $antesDe = $hijo;

                break;
            }
        }

        $pPr->insertBefore($nuevo, $antesDe);
    }

    private function hijo(DOMNode $padre, string $nombre): ?DOMElement
    {
        foreach ($padre->childNodes as $hijo) {
            if ($hijo instanceof DOMElement && $hijo->localName === $nombre) {
                return $hijo;
            }
        }

        return null;
    }

    private function quitarHijo(DOMNode $padre, string $nombre): void
    {
        $hijo = $this->hijo($padre, $nombre);

        if ($hijo !== null) {
            $padre->removeChild($hijo);
        }
    }
}
