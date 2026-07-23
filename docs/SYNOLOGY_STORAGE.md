# Documentos laborales en Synology NAS

Implementación del expediente documental (checkpoint "Documentos en Synology"). Reutiliza el mismo disco `nas` que ya usa la biblioteca multimedia de capacitación (ver `docs/CONFIGURACION_NAS.md`), con sus propias rutas lógicas y su propia puerta de entrada al disco.

## La base de datos nunca guarda archivos binarios

Solo metadatos. Dos tablas nuevas:

```
document_types              -- catalogo de tipos de documento
  id, nombre, clave (unica), descripcion, requerido, aplica_alta, activo

employee_documents          -- un documento cargado
  id, user_id, empresa_id, sucursal_id, document_type_id,
  disk, path, original_name, stored_name, mime, extension, size, hash,
  version, previous_version_id,
  status, uploaded_by, reviewed_by, reviewed_at, comments, rejection_reason,
  timestamps, soft delete
```

`disk`/`path` apuntan al disco NAS con una ruta lógica (`expedientes/{user_id}/{uuid}.{ext}`); el nombre original del archivo se conserva solo como metadato (`original_name`), nunca como nombre real en disco (`stored_name` es un UUID, igual que en `MediaStorageService`).

### Versiones sin tabla aparte

`document_versions` era opcional según el encargo. Se optó por **no** crear una tabla separada: cada nueva versión es una fila nueva en `employee_documents`, enlazada a la anterior por `previous_version_id`, con `version` incrementado. Al subir una nueva versión, la anterior se marca `status = archivado` automáticamente (`EmployeeDocumentController::store()`). El historial completo de un tipo de documento para un colaborador es simplemente:

```php
EmployeeDocument::where('user_id', $id)->where('document_type_id', $tipoId)->orderByDesc('version')->get();
```

### Estados (`App\Enums\EstadoDocumento`)

`pendiente` (nunca se usa como estado de una fila real: es el estado "virtual" que ve el frontend cuando un tipo de documento no tiene ningún `EmployeeDocument` cargado todavía) · `cargado` · `en_revision` (estado real tras subir) · `aprobado` · `rechazado` · `requiere_correccion` · `vencido` · `archivado`.

## `App\Services\Expedientes\DocumentoStorageService`

Única puerta de entrada al disco `config('expedientes.disk')` (por defecto `nas`), espejo deliberado de `App\Services\Multimedia\MediaStorageService` para el mismo disco pero con rutas propias de documentos laborales en vez de video. Ningún controlador debe llamar `Storage::disk('nas')` directamente para estos archivos.

Métodos: `nombreInterno()`, `rutaDocumento()`, `guardar()`, `existe()`, `eliminar()`, `hashSha256()`, `respuesta()` (streaming, para visor/descarga).

`config/expedientes.php` — `disk`, `max_upload_mb` (`EXPEDIENTES_MAX_UPLOAD_MB`, default 20MB), `extensiones_permitidas` (`pdf`, `jpg`, `jpeg`, `png`).

## Nunca se expone la ruta real

El frontend solo conoce el `id` de `EmployeeDocument`. La descarga/visor pasa por una ruta protegida por policy:

```
GET /rh/documentos/{documento}/descargar   rh.documentos.descargar
```

que valida `EmployeeDocumentPolicy::descargar()` antes de transmitir el archivo por streaming (`DocumentoStorageService::respuesta()`), igual que el patrón ya usado para video. El navegador nunca ve la ruta física del NAS.

## Flujo de revisión

```
POST /rh/expedientes/{colaborador}/documentos           rh.expedientes.documentos.store       (subir)
POST /rh/documentos/{documento}/aprobar                  rh.documentos.aprobar
POST /rh/documentos/{documento}/rechazar                 rh.documentos.rechazar                (rejection_reason requerido)
POST /rh/documentos/{documento}/solicitar-correccion      rh.documentos.solicitar-correccion    (comments requerido)
```

- **Subir**: el propio colaborador (permiso `documentos.subir` + puede ver su expediente) o RH/gerente/jefe dentro de su alcance.
- **Aprobar/rechazar/pedir corrección**: siempre alguien distinto al dueño del documento (`EmployeeDocumentPolicy::revisar()` lo exige explícitamente — nadie revisa su propio documento), con el permiso correspondiente (`documentos.aprobar`/`documentos.rechazar`; pedir corrección solo exige el permiso base `documentos.revisar`, es una acción más suave que rechazar).
- **Descargar**: dueño del documento o alguien con `documentos.descargar` + alcance sobre ese colaborador.

## UI

`resources/js/components/Rh/ExpedienteDocumentos.vue` — grid de tarjetas por tipo de documento (badge de estado semántico vía `EstadoBadge`, motivo de rechazo/comentarios visibles, botón "Subir"/"Subir nueva versión" cuando aplica, acciones Aprobar/Corrección/Rechazar para revisores). `RevisarDocumentoDialog.vue` — diálogo compartido para rechazar/pedir corrección (pide motivo).

## Pendiente / limitaciones conocidas

- El backend **no bloquea** técnicamente volver a subir sobre un documento `aprobado` sin pasar antes por "solicitar corrección" — la UI oculta el botón "Subir" en ese caso, pero una llamada directa a la API podría hacerlo. Si se necesita blindar esto a nivel de negocio (no solo UI), agregar la validación en `EmployeeDocumentController::store()`.
- No hay visor embebido de PDF/imagen todavía: "Ver" abre el archivo en una pestaña nueva del navegador (`target="_blank"`), que el navegador renderiza nativamente para PDF/imágenes. Un visor embebido en la propia página es una mejora futura, no un requisito bloqueante.
- `X-Accel-Redirect` (Nginx) para descargas de documentos no se implementó por separado; reutiliza el streaming directo por PHP, igual que multimedia en este entorno de desarrollo.
