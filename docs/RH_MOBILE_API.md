# RH móvil (API v1)

Endpoints para que RH/aprobadores (gerentes, jefes directos, etc.) trabajen desde la app móvil: dashboard, bandeja unificada de pendientes, y CRUD de revisión de solicitudes/vacaciones/documentos/incorporaciones/colaboradores. Prefijo `/api/v1/rh`, todos requieren `auth:sanctum` + el permiso correspondiente (sección "Permisos" abajo).

Ningún endpoint decide permisos, alcance ni flujo en el cliente: todo se calcula en el backend a partir de roles/permisos (Spatie) y `App\Services\AlcanceOrganizacionalService` (empresa/sucursal/jefe directo) — la app solo pinta lo que el backend le manda en `acciones_permitidas`/`workflow`.

## Reutilización (sección 8 del encargo: "no duplicar lógica")

| Endpoint | Service que hace el trabajo real |
|---|---|
| `rh/dashboard` | `App\Services\RhMobile\RhDashboardService` (agrega `RhPendientesService`) |
| `rh/pendientes` | `App\Services\RhMobile\RhPendientesService` |
| `rh/solicitudes/*` | `App\Services\Solicitudes\SolicitudesService` (mismo que usa `Rh\SolicitudController` web) |
| `rh/vacaciones/*` | `App\Services\Vacaciones\VacacionesService` (mismo que usa `Rh\VacacionesController` web, refactorizado para no duplicar `aprobar`/`rechazar`) |
| `rh/documentos/*` | `App\Services\Incorporacion\IncorporacionService::aprobarDocumento/rechazarDocumento` (mismo que `Api\V1\Rh\ExpedienteController`) |
| `rh/incorporaciones/*` | `App\Services\Incorporacion\IncorporacionService::aprobarIncorporacion/rechazarIncorporacion` (mismo que `Api\V1\Rh\ExpedienteController::aprobarIncorporacion/rechazarIncorporacion`, que se conservan intactos para compatibilidad) |
| `rh/colaboradores/*` | `App\Services\AlcanceOrganizacionalService` directo (datos básicos, no expediente) |

`App\Services\RhMobile\WorkflowService` centraliza `acciones_permitidas`/`workflow` para los 4 tipos de recurso (primera versión, de una sola etapa "rh"; extensible, ver comentario en la clase).

## Dashboard

```
GET /api/v1/rh/dashboard        permiso: rh.mobile.dashboard.ver
```

```json
{
  "resumen": { "pendientes_total": 18, "solicitudes": 7, "vacaciones": 4, "documentos": 5, "incorporaciones": 2 },
  "urgentes": [ /* primeros 5 de la bandeja, mismo shape que rh/pendientes */ ],
  "recientes": [ /* primeros 10 */ ]
}
```

## Bandeja unificada

```
GET /api/v1/rh/pendientes?tipo=&q=&page=&per_page=&sucursal_id=&departamento_id=      permiso: rh.pendientes.ver
```

`tipo`: `solicitud|vacaciones|documento|incorporacion|todos` (default `todos`). Cada tipo solo aparece si el usuario tiene el permiso `rh.<tipo>.ver` correspondiente (un `rh_auxiliar` sin `rh.solicitudes.aprobar` sigue viendo solicitudes en la bandeja, solo sin la acción `aprobar`).

```json
{
  "data": [
    {
      "id": "solicitud:184",
      "tipo": "solicitud",
      "resource_id": 184,
      "prioridad": "normal",
      "titulo": "Permiso con goce de sueldo",
      "colaborador": { "id": 52, "nombre": "Jesús Pérez", "numero_empleado": "ML-0052", "puesto": "Analista", "sucursal": "Corporativo" },
      "resumen": "Solicita permiso...",
      "creado_en": "2026-09-09T17:40:00-06:00",
      "acciones_permitidas": ["ver", "aprobar", "rechazar"]
    }
  ],
  "meta": { "current_page": 1, "per_page": 15, "total": 18, "solicitudes": 7, "vacaciones": 4, "documentos": 5, "incorporaciones": 2 }
}
```

`prioridad` siempre `"normal"` por ahora (no hay un cálculo de urgencia distinto de "está pendiente" — campo reservado, ver "Pendiente" al final).

## Solicitudes

```
GET  /api/v1/rh/solicitudes?estado=&tipo=&sucursal_id=&empresa_id=&departamento_id=&q=      rh.solicitudes.ver
GET  /api/v1/rh/solicitudes/{solicitud}                                                     rh.solicitudes.detalle
POST /api/v1/rh/solicitudes/{solicitud}/aprobar     { comentario? }                         rh.solicitudes.aprobar
POST /api/v1/rh/solicitudes/{solicitud}/rechazar    { motivo }                              rh.solicitudes.rechazar
POST /api/v1/rh/solicitudes/{solicitud}/correccion  { motivo }                               rh.solicitudes.correccion
```

Detalle:

```json
{
  "data": {
    "id": 184, "folio": "SOL-000184", "tipo": "permiso_con_goce", "estado": "enviada",
    "colaborador": { "id": 52, "nombre": "...", "numero_empleado": "...", "puesto": "...", "sucursal": "..." },
    "fecha_inicio": "2026-09-10", "fecha_fin": "2026-09-10", "motivo": "...", "motivo_rechazo": null,
    "adjuntos": [{ "id": 3, "nombre": "constancia.pdf" }],
    "acciones_permitidas": ["ver", "aprobar", "rechazar", "solicitar_correccion"],
    "workflow": { "estado": "enviada", "etapa_actual": { "clave": "rh", "nombre": "Revisión RH" }, "progreso": { "actual": 0, "total": 1 }, "flujo": [...], "siguiente_etapa": null },
    "historial": [{ "accion": "enviada", "comentario": null, "usuario": "...", "fecha": "..." }]
  }
}
```

Errores: `403` sin el permiso o fuera de alcance organizacional (`AlcanceOrganizacionalService::puedeVerUsuario`), `404` si la solicitud no existe o el colaborador dueño está fuera de alcance, `422` si la solicitud ya está en un estado final (`aprobada`/`rechazada`/`cancelada`/`cerrada`) — la transición no aplica.

## Vacaciones

```
GET  /api/v1/rh/vacaciones?estado=&sucursal_id=&empresa_id=&q=      rh.vacaciones.ver
GET  /api/v1/rh/vacaciones/{vacacion}                                rh.vacaciones.detalle
POST /api/v1/rh/vacaciones/{vacacion}/aprobar                        rh.vacaciones.aprobar
POST /api/v1/rh/vacaciones/{vacacion}/rechazar  { motivo }            rh.vacaciones.rechazar
```

Detalle incluye `saldo_disponible` (recalculado con `VacacionesService::saldo()`, no una copia guardada). `422` si la vacación ya no está `pendiente`.

## Documentos

```
GET  /api/v1/rh/documentos?estado=&sucursal_id=&q=&per_page=      rh.documentos.ver
GET  /api/v1/rh/documentos/{documento}                             rh.documentos.detalle
GET  /api/v1/rh/documentos/{documento}/ver                         rh.documentos.ver_archivo (streaming, nunca expone disk/path)
POST /api/v1/rh/documentos/{documento}/aprobar  { comentario? }     rh.documentos.aprobar
POST /api/v1/rh/documentos/{documento}/rechazar { motivo }          rh.documentos.rechazar
GET  /api/v1/rh/documentos/{documento}/extraccion                  rh.documentos.extraccion.ver
POST /api/v1/rh/documentos/{documento}/extraccion/aplicar { valores } rh.documentos.extraccion.aplicar
POST /api/v1/rh/documentos/{documento}/extraccion/ignorar           rh.documentos.extraccion.ignorar
```

`{documento}` es el id real de `employee_documents` (no el tipo). Esta bandeja es la vista "directa" (todos los documentos, cualquier colaborador, filtrable); `rh/expedientes/{colaborador}/documentos/{documento}/*` (ver `docs/API_MOVIL.md`) sigue existiendo para navegar por colaborador — ambas llaman al mismo `IncorporacionService`.

Extracción automática de datos personales (CURP/RFC/NSS/fecha de nacimiento): mismo
`DocumentExtractionService` que el panel web, ver `docs/DOCUMENT_EXTRACTION.md`.
Reprocesar solo está disponible en el panel web por ahora.

## Jerarquía de puestos (solo lectura)

```
GET /api/v1/rh/jerarquia-puestos      permiso: puestos.administrar
```

Mismo `JerarquiaPuestoService` que el panel web (`Administracion\JerarquiaPuestoController`);
la app solo consulta el árbol, no puede editarlo. Ver `docs/JERARQUIA_PUESTOS.md`.

## Formatos (catálogo y descarga)

```
GET /api/v1/rh/formatos                             plantillas.ver
GET /api/v1/rh/formatos/{documento}/descargar        formatos.descargar_docx o formatos.descargar_pdf
GET /api/v1/rh/formatos/{documento}/descargar-pdf    formatos.descargar_docx o formatos.descargar_pdf
```

Mismo `FormatoCatalogoService` que el panel web; la descarga respeta
`AlcanceOrganizacionalService` para documentos asociados a un colaborador (los de
candidatos no tienen alcance por sucursal que validar aparte). Generar un documento
nuevo y la vista previa se quedan solo en el panel web (requieren un flujo de
selección/edición más largo del que tiene sentido en la app). Ver
`docs/PLANTILLAS_FORMATOS.md`.

## Incorporaciones

```
GET  /api/v1/rh/incorporaciones?estado=&q=&page=&per_page=      rh.incorporaciones.ver
GET  /api/v1/rh/incorporaciones/{colaborador}                    rh.incorporaciones.detalle
POST /api/v1/rh/incorporaciones/{colaborador}/aprobar             rh.incorporaciones.aprobar
POST /api/v1/rh/incorporaciones/{colaborador}/rechazar { motivo } rh.incorporaciones.rechazar
```

`estado` (calculado, no columna): `incompleto | en_revision | completo | aprobada | rechazada`. `aprobar` responde `422` si falta algún documento obligatorio por aprobar (igual regla que `rh/expedientes/{colaborador}/aprobar-incorporacion`, mismo service). Al aprobar, el colaborador pasa a `estatus = activo`.

## Colaboradores

```
GET /api/v1/rh/colaboradores?q=&sucursal_id=&departamento_id=&estatus=&page=&per_page=      rh.colaboradores.ver
GET /api/v1/rh/colaboradores/{colaborador}                                                   rh.colaboradores.detalle
```

Datos básicos + contadores (`solicitudes_pendientes`, `vacaciones_pendientes`, `documentos_pendientes`) — nunca el expediente completo (para eso, `rh/expedientes/{colaborador}`).

## Vacantes (solo lectura)

```
GET /api/v1/rh/vacantes?estado=&sucursal_id=&page=&per_page=      vacantes.ver
```

Mismo alcance organizacional y mismo permiso que el panel web (`App\Http\Controllers\Rh\VacanteController`) — gestionar una vacante (crear/editar/cubrir/cancelar) se queda solo en web por ahora. Regresa puesto/departamento/sucursal, motivo/estado, `plazas_requeridas`/`plazas_cubiertas`/`plazas_disponibles` y si es automática, más `candidatos_count`.

## Cumpleaños

```
GET /api/v1/rh/cumpleanos?periodo=hoy|7_dias|30_dias|mes&mes=&sucursal_id=&departamento_id=&q=&page=&per_page=   rh.cumpleanos.ver
GET /api/v1/rh/cumpleanos/{greeting}                                                                              rh.cumpleanos.ver
GET /api/v1/rh/cumpleanos/{greeting}/imagen                                                                       rh.cumpleanos.ver
GET /api/v1/rh/cumpleanos/{colaborador}/foto                                                                      rh.cumpleanos.ver
```

Igual criterio de alcance que el resto: `AlcanceOrganizacionalService` acota tanto los colaboradores listados como los `meta.hoy`/`meta.proximos_7_dias`/`meta.proximos_30_dias` (nunca cuentan cumpleaños fuera del alcance del destinatario). Nunca regresa el año de nacimiento; `{greeting}` es el destino del push `{"type": "rh_cumpleanos", ...}` (ver `docs/PUSH_NOTIFICATIONS.md` para el payload exacto, incluyendo el caso `resource_id: null` cuando el aviso no apunta a una sola felicitación). Detalle completo: `docs/CUMPLEANOS.md`.

## Permisos nuevos

Ver `database/seeders/RolesYPermisosSeeder.php`, bloque "Backend movil v5". Asignados a `super_admin` (todo), `rh_admin` (todo lo de RH móvil), `rh_auxiliar`/`coordinadora(_regional)`/`auditor`/`director_comercial` (solo `ver`/`detalle`, sin aprobar), `gerente_sucursal`/`gerente`/`subgerente`/`gerente_regional`/`jefe_directo` (aprueban solicitudes/vacaciones de su alcance, no documentos/incorporaciones — eso queda exclusivo de RH), `colaborador` (solo lo transversal: bootstrap, dispositivos, notificaciones, configuración de solicitudes).

## Tests

`tests/Feature/Api/Rh/RhDashboardApiTest.php`, `RhPendientesApiTest.php`, `RhSolicitudApiTest.php`, `RhVacacionApiTest.php`, `RhDocumentoApiTest.php`, `RhIncorporacionApiTest.php`, `RhColaboradorApiTest.php`, `RhVacanteApiTest.php`.

## Pendiente (fuera de alcance de esta primera versión)

- `prioridad` en la bandeja siempre `"normal"`: no hay todavía una señal de urgencia (p. ej. "vence en menos de 24h") calculada.
- Workflow de una sola etapa ("rh"): `App\Services\RhMobile\WorkflowService` está preparado para agregar una segunda etapa (p. ej. gerencia antes de RH) sin tocar los controladores, pero esa etapa adicional no existe todavía en el modelo de datos.
- `rh/documentos` no tiene su propio `configuracion`/adjuntos dedicado — reutiliza el mismo `EmployeeDocument`/`DocumentoStorageService` que expedientes.
