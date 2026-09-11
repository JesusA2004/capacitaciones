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

## Dos flujos distintos que comparten esta carpeta

### 1. Formatos oficiales fijos de MR. LANA (PDF) — `docs/FORMATOS_OFICIALES.md`

Los PDFs oficiales reales (formato de vacaciones, solicitud de empleo, contrato de
crédito, etc.) van en `formatos/originales/` **tal cual**, sin placeholders ni edición:
el sistema los pinta encima (overlay), nunca los reescribe. Flujo:

1. Coloca el PDF real en `formatos/originales/` con el nombre exacto que espera
   `App\Console\Commands\ImportarFormatosOriginalesCommand::CATALOGO` (o agrega una
   entrada nueva ahí si es un formato que no existía).
2. Corre `php artisan formatos:importar-originales` — copia el PDF al disco `nas` y
   crea/actualiza su `OfficialFormat`. Es idempotente, se puede correr las veces que
   haga falta.
3. Desde `/rh/formatos-oficiales/{formato}` (solo rh_admin/super_admin), configura en
   qué coordenadas del PDF se pintan los datos del colaborador.
4. Ya disponible en `/rh/formatos` para que cualquier RH con permiso lo genere.

### 2. Plantillas DOCX editables ("Plantillas avanzadas") — `docs/PLANTILLAS_FORMATOS.md`

1. Coloca el documento base (Word) en `formatos/originales/`.
2. Revisa `formatos/placeholders/PLACEHOLDERS.md` y reemplaza los datos variables del
   documento por los placeholders correspondientes, por ejemplo `{{nombre_completo}}`.
3. Sube el archivo preparado desde el sistema en `/rh/plantillas` ("Plantillas
   avanzadas", no se sube por Git).
4. El sistema guarda el archivo en el disco `nas` (Synology) y queda disponible para
   generar documentos precargados por colaborador, candidato o solicitud.
5. Verifica el primer documento generado antes de usarlo en producción con colaboradores
   reales.

Ver `claude/instrucciones/FORMATO_PLANTILLAS.md` para el detalle de cómo preparar el
DOCX correctamente.
