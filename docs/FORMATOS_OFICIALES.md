# Formatos oficiales de MR. LANA

Tab "Oficiales PDF" (y "Configuración de campos") dentro del menú único **Formatos**
(`/rh/formatos`, ver `docs/PLANTILLAS_FORMATOS.md` para el resto de tabs): a diferencia
de las plantillas DOCX editables, aquí los documentos son **oficiales y fijos** — RH
nunca los sube ni los cambia desde el sistema. El sistema solo selecciona colaborador,
precarga sus datos y pinta ese texto **encima** del PDF oficial (overlay), sin tocar el
contenido original.

Si `claude/formatos/originales/` no tiene PDFs importados todavía, la tab "Oficiales
PDF" no truena ni queda en blanco: muestra el aviso "No hay formatos oficiales
importados. Sube los PDFs oficiales y ejecuta
`php artisan formatos:importar-originales`." (`CrudEmptyState` en
`Rh/FormatosOficiales/Index.vue`).

Tablas `official_formats` y `official_format_generations` (migraciones
`2026_09_11_090000_create_official_formats_table` y
`2026_09_11_090001_create_official_format_generations_table`), modelos
`App\Models\OfficialFormat` / `App\Models\OfficialFormatGeneration`.

## Por qué overlay y no editar el PDF

Los formatos reales de MR. LANA (`claude/formatos/originales/`, ver abajo) son PDFs con
membrete, diseño y texto legal fijo — no son plantillas para reescribir. En vez de
intentar "editarlos" como si fueran Word, `App\Services\Formatos\OfficialFormatOverlayService`
usa **`setasign/fpdi-fpdf`**: importa cada página del PDF original como plantilla y
escribe el dato del colaborador encima, en las coordenadas configuradas. El PDF
original nunca se modifica; el resultado es un PDF nuevo.

Si algún formato futuro llega en DOCX en vez de PDF, la generación por overlay no
aplica — `file_type` queda registrado como `docx` y esos casos se rechazan
explícitamente (`422`) hasta que se implemente un camino con PHPWord
`TemplateProcessor` (mismo motor que ya usa `docs/PLANTILLAS_FORMATOS.md`). Ningún
formato real de hoy (los 12 de `claude/formatos/originales/`) es DOCX.

## Importar los PDFs oficiales

```bash
php artisan formatos:importar-originales
```

- Lee `config('formatos_oficiales.origen_local')` (`claude/formatos/originales/` por
  defecto, **fuera de Git** salvo `.gitkeep` — ver `claude/formatos/README.md`).
- Cada archivo real se mapea por **nombre exacto** a un slug/tipo fijo en
  `App\Console\Commands\ImportarFormatosOriginalesCommand::CATALOGO` — un PDF nuevo que
  no esté en ese catálogo se reporta como "sin mapear" y se omite (nunca se adivina el
  tipo/nombre de un archivo desconocido).
- Copia el PDF al disco `nas` (`config('formatos_oficiales.disk')`,
  `formatos-oficiales/originales/{slug}.pdf`, ruta determinista por slug) y hace
  `updateOrCreate` por `slug`: correrlo de nuevo actualiza el mismo registro/archivo, no
  duplica. Renombrar el PDF local sin actualizar `CATALOGO` rompe el mapeo a propósito
  (obliga a decidir el slug/tipo del archivo nuevo, no a adivinarlo).
- Es seguro correrlo en cualquier ambiente (`|| true` en el runbook de deploy): si la
  carpeta local no existe o está vacía, no hace nada y termina en éxito.

## Flujo para RH

1. `/rh/formatos` muestra un catálogo de cards, una por `OfficialFormat` activo:
   nombre, tipo, último generado, y badge **"Listo"** o **"Falta configurar"**
   (`OfficialFormat::tieneConfiguracion()` — al menos un campo con `enabled: true` en
   `overlay_config`).
2. RH da clic en **"Generar"**, elige colaborador o candidato. El sistema precarga sus
   datos automáticamente (mismo `App\Services\Plantillas\PlaceholderResolver` que las
   plantillas DOCX — no se duplica esa lógica) y los muestra en pantalla **antes** de
   generar.
3. Si falta un dato, se muestra como advertencia con un campo para escribirlo **solo
   para ese documento** (`extra`, nunca se guarda en el expediente).
4. Botón **"Vista previa"**: genera el overlay real (con los datos reales del
   colaborador) sin persistir nada, y lo muestra embebido.
5. Botón **"Generar documento"**: genera el PDF final, lo guarda en el disco `nas` y
   crea un `OfficialFormatGeneration` con `generated_by_id` (quién lo generó) y
   `data_snapshot` (los valores realmente usados, para auditoría aunque el colaborador
   cambie sus datos después).
6. Botón **"Descargar PDF"**.

RH **nunca** ve un catálogo editable ni puede cambiar el documento base — esa es la
diferencia deliberada con `/rh/plantillas` (ver "Plantillas avanzadas" abajo).

## Configurador visual de posición de datos

`/rh/formatos-oficiales/{formato}` (solo `formatos_oficiales.configurar`, normalmente
`rh_admin`/`super_admin`):

- Muestra el PDF original embebido (`GET .../original`, streaming, nunca expone
  `source_path`) junto a un formulario con **un campo por dato disponible**
  (`OfficialFormatOverlayService::CAMPOS_DISPONIBLES` — nombre completo, puesto,
  sucursal, CURP, fechas de permiso, etc.).
- Cada campo tiene: `enabled` (checkbox), `pagina`, `x`, `y` (milímetros, origen
  esquina superior izquierda — mismo default de FPDF/FPDI), `font_size`, `align`
  (izquierda/centro/derecha), `max_width` (mm, envuelve el texto con `MultiCell` si no
  cabe en una línea) y `color` (hex).
- Botón **"Actualizar vista previa"**: genera el overlay con **datos de ejemplo**
  ficticios (`OfficialFormatOverlayService::datosMuestra()`, nunca un colaborador real)
  usando la configuración actual del formulario (aunque no se haya guardado todavía) y
  lo muestra embebido al lado del original — así RH ajusta coordenadas por prueba y
  error sin tener que guardar cada intento.
- Botón **"Guardar configuración"**: persiste `overlay_config`.
- Es la "versión simple" a propósito (formulario con coordenadas, no arrastrar y
  soltar): un formato sin configurar muestra "Este formato necesita configurar dónde se
  colocarán los datos" en vez de romperse.

## Qué nunca se expone

- `source_path`/`source_disk` (PDF original) y `generated_path`/`generated_disk` (PDF
  generado) están en `$hidden` en ambos modelos — ninguna respuesta JSON/Inertia los
  incluye. La descarga siempre pasa por streaming (`OfficialFormatStorageService::respuesta()`).
- El texto detectado del colaborador que se muestra en el diálogo de generación es solo
  el que corresponde a campos **configurados** en ese formato (`datosVisibles()` en el
  controller) — nunca todos los datos personales disponibles.

## Permisos

- `formatos_oficiales.ver` — ver el catálogo.
- `formatos_oficiales.generar` — generar/previsualizar un documento.
- `formatos_oficiales.descargar` — descargar un PDF ya generado.
- `formatos_oficiales.configurar` — configurar dónde se pintan los datos (solo
  `rh_admin`/`super_admin`; `rh_auxiliar` y `gerente_sucursal` generan/descargan pero no
  configuran).

Igual que el resto de RH, la descarga y la generación respetan
`AlcanceOrganizacionalService`: no se puede generar/descargar un documento de un
colaborador fuera del alcance del usuario.

## "Plantillas avanzadas" (motor DOCX, no la experiencia principal)

El módulo anterior de plantillas DOCX editables (`App\Services\Plantillas\PlantillaDocumentoService`,
`Rh\FormatoController`) **no se eliminó**: sigue siendo el motor que usa el botón
"Generar formato" dentro de una Solicitud (`GenerarFormatoDialog.vue`), y sigue
disponible para uso avanzado en `/rh/plantillas` ("Plantillas avanzadas", solo
`plantillas.crear` — `rh_admin`/`super_admin`) → botón "Documentos generados"
(`/rh/formatos/catalogo`, el catálogo/tablero que antes vivía en `/rh/formatos`).

RH operativo (`rh_auxiliar`, `gerente_sucursal`) ya no ve ese catálogo como su pantalla
principal de "Formatos": ese nombre y esa ruta (`/rh/formatos`) ahora son de este módulo
(formatos oficiales fijos). Ver `docs/PLANTILLAS_FORMATOS.md` para el motor DOCX.

## Generación automática desde una solicitud

Además de la generación manual descrita arriba (RH elige colaborador/candidato desde
`/rh/formatos-oficiales`), una `OfficialFormatGeneration` también puede nacer
automáticamente al aprobar una `SolicitudInterna` — ver `docs/SOLICITUDES_UNIFICADAS.md`
y `App\Services\Solicitudes\SolicitudFormatoOficialService`. En ese caso la fila queda
con `solicitud_interna_id` distinto de null, `status` (`generado`/`firmado`) y, tras
subir el firmado (`FormatoOficialController::subirFirmado()`), los campos `signed_*`.
`Rh/Solicitudes/Show.vue` la muestra en su propia sección "Documento oficial",
separada de "Documentos adicionales" (plantillas DOCX manuales de la sección anterior).

## Fuera de alcance en esta fase

- Configurador drag-and-drop pixel-perfecto sobre el PDF (la versión actual es un
  formulario de coordenadas + vista previa, explícitamente aceptado como "versión
  simple" para esta fase).
- Overlay sobre formatos DOCX (todos los formatos reales de MR. LANA hoy son PDF).
- API móvil para formatos oficiales (por ahora es una pantalla de escritorio/RH).
