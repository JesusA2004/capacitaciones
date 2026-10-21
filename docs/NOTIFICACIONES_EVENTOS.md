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
| Corrección de datos solicitada (`actualizacion_datos`, bancaria, reposición; incluye datos médicos) | Colaborador | "Cambio de datos enviado" | «Corrección de datos por revisar»: qué datos, **nunca valores** (médicos = «Datos médicos (confidencial)»); ruteo `solicitud_creada` + pendiente `correccion_por_revisar` | — | `SolicitudInterna` · `rh_solicitud` |
| RH pide más información de una corrección | RH | — | Colaborador: pendiente `correccion_por_responder` | Colaborador: «Recursos Humanos necesita más información» + comentario de RH | `SolicitudInterna` · `solicitud` |
| El colaborador responde la corrección | Colaborador | "Respuesta enviada" | Responsables (`solicitud_creada`) + quien pidió la información: «Corrección respondida por revisar» (aviso nuevo, ocurrencia por respuesta) | — | `SolicitudInterna` · `rh_solicitud` |
| Corrección aprobada / rechazada | RH | — | — | Colaborador: «Corrección aprobada» (expediente actualizado) / «Corrección rechazada. Motivo: …» | `SolicitudInterna` · `solicitud` |
| Documento del expediente rechazado / por corregir | RH | — | — | Colaborador: nombre del documento + motivo + «vuelve a subirlo» | `EmployeeDocument` · `documento` |
| Préstamo disponible (cumple 6 meses desde `fecha_ingreso`, fecha del servidor) | Scheduler `prestamos:avisar-disponibles` (08:15) | — | — | **Solo** el colaborador, una vez en la vida (ocurrencia fija); ventana de 7 días para no avisar de golpe a la plantilla antigua | `Colaborador` · `prestamo_disponible` → Mis solicitudes |
| Acta administrativa registrada | Gerente / RH | — | RH con `actas.gestionar` y alcance (`acta_registrada`), sin quien la levantó ni la persona; sin los hechos | — | `ActaAdministrativa` |
| Responsiva de activos generada | RH / gerente | — | — | Colaborador que recibe el activo (`responsiva_registrada`) | `Responsiva` |
| Vacaciones solicitadas | Colaborador | "Vacaciones solicitadas" | Responsables `rh.vacaciones.aprobar` | Colaborador al resolver | `SolicitudVacaciones` · `rh_vacaciones`/`vacaciones` |
| Documento de expediente subido | Colaborador o RH | "Documento enviado" | `rh.documentos.ver` con alcance | Colaborador: aprobado/rechazado | `EmployeeDocument` · `rh_documento`/`documento` |
| Incorporación completa | Sistema | — | `rh.incorporaciones.ver` | Colaborador: aprobada/rechazada | `Colaborador` · `rh_incorporacion`/`incorporacion` |
| Cambio de foto | Colaborador | (vía NotificadorRh) | `expedientes.revisar` | Colaborador | `CambioFotoPerfil` |
| Candidato / entrevista / psicométricos / socioeconómico / referencias / contratación | RH / gerente | — | Ruteo del evento de la etapa | — | `Candidato` |
| Intervención | Gerente | — | Regional / Dirección Comercial | Gerente | `IntervencionCandidato` → `Candidato` |
| Alta / contrato / documentos por firma | RH | — | Colaborador (`documento_firma_pendiente`), RH | Colaborador | `GeneratedDocument` |
| Alta de colaborador (cuenta, accesos, equipo) | RH | — | RH + **Sistemas** + gerente del colaborador (`colaborador_alta`). Nunca Regional ni toda la plantilla | — | `Colaborador` · `ver_expediente` |
| Expediente completo (generar paquete de contratación) | RH / Scheduler | — | Quien puede generar documentos con alcance sobre la persona (`expediente_completo`, `documentos_laborales.generar`) | — | `Colaborador` |
| Baja de colaborador (retirar accesos, cuentas, equipo) | RH | — | RH + **Sistemas** (`colaborador_baja`). Nunca toda la plantilla. Sin el motivo de la baja (dato sensible) | — | `Colaborador` · `ver_expediente` |
| Inducción / onboarding / activos | Sistema / RH | — | Colaborador, RH (`onboarding.gestionar`) | Colaborador | `OnboardingAvance` |
| Evaluación de periodo de prueba | Sistema / jefe | — | Evaluador, RH (`evaluaciones.autorizar`) | Colaborador | `EvaluacionPeriodoPrueba` |
| Excepción de Dirección General | RH | — | `excepciones.autorizar` | RH | `SolicitudExcepcionPeriodoPrueba` |
| Préstamo autorizado | RH / Dirección | — | — | Colaborador (`prestamo_autorizado`); el genérico «Actualización de tu solicitud» ya no se duplica | `Prestamo` |
| Baja registrada (directa, cierre laboral aplicado) | Gerente / RH | "Baja registrada" (accesos suspendidos) | RH + Regional + **Sistemas** (`cierre_baja_solicitada`): quién la dio, a quién (puesto · sucursal), forma · causal y último día; solo para enterarse (RH puede rehabilitar accesos, Sistemas retira cuentas y equipo). **Sin solicitud ni aprobación**; nunca genera «solicitud por revisar» | — | `CierreLaboral` |
| Alertas del módulo de bajas (docs/BAJAS_Y_CAUSALES.md) | Gerente / RH / Jurídico | — | Jurídico (`baja_revision_juridico` al registrar una causal que lo requiere, `baja_alerta_juridico`), RH (`baja_por_revisar` cuando Jurídico aprueba o devuelve), Contraloría (`baja_alerta_contraloria`, RE-02/RE-03), RH con correo (`baja_alerta_clima_laboral`, RV-04), RH (`baja_rotacion_temprana`) | Quien la registró (`baja_resuelta`: cierre, no procede) | `Baja` · `baja` |
| Negativa a firmar/recibir la baja | RH | — | RH que gestiona el cierre, para generar el acta de negativa con dos testigos (`cierre_negativa_firma`, `cierres.gestionar`) | — | `CierreLaboral` |
| Finiquito / documentos de la baja por firmar | RH | — | Colaborador (`documento_firma_pendiente`) y RH que opera el documento | Colaborador | `GeneratedDocument` / `CierreLaboral` |
| Acta administrativa | Gerente / RH | — | RH (`actas.gestionar`) | — | `ActaAdministrativa` |
| Recibo de nómina | RH | — | — | Colaborador (si `features.recibos_colaborador`) | `ReciboNomina` |
| Contrato por vencer / expediente incompleto / datos faltantes | Scheduler | — | RH / colaborador | — | `ContratoLaboral` / `Colaborador` |
| Aviso de RH (con acción) | RH | El emisor también recibe el aviso si eligió "Todos" | Destinatarios del aviso | — | `aviso_rh` |

Al abrir un aviso viejo, `DestinoNotificacionService` consulta el estado actual
del recurso y responde "Ya fue atendida" en vez de mandar a una pantalla rota.

### Flujo de baja vigente vs. legacy (histórico)

El flujo de baja **vigente** es: el *gerente* (su personal, por alcance) o
*RH* **registra la baja en «Bajas» y queda aplicada de inmediato** (accesos
suspendidos; aviso `cierre_baja_solicitada` a RH, regionales y Sistemas) → *documentos* de
la causa (generados → impresos → firmados físicamente; si el colaborador se
niega, *acta de negativa con dos testigos*, `cierre_negativa_firma`) →
*finiquito* (RH calcula/ajusta) → *registrar entrega del finiquito* → *cierre*.
**No hay preautorización ni autorización posterior de RH**, ni devolver/reenviar
la baja, ni programar pago/cita. La solicitud interna de baja que respalda el
trámite (folio, historial) **no** dispara `solicitud_creada` (ni el flujo
normal ni el reconciliador). Al **ejecutarse** la baja, RH y **Sistemas**
reciben `colaborador_baja` para retirar accesos, cuentas y equipo. El alta
dispara el espejo: `colaborador_alta` a RH, Sistemas y el gerente del
colaborador. Ninguno de estos eventos notifica a toda la plantilla.

Los siguientes eventos/tareas son **LEGACY (histórico)** del flujo anterior y
**nunca se generan para un cierre nuevo**. Sus claves se conservan en
`config/configuracion_sistema.php` (marcadas `'legacy' => true`, etiqueta
«Legacy: …») y en `App\Enums\TipoTarea` (`esLegacy()`) solo para leer avisos y
registros previos. Candados: `NotificadorRhService::notificarEvento()` ignora un
evento legacy, `TareaService::abrir()` se niega a abrir un tipo legacy y el
reconciliador (`notificaciones:reconciliar`) cierra cualquier pendiente legacy
que siga abierto sin reponer su aviso. Las migraciones
`2026_10_16_120000_baja_inmediata_sin_autorizacion_rh` y
`2026_10_16_140000_cierre_retira_avisos_de_autorizacion_y_pago` ya habían
retirado las tareas y avisos abiertos:

- **Autorización de RH de la baja** (`cierre_autorizacion_rh`): LEGACY.
- **Preautorización de la baja** (`cierre_preautorizacion`): LEGACY (tampoco
  existe ya «devolver para corregir»).
- **Pago de finiquito por programar** (`pago_por_programar`) y **cita de
  finiquito** (`cita_finiquito`): LEGACY. Los pasos del flujo anterior
  (programar pago, cita, finiquito firmado, confirmar pago) solo proceden con
  `features.cierre_flujo_legacy` (`CIERRE_FLUJO_LEGACY`, apagado por defecto);
  sin ella `CierreLaboralService` los rechaza (web y API).

## Diagnóstico

```bash
php artisan people:diagnostico-notificaciones   # colas, Reverb, Expo, fallas (sin secretos)
php artisan people:probar-notificacion --user=ID # aviso de PRUEBA por los 3 canales
php artisan notificaciones:reconciliar           # red de seguridad (también corre cada minuto)
```

En web: Administración → **Notificaciones** (permiso `notificaciones.ver_todas`):
tus avisos con «Marcar como leída», «Marcar todas como leídas» e «Ir» (abre el
registro), y la consulta de solo lectura «De todo el equipo». El estado de colas,
Reverb y Expo se revisa con `people:diagnostico-notificaciones`. La campana **siempre** muestra solo las notificaciones propias, incluso
para super_admin.
