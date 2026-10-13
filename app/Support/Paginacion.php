<?php

namespace App\Support;

/**
 * Filas por página de cualquier listado: el usuario elige 20 · 30 · 50 ·
 * Todos en el pie de la tabla (PaginacionTabla.vue), que viaja como
 * `per_page`. «Todos» se acota a TODOS para no traer tablas sin límite.
 */
final class Paginacion
{
    public const TODOS = 1000;

    public const POR_DEFECTO = 20;

    public static function porPagina(int $porDefecto = self::POR_DEFECTO, mixed $solicitado = null): int
    {
        $valor = filter_var($solicitado ?? request()->query('per_page'), FILTER_VALIDATE_INT);

        return is_int($valor) && $valor >= 1 ? min($valor, self::TODOS) : $porDefecto;
    }
}
