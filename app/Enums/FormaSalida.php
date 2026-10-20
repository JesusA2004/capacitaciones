<?php

namespace App\Enums;

/**
 * Forma de salida de una baja (cómo se va la persona): primer nivel del
 * catálogo de bajas (docs/BAJAS_Y_CAUSALES.md). Cada causal pertenece a una
 * sola forma y su clave inicia con la clave de la forma (RV-01 → RV).
 *
 * Equivale 1:1 al tipo de baja que guarda el cierre laboral
 * (App\Enums\TipoBaja: renuncia / no renovación / rescisión): el cierre
 * conserva su valor histórico por compatibilidad con la API móvil y el
 * finiquito; la baja guarda la forma.
 */
enum FormaSalida: string
{
    case RenunciaVoluntaria = 'RV';
    case NoRenovacion = 'NR';
    case Rescision = 'RE';

    public function etiqueta(): string
    {
        return match ($this) {
            self::RenunciaVoluntaria => 'Renuncia voluntaria',
            self::NoRenovacion => 'No renovación',
            self::Rescision => 'Rescisión',
        };
    }

    /**
     * Descripción del catálogo (cat_forma_salida.descripcion).
     */
    public function descripcion(): string
    {
        return match ($this) {
            self::RenunciaVoluntaria => 'La persona decide irse.',
            self::NoRenovacion => 'La empresa decide no continuar, sin que exista una falta grave.',
            self::Rescision => 'La empresa termina la relación por una falta grave de la persona (art. 47 LFT).',
        };
    }

    /**
     * Texto corto de la tarjeta grande de la pantalla de solicitud.
     */
    public function resumen(): string
    {
        return match ($this) {
            self::RenunciaVoluntaria => 'La persona decide irse',
            self::NoRenovacion => 'La empresa decide no continuar',
            self::Rescision => 'Falta grave de la persona',
        };
    }

    public function orden(): int
    {
        return match ($this) {
            self::RenunciaVoluntaria => 10,
            self::NoRenovacion => 20,
            self::Rescision => 30,
        };
    }

    /**
     * Sufijo del token de color (resources/css/app.css → --forma-salida-*).
     */
    public function token(): string
    {
        return match ($this) {
            self::RenunciaVoluntaria => 'renuncia',
            self::NoRenovacion => 'no-renovacion',
            self::Rescision => 'rescision',
        };
    }

    public function tipoBaja(): TipoBaja
    {
        return match ($this) {
            self::RenunciaVoluntaria => TipoBaja::Renuncia,
            self::NoRenovacion => TipoBaja::FinContrato,
            self::Rescision => TipoBaja::Rescision,
        };
    }

    public static function desdeTipoBaja(TipoBaja $tipo): self
    {
        return match ($tipo) {
            TipoBaja::Renuncia => self::RenunciaVoluntaria,
            TipoBaja::FinContrato => self::NoRenovacion,
            TipoBaja::Rescision => self::Rescision,
        };
    }
}
