# Extracción automática de datos personales

Sugiere a RH datos personales (CURP, RFC, NSS, fecha de nacimiento, código postal,
sexo) detectados automáticamente en un documento de expediente ya subido, para que RH
los compare contra lo capturado y decida si los aplica. Tabla `document_extractions`
(migración `2026_09_10_100000_create_document_extractions_table`), modelo
`App\Models\DocumentExtraction`, enum `App\Enums\EstadoExtraccion`.

## ⚠️ Limitación importante: solo lee PDF con texto, no hace OCR

Esta primera versión usa **`smalot/pdfparser`** (`composer.json`), que extrae texto de
un PDF **solo si el PDF ya trae texto seleccionable** (por ejemplo, un PDF generado
digitalmente). **No hace OCR**: no puede leer el texto de una imagen.

En la práctica, esto significa que:

- Un **PDF con texto real** (poco común para una INE, pero posible en comprobantes de
  domicilio digitales o actas descargadas de un portal de gobierno) **sí se procesa**.
- Una **foto/escaneo de INE en JPG/PNG**, o un **PDF que es en realidad una imagen
  escaneada sin capa de texto** (el caso más común para identificaciones), **no se
  puede procesar automáticamente**. La extracción queda en estado `failed` con un
  mensaje claro para RH ("revisa manualmente"); el documento sigue subido y disponible
  en el expediente con normalidad, solo sin sugerencias automáticas.

Integrar OCR real (Tesseract, un servicio cloud de OCR, o extracción de layout de una
INE) queda **fuera de alcance de esta fase** y es la mejora natural siguiente si RH
necesita cobertura para documentos escaneados/fotografiados.

## Flujo

1. RH (o el colaborador, según el tipo de documento) sube un documento elegible al
   expediente (`POST rh/expedientes/{colaborador}/documentos`, mismo endpoint de
   siempre — ver `docs/EXPEDIENTES_DIGITALES.md`). Son elegibles los tipos en
   `DocumentExtractionService::TIPOS_ELEGIBLES`: `ine`, `curp`, `rfc`, `nss`,
   `acta_nacimiento`, `comprobante_domicilio`. El resto de tipos (contrato, recibo,
   fotografía, etc.) no dispara extracción.
2. `DocumentoStorageService` (mismo servicio que guarda cualquier documento del
   expediente) encola `App\Jobs\ProcesarDocumentoPersonalJob` de forma asíncrona si el
   tipo es elegible — **nunca bloquea ni puede romper la subida del documento**: el
   `EmployeeDocument` ya quedó guardado antes de encolar el job, y si el job falla, el
   documento sigue existiendo con normalidad, solo sin extracción.
3. El job llama a `DocumentExtractionService::procesar()`:
   - Lee el archivo del disco `nas` (`DocumentoStorageService`). Si falla la lectura,
     la extracción queda `failed`.
   - Si el archivo no es un PDF (por extensión o por firma `%PDF-`), queda `failed` con
     el mensaje de "revisa manualmente" de la sección anterior.
   - Si es PDF, intenta sacar el texto con `smalot/pdfparser`. Si el PDF no tiene texto
     (escaneo sin capa de texto) o el parseo truena, queda `failed` — nunca lanza una
     excepción no controlada (todo el parseo está en try/catch).
   - Con el texto obtenido, `RegexPersonalDataExtractor::extraer()` busca por regex:
     CURP, RFC, NSS (11 dígitos), código postal (cerca de "C.P."), sexo (cerca de
     "SEXO") y fecha de nacimiento (cerca de la palabra "NACIMIENTO", para no confundir
     con fecha de expedición/vigencia). Deliberadamente **no** intenta adivinar nombre,
     apellidos ni domicilio completo por regex (alto riesgo de falso positivo en texto
     libre) — queda para una iteración futura con OCR + layout.
   - Compara cada dato detectado contra el valor actual del colaborador
     (`calcularDiferencias()`) y guarda el resultado como `processed`.
4. RH revisa la extracción desde el expediente del colaborador (pestaña/panel de
   documentos): ve qué se detectó, con qué confianza (`alta`/`media`), y si coincide o
   no con el dato ya capturado.
5. RH decide, por documento:
   - **Aplicar** (`POST .../extraccion/aplicar`): copia los valores indicados (el
     detectado tal cual, o uno corregido a mano por RH — no se distingue) a las
     columnas reales de `users` (`curp`, `rfc`, `nss`, `fecha_nacimiento`; código
     postal y sexo se muestran como "detectado" pero no tienen columna propia en
     `users`, así que no se pueden aplicar directo). Queda `reviewed`.
   - **Ignorar** (`POST .../extraccion/ignorar`): descarta la sugerencia sin tocar al
     colaborador. Queda `reviewed`.
   - **Reprocesar** (`POST .../extraccion/reprocesar`): vuelve a correr `procesar()`
     sobre el mismo documento (por ejemplo, si RH subió una versión nueva y mejor del
     archivo).
   - **Nunca se aplica nada automáticamente**: `procesar()`/`reprocesar()` solo llenan
     la sugerencia; solo `aplicar()` modifica al colaborador, y solo con acción
     explícita de RH.

## Rutas web

`app/Http/Controllers/Rh/DocumentExtraccionController.php`, dentro de
`rh/documentos/{documento}/extraccion` (ver `routes/rh.php`):

- `GET rh/documentos/{documento}/extraccion` — ver la extracción (`show`).
- `POST rh/documentos/{documento}/extraccion/aplicar` — aplicar datos.
- `POST rh/documentos/{documento}/extraccion/ignorar` — ignorar.
- `POST rh/documentos/{documento}/extraccion/reprocesar` — reprocesar.

Las cuatro acciones validan el permiso correspondiente **y** que el usuario tenga
alcance organizacional sobre el colaborador dueño del documento
(`AlcanceOrganizacionalService::puedeVerExpediente()`), igual que el resto de acciones
sobre `EmployeeDocument` — un `rh_auxiliar` con alcance limitado a su sucursal no puede
ver ni tocar la extracción de un documento fuera de su alcance.

## API móvil

`app/Http/Controllers/Api/V1/Rh/DocumentoController.php` (ver `routes/api.php` y
`docs/RH_MOBILE_API.md`):

- `GET /api/v1/rh/.../{documento}/extraccion` — ver.
- `POST /api/v1/rh/.../{documento}/extraccion/aplicar` — aplicar.
- `POST /api/v1/rh/.../{documento}/extraccion/ignorar` — ignorar.

Reprocesar solo está disponible en el panel web por ahora (no hay caso de uso urgente
para reprocesar desde la app). Los tres endpoints móviles respetan
`AlcanceOrganizacionalService` igual que el resto de la API móvil de RH.

## Qué nunca se expone

- El `path` físico del documento en el NAS: nunca viaja en las respuestas JSON/Inertia,
  igual que el resto de documentos del expediente.
- El texto completo extraído del PDF (`extracted_text`, limitado a 20,000 caracteres
  para no inflar la fila) solo se guarda para depuración/auditoría interna; el
  frontend consume `extracted_data`/`differences`, no el texto crudo.

## Permisos

- `rh.documentos.extraccion.ver` — ver la extracción de un documento.
- `rh.documentos.extraccion.aplicar` — aplicar datos al colaborador.
- `rh.documentos.extraccion.ignorar` — descartar sugerencia.
- `rh.documentos.extraccion.reprocesar` — reprocesar.

`rh_admin` tiene los cuatro; `rh_auxiliar` tiene `ver` y `reprocesar` pero no
`aplicar`/`ignorar` (la decisión final de aceptar/descartar datos personales queda con
`rh_admin`) — ver `database/seeders/RolesYPermisosSeeder.php`.

## Requisito de infraestructura

`ProcesarDocumentoPersonalJob` corre en la cola por defecto (`config('queue.default')`).
En VPS, esto requiere un worker de cola corriendo (`php artisan queue:work` vía
supervisor/systemd) para que la extracción se procese — si no hay worker activo, la
extracción queda `pending` indefinidamente hasta que uno la recoja. Ver `docs/DEPLOY.md`.

## Fuera de alcance en esta fase

- OCR de imágenes/escaneos (JPG, PNG, PDF sin texto) — la limitación principal de esta
  versión, ver arriba.
- Extracción de nombre, apellidos o domicilio completo por regex.
- Aplicación automática sin revisión de RH.
