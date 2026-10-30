<?php

namespace App\Enums;

/**
 * Estatus de una baja (expediente de la baja, App\Models\Baja). La baja es
 * DIRECTA (docs/BAJAS_Y_CAUSALES.md): el gerente o RH la registra y queda
 * aplicada de inmediato (cierre laboral, acceso suspendido, documentos
 * generados, finiquito propuesto).
 *
 *   En trámite (en_revision_rh) → Cerrada (RH, con los documentos obligatorios
 *   completos y el último día laborado ya pasado).
 *   No procede (cancelada): RH la revierte; la persona sigue laborando y se
 *   le devuelve el acceso. Nunca se borra la baja.
 *
 * El tablero reparte las bajas En trámite en tres cubos de presentación —En
 * trámite, Faltan documentos y Listas para cerrar— según sus documentos y el
 * último día (BajaDocumentosService::cuboTablero), no son estatus distintos.
 *
 * COMPUERTA DE JURÍDICO RETIRADA (propuesta de mejoras Etapa 1): Jurídico ya
 * no autoriza bajas (conserva acceso de consulta). El case EnRevisionJuridico
 * se conserva SOLO para leer históricos; la migración
 * 2026_10_30_120000_bajas_retiro_compuerta_juridico convierte a En trámite las
 * filas que hubiera. Ya no se asigna a ninguna baja nueva.
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
        return in_array($this, [self::Borrador, self::Rechazada, self::EnRevisionJuridico], true);
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
