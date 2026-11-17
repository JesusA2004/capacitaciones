import type {
    ClaveCuadranteNineBox,
    ComponenteDesempeno,
    NivelNineBox,
} from '@/types';

/**
 * Colores de la Nine Box (docs/NINE_BOX.md): SOLO tokens de la paleta
 * pastel de resources/css/app.css, nunca hex. Cada cuadrante tiene su
 * fondo, su acento (texto/ícono) y su aro (avatares).
 */
export const ESTILO_CUADRANTE: Record<
    ClaveCuadranteNineBox,
    { fondo: string; acento: string; aro: string; punto: string }
> = {
    estrella: {
        fondo: 'bg-success-soft',
        acento: 'text-success',
        aro: 'ring-success/60',
        punto: 'bg-success',
    },
    alto_potencial: {
        fondo: 'bg-verde-suave',
        acento: 'text-success',
        aro: 'ring-esmeralda/60',
        punto: 'bg-esmeralda',
    },
    profesional_clave: {
        fondo: 'bg-salvia',
        acento: 'text-primary',
        aro: 'ring-primary/50',
        punto: 'bg-primary',
    },
    enigma: {
        fondo: 'bg-info-soft',
        acento: 'text-info',
        aro: 'ring-info/50',
        punto: 'bg-info',
    },
    nucleo: {
        fondo: 'bg-menta',
        acento: 'text-primary',
        aro: 'ring-primary/40',
        punto: 'bg-esmeralda',
    },
    dilema: {
        fondo: 'bg-warning-soft',
        acento: 'text-warning',
        aro: 'ring-warning/50',
        punto: 'bg-oro',
    },
    confiable: {
        fondo: 'bg-olivo',
        acento: 'text-success',
        aro: 'ring-success/40',
        punto: 'bg-success',
    },
    efectivo: {
        fondo: 'bg-crema',
        acento: 'text-bronce',
        aro: 'ring-bronce/50',
        punto: 'bg-bronce',
    },
    bajo_desempeno: {
        fondo: 'bg-danger-soft',
        acento: 'text-danger',
        aro: 'ring-danger/50',
        punto: 'bg-danger',
    },
};

/** Columna (desempeño) y fila (potencial, alto arriba) de la cuadrícula. */
export const INDICE_NIVEL: Record<NivelNineBox, number> = {
    bajo: 0,
    medio: 1,
    alto: 2,
};

export const ETIQUETA_NIVEL: Record<NivelNineBox, string> = {
    bajo: 'Bajo',
    medio: 'Medio',
    alto: 'Alto',
};

export function textoMetodo(componente: ComponenteDesempeno): string {
    switch (componente.metodo) {
        case 'meta':
            return 'Cumplimiento de la meta';
        case 'percentil':
            return 'Percentil contra todos los gestores';
        default:
            return 'Rango de mora configurado';
    }
}

/** Tono del puntaje 0-100 (texto). */
export function tonoPuntaje(puntaje: number | null): string {
    if (puntaje === null) {
        return 'text-muted-foreground';
    }

    if (puntaje >= 70) {
        return 'text-success';
    }

    return puntaje >= 40 ? 'text-warning' : 'text-danger';
}

export function normalizarTexto(texto: string): string {
    return texto
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .trim();
}
