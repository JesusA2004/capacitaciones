<?php

namespace App\Services\DocumentosMaestros\Docx;

/**
 * Convierte el ORIGINAL jurídico (DOCX tal como lo entregó Jurídico/RH) en
 * el MASTER técnico procesable, aplicando las reglas de mapeo versionadas
 * en config/documentos_maestros.php. El usuario nunca escribe marcadores:
 * esto lo hace el sistema, una sola vez por versión del original.
 *
 * Cada regla localiza un dato variable por su contexto (no por posición) y
 * lo sustituye por un marcador interno {{campo@n}}; el texto original que
 * ocupaba ese lugar (p. ej. "__________" o el nombre de ejemplo) se guarda
 * en la instancia n, para restaurarlo si el dato es opcional y viene vacío.
 *
 * Tipos de regla (todas aceptan `parrafo` = el párrafo debe contener ese
 * texto, `fila` = el párrafo debe estar en una fila de tabla que contenga
 * ese texto, `ocurrencia` = n-ésimo párrafo candidato o "todas", y
 * `opcional`):
 *  - blanco: el blanco (guiones bajos/tabuladores) inmediatamente después
 *    de `antes` o inmediatamente antes de `despues`;
 *  - texto:  el texto literal `buscar` (dato de ejemplo, fecha concreta,
 *    casilla ☐/☒…); `en_parrafo` elige la n-ésima aparición o "todas";
 *  - celda:  la celda que sigue a la celda cuyo texto es `etiqueta`.
 *
 * Una regla que no encuentra su contexto NO adivina: queda como pendiente
 * en el reporte y el master no puede activarse hasta resolverla.
 */
class PreparadorMasterDocx
{
    private const BLANCO = '[_\t]+(?:[ \x{00A0}]?[_\t]+)*';

    /**
     * @param  list<array<string, mixed>>  $reglas
     * @return array{master: string, instancias: array<int, array{campo: string, original: string, opcional: bool, prefijo?: string, sufijo?: string, piezas?: list<array{rpr: string, texto: string}>}>, aplicadas: list<array<string, mixed>>, pendientes: list<array<string, mixed>>, documento: DocumentoWord}
     */
    public function preparar(string $original, array $reglas): array
    {
        $documento = DocumentoWord::desdeBytes($original);
        $instancias = [];
        $aplicadas = [];
        $pendientes = [];
        $siguiente = 1;

        foreach ($reglas as $indice => $regla) {
            $campo = (string) ($regla['campo'] ?? '');
            $opcional = (bool) ($regla['opcional'] ?? false);
            // Solo cuentan los párrafos donde la regla SÍ encuentra su dato
            // (un "Nombre:" ya llenado por una regla anterior no compite).
            $candidatos = array_values(array_filter($this->candidatos($documento, $regla), fn (ParrafoWord $p): bool => $this->rangos($p, $regla) !== []));
            $ocurrencia = $regla['ocurrencia'] ?? 1;
            $aplicados = 0;

            if ($ocurrencia !== 'todas') {
                $candidatos = array_slice($candidatos, max(0, (int) $ocurrencia - 1), 1);
            }

            foreach ($candidatos as $parrafo) {
                foreach ($this->rangos($parrafo, $regla) as [$inicio, $fin, $prefijo, $sufijo]) {
                    $original = mb_substr($parrafo->texto(), $inicio, $fin - $inicio);
                    $piezas = $parrafo->piezas($inicio, $fin);
                    $n = $siguiente++;
                    $parrafo->reemplazar($inicio, $fin, sprintf('%s{{%s@%d}}%s', $prefijo, $campo, $n, $sufijo));
                    // prefijo/sufijo: espacios que el SISTEMA agregó para que el
                    // dato no quede pegado al texto fijo; piezas: formato por
                    // tramo si el blanco mezclaba formatos. Ambos permiten
                    // restaurar el original exacto (dato opcional vacío y QA).
                    $instancia = ['campo' => $campo, 'original' => $original, 'opcional' => $opcional];

                    if ($prefijo !== '') {
                        $instancia['prefijo'] = $prefijo;
                    }

                    if ($sufijo !== '') {
                        $instancia['sufijo'] = $sufijo;
                    }

                    if (count(array_unique(array_column($piezas, 'rpr'))) > 1) {
                        $instancia['piezas'] = $piezas;
                    }

                    $instancias[$n] = $instancia;
                    $aplicados++;
                }
            }

            if ($aplicados === 0 && ($regla['tipo'] ?? '') === 'celda') {
                foreach (array_slice($this->candidatosCelda($documento, $regla), 0, $ocurrencia === 'todas' ? null : 1) as [$etiqueta, $valor]) {
                    $n = $siguiente++;
                    $instancias[$n] = ['campo' => $campo, 'original' => $valor->texto(), 'opcional' => $opcional];
                    $valor->fijarTodo(sprintf('{{%s@%d}}', $campo, $n), $etiqueta->primerRun());
                    $aplicados++;
                }
            }

            $resumen = ['regla' => $indice + 1, 'tipo' => $regla['tipo'] ?? null, 'campo' => $campo, 'contexto' => $this->contexto($regla)];

            if ($aplicados > 0) {
                $aplicadas[] = [...$resumen, 'aplicaciones' => $aplicados];
            } else {
                $pendientes[] = [...$resumen, 'motivo' => 'No se encontró el contexto de la regla en el documento.'];
            }
        }

        return [
            'master' => $documento->guardar(),
            'instancias' => $instancias,
            'aplicadas' => $aplicadas,
            'pendientes' => $pendientes,
            'documento' => $documento,
        ];
    }

    /**
     * @param  array<string, mixed>  $regla
     * @return list<ParrafoWord>
     */
    private function candidatos(DocumentoWord $documento, array $regla): array
    {
        if (($regla['tipo'] ?? '') === 'celda') {
            return [];
        }

        $ancla = (string) ($regla['antes'] ?? $regla['despues'] ?? $regla['buscar'] ?? $regla['desde'] ?? '');

        return array_values(array_filter($documento->parrafos(), function (ParrafoWord $p) use ($regla, $ancla): bool {
            $texto = $p->texto();

            if ($ancla === '' || ! str_contains($texto, $ancla)) {
                return false;
            }

            if (isset($regla['parrafo']) && ! str_contains($texto, (string) $regla['parrafo'])) {
                return false;
            }

            if (isset($regla['fila'])) {
                $fila = $p->fila();

                return $fila !== null && str_contains($fila->textContent, (string) $regla['fila']);
            }

            return true;
        }));
    }

    /**
     * @param  array<string, mixed>  $regla
     * @return list<array{0: ParrafoWord, 1: ParrafoWord}>
     */
    private function candidatosCelda(DocumentoWord $documento, array $regla): array
    {
        $etiqueta = $this->normalizar((string) ($regla['etiqueta'] ?? ''));
        $parrafos = $documento->parrafos();
        $resultado = [];

        foreach ($parrafos as $parrafo) {
            if ($etiqueta === '' || $this->normalizar($parrafo->texto()) !== $etiqueta) {
                continue;
            }

            $celda = $parrafo->celdaSiguiente();

            if ($celda === null) {
                continue;
            }

            foreach ($parrafos as $valor) {
                if ($valor->celda() === $celda) {
                    $resultado[] = [$parrafo, $valor];
                    break;
                }
            }
        }

        return $resultado;
    }

    /**
     * Rangos [inicio, fin, prefijo, sufijo] (en caracteres) a sustituir
     * dentro del párrafo, de ÚLTIMO a primero para que los offsets de las
     * sustituciones pendientes sigan siendo válidos.
     *
     * @param  array<string, mixed>  $regla
     * @return list<array{0: int, 1: int, 2: string, 3: string}>
     */
    private function rangos(ParrafoWord $parrafo, array $regla): array
    {
        $texto = $parrafo->texto();
        $enParrafo = $regla['en_parrafo'] ?? 1;
        $rangos = [];

        switch ($regla['tipo'] ?? '') {
            case 'blanco':
                $ancla = (string) ($regla['antes'] ?? $regla['despues'] ?? '');
                $despues = isset($regla['despues']);

                foreach ($this->posiciones($texto, $ancla) as $posicion) {
                    $rango = $despues ? $this->blancoAntesDe($texto, $posicion) : $this->blancoDespuesDe($texto, $posicion + mb_strlen($ancla));

                    if ($rango !== null) {
                        $rangos[] = $rango;
                    }
                }
                break;
            case 'texto':
                $buscar = (string) ($regla['buscar'] ?? '');

                foreach ($this->posiciones($texto, $buscar) as $posicion) {
                    $rangos[] = [$posicion, $posicion + mb_strlen($buscar), '', ''];
                }
                break;
            case 'entre':
                // Blancos hechos de espacios subrayados o mezclas raras: se
                // sustituye todo lo que hay entre `desde` y `hasta`.
                $desde = (string) ($regla['desde'] ?? '');
                $hasta = (string) ($regla['hasta'] ?? '');

                foreach ($this->posiciones($texto, $desde) as $posicion) {
                    $inicio = $posicion + mb_strlen($desde);
                    $fin = $hasta === '' ? mb_strlen($texto) : mb_strpos($texto, $hasta, $inicio);

                    // Nunca se pisa un marcador ya colocado por otra regla.
                    if ($fin === false || $fin < $inicio || str_contains(mb_substr($texto, $inicio, $fin - $inicio), '{{')) {
                        continue;
                    }

                    $rangos[] = [$inicio, $fin, ' ', $hasta === '' || preg_match('/^[.,;:]/u', $hasta) === 1 ? '' : ' '];
                }
                break;
            default:
                return [];
        }

        if ($enParrafo !== 'todas') {
            $rangos = array_slice($rangos, max(0, (int) $enParrafo - 1), 1);
        }

        usort($rangos, fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return $rangos;
    }

    /**
     * @return list<int> posiciones (en caracteres) de cada aparición.
     */
    private function posiciones(string $texto, string $buscar): array
    {
        if ($buscar === '') {
            return [];
        }

        $posiciones = [];
        $desde = 0;

        while (($posicion = mb_strpos($texto, $buscar, $desde)) !== false) {
            $posiciones[] = $posicion;
            $desde = $posicion + max(1, mb_strlen($buscar));
        }

        return $posiciones;
    }

    /**
     * @return array{0: int, 1: int, 2: string, 3: string}|null
     */
    private function blancoDespuesDe(string $texto, int $desde): ?array
    {
        $resto = mb_substr($texto, $desde);

        if (preg_match('/^[ \x{00A0}]{0,3}/u', $resto, $espacios) !== 1) {
            return null;
        }

        $saltar = mb_strlen($espacios[0]);
        $cola = mb_substr($resto, $saltar);

        if (preg_match('/^'.self::BLANCO.'/u', $cola, $blanco) !== 1) {
            return null;
        }

        $inicio = $desde + $saltar;
        $fin = $inicio + mb_strlen($blanco[0]);

        return [$inicio, $fin, $this->separador($texto, $inicio - 1), $this->separador($texto, $fin)];
    }

    /**
     * @return array{0: int, 1: int, 2: string, 3: string}|null
     */
    private function blancoAntesDe(string $texto, int $hasta): ?array
    {
        $previo = mb_substr($texto, 0, $hasta);

        if (preg_match('/'.self::BLANCO.'[ \x{00A0}]{0,3}$/u', $previo, $coincidencia) !== 1) {
            return null;
        }

        $sinEspacios = rtrim($coincidencia[0], " \u{00A0}");
        $inicio = mb_strlen($previo) - mb_strlen($coincidencia[0]);
        $fin = $inicio + mb_strlen($sinEspacios);

        return [$inicio, $fin, $this->separador($texto, $inicio - 1), $this->separador($texto, $fin)];
    }

    /**
     * Un blanco pegado a una palabra ("ubicado en____") necesita un espacio
     * para que el dato no quede pegado al texto fijo.
     */
    private function separador(string $texto, int $posicion): string
    {
        if ($posicion < 0 || $posicion >= mb_strlen($texto)) {
            return '';
        }

        return preg_match('/[\p{L}\p{N}]/u', mb_substr($texto, $posicion, 1)) === 1 ? ' ' : '';
    }

    private function normalizar(string $texto): string
    {
        return mb_strtolower(trim((string) preg_replace('/[\s:]+/u', ' ', $texto)));
    }

    /**
     * @param  array<string, mixed>  $regla
     */
    private function contexto(array $regla): string
    {
        foreach (['antes', 'despues', 'buscar', 'desde', 'etiqueta'] as $clave) {
            if (isset($regla[$clave])) {
                return sprintf('%s «%s»', $clave, mb_substr((string) $regla[$clave], 0, 80));
            }
        }

        return '';
    }
}
