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
2. En `/rh/formatos`, RH elige una plantilla y un colaborador o candidato, y genera el
   documento. `App\Services\Plantillas\PlantillaDocumentoService::generar()`:
   - Descarga la plantilla del NAS a un archivo temporal local (PhpWord necesita una
     ruta de archivo real, no un stream; esto funciona sin importar si el disco `nas`
     es local o remoto).
   - Usa `PhpOffice\PhpWord\TemplateProcessor` con delimitadores `{{` `}}` (no el `${}`
     que trae PhpWord por defecto — ver
     `claude/formatos/placeholders/PLACEHOLDERS.md`).
   - Reemplaza cada placeholder con el valor resuelto por
     `App\Services\Plantillas\PlaceholderResolver` (única fuente de verdad de qué
     placeholder mapea a qué dato).
   - Guarda el resultado en el NAS y crea un `GeneratedDocument`.
3. RH descarga el documento generado (`GET rh/formatos/{documento}/descargar`, marca el
   estado como `entregado` en la primera descarga), lo imprime.
4. El colaborador/candidato firma en papel (**firma física en Fase 1**, no hay firma
   electrónica avanzada).
5. RH escanea el documento firmado y lo sube como un `EmployeeDocument` normal (tipo
   `contrato` u otro) desde la pestaña Documentos del expediente — **no** como una
   nueva `GeneratedDocument`. `generated_documents.signed_document_id` queda disponible
   para vincular manualmente ambos registros en una iteración futura; en esta fase no
   hay UI para ese enlace.

## Placeholders

Catálogo completo en `claude/formatos/placeholders/PLACEHOLDERS.md`. Resueltos por
`PlaceholderResolver::resolver()` para un `User` (colaborador) o `Candidato`; los
placeholders de solicitud (`fecha_inicio_permiso`, `motivo_permiso`, etc.) se resuelven
vía el parámetro `$extra` cuando el módulo de Solicitudes (`docs/SOLICITUDES_INTERNAS.md`)
genere documentos.

## `generated_documents.solicitud_id`

Columna sin FK todavía: la tabla `solicitudes_internas` se crea en un bloque posterior.
La restricción se agregará en una migración aparte cuando exista esa tabla, sin romper
los registros existentes (columna nullable, aditiva).

## Permisos

`plantillas.ver`, `plantillas.crear`, `plantillas.editar`, `plantillas.eliminar`
(administrar catálogo, solo `rh_admin`) y `plantillas.generar` (generar documentos,
`rh_admin` y `rh_auxiliar`).

## Fuera de alcance en Fase 1

- Generación de PDF exacto desde plantilla (solo DOCX; PDF solo como referencia de
  diseño, ver `claude/instrucciones/FORMATO_PLANTILLAS.md`).
- Detección automática de placeholders al subir una plantilla (RH debe conocer el
  catálogo y prepararla manualmente).
- Firma electrónica avanzada.
