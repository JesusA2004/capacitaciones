# MR. LANA PEOPLE — Auditoría final Fase 1

Fecha: 2026-10-13.

## Verificación

| Check | Resultado |
|---|---|
| Backend (`php artisan test`) | __SUITE__ |
| Pint | OK |
| PHPStan (Larastan nivel 7) | 0 errores |
| Web: vue-tsc · ESLint · `test:js` · build | OK · OK · 31/31 · OK |
| App: tsc · lint · Jest · expo-doctor | OK · OK · 576/576 · 21/21 |
| Smoke navegador (RH + colaborador, gerente, regional, Dirección Comercial) | 0 errores JS, 0 warnings Vue, 0 WebSocket, 0 HTTP 4xx/5xx |
| Recorrido de roles (16 roles, 281 pantallas) | 0 errores |

## Reclutamiento
OK

## Vacantes
OK

## Campañas
OK

## Candidatos
OK — incluye el expediente previo a la contratación (CV, psicométricos, videos
del socioeconómico, referencias) en el NAS; ver `docs/RECLUTAMIENTO.md`.

## Referencias
OK

## Contratación
OK

## Alta
OK

## Expedientes
OK

## Documentos
OK — 28 formatos activos, 28 OK, 0 zonas sin clasificar, 267 marcatextos
retirados (0 en documentos finales); beneficiario y parentesco obligatorios
(con dato se escriben, sin dato la vista previa los lista y el definitivo se
bloquea). Detalle en `docs/AUDITORIA_FORMATOS_FINAL.md`.

## Inducción
OK

## Evaluación inducción
OK

## Capacitación inicial
OK

## Evaluación periodo
OK

## Contrato indeterminado
OK

## Solicitudes
OK

## Vacaciones
OK

## Préstamos
OK

## Nómina
OK

## Avisos
OK

## Directorio
OK

## Organigrama
OK

## Importaciones
OK

## Timeline
OK

## App
OK — versión 1.2.0, versionCode 9, `com.mrlana.people`.

## Infraestructura
OK

## Externos
- `permiso_extraordinario.maternidad`/Aptos: formato inactivo hasta disponer
  de Aptos o recibir versión del documento con una fuente disponible.
- Dependencias del VPS por verificar en despliegue (abajo).

## Dependencias de producción

**REQUERIDO**
- PHP 8.4 (FPM) con GD; Composer.
- Node + npm (build y Browsershot).
- Chrome/Chromium para Puppeteer/Browsershot (PDF HTML → PDF):
  `BROWSERSHOT_CHROME_PATH`, `BROWSERSHOT_NODE_BINARY`.
- Worker de cola (`QUEUE_CONNECTION`, `php artisan queue:work` con supervisor).
- NAS (Synology) montado y escribible como disco `nas`.
- Fuentes usadas por los masters activos (Century Gothic y las del diagnóstico
  de fuentes de cada versión).
- Límites de subida para videos de candidatos (250 MB c/u): php.ini
  `upload_max_filesize`/`post_max_size` y Nginx `client_max_body_size` (300M en
  `docs/DEPLOY.md`).

**DOCX**
- LibreOffice (`FORMATOS_LIBREOFFICE_PATH`) u otro motor fiel configurado.

**PDF digital**
- Poppler: `pdftotext` (detector de campos) y `pdftoppm` (QA visual).

**PDF escaneado**
- Tesseract + idioma `spa`, SOLO si se quiere OCR local
  (`FORMATOS_TESSERACT_PATH`).

**OPCIONAL**
- Reverb (tiempo real); sin él la app funciona con recarga normal.
- FFmpeg (`FFMPEG_BIN`), solo para la biblioteca multimedia de Capacitación
  (oculta).

Comprobación en el servidor: `php artisan people:diagnostico`.
