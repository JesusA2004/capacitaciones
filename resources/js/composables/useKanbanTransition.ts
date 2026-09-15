import { nextTick, ref } from 'vue';

/**
 * Estado compartido del ciclo de vida de un drag & drop de tablero Kanban
 * (Solicitudes y Vacantes usan exactamente el mismo patrón). NO conoce
 * reglas de negocio (qué transición es válida, qué requiere comentario,
 * etc.) — eso lo decide quien usa el composable.
 *
 * Por qué existe: abrir un Dialog/confirmación dentro del handler `@add` de
 * vue-draggable-plus (SortableJS) dispara el montaje de un overlay de Reka
 * mientras Sortable todavía está limpiando su propio estado interno
 * (clases `sortable-ghost`/`sortable-chosen`, captura de puntero) del mismo
 * gesto nativo de pointerup — eso puede dejar la página sin responder,
 * misma familia de bug que el freeze de overlays ya corregido antes (ver
 * CLAUDE.md). El patrón correcto es: manejar `@start`/`@end` (nunca
 * `@add`/`@remove` para abrir UI), restaurar el tablero a su estado
 * canónico INMEDIATAMENTE al soltar, esperar un `nextTick()` a que
 * Vue/Sortable terminen su ciclo, y solo entonces mostrar la confirmación.
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
     * Restaura el tablero a su estado canónico y espera a que Vue/Sortable
     * terminen su ciclo antes de devolver el control — recién ahí es seguro
     * montar un Dialog/confirmación.
     */
    async function asentarAntesDeConfirmar(reconstruirCanonico: () => void) {
        reconstruirCanonico();
        await nextTick();
    }

    return {
        isDragging,
        processing,
        onStart,
        onEnd,
        asentarAntesDeConfirmar,
    };
}
