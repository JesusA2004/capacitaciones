# Configuración del disco NAS (biblioteca multimedia)

La biblioteca multimedia (videos, documentos e imágenes usados en las lecciones) nunca se guarda en `storage/app/public` ni en el disco `local` por defecto de Laravel: usa un disco dedicado llamado `nas`, definido en `config/filesystems.php`. Este documento explica cómo configurarlo en desarrollo y en producción.

## Por qué un disco aparte

- Los videos originales y sus variantes HLS pueden ocupar cientos de GB; no deben vivir dentro del propio servidor de aplicación.
- `App\Services\Multimedia\MediaStorageService` es la **única** puerta de entrada a este disco en todo el código (`Storage::disk(config('media.disk'))`). Ningún controlador ni Job debe llamar a `Storage::disk('nas')` directamente ni construir rutas a mano.
- El disco físico real nunca se expone al frontend: solo se manejan IDs de `RecursoMultimedia` y rutas lógicas internas (`hls/{uuid}/master.m3u8`, `originales/{uuid}.mp4`, etc.).

## Modo 1 — Carpeta local (desarrollo, y opción válida en producción)

Variable de entorno por defecto: `NAS_DRIVER=local` (no hace falta declararla, es el valor por defecto de `config/filesystems.php`).

```env
NAS_DRIVER=local
NAS_ROOT=/ruta/absoluta/al/punto/de/montaje
```

- En **desarrollo**, `NAS_ROOT` puede apuntar a cualquier carpeta local (por defecto `storage/app/private/capacitacion` si no se declara).
- En **producción**, la recomendación es montar el recurso compartido del NAS (SMB/CIFS o NFS) como una carpeta del sistema operativo del servidor (por ejemplo `/mnt/mrlana-capacitacion`) y apuntar `NAS_ROOT` ahí. Desde el punto de vista de Laravel sigue siendo `driver=local`: FFmpeg/FFprobe pueden abrir la ruta absoluta directamente (`MediaStorageService::rutaLocalAbsoluta()`), sin descargar nada a un temporal primero (`MediaStorageService::esDiscoLocal()` devuelve `true`).

Esta es la opción recomendada por defecto: es la más simple y la más rápida (ni la subida ni el procesamiento pasan por una capa de red adicional gestionada por PHP).

## Modo 2 — SFTP (cuando montar el NAS como carpeta local no es viable)

```env
NAS_DRIVER=sftp
NAS_HOST=nas.interno.mrlana.local
NAS_PORT=22
NAS_USERNAME=capacitacion
NAS_PASSWORD=...          # o NAS_PRIVATE_KEY con la ruta a una llave privada
NAS_ROOT=/capacitacion
```

Requiere instalar el adaptador de Flysystem para SFTP, que no viene por defecto con Laravel:

```bash
composer require league/flysystem-sftp-v3
```

Diferencias de comportamiento con este driver:

- `MediaStorageService::esDiscoLocal()` devuelve `false`.
- `ProcesarVideoJob` descarga el video original a un temporal local (`MediaStorageService::descargarATemporal()`) antes de invocar FFmpeg/FFprobe, y sube el resultado (miniatura + carpeta HLS completa) de vuelta al terminar. Esto es más lento y usa más disco temporal en el servidor de aplicación que el Modo 1.
- `MediaStorageService::respuesta()` (usada para servir segmentos `.ts` al reproductor) sigue funcionando igual sin cambios de código: Laravel la implementa por streaming sobre cualquier adaptador de Flysystem, no solo sobre discos locales.

## Variables de entorno relacionadas

Ya declaradas (comentadas) en `.env.example`:

```env
# NAS_DRIVER=local
# NAS_ROOT=/mnt/mrlana-capacitacion
# NAS_HOST=
# NAS_PORT=22
# NAS_USERNAME=
# NAS_PASSWORD=
# NAS_PRIVATE_KEY=

# FFMPEG_BIN=/usr/bin/ffmpeg
# FFPROBE_BIN=/usr/bin/ffprobe
# MEDIA_TOKEN_TTL=600
# MEDIA_ALLOWED_ORIGIN=
# MEDIA_MAX_UPLOAD_MB=
# VIDEO_COMPLETION_PERCENT=98
# VIDEO_HEARTBEAT_SECONDS=8
# VIDEO_PLAYBACK_RATE=1
```

`MEDIA_TOKEN_TTL`, `VIDEO_*` y el resto de opciones de procesamiento/reproducción están documentadas en detalle en `docs/PROCESAMIENTO_VIDEO.md`.

## Verificación de la configuración

```bash
php artisan tinker
>>> app(\App\Services\Multimedia\MediaStorageService::class)->esDiscoLocal();
>>> app(\App\Services\Multimedia\MediaStorageService::class)->disco()->exists('.');
```

Si `esDiscoLocal()` devuelve `false` sin haber configurado `NAS_DRIVER=sftp` intencionalmente, o si `disco()->exists('.')` lanza una excepción de conexión, revisar `NAS_ROOT`/credenciales antes de subir el primer archivo real.

## Limitación conocida de este entorno de desarrollo

FFmpeg y FFprobe **no están instalados** en la máquina Windows/WAMP usada para este proyecto durante su construcción. El código maneja esto correctamente (los jobs de procesamiento fallan con un error controlado y `RecursoMultimedia.estado` pasa a `error` con el mensaje capturado, en vez de romper el proceso), pero la conversión real a HLS no pudo probarse de extremo a extremo aquí. Antes de usar la biblioteca multimedia con video real:

1. Instalar FFmpeg/FFprobe en el servidor (o en la imagen de contenedor) y declarar `FFMPEG_BIN`/`FFPROBE_BIN` si no están en el `PATH`.
2. Subir un video de prueba corto y confirmar en la cola (`php artisan queue:work` o Horizon) que `RecursoMultimedia.estado` pasa de `pendiente` → `procesando` → `disponible`, con `ruta_hls_manifiesto`, `duracion_segundos` y `resolucion_original` completos.
3. Reproducirlo desde "Mi capacitación" y confirmar que el contador de "Visto" avanza y que adelantar el video manualmente (arrastrando la barra) no salta más allá de lo ya visto.
