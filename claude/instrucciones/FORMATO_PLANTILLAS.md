# Cómo preparar un formato oficial como plantilla DOCX

Guía para RH/Legal antes de subir un formato al módulo **Plantillas y formatos**
(`/rh/plantillas`).

## 1. Formato de archivo

- Prioridad: **DOCX** (Word). El sistema usa `PhpOffice\PhpWord\TemplateProcessor`, que
  reemplaza texto dentro del propio archivo Word sin destruir el diseño (logotipos,
  tablas, encabezados, pies de página, numeración).
- PDF solo se admite como **formato original de referencia** (para mostrar cómo debe
  verse) o si el PDF es rellenable/tiene mapeo de coordenadas ya definido. El sistema
  **no reconstruye diseño desde un PDF plano** — no destruye el diseño porque
  simplemente no lo edita automáticamente en ese caso.

## 2. Dónde colocar el placeholder

Reemplaza el dato variable (nombre, fecha, puesto, etc.) directamente en el texto del
documento por el placeholder correspondiente, tomado de
`claude/formatos/placeholders/PLACEHOLDERS.md`. Por ejemplo, donde el formato actual
dice "Juan Pérez", debe decir `{{nombre_completo}}`.

## 3. Errores comunes a evitar

- **No dividas el placeholder en varias ejecuciones de texto (runs) de Word.** Si copias
  y pegas texto con formato mixto, Word puede partir `{{nombre_completo}}` en fragmentos
  invisibles que el sistema no puede reconocer. Si tienes dudas, borra el texto
  variable y escribe el placeholder de nuevo manualmente, sin pegar formato.
- No uses corchetes simples `[nombre]` ni paréntesis — el sistema busca exactamente
  `{{...}}`.
- No captures placeholders dentro de imágenes o cuadros de texto flotantes (no son
  compatibles).
- Las tablas sí son compatibles: puedes poner un placeholder dentro de una celda.

## 4. Clasificación al subir la plantilla

Al registrar la plantilla en `/rh/plantillas`, se captura:

- Nombre y tipo (contrato, aviso de privacidad, consentimiento de datos, carta de
  confidencialidad, formato de permiso, formato de vacaciones, formato de incapacidad,
  formato de alta, formato de baja, resguardo, acuse, otro).
- Alcance opcional: empresa, sucursal y/o puesto (si el formato solo aplica a un
  subconjunto de la organización).
- Descripción breve de cuándo usar ese formato.

## 5. Firma en Fase 1

La firma es **física**: el sistema genera el documento precargado, RH lo imprime, el
colaborador/candidato firma en papel, y RH escanea el documento firmado y lo sube al
expediente como un documento normal (no como una nueva plantilla). El sistema no
implementa firma electrónica avanzada en esta fase.

## 6. Responsabilidad legal

El sistema **no redacta ni valida contenido legal**. RH/Legal es responsable de que el
contenido del formato (cláusulas, textos legales, aviso de privacidad) cumpla con la
normativa aplicable antes de cargarlo. El sistema únicamente automatiza el llenado de
datos variables sobre un documento ya validado.
