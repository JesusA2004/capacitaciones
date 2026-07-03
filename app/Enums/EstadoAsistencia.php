<?php

namespace App\Enums;

/**
 * Estados obligatorios de asistencia (Fase 9, ver
 * docs/AUDITORIA_CUMPLIMIENTO.md sección 2). `Tarde` se conserva de la Fase
 * 5 para el marcado manual del instructor (no lo pide el encargo, pero no
 * lo contradice y ya tenía cobertura de pruebas); los nuevos estados
 * (`AsistenciaParcial`, `PendienteRevision`, `CorregidaManualmente`) son los
 * que puede asignar la sincronización automática.
 */
enum EstadoAsistencia: string
{
    case Pendiente = 'pendiente';
    case Presente = 'presente';
    case AsistenciaParcial = 'asistencia_parcial';
    case Ausente = 'ausente';
    case Tarde = 'tarde';
    case PendienteRevision = 'pendiente_revision';
    case CorregidaManualmente = 'corregida_manualmente';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Presente => 'Presente',
            self::AsistenciaParcial => 'Asistencia parcial',
            self::Ausente => 'Ausente',
            self::Tarde => 'Tarde',
            self::PendienteRevision => 'Pendiente de revisión',
            self::CorregidaManualmente => 'Corregida manualmente',
        };
    }

    public function completaLeccion(): bool
    {
        return match ($this) {
            self::Presente, self::Tarde, self::AsistenciaParcial, self::CorregidaManualmente => true,
            default => false,
        };
    }
}
