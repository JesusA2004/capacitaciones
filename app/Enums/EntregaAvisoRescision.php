<?php

namespace App\Enums;

/**
 * Forma de entrega del aviso de rescisión (RN-07/RN-08 y PDF Etapa 1). Hoy se
 * capturan «Entregado personalmente» y «Se negó a recibirlo»; `ante_tribunal`
 * se conserva para leer bajas registradas antes de este cambio.
 */
enum EntregaAvisoRescision: string
{
    case Personal = 'personal';
    case SeNego = 'se_nego';
    case AnteTribunal = 'ante_tribunal';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Personal => 'Entregado personalmente',
            self::SeNego => 'Se negó a recibirlo',
            self::AnteTribunal => 'Ante Tribunal',
        };
    }

    /**
     * Opciones de captura vigentes.
     *
     * @return list<self>
     */
    public static function vigentes(): array
    {
        return [self::Personal, self::SeNego];
    }
}
