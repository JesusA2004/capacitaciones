# QA — Kanban de Solicitudes y Vacantes (drag & drop)

## Historial

**Intento 1 (corregido, pero insuficiente):** ambos tableros abrían un
`Dialog`/`SweetAlert2` **dentro del handler `@add`** de SortableJS — mismo
call stack síncrono en el que SortableJS todavía limpia su propio estado
(`sortable-ghost`/`sortable-chosen`, captura de puntero). Se corrigió
moviendo toda la UI a `@start`/`@end` (nunca `@add`/`@remove`). Reportado en
producción como insuficiente: el freeze seguía ocurriendo.

**Intento 2 (este):** con `@add` ya descartado, la causa más probable que
queda es una segunda: **`onEndDrag` reconstruía el arreglo completo de
`columnas` de forma síncrona dentro del propio handler `@end`**, mientras
`columnas[estado]` sigue enlazado con `v-model` a cada `<VueDraggable>`.
`vue-draggable-plus` también escribe a ese mismo arreglo reactivo como parte
de su propia reconciliación del drop. Dos escrituras al mismo `v-model`
——la de la librería y la nuestra (`construirColumnas(props.solicitudes)`,
que además mueve el elemento de la lista `v-for` de una columna a otra en el
mismo tick, forzando montar/desmontar el nodo que SortableJS acaba de
soltar)— compitiendo en la misma ventareal de limpieza de Sortable es una
causa de freeze conocida en integraciones Vue+SortableJS (el nodo que
Sortable todavía referencia internamente como `dragEl`/`ghostEl` puede
quedar huérfano si Vue lo desmonta antes de que Sortable termine sus propios
resets de `pointer-events`/`user-select` en `document.body`).

No se pudo reproducir el freeze en vivo en este entorno (sin navegador
disponible para el agente) ni confirmar esta causa con un debugger real —
sigue siendo la hipótesis más fundamentada, no un hecho verificado. Por eso
esta vez el fix es estructural (dejar de pelear con el `v-model` en vez de
solo reordenar `await`s) y se agrega instrumentación para que, si el
usuario lo reproduce de nuevo, quede evidencia concreta en vez de otra
suposición.

## Fix aplicado (intento 2)

`resources/js/composables/useKanbanTransition.ts` ya NO reconstruye el
tablero dentro de `@end`. Ambos tableros ahora:

1. Escuchan `@start`/`@end` de `VueDraggable`, nunca `@add`/`@remove`
   (se mantiene del intento 1).
2. **En `@start`** capturan `evento.item.dataset.kanbanId` (id real, ver
   `data-kanban-id` en cada tarjeta) y `evento.from.dataset.estado` en
   variables locales (`dragOrigenId`/`dragOrigenEstado`) — en vez de confiar
   solo en `evento.data`/`evento.from` en `@end`, que para entonces ya
   pudieron mutar por el propio `v-model` de la librería.
3. **En `@end`**, buscan la solicitud/vacante por ese id en la fuente
   canónica (`props.solicitudes`/`props.vacantes`, nunca `evento.data`) y
   **NO tocan `columnas`** — dejan el drop tal como el usuario lo ve y solo
   abren la confirmación correspondiente.
4. `columnas` solo se fuerza a su estado canónico
   (`restaurarCanonico()`) en tres momentos, todos desacoplados del gesto de
   arrastre y con SortableJS ya inactivo: al cancelar el diálogo de
   confirmación, al cerrar `CubrirVacanteDialog` sin cubrir, y si el
   `router.patch`/`put` responde con error. En éxito no hace falta: el
   `watch(() => props.solicitudes, construirColumnas)` ya reconstruye desde
   los props frescos que trae la respuesta de Inertia.

Mientras hay una confirmación abierta o una request en curso
(`useKanbanTransition().processing`), cada `VueDraggable` recibe
`:disabled="true"` — no se puede iniciar un segundo drag hasta resolver el
pendiente.

## Drag handle

Cada tarjeta tiene un `GripVertical` con la clase `kanban-drag-handle`, y
`VueDraggable` usa `handle=".kanban-drag-handle"` +
`filter="a, button, input, textarea, select"`. Solo ese ícono inicia un
drag; clic en el resto de la tarjeta (Vacantes) sigue abriendo edición, y
los links/botones internos nunca inician un drag a medias.

## Vacantes: reglas que se mantienen

- Soltar en "Cubierta" nunca hace un `PUT` directo: abre
  `CubrirVacanteDialog` (cobertura real) después de `@end`. Cerrar el
  diálogo sin cubrir restaura `columnas` a su estado canónico.
- Soltar en "Cancelada" pide motivo obligatorio con `PeopleConfirmDialog`
  (no `SweetAlert2`, para no mezclar dos sistemas de overlay) después de
  `@end`.
- Cualquier otra transición pide una confirmación ligera
  (`PeopleConfirmDialog`) antes de escribir nada. El backend sigue siendo la
  autoridad final vía `EstadoVacante::puedeTransicionarA()`.

## Si el freeze vuelve a ocurrir: cómo capturar evidencia real

Sin esto, cualquier siguiente intento de arreglo vuelve a ser una hipótesis
a ciegas. Antes de reportarlo, con las DevTools abiertas (pestaña Console),
pegar esto una sola vez ANTES de reproducir el freeze:

```js
window.__kanbanDebug = true;
['pointerdown', 'pointerup', 'pointermove'].forEach((ev) =>
    document.addEventListener(
        ev,
        () => {
            if (!window.__kanbanDebug) return;
            console.log(ev, {
                bodyPointerEvents: document.body.style.pointerEvents,
                bodyUserSelect: document.body.style.userSelect,
                sortableDrag: document.querySelectorAll('.sortable-drag').length,
                sortableGhost: document.querySelectorAll('.sortable-ghost').length,
                sortableChosen: document.querySelectorAll('.sortable-chosen').length,
                dismissableLayers: document.querySelectorAll('[data-dismissable-layer]').length,
                activeElement: document.activeElement?.tagName,
            });
        },
        true,
    ),
);
```

Luego reproducir el drag que se congela. En el momento exacto del freeze,
correr:

```js
({
    bodyPointerEvents: document.body.style.pointerEvents,
    bodyUserSelect: document.body.style.userSelect,
    bodyStyle: document.body.getAttribute('style'),
    sortableDrag: document.querySelectorAll('.sortable-drag').length,
    sortableGhost: document.querySelectorAll('.sortable-ghost').length,
    sortableChosen: document.querySelectorAll('.sortable-chosen').length,
    dismissableLayers: document.querySelectorAll('[data-dismissable-layer]').length,
    activeElement: document.activeElement,
})
```

Y copiar: (1) el log completo de eventos `pointerdown/pointerup/pointermove`
desde que empezó el drag, (2) el resultado del segundo snippet, (3) el
navegador/versión exacto. Con eso sí es posible confirmar la causa en vez de
suponerla — sin esa evidencia, cualquier cambio adicional a este código
seguiría siendo una apuesta, no una corrección verificada.

## QA manual (a ejecutar por el usuario — no hay navegador disponible para el agente)

Después de sembrar datos demo, sin recargar la página (cero F5):

**Solicitudes — 30 movimientos:**
Enviada→Revisión, Revisión→Corrección, Corrección→Revisión,
Revisión→Rechazada (sin comentario, debe bloquear; con comentario, debe
pasar), Revisión→Aprobada, Aprobada→Cerrada. Cancelar varias confirmaciones
a mitad de camino. Abrir/cerrar el detalle de una solicitud entre
movimientos.

**Vacantes — 30 movimientos:**
Abierta→En reclutamiento→Con candidatos→En revisión (confirmación ligera en
cada una). Intentar soltar en "Cubierta" → debe abrir
`CubrirVacanteDialog`, nunca cambiar el estado solo. Cerrar el diálogo sin
cubrir → la vacante debe seguir en su columna original. Soltar en
"Cancelada" → motivo obligatorio; cancelar el diálogo no debe mover nada.

**Después de cada ciclo, en la consola del navegador:**

```js
document.body.style.pointerEvents !== 'none'
document.querySelectorAll('.sortable-drag').length === 0
document.querySelectorAll('.sortable-ghost').length === 0
document.querySelectorAll('.sortable-chosen').length === 0
```

Los cuatro deben cumplirse siempre. Si alguno falla, es una regresión de
esta misma clase de bug — no agregar un `setTimeout`/`setInterval` para
parcharlo; revisar si algún overlay nuevo se está abriendo dentro de
`@add`/`@remove` en vez de `@end`, o si algo volvió a reemplazar `columnas`
de forma síncrona dentro de `@end`.
