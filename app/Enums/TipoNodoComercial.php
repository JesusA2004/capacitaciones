<?php

namespace App\Enums;

/**
 * Nivel de un nodo dentro de la matriz comercial (MATRIZ -> Región -> Zona
 * -> Ruta). Dentro de una zona, la lista que entregó dirección mezcla rutas
 * de cobro reales con POSICIONES de la sucursal ("CUERNAVACA GTE",
 * "MIACATLAN SUBGERENCIA", "VOLANTE CUERNAVACA"): esas se guardan como
 * `gerencia`/`subgerencia`/`volante`, nunca como `ruta` — no son una cartera
 * que se le asigne a un Gestor (ver ClasificadorNodoComercial y
 * docs/MATRIZ_COMERCIAL.md). `sucursal` queda para cuando un nodo
 * corresponde 1:1 a una Sucursal sin subdivisión en rutas.
 */
enum TipoNodoComercial: string
{
    case Matriz = 'matriz';
    case Region = 'region';
    case Zona = 'zona';
    case Ruta = 'ruta';
    case Sucursal = 'sucursal';
    case Gerencia = 'gerencia';
    case Subgerencia = 'subgerencia';
    case Volante = 'volante';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Matriz => 'Matriz',
            self::Region => 'Región',
            self::Zona => 'Zona',
            self::Ruta => 'Ruta',
            self::Sucursal => 'Sucursal',
            self::Gerencia => 'Posición de gerencia',
            self::Subgerencia => 'Posición de subgerencia',
            self::Volante => 'Posición de gestor volante',
        };
    }

    /**
     * true si este nivel es una cartera que se le asigna a un Gestor y, por
     * lo tanto, cuenta como "cubierta" o "sin cubrir". Las posiciones de
     * gerencia/subgerencia/volante NO: esas se ven en el Organigrama.
     */
    public function esCobertura(): bool
    {
        return match ($this) {
            self::Ruta, self::Sucursal => true,
            default => false,
        };
    }

    /** Posición de la sucursal (no ruta de cobro) dentro de una zona. */
    public function esPosicion(): bool
    {
        return in_array($this, [self::Gerencia, self::Subgerencia, self::Volante], true);
    }
}
