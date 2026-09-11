<?php

namespace App\Enums;

/**
 * Nivel de un nodo dentro de la matriz comercial (MATRIZ -> Región -> Zona
 * -> Ruta). `gerencia`/`subgerencia` quedan disponibles para cuando un nodo
 * representa específicamente un puesto de gerencia/subgerencia dentro de
 * una zona, no una ruta operativa; `sucursal` para cuando un nodo de la
 * matriz corresponde 1:1 a una Sucursal sin subdivisión en rutas.
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

    public function etiqueta(): string
    {
        return match ($this) {
            self::Matriz => 'Matriz',
            self::Region => 'Región',
            self::Zona => 'Zona',
            self::Ruta => 'Ruta',
            self::Sucursal => 'Sucursal',
            self::Gerencia => 'Gerencia',
            self::Subgerencia => 'Subgerencia',
        };
    }

    /**
     * true si este nivel es el que puede tener un gestor/responsable
     * asignado y, por lo tanto, contar como "cubierta" o "sin cubrir".
     */
    public function esCobertura(): bool
    {
        return match ($this) {
            self::Ruta, self::Sucursal, self::Gerencia, self::Subgerencia => true,
            default => false,
        };
    }
}
