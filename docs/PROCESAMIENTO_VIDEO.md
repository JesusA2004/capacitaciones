# Procesamiento de video, reproductor HLS y control de avance

Este documento explica el recorrido completo de un video: desde que se sube a la biblioteca multimedia hasta que un colaborador lo ve en "Mi capacitación", incluyendo cómo se impide que adelante partes que no ha visto.

## 1. Subida y encolado

`RecursoMultimediaController::store` (`app/Http/Controllers/Multimedia/RecursoMultimediaController.php`) guarda el archivo original en el disco `nas` (ver `docs/CONFIGURACION_NAS.md`) mediante `MediaStorageService::guardar()`, crea el `RecursoMultimedia` con `estado = pendiente` y despacha `App\Jobs\ProcesarVideoJob` a la cola. Documentos e imágenes **no** se encolan: pasan directo a `estado = disponible`, porque no requieren transcodificación.

La subida en sí se hace con `useForm({ forceFormData: true })` de Inertia (`resources/js/components/Multimedia/MultimediaUploadDialog.vue`), que ya reporta el progreso de subida vía `onProgress` sin necesitar XHR/fetch manual.

## 2. Procesamiento (`ProcesarVideoJob`)

`app/Jobs/ProcesarVideoJob.php` es un job en cola (`tries=2`, `timeout=3600`, `backoff=30`) idempotente: si el recurso ya no está en `estado = pendiente` cuando se ejecuta (por ejemplo, un reintento posterior a que otro worker ya lo dejó `disponible`), no hace nada.

Pasos, usando `App\Services\Multimedia\FfmpegService`:

1. `inspeccionar()` — ejecuta `ffprobe` y obtiene duración, ancho y alto del video original.
2. `convertirAHls()` — genera únicamente las resoluciones candidatas (`config('media.video.resoluciones')`, por defecto 360/480/720/1080) que sean **menores o iguales** a la altura original (nunca se escala hacia arriba), más un manifiesto maestro (`master.m3u8`) que las referencia.
3. `generarMiniatura()` — un fotograma de portada.
4. Se suben la carpeta HLS completa y la miniatura al disco `nas`, y se actualiza el `RecursoMultimedia` a `estado = disponible` con `duracion_segundos`, `resolucion_original` y `ruta_hls_manifiesto`.

Si cualquier paso falla, el `catch (\Throwable)` deja `estado = error` con el mensaje en `error_procesamiento` y reporta la excepción; nunca deja el recurso a medias en `procesando`.

Todo el trabajo con FFmpeg ocurre sobre rutas **locales absolutas**. Si el disco `nas` no es local (`NAS_DRIVER=sftp`), el original se descarga primero a un temporal (`MediaStorageService::descargarATemporal()`) y los temporales se limpian siempre en el `finally`, haya o no error.

> **Limitación conocida**: FFmpeg/FFprobe no están instalados en el entorno Windows/WAMP donde se construyó esta fase, así que la conversión real no pudo ejecutarse de extremo a extremo aquí. Las pruebas automatizadas (`tests/Feature/Multimedia/ProcesarVideoJobTest.php`) cubren la idempotencia y el manejo de errores usando `Illuminate\Support\Facades\Process::fake()`, no una codificación real. Ver el apartado de verificación manual en `docs/CONFIGURACION_NAS.md`.

## 3. Control de avance del reproductor (anti-adelanto)

Esta es la parte más delicada del encargo: un colaborador **no debe poder saltarse partes del video** con el objetivo de completar la lección sin verla. La estrategia tiene dos capas independientes, ambas en el servidor (nunca solo en el cliente):

### 3.1 Lo realmente visto se mide con tramos fusionados, no con "lo que dice el reproductor"

`App\Services\Multimedia\ReproduccionVideoService` no confía en que el cliente reporte "ya vi el 80%": cada heartbeat reporta una **posición** (segundo actual), y el servicio calcula el tramo `[posición anterior, posición nueva]` y lo fusiona con los tramos ya guardados en `intervalos_video_vistos` (tabla `user_id` + `leccion_id` + `inicio_segundo` + `fin_segundo`). El porcentaje visto usado para completar la lección es la suma de estos tramos únicos, así que rebobinar y volver a ver el mismo minuto dos veces no cuenta doble, pero tampoco perjudica.

El "segundo máximo permitido" (`ReproduccionVideoService::segundoMaximoPermitido()`) es siempre `MAX(fin_segundo) de los tramos + tolerancia`. La tolerancia (`config('media.video.salto_tolerancia_segundos')`, 5 segundos por defecto) absorbe variaciones normales de red/heartbeat, no adelantos reales.

### 3.2 El manifiesto HLS que ve el reproductor está truncado a ese límite

Aquí está el punto clave para que el anti-adelanto sea real y no cosmético: **el propio archivo `.m3u8` que descarga el reproductor no contiene los segmentos que el usuario todavía no puede ver**. No se trata de ocultar un botón de "adelantar": aunque alguien inspeccione las peticiones de red y arme un cliente HLS a mano, no puede pedir un segmento que el servidor no le ofrece.

Flujo (`App\Http\Controllers\MiCapacitacion\ReproduccionController` + `App\Services\Multimedia\ManifiestoHlsService`), todo detrás de rutas firmadas de corta duración (`URL::temporarySignedRoute`, TTL = `config('media.token_ttl')`, 600 segundos por defecto):

1. **Manifiesto maestro** (`GET .../reproduccion/manifiesto/master.m3u8`): se sirve el `master.m3u8` real generado por FFmpeg, pero reescribiendo cada línea de variante (`360p.m3u8`, `720p.m3u8`, ...) para que apunte a una ruta firmada propia (`.../manifiesto/{altura}.m3u8`) en vez del nombre de archivo real. El reproductor nunca ve la estructura de carpetas del disco NAS.
2. **Variante** (`GET .../reproduccion/manifiesto/{altura}.m3u8`): `ManifiestoHlsService::truncarVariante()` lee la variante real, recorre sus entradas `#EXTINF` y solo incluye los segmentos cuyo tiempo acumulado es menor al "segundo máximo permitido" del usuario. Si la lista queda incompleta, la playlist se marca `#EXT-X-PLAYLIST-TYPE:EVENT` (en vez de `VOD`) y **sin** `#EXT-X-ENDLIST`: eso le indica a hls.js que puede haber más segmentos después y que debe volver a pedir el manifiesto periódicamente, en vez de asumir que ya tiene todo el video.
3. **Segmento** (`GET .../reproduccion/segmento/{altura}/{archivo}`): antes de servir un `.ts`, el controlador vuelve a calcular su segundo de inicio a partir del índice en el nombre de archivo (`{altura}p_{índice}.ts`) y rechaza con `403` cualquier segmento cuyo inicio supere el límite permitido, sin importar si venía listado en una respuesta anterior del manifiesto.

En producción, servir el `.ts` puede optimizarse con un header `X-Accel-Redirect` para que Nginx lo entregue directamente sin pasar por PHP (ver ejemplo de configuración más abajo); esto es una optimización de rendimiento, no un requisito de seguridad: `MediaStorageService::respuesta()` ya sirve el archivo correctamente por streaming sin este header.

### 3.3 Heartbeats y finalización automática

`resources/js/components/MiCapacitacion/ReproductorVideo.vue` inicializa hls.js (o el `<video>` nativo en Safari), y cada `heartbeat_segundos` (por defecto 8, configurable con `VIDEO_HEARTBEAT_SECONDS`) llama a `POST .../reproduccion/heartbeat` con la posición actual. La respuesta indica:

- `permitido`: si la posición reportada excede el límite, el servidor la rechaza, el reproductor hace `video.currentTime = posicion_permitida` y se muestra un aviso ("No puedes adelantar el video sin haberlo visto antes.").
- `porcentaje_visto` y `completada`: cuando el porcentaje único visto alcanza `config('media.video.completion_percent')` (98% por defecto), `ReproduccionVideoService` llama a `ProgresoService::completarLeccion()` automáticamente. **Las lecciones de video no tienen botón de "marcar como completada" manual** (`MiCapacitacionController::completarLeccion` lo rechaza explícitamente para `tipo = video`): la única forma de completarlas es viéndolas de verdad.

El evento `seeking` del `<video>` también recorta visualmente cualquier intento de arrastrar la barra más allá del límite conocido en el cliente, pero esto es solo una mejora de experiencia (evita el salto visual antes de que llegue la respuesta del heartbeat); la aplicación real de la regla ocurre siempre en el servidor, como se describió arriba.

## 4. Ejemplo de configuración Nginx con `X-Accel-Redirect` (opcional, producción)

Solo relevante cuando el disco `nas` es una carpeta local (montada por NFS/SMB) accesible también por Nginx. Permite que Nginx entregue los segmentos `.ts` directamente, sin que PHP-FPM mantenga el proceso ocupado durante toda la transferencia:

```nginx
location /internal/nas/ {
    internal;
    alias /mnt/mrlana-capacitacion/;
}
```

Y en `MediaStorageService::respuesta()`, en vez de `FilesystemAdapter::response()`, se devolvería una respuesta vacía con el header `X-Accel-Redirect: /internal/nas/{ruta}`. Esta fase no implementa esa variante (no hay Nginx en el entorno de desarrollo para probarla) y usa siempre streaming directo vía Laravel, que es correcto en cualquier entorno; queda documentado aquí como la optimización recomendada al llegar a producción.

## 5. Variables de entorno relevantes

```env
# FFMPEG_BIN=/usr/bin/ffmpeg
# FFPROBE_BIN=/usr/bin/ffprobe
# MEDIA_TOKEN_TTL=600            # segundos de vigencia de cada URL firmada de manifiesto/segmento
# MEDIA_ALLOWED_ORIGIN=
# MEDIA_MAX_UPLOAD_MB=2048
# VIDEO_COMPLETION_PERCENT=98    # % único visto para completar la leccion automaticamente
# VIDEO_HEARTBEAT_SECONDS=8      # cada cuanto el reproductor reporta su posicion
# VIDEO_PLAYBACK_RATE=1          # reservado; no se usa aun para limitar velocidad de reproduccion
```

`config('media.video.salto_tolerancia_segundos')` (5 segundos, no expuesto como variable de entorno por ahora) es la tolerancia de avance descrita en 3.1.
