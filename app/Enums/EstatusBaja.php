<?php

namespace App\Enums;

/**
 * Estatus de una baja (expediente de la baja, App\Models\Baja):
 *
 *   Borrador (gerente) → En revisión RH → [En revisión Jurídico si la
 *   causal lo requiere] → Cerrada (RH)
 *   Rechazada: RH o Jurídico la regresan al gerente con motivo; el gerente
 *   corrige y la vuelve a enviar.
 *   Cancelada: la baja no procede (la persona sigue laborando); el acceso
 *   suspendido al enviarla se devuelve. Nunca se borra la baja.
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
            self::Borrador => 'Borrador',
            self::EnRevisionRh => 'En revisión RH',
            self::EnRevisionJuridico => 'En revisión Jurídico',
            self::Rechazada => 'Rechazada',
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
     * El gerente captura/corrige todo (forma, causal, fechas) solo aquí.
     */
    public function editablePorSolicitante(): bool
    {
        return in_array($this, [self::Borrador, self::Rechazada], true);
    }

    /**
     * @return list<string>
     */
    public static function valoresAbiertos(): array
    {
        return array_values(array_map(fn (self $e): string => $e->value, array_filter(self::cases(), fn (self $e): bool => $e->esAbierta())));
    }
}
