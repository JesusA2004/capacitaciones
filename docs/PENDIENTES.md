# Mis pendientes

Bandeja «¿Qué me toca hacer?» (Inicio → Pendientes, `rh.pendientes.index`) y su
espejo en la API (`GET /api/v1/tareas`). Fuente única: tabla `tareas_rh`
(`App\Models\TareaRh`, tipos en `App\Enums\TipoTarea`), servicio
`App\Services\Tareas\TareaService`.

## Un solo número

La lista (todas las etapas, también vacaciones/permisos/préstamos y
«sin jefe»), el número grande de la pantalla y el contador del sidebar son la
**misma consulta**: pendientes abiertos que le llegan a la persona, sin los que
quitó de su lista (`TareaService::totalAbiertas`, caché de 30 s que se olvida al
descartar/restaurar/marcar hecho). Antes el contador del sidebar contaba
aprobaciones de solicitudes y no cuadraba con la lista.

## Se quitan solos

1. Cada flujo resuelve su pendiente al completar la acción
   (`TareaService::resolver`, ya existía en cada servicio).
2. `php artisan pendientes:reconciliar` (scheduler cada 5 min, y al abrir
   «Mis pendientes» como máximo cada 2 min) revisa **todos** los abiertos contra
   el estado real (`ReconciliacionPendientesService`) y cierra, sin actor y con
   el motivo en `datos.cierre_automatico`:
   - el registro ya no existe (borrado);
   - la persona ya fue dada de baja (salvo cierre, finiquito, reingreso y
     documentos de salida);
   - el registro ya avanzó: candidato con proceso terminado, intervención,
     solicitud, cierre, reingreso o excepción resueltos; evaluación ya
     capturada/autorizada; contrato no vigente; documento en otro paso del
     flujo (firmado físicamente = concluido); alta fuera de esa etapa.

Nunca abre pendientes ni avisa; solo cierra. La red de avisos sigue siendo
`notificaciones:reconciliar` (docs/NOTIFICACIONES_EVENTOS.md).

## Acciones por pendiente

| Acción | Efecto | Quién |
|---|---|---|
| Resolver / Ir | Abre la pantalla donde se atiende (`PendienteController::urlDe`) | Destinatario |
| Quitar de mi lista (motivo opcional) | Solo para esa persona (`tarea_rh_descartes`); a los demás les sigue llegando | Destinatario |
| Restaurar | Lo regresa a su lista (pestaña «Quitados») | Quien lo quitó |
| Marcar como hecho | Cierra el pendiente para todos | Solo informativos (`TipoTarea::esInformativo`) o asignados a su propia cuenta (`TareaRhPolicy::marcarHecha`) |

Si un pendiente quitado se vuelve a generar por un evento nuevo, es otra fila
de `tareas_rh` y **reaparece**. Mientras sea el mismo pendiente abierto, sigue
quitado.

API (aditivo, sin cambiar contratos): `POST /api/v1/tareas/{id}/descartar`
(`motivo` opcional), `POST /api/v1/tareas/{id}/restaurar`, `estado=descartadas`
en el índice, `conteos.descartadas`, y en cada tarea `informativo` y
`semaforo` (`verde`/`ambar`/`rojo`, config `pendientes.semaforo`).

## Pendientes por rol

Cada tipo vigente tiene su permiso `pendientes.recibir_<tipo>` (módulo
«Pendientes que recibe», `App\Support\CatalogoPermisosPendientes`). Se editan en
**Roles y permisos → Pendientes** (tarjetas por tipo con su interruptor; se
guarda con «Guardar» como el resto de la matriz).

- Solo filtra los pendientes asignados **por permiso**. Lo asignado a una
  cuenta por nombre (su firma, su evaluación, la entrevista que aprueba)
  siempre llega.
- Apagarlo lo quita de la lista, del contador y de los destinatarios de su
  aviso (`TareaService::esDestinatario`/`destinatarios`), en web y API.
- Encenderlo no da permiso de actuar: si el rol no tiene el permiso de acción
  con que se asigna, la tarjeta lo advierte.
- Despliegue: la migración `2026_11_18_500100_otorga_permisos_recibir_pendientes`
  crea los permisos y los da a **todos** los roles existentes (nadie deja de
  recibir nada); el seeder los incluye en la base de cada rol. Un rol creado a
  mano después arranca sin ellos: hay que encenderlos en la pestaña.
- Mientras el permiso no exista en la base, el tipo se recibe como antes.
