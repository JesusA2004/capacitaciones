<?php

namespace App\Services\DocumentosMaestros\Pdf;

use RuntimeException;

/**
 * Prepara el MASTER técnico de un PDF original: reescribe el archivo con
 * tabla de referencias clásica (PDF 1.4) cuando el original usa
 * "cross-reference streams" y "object streams" (PDF 1.5+), que el parser
 * libre de FPDI no puede leer.
 *
 * NO cambia el contenido: cada objeto (páginas, fuentes, imágenes, flujos
 * de contenido) se copia byte por byte; solo se desempacan los objetos que
 * venían comprimidos dentro de object streams y se escribe una tabla xref
 * normal. Visualmente el master es idéntico al original, que además se
 * conserva intacto aparte.
 */
class NormalizadorPdf
{
    public function necesitaNormalizar(string $pdf): bool
    {
        $inicio = $this->startxref($pdf);

        return $inicio !== null && ! str_starts_with(ltrim(substr($pdf, $inicio, 20)), 'xref');
    }

    public function normalizar(string $pdf): string
    {
        if (! $this->necesitaNormalizar($pdf)) {
            return $pdf;
        }

        if (preg_match('/\/Encrypt\s/', $pdf) === 1) {
            throw new RuntimeException('El PDF está cifrado; Jurídico debe entregar una copia sin protección.');
        }

        $entradas = [];
        $trailer = null;
        $posicion = $this->startxref($pdf);
        $visitados = [];

        // Cadena de actualizaciones incrementales: la más reciente gana.
        while ($posicion !== null && ! isset($visitados[$posicion])) {
            $visitados[$posicion] = true;
            [$diccionario, $datos] = $this->objetoStream($pdf, $posicion);
            $trailer ??= $diccionario;

            foreach ($this->entradasXref($diccionario, $datos) as $numero => $entrada) {
                $entradas[$numero] ??= $entrada;
            }

            $posicion = preg_match('/\/Prev\s+(\d+)/', $diccionario, $m) === 1 ? (int) $m[1] : null;
        }

        if ($trailer === null) {
            throw new RuntimeException('No se encontró la tabla de referencias del PDF.');
        }

        ksort($entradas);
        $objetos = [];
        $streamsObjetos = [];

        foreach ($entradas as $numero => $entrada) {
            if ($entrada['tipo'] === 1) {
                $cuerpo = $this->cuerpoObjeto($pdf, $entrada['offset']);

                if (preg_match('/\/Type\s*\/(XRef|ObjStm)\b/', substr($cuerpo, 0, 400)) === 1) {
                    continue;
                }

                $objetos[$numero] = [$entrada['generacion'], $cuerpo];
            } elseif ($entrada['tipo'] === 2) {
                $contenedor = $entrada['contenedor'];
                $streamsObjetos[$contenedor] ??= $this->desempacarObjStm($pdf, $entradas[$contenedor]['offset'] ?? null);
                $objetos[$numero] = [0, $streamsObjetos[$contenedor][$numero] ?? 'null'];
            }
        }

        $salida = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objetos as $numero => [$generacion, $cuerpo]) {
            $offsets[$numero] = [strlen($salida), $generacion];
            $salida .= sprintf("%d %d obj\n%s\nendobj\n", $numero, $generacion, trim($cuerpo, "\r\n"));
        }

        $tamano = max(array_keys($objetos) ?: [0]) + 1;
        $xref = strlen($salida);
        $salida .= sprintf("xref\n0 %d\n0000000000 65535 f\r\n", $tamano);

        for ($i = 1; $i < $tamano; $i++) {
            $salida .= isset($offsets[$i])
                ? sprintf("%010d %05d n\r\n", $offsets[$i][0], $offsets[$i][1])
                : "0000000000 00000 f\r\n";
        }

        $nuevoTrailer = sprintf('/Size %d', $tamano);

        foreach (['Root', 'Info'] as $clave) {
            if (preg_match('/\/'.$clave.'\s+(\d+\s+\d+\s+R)/', $trailer, $m) === 1) {
                $nuevoTrailer .= sprintf(' /%s %s', $clave, $m[1]);
            }
        }

        if (preg_match('/\/ID\s*(\[[^\]]*\])/', $trailer, $m) === 1) {
            $nuevoTrailer .= ' /ID '.$m[1];
        }

        return $salida.sprintf("trailer\n<< %s >>\nstartxref\n%d\n%%%%EOF\n", $nuevoTrailer, $xref);
    }

    private function startxref(string $pdf): ?int
    {
        $cola = substr($pdf, -2048);

        return preg_match_all('/startxref\s+(\d+)/', $cola, $m) > 0 ? (int) end($m[1]) : null;
    }

    /**
     * Diccionario + datos decodificados del objeto stream en $offset.
     *
     * @return array{0: string, 1: string}
     */
    private function objetoStream(string $pdf, int $offset): array
    {
        $cuerpo = $this->cuerpoObjeto($pdf, $offset);
        $inicioStream = strpos($cuerpo, 'stream');

        if ($inicioStream === false) {
            throw new RuntimeException('Referencia cruzada sin stream en el offset '.$offset.'.');
        }

        $diccionario = substr($cuerpo, 0, $inicioStream);
        $datos = substr($cuerpo, $inicioStream + 6);
        $datos = preg_replace('/^\r?\n/', '', $datos) ?? $datos;
        $fin = strrpos($datos, 'endstream');
        $datos = $fin !== false ? substr($datos, 0, $fin) : $datos;

        $indirecta = preg_match('/\/Length\s+\d+\s+\d+\s+R/', $diccionario) === 1;

        if (! $indirecta && preg_match('/\/Length\s+(\d+)/', $diccionario, $m) === 1 && (int) $m[1] <= strlen($datos)) {
            $datos = substr($datos, 0, (int) $m[1]);
        }

        return [$diccionario, $this->decodificar($diccionario, $datos)];
    }

    /**
     * @return array<int, array{tipo: int, offset: int, generacion: int, contenedor: int}>
     */
    private function entradasXref(string $diccionario, string $datos): array
    {
        if (preg_match('/\/W\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s*\]/', $diccionario, $w) !== 1) {
            throw new RuntimeException('Tabla de referencias sin /W.');
        }

        $anchos = [(int) $w[1], (int) $w[2], (int) $w[3]];
        $tamanoFila = array_sum($anchos);
        $tamano = preg_match('/\/Size\s+(\d+)/', $diccionario, $s) === 1 ? (int) $s[1] : 0;
        $indices = preg_match('/\/Index\s*\[([\d\s]+)\]/', $diccionario, $i) === 1
            ? array_map('intval', preg_split('/\s+/', trim($i[1])) ?: [])
            : [0, $tamano];
        $entradas = [];
        $cursor = 0;

        for ($k = 0; $k + 1 < count($indices); $k += 2) {
            for ($n = 0; $n < $indices[$k + 1]; $n++) {
                if ($cursor + $tamanoFila > strlen($datos)) {
                    break 2;
                }

                $campos = [];
                $p = $cursor;

                foreach ($anchos as $ancho) {
                    $valor = 0;

                    for ($b = 0; $b < $ancho; $b++) {
                        $valor = ($valor << 8) | ord($datos[$p++]);
                    }

                    $campos[] = $valor;
                }

                $cursor += $tamanoFila;
                $tipo = $anchos[0] === 0 ? 1 : $campos[0];
                $entradas[$indices[$k] + $n] = [
                    'tipo' => $tipo,
                    'offset' => $tipo === 1 ? $campos[1] : 0,
                    'generacion' => $tipo === 1 ? $campos[2] : 0,
                    'contenedor' => $tipo === 2 ? $campos[1] : 0,
                ];
            }
        }

        return $entradas;
    }

    /**
     * @return array<int, string> número de objeto → cuerpo
     */
    private function desempacarObjStm(string $pdf, ?int $offset): array
    {
        if ($offset === null) {
            return [];
        }

        [$diccionario, $datos] = $this->objetoStream($pdf, $offset);
        $n = preg_match('/\/N\s+(\d+)/', $diccionario, $m) === 1 ? (int) $m[1] : 0;
        $primero = preg_match('/\/First\s+(\d+)/', $diccionario, $m) === 1 ? (int) $m[1] : 0;
        $encabezado = array_map('intval', preg_split('/\s+/', trim(substr($datos, 0, $primero))) ?: []);
        $objetos = [];

        for ($i = 0; $i < $n; $i++) {
            $numero = $encabezado[$i * 2] ?? null;
            $inicio = $encabezado[$i * 2 + 1] ?? null;

            if ($numero === null || $inicio === null) {
                break;
            }

            $siguiente = $encabezado[$i * 2 + 3] ?? null;
            $objetos[$numero] = $siguiente !== null
                ? substr($datos, $primero + $inicio, $siguiente - $inicio)
                : substr($datos, $primero + $inicio);
        }

        return $objetos;
    }

    /**
     * Bytes entre "n g obj" y "endobj" (incluye streams completos).
     */
    private function cuerpoObjeto(string $pdf, int $offset): string
    {
        if (preg_match('/\G\s*\d+\s+\d+\s+obj/', $pdf, $m, 0, $offset) !== 1) {
            throw new RuntimeException('Objeto PDF inválido en el offset '.$offset.'.');
        }

        $inicio = $offset + strlen($m[0]);
        $stream = strpos($pdf, 'stream', $inicio);
        $endobj = strpos($pdf, 'endobj', $inicio);

        if ($endobj === false) {
            throw new RuntimeException('Objeto PDF sin endobj en el offset '.$offset.'.');
        }

        if ($stream !== false && $stream < $endobj) {
            $finStream = strpos($pdf, 'endstream', $stream);

            if ($finStream !== false) {
                $endobj = strpos($pdf, 'endobj', $finStream) ?: $endobj;
            }
        }

        return substr($pdf, $inicio, $endobj - $inicio);
    }

    private function decodificar(string $diccionario, string $datos): string
    {
        if (str_contains($diccionario, '/FlateDecode')) {
            $plano = @gzuncompress($datos);

            if ($plano === false) {
                $plano = @gzinflate(substr($datos, 2));
            }

            if ($plano === false) {
                throw new RuntimeException('No se pudo descomprimir un flujo del PDF.');
            }

            $datos = $plano;
        }

        if (preg_match('/\/Predictor\s+(\d+)/', $diccionario, $p) === 1 && (int) $p[1] >= 10) {
            $columnas = preg_match('/\/Columns\s+(\d+)/', $diccionario, $c) === 1 ? (int) $c[1] : 1;
            $datos = $this->despredecirPng($datos, $columnas);
        }

        return $datos;
    }

    private function despredecirPng(string $datos, int $columnas): string
    {
        $fila = $columnas + 1;
        $previa = array_fill(0, $columnas, 0);
        $salida = '';

        for ($o = 0; $o + $fila <= strlen($datos); $o += $fila) {
            $filtro = ord($datos[$o]);
            $actual = [];

            for ($i = 0; $i < $columnas; $i++) {
                $x = ord($datos[$o + 1 + $i]);
                $a = $i > 0 ? $actual[$i - 1] : 0;
                $b = $previa[$i];
                $c = $i > 0 ? $previa[$i - 1] : 0;
                $actual[$i] = match ($filtro) {
                    1 => ($x + $a) & 0xFF,
                    2 => ($x + $b) & 0xFF,
                    3 => ($x + intdiv($a + $b, 2)) & 0xFF,
                    4 => ($x + $this->paeth($a, $b, $c)) & 0xFF,
                    default => $x,
                };
            }

            $salida .= implode('', array_map('chr', $actual));
            $previa = $actual;
        }

        return $salida;
    }

    private function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }
}
