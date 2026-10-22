<?php

namespace App\Support;

/**
 * Filas por página de cualquier listado. El usuario elige 10 · 20 · 30 ·
 * 40 · 50 · Todos en el pie de la tabla (PaginacionTabla.vue), que viaja
 * en la URL como `por_pagina`. «Todos» trae todas las filas pero acotadas a
 * `config('paginacion.todos_maximo')`; la UI avisa cuando el tope recorta.
 *
 * Orden de lectura:
 *  1. `$solicitado` explícito (lo que el Service recibió en sus filtros).
 *  2. `por_pagina` del query string: estricto, solo las opciones del
 *     catálogo o `todos`; cualquier otro valor cae al default.
 *  3. `per_page` (legado, lo usa la app móvil y URLs viejas): cualquier
 *     entero ≥ 1 hasta el tope, o `todos`, para no romper clientes que ya
 *     pedían otros tamaños.
 */
final class Paginacion
{
    /** Tope de «Todos» si la configuración no trae uno válido. */
    public const TODOS = 2000;

    public const POR_DEFECTO = 20;

    public const PARAMETRO = 'por_pagina';

    public const PARAMETRO_LEGADO = 'per_page';

    public const VALOR_TODOS = 'todos';

    public static function porPagina(int $porDefecto = self::POR_DEFECTO, mixed $solicitado = null): int
    {
        if (self::tieneValor($solicitado)) {
            return self::flexible($solicitado) ?? $porDefecto;
        }

        $request = request();
        $porPagina = $request->query(self::PARAMETRO);

        if (self::tieneValor($porPagina)) {
            return self::estricto($porPagina) ?? $porDefecto;
        }

        $legado = $request->query(self::PARAMETRO_LEGADO);

        if (self::tieneValor($legado)) {
            return self::flexible($legado) ?? $porDefecto;
        }

        return $porDefecto;
    }

    /** Tope de filas que devuelve «Todos». */
    public static function maximoTodos(): int
    {
        $maximo = filter_var(config('paginacion.todos_maximo', self::TODOS), FILTER_VALIDATE_INT);

        return is_int($maximo) && $maximo >= 1 ? $maximo : self::TODOS;
    }

    /**
     * Tamaños de página que se ofrecen en el selector (sin «Todos»).
     *
     * @return list<int>
     */
    public static function opciones(): array
    {
        $configuradas = config('paginacion.opciones', [10, 20, 30, 40, 50]);
        $opciones = [];

        foreach (is_array($configuradas) ? $configuradas : [] as $opcion) {
            $valor = filter_var($opcion, FILTER_VALIDATE_INT);

            if (is_int($valor) && $valor >= 1) {
                $opciones[] = min($valor, self::maximoTodos());
            }
        }

        return $opciones === [] ? [10, 20, 30, 40, 50] : $opciones;
    }

    /** Solo valores del catálogo o «todos»; lo demás no es válido. */
    private static function estricto(mixed $valor): ?int
    {
        if (self::esTodos($valor)) {
            return self::maximoTodos();
        }

        $entero = filter_var($valor, FILTER_VALIDATE_INT);

        return is_int($entero) && in_array($entero, self::opciones(), true) ? $entero : null;
    }

    /** Cualquier entero positivo (acotado al tope) o «todos». */
    private static function flexible(mixed $valor): ?int
    {
        if (self::esTodos($valor)) {
            return self::maximoTodos();
        }

        $entero = filter_var($valor, FILTER_VALIDATE_INT);

        return is_int($entero) && $entero >= 1 ? min($entero, self::maximoTodos()) : null;
    }

    private static function esTodos(mixed $valor): bool
    {
        return is_string($valor) && in_array(mb_strtolower(trim($valor)), [self::VALOR_TODOS, 'all'], true);
    }

    private static function tieneValor(mixed $valor): bool
    {
        return $valor !== null && $valor !== '' && ! is_array($valor);
    }
}
