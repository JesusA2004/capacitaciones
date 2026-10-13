<?php

namespace App\Services\DocumentosMaestros\Variables;

use App\Enums\TransformacionTexto;
use App\Services\Formatos\Variables\NumeroALetras;

/**
 * Campos COMPUESTOS sin programar: un espacio del documento puede llevar
 * un dato con transformación («Nombre completo · MAYÚSCULAS») o una
 * expresión armada en el editor con piezas seguras:
 *
 *   texto fijo · dato del catálogo · separador · MAYÚSCULAS/minúsculas/
 *   Título · moneda · número con letra · «omitir si está vacío».
 *
 * NUNCA se evalúa código (PHP/JS/Blade): la expresión es una lista de
 * piezas con valores de una lista cerrada, validada al guardarla.
 *
 * Forma (document_template_fields.expression y mapping.expresiones):
 *   ['partes' => [
 *       ['tipo' => 'variable', 'clave' => 'contacto_emergencia_nombre'],
 *       ['tipo' => 'texto', 'valor' => ' (', 'separador' => true],
 *       ['tipo' => 'variable', 'clave' => 'contacto_emergencia_parentesco', 'sufijo' => ')', 'opcional' => true],
 *    ],
 *    'transformar' => 'mayusculas']
 *
 * - `opcional`: si el dato está vacío, la pieza (con su prefijo/sufijo) se
 *   omite; si NO es opcional y está vacío, el documento lo pide como
 *   faltante (no se imprime un hueco).
 * - Un texto con `separador` solo se imprime ENTRE dos piezas con valor
 *   (no deja « · » colgando cuando falta el teléfono).
 */
class ExpresionesDocumentoService
{
    public const MAX_PARTES = 12;

    public const MAX_TEXTO = 120;

    public const FORMATOS = ['moneda', 'numero_letra'];

    public function __construct(private readonly NumeroALetras $letras) {}

    /**
     * Agrega a $valores el valor de cada campo calculado del mapa compilado
     * (mapping.expresiones: campo del motor => especificación).
     *
     * @param  array<string, mixed>  $mapping
     * @param  array<string, string>  $valores
     * @return array<string, string>
     */
    public function completar(array $mapping, array $valores): array
    {
        foreach ((array) ($mapping['expresiones'] ?? []) as $campo => $especificacion) {
            if (is_array($especificacion)) {
                $valores[(string) $campo] = $this->evaluar($especificacion, $valores);
            }
        }

        return $valores;
    }

    /**
     * @param  array<string, mixed>  $especificacion  {source_key?, transform?, expression?}
     * @param  array<string, string>  $valores
     */
    public function evaluar(array $especificacion, array $valores): string
    {
        $expresion = $especificacion['expression'] ?? null;
        $resultado = is_array($expresion)
            ? $this->evaluarPartes((array) ($expresion['partes'] ?? []), $valores)
            : trim((string) ($valores[(string) ($especificacion['source_key'] ?? '')] ?? ''));

        $transformaciones = [
            is_array($expresion) ? ($expresion['transformar'] ?? null) : null,
            $especificacion['transform'] ?? null,
        ];

        foreach ($transformaciones as $transformacion) {
            $enum = is_string($transformacion) ? TransformacionTexto::tryFrom($transformacion) : null;

            if ($enum !== null) {
                $resultado = $enum->aplicar($resultado);
            }
        }

        return $resultado;
    }

    /**
     * Datos del catálogo que usa la especificación: los REQUERIDOS (si
     * faltan, el documento no se genera) y todos.
     *
     * @param  array<string, mixed>  $especificacion
     * @return array{requeridas: list<string>, todas: list<string>}
     */
    public function variablesDe(array $especificacion): array
    {
        $expresion = $especificacion['expression'] ?? null;

        if (! is_array($expresion)) {
            $clave = (string) ($especificacion['source_key'] ?? '');

            return $clave === '' ? ['requeridas' => [], 'todas' => []] : ['requeridas' => [$clave], 'todas' => [$clave]];
        }

        $requeridas = [];
        $todas = [];

        foreach ((array) ($expresion['partes'] ?? []) as $parte) {
            if (is_array($parte) && ($parte['tipo'] ?? null) === 'variable' && is_string($parte['clave'] ?? null)) {
                $todas[] = $parte['clave'];

                if (! ($parte['opcional'] ?? false)) {
                    $requeridas[] = $parte['clave'];
                }
            }
        }

        return ['requeridas' => array_values(array_unique($requeridas)), 'todas' => array_values(array_unique($todas))];
    }

    /**
     * Errores de una expresión antes de guardarla (lista vacía = válida).
     *
     * @param  callable(string): bool  $existeVariable
     * @return list<string>
     */
    public function validar(mixed $expresion, callable $existeVariable): array
    {
        if (! is_array($expresion) || ! is_array($expresion['partes'] ?? null)) {
            return ['La expresión no tiene piezas.'];
        }

        $errores = [];
        $partes = $expresion['partes'];

        if ($partes === [] || count($partes) > self::MAX_PARTES) {
            $errores[] = sprintf('Una expresión lleva entre 1 y %d piezas.', self::MAX_PARTES);
        }

        if (isset($expresion['transformar']) && TransformacionTexto::tryFrom((string) $expresion['transformar']) === null) {
            $errores[] = 'Transformación no válida.';
        }

        $variables = 0;

        foreach (array_values($partes) as $i => $parte) {
            $n = $i + 1;

            if (! is_array($parte)) {
                $errores[] = "Pieza {$n}: no válida.";

                continue;
            }

            $permitidas = ['tipo', 'clave', 'valor', 'prefijo', 'sufijo', 'opcional', 'separador', 'transformar', 'formato'];

            if (array_diff(array_keys($parte), $permitidas) !== []) {
                $errores[] = "Pieza {$n}: tiene propiedades no permitidas.";
            }

            foreach (['valor', 'prefijo', 'sufijo'] as $texto) {
                if (isset($parte[$texto]) && (! is_string($parte[$texto]) || mb_strlen($parte[$texto]) > self::MAX_TEXTO || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F<>{}]/u', $parte[$texto]) === 1)) {
                    $errores[] = "Pieza {$n}: el texto no es válido (máximo ".self::MAX_TEXTO.' caracteres, sin < > { }).';
                }
            }

            $tipo = $parte['tipo'] ?? null;

            if ($tipo === 'texto') {
                continue;
            }

            if ($tipo !== 'variable') {
                $errores[] = "Pieza {$n}: tipo de pieza no válido.";

                continue;
            }

            $variables++;

            if (! is_string($parte['clave'] ?? null) || ! $existeVariable($parte['clave'])) {
                $errores[] = "Pieza {$n}: el dato elegido no existe.";
            }

            if (isset($parte['formato']) && ! in_array($parte['formato'], self::FORMATOS, true)) {
                $errores[] = "Pieza {$n}: formato no válido.";
            }

            if (isset($parte['transformar']) && TransformacionTexto::tryFrom((string) $parte['transformar']) === null) {
                $errores[] = "Pieza {$n}: transformación no válida.";
            }
        }

        if ($variables === 0) {
            $errores[] = 'La expresión debe usar al menos un dato.';
        }

        return $errores;
    }

    /**
     * @param  array<int|string, mixed>  $partes
     * @param  array<string, string>  $valores
     */
    private function evaluarPartes(array $partes, array $valores): string
    {
        // Primero cada pieza con su valor final (o null si se omite).
        $piezas = [];

        foreach (array_values($partes) as $parte) {
            if (! is_array($parte)) {
                continue;
            }

            if (($parte['tipo'] ?? null) === 'texto') {
                $piezas[] = ['texto' => (string) ($parte['valor'] ?? ''), 'separador' => (bool) ($parte['separador'] ?? false), 'dato' => false];

                continue;
            }

            $valor = trim((string) ($valores[(string) ($parte['clave'] ?? '')] ?? ''));
            $valor = $this->formatear($valor, $parte['formato'] ?? null);
            $transformacion = is_string($parte['transformar'] ?? null) ? TransformacionTexto::tryFrom($parte['transformar']) : null;

            if ($transformacion !== null) {
                $valor = $transformacion->aplicar($valor);
            }

            $piezas[] = [
                'texto' => $valor === '' ? null : sprintf('%s%s%s', (string) ($parte['prefijo'] ?? ''), $valor, (string) ($parte['sufijo'] ?? '')),
                'separador' => false,
                'dato' => true,
            ];
        }

        $salida = '';

        foreach ($piezas as $i => $pieza) {
            if ($pieza['texto'] === null) {
                continue;
            }

            if ($pieza['separador']) {
                $antes = $this->hayDato(array_slice($piezas, 0, $i));
                $despues = $this->hayDato(array_slice($piezas, $i + 1));

                if (! $antes || ! $despues) {
                    continue;
                }
            }

            $salida .= $pieza['texto'];
        }

        return trim($salida);
    }

    /**
     * @param  list<array{texto: string|null, separador: bool, dato: bool}>  $piezas
     */
    private function hayDato(array $piezas): bool
    {
        foreach ($piezas as $pieza) {
            if ($pieza['dato'] && $pieza['texto'] !== null) {
                return true;
            }
        }

        return false;
    }

    private function formatear(string $valor, mixed $formato): string
    {
        if ($valor === '' || ! is_string($formato)) {
            return $valor;
        }

        $numero = str_replace([',', '$', ' '], '', $valor);

        if (! is_numeric($numero)) {
            return $valor;
        }

        return match ($formato) {
            'moneda' => number_format((float) $numero, 2),
            'numero_letra' => $this->letras->moneda((float) $numero),
            default => $valor,
        };
    }
}
