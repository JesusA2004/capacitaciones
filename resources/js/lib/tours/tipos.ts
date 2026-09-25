import type { Component } from 'vue';

export type PasoTour = {
    /**
     * Selector CSS del elemento real de la pantalla que se resalta. Sin
     * selector, el paso se muestra como un diálogo centrado (introducciones,
     * cierres, explicaciones que no dependen de un elemento concreto).
     */
    selector?: string;
    /**
     * Pantalla donde vive el paso. Si no es la actual, el tour navega ahí
     * antes de mostrarlo. Sin `ruta` (ni `rutaDesde`), el paso se queda en la
     * misma pantalla que el paso anterior.
     */
    ruta?: string;
    /**
     * Selector de un enlace (`<a href>`) de la pantalla actual cuyo destino
     * se usa como ruta del paso — p. ej. "abre el primer expediente de la
     * lista" sin conocer su id de antemano. Si el enlace no existe (lista
     * vacía), el paso se omite.
     */
    rutaDesde?: string;
    titulo: string;
    texto: string;
    /** Tip práctico que se muestra destacado debajo del texto. */
    consejo?: string;
    /**
     * Si el elemento no aparece (lista vacía, botón que depende de un
     * permiso, sección apagada por configuración), el paso se omite en vez
     * de mostrarse centrado.
     */
    opcional?: boolean;
    /** Módulo al que pertenece el paso (chip del encabezado del tooltip). */
    seccion?: string;
};

export type Tour = {
    /** Identificador único y estable: se usa para recordar "ya lo vi". */
    id: string;
    titulo: string;
    pasos: PasoTour[];
};

export type ModoGuia = 'operativo' | 'colaborador';

/**
 * Módulo del sistema con su recorrido paso a paso. Es la única fuente de
 * verdad para el botón flotante de ayuda, la página /ayuda y el recorrido
 * completo del sistema.
 */
export type ModuloGuia = {
    id: string;
    nombre: string;
    /** Ruta de la pantalla principal del módulo (igual que en el menú). */
    ruta: string;
    /** Pantallas en las que el botón flotante ofrece este recorrido. */
    patron: RegExp;
    /** Modo de navegación en el que el módulo aparece en el menú. */
    modo: ModoGuia;
    /** Basta con tener uno; vacío = cualquiera en ese modo. */
    permisos: string[];
    /** Agrupación en la página de Ayuda. */
    grupo: string;
    icono: Component;
    descripcion: string;
    /**
     * Pasos dentro del módulo, sin `ruta`: se les asigna la del módulo al
     * construir el tour (hasta el primer paso con `rutaDesde`, a partir del
     * cual heredan la pantalla a la que ése llevó).
     */
    pasos: PasoTour[];
};

export type ContextoGuia = {
    tienePermiso: (permiso: string) => boolean;
    modo: ModoGuia;
};
