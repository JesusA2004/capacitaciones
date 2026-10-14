# Extracción automática de datos personales

Sugiere a RH datos personales (CURP, RFC, NSS, fecha de nacimiento, domicilio,
beneficiario, contacto de emergencia y más — ver «Campos» abajo) detectados
automáticamente en un documento YA EXISTENTE en el NAS, para que RH los compare
contra lo capturado y decida si los aplica — nunca se le vuelve a pedir al
colaborador un dato que RH ya tiene en papel. Tabla `document_extractions`
(migraciones `2026_09_10_100000_create_document_extractions_table`,
`2026_10_14_132812_add_extractor_metadata_to_document_extractions_table`,
`2026_10_14_140000_add_expediente_historico_support_to_document_extractions_table`),
modelo `App\Models\DocumentExtraction`, enum `App\Enums\EstadoExtraccion`.

## Dos fuentes posibles

Una fila de `document_extractions` tiene exactamente una de estas dos FKs (nunca
ambas, nunca ninguna):

- **`employee_document_id`** → un documento normal del checklist de expediente
  (INE, CURP, RFC, NSS, acta de nacimiento, comprobante de domicilio — ver
  `DocumentExtractionService::TIPOS_ELEGIBLES`).
- **`expediente_historico_id`** → el PDF único de la migración inicial
  (`App\Models\ExpedienteHistorico`, ver `docs/MIGRACION_INICIAL_EXPEDIENTES.md`).
  Solo se procesa cuando `estado = vinculado` y tiene `colaborador_id` — los
  pendientes de vincular nunca se tocan automáticamente. El PDF histórico
  **nunca se mueve, renombra, corta ni borra**: solo se lee.

`DocumentExtraction::colaboradorExpediente()` (columna `colaborador_id`) es la
fuente real de "de quién es esta sugerencia" en ambos casos — no se navega por
`documento()->colaborador()`, porque un histórico no tiene `EmployeeDocument`.

## Cómo se lee cada archivo (nunca cloud)

1. **PDF con texto** (`smalot/pdfparser`): extracción directa, el caso más barato.
2. **JPG/PNG**: OCR local vía Tesseract (`tesseract -l spa`) si el binario está
   instalado en el servidor. Si no está, la extracción queda `failed` con un
   mensaje explícito — nunca truena.
3. **PDF escaneado (sin capa de texto)**: si Tesseract **y** un rasterizador
   PDF→imagen (`pdftoppm`, `mutool` o ImageMagick `magick`/`convert`) están
   instalados, se rasteriza página por página (máx. 15 páginas, 150 DPI,
   timeout por página) y se corre OCR sobre cada una, concatenando el texto.
   Si falta cualquiera de los dos binarios, queda `failed` con el mensaje
   "PDF escaneado; requiere Tesseract y un rasterizador PDF local" — nunca se
   manda a un servicio externo de OCR/IA.

`RegexPersonalDataExtractor::extraer()` corre sobre el texto obtenido (sea cual
sea el origen) y busca por regex, siempre bajo etiqueta explícita del
documento — nunca adivina nombre, apellidos o domicilio de texto libre sin
etiqueta (CLAUDE.md). Las etiquetas de texto libre (domicilio, beneficiario,
contacto de emergencia…) se capturan de forma perezosa y se detienen en el
primer salto de línea o la siguiente etiqueta conocida, para no "tragarse" la
sección siguiente del documento.

## Campos detectados y a qué columna de Colaborador se aplican

`DocumentExtractionService::CAMPO_COLUMNA` (whitelist real, también usada por
`AplicarExtraccionRequest`):

| Detectado | Columna de Colaborador | Nota |
|---|---|---|
| `curp`, `rfc`, `nss`, `fecha_nacimiento` | igual nombre | — |
| `codigo_postal` | `domicilio_cp` | — |
| `clave_elector`, `correo`→`correo_personal`, `telefono`, `nacionalidad`, `lugar_nacimiento`, `profesion` | — | — |
| `domicilio` | `domicilio` | — |
| `colonia`, `municipio`, `estado_domicilio` | `domicilio_colonia`, `domicilio_municipio`, `domicilio_estado` | — |
| `beneficiario_nombre`, `beneficiario_parentesco` | igual nombre | **nunca** se cruza con contacto de emergencia |
| `contacto_emergencia_nombre`, `contacto_emergencia_telefono`, `contacto_emergencia_parentesco` | igual nombre | **nunca** se cruza con beneficiario |
| `sexo` | `genero` (enum `Genero`) | solo se aplica con mapeo inequívoco H→masculino, M→femenino |
| `estado_civil` | `estado_civil` (enum `EstadoCivil`) | solo se aplica si el texto detectado tiene equivalencia segura (soltero/casado/unión libre/divorciado/viudo); si no, se queda como sugerencia sin aplicar — nunca se adivina |

## Flujo

1. Para `EmployeeDocument`: `DocumentoStorageService::subirVersion()` encola
   `App\Jobs\ProcesarDocumentoPersonalJob` si el tipo es elegible — nunca
   bloquea la subida.
2. Para `ExpedienteHistorico` ya existente en el NAS: el comando
   `php artisan expedientes:extraer-existentes` (ver abajo) es quien dispara el
   procesamiento (no hay "subida" que lo encole automáticamente, el histórico
   ya estaba ahí desde la migración inicial).
3. `DocumentExtractionService::procesar()`/`procesarHistorico()` leen el
   archivo, extraen texto (con OCR si aplica), corren el regex, calculan
   diferencias contra el colaborador y guardan `processed` — nunca modifican
   al colaborador.
4. RH revisa desde el expediente: `ExtraccionDocumentoDialog.vue` por
   documento/histórico (igual que siempre), más un resumen pequeño en el
   expediente ("Podemos completar N datos con documentos existentes" →
   `ResumenExtraccionesDialog.vue`) que lista las sugerencias pendientes de
   **todos** los documentos del colaborador (normales + histórico) sin ser una
   pantalla nueva.
5. RH decide, por documento/histórico: **Aplicar**, **Ignorar** o
   **Reprocesar** — nunca se aplica nada automáticamente.
6. **Conflictos**: si dos documentos del mismo colaborador detectan valores
   distintos para el mismo campo, `DocumentExtractionService::conflictos()` los
   agrupa (campo, valor, fuente, id de extracción) para que RH decida
   manualmente — el sistema nunca elige uno solo.

## Comando masivo: `expedientes:extraer-existentes`

Procesa documentos y expedientes históricos YA EXISTENTES en el NAS (para
aprovechar lo que RH ya tiene sin volver a pedírselo al colaborador). Usa
`chunkById()` en ambas fuentes — nunca carga todo con `->get()` — para poder
procesar miles de archivos sin llenar memoria.

- Sin `--ejecutar`: reporte (cuenta cuántos entrarían), no lee el NAS ni
  escribe nada.
- `--ejecutar`: procesa de verdad (o `--enqueue` para encolar
  `ProcesarDocumentoPersonalJob`/`ProcesarDocumentoHistoricoJob` en vez de
  procesar en la misma ejecución).
- `--colaborador=ID`: limita a un colaborador.
- `--solo-faltantes`: solo colaboradores a los que aún les falta algún campo
  de `DocumentExtractionService::columnasColaborador()`.
- `--forzar`: vuelve a procesar aunque ya exista una extracción vigente.

**Idempotencia**: `source_hash` (hash del archivo) + `extractor_version`
(`RegexPersonalDataExtractor::VERSION`) evitan releer del NAS en una corrida
posterior un archivo que no cambió y ya se procesó con la misma versión del
extractor — tanto para `EmployeeDocument::hash` como para
`ExpedienteHistorico::hash` (ya existía en el histórico desde la migración
inicial).

## Rutas web

`app/Http/Controllers/Rh/DocumentExtraccionController.php` (ver `routes/rh.php`):

- `GET/POST rh/documentos/{documento}/extraccion/...` — ver/aplicar/ignorar/reprocesar (EmployeeDocument).
- `GET/POST rh/expedientes/historico/{historico}/extraccion/...` — mismas cuatro acciones sobre un ExpedienteHistorico.
- `GET rh/expedientes/{colaborador}/extracciones-pendientes` — resumen agregado (`resumen()`) para el panel del expediente.

Las seis acciones validan el permiso correspondiente **y** alcance
organizacional (`AlcanceOrganizacionalService::puedeVerExpediente()`).

## API móvil

`app/Http/Controllers/Api/V1/Rh/DocumentoController.php` (ver `routes/api.php`
y `docs/RH_MOBILE_API.md`) expone ver/aplicar/ignorar sobre `EmployeeDocument`
(reprocesar y el histórico solo están disponibles en el panel web por ahora).

## Qué nunca se expone

- El `path`/`disk` físico (NAS) del documento o del histórico.
- El texto completo extraído (`extracted_text`, limitado a 20,000 caracteres)
  solo se guarda para depuración/auditoría; el frontend consume
  `extracted_data`/`differences`.
- Una "página" del documento cuando no se puede conocer con certeza — nunca se
  inventa un número de página.

## Permisos

- `rh.documentos.extraccion.ver` / `.aplicar` / `.ignorar` / `.reprocesar` —
  mismos permisos para EmployeeDocument y ExpedienteHistorico.

`rh_admin` tiene los cuatro; `rh_auxiliar` tiene `ver` y `reprocesar` pero no
`aplicar`/`ignorar` — ver `database/seeders/RolesYPermisosSeeder.php`.

## Requisito de infraestructura

- `ProcesarDocumentoPersonalJob`/`ProcesarDocumentoHistoricoJob` corren en la
  cola por defecto: requieren un worker activo en VPS (ver `docs/DEPLOY.md`).
- OCR local: `tesseract-ocr` + `tesseract-ocr-spa` (imagen y PDF escaneado) y
  un rasterizador (`poppler-utils` para `pdftoppm`, o ImageMagick) solo para
  PDF escaneado. Si no están instalados, la extracción de esos casos queda
  `failed` con mensaje explícito — nunca rompe nada ni usa un servicio cloud.

## Nunca autoaplica

Ni `EmployeeDocument` ni `ExpedienteHistorico` modifican al colaborador por sí
solos: `procesar()`/`procesarHistorico()`/`reprocesar()` solo llenan la
sugerencia. Solo `aplicar()` escribe en Colaborador, y solo con los campos que
RH decide explícitamente (whitelist de `AplicarExtraccionRequest`).
