<?php

namespace App\Enums;

/**
 * Para qué le llega un aviso a una persona (docs/NOTIFICACIONES_EVENTOS.md):
 * no es lo mismo confirmarle a quien hizo algo que pedirle a alguien que
 * actúe. Visibilidad (quién PUEDE ver) es otra cosa: nunca decide quién
 * RECIBE.
 */
enum CategoriaNotificacion: string
{
    // Para quien hizo la acción: "Tu solicitud fue registrada".
    case Confirmacion = 'confirmacion';
    // Para quien debe resolver: "Nueva solicitud por aprobar".
    case Pendiente = 'pendiente';
    // Para el interesado cuando algo se resolvió: "Tu solicitud fue aprobada".
    case Resultado = 'resultado';
    // `people:probar-notificacion`: claramente marcada, nunca de negocio.
    case Prueba = 'prueba';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Confirmacion => 'Confirmación',
            self::Pendiente => 'Tarea por resolver',
            self::Resultado => 'Resultado',
            self::Prueba => 'Prueba',
        };
    }
}
