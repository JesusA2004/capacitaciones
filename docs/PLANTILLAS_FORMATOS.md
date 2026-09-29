# Plantillas avanzadas (motor DOCX editable)

> **Un solo menú "Formatos", con tabs por dentro.** El sidebar ya no muestra
> "Formatos" y "Plantillas avanzadas" como dos módulos sueltos: hay un único acceso
> "Formatos" (`resources/js/components/AppSidebar.vue`) que abre una pantalla con 4
> tabs — `Oficiales PDF` (formatos fijos MR. LANA con overlay por coordenadas, ver
> `docs/FORMATOS_OFICIALES.md`), `Plantillas avanzadas DOCX` (este documento),
> `Generados` (historial) y `Configuración de campos` (coordenadas del overlay,
> contextual a un formato oficial elegido en la tab "Oficiales PDF"). Cada tab sigue
> siendo, por dentro, su propia ruta/controlador Inertia (`/rh/formatos`,
> `/rh/plantillas`, `/rh/formatos/catalogo`, `/rh/formatos-oficiales/{formato}`) — la
> barra de tabs compartida vive en `resources/js/components/Rh/FormatosTabsNav.vue` y
> navega entre ellas con `<Link>` (sin F5). Este documento describe el motor de
> plantillas DOCX editables detrás de la tab "Plantillas avanzadas DOCX" y el catálogo
> de documentos generados con él (`/rh/formatos/catalogo`) — sigue siendo el motor real
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
  con un valor vacío ni se oculta. **Excepción**: si RH marcó esa variable como
  requerida en "Variables" (sea automática o manual, ver abajo), `puede_generar` da
  `false` y tanto `store()`/`generar()` (web y móvil) rechazan la generación con 422,
  antes de tocar storage — nunca genera un documento con un hueco silencioso.

### Variables automáticas requeridas

`document_templates.variables_manuales` no es solo para marcadores "sin mapear": RH
también puede declarar ahí un marcador que SÍ corresponde a un dato real
(`{{curp}}`, `{{rfc}}`, `{{domicilio}}`, `{{fecha_ingreso}}`, etc.) solo para marcarlo
`requerido`. `VariableMappingService` distingue ambos casos por si la clave está en
`PlaceholderResolver` (automática) o no (manual):

- `VariableMappingService::manuales()` — solo las claves SIN dato real (lo que ve la
  UI de "llenar a mano").
- `VariableMappingService::automaticasConfiguradas()` — solo las claves CON dato real
  que RH configuró (su valor lo sigue resolviendo el dato real, nunca esta
  configuración; solo importa `requerido`).
- `VariableMappingService::clavesRequeridas()` — unión de ambas, es lo único que
  `FormatoPreviewService::previsualizar()` intersecta contra `faltantes` para calcular
  `puede_generar`.

Retrocompatible: una plantilla creada antes de esta función no tiene ninguna entrada
automática en `variables_manuales`, así que ningún dato automático es requerido hasta
que RH entra a "Variables" y lo marca explícitamente.

`GET rh/formatos/{documento}/descargar-pdf` (y su espejo en la API móvil) usa
`App\Services\Formatos\Motor\ConversorDocxPdf` — el mismo conversor desacoplado que ya
usa el módulo de formatos oficiales: si `config('formatos_oficiales.libreoffice')`
apunta a un binario de LibreOffice (`FORMATOS_LIBREOFFICE_PATH` en `.env`), lo prefiere
(`soffice --headless --convert-to pdf`, fidelidad "exacta" — LibreOffice sí respeta
tablas/estilos complejos que PhpWord no traduce bien a PDF). Sin esa variable
configurada, cae al writer PDF de PhpWord + Dompdf que ya existía (fidelidad
"aproximada", **no** garantiza fidelidad perfecta de un DOCX con estructura compleja).
Si ambas fallan, RH ve un aviso y sigue teniendo el Word — la conversión nunca bloquea
la descarga del DOCX original. Instalar LibreOffice en el servidor de producción:

```bash
# Debian/Ubuntu
apt-get install -y libreoffice --no-install-recommends
# confirmar la ruta real del binario (normalmente /usr/bin/soffice) y ponerla en .env:
FORMATOS_LIBREOFFICE_PATH=/usr/bin/soffice
```

## Permisos

- `plantillas.ver`, `plantillas.crear`, `plantillas.editar`, `plantillas.eliminar`
  (administrar catálogo de plantillas, solo `rh_admin`).
- `plantillas.generar` (generar documentos, subir firmados; `rh_admin` y `rh_auxiliar`).
- `formatos.ver`, `formatos.preview`, `formatos.descargar_pdf`, `formatos.descargar_docx`
  (catálogo/vista previa/descarga — deliberadamente aparte de `plantillas.*`, ver
  comentario en `RolesYPermisosSeeder`; `rh_admin`, `rh_auxiliar` y `gerente_sucursal`).
- El sidebar solo muestra el menú "Formatos" (único) con `formatos_oficiales.ver` o
  `plantillas.ver`; dentro de la pantalla, la tab "Plantillas avanzadas DOCX" y la tab
  "Generados" se muestran con `plantillas.ver`, y el botón "Nueva plantilla" solo con
  `plantillas.crear` — ver `FormatosTabsNav.vue`. `formatos_oficiales.*` (tabs
  "Oficiales PDF" y "Configuración de campos") está en `docs/FORMATOS_OFICIALES.md`.

## API móvil de RH

`GET /api/v1/rh/formatos` (catálogo, mismo `FormatoCatalogoService` que el panel web),
`POST /api/v1/rh/formatos/{plantilla}/preparar` y `.../generar` (mismo
`FormatoPreviewService`/`VariableMappingService` que el panel web — RH elige
colaborador, revisa datos resueltos/faltantes, captura variables manuales y genera),
`GET /api/v1/rh/formatos/{documento}/descargar` y `.../descargar-pdf` (respetan
`AlcanceOrganizacionalService` para documentos de colaboradores; `generar`/`preparar`
también, vía `alcanzaColaborador()` sobre el `sujeto_id` recibido). Administrar
plantillas (subir DOCX, mapear variables manuales, versionar) se queda en Portal RH —
mismo criterio que el resto de módulos de administración. Ver `docs/RH_MOBILE_API.md`.

## Filtros y exportación

`/rh/plantillas` y `/rh/formatos/catalogo` tienen filtros (tipo, alcance, responsable, rango de
fechas, buscador) y exportación Excel/PDF que respeta esos filtros — mismo patrón que el
resto de listados operativos, ver `docs/ARQUITECTURA_SERVICES.md`.

## Variables manuales (RH ya no necesita saberse los códigos de memoria)

`/rh/plantillas` → menú de acciones de una plantilla → **"Variables"** abre un editor
(`PlantillaVariablesDialog.vue`) que:

1. Lee los marcadores `{{...}}` que de verdad aparecen en el DOCX
   (`PlantillaDocumentoService::variablesEnPlantilla()`, el mismo escaneo que ya usaba
   el catálogo — no se duplica esa lógica).
2. Separa los detectados en **conocidos** (ya existen en `PlaceholderResolver`, se
   llenan solos con datos reales) y **sin mapear** (no corresponden a ningún dato del
   colaborador/candidato — RH debe decidir de dónde sale ese valor).
3. Para cada marcador sin mapear, RH captura: etiqueta visible, tipo de dato
   (texto/texto largo/fecha/número/moneda/lista de opciones), si es obligatorio para
   generar, y un valor por defecto opcional. Se guarda en
   `document_templates.variables_manuales` (JSON) vía
   `PUT rh/plantillas/{plantilla}/variables`
   (`App\Http\Requests\Rh\UpdateDocumentTemplateVariablesRequest`,
   `App\Services\Plantillas\VariableMappingService`).
4. Un catálogo de referencia agrupado (Colaborador/Laboral/Empresa/Solicitud/
   Préstamo/...) permite copiar cualquier marcador conocido con un clic
   (`VariableMappingService::catalogoConocidas()`).

**Nunca se puede declarar como variable manual** una clave que ya es un dato conocido
(evita ambigüedad: un mismo marcador no puede significar dos cosas) ni una clave que no
aparece de verdad en el DOCX (`UpdateDocumentTemplateVariablesRequest::withValidator()`
lo rechaza explícitamente) — igual que el resto del proyecto, nunca se inventa un campo
que no existe.

Al generar/previsualizar (`FormatoController::preview`/`store`), una variable manual
marcada como **obligatoria** que no llegó en `extra` bloquea la generación
(`puede_generar: false`, `faltantes_requeridos`) — a diferencia de un dato base del
colaborador vacío, que sigue siendo solo un aviso (comportamiento histórico sin
cambios). El campo `extra` también rechaza cualquier clave que no sea ni una variable
conocida ni una manual ya declarada para esa plantilla — nunca se inyecta un
placeholder arbitrario.

## Fuera de alcance en Fase 1

- Firma electrónica avanzada (la firma sigue siendo física + escaneo, ver arriba).
- Editor de variables manuales solo en Portal RH — la app móvil consume el resultado
  (`manuales`/`puede_generar`) pero no administra el catálogo de variables.

## Qué documentos maneja el catálogo "Generados (Word)"

`generated_documents` no es solo del motor Word: también guarda recibos de
nómina, contratos, préstamos y documentos laborales del motor documental (PDF,
con `documentable_type`, y a veces con `document_template_id`). El catálogo
Word (`Rh\FormatoController` y `Api\V1\Rh\FormatoController`) opera **solo**
`GeneratedDocument::desdePlantillaEditable()` = `document_template_id` no nulo
**y** `mime` DOCX. Cualquier otro documento responde 404 en
descargar / descargar-pdf / subir-firmado / eliminar (regresión de producción
2026-09-28: un recibo PDF llegaba a `ConversorDocxPdf::convertir(null)` → 500).

El archivo se lee del disco que registró el documento (`$documento->disk`,
validado contra `config/filesystems.php`), vía
`App\Services\Plantillas\DocumentoWordGeneradoService`. Si el archivo físico ya
no existe o el NAS no responde, web regresa con el aviso "El archivo fuente de
este documento ya no está disponible." y la API responde 404 — nunca 500.
Pruebas: `tests/Feature/Rh/FormatoDocumentosWordTest.php`.
