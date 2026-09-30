# QA de formatos — de cero a expediente, paso por paso

Guía exacta para probar el motor de plantillas DOCX editables (docs/PLANTILLAS_FORMATOS.md)
de principio a fin, con un documento de prueba real. Para escenarios más profundos
(nueva versión de plantilla, variable obligatoria bloqueando la generación por API
directa, etc.) ver `docs/PRUEBA_MAESTRA_FORMATOS.md` — este documento es el recorrido
lineal "cómo empiezo de cero", aquel es el checklist de casos borde.

No confundir los dos módulos de formatos (ver docs/PLANTILLAS_FORMATOS.md, nota al
inicio):

- **Oficiales PDF** (`docs/FORMATOS_OFICIALES.md`): PDFs fijos de MR. LANA con overlay
  por coordenadas — no es lo que prueba esta guía.
- **Plantillas avanzadas DOCX** (este documento): un `.docx` que RH sube, con
  placeholders `{{...}}`, que el sistema conserva tal cual (logos, tablas, bordes,
  firmas, tipografía) y solo reemplaza los marcadores.

## Paso 0 — crear el Word de prueba

No hay (ni debe haber) un documento empresarial real de prueba en el repo. Crea uno tú
mismo en Word/LibreOffice con este contenido — cópialo tal cual, respetando las llaves
dobles `{{ }}`:

```
CONTRATO DE PRUEBA

Nombre:
{{nombre_completo}}

CURP:
{{curp}}

RFC:
{{rfc}}

NSS:
{{nss}}

Puesto:
{{puesto}}

Sucursal:
{{sucursal}}

Empresa:
{{empresa}}

Fecha de ingreso:
{{fecha_ingreso}}

Sueldo:
{{sueldo_mensual}}

Observaciones:
{{observaciones}}
```

Guárdalo como `contrato-prueba.docx`. A propósito mezcla dos tipos de placeholder para
que la prueba cubra ambos caminos de una vez:

- **Automáticos** (el sistema los llena solo, catálogo completo en
  `claude/formatos/placeholders/PLACEHOLDERS.md`): `nombre_completo`, `curp`, `rfc`,
  `nss`, `puesto`, `sucursal`, `empresa`, `fecha_ingreso`.
- **Manuales** (no existen en el catálogo automático — el sistema los va a marcar
  "sin mapear" y te va a pedir configurarlos): `sueldo_mensual`, `observaciones`.

## Paso 1 — subir la plantilla

1. Portal web → **Operación RH** → **Formatos** → tab **Plantillas avanzadas DOCX**
   (`/rh/plantillas`).
2. **Nueva plantilla** → nombre "Contrato de prueba", tipo el que corresponda (o
   "Otro"), sube `contrato-prueba.docx` → Guardar.

## Paso 2 — configurar las variables manuales

1. Abre la plantilla recién creada → **Variables**.
2. Debes ver `sueldo_mensual` y `observaciones` en "sin mapear" — si aparecen ahí,
   el detector de placeholders funciona correctamente.
3. Configura cada una: etiqueta legible ("Sueldo mensual", "Observaciones"), tipo
   texto. Marca **una de las dos como obligatoria** (para probar el bloqueo del Caso 4
   más abajo) y deja la otra opcional.
4. Guardar.

## Paso 3 — generar el documento (Word)

1. Formatos → tab **Generados** o **Plantillas avanzadas DOCX → Generar** → elige
   "Contrato de prueba" + un colaborador de prueba con CURP/RFC/NSS/puesto/sucursal ya
   capturados en su expediente.
2. Vista previa → confirma que los placeholders automáticos ya muestran los datos
   reales del colaborador (no el texto `{{nombre_completo}}` literal).
3. Si dejaste la variable manual obligatoria sin capturar, el botón de generar debe
   quedar deshabilitado con el aviso correspondiente (Caso 4). Captura un valor y sigue.
4. **Generar** → descarga el `.docx` resultante y ábrelo: confirma que
   - los placeholders quedaron reemplazados por los datos reales;
   - el formato del Word original (fuente, párrafos, cualquier tabla/logo que hayas
     agregado) se conservó — el sistema nunca reconstruye el documento desde cero, solo
     sustituye texto dentro del `.docx` original (ver docs/PLANTILLAS_FORMATOS.md,
     sección "Flujo").

## Paso 4 — generar el PDF

1. Desde el mismo documento generado → **Descargar PDF**
   (`GET rh/formatos/{documento}/descargar-pdf`).
2. Revisa qué fidelidad tiene:
   - Si el servidor tiene `FORMATOS_LIBREOFFICE_PATH` configurado (ver
     `docs/DEPLOY.md`) y `soffice` responde, el PDF debe verse **igual** al Word
     (conversión real vía LibreOffice headless).
   - Si no está configurado, cae automáticamente al convertidor aproximado
     (PhpWord + DomPDF) — el PDF se genera igual, nunca falla ni da 500, solo con
     fidelidad menor (puede perder algún detalle visual fino). Corre
     `php artisan people:diagnostico` para confirmar cuál de los dos casos aplica en
     tu entorno (sección "LibreOffice (Word → PDF)").
3. Para forzar y comparar el caso sin LibreOffice: quita/renombra temporalmente el
   binario o la variable de entorno, corre `php artisan config:clear` y repite la
   descarga — debe seguir funcionando (fidelidad "aproximada"), nunca romperse.

## Paso 5 — subir firmado

1. Sobre el mismo documento generado → **Subir firmado** (simula la firma física:
   sube cualquier PDF/imagen como si fuera el escaneo).
2. Confirma que el estado del documento cambia a `firmado` y queda enlazado al
   `EmployeeDocument` correspondiente (ver docs/PLANTILLAS_FORMATOS.md, paso 5 del
   flujo).

## Paso 6 — verlo en el expediente

1. Expedientes → el colaborador de prueba → pestaña **Documentos**.
2. El documento firmado debe aparecer ahí, con su versión y estado — no debe haber
   que "adivinar" en qué documento se guardó.

## Paso 7 — generarlo desde una solicitud

1. Crea o abre una solicitud interna de ese mismo colaborador (por ejemplo, un permiso
   o vacaciones).
2. Dentro de la solicitud → botón **Generar formato** → elige la plantilla de prueba
   (o una plantilla real ya sugerida automáticamente por
   `TipoPlantillaDocumento::paraTipoSolicitud()` según el tipo de solicitud).
3. Confirma que los placeholders de la solicitud (folio, fechas, motivo) se llenan
   solos, sin volver a pedir nada que la solicitud ya tenía.

## Paso 8 — generarlo desde la app móvil de RH

1. App móvil, sesión con permiso de RH → **Formatos**.
2. Selecciona la misma plantilla de prueba + el mismo colaborador.
3. Antes de generar, la app debe mostrar claramente: variables ya resueltas
   automáticamente, variables manuales pendientes (con su etiqueta legible, no el
   código interno) y si falta alguna obligatoria, el botón de generar debe estar
   deshabilitado — igual que en web.
4. Genera → confirma que puedes ver/descargar el Word y (si aplica) el PDF, y que el
   resultado es idéntico al generado desde web en el paso 3.

## Qué reportar si algo falla

Para cada paso: qué esperabas vs qué pasó, y si es un problema de **datos** (el
colaborador de prueba no tenía CURP/RFC capturados, por ejemplo) o un problema real
del motor. Nunca reportar como bug algo que en realidad es un dato faltante del
colaborador de prueba — usa un colaborador con expediente completo para esta prueba.

## Ver también

- `docs/PLANTILLAS_FORMATOS.md` — arquitectura completa del motor.
- `docs/PRUEBA_MAESTRA_FORMATOS.md` — casos borde (nueva versión de plantilla, variable
  obligatoria bloqueando por API directa aunque la UI se salte, etc.).
- `docs/FORMATOS_OFICIALES.md` — el otro módulo de formatos (PDFs fijos con overlay),
  no confundir con este.
- `docs/DEPLOY.md` — configuración de `FORMATOS_LIBREOFFICE_PATH` en producción.
