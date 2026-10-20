<?php

namespace App\Enums;

/**
 * Estatus de una baja (expediente de la baja, App\Models\Baja). La baja es
 * DIRECTA (docs/BAJAS_Y_CAUSALES.md): el gerente o RH la registra y queda
 * aplicada de inmediato (cierre laboral, acceso suspendido, documentos
 * generados, finiquito propuesto).
 *
 *   En trámite (en_revision_rh) → [En revisión Jurídico si la causal lo
 *   requiere] → Cerrada (RH, con los documentos obligatorios completos)
 *   No procede (cancelada): RH la revierte; la persona sigue laborando y se
 *   le devuelve el acceso. Nunca se borra la baja.
 *
 * LEGADO (flujo anterior, ya no se crean): Borrador y Rechazada. La
 * migración 2026_10_20_230000_bajas_directas convierte los registros que
 * hubiera; los casos se conservan para leer la bitácora anterior.
 */
enum EstatusBaja: string
{
    case Borrador = 'borrador';
    case EnRevisionRh = 'en_revision_rh';
    case EnRevisionJuridico = 'en_revision_juridico';
    case Rechazada = 'rechazada';
    case Cerrada = 'cerrada';
    case Cancelada = 'cancelada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador (flujo anterior)',
            self::EnRevisionRh => 'En trámite',
            self::EnRevisionJuridico => 'En revisión Jurídico',
            self::Rechazada => 'Rechazada (flujo anterior)',
            self::Cerrada => 'Cerrada',
            self::Cancelada => 'No procede',
        };
    }

    /**
     * Abierta = cuenta para «solo una baja abierta por colaborador» (RN-11).
     */
    public function esAbierta(): bool
    {
        return ! in_array($this, [self::Cerrada, self::Cancelada], true);
    }

    /**
     * Estatus del flujo anterior (borrador del gerente / rechazo): ya no se
     * crean ni se ofrecen en filtros.
     */
    public function esLegado(): bool
    {
        return in_array($this, [self::Borrador, self::Rechazada], true);
    }

    /**
     * @return list<self>
     */
    public static function vigentes(): array
    {
        return array_values(array_filter(self::cases(), fn (self $e): bool => ! $e->esLegado()));
    }

    /**
     * @return list<string>
     */
    public static function valoresAbiertos(): array
    {
        return array_values(array_map(fn (self $e): string => $e->value, array_filter(self::cases(), fn (self $e): bool => $e->esAbierta())));
    }
}
