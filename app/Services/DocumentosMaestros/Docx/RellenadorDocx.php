<?php

namespace App\Services\DocumentosMaestros\Docx;

/**
 * Llena un MASTER técnico con los datos del colaborador/proceso. Es
 * determinista: no busca nada en el texto, solo sustituye los marcadores
 * internos {{campo@n}} que el preparador dejó, cada uno completo dentro de
 * un solo <w:t> (por eso basta una sustitución sobre el XML de cada parte).
 *
 * Valor vacío de un campo opcional → se restaura el texto original de esa
 * instancia (el blanco "_______" del formato), nunca se deja un hueco
 * raro. Un salto de línea en el valor se convierte en <w:br/>.
 */
class RellenadorDocx
{
    /**
     * @param  array<int|string, array{campo: string, original: string, opcional?: bool}>  $instancias
     * @param  array<string, string>  $valores
     * @return array{docx: string, sin_valor: list<string>}
     */
    public function rellenar(string $master, array $instancias, array $valores): array
    {
        $documento = DocumentoWord::desdeBytes($master);
        $sinValor = [];
        $reemplazos = [];

        foreach ($documento->xmlPartes() as $parte => $xml) {
            $reemplazos[$parte] = (string) preg_replace_callback('/\{\{([a-z0-9_]+)@(\d+)\}\}/', function (array $m) use ($instancias, $valores, &$sinValor): string {
                $campo = $m[1];
                $instancia = $instancias[(int) $m[2]] ?? $instancias[$m[2]] ?? null;
                $valor = $valores[$campo] ?? '';

                if (trim($valor) === '') {
                    if ($instancia !== null && ($instancia['opcional'] ?? false)) {
                        return $this->xml($instancia['original']);
                    }

                    $sinValor[] = $campo;
                }

                return $this->xml($valor);
            }, $xml);
        }

        return ['docx' => $documento->guardar($reemplazos), 'sin_valor' => array_values(array_unique($sinValor))];
    }

    private function xml(string $valor): string
    {
        $escapado = htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return str_replace(["\r\n", "\n"], '</w:t><w:br/><w:t xml:space="preserve">', $escapado);
    }
}
