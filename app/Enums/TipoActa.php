<?php

namespace App\Enums;

enum TipoActa: string
{
    case Administrativa = 'administrativa';
    case Hechos = 'hechos';
    case CartaResponsiva = 'carta_responsiva';
    case Auditoria = 'auditoria';

    /**
     * Único tipo que se levanta HOY (decisión 2026-10-15): el acta
     * administrativa circunstanciada, con un solo formato. Acta de hechos,
     * carta responsiva y acta de auditoría quedan solo para leer registros
     * históricos (nunca se crean nuevas).
     *
     * @return list<self>
     */
    public static function creables(): array
    {
        return [self::Administrativa];
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Administrativa => 'Acta administrativa circunstanciada',
            self::Hechos => 'Acta de hechos',
            self::CartaResponsiva => 'Carta responsiva',
            self::Auditoria => 'Acta de auditoría',
        };
    }

    /**
     * Clave de la plantilla documental con la que se genera este tipo de
     * acta (DocumentTemplate::clave, ver config/contratos.php).
     */
    public function clavePlantilla(): string
    {
        return match ($this) {
            self::Administrativa => 'acta_administrativa',
            self::Hechos => 'acta_hechos',
            self::CartaResponsiva => 'carta_responsiva',
            self::Auditoria => 'acta_auditoria',
        };
    }
}
