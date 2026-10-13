<?php

namespace App\Services\DocumentosMaestros\Deteccion;

use App\Enums\ZonaCampoDocumento;
use App\Services\DocumentosMaestros\Docx\DetectorCamposDocx;
use App\Services\DocumentosMaestros\Docx\DocumentoWord;
use App\Services\DocumentosMaestros\Docx\ParrafoWord;
use App\Services\DocumentosMaestros\Docx\PreparadorMasterDocx;

/**
 * Detecta en un DOCX los espacios destinados a datos que AÚN no tienen
 * espacio en el mapa: leyendo directamente document.xml, encabezados,
 * pies y tablas (nunca OCR).
 *
 *  - blancos «______» (líneas donde alguien escribiría a mano);
 *  - celdas vacías junto a una celda etiqueta («CURP: | ___»);
 *  - fechas concretas (un «16 de septiembre de 2026» de ejemplo);
 *  - casillas ☐ / ☒.
 *
 * Las líneas de firma/huella se reconocen (mismo criterio que el detector
 * de pendientes) y no se proponen como dato.
 *
 * Cada candidato trae su ANCLA en el mismo formato que las reglas del
 * preparador (blanco/texto/celda + contexto), calculada sobre el documento
 * YA preparado con el mapa existente: como los espacios nuevos se agregan
 * al final del mapa, el preparador los aplica exactamente sobre ese mismo
 * texto. Cada ancla se verifica (debe caer en un solo lugar); la que no se
 * puede fijar con precisión se reporta sin ancla — nunca se adivina.
 *
 * @phpstan-type Candidato array{selector: array<string, mixed>|null, contexto: string, original: string, type: 'texto'|'casilla', zona: ZonaCampoDocumento, sugerencias: list<array{clave: string, etiqueta: string, confianza: float}>, detection_method: string, detection_confidence: float, ubicacion: array{referencia: string, contexto: string}|null}
 */
class CandidatosDocxService
{
    private const BLANCO = '/_{4,}(?:[ \x{00A0}]?_+)*/u';

    private const MESES = 'enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre';

    public function __construct(
        private readonly PreparadorMasterDocx $preparador,
        private readonly SugeridorVariables $sugeridor,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $reglas  Mapa existente (en orden), ya con su `campo`.
     * @param  list<string>  $fechasFijas
     * @return list<Candidato>
     */
    public function detectar(string $original, array $reglas = [], array $fechasFijas = []): array
    {
        $vista = $this->preparador->preparar($original, $reglas)['documento'];
        $parrafos = $vista->parrafos();
        $candidatos = [];

        foreach ($parrafos as $parrafo) {
            $texto = $parrafo->texto();

            foreach ($this->blancos($texto) as [$inicio, $fin]) {
                $izquierda = mb_substr($texto, 0, $inicio);
                $blanco = mb_substr($texto, $inicio, $fin - $inicio);

                if (DetectorCamposDocx::esLineaDeFirma($this->sinMarcadores($texto), $this->sinMarcadores(mb_substr($izquierda, -60)))) {
                    continue;
                }

                $contexto = $this->contextoLegible($izquierda, mb_substr($texto, $fin));
                $candidatos[] = $this->candidato(
                    $this->selectorBlanco($parrafos, $parrafo, $texto, $inicio, $fin),
                    $contexto,
                    $blanco,
                    'texto',
                    'docx_blanco',
                    $parrafo,
                    $izquierda,
                );
            }

            // Líneas de espacios/tabuladores SUBRAYADOS (sin «_»).
            foreach ($parrafo->blancosSubrayados() as [$inicio, $fin]) {
                $blanco = mb_substr($texto, $inicio, $fin - $inicio);
                $izquierda = mb_substr($texto, 0, $inicio);

                if (str_contains($blanco, '____') || DetectorCamposDocx::esLineaDeFirma($this->sinMarcadores($texto), $this->sinMarcadores(mb_substr($izquierda, -60)))) {
                    continue;
                }

                $candidatos[] = $this->candidato(
                    $this->selectorEntre($parrafos, $parrafo, $texto, $inicio, $fin),
                    $this->contextoLegible($izquierda, mb_substr($texto, $fin)),
                    $blanco,
                    'texto',
                    'docx_subrayado',
                    $parrafo,
                    $izquierda,
                );
            }

            foreach ($this->fechas($texto, $fechasFijas) as [$inicio, $fecha]) {
                $candidatos[] = $this->candidato(
                    $this->selectorTexto($parrafos, $parrafo, $texto, $fecha, $inicio),
                    $this->contextoLegible(mb_substr($texto, 0, $inicio), mb_substr($texto, $inicio + mb_strlen($fecha))),
                    $fecha,
                    'texto',
                    'docx_fecha',
                    $parrafo,
                    mb_substr($texto, 0, $inicio).' fecha',
                );
            }

            foreach ($this->posiciones($texto, ['☐', '☒']) as [$inicio, $casilla]) {
                $derecha = mb_substr($texto, $inicio + 1, 60);
                $candidatos[] = $this->candidato(
                    $this->selectorTexto($parrafos, $parrafo, $texto, $casilla, $inicio),
                    trim($this->sinMarcadores($derecha)),
                    $casilla,
                    'casilla',
                    'docx_casilla',
                    $parrafo,
                    $derecha,
                );
            }
        }

        foreach ($this->celdasVacias($parrafos) as [$etiqueta, $ocurrencia]) {
            $candidatos[] = [
                'selector' => array_filter(['tipo' => 'celda', 'etiqueta' => $etiqueta, 'ocurrencia' => $ocurrencia > 1 ? $ocurrencia : null], fn (mixed $v): bool => $v !== null),
                'contexto' => $etiqueta,
                'original' => '',
                'type' => 'texto',
                'zona' => ZonaCampoDocumento::Dato,
                'sugerencias' => $this->sugeridor->sugerir($etiqueta),
                'detection_method' => 'docx_celda',
                'detection_confidence' => 0.8,
                'ubicacion' => null,
            ];
        }

        return $this->verificar($original, $reglas, $candidatos);
    }

    /**
     * @param  array<string, mixed>|null  $selector
     * @return Candidato
     */
    private function candidato(?array $selector, string $contexto, string $original, string $tipo, string $metodo, ParrafoWord $parrafo, string $paraSugerir): array
    {
        $zona = $tipo === 'texto' ? ($this->sugeridor->zona($paraSugerir) ?? ZonaCampoDocumento::Dato) : ZonaCampoDocumento::Dato;
        $sugerencias = $zona === ZonaCampoDocumento::Dato ? $this->sugeridor->sugerir($paraSugerir) : [];

        if ($metodo === 'docx_fecha' && $sugerencias === []) {
            $sugerencias = [
                ['clave' => 'fecha_documento_larga', 'etiqueta' => 'Fecha del documento', 'confianza' => 0.5],
                ['clave' => 'fecha_inicio_contrato_larga', 'etiqueta' => 'Fecha de inicio del contrato', 'confianza' => 0.4],
            ];
        }

        return [
            'selector' => $selector,
            'contexto' => $contexto,
            'original' => $original,
            'type' => $tipo === 'casilla' ? 'casilla' : 'texto',
            'zona' => $zona,
            'sugerencias' => $sugerencias,
            'detection_method' => $metodo,
            'detection_confidence' => $selector === null ? 0.3 : ($sugerencias[0]['confianza'] ?? 0.5),
            'ubicacion' => ['referencia' => $parrafo->referencia(), 'contexto' => $contexto],
        ];
    }

    /**
     * Ancla de un blanco: el texto fijo inmediatamente antes (o después) y,
     * si se repite, el párrafo o la ocurrencia que lo hace único.
     *
     * @param  list<ParrafoWord>  $parrafos
     * @return array<string, mixed>|null
     */
    private function selectorBlanco(array $parrafos, ParrafoWord $parrafo, string $texto, int $inicio, int $fin): ?array
    {
        $antes = $this->anclaAntes(mb_substr($texto, 0, $inicio));

        if ($antes !== null) {
            $selector = ['tipo' => 'blanco', 'antes' => $antes];
            $propios = $this->blancosTras($texto, $antes);
            $indice = array_search($inicio, $propios, true);

            if ($indice === false) {
                return null;
            }

            if ($indice > 0) {
                $selector['en_parrafo'] = $indice + 1;
            }

            return $this->desambiguar($parrafos, $parrafo, $selector, fn (string $t): bool => $this->blancosTras($t, $antes) !== []);
        }

        $despues = $this->anclaDespues(mb_substr($texto, $fin));

        if ($despues !== null && mb_strpos($texto, $despues) === $fin + (mb_strlen(mb_substr($texto, $fin)) - mb_strlen(ltrim(mb_substr($texto, $fin), " \u{00A0}")))) {
            return $this->desambiguar($parrafos, $parrafo, ['tipo' => 'blanco', 'despues' => $despues], fn (string $t): bool => str_contains($t, $despues));
        }

        return null;
    }

    /**
     * Ancla de un blanco subrayado: todo lo que hay ENTRE el texto fijo de
     * antes y el de después (el preparador sustituye el tramo completo).
     *
     * @param  list<ParrafoWord>  $parrafos
     * @return array<string, mixed>|null
     */
    private function selectorEntre(array $parrafos, ParrafoWord $parrafo, string $texto, int $inicio, int $fin): ?array
    {
        $desde = $this->anclaAntes(mb_substr($texto, 0, $inicio));
        $hasta = $this->anclaDespues(mb_substr($texto, $fin));
        $desde = $desde !== null ? ltrim($desde, " \u{00A0}\t") : null;
        $hasta = $hasta !== null ? rtrim($hasta, " \u{00A0}\t") : null;

        if ($desde === null || $hasta === null || mb_strlen($desde) < 3 || mb_strlen($hasta) < 1) {
            return null;
        }

        return $this->desambiguar($parrafos, $parrafo, ['tipo' => 'entre', 'desde' => $desde, 'hasta' => $hasta], fn (string $t): bool => str_contains($t, $desde) && str_contains($t, $hasta));
    }

    /**
     * @param  list<ParrafoWord>  $parrafos
     * @return array<string, mixed>|null
     */
    private function selectorTexto(array $parrafos, ParrafoWord $parrafo, string $texto, string $buscar, int $inicio): ?array
    {
        $propias = array_column($this->posiciones($texto, [$buscar]), 0);
        $indice = array_search($inicio, $propias, true);

        if ($indice === false) {
            return null;
        }

        $selector = ['tipo' => 'texto', 'buscar' => $buscar];

        if ($indice > 0) {
            $selector['en_parrafo'] = $indice + 1;
        }

        return $this->desambiguar($parrafos, $parrafo, $selector, fn (string $t): bool => str_contains($t, $buscar));
    }

    /**
     * Si el ancla aparece en más de un párrafo, se fija con un texto propio
     * del párrafo (`parrafo`) o, si no hay, con su `ocurrencia`.
     *
     * @param  list<ParrafoWord>  $parrafos
     * @param  array<string, mixed>  $selector
     * @param  callable(string): bool  $aplica
     * @return array<string, mixed>
     */
    private function desambiguar(array $parrafos, ParrafoWord $parrafo, array $selector, callable $aplica): array
    {
        $coinciden = array_values(array_filter($parrafos, fn (ParrafoWord $p): bool => $aplica($p->texto())));

        if (count($coinciden) <= 1) {
            return $selector;
        }

        $propio = $this->textoPropio($parrafo->texto());

        if ($propio !== null) {
            $conPropio = array_filter($coinciden, fn (ParrafoWord $p): bool => str_contains($p->texto(), $propio));

            if (count($conPropio) === 1) {
                return [...$selector, 'parrafo' => $propio];
            }
        }

        $posicion = array_search($parrafo, $coinciden, true);

        return [...$selector, 'ocurrencia' => $posicion === false ? 1 : $posicion + 1];
    }

    /**
     * Comprueba cada ancla nueva con el preparador real: debe aplicarse una
     * sola vez. Las que no, quedan sin ancla (RH las ve en rojo).
     *
     * @param  list<array<string, mixed>>  $reglas
     * @param  list<Candidato>  $candidatos
     * @return list<Candidato>
     */
    private function verificar(string $original, array $reglas, array $candidatos): array
    {
        $prueba = $reglas;
        $indices = [];

        foreach ($candidatos as $i => $candidato) {
            if ($candidato['selector'] !== null) {
                $indices[count($prueba) + 1] = $i;
                $prueba[] = [...$candidato['selector'], 'campo' => sprintf('candidato_%d', $i)];
            }
        }

        if ($indices === []) {
            return $candidatos;
        }

        $resultado = $this->preparador->preparar($original, $prueba);
        $aplicaciones = [];

        foreach ($resultado['aplicadas'] as $aplicada) {
            $aplicaciones[(int) $aplicada['regla']] = (int) $aplicada['aplicaciones'];
        }

        // Ancla que no cae en un solo lugar: el espacio queda sin ancla.
        $imprecisos = [];

        foreach ($indices as $regla => $i) {
            if (($aplicaciones[$regla] ?? 0) !== 1) {
                $imprecisos[$i] = true;
            }
        }

        return array_map(
            fn (array $candidato, int $i): array => isset($imprecisos[$i]) ? [...$candidato, 'selector' => null, 'detection_confidence' => 0.2] : $candidato,
            $candidatos,
            array_keys($candidatos),
        );
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function blancos(string $texto): array
    {
        $rangos = [];

        if (preg_match_all(self::BLANCO, $texto, $coincidencias, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($coincidencias[0] as [$blanco, $offset]) {
                $inicio = mb_strlen(substr($texto, 0, (int) $offset));
                $rangos[] = [$inicio, $inicio + mb_strlen($blanco)];
            }
        }

        return $rangos;
    }

    /**
     * Inicio de cada blanco que sigue (a ≤3 espacios) a una aparición de $antes.
     *
     * @return list<int>
     */
    private function blancosTras(string $texto, string $antes): array
    {
        $inicios = [];

        foreach ($this->posiciones($texto, [$antes]) as [$posicion]) {
            $resto = mb_substr($texto, $posicion + mb_strlen($antes));

            if (preg_match('/^[ \x{00A0}]{0,3}_/u', $resto, $m) === 1) {
                $inicios[] = $posicion + mb_strlen($antes) + mb_strlen($m[0]) - 1;
            }
        }

        return $inicios;
    }

    /**
     * Texto fijo inmediatamente antes del blanco (sin marcadores ni espacios
     * finales), de 3 a 40 caracteres, cortado en palabra completa.
     */
    private function anclaAntes(string $izquierda): ?string
    {
        // El ancla nunca incluye otro espacio (marcador o «____»): esos
        // cambian cuando se mapean y el ancla dejaría de encontrarse.
        $tramo = (string) preg_replace('/^.*(\}\}|_)/su', '', $izquierda);
        $tramo = rtrim($tramo, " \u{00A0}");

        if (preg_match('/_$/u', $tramo) === 1 || mb_strlen(trim($tramo)) < 3) {
            return null;
        }

        if (mb_strlen($tramo) > 40) {
            $tramo = mb_substr($tramo, -40);
            $espacio = mb_strpos($tramo, ' ');
            $tramo = $espacio !== false && $espacio < 20 ? mb_substr($tramo, $espacio + 1) : $tramo;
        }

        return mb_strlen(trim($tramo)) >= 3 ? $tramo : null;
    }

    private function anclaDespues(string $derecha): ?string
    {
        $tramo = ltrim($derecha, " \u{00A0}");
        $tramo = (string) preg_replace('/(\{\{|_).*$/su', '', $tramo);

        $tramo = mb_substr($tramo, 0, 40);

        return mb_strlen(trim($tramo)) >= 3 ? $tramo : null;
    }

    /**
     * Un texto del párrafo sin marcadores (8–50 caracteres) para fijar el
     * ancla cuando el mismo contexto se repite en otros párrafos.
     */
    private function textoPropio(string $texto): ?string
    {
        $tramos = preg_split('/\{\{[a-z0-9_]+@\d+\}\}|_{4,}/u', $texto) ?: [];
        usort($tramos, fn (string $a, string $b): int => mb_strlen(trim($b)) <=> mb_strlen(trim($a)));
        $mejor = trim((string) ($tramos[0] ?? ''));

        return mb_strlen($mejor) >= 8 ? mb_substr($mejor, 0, 50) : null;
    }

    /**
     * @param  list<string>  $fijas
     * @return list<array{0: int, 1: string}>
     */
    private function fechas(string $texto, array $fijas): array
    {
        $encontradas = [];

        if (preg_match_all('/\b\d{1,2}\s+de\s+('.self::MESES.')\s+(de|del)\s+20\d{2}\b/iu', $texto, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[0] as [$fecha, $offset]) {
                if (! in_array($fecha, $fijas, true)) {
                    $encontradas[] = [mb_strlen(substr($texto, 0, (int) $offset)), $fecha];
                }
            }
        }

        return $encontradas;
    }

    /**
     * @param  list<string>  $buscados
     * @return list<array{0: int, 1: string}>
     */
    private function posiciones(string $texto, array $buscados): array
    {
        $resultado = [];

        foreach ($buscados as $buscado) {
            $desde = 0;

            while ($buscado !== '' && ($posicion = mb_strpos($texto, $buscado, $desde)) !== false) {
                $resultado[] = [$posicion, $buscado];
                $desde = $posicion + max(1, mb_strlen($buscado));
            }
        }

        usort($resultado, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $resultado;
    }

    /**
     * Celdas «Etiqueta:» cuya celda siguiente está vacía.
     *
     * @param  list<ParrafoWord>  $parrafos
     * @return list<array{0: string, 1: int}>
     */
    private function celdasVacias(array $parrafos): array
    {
        $vistas = [];
        $resultado = [];

        foreach ($parrafos as $parrafo) {
            $etiqueta = trim($parrafo->texto());
            $siguiente = $parrafo->celdaSiguiente();

            if ($siguiente === null || $etiqueta === '' || mb_strlen($etiqueta) > 60 || str_contains($etiqueta, '{{') || ! str_ends_with($etiqueta, ':')) {
                continue;
            }

            $vacia = true;

            foreach ($parrafos as $otro) {
                if ($otro->celda() === $siguiente && trim($otro->texto()) !== '') {
                    $vacia = false;
                    break;
                }
            }

            if ($vacia) {
                $clave = mb_strtolower($etiqueta);
                $vistas[$clave] = ($vistas[$clave] ?? 0) + 1;
                $resultado[] = [$etiqueta, $vistas[$clave]];
            }
        }

        return $resultado;
    }

    private function sinMarcadores(string $texto): string
    {
        return (string) preg_replace('/\{\{[a-z0-9_]+@\d+\}\}/', '', $texto);
    }

    private function contextoLegible(string $izquierda, string $derecha): string
    {
        $antes = trim($this->sinMarcadores(mb_substr($izquierda, -70)));
        $despues = trim($this->sinMarcadores(mb_substr($derecha, 0, 30)));

        return trim(sprintf('%s ____ %s', $antes, $despues));
    }

    /**
     * Para el editor: el texto de cada párrafo de un documento preparado,
     * partido en tramos de texto y espacios (marcadores {{campo@n}}).
     *
     * @param  array<string, string>  $campoAClave  campo del marcador → field_key
     * @param  array<int, array{campo: string, original: string}>  $instancias
     * @return list<array{referencia: string, en_tabla: bool, segmentos: list<array<string, string>>}>
     */
    public function parrafosVista(DocumentoWord $vista, array $campoAClave, array $instancias): array
    {
        $resultado = [];

        foreach ($vista->parrafos() as $parrafo) {
            $texto = $parrafo->texto();

            if (trim($texto) === '') {
                continue;
            }

            $segmentos = [];
            $partes = preg_split('/(\{\{[a-z0-9_]+@\d+\}\})/', $texto, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

            foreach ($partes as $parte) {
                if (preg_match('/^\{\{([a-z0-9_]+)@(\d+)\}\}$/', $parte, $m) === 1) {
                    $segmentos[] = isset($campoAClave[$m[1]])
                        ? ['tipo' => 'campo', 'field_key' => $campoAClave[$m[1]], 'original' => (string) ($instancias[(int) $m[2]]['original'] ?? '')]
                        : ['tipo' => 'texto', 'texto' => (string) ($instancias[(int) $m[2]]['original'] ?? '')];

                    continue;
                }

                // Blancos restantes = líneas de firma (los demás ya son espacios).
                foreach (preg_split('/(_{4,}(?:[ \x{00A0}]?_+)*)/u', $parte, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $tramo) {
                    $segmentos[] = preg_match('/^_{4,}/u', $tramo) === 1 ? ['tipo' => 'firma', 'original' => $tramo] : ['tipo' => 'texto', 'texto' => $tramo];
                }
            }

            $resultado[] = ['referencia' => $parrafo->referencia(), 'en_tabla' => $parrafo->enTabla(), 'segmentos' => $segmentos];
        }

        return $resultado;
    }
}
