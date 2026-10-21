<?php

namespace App\Enums;

/**
 * Por qué la app de una cuenta quedó limitada a subir sus documentos
 * pendientes (App\Services\Expedientes\BloqueoDocumentosService).
 *
 * - sin_carta: personal que ya laboraba, con documentos faltantes y sin
 *   carta compromiso (se bloquea de inmediato, nunca se le genera carta).
 * - carta_vencida: tuvo carta compromiso y pasó la fecha límite sin
 *   entregar lo comprometido.
 * - excede_maximo_carta: nuevo ingreso que cerró su registro con más
 *   documentos faltantes de los que admite una carta
 *   (config('expedientes.carta_compromiso.max_documentos')); la carta se
 *   genera sola cuando sus faltantes bajan a ese máximo.
 */
enum MotivoBloqueoDocumentos: string
{
    case SinCarta = 'sin_carta';
    case CartaVencida = 'carta_vencida';
    case ExcedeMaximoCarta = 'excede_maximo_carta';

    public function etiqueta(): string
    {
        return match ($this) {
            self::SinCarta => 'Documentos faltantes en tu expediente',
            self::CartaVencida => 'Venció tu carta compromiso',
            self::ExcedeMaximoCarta => 'Demasiados documentos pendientes para una carta compromiso',
        };
    }
}
