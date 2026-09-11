# Plantillas avanzadas (motor DOCX editable)

> **Este NO es el módulo "Formatos" que ve RH operativo.** Desde el rediseño de
> formatos, `/rh/formatos` muestra los formatos oficiales fijos de MR. LANA (PDF con
> overlay, ver `docs/FORMATOS_OFICIALES.md`). Este documento describe el motor de
> plantillas DOCX editables que queda detrás de "Plantillas avanzadas"
> (`/rh/plantillas`, solo `plantillas.crear` — rh_admin/super_admin) y el catálogo de
> documentos generados con él (`/rh/formatos/catalogo`) — sigue siendo el motor real
> detrás del botón "Generar formato" de una Solicitud.

Módulos `/rh/plantillas` (catálogo de plantillas DOCX) y `/rh/formatos/catalogo`
(generación de documentos precargados desde ellas). Tablas `document_templates` y
`generated_documents` — ver migraciones `2026_09_02_100000_create_document_templates_table`
y `2026_09_02_100001_create_generated_documents_table`.

## Nota de nomenclatura

A diferencia de la mayoría del proyecto (tablas en español), este módulo usa nombres en
**inglés** (`document_templates`, `generated_documents`, modelos `DocumentTemplate` /
`GeneratedDocument`), siguiendo el precedente ya existente en el mismo dominio de
documentos: `document_types` / `employee_documents` (ver `docs/SYNOLOGY_STORAGE.md`).
Se prefirió consistencia dentro del dominio de documentos sobre la convención general
del resto del proyecto.

## Dependencia nueva

`composer require phpoffice/phpword` (1.4). Es la única dependencia PHP nueva agregada
en esta fase; no afecta ningún otro módulo. `composer audit` reporta vulnerabilidades
preexistentes en `league/commonmark` y `phpoffice/phpspreadsheet` (dependencias de
`maatwebsite/excel`, ya presentes antes de este cambio) — no relacionadas con
`phpoffice/phpword`.

## Flujo

1. RH sube una plantilla DOCX en `/rh/plantillas` (nombre, tipo, descripción, alcance
   opcional por empresa/sucursal/puesto). El archivo se guarda en el disco `nas`
   (`config('plantillas.disk')`) vía `App\Services\Plantillas\PlantillaStorageService`
   — la base de datos solo guarda metadatos, igual que el resto del proyecto.
2. RH genera el documento de dos formas:
   - **Desde `/rh/formatos/catalogo`**: elige plantilla + colaborador/candidato manualmente.
   - **Desde una solicitud** (`/rh/solicitudes/{solicitud}` → botón "Generar formato"):
     `Rh\FormatoController::store` recibe `solicitud_id` (o `solicitud_vacaciones_id`
     para vacaciones) en vez de `tipo_sujeto`/`sujeto_id`; el colaborador se deriva de
     la solicitud y los placeholders de fecha/motivo/folio se arman automáticamente
     desde sus campos (`extraDesdeSolicitud()` / `extraDesdeSolicitudVacaciones()`).
     El enum `TipoPlantillaDocumento::paraTipoSolicitud()` sugiere qué tipo de plantilla
     usar según el tipo de solicitud (el frontend ordena la lista con esa sugerencia
     primero; RH siempre puede elegir otra).

   En ambos casos, `App\Services\Plantillas\PlantillaDocumentoService::generar()`:
   - Descarga la plantilla del NAS a un archivo temporal local (PhpWord necesita una
     ruta de archivo real, no un stream; esto funciona sin importar si el disco `nas`
     es local o remoto).
   - Usa `PhpOffice\PhpWord\TemplateProcessor` con delimitadores `{{` `}}` (no el `${}`
     que trae PhpWord por defecto — ver
     `claude/formatos/placeholders/PLACEHOLDERS.md`).
   - Reemplaza cada placeholder con el valor resuelto por
     `App\Services\Plantillas\PlaceholderResolver` (única fuente de verdad de qué
     placeholder mapea a qué dato).
   - Guarda el resultado en el NAS y crea un `GeneratedDocument` (con `solicitud_id` o
     `solicitud_vacaciones_id` si aplica).
3. RH descarga el documento generado en Word (`GET rh/formatos/{documento}/descargar`,
   marca el estado como `entregado` en la primera descarga) o en PDF
   (`GET rh/formatos/{documento}/descargar-pdf`, convertido al vuelo desde el DOCX
   guardado — no se persisten dos archivos por documento), lo imprime.
4. El colaborador/candidato firma en papel (**firma física en Fase 1**, no hay firma
   electrónica avanzada).
5. RH sube el escaneo del documento firmado desde la solicitud (botón "Subir firmado") o
   desde `/rh/formatos/catalogo` — `Rh\FormatoController::subirFirmado`. Reutiliza
   `App\Services\Expedientes\DocumentoStorageService::subirVersion()` (misma lógica que
   subir cualquier documento al expediente: versiona si ya existe uno vigente del mismo
   tipo) para crear el `EmployeeDocument` correspondiente, y enlaza ambos registros
   seteando `generated_documents.signed_document_id`, con `status = firmado`.

## Placeholders

Catálogo completo en `claude/formatos/placeholders/PLACEHOLDERS.md`. Resueltos por
`PlaceholderResolver::resolver()` para un `User` (colaborador) o `Candidato`; los
placeholders de solicitud (`fecha_inicio_permiso`, `motivo_permiso`, `folio_solicitud`,
`dias_vacaciones`, etc.) se resuelven vía el parámetro `$extra`, armado por
`FormatoController::extraDesdeSolicitud()` / `::extraDesdeSolicitudVacaciones()` cuando
el documento se genera desde una solicitud.

## `generated_documents.solicitud_id` / `solicitud_vacaciones_id`

Ambas son FKs nullables (`solicitud_id` → `solicitudes_internas`, `solicitud_vacaciones_id`
→ `solicitudes_vacaciones`), mutuamente excluyentes (regla `prohibits` en
`StoreGeneratedDocumentRequest`): un documento generado está asociado a como mucho una
solicitud, o a ninguna (generado libremente desde `/rh/formatos/catalogo`).

## Catálogo (`/rh/formatos/catalogo`) y vista previa

`/rh/formatos/catalogo` muestra un catálogo con una card por `DocumentTemplate` activa: nombre,
tipo, descripción, las variables `{{...}}` que realmente usa esa plantilla (leídas del
DOCX por `PlantillaDocumentoService::variablesEnPlantilla()`, cacheadas por
plantilla+versión — no hay que mantener una lista aparte a mano), cuántas veces se ha
generado y la fecha del último uso. `App\Services\Formatos\FormatoCatalogoService::listar()`
arma ese catálogo y lo reutilizan tanto el panel web como la API móvil.

Botón "Generar" abre un diálogo para elegir colaborador/candidato y, antes de generar:

- **Vista previa** (`POST rh/formatos/preview`, `App\Services\Formatos\FormatoPreviewService`):
  fusiona los placeholders igual que `generar()` pero sin persistir nada, convierte el
  DOCX resultante a HTML (recargándolo con `PhpOffice\PhpWord\IOFactory` y su writer
  HTML) y lo muestra embebido en un `<iframe>`. Si la plantilla tiene una estructura que
  PhpWord no puede convertir, `html` regresa `null` y la pantalla ofrece generar y
  descargar directo para revisar — nunca truena.
- **Datos faltantes**: la vista previa también regresa qué variables de la plantilla
  quedaron vacías para ese colaborador/candidato (`faltantes`); el diálogo deja
  llenarlas a mano solo para ese documento (van en `extra`, no se guardan en el
  expediente). Si RH ignora el aviso y genera el documento de todas formas, el
  placeholder `{{clave}}` sin resolver queda literal en el DOCX final — no se rellena
  con un valor vacío ni se oculta.

`GET rh/formatos/{documento}/descargar-pdf` (y su espejo en la API móvil) usa el mismo
`FormatoPreviewService` para convertir el DOCX ya generado a PDF con el writer PDF de
PhpWord + Dompdf (ya es dependencia del proyecto, sin paquetes nuevos). Si la conversión
falla, RH ve un aviso y sigue teniendo el Word.

## Permisos

- `plantillas.ver`, `plantillas.crear`, `plantillas.editar`, `plantillas.eliminar`
  (administrar catálogo de plantillas, solo `rh_admin`).
- `plantillas.generar` (generar documentos, subir firmados; `rh_admin` y `rh_auxiliar`).
- `formatos.ver`, `formatos.preview`, `formatos.descargar_pdf`, `formatos.descargar_docx`
  (catálogo/vista previa/descarga — deliberadamente aparte de `plantillas.*`, ver
  comentario en `RolesYPermisosSeeder`; `rh_admin`, `rh_auxiliar` y `gerente_sucursal`).
- El sidebar "Plantillas avanzadas" se muestra solo con `plantillas.crear` (no
  `plantillas.ver`), a propósito más restrictivo que antes — ver "Preferido" arriba.
  `formatos_oficiales.*` (módulo distinto) está en `docs/FORMATOS_OFICIALES.md`.

## API móvil de RH

`GET /api/v1/rh/formatos` (catálogo, mismo `FormatoCatalogoService` que el panel web),
`GET /api/v1/rh/formatos/{documento}/descargar` y `.../descargar-pdf` (respetan
`AlcanceOrganizacionalService` para documentos de colaboradores). Generar un documento
nuevo y la vista previa con variables faltantes se quedan solo en el panel web por
ahora — requieren un flujo de selección/edición más largo del que tiene sentido en la
app; la app solo consulta el catálogo y descarga lo ya generado. Ver `docs/RH_MOBILE_API.md`.

## Filtros y exportación

`/rh/plantillas` y `/rh/formatos/catalogo` tienen filtros (tipo, alcance, responsable, rango de
fechas, buscador) y exportación Excel/PDF que respeta esos filtros — mismo patrón que el
resto de listados operativos, ver `docs/ARQUITECTURA_SERVICES.md`.

## Fuera de alcance en Fase 1

- Detección automática de placeholders al **subir** una plantilla (RH debe conocer el
  catálogo y prepararla manualmente) — sí se detectan al **leerla** para el catálogo y
  la vista previa (ver arriba), pero no hay validación en el momento de la subida.
- Firma electrónica avanzada.
