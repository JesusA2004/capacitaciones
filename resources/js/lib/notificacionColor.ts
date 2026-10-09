/**
 * Traduce el `color` cerrado que manda App\Services\Colaboradores\NotificacionesService
 * (success/info/warning/danger/celebracion/neutral) a clases Tailwind — la
 * única fuente de verdad de "qué tan grave/positiva es esta notificación"
 * vive en el backend, esto solo pinta el círculo del emoji acorde.
 */
export function colorClaseNotificacion(color: string): string {
    const clases: Record<string, string> = {
        success: 'bg-success/15 text-success',
        info: 'bg-info/15 text-info',
        warning: 'bg-warning/15 text-warning',
        danger: 'bg-destructive/15 text-destructive',
        celebracion: 'bg-pink-500/15 text-pink-600',
        neutral: 'bg-muted text-muted-foreground',
    };

    return clases[color] ?? clases.neutral;
}
