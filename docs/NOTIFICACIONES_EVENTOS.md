# Notificaciones por evento (matriz canónica)

Regla: **todo evento que deja un pendiente para alguien genera, desde el backend
y en un solo flujo**:

1. notificación en base de datos (campana web y bandeja de la app);
2. broadcast por Reverb (tiempo real web) — mismo job encolado;
3. push de Expo a cada dispositivo activo del destinatario.

Todo pasa por `App\Services\Notificaciones\NotificacionEventosService`
(directamente o vía `App\Services\Tareas\NotificadorRhService`, que ya delega en
él). Ningún controller notifica por su cuenta.

## Tres conceptos que no se mezclan

| Categoría | Para quién | Ejemplo | Método |
|---|---|---|---|
| Confirmación | Quien hizo la acción, **aunque sea el mismo usuario** | "Solicitud enviada" | `confirmarAlActor()` |
| Tarea (pendiente) | Quien debe resolver | "Nueva solicitud por revisar" | `crearPendiente()` / `avisarSiguienteResponsable()` |
| Resultado | El interesado cuando se resolvió | "Tu solicitud fue aprobada" | `avisarResultado()` |

Si el actor también es responsable (`registrarConPendiente()`), recibe **un**
aviso combinado ("Solicitud enviada y pendiente de tu resolución"): nunca dos
iguales ni cero.

**Visibilidad ≠ destinatario.** Poder ver todas las notificaciones
(`notificaciones.ver_todas`, Sistemas/RH/super_admin) no hace que alguien reciba
avisos: la tarea solo llega a quien tiene el permiso de **acción** y alcance
sobre la persona (o la regla de ruteo de Configuración → Notificaciones).

## Idempotencia

Cada entrega reclama una clave única en `notificacion_entregas`:
`sha1(destinatario | categoría | evento | TipoRecurso#id | acción | ocurrencia)`.
La ocurrencia distingue transiciones legítimas repetidas (deriva del texto, el
`updated_at` del recurso y el día; el reconciliador usa `tarea{id}`). Misma
transición = una notificación; un reintento del worker reintenta el job ya
encolado (no vuelve a crear la notificación).

## Entrega garantizada

- Colas: `notifications` (dedicada, worker `people-notifications`) separada de
  `default` (PDF, extracción, video — worker `people-worker`).
- `DB_QUEUE_AFTER_COMMIT=true` (por defecto): nada sale antes del commit.
- Worker caído: los jobs esperan en `jobs` y salen al levantar Supervisor.
- Red de seguridad: `php artisan notificaciones:reconciliar` (cada minuto) busca
  pendientes abiertos de las últimas 48 h sin aviso para su responsable y crea el
  faltante una sola vez.
- Reverb caído: base de datos y push siguen; la campana web consulta cada 30 s.
- Expo caído: la acción no falla; el push se reintenta y, si se agota, queda en
  `failed_jobs` y en el diagnóstico.

## Matriz

| Evento | Actor | Confirmación (actor) | Tarea (destinatario) | Resultado | Deep link (`related_type`/`type`) |
|---|---|---|---|---|---|
| Solicitud creada (permiso, préstamo, constancia, cambio de datos, baja…) | Colaborador / gerente / RH | "Solicitud enviada" · "Préstamo solicitado" · "Cambio de datos enviado" · "Baja solicitada" | Ruteo `solicitud_creada` (RH/aprobador, sin el solicitante) | — | `SolicitudInterna` · `rh_solicitud` |
| Visto bueno (gerente → regional) | Sistema | — | Aprobador del nivel (`solicitud_visto_bueno`) | — | `SolicitudInterna` |
| Solicitud revisada/aprobada/rechazada/corrección | Aprobador | — | Siguiente nivel si aplica | Solicitante (`solicitud`) | `SolicitudInterna` · `solicitud` |
| Vacaciones solicitadas | Colaborador | "Vacaciones solicitadas" | Responsables `rh.vacaciones.aprobar` | Colaborador al resolver | `SolicitudVacaciones` · `rh_vacaciones`/`vacaciones` |
| Documento de expediente subido | Colaborador o RH | "Documento enviado" | `rh.documentos.ver` con alcance | Colaborador: aprobado/rechazado | `EmployeeDocument` · `rh_documento`/`documento` |
| Incorporación completa | Sistema | — | `rh.incorporaciones.ver` | Colaborador: aprobada/rechazada | `Colaborador` · `rh_incorporacion`/`incorporacion` |
| Cambio de foto | Colaborador | (vía NotificadorRh) | `expedientes.revisar` | Colaborador | `CambioFotoPerfil` |
| Candidato / entrevista / psicométricos / socioeconómico / referencias / contratación | RH / gerente | — | Ruteo del evento de la etapa | — | `Candidato` |
| Intervención | Gerente | — | Regional / Dirección Comercial | Gerente | `IntervencionCandidato` → `Candidato` |
| Alta / contrato / documentos por firma | RH | — | Colaborador (`documento_firma_pendiente`), RH | Colaborador | `GeneratedDocument` |
| Inducción / onboarding / activos | Sistema / RH | — | Colaborador, RH (`onboarding.gestionar`) | Colaborador | `OnboardingAvance` |
| Evaluación de periodo de prueba | Sistema / jefe | — | Evaluador, RH (`evaluaciones.autorizar`) | Colaborador | `EvaluacionPeriodoPrueba` |
| Excepción de Dirección General | RH | — | `excepciones.autorizar` | RH | `SolicitudExcepcionPeriodoPrueba` |
| Préstamo autorizado | RH / Dirección | — | — | Colaborador (`prestamo_autorizado`) | `Prestamo` |
| Baja (cierre laboral) | Gerente / RH | "Baja solicitada" | Preautorizador, RH (`cierre_autorizacion_rh`), pago, cita | — | `CierreLaboral` |
| Finiquito | RH | — | `cierres.programar_pago` | — | `CierreLaboral` |
| Acta administrativa | Gerente / RH | — | RH (`actas.gestionar`) | — | `ActaAdministrativa` |
| Recibo de nómina | RH | — | — | Colaborador (si `features.recibos_colaborador`) | `ReciboNomina` |
| Contrato por vencer / expediente incompleto / datos faltantes | Scheduler | — | RH / colaborador | — | `ContratoLaboral` / `Colaborador` |
| Aviso de RH (con acción) | RH | El emisor también recibe el aviso si eligió "Todos" | Destinatarios del aviso | — | `aviso_rh` |

Al abrir un aviso viejo, `DestinoNotificacionService` consulta el estado actual
del recurso y responde "Ya fue atendida" en vez de mandar a una pantalla rota.

## Diagnóstico

```bash
php artisan people:diagnostico-notificaciones   # colas, Reverb, Expo, fallas (sin secretos)
php artisan people:probar-notificacion --user=ID # aviso de PRUEBA por los 3 canales
php artisan notificaciones:reconciliar           # red de seguridad (también corre cada minuto)
```

En web: Administración → **Notificaciones del sistema** (permiso
`notificaciones.ver_todas`), con tarjetas de estado (cola general, notificaciones,
Reverb, Expo, trabajos fallidos) y consulta de solo lectura de los avisos de
todos. La campana **siempre** muestra solo las notificaciones propias, incluso
para super_admin.
