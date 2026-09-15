# QA — Kanban de Solicitudes y Vacantes (drag & drop)

## Causa del freeze

Ambos tableros (`Rh/Solicitudes/Index.vue`, `Rh/Vacantes/Index.vue`) usan
`vue-draggable-plus` sobre SortableJS. La versión anterior abría un `Dialog`
de Reka (o, en Vacantes, `CubrirVacanteDialog`/`SweetAlert2` vía
`pedirMotivoCancelacionVacante()`) **dentro del handler `@add`**, es decir,
en el mismo call stack síncrono en el que SortableJS todavía está limpiando
su propio estado (clases `sortable-ghost`/`sortable-chosen`, captura de
puntero) del gesto nativo `pointerup` que originó el drop.

Montar un overlay ahí compite por el mismo ciclo de eventos del navegador
que SortableJS necesita para terminar su limpieza — la misma familia de
problema que el freeze de overlays de Reka ya corregido antes (ver
`resources/js/app.ts`/`CLAUDE.md`), solo que disparado por una librería de
drag & drop en vez de por un `DropdownMenu`/`Select`.

No se pudo reproducir el freeze en vivo en este entorno (sin navegador
disponible para el agente), pero el patrón de código —montar un overlay
reactivo dentro de un callback síncrono de SortableJS— es una causa
conocida y suficiente por sí sola; el fix aplicado es seguro
independientemente de si esa era la única causa.

## Fix aplicado

`resources/js/composables/useKanbanTransition.ts` (nuevo, sin reglas de
negocio) expone `onStart`/`onEnd`/`asentarAntesDeConfirmar()`. Ambos
tableros ahora:

1. Escuchan `@start`/`@end` de `VueDraggable`, **nunca** `@add`/`@remove`.
2. Al terminar (`@end`), leen `evento.data` (item arrastrado) y
   `evento.from.dataset.estado` / `evento.to.dataset.estado` (origen/destino
   reales de SortableJS, vía `:data-estado` en cada `VueDraggable`).
3. Reconstruyen `columnas` **de inmediato** desde la fuente canónica
   (`props.solicitudes` / `props.vacantes`) — deshace visualmente el
   arrastre antes de mostrar cualquier UI.
4. Esperan un `await nextTick()` (no un `setTimeout`) a que Vue/Sortable
   terminen su ciclo.
5. Solo entonces abren la confirmación correspondiente.
6. Al confirmar, `router.patch`/`router.put` escribe el cambio real;
   Inertia refresca los props y el `watch` de cada página reconstruye el
   tablero con el estado ya confirmado por el backend.
7. Cancelar la confirmación no necesita revertir nada (el tablero ya estaba
   en su estado canónico desde el paso 3); un error 422/403 tampoco, porque
   la tarjeta nunca llegó a "moverse" de verdad.

Mientras hay una confirmación abierta o una request en curso
(`useKanbanTransition().processing`), cada `VueDraggable` recibe
`:disabled="true"` — no se puede iniciar un segundo drag hasta resolver el
pendiente.

## Drag handle

Antes toda la tarjeta era arrastrable, lo que competía con los `Link`,
botones y el `@click` de editar (Vacantes). Ahora cada tarjeta tiene un
`GripVertical` con la clase `kanban-drag-handle`, y `VueDraggable` usa
`handle=".kanban-drag-handle"` + `filter="a, button, input, textarea,
select"`. Solo ese ícono inicia un drag; clic en el resto de la tarjeta
(Vacantes) sigue abriendo edición, y los links/botones internos nunca
inician un drag a medias.

## Vacantes: reglas que se mantienen

- Soltar en "Cubierta" nunca hace un `PUT` directo: abre
  `CubrirVacanteDialog` (cobertura real) después de `@end`. Cancelar el
  diálogo no cambia nada.
- Soltar en "Cancelada" pide motivo obligatorio con `PeopleConfirmDialog`
  (ya no `SweetAlert2`, para no mezclar dos sistemas de overlay) después de
  `@end`.
- Cualquier otra transición pide una confirmación ligera
  (`PeopleConfirmDialog`) antes de escribir nada. El backend sigue siendo la
  autoridad final vía `EstadoVacante::puedeTransicionarA()`.

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
`@add`/`@remove` en vez de `@end`.
