/**
 * Traduce el `color` cerrado que manda App\Services\Colaboradores\NotificacionesService
 * (success/info/warning/danger/celebracion/neutral) a clases Tailwind — la
 * única fuente de verdad de "qué tan grave/positiva es esta notificación"
 * vive en el backend, esto solo pinta el círculo del emoji acorde.
 */
export function colorClaseNotificacion(color: string): string {
    const clases: Record<string, string> = {
        success: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
        info: 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
        warning: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
        danger: 'bg-destructive/15 text-destructive',
        celebracion: 'bg-pink-500/15 text-pink-600 dark:text-pink-400',
        neutral: 'bg-muted text-muted-foreground',
    };

    return clases[color] ?? clases.neutral;
}
