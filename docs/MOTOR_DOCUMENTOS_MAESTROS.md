# Motor de documentos maestros

RH/Jurídico entregan el documento jurídico **original** (DOCX o PDF) una vez.
PEOPLE lo prepara, lo versiona y, en el momento operativo correcto, lo llena
con los datos que ya conoce. Nadie escribe `{{marcadores}}`.

## Conceptos

| Concepto | Dónde vive |
|---|---|
| ORIGINAL (inmutable) | `document_templates.original_*` → `nas:documentos-maestros/originales/{sha256}.{ext}` |
| MASTER técnico | `document_templates.path` + `master_hash` + `mapping` + `analisis` |
| Mapa de campos | `config/documentos_maestros.php` (versionado en git; sin binarios) |
| INSTANCIA / OUTPUT | `generated_documents` (snapshot `payload`, `master_familia`, `master_hash`, `version_plantilla`, `checksum`, `fidelidad`, `proceso`, `documentable`) |
| Evento del flujo | `App\Services\DocumentosMaestros\DocumentoProcesoService` |

`familia` (p. ej. `contrato_capacitacion.gestor`) identifica la línea de un
master; sus versiones se numeran 1..n y solo una está activa. `clave`
(`contrato_capacitacion`) es lo que pide el flujo; el resolvedor elige la
familia correcta para la persona. Las plantillas anteriores conservan
`familia = clave` y siguen funcionando (y descargándose) igual.

## Preparación (una sola vez por versión)

- **DOCX** — `Docx\PreparadorMasterDocx` aplica reglas por contexto
  (`blanco`, `entre`, `texto`, `celda`) sobre document/headers/footers,
  resistentes a runs fragmentados; deja `{{campo@n}}` en un solo `<w:t>` y
  guarda el texto original de cada instancia (para restaurarlo si el dato
  es opcional, p. ej. beneficiarios). Imágenes, estilos y secciones se
  copian byte por byte. `Docx\DetectorCamposDocx` reporta blancos sin
  mapear, datos de ejemplo y fechas concretas; con pendientes el master no
  se activa.
- **PDF** — `Pdf\NormalizadorPdf` reescribe PDFs con xref comprimido a PDF
  clásico (mismo contenido) para que FPDI los lea; `Pdf\RenderizadorOverlayMaestro`
  dibuja los campos (mm) sobre las páginas del original (rango de páginas,
  copias múltiples por hoja, ajuste de tamaño, multilínea, cubrir dato de
  ejemplo impreso).

## Generación (determinista, sin IA ni OCR)

`MotorDocumentalService::generarDesdeMaestro()`:
1. `DatosDocumentoService` arma los datos (colaborador, sucursal, empresa,
   contrato, cierre, evaluación, solicitud, préstamo, datos del acto).
2. Faltantes requeridos → **422 `DATOS_FALTANTES`** con lista y origen; la
   UI pide solo eso y lo guarda en la ficha (`completar`).
3. DOCX: `RellenadorDocx` → `Formatos\Motor\ConversorDocxPdf`
   (LibreOffice → Word COM en Windows → PhpWord marcado "aproximada").
   PDF: overlay.
4. PDF (y DOCX llenado de respaldo) al expediente, categoría correcta;
   `GeneratedDocument` con snapshot e hash; flujo físico
   (`FlujoDocumentalService`): impreso → firma/huella/testigos → envío →
   recepción → escaneo → archivo, según banderas del master.

Nombre: `EMP_0123_Juan_Perez_Contrato_de_capacitacion_inicial_2026-10-04.pdf`.

## Comandos y configuración

```bash
php artisan people:importar-formatos-juridicos            # idempotente
php artisan people:importar-formatos-juridicos --simular  # solo inventario
```

`.env`: `FORMATOS_LIBREOFFICE_PATH` (Linux), `FORMATOS_CONVERSOR=auto|libreoffice|word|phpword`,
`DOCUMENTOS_MAESTROS_DISK`, `DOCUMENTOS_MAESTROS_FUENTE`,
`DOCUMENTOS_ALTA_GENERACION_AUTOMATICA` (false = el paquete se genera con el
botón de la ficha).

Un archivo nuevo de Jurídico: Administración → Documentos maestros → *Nueva
versión* (aplica el mismo mapa; queda inactiva) → *Probar con colaborador*
→ *Activar*. Si el formato cambió de estructura, el reporte muestra los
pendientes y hay que ajustar sus reglas en `config/documentos_maestros.php`.

## Endpoints (web JSON = API v1, mismo controlador)

| Método | Ruta (`/rh/…` web, `/api/v1/rh/…` app) |
|---|---|
| GET | `documentos-proceso/colaborador/{colaborador}` |
| GET | `documentos-proceso/{contrato\|cierre\|solicitud\|prestamo\|evaluacion\|entrega_activo}/{id}` |
| POST | `documentos-proceso/{tipo}/{id}/generar` `{clave, proceso?, regenerar?, completar?, manuales?}` |
| POST | `documentos-proceso/{contrato\|cierre\|prestamo}/{id}/paquete` |
| POST | `documentos-proceso/documento/{documento}/{imprimir\|firma-fisica\|envio\|recepcion\|escaneo\|archivar}` (web; la app usa `documentos-laborales/{id}/*`) |
| POST | `cierres/{cierre}/procedimiento/{negativa\|testigos\|etapa}` |
| GET/POST | `documentos-maestros/*` (solo web, `plantillas_documentales.administrar`) |

Seguridad: alcance organizacional o cadena de mando sobre la persona,
permiso por documento (`documentos_maestros.permisos_generacion`), Policy de
`GeneratedDocument` para el flujo físico; el registro se resuelve desde la
URL; descargas siempre por endpoint autorizado (nunca la ruta del NAS);
`GeneratedDocument::colaborador()` usa `withTrashed`.

## Limitaciones conocidas

- La solicitud de permiso aún no captura en su formulario `hora_salida`,
  `hora_entrada` ni `modalidad_permiso` (columnas creadas); el formato las
  marca cuando existan y deja la modalidad sin marcar para permisos con goce.
- Carta responsiva, contrato de periodo de prueba y de tiempo determinado:
  sin formato de Jurídico.
- Empresa: domicilio fiscal y representante aún no se editan en la pantalla
  de Empresas (se usan los valores por defecto del registro).
