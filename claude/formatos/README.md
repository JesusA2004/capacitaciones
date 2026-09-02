# Formatos oficiales

Esta carpeta organiza los formatos oficiales que el sistema debe respetar visualmente al
generar documentos precargados.

## Subcarpetas

- `originales/` — **área de trabajo local**. Aquí RH coloca los archivos reales
  (contrato laboral, aviso de privacidad, consentimiento de datos, carta de
  confidencialidad, formato de permiso, formato de vacaciones, formato de incapacidad,
  formato de alta, formato de baja, resguardos, acuses y otros formatos internos).
- `ejemplos/` — versiones de ejemplo sin datos sensibles reales (útiles para pruebas y
  para mostrarle a Legal/RH cómo debe quedar un formato antes de aprobarlo).
- `placeholders/PLACEHOLDERS.md` — catálogo de los placeholders que el sistema puede
  precargar automáticamente en un DOCX.

## Importante: datos sensibles

Los archivos que RH coloque en `formatos/originales/` pueden contener información
confidencial (nombres, CURP, RFC, cláusulas legales internas, membretes con datos
fiscales, etc.). **Estos archivos NO deben subirse a Git.**

`formatos/originales/*` está excluido en `.gitignore` (solo se versiona el archivo
`.gitkeep` para que la carpeta exista en el repositorio). Si necesitas compartir un
formato de ejemplo sin riesgo, colócalo en `formatos/ejemplos/` en su lugar — esa carpeta
sí se versiona, siempre y cuando el archivo no contenga datos reales de personas.

## Flujo recomendado para RH

1. Coloca el documento base (Word/PDF) en `formatos/originales/`.
2. Revisa `formatos/placeholders/PLACEHOLDERS.md` y reemplaza los datos variables del
   documento por los placeholders correspondientes, por ejemplo `{{nombre_completo}}`.
3. Sube el archivo preparado desde el sistema en `/rh/plantillas` (no se sube por Git).
4. El sistema guarda el archivo en el disco `nas` (Synology) y queda disponible para
   generar documentos precargados por colaborador, candidato o solicitud.
5. Verifica el primer documento generado antes de usarlo en producción con colaboradores
   reales.

Ver `claude/instrucciones/FORMATO_PLANTILLAS.md` para el detalle de cómo preparar el
DOCX correctamente.
