<?php

namespace App\Enums;

/**
 * «PERMISO SOLICITADO» del Formato de Permiso oficial
 * (docs/formatosRH/Formato_Permiso.docx). Solo estas tres modalidades.
 */
enum TipoPermisoSolicitado: string
{
    case Faltar = 'faltar';
    case SalirTemprano = 'salir_temprano';
    case LlegarTarde = 'llegar_tarde';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Faltar => 'Permiso para faltar',
            self::SalirTemprano => 'Permiso para salir temprano',
            self::LlegarTarde => 'Permiso para llegar tarde',
        };
    }

    /** Hora que captura el colaborador (null = se captura por días). */
    public function campoHora(): ?string
    {
        return match ($this) {
            self::Faltar => null,
            self::SalirTemprano => 'hora_salida',
            self::LlegarTarde => 'hora_entrada',
        };
    }
}
