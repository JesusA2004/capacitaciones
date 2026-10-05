# Motor de documentos maestros

RH/Jurídico entregan el documento jurídico **original** (DOCX o PDF) una vez.
PEOPLE lo prepara, lo versiona, **valida que lo que genera se vea igual al
original** y, en el momento operativo correcto, lo llena con los datos que
ya conoce. Nadie escribe `{{marcadores}}`.

Regla principal: **el documento generado = el mismo archivo original + los
datos de la persona.** Si el motor no puede garantizarlo (no hay conversor
fiel, la versión no pasó la prueba de diseño, un dato no cabe) **bloquea**
con un 422 explicable; nunca emite un documento "parecido".

## Conceptos

| Concepto | Dónde vive |
|---|---|
| ORIGINAL (inmutable) | `document_templates.original_*` → `nas:documentos-maestros/originales/{sha256}.{ext}`; nunca se sobrescribe (v1, v2, v3… conviven) |
| MASTER técnico | `document_templates.path` + `master_hash` + `mapping` + `analisis` |
| QA visual de la versión | `document_templates.visual_validation_status` (`pending`/`passed`/`failed`), `visual_similarity`, `page_count_original`, `page_count_output`, `visual_checked_at`, `visual_engine`, `visual_report`, `diagnostico_fuentes` |
| Activación | `activo`, `activado_por`, `activado_en`, `activacion_excepcional_motivo` |
| Mapa de campos | `config/documentos_maestros.php` (versionado en git; sin binarios) |
| INSTANCIA / OUTPUT | `generated_documents`: PDF (`disk`/`path`/`checksum`), Word llenado (`docx_disk`/`docx_path`/`docx_hash`), `conversion_engine`, `conversion_fidelity`, `master_familia`, `master_version`, `master_hash`, `paginas`, snapshot `payload`, `revision_de_id`/`motivo_revision` |
| Decisión documental del puesto | `puestos.grupo_documental` **o** `puestos.no_requiere_documentos_laborales` + `motivo_sin_documentos` |
| Evento del flujo | `App\Services\DocumentosMaestros\DocumentoProcesoService` (fuente única para web y app) |

`familia` (p. ej. `contrato_capacitacion.gestor`) identifica la línea de un
master; sus versiones se numeran 1..n y solo una está activa. `clave`
(`contrato_capacitacion`) es lo que pide el flujo; el resolvedor elige la
familia correcta para la persona con esta prioridad: **puesto + empresa →
puesto → grupo + empresa → grupo → general** (la general solo si el tipo no
tiene variantes por grupo o `fallback_general` lo permite). Nunca se usa el
contrato de un Gerente para un Gestor.

## Fidelidad

| Nivel (`conversion_fidelity`) | Conversor (`conversion_engine`) | ¿Documento definitivo? |
|---|---|---|
| **nativa** | `word` — Microsoft Word por COM (Windows con Office) | Sí |
| **nativa** | `overlay` — PDF original de Jurídico como fondo vectorial + datos encima | Sí |
| **alta** | `libreoffice` — LibreOffice headless | Solo si **esa versión** pasó el QA visual con LibreOffice (`visual_engine = libreoffice`) |
| **aproximada** | `phpword` — PhpWord + DomPDF (reconstruye el documento) | **Nunca.** Solo vista previa/desarrollo |

- `ConversorDocxPdf::convertirFiel()` jamás cae a PhpWord. Sin Word ni
  LibreOffice → **422 `DOCUMENT_CONVERTER_UNAVAILABLE`** ("No hay un motor de
  conversión fiel disponible para generar este documento oficial."). No se
  guarda nada.
- `ConversorDocxPdf::convertir()` (vista previa de "Probar con colaborador",
  módulo heredado de formatos) puede usar PhpWord y lo marca `aproximada`.
- El módulo heredado de formatos oficiales (`RenderizadorFormato`,
  "Descargar PDF" de un Word generado) también exige conversor fiel.
- Documentos históricos: conservan su `fidelidad` original (`exacta`/
  `aproximada`); la migración copia `aproximada` a `conversion_fidelity` y
  deja sin nivel los antiguos "exacta" (no se sabe con qué conversor se
  hicieron; no se inventa).

### Microsoft Word (Windows)

`resources/scripts/docx-a-pdf-word.ps1`: abre el DOCX en **solo lectura**,
sin recientes ni macros, exporta con `ExportAsFixedFormat` (calidad de
impresión; fuentes no incrustables se rasterizan en vez de sustituirse),
cierra el documento, cierra Word y libera los objetos COM. Escribe el PID
del `WINWORD.EXE` que **él** abrió; si PHP corta por tiempo
(`FORMATOS_CONVERSOR_TIMEOUT`, 180 s por defecto) se termina **solo ese**
proceso (nunca un Word abierto por alguien). Rutas con espacios/Unicode: se
pasan como argumentos, nunca interpoladas. Word se detecta por registro
(`HKCR\Word.Application`) y se puede apagar con `FORMATOS_WORD_HABILITADO=false`.

### LibreOffice (Linux)

`FORMATOS_LIBREOFFICE_PATH=/usr/bin/soffice`. Cada conversión usa un perfil
de usuario aislado (`-env:UserInstallation`) para no bloquearse entre
procesos. Que LibreOffice produzca un PDF **no** valida la familia: la
versión debe pasar el QA visual con LibreOffice en ese servidor. Las
fuentes del documento deben estar instaladas (ver *Fuentes*).

## Preparación (una sola vez por versión)

- **DOCX** — `Docx\PreparadorMasterDocx` aplica reglas por contexto
  (`blanco`, `entre`, `texto`, `celda`) sobre document/headers/footers,
  resistentes a runs fragmentados; deja `{{campo@n}}` en un solo `<w:t>` del
  primer run del rango (el dato hereda fuente, tamaño, negritas, subrayado).
  Por instancia guarda el texto original, los **espacios separadores** que
  agregó el sistema (`prefijo`/`sufijo`) y, si el blanco mezclaba formatos
  (p. ej. `____ de ____` con el "de" en negritas), sus **tramos con formato**
  (`piezas`). Así el original se puede restaurar EXACTO (dato opcional vacío
  y QA de identidad). Tabuladores del dato → `<w:tab/>`; saltos → `<w:br/>`.
  `styles.xml`, numeración, configuración, tema, imágenes, relaciones y
  secciones se copian byte por byte; nunca se pasa por HTML.
  `Docx\DetectorCamposDocx` reporta blancos sin mapear, datos de ejemplo y
  fechas concretas (`detectados = mapeados + firmas + pendientes`; las líneas
  de firma nunca se mapean).
- **PDF** — `Pdf\NormalizadorPdf` reescribe PDFs con xref comprimido a PDF
  clásico (mismo contenido); `Pdf\RenderizadorOverlayMaestro` importa cada
  página como objeto vectorial (nunca rasteriza) y dibuja encima: texto con
  reducción hasta `tamano_minimo` (6 pt por defecto), `multilinea` con wrap
  dentro del alto de la caja, `alineacion` L/C/R, casillas como **X
  vectorial** centrada (máx. 4 mm, 70 % del lado menor de la caja: nunca
  sale del recuadro, a cualquier DPI), copias múltiples por hoja y fondo
  para cubrir datos de ejemplo impresos. Si un dato no cabe ni al tamaño
  mínimo es un **desborde** (nunca se encima).

## QA visual por versión (`Calidad\ValidacionVisualMaestroService`)

Corre al **importar/cargar** una versión (`DOCUMENTOS_QA_AL_IMPORTAR=true`),
cuando RH pulsa **"Probar diseño"** y antes de activar. **No** corre en cada
generación de un colaborador.

DOCX:
1. ORIGINAL → PDF con el conversor fiel.
2. **Identidad**: master con cada marcador restaurado a su original exacto →
   PDF. Se rasteriza página por página y se compara con el 1: similitud
   mínima ≥ `umbral_identidad` (0.985), mismo número y tamaño de páginas.
   Mide que la preparación del master no movió nada.
3. **Estrés**: master con datos largos realistas
   (`Calidad\ValoresPruebaDocumento`: "José Francisco de Jesús Hernández
   González", domicilio con interior y colonia larga, sucursal de nombre
   compuesto, puesto largo, sueldo de 6 cifras) → PDF. Mismo tamaño de
   página; estructura intacta (mismas imágenes, encabezados, pies, tablas,
   secciones; `styles.xml`, numeración, tema y encabezados/pies sin datos
   byte a byte iguales — mismo XML + misma página + mismo conversor = mismo
   logo/membrete); ningún marcador residual. Si con datos MUY largos el Word
   crece de páginas se registra como **advertencia** visible (no invalida el
   diseño); al generar para una persona real que provoque esa página extra el
   motor **bloquea** (ver *Desbordes*).
4. **Fuentes**: todas las del documento instaladas en el servidor; si falta
   una, la versión no se valida ("instala la fuente X").

PDF overlay: páginas del ORIGINAL vs las mismas páginas importadas por el
motor (fondo, logo y cajas conservados, ≥ `umbral_overlay`); con datos
largos, fuera de las cajas de los campos (máscaras) nada cambia; ningún
campo se desborda.

Rasterizador (`Calidad\RasterizadorPdf`): Windows usa el motor PDF nativo
(`Windows.Data.Pdf`, `resources/scripts/pdf-a-png-windows.ps1`, mide el DPI
real porque Windows aplica la escala de pantalla); Linux usa `pdftoppm`
(`DOCUMENTOS_QA_PDFTOPPM_PATH`). Sin rasterizador o sin conversor fiel el
QA queda `pending` (motivo de infraestructura, visible en la pantalla).
Comparador (`Calidad\ComparadorVisual`): luminancia por bloques de 4 px con
GD; máscaras en puntos PDF; reporta similitud por página, bandas de
encabezado/pie y zonas con diferencias.

### Fuentes (`Calidad\DiagnosticoFuentesService`)

Extrae las fuentes realmente usadas (runs de documento/encabezados/pies,
estilos usados con su cadena `basedOn`, valores por defecto, fuentes del
tema `minorHAnsi`/`majorHAnsi`, símbolos `w:sym`) y las compara con las
instaladas: Windows lee la tabla `name` de cada TTF/OTF/TTC
(`Calidad\LectorNombreFuente`); Linux usa `fc-list`. Muestra "Fuente /
Disponible / Sustitución" (sustitutos de métrica compatible: Liberation
Sans/Serif/Mono, Carlito, Caladea). Se cachea un día (`php artisan
cache:clear` tras instalar una fuente).

## Activación segura

`ImportadorFormatosJuridicosService::bloqueosActivacion()` — el botón
**Activar** queda deshabilitado mostrando las razones si:

- la versión está **bloqueada** por el registro jurídico o es de referencia;
- campos mapeados < 100 % (sin contar firmas) o hay reglas/datos pendientes;
- hay desbordes con datos largos;
- (DOCX) no hay conversor fiel en el servidor;
- falta la prueba de diseño o no pasó (`visual_validation_status != passed`).

**Excepción**: solo si lo único que falta es el QA visual, un usuario con
`documentos_maestros.activar_excepcional` (super_admin) puede activar con
motivo obligatorio (≥ 15 caracteres): queda en `activacion_excepcional_motivo`,
en la auditoría (`documento_maestro_activado_excepcion`) y visible en la
versión. Si el master cambia (reglas nuevas), el QA y la excepción se
reinician. El importador solo autoactiva la versión del registro cuando no
tiene bloqueos.

## Generación (determinista, sin IA ni OCR)

`MotorDocumentalService::generarDesdeMaestro()`:
1. `DatosDocumentoService` arma los datos (colaborador, sucursal, empresa,
   contrato, cierre, evaluación, solicitud, préstamo, datos del acto).
2. Faltantes requeridos → **422 `DATOS_FALTANTES`** con cada dato: etiqueta,
   `control` (select/fecha/hora/correo/moneda/texto), `opciones`
   (estado civil, sexo), `persistencia` (`colaborador`/`sucursal` = se guarda
   en la ficha; `documento` = solo vive en el snapshot: testigos, hora del
   acta) y si es `editable` (nunca puesto, sucursal, departamento o jefe:
   esos se corrigen en su módulo).
3. La versión debe tener el diseño validado (o excepción) → si no, **422
   `DOCUMENT_VISUAL_VALIDATION_FAILED`**.
4. DOCX: `RellenadorDocx` → `ConversorDocxPdf::convertirFiel()` (Word; o
   LibreOffice si esa versión se validó con él). PDF: overlay.
5. **Desbordes** → **422 `DOCUMENT_FIELD_OVERFLOW`** con los campos y la
   razón: overlay = un dato no cabe en su caja; Word = el documento cambia de
   número de páginas respecto al original (se señalan los datos más largos
   que el espacio del formato). Nada se guarda.
6. PDF **y** Word llenado al expediente (carpeta de su categoría),
   `GeneratedDocument` con snapshot, hashes, motor, fidelidad, versión del
   master y páginas; flujo físico (`FlujoDocumentalService`): impreso → firma
   /huella/testigos → envío → recepción → escaneo → archivo, según banderas.
   El escaneo firmado se guarda aparte; nunca reemplaza el documento
   generado.

Nombre: `EMP_0123_Juan_Perez_Contrato_de_capacitacion_inicial_2026-10-04.pdf` (y `.docx`).

Histórico: el documento conserva su snapshot, su PDF y su Word; abrirlo
nunca lo regenera. Cambiar sueldo/puesto/empresa/representante o activar
una versión nueva no altera lo emitido.

Regenerar: solo antes de imprimir (Generado/Pendiente de impresión); la
anterior queda cancelada en el historial. **Ya firmado**: no se regenera
encima; "Nueva revisión" exige `documentos_laborales.revisar_firmado`
(rh_admin, super_admin) y motivo; crea otra instancia con `revision_de_id`
y el firmado se conserva intacto (auditoría `documento_revision_firmado`).

## Datos del patrón

- Representante legal: en contratos y convenios está escrito en las
  declaraciones notariales del original (`representante: fijo_juridico`, no
  cambia). En los documentos donde es un dato (`representante: empresa`:
  aviso, acta, contrato de crédito) sale de `empresas.representante_legal_nombre`
  (Administración → Empresas → "Datos del patrón en documentos laborales");
  si está vacío se usa el predeterminado del registro (`empresa_defecto`) y
  la vista previa advierte "Usando representante legal predeterminado".
- Domicilio del patrón: fiscal o de la sucursal (Parámetros de RH); la vista
  previa muestra el domicilio usado y su fuente (fiscal / sucursal /
  predeterminado).

## Cobertura documental por puesto (`CoberturaDocumentalService`)

Administración → Documentos maestros → **Cobertura por puesto** (también
enlazada desde Parámetros de RH): cada puesto activo con su grupo y, por
columna (capacitación inicial, periodo de prueba, tiempo determinado,
indeterminado, confidencialidad, no competencia, responsivas), si tiene
formato propio validado, general, cargado sin validar, falta o no aplica.
Arriba: "N puestos tienen configuración documental incompleta" (filtra).
Todo puesto activo debe tener **decisión**: grupo documental **o** "no
requiere documentos laborales" con motivo; quitar el grupo sin esa decisión
se rechaza. `grupos_por_puesto` solo siembra puestos sin decisión
(migración y `PuestoJerarquiaSeeder`); después manda la BD. Las columnas
que cuentan para "incompleto" se configuran en
`documentos_maestros.cobertura.requeridas` (hoy: capacitación,
indeterminado, confidencialidad, no competencia; periodo de prueba y tiempo
determinado se muestran pero Jurídico no los ha entregado).

## Comandos y configuración

```bash
php artisan people:importar-formatos-juridicos            # idempotente; prepara y corre el QA visual
php artisan people:importar-formatos-juridicos --simular  # solo inventario
```

`.env`: `FORMATOS_CONVERSOR=auto|word|libreoffice|phpword`,
`FORMATOS_WORD_HABILITADO`, `FORMATOS_LIBREOFFICE_PATH`,
`FORMATOS_CONVERSOR_TIMEOUT`, `DOCUMENTOS_QA_AL_IMPORTAR`,
`DOCUMENTOS_QA_PDFTOPPM_PATH`, `DOCUMENTOS_MAESTROS_DISK`,
`DOCUMENTOS_MAESTROS_FUENTE`, `DOCUMENTOS_ALTA_GENERACION_AUTOMATICA` (false =
el paquete se genera con el botón de la ficha). Umbrales en
`documentos_maestros.validacion_visual`.

Despliegue a un servidor nuevo: instalar el conversor (Word o LibreOffice),
el rasterizador (Windows nativo o `poppler-utils`) y las fuentes de los
formatos; correr `people:importar-formatos-juridicos`; revisar en
Documentos maestros que las versiones activas digan "Diseño validado".

Un archivo nuevo de Jurídico: Documentos maestros → tarjeta → **Nueva
versión** (arrastrar el DOCX/PDF; aplica el mismo mapa; prueba el diseño;
queda inactiva) → **Probar con colaborador** (buscador por nombre/número;
pestañas Original / Generado; páginas, fidelidad, campos, desbordes,
fuentes, domicilio y representante usados) → **Activar**. Si el formato
cambió de estructura, el diagnóstico técnico muestra los pendientes y hay
que ajustar sus reglas en `config/documentos_maestros.php`.

## Endpoints (web JSON = API v1, mismo controlador)

| Método | Ruta (`/rh/…` web, `/api/v1/rh/…` app) |
|---|---|
| GET | `documentos-proceso/colaborador/{colaborador}` |
| GET | `documentos-proceso/{contrato\|cierre\|solicitud\|prestamo\|evaluacion\|entrega_activo}/{id}` |
| POST | `documentos-proceso/{tipo}/{id}/generar` `{clave, proceso?, regenerar?, revision?, motivo?, completar?, manuales?}` |
| POST | `documentos-proceso/{contrato\|cierre\|prestamo}/{id}/paquete` |
| GET | `documentos-proceso/documento/{documento}/word` (Word llenado; Policy `descargarWord`) |
| POST | `documentos-proceso/documento/{documento}/{imprimir\|firma-fisica\|envio\|recepcion\|escaneo\|archivar}` |
| POST | `cierres/{cierre}/procedimiento/{negativa\|testigos\|etapa}` |
| GET/POST/PUT | `documentos-maestros/*` (solo web, `plantillas_documentales.administrar`): índice, `cobertura`, `cobertura/puestos/{puesto}`, `colaboradores?q=`, `{master}`, `familia/{familia}/versiones`, `{master}/validar-diseno`, `{master}/activar` `{motivo_excepcional?}`, `{master}/desactivar`, `{master}/probar`, `{master}/prueba/{token}`, `{master}/original`, `{master}/original-pdf` |

Cada documento del proceso trae `estado`, `estado_etiqueta` (Listo para
generar, Generado, Firma pendiente, Firmado, Escaneado, Enviado, Recibido,
Archivado, Formato no cargado, Formato sin validar), `master.etiqueta`
("Gestor v2"), `linea_tiempo` (pasos hecho/actual/pendiente según las
banderas del formato), `siguiente_accion` (la primaria) y `acciones` (una
sola primaria; el resto secundarias: Ver PDF, Descargar Word, Regenerar,
Nueva revisión). Web y app solo lo pintan.

Seguridad: alcance organizacional o cadena de mando sobre la persona;
permiso por documento (`documentos_maestros.permisos_generacion`); Policy de
`GeneratedDocument` (`descargar`, `descargarWord` — nunca el colaborador
titular —, `operar`); el registro se resuelve desde la URL (nunca ids del
cuerpo); "Probar con colaborador" valida el alcance de quien prueba;
descargas siempre por endpoint autorizado; `withTrashed` en colaboradores
para el historial de bajas.

## Diseño de página y fondos

Además del original de Jurídico, una familia puede tener un **diseño de
página** (`LayoutDocumentoService`). Nunca toca el texto jurídico; se aplica
al DOCX ya llenado y antes de convertir (`Docx\AplicadorLayoutDocx`):

- **Fondo**: imagen anclada **detrás del texto** (`behindDoc`, `wrapNone`,
  posición relativa a la página x=0 y=0, tamaño de la hoja) en el primer
  párrafo de cada encabezado de cada sección → se repite en todas las
  páginas y no empuja texto ni cambia saltos. Nunca es un `<img>` inline.
  Ajuste `stretch` (hoja exacta), `contain` o `cover` (centrado); opacidad
  con `alphaModFix`; «Primera página» usa un encabezado `first` (titlePg).
- **Logo**: con `header_logo_enabled = false` se retiran los dibujos del
  encabezado original (el fondo viejo de Jurídico) para no duplicar logo.
- **Márgenes** (`page.margins_cm`) y **párrafos normales**: sangría izquierda
  (0.64 cm en el preset de indeterminados), derecha, espacio antes/después,
  justificado y «mantener líneas juntas». No se tocan títulos centrados,
  listas numeradas, tablas, firmas, cuadros de texto, párrafos con imagen ni
  párrafos que traen su propia sangría. Margen de página ≠ sangría de párrafo.

Herencia: **preset** (`document_layout_presets`) → **familia**
(`document_family_layouts.overrides`) → **versión**
(`document_templates.layout_overrides`). Un `null` explícito gana (una
familia puede decir «sin fondo» sobre un preset con fondo).

Biblioteca (`document_assets`: fondo, logo, marca de agua, imagen): PNG/JPG
(WEBP se convierte a PNG). Identidad por **SHA-256**; reemplazar crea la
versión siguiente (mismo slug) y mueve presets/familias a ella; los
documentos ya generados conservan la suya. Solo se elimina un fondo sin uso.
Área segura por fondo (`safe_area_*_mm`): si márgenes + sangría invaden la
zona gráfica se avisa («El contenido invade el área gráfica del fondo»); no
se mueve nada solo.

Snapshot: `generated_documents.layout_snapshot` guarda hash del diseño,
preset, márgenes, párrafo, logo y fondo (id, slug, versión, SHA-256, ajuste,
opacidad, aplicar a). Desbordes: con diseño, el número de páginas esperado
es el del original **con ese diseño** (`document_templates.layout_qa`, por
hash; se calcula una vez con Word al primer uso).

Preset inicial: «Contrato indeterminado MR. LANA» con fondo
`docs/imgBG/bgDocs.png` («MR. LANA — Fondo contrato indeterminado», slug
`mr-lana-contrato-indeterminado`), todas las páginas, estirar, 100 %, sin
logo extra, sangría 0.64 cm, márgenes 2.9/3.0/2.8/3.0 cm (libran el logo y
la ola del fondo). Solo para `contrato_indeterminado.*`; los demás documentos
no heredan fondo.

```bash
php artisan migrate
php artisan documentos:instalar-fondo-indeterminado --solo-regional   # primero la Regional
php artisan documentos:instalar-fondo-indeterminado                   # luego todas las indeterminadas
```

UI: Documentos maestros → **Fondos** (biblioteca) y, en cada documento,
**Diseño de página** (preset, fondo, ajuste, opacidad, sangría, vista
previa con mostrar/ocultar fondo y guías de área segura que nunca salen en
el PDF).

## Limitaciones conocidas

- `PERMISO EXTRAORDINARIO CON GOCE DE SUELDO.docx` usa la fuente **Aptos**,
  no instalada en el servidor de desarrollo: su versión queda "Diseño no
  validado" y no genera documentos hasta instalar la fuente (o que Jurídico
  lo guarde con Century Gothic).
- Con datos muy largos simultáneos, `contrato_capacitacion.gerente` v1
  (inactiva), `contrato_indeterminado.administrativo_no_confianza` y
  `contrato_indeterminado.coordinadora` crecen una página (párrafos vacíos al
  final del original). Advertencia visible; si una persona real lo provoca,
  la generación se bloquea con `DOCUMENT_FIELD_OVERFLOW`.
- Carta responsiva, contrato de periodo de prueba y de tiempo determinado:
  sin formato de Jurídico.
- La solicitud de permiso aún no captura en su formulario `hora_salida`,
  `hora_entrada` ni `modalidad_permiso` (columnas creadas); el formato las
  marca cuando existan.
