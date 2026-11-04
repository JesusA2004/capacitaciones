<?php

namespace App\Enums;

/**
 * Estado del corte mensual de Recepción documental RH
 * (App\Models\RecepcionDocumentalCorte). Se CALCULA desde sus renglones
 * (RecepcionDocumentalService::recalcularEstado), salvo «Cerrado», que solo
 * se pone y se quita con permiso explícito y queda en la bitácora.
 */
enum EstadoCorteRecepcion: string
{
    /** Creado, todavía sin calcular lo esperado. */
    case EnPreparacion = 'en_preparacion';
    /** Mes en curso: todavía pueden llegar eventos y originales. */
    case Abierto = 'abierto';
    /** El mes ya terminó y hay originales pendientes o no recibidos. */
    case ConPendientes = 'con_pendientes';
    /** Todo lo esperado está recibido (o justificado como «No aplica»). */
    case Completo = 'completo';
    /** Cerrado por RH: ya no se recalcula ni se edita (se puede reabrir con motivo). */
    case Cerrado = 'cerrado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EnPreparacion => 'En preparación',
            self::Abierto => 'Abierto',
            self::ConPendientes => 'Con pendientes',
            self::Completo => 'Completo',
            self::Cerrado => 'Cerrado',
        };
    }

    public function esEditable(): bool
    {
        return $this !== self::Cerrado;
    }
}
