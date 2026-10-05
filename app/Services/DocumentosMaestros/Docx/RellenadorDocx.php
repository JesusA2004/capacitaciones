<?php

namespace App\Services\DocumentosMaestros\Docx;

/**
 * Llena un MASTER técnico con los datos del colaborador/proceso. Es
 * determinista: no busca nada en el texto, solo sustituye los marcadores
 * internos {{campo@n}} que el preparador dejó, cada uno completo dentro de
 * un solo <w:t> (por eso basta una sustitución sobre el XML de cada parte:
 * el dato hereda las propiedades del run original — fuente, tamaño,
 * negritas, subrayado — y nada más del documento cambia).
 *
 * Valor vacío de un campo opcional → se restaura el ORIGINAL exacto de esa
 * instancia (el blanco "_______" del formato, con su formato por tramo y sin
 * los espacios separadores que agregó el sistema), nunca un hueco raro.
 * Un salto de línea en el valor se convierte en <w:br/> y un tabulador en
 * <w:tab/> (un "\t" literal dentro de <w:t> Word lo pinta distinto).
 *
 * @phpstan-type Instancia array{campo: string, original: string, opcional?: bool, prefijo?: string, sufijo?: string, piezas?: list<array{rpr: string, texto: string}>}
 */
class RellenadorDocx
{
    /**
     * Instancias del mapping de un master, normalizadas (conserva prefijo,
     * sufijo y piezas para poder restaurar el original exacto).
     *
     * @param  array<string, mixed>|null  $mapping
     * @return array<int, Instancia>
     */
    public static function instanciasDe(?array $mapping): array
    {
        $instancias = [];

        foreach ((array) (($mapping ?? [])['instancias'] ?? []) as $n => $instancia) {
            if (! is_array($instancia)) {
                continue;
            }

            $normalizada = ['campo' => (string) ($instancia['campo'] ?? ''), 'original' => (string) ($instancia['original'] ?? ''), 'opcional' => (bool) ($instancia['opcional'] ?? false)];

            foreach (['prefijo', 'sufijo'] as $clave) {
                if (isset($instancia[$clave]) && is_string($instancia[$clave])) {
                    $normalizada[$clave] = $instancia[$clave];
                }
            }

            $piezas = [];

            foreach ((array) ($instancia['piezas'] ?? []) as $pieza) {
                if (is_array($pieza)) {
                    $piezas[] = ['rpr' => (string) ($pieza['rpr'] ?? ''), 'texto' => (string) ($pieza['texto'] ?? '')];
                }
            }

            if ($piezas !== []) {
                $normalizada['piezas'] = $piezas;
            }

            $instancias[(int) $n] = $normalizada;
        }

        return $instancias;
    }

    /**
     * @param  array<int|string, Instancia>  $instancias
     * @param  array<string, string>  $valores
     * @return array{docx: string, sin_valor: list<string>}
     */
    public function rellenar(string $master, array $instancias, array $valores): array
    {
        return $this->sustituir($master, $instancias, function (string $campo, ?array $instancia) use ($valores): ?array {
            $valor = $valores[$campo] ?? '';

            if (trim($valor) === '') {
                return $instancia !== null && ($instancia['opcional'] ?? false) ? ['original' => true] : null;
            }

            return ['valor' => $valor];
        });
    }

    /**
     * Restaura en cada marcador el ORIGINAL exacto (QA visual "identidad":
     * si el master está bien preparado, el resultado se ve idéntico al
     * documento de Jurídico).
     *
     * @param  array<int|string, Instancia>  $instancias
     */
    public function restaurarOriginal(string $master, array $instancias): string
    {
        return $this->sustituir($master, $instancias, fn (string $campo, ?array $instancia): array => ['original' => true])['docx'];
    }

    /**
     * @param  array<int|string, Instancia>  $instancias
     * @param  callable(string, Instancia|null): (array{valor?: string, original?: bool}|null)  $decidir
     * @return array{docx: string, sin_valor: list<string>}
     */
    private function sustituir(string $master, array $instancias, callable $decidir): array
    {
        $documento = DocumentoWord::desdeBytes($master);
        $sinValor = [];
        $reemplazos = [];

        foreach ($documento->xmlPartes() as $parte => $xml) {
            $reemplazos[$parte] = (string) preg_replace_callback('/( ?)\{\{([a-z0-9_]+)@(\d+)\}\}( ?)/', function (array $m) use ($instancias, $decidir, &$sinValor): string {
                [$todo, $antes, $campo, $numero, $despues] = $m;
                $instancia = $instancias[(int) $numero] ?? $instancias[$numero] ?? null;
                $decision = $decidir($campo, $instancia);

                if ($decision === null) {
                    $sinValor[] = $campo;

                    return $antes.$despues;
                }

                if (($decision['original'] ?? false) && $instancia !== null) {
                    // Sin los separadores que puso el sistema: el original exacto.
                    $antes = ($instancia['prefijo'] ?? '') === ' ' ? '' : $antes;
                    $despues = ($instancia['sufijo'] ?? '') === ' ' ? '' : $despues;

                    return $antes.$this->original($instancia).$despues;
                }

                return $antes.$this->xml((string) ($decision['valor'] ?? '')).$despues;
            }, $xml);
        }

        return ['docx' => $documento->guardar($reemplazos), 'sin_valor' => array_values(array_unique($sinValor))];
    }

    /**
     * @param  Instancia  $instancia
     */
    private function original(array $instancia): string
    {
        $piezas = $instancia['piezas'] ?? [];

        if (count($piezas) < 2) {
            return $this->xml($instancia['original']);
        }

        // Cerrar el run actual, un run por tramo con su formato original y
        // reabrir un run con el formato del primero (el que contenía el
        // marcador) para el texto que sigue.
        $xml = '</w:t></w:r>';

        foreach ($piezas as $pieza) {
            $xml .= '<w:r>'.$pieza['rpr'].'<w:t xml:space="preserve">'.$this->xml($pieza['texto']).'</w:t></w:r>';
        }

        return $xml.'<w:r>'.$piezas[0]['rpr'].'<w:t xml:space="preserve">';
    }

    private function xml(string $valor): string
    {
        $escapado = htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return str_replace(
            ["\r\n", "\n", "\t"],
            ['</w:t><w:br/><w:t xml:space="preserve">', '</w:t><w:br/><w:t xml:space="preserve">', '</w:t><w:tab/><w:t xml:space="preserve">'],
            $escapado,
        );
    }
}
