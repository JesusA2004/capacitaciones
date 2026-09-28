# Prueba manual maestra — formatos DOCX

Checklist de los cuatro escenarios clave del motor de plantillas DOCX
(`docs/PLANTILLAS_FORMATOS.md`, `docs/DOCX_TEMPLATES.md`). Ejecutar en
Portal RH y, donde se indique, también en la app móvil de RH.

## A. Formato automático (sin variables manuales)

1. Prepara un DOCX con marcadores que sí corresponden a datos reales:
   `{{nombre_completo}}`, `{{curp}}`, `{{puesto}}`, `{{empresa}}`,
   `{{fecha_ingreso}}`, `{{domicilio}}`.
2. Portal RH → Formatos → Plantillas avanzadas → Nueva plantilla → subir el
   DOCX.
3. Abrir "Variables" de la plantilla recién creada → confirmar que **no**
   aparece nada en "sin mapear" (todos los marcadores son conocidos).
4. Formatos → Documentos generados → elegir la plantilla y un colaborador
   con esos datos completos → **Vista previa** → confirmar que los valores
   se ven correctos.
5. **Generar** → confirmar que el DOCX descargado tiene los datos reales
   (no los marcadores literales).
6. Si el servidor tiene conversión a PDF disponible: descargar también en
   PDF y confirmar que se ve igual.
7. Guardar en expediente (si la plantilla está configurada para eso) →
   confirmar que aparece en el expediente del colaborador.
8. **App móvil**: Gestión RH → Formatos → mismo formato/colaborador →
   preparar → generar → confirmar que el resultado es idéntico al generado
   desde web.

## B. Formato con campo manual

1. Sube un DOCX con un marcador que **no** corresponde a ningún dato real,
   ej. `{{numero_de_obra}}`.
2. Abrir "Variables" → debe aparecer en "sin mapear".
3. Configurar: etiqueta "Número de obra", tipo texto, marcar como
   **obligatoria**, sin valor por defecto → Guardar.
4. Ir a generar para un colaborador → confirmar que aparece el campo para
   capturarlo, con la etiqueta configurada (no el código `numero_de_obra`).
5. Capturar un valor → Vista previa → confirmar que aparece en el
   documento.
6. Generar → confirmar que el valor capturado queda en el documento final.
7. **App móvil**: repetir preparar/generar con esa misma plantilla —
   confirmar que el campo manual aparece igual de claro (etiqueta, no
   código) y que el botón "Generar documento" está deshabilitado hasta
   capturarlo.

## C. Falta un dato requerido

1. Elige un colaborador que **no** tenga capturado uno de los datos base
   usados por la plantilla (ej. sin domicilio).
2. Preparar/Vista previa → debe mostrarse como aviso ("Sin dato todavía"),
   pero **sin bloquear** la generación (comportamiento intencional: un dato
   base vacío es una advertencia, no un bloqueo).
3. Ahora prueba con una variable **manual marcada como obligatoria** sin
   valor capturado → el botón de generar debe estar deshabilitado (web:
   mensaje de error; móvil: botón deshabilitado con el aviso) hasta que se
   resuelva.
4. Intentar forzar la generación sin ese dato vía una petición directa
   (Postman/curl con el token del RH) → el backend debe rechazarlo (422)
   incluso si el frontend se salta la validación — confirmar que el
   `puede_generar`/`faltantes_requeridos` del backend realmente bloquea,
   no solo la UI.

## D. Nueva versión de la plantilla

1. Genera un documento con la plantilla en su versión actual (v1) para un
   colaborador de prueba.
2. Edita la plantilla → sube un DOCX nuevo (con cambios visibles, ej. un
   párrafo distinto) → confirma que el número de versión subió.
3. Descarga de nuevo el documento generado en el paso 1 (desde "Documentos
   generados") → debe seguir mostrando el contenido **original**, no el de
   la plantilla nueva.
4. Genera un documento **nuevo** con la misma plantilla → debe usar el
   contenido de la versión nueva.
5. Si la plantilla nueva agregó un marcador que no existía antes, confirma
   que "Variables" lo detecta como sin mapear y que la generación anterior
   (paso 1) no se ve afectada por dejarlo sin configurar.

## Qué reportar si algo falla

Para cada escenario: paso exacto, qué se esperaba vs qué pasó, y si es un
problema de datos (colaborador sin cierto campo) o un problema real del
motor.
