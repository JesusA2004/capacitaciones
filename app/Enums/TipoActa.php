<?php

namespace App\Enums;

enum TipoActa: string
{
    case Administrativa = 'administrativa';
    case Hechos = 'hechos';
    case CartaResponsiva = 'carta_responsiva';
    case Auditoria = 'auditoria';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Administrativa => 'Acta administrativa',
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
