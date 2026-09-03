# Plantillas y formatos precargados

Módulos `/rh/plantillas` (catálogo de formatos oficiales) y `/rh/formatos` (generación
de documentos precargados). Tablas `document_templates` y `generated_documents` — ver
migraciones `2026_09_02_100000_create_document_templates_table` y
`2026_09_02_100001_create_generated_documents_table`.

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
   - **Desde `/rh/formatos`**: elige plantilla + colaborador/candidato manualmente.
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
3. RH descarga el documento generado (`GET rh/formatos/{documento}/descargar`, marca el
   estado como `entregado` en la primera descarga), lo imprime.
4. El colaborador/candidato firma en papel (**firma física en Fase 1**, no hay firma
   electrónica avanzada).
5. RH sube el escaneo del documento firmado desde la solicitud (botón "Subir firmado") o
   desde `/rh/formatos` — `Rh\FormatoController::subirFirmado`. Reutiliza
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
solicitud, o a ninguna (generado libremente desde `/rh/formatos`).

## Permisos

`plantillas.ver`, `plantillas.crear`, `plantillas.editar`, `plantillas.eliminar`
(administrar catálogo, solo `rh_admin`) y `plantillas.generar` (generar documentos, subir
firmados, y exportar el listado de formatos; `rh_admin` y `rh_auxiliar`).

## Filtros y exportación

`/rh/plantillas` y `/rh/formatos` tienen filtros (tipo, alcance, responsable, rango de
fechas, buscador) y exportación Excel/PDF que respeta esos filtros — mismo patrón que el
resto de listados operativos, ver `docs/ARQUITECTURA_SERVICES.md`.

## Fuera de alcance en Fase 1

- Generación de PDF exacto desde plantilla (solo DOCX; PDF solo como referencia de
  diseño, ver `claude/instrucciones/FORMATO_PLANTILLAS.md`).
- Detección automática de placeholders al subir una plantilla (RH debe conocer el
  catálogo y prepararla manualmente).
- Firma electrónica avanzada.
