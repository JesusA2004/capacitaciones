# Solicitudes internas

Trámites de RH del día a día que **no son vacaciones** (esas tienen su propio módulo completo, ver `docs/VACACIONES.md` — no se duplicó esa lógica): permisos con/sin goce, incapacidades, constancias laborales, actualización de datos/bancaria, reposición documental, préstamo interno y solicitudes generales.

## Modelo de datos

- `solicitudes_internas` — folio (`SOL-000001`, correlativo), `user_id`, `tipo`, `estado`, `fecha_inicio`/`fecha_fin` (opcionales, solo para permisos/incapacidad), `motivo`, `observaciones`, `revisado_por`/`revisado_en`, `motivo_rechazo`, snapshot de `empresa_id`/`sucursal_id` al momento de crearla (para que los reportes no dependan de que el colaborador siga en la misma sucursal).
- `solicitud_interna_documentos` — adjuntos, mismo patrón que `employee_documents` (disco NAS, nombre interno UUID, nunca se expone la ruta física).
- `solicitud_interna_historial` — timeline: una fila por cada cambio de estado o comentario.
- `generated_documents.solicitud_id` — ya existía como columna sin FK (se creó antes que esta tabla); esta fase le agrega la constraint real, para poder generar un formato precargado (ver `docs/PLANTILLAS_FORMATOS.md`) asociado a una solicitud.

`App\Enums\TipoSolicitudInterna` / `App\Enums\EstadoSolicitudInterna` — catálogo completo y reglas (`usaRangoFechas()`, `esFinal()`, `puedeCancelarse()`).

## Estados

`creada` → `enviada` → `en_revision` → `aprobada` | `rechazada` | `requiere_correccion` (vuelve a poder revisarse) → `cerrada`. El colaborador puede `cancelar` mientras no esté en un estado final.

## Servicio único

`App\Services\Solicitudes\SolicitudesService` — toda la lógica de negocio (crear, listar con alcance, cambiar de estado, adjuntar documento, generar folio). La reutilizan:

- `App\Http\Controllers\Solicitudes\SolicitudInternaController` — vista del colaborador (`/solicitudes`).
- `App\Http\Controllers\Rh\SolicitudController` — revisión de RH/gerencia (`/rh/solicitudes`).
- `App\Http\Controllers\Api\V1\SolicitudController` y `Api\V1\ColaboradorController` — API móvil.

Ninguno de los tres calcula nada por su cuenta (ver `docs/ARQUITECTURA_SERVICES.md`).

## Alcance y permisos

`SolicitudInternaPolicy` reutiliza `AlcanceOrganizacionalService` (mismo criterio que `SolicitudVacacionesPolicy`/`EmployeeDocumentPolicy`): un colaborador solo ve/cancela las suyas; `jefe_directo` ve las de sus subordinados directos; `gerente`/`gerente_regional`/`coordinadora`/`rh_admin`/etc. según su alcance de sucursal(es) o global. Permisos: `solicitudes.ver`, `.crear`, `.revisar`, `.aprobar`, `.rechazar`, `.cerrar` (ya sembrados desde el checkpoint de roles RH, ver `docs/ROLES_PERMISOS_RH.md`).

## Rutas

Web:

```
GET  /solicitudes                          Mis solicitudes (colaborador)
POST /solicitudes
GET  /solicitudes/{solicitud}
POST /solicitudes/{solicitud}/cancelar
POST /solicitudes/{solicitud}/documentos

GET  /rh/solicitudes                       Revisión (RH/gerencia)
GET  /rh/solicitudes/{solicitud}
POST /rh/solicitudes/{solicitud}/revisar
POST /rh/solicitudes/{solicitud}/requerir-correccion
POST /rh/solicitudes/{solicitud}/aprobar
POST /rh/solicitudes/{solicitud}/rechazar
POST /rh/solicitudes/{solicitud}/cerrar
```

API (`/api/v1`, ver `docs/API_MOVIL.md`): `GET|POST /solicitudes`, `GET /solicitudes/{solicitud}` — siempre acotado al colaborador autenticado.

## Pantallas

- `Solicitudes/Index.vue` / `Solicitudes/Show.vue` — colaborador: crear, ver estado, timeline, adjuntar documentos, cancelar.
- `Rh/Solicitudes/Index.vue` (con filtros por estado/tipo/empresa/sucursal) / `Rh/Solicitudes/Show.vue` — revisar, pedir corrección, aprobar, rechazar (con motivo), cerrar.

## Pendiente (fuera de esta fase)

- Generar formato precargado directamente desde la vista de la solicitud (la relación `GeneratedDocument::solicitud()` ya existe; falta el botón/acción en `Rh/Solicitudes/Show.vue` que llame a `PlantillaDocumentoService` con el `solicitud_id`).
- Préstamo interno: el tipo existe en el enum pero no tiene reglas de negocio propias (montos, plazos) — se activará cuando el encargo lo pida.
