<?php

namespace App\Enums;

/**
 * Resultado de publicar un material de apoyo del centro de Ayuda
 * (`php artisan ayuda:publicar-material`).
 */
enum EstadoPublicacionMaterialAyuda: string
{
    // No existía en el disco privado: se copió.
    case Publicado = 'publicado';
    // Existía con otro contenido (o se pidió --forzar): se reemplazó.
    case Actualizado = 'actualizado';
    // Ya estaba publicado con el mismo tamaño y huella: no se tocó.
    case SinCambios = 'sin_cambios';
    // El archivo no está en docs/ayuda: no se pudo publicar.
    case Faltante = 'faltante';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Publicado => 'Publicado',
            self::Actualizado => 'Actualizado',
            self::SinCambios => 'Sin cambios',
            self::Faltante => 'FALTA EL ARCHIVO',
        };
    }
}
