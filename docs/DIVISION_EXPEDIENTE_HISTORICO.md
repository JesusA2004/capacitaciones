# División del PDF histórico por checklist

La migración inicial (`docs/MIGRACION_INICIAL_EXPEDIENTES.md`) dejó a cada colaborador con **un solo PDF** en el NAS, `{expediente}/Historico/Expediente historico unificado.pdf`, que trae todo junto. Esta función **lo separa por documento del checklist** para que el sistema sepa si el expediente está completo. Además reporta qué datos personales del PDF **faltan o no cuadran** en el sistema.

## Reglas que no cambian

- **El original nunca se corta, mueve ni borra.** Las partes son **copias**, en `{expediente}/Historico/Division por checklist/{id de división}/NN - {Documento} (p. 1-3).pdf`.
- **Nada se aprueba solo.** Lo clasificado entra al checklist como versión **en revisión** (`employee_documents.origen = division_historico`), y RH lo aprueba en el expediente con el flujo normal. El avance (`ProgresoExpediente`) cuenta un documento solo cuando está aprobado.
- **No se reemplaza lo que ya existe.** Si el colaborador ya tiene un documento vigente **aprobado, en revisión, cargado o con cambio solicitado**, la parte queda como **«Ya había un documento vigente»**. La copia se conserva y RH puede cargarla a mano si la prefiere. Un vigente **rechazado, vencido o que requiere corrección** sí recibe la parte como versión nueva en revisión.
- **Las páginas sin clasificar se reportan explícitamente**, en las advertencias y en el detalle por página con su motivo. Quedan como partes «Sin clasificar», un tramo por cada grupo de páginas consecutivas, para que RH las asigne a mano.
- **Los datos nunca se aplican solos.** RH confirma cada campo. Solo puede elegir uno de los valores detectados, nunca texto libre. Nadie aplica datos sobre **su propio expediente**.
- Solo se procesan históricos **vinculados** a un colaborador. Los «pendientes de vincular» nunca se tocan.

## Cómo funciona

`App\Services\Expedientes\DivisionHistorico\*`:

| Clase | Responsabilidad |
|---|---|
| `DivisionExpedienteHistoricoService` | Orquesta, es idempotente (mismo SHA-256 + `version_reglas` = no se repite sin `--forzar`), usa un candado por histórico, asigna a mano, aplica datos y arma la pantalla |
| `LectorPaginasPdf` | Texto de **cada página**: primero la capa de texto (`smalot/pdfparser`); si la página no la tiene (menos de `min_caracteres_texto`), OCR local |
| `Ocr\MotorOcr` | Interfaz del OCR. Drivers: `TesseractMotorOcr` (pdftoppm + tesseract `spa`) y `SinMotorOcr`. Se elige con `EXPEDIENTES_OCR_DRIVER` (AppServiceProvider) |
| `ClasificadorPaginas` | Puntaje por reglas de `config/expedientes.php → division_historico.reglas` y agrupación en partes |
| `CortadorPdf` | Copia las páginas con FPDI. Si el PDF usa cross-reference streams, primero lo pasa por `NormalizadorPdf` (en memoria) |
| `ComparadorDatosHistorico` | Corre el `RegexPersonalDataExtractor` existente **por parte** y compara contra el colaborador con `DocumentExtractionService::compararCampo()` |

### Clasificación

- Las reglas son por **clave real** de `document_types`: los 14 del checklist (`DocumentTypeSeeder`) más sus opcionales; las hojas de cartas de recomendación van a la carta 1 (obligatoria) y, si RH quiere, pasa la segunda carta a la carta 2 (opcional) en el editor.
- Los 3 **opcionales del checklist** (pagaré, carta de antecedentes no penales, licencia de conducir — ver `docs/EXPEDIENTES_DIGITALES.md`) se pueden **asignar a mano** (por parte o en el editor de hojas), pero todavía no tienen reglas de OCR: sus hojas quedan «sin clasificar» hasta que RH las asigna. Asignarlos nunca cambia el avance ni los faltantes.
- El selector «Documento del checklist» es un combobox con buscador (`resources/js/components/ui/combobox-buscable`): lista completa al abrir, filtro sin acentos por nombre, clave y sinónimos (`resources/js/lib/opcionesDocumentoChecklist.ts`), agrupado en Requeridos / Opcionales; al asignar una parte, lo que ya está en el expediente aparece deshabilitado («Ya en el expediente», `detalle.tipos_cubiertos`).
- **Puntaje:** una frase *fuerte* vale 3 puntos (+2 si está en el encabezado de la página) y una *débil* vale 1. Gana el tipo con más puntos si alcanza `puntaje_minimo` (2).
- **Empate** → «Ambigua», sin clasificar. Nunca se elige uno al azar.
- **Confianza:** alta (≥ 5 puntos y ventaja ≥ 2), media o baja (ventaja de 1 punto, o página de continuación).
- **Continuación** (`continuacion: true`: solicitud, contratos, confidencialidad, no competencia): una hoja con texto sin coincidencias, o cuyo mejor tipo es el de la hoja anterior, se une a esa hoja con confianza baja.
- Todas las páginas de un mismo tipo forman **una** parte, aunque no sean consecutivas.
- **Fotografías:** no traen texto, así que casi siempre quedan sin clasificar y RH las asigna.
- Al cambiar una regla, sube `version_reglas` y vuelve a dividir con `--forzar` o con «Volver a dividir».

### Datos faltantes / discrepancias

- De cada documento se aceptan solo los campos que tiene sentido leer de él (`reglas.{tipo}.campos`). Por ejemplo, un número de 11 dígitos en un recibo de luz no se toma como NSS.
- Estados del dato (enum `EstadoDatoDetectado`):
  - **Falta en el sistema:** RH aplica.
  - **Diferente a lo capturado:** RH decide; se pide confirmación antes de reemplazar.
  - **Los documentos no coinciden entre sí:** RH elige uno.
  - **Coincide**
  - **Aplicado por RH**
- Se aplica con `DocumentExtractionService::aplicarAlColaborador()`, la misma regla de enums y fechas que las sugerencias de extracción.
- **El nombre nunca se extrae** del PDF. Solo se verifica que el nombre capturado aparezca en el texto. Si no aparece, sale una advertencia («confirma que el expediente sea de esta persona»).

## Uso

**Web:** Expediente → Documentos → «Expediente histórico» → **Dividir por checklist y revisar datos** (`/rh/expedientes/division-historico/{colaborador}`). La pantalla muestra:

- disponibilidad de OCR;
- el checklist: **completo**, **completo en cuanto RH apruebe** o **incompleto, con lo que falta**;
- las partes, con sus páginas, tipo, confianza y resultado, más Ver y «Asignar a…»;
- los datos con «Aplicar» por valor;
- el detalle página por página.

Dividir corre en cola (`DividirExpedienteHistoricoJob`).

**Consola:**

```bash
php artisan expedientes:dividir-historico --colaborador=15 --simular   # no escribe nada
php artisan expedientes:dividir-historico --todos --simular
php artisan expedientes:dividir-historico --todos                     # ejecuta (actor: primer super_admin)
php artisan expedientes:dividir-historico --todos --forzar --usuario="Nombre Apellido"
```

### Permisos

Se reusan los existentes y además se valida el alcance (`AlcanceOrganizacionalService::puedeVerExpediente`):

| Acción | Permiso |
|---|---|
| Ver la pantalla y las partes | `rh.documentos.extraccion.ver` |
| Dividir | `rh.documentos.extraccion.reprocesar` |
| Asignar una parte | `expedientes.editar` |
| Aplicar un dato | `rh.documentos.extraccion.aplicar` |

El propio expediente devuelve 403.

## Corregir hojas a mano (editor de hojas)

Cuando el OCR deja un bloque sin clasificar (p. ej. 5-7) o no detecta que las hojas 9-11 siguen al contrato que empieza en la 8, RH lo corrige **hoja por hoja** en **Dividir PDF histórico → Corregir hojas** (`/rh/expedientes/division-historico/{división}/editor`).

- **Desde cada parte** de la tabla «Documentos encontrados», el botón **Separar hojas** (o «Corregir hoja») abre el editor con esas hojas ya seleccionadas (`?hojas=3,4`). Es el camino para el caso «el OCR juntó 2 hojas como INE y solo una es la INE»: «Asignar a…» solo existe para partes que todavía no se cargaron al checklist.
- **Miniaturas de todas las hojas**, dibujadas en el navegador con pdf.js desde el PDF original (una sola descarga; cada miniatura se dibuja al entrar en pantalla). Cada hoja muestra su clasificación con color por tipo; «Sin clasificar» va resaltado con borde punteado.
- **Selección:** clic, Mayús+clic (rango), Ctrl/Cmd+clic (sumar), o escribiendo «5-7, 9». Con teclado: flechas, Espacio, Mayús+flechas, Enter (ver en grande), Ctrl+A, Esc. En móvil cada toque suma o quita y las acciones salen en una barra inferior.
- **Acciones:** asignar tipo (por hoja o en lote; si ya hay un documento de ese tipo, las hojas se le unen, salvo «como documento aparte»), «Continúa el documento anterior» (las hojas se unen al documento de la hoja previa, en el MISMO documento), «Nuevo documento desde esta hoja» (partir), «Mover a…» y reordenar hojas dentro del documento. Zoom de cualquier hoja.
- **Deshacer/rehacer** (Ctrl+Z / Ctrl+Y) durante la sesión. Nada cambia en el expediente hasta **Guardar**, que primero muestra la **vista previa**: documentos resultantes (tipo, hojas, nº de páginas, qué pasará en el expediente), el PDF de cada uno (armado en memoria, no se guarda) y advertencias: hojas que quedan sin clasificar, documentos requeridos que siguen faltando, tipos repetidos (el expediente guarda una versión vigente por tipo), tipos ya cubiertos por otro origen y documentos que se quedan sin hojas.

Al **confirmar** (`EditorPaginasDivisionService::confirmar`):

1. Se generan los PDFs solo de los documentos que cambiaron, en `…/Division por checklist/{división}/Revision {n}/`. El PDF original nunca se toca.
2. En una `DB::transaction`: se crea la revisión `n` (`revisiones_division_historico`: plan, hash, auditoría hoja por hoja «de → a», advertencias, autor); las partes anteriores quedan con `reemplazada_por_revision_id` (no se borran, ni su copia); nacen las partes nuevas (`revision_id`).
3. En el expediente: si el tipo vino de este PDF, se sube **nueva versión en revisión** (la anterior se archiva con `subirVersion`); si el tipo no estaba cubierto, se carga en revisión; si ya hay un documento vigente de otro origen, no se reemplaza (solo se guarda la copia). Un documento de este PDF que se queda sin hojas se **archiva** si seguía en revisión; si ya estaba aprobado no se toca y se advierte.
4. Bitácora: `expediente_historico.hojas_corregidas` (AuditoriaService). «Asignar a…» de una parte registra `expediente_historico.parte_asignada` y escribe documento + parte + hojas en una sola `DB::transaction`; una parte ya reemplazada por una corrección, o de una división vieja, se rechaza.

Si FPDI o el NAS fallan al guardar, RH ve el motivo (error de validación, nunca un 500) y nada cambia en el expediente. Un reintento no choca con la copia que alcanzó a escribirse en `Revision {n}/`: si es idéntica se reutiliza y si no, la nueva se guarda con sufijo « (2)».

Es **idempotente**: el mismo plan dos veces no crea nada la segunda. Con concurrencia, si otra persona guardó antes, la revisión base vieja se rechaza («recarga»). Solo se corrige la división más reciente de cada PDF.

**Volver a dividir no pisa lo corregido:** las hojas corregidas quedan `manual` en `divisiones_expediente_historico.paginas` (con `tipo_ocr` = lo que dijo el OCR). Al re-procesar el mismo archivo (mismo SHA-256), cada documento con al menos una hoja manual se conserva entero tal como RH lo dejó y el OCR solo clasifica el resto; se agrega la advertencia «Se respetaron las hojas que RH corrigió a mano». Asignar una parte completa con «Asignar a…» también marca sus hojas como manuales.

| Acción del editor | Permiso |
|---|---|
| Abrir el editor, vista previa y PDF de vista previa | `rh.documentos.extraccion.ver` |
| Guardar la corrección | `expedientes.editar` |

Con alcance organizacional y nunca sobre el propio expediente (403). Pruebas: `tests/Feature/Expedientes/EditorHojasDivisionTest.php` (PDF sintético de 12 hojas).
## OCR en el servidor (Linux VPS)

```bash
sudo apt-get install -y tesseract-ocr tesseract-ocr-spa poppler-utils
tesseract --version && tesseract --list-langs | grep spa && pdftoppm -v
```

Variables (opcionales, ver `.env.example`):

- `EXPEDIENTES_OCR_DRIVER`: `tesseract` o `ninguno`.
- `EXPEDIENTES_TESSERACT_PATH`
- `EXPEDIENTES_PDFTOPPM_PATH`
- `EXPEDIENTES_OCR_IDIOMA` (por defecto `spa`)
- `EXPEDIENTES_OCR_DPI` (por defecto 200)
- `EXPEDIENTES_OCR_TIMEOUT` (segundos por página)

**Sin OCR la división no falla:** las páginas escaneadas quedan con «Página escaneada sin OCR disponible» y se reportan. Nunca se usa un servicio externo: el documento no sale del servidor.

## Tablas

- `divisiones_expediente_historico`: una fila por corrida. Guarda el hash leído, `version_reglas`, el OCR usado, la clasificación de **cada página**, los datos detectados, las advertencias, el error y quién la ejecutó.
- `partes_division_historico`: una fila por parte. Guarda las páginas, el tipo, la confianza, el estado (`EstadoParteDivision`), la copia en el NAS y el `employee_document_id` cuando se cargó al checklist.

Pruebas: `tests/Feature/Expedientes/DivisionExpedienteHistoricoTest.php`. Usan un PDF de 6 páginas generado con FPDF y un OCR falso.
