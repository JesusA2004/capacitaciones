<?php

namespace App\Enums;

/**
 * Ciclo de vida de una versión de plantilla oficial
 * (App\Models\OfficialFormatVersion):
 * - borrador: RH la está mapeando; se puede editar, no se usa para generar.
 * - publicada: es la vigente del formato; inmutable (cambiarla exige una
 *   versión nueva) y es la única que genera documentos nuevos.
 * - retirada: fue vigente; se conserva intacta para auditar/reproducir los
 *   documentos que se generaron con ella.
 */
enum EstadoVersionFormato: string
{
    case Borrador = 'borrador';
    case Publicada = 'publicada';
    case Retirada = 'retirada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Publicada => 'Vigente',
            self::Retirada => 'Histórica',
        };
    }
}
