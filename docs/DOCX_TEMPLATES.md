# Cómo preparar un formato en Word (DOCX) — guía para RH

Esta guía es para quien prepara los documentos en Word, no para quien programa. Si
necesitas el detalle técnico del motor, ve a `docs/PLANTILLAS_FORMATOS.md`.

## 1. Prepara tu Word normal

Escribe el documento exactamente como lo quieres ver impreso: logo, tablas, márgenes,
firmas, todo. En los espacios donde debería ir un dato del colaborador, escribe un
**marcador** entre doble llave, así:

```
NOMBRE: {{nombre_completo}}

CURP: {{curp}}

DOMICILIO: {{domicilio}}

PUESTO: {{laboral.puesto}}
```

Reglas del marcador:

- Va **pegado** a las llaves dobles: `{{nombre_completo}}`, no `{{ nombre_completo }}`
  ni `{ {nombre_completo} }`.
- Solo letras, números y guion bajo dentro de las llaves (`fecha_ingreso`, no
  `fecha ingreso`).
- Puedes escribirlo dentro de una tabla, en el encabezado, en el pie de página — Word
  lo detecta en cualquier parte del documento.
- Si Word te subraya el marcador en rojo (corrector ortográfico) no importa, no afecta
  el resultado.

**No** intentes convertir el Word a PDF antes de subirlo — el sistema recibe el `.docx`
directamente y conserva tu diseño, tipografías, tablas y logo tal cual los hiciste.

## 2. Copia los marcadores desde el Portal (no te los memorices)

En **Portal RH → Formatos → Plantillas avanzadas**, sube tu Word primero (paso 4) y
luego abre **"Variables"** en el menú de esa plantilla. Ahí vas a encontrar:

- La lista de marcadores que el sistema detectó en tu Word.
- Un catálogo con TODOS los datos disponibles (nombre, CURP, RFC, puesto, sucursal,
  fecha de ingreso, sueldo, datos de préstamo, de solicitud, etc.), agrupados por tema.
  Cada uno tiene un botón para **copiar** el marcador exacto — pégalo directo en tu Word
  si no te acuerdas del código.

## 3. Sube tu Word

**Portal RH → Formatos → Plantillas avanzadas → Nueva plantilla**:

1. Nombre del formato (ej. "Contrato individual de trabajo").
2. Tipo (contrato, constancia, etc.).
3. Alcance opcional (si solo aplica a una empresa/sucursal/puesto en particular).
4. Sube el archivo `.docx`.

## 4. Revisa qué marcadores quedaron "sin mapear"

Abre **"Variables"** de tu plantilla recién subida. Vas a ver dos tipos de marcadores:

- **Ya resueltos (automáticos)**: corresponden a un dato real del colaborador (nombre,
  CURP, puesto, etc.) — no necesitas hacer nada, se llenan solos al generar. Junto a
  cada uno hay un check **"Obligatorio para generar"**: si lo activas, el sistema no
  dejará generar el documento mientras ese colaborador no tenga ese dato capturado en
  su expediente (por ejemplo, marcar `{{curp}}` como obligatorio bloquea generar un
  contrato para alguien sin CURP capturada, en vez de dejar el marcador literal en el
  documento sin que nadie lo note). Por default, un dato automático vacío **no**
  bloquea — solo se avisa.
- **Sin mapear (manuales)**: el sistema no sabe de dónde sacar ese valor (por ejemplo,
  `{{numero_de_obra}}` si inventaste ese marcador para un dato que no existe en el
  expediente). Para cada uno, decide:
  - **Nombre visible** — cómo se llama ese dato para quien genera el documento.
  - **Tipo** — texto, texto largo, fecha, número, moneda, o una lista de opciones fijas.
  - **¿Obligatorio?** — si lo marcas, el sistema no dejará generar el documento hasta
    que alguien capture ese valor.
  - **Valor por defecto** (opcional).

Si dejas un marcador manual sin configurar, el documento se genera igual, pero el
texto `{{esa_clave}}` va a quedar **literal** (visible tal cual) en el resultado —
nunca se borra ni se rellena con un espacio en blanco sin que tú lo decidas.

## 5. Prueba antes de usarlo de verdad

Desde **Formatos → Plantillas avanzadas → Documentos generados**, elige tu plantilla,
selecciona un colaborador de prueba y da clic en **"Vista previa"**. Vas a ver:

- Los datos que sí se llenaron.
- Los que faltan (con un campo para capturarlos solo para esa prueba, sin que se
  guarden en el expediente de nadie).

Si algo se ve mal, corrige el Word y súbelo de nuevo (ver siguiente sección).

## 6. Generar el documento real

Mismo lugar, botón **"Generar documento"**. Al generar:

- Se guarda quién lo generó, para quién, cuándo, con qué plantilla y con qué datos
  exactos (auditoría).
- El documento generado **nunca cambia después**, aunque el colaborador actualice sus
  datos más adelante (por ejemplo, si cambia de puesto) o aunque tú subas una versión
  nueva del Word — el contrato que ya se entregó conserva exactamente el contenido con
  el que se generó.
- Se puede descargar en Word; si el servidor tiene conversión a PDF disponible, también
  en PDF (ver `docs/PLANTILLAS_FORMATOS.md`, sección de descarga).

## 7. ¿Qué pasa si subo una versión nueva del Word?

En **Editar plantilla**, sube un archivo nuevo — el sistema le sube el número de
versión automáticamente. Los documentos que ya generaste con la versión anterior **no
se ven afectados**: ya tienen su propio archivo generado y guardado, con el contenido
congelado del momento en que se crearon. Solo las generaciones **nuevas** usan el Word
que acabas de subir.

Si tu Word tenía marcadores configurados como variables manuales y la nueva versión
mantiene los mismos marcadores, la configuración se conserva. Si agregaste marcadores
nuevos, vuelve a abrir "Variables" para mapearlos.

## 8. Si un dato falta

- Un dato automático del colaborador que está vacío (por ejemplo, un colaborador sin
  CURP capturada todavía) se avisa en la vista previa, pero **no bloquea** la
  generación por default — el marcador queda literal en el documento.
- Si marcaste ese mismo dato automático como **obligatorio** en "Variables" (paso 4),
  sí bloquea: el mensaje dice explícitamente qué falta (por ejemplo, "Falta CURP.").
- Una variable manual que marcaste como **obligatoria** siempre bloquea la generación
  hasta que alguien la llene — esto no cambió.
- Las plantillas que ya tenías antes de esta función siguen exactamente igual: ningún
  dato automático se vuelve obligatorio solo; tienes que entrar a "Variables" y
  marcarlo tú.

## 9. Firma

La firma sigue siendo la misma que ya usa el resto del sistema: se imprime, se firma en
papel, y se sube el escaneo desde la misma pantalla del documento generado — no hay un
segundo sistema de firma para los documentos DOCX.

## 10. Preguntas frecuentes

**¿Puedo usar el mismo marcador dos veces en el mismo Word?** Sí, todas las apariciones
se reemplazan.

**¿El sistema modifica mi Word original?** No. El archivo que subiste nunca se toca;
cada generación crea un archivo nuevo a partir de él.

**¿Puedo usar macros de Word (`.docm`)?** No — por seguridad solo se acepta `.docx` sin
macros (ver `docs/SEGURIDAD.md`).

**¿Puedo poner una imagen/logo fijo en el Word?** Sí, cualquier imagen que ya esté en
tu Word (logo, firma preimpresa) se conserva tal cual — no necesita marcador.
