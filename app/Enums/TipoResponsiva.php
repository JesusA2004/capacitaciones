<?php

namespace App\Enums;

/**
 * Qué clase de equipo ampara la responsiva. El formato oficial
 * (docs/responsiva/ResponsivaLaptopPanta.docx) es para «equipo de cómputo»;
 * para cascos, uniformes, celulares o herramientas se usa el MISMO texto con
 * la denominación «equipo de trabajo» — no se agregan ni quitan cláusulas.
 */
enum TipoResponsiva: string
{
    case EquipoComputo = 'equipo_computo';
    case EquipoTrabajo = 'equipo_trabajo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EquipoComputo => 'Equipo de cómputo',
            self::EquipoTrabajo => 'Equipo de trabajo (casco, uniforme, celular, herramienta…)',
        };
    }

    /** Denominación que usa el texto de la responsiva («el equipo de cómputo»). */
    public function denominacion(): string
    {
        return match ($this) {
            self::EquipoComputo => 'equipo de cómputo',
            self::EquipoTrabajo => 'equipo de trabajo',
        };
    }

    public function titulo(): string
    {
        return mb_strtoupper('Responsiva de '.$this->denominacion(), 'UTF-8');
    }
}
