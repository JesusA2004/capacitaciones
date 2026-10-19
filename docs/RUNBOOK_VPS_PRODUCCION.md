# Runbook · arranque de producción en el VPS (datos reales)

Pasos para dejar MR. LANA PEOPLE listo con datos reales en el VPS. Cada comando existe hoy en el repo. El flujo es: **preparar entorno → (opcional) resetear → migrar + sembrar catálogos → crear admin → importar los 4 Excel → enlazar expedientes del NAS + OCR/división → verificar**.

> **Destructivo:** `people:reset-produccion` BORRA toda la base de datos. Úsalo solo cuando quieras empezar de cero. Si es una instalación nueva y vacía, sáltate el reset y ve directo a *migrar*.

---

## 0. Prerrequisitos en el VPS (una sola vez)

- PHP 8.3+ con extensiones de Laravel, MariaDB/MySQL, Node para compilar.
- `composer install --no-dev --optimize-autoloader` y `npm ci && npm run build`.
- `.env` de producción: `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` generada, conexión de base de datos, correo, y:
  - **NAS/expedientes**: `EXPEDIENTES_DISK=nas`, `NAS_DRIVER` (`local` si está montado por carpeta, o `sftp`), `NAS_ROOT` apuntando al montaje real. Ver `docs/CONFIGURACION_NAS.md`, `docs/SYNOLOGY_STORAGE.md`, `docs/ESTRUCTURA_EXPEDIENTES_NAS.md`.
  - **OCR** (división de expedientes históricos): instalar `tesseract-ocr tesseract-ocr-spa poppler-utils`; en `.env` `EXPEDIENTES_OCR_DRIVER=tesseract` (y si hace falta `EXPEDIENTES_TESSERACT_PATH`, `EXPEDIENTES_PDFTOPPM_PATH`). Si no hay OCR, la división no falla: marca esas páginas como «escaneada sin OCR».
  - **PDF / formatos**: LibreOffice (`FORMATOS_LIBREOFFICE_PATH`) para convertir formatos oficiales.
  - **Multimedia** (solo si se enciende `capacitacion`): FFmpeg/FFprobe (`FFMPEG_BIN`/`FFPROBE_BIN`).
  - **Cola**: un worker real (`php artisan queue:work`) para los jobs (división de expedientes, PDFs, notificaciones).
- `php artisan storage:link`.
- `SEED_DEMO_DATA` **sin definir o `false`** (jamás datos de demo en producción).

---

## 1. (Opcional) Resetear la base de datos a cero

Solo si vas a reemplazar todo lo que haya:

```bash
php artisan people:reset-produccion --seed
```

- Pide escribir `BORRAR TODO` para confirmar (o `--force` en automatización).
- Hace `migrate:fresh`, siembra **catálogos + roles/permisos base** (sin personas) y limpia cachés.
- **No toca los archivos del NAS** (solo la base de datos).

## 1-bis. Instalación nueva y vacía (sin reset)

```bash
php artisan migrate --force
php artisan db:seed --force          # catálogos + roles/permisos (sin personas)
php artisan people:sincronizar-permisos
```

`db:seed --force` en producción siembra empresas, sucursales, departamentos, puestos, catálogos BASE_GENERAL, tipos de documento, matriz comercial, plantilla autorizada base, frases de cumpleaños y filtros de reclutamiento — **ningún colaborador de demo**.

---

## 2. Crear tu cuenta de administrador (con la que entrarás)

```bash
php artisan people:crear-admin --nombre="Jesus" --apellidos="Arizmendi" --correo="tucorreo@dominio.mx"
# te pedirá la contraseña (mínimo 8); o pásala con --password=...
```

- Crea un **super_admin OPERATIVO sin colaborador**: no aparece en Expedientes, ni en plantilla/headcount, ni en rotación, ni en el directorio.
- **Inicias sesión con el USUARIO** que imprime el comando (p. ej. `Jesus Arizmendi`), no con el correo.
- Idempotente: si vuelves a correrlo con el mismo correo, actualiza la contraseña y reafirma el rol.

---

## 3. Importar los 4 Excel reales (en este orden)

Centro único: **Configuración → Importar datos** (web). Todos siguen **subir → analizar → vista previa → advertencias → confirmar → aplicar** (nunca aplican al cargar). Dos tienen además comando CLI.

| # | Carga | Cómo | Archivo |
|---|---|---|---|
| 3.1 | **Colaboradores base + expedientes históricos** | Web (Importar datos → Migración inicial) o CLI | roster BASE_GENERAL / SEMANA 41 (lo provees tú) |
| 3.2 | **Sueldos y vacaciones** | Web (Importar datos → Salarios y vacaciones) | `REPORTE_TRABAJADORES_VAC.xlsx` |
| 3.3 | **Headcount autorizado** | Web (Importar datos → Headcount) o CLI | `claude/headcount/HEADCOUNT GENERAL MR LANA ...xlsx` |
| 3.4 | **Rotación** | Web (Importar datos → Rotación) | `claude/rotacion/INDICE DE ROTACIÓN ...xlsx` |

**Primero colaboradores** (crea a las personas), luego lo demás (salarios/headcount/rotación se apoyan en o conviven con esa base).

CLI equivalentes (opcionales):

```bash
# 3.1 Colaboradores + expedientes históricos (SIEMPRE dry-run primero)
php artisan expedientes:importar-inicial "ruta/BASE_GENERAL.xlsx" --roster="ruta/roster.xlsx" --dry-run
php artisan expedientes:importar-inicial "ruta/BASE_GENERAL.xlsx" --roster="ruta/roster.xlsx" --apply --confirm

# 3.3 Headcount
php artisan headcount:importar "claude/headcount/HEADCOUNT GENERAL MR LANA 28-08-2026..xlsx"
```

> Sueldos/vacaciones (3.2) y rotación (3.4) son **solo por web** (no tienen comando): hazlos desde Importar datos.

Columnas que espera cada parser (por NOMBRE de encabezado, no por posición):
- **Colaboradores** (hoja BASE_GENERAL): al menos *Nombre* + *Apellido paterno* (+ materno, empresa, sucursal, departamento, puesto…). Reglas/alias en `config/expedientes.php → migracion_inicial`.
- **Sueldos/vacaciones** (encabezados en la fila 4): `nombre_completo`, `sucursal`, `fecha_ingreso`, `dias_otorgados`, `dias_tomados`, `dias_restantes`, `salario_diario`.
- **Headcount**: bloques por sucursal con `MODALIDAD / PLANTILLA AUTORIZADA / PLANTILLA ACTUAL / VACANTES`; solo se importa **PLANTILLA AUTORIZADA**.
- **Rotación**: etiquetas en columna A (`ALTAS, BAJAS, BAJAS VOLUNTARIAS, BAJAS INVOLUNTARIAS, PLANTILLA, ROTACION`) por mes.

---

## 4. Sincronizar estructura y vacantes

```bash
php artisan people:sincronizar-organigrama
php artisan organigrama:sincronizar-jefes
php artisan people:sincronizar-vacantes      # vacantes = plantilla autorizada − ocupados reales
```

---

## 5. Enlazar expedientes del NAS (Synology) + OCR + dividir documentos

Con el NAS montado (`EXPEDIENTES_DISK=nas`, `NAS_ROOT`) y OCR instalado (paso 0):

```bash
# Organizar/verificar el layout en el NAS
php artisan expedientes:organizar-nas
php artisan expedientes:verificar-storage --hash

# Dividir el PDF histórico de cada colaborador por tipo de documento (checklist),
# leyendo capa de texto y, si no hay, OCR. SIEMPRE simular primero.
php artisan expedientes:dividir-historico --todos --simular
php artisan expedientes:dividir-historico --todos
```

- El PDF original **nunca** se corta, mueve ni borra: se generan copias por tipo de documento en «Historico/División por checklist».
- Los datos personales detectados por OCR se **reportan**, no se aplican solos (RH confirma en pantalla). Ver `docs/DIVISION_EXPEDIENTE_HISTORICO.md` y `docs/DOCUMENT_EXTRACTION.md`.
- También puedes lanzar la división por colaborador desde la ficha (web): se encola `DividirExpedienteHistoricoJob` (requiere el worker corriendo).

---

## 6. Diagnóstico y verificación final

```bash
php artisan people:diagnostico                 # columnas, storage, catálogos, permisos, roles, headcount
php artisan people:diagnostico-multimedia      # NAS, FFmpeg, X-Accel, cola de video (si usas capacitación)
php artisan people:diagnostico-pdf
php artisan people:diagnostico-notificaciones
```

Luego, en el navegador:
1. Inicia sesión con tu usuario de administrador (paso 2).
2. Recorre el sidebar por etapas (Inicio → 1..7 → Organización → Reportes → Configuración) y confirma que cada pantalla carga.
3. Revisa Reportes de RH (incluye rotación), el tablero y los expedientes importados.

---

## Orden resumido (copia rápida)

```bash
# (opcional) reset total
php artisan people:reset-produccion --seed

# instalación nueva
php artisan migrate --force && php artisan db:seed --force && php artisan people:sincronizar-permisos

# tu cuenta
php artisan people:crear-admin --nombre="Jesus" --apellidos="Arizmendi" --correo="tucorreo@dominio.mx"

# importar (colaboradores por CLI o web; sueldos y rotación por web)
php artisan expedientes:importar-inicial "BASE_GENERAL.xlsx" --roster="roster.xlsx" --dry-run
php artisan expedientes:importar-inicial "BASE_GENERAL.xlsx" --roster="roster.xlsx" --apply --confirm
php artisan headcount:importar "claude/headcount/HEADCOUNT GENERAL MR LANA 28-08-2026..xlsx"
#   → sueldos/vacaciones y rotación: Configuración → Importar datos (web)

# estructura y vacantes
php artisan people:sincronizar-organigrama && php artisan organigrama:sincronizar-jefes && php artisan people:sincronizar-vacantes

# expedientes NAS + OCR
php artisan expedientes:organizar-nas
php artisan expedientes:dividir-historico --todos --simular
php artisan expedientes:dividir-historico --todos

# diagnóstico
php artisan people:diagnostico
```
