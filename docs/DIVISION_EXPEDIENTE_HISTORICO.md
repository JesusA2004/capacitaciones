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

- Las reglas son por **clave real** de `document_types`: los 14 del checklist (`DocumentTypeSeeder`).
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
