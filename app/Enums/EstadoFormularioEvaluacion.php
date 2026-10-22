<?php

namespace App\Enums;

/**
 * Ciclo de una versión de formulario de evaluación: borrador (editable, a lo
 * más uno por clave) → publicado (inmutable, el vigente; uno por clave) →
 * archivado (lo reemplazó otra versión publicada; las evaluaciones que se
 * contestaron con ella la siguen usando).
 */
enum EstadoFormularioEvaluacion: string
{
    case Borrador = 'borrador';
    case Publicado = 'publicado';
    case Archivado = 'archivado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Publicado => 'Publicado (vigente)',
            self::Archivado => 'Versión anterior',
        };
    }
}
