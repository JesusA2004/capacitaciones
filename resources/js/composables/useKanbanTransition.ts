import { ref } from 'vue';

/**
 * Estado compartido del ciclo de vida de un drag & drop de tablero Kanban
 * (Solicitudes y Vacantes usan exactamente el mismo patrón). NO conoce
 * reglas de negocio (qué transición es válida, qué requiere comentario,
 * etc.) — eso lo decide quien usa el composable.
 *
 * Dos reglas, aprendidas de un freeze real reportado en producción:
 *
 * 1. Nunca abrir un Dialog/confirmación dentro del handler `@add` de
 *    vue-draggable-plus (SortableJS): eso monta un overlay de Reka mientras
 *    Sortable todavía está limpiando su propio estado interno (clases
 *    `sortable-ghost`/`sortable-chosen`, captura de puntero) del mismo
 *    gesto nativo de pointerup. El patrón correcto es manejar
 *    `@start`/`@end` (nunca `@add`/`@remove` para abrir UI).
 *
 * 2. Nunca pelear con el v-model de vue-draggable-plus reconstruyendo el
 *    arreglo completo DENTRO del propio handler `@end`. `columnas[estado]`
 *    ya está enlazado con `v-model` a cada `<VueDraggable>`; si justo al
 *    soltar reemplazamos ese arreglo por el canónico del servidor (que
 *    todavía no cambió), Vue mueve el nodo recién soltado de una lista `v-for`
 *    a otra en el mismo ciclo en que Sortable apenas está terminando de
 *    limpiar sus referencias internas a ese mismo nodo (`dragEl`/`ghostEl`) —
 *    dos escrituras al mismo arreglo reactivo desde dos fuentes (la
 *    reconciliación propia de la librería y la nuestra) en la misma
 *    ventana. Por eso `restaurarCanonico()` NUNCA se llama automáticamente
 *    al soltar: se deja que el drop quede como el usuario lo ve, y solo se
 *    fuerza el estado canónico en respuesta a una acción discreta y
 *    desacoplada del gesto de arrastre (cancelar el diálogo, o un error del
 *    servidor) — momentos en los que Sortable ya está completamente inactivo.
 */
export function useKanbanTransition() {
    const isDragging = ref(false);
    const processing = ref(false);

    function onStart() {
        isDragging.value = true;
    }

    function onEnd() {
        isDragging.value = false;
    }

    /**
     * Fuerza el tablero a su estado canónico. Solo debe llamarse desde un
     * evento desacoplado del gesto de arrastre (cancelar, error del
     * servidor, o el `watch()` sobre los props tras un reload) — nunca
     * dentro de `@end`.
     */
    function restaurarCanonico(reconstruirCanonico: () => void) {
        reconstruirCanonico();
    }

    return {
        isDragging,
        processing,
        onStart,
        onEnd,
        restaurarCanonico,
    };
}
