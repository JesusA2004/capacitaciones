import { ref } from 'vue';

/**
 * Ancho (px) que la barra superior (AppSidebarHeader) puede prestarle a las
 * acciones de un módulo (CrudPageHeader) SIN empujar ni encimar el
 * breadcrumb, la ayuda y la campana. Lo calcula la barra cada vez que cambia
 * de tamaño; CrudPageHeader solo sube sus botones a la barra si caben ahí —
 * si no, se quedan en la página, debajo del breadcrumb, y se acomodan en
 * varios renglones. Así ninguna combinación de botones desborda la barra.
 *
 * Estado único a nivel módulo: solo existe una barra superior a la vez.
 */
const espacioAcciones = ref(Number.POSITIVE_INFINITY);

export function useEspacioAccionesPagina() {
    return { espacioAcciones };
}
