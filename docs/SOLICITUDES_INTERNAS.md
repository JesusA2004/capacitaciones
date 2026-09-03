# Solicitudes internas

Trámites de RH del día a día que **no son vacaciones** (esas tienen su propio módulo completo, ver `docs/VACACIONES.md` — no se duplicó esa lógica): permisos con/sin goce, incapacidades, constancias laborales, actualización de datos/bancaria, reposición documental, préstamo interno y solicitudes generales.

## Modelo de datos

- `solicitudes_internas` — folio (`SOL-000001`, correlativo), `user_id`, `tipo`, `estado`, `fecha_inicio`/`fecha_fin` (opcionales, solo para permisos/incapacidad), `motivo`, `observaciones`, `revisado_por`/`revisado_en`, `motivo_rechazo`, snapshot de `empresa_id`/`sucursal_id` al momento de crearla (para que los reportes no dependan de que el colaborador siga en la misma sucursal).
- `solicitud_interna_documentos` — adjuntos, mismo patrón que `employee_documents` (disco NAS, nombre interno UUID, nunca se expone la ruta física).
- `solicitud_interna_historial` — timeline: una fila por cada cambio de estado o comentario.
- `generated_documents.solicitud_id` — FK real (nullable): un formato precargado (ver `docs/PLANTILLAS_FORMATOS.md`) puede estar asociado a una solicitud, generado con el botón "Generar formato" en `Rh/Solicitudes/Show.vue`.

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
GET  /rh/solicitudes/exportar-excel        Exportación respetando filtros activos
GET  /rh/solicitudes/exportar-pdf
GET  /rh/solicitudes/{solicitud}
POST /rh/solicitudes/{solicitud}/revisar
POST /rh/solicitudes/{solicitud}/requerir-correccion
POST /rh/solicitudes/{solicitud}/aprobar
POST /rh/solicitudes/{solicitud}/rechazar
POST /rh/solicitudes/{solicitud}/cerrar

POST /rh/formatos                          Generar formato (con solicitud_id opcional)
POST /rh/formatos/{documento}/subir-firmado
```

API (`/api/v1`, ver `docs/API_MOVIL.md`): `GET|POST /solicitudes`, `GET /solicitudes/{solicitud}` — siempre acotado al colaborador autenticado.

## Pantallas

- `Solicitudes/Index.vue` / `Solicitudes/Show.vue` — colaborador: crear, ver estado, timeline, adjuntar documentos, cancelar, ver (sin descargar) los formatos que RH generó para su solicitud.
- `Rh/Solicitudes/Index.vue` (filtros: estado, tipo, empresa, sucursal, departamento, puesto, responsable, rango de fechas, buscador; exportación Excel/PDF) / `Rh/Solicitudes/Show.vue` — revisar, pedir corrección, aprobar, rechazar (con motivo), cerrar, generar formato precargado y subir el firmado (ver `docs/PLANTILLAS_FORMATOS.md`).

## Pendiente (fuera de esta fase)

- Préstamo interno: el tipo existe en el enum pero no tiene reglas de negocio propias (montos, plazos) — se activará cuando el encargo lo pida.
- El colaborador no puede descargar directamente los formatos generados para su solicitud (solo los ve listados); la descarga vive bajo el gate de RH (`plantillas.ver`) — decisión deliberada de esta fase, no un bug.
