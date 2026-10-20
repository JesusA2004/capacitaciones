/**
 * Tonos semánticos de la paleta pastel (ver resources/css/app.css). Un solo
 * mapa para chips de estado, tarjetas de métrica y avisos: así «faltan
 * datos» se ve igual en Contratos, Bajas o Evaluaciones. Nunca hex sueltos.
 */
export type Tono =
    'exito' | 'info' | 'alerta' | 'peligro' | 'neutro' | 'oro' | 'primario';

/** Fondo suave + texto del tono (chips, íconos en círculo). */
export const TONO_SUAVE: Record<Tono, string> = {
    exito: 'bg-success-soft text-success',
    info: 'bg-info-soft text-info',
    alerta: 'bg-warning-soft text-warning',
    peligro: 'bg-danger-soft text-destructive',
    neutro: 'bg-muted text-muted-foreground',
    oro: 'bg-crema text-bronce',
    primario: 'bg-salvia text-primary',
};

/** Borde del tono (tarjeta seleccionada, franja lateral). */
export const TONO_BORDE: Record<Tono, string> = {
    exito: 'border-success/50',
    info: 'border-info/50',
    alerta: 'border-warning/50',
    peligro: 'border-destructive/50',
    neutro: 'border-border',
    oro: 'border-oro/60',
    primario: 'border-primary/50',
};

/** Texto del tono (números grandes). */
export const TONO_TEXTO: Record<Tono, string> = {
    exito: 'text-success',
    info: 'text-info',
    alerta: 'text-warning',
    peligro: 'text-destructive',
    neutro: 'text-foreground',
    oro: 'text-bronce',
    primario: 'text-primary',
};
