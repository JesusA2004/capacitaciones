# Procesamiento de video, reproductor HLS y control de avance

Este documento explica el recorrido completo de un video: desde que se sube a la biblioteca multimedia hasta que un colaborador lo ve en "Mi capacitación", incluyendo cómo se impide que adelante partes que no ha visto.

## 1. Subida por bloques reanudable (Fase 9) y encolado

`cargas_multimedia` existía desde la Fase 3 como tabla fantasma (esquema sin ningún código que la usara); la Fase 9 la implementó de verdad. Documentos e imágenes siguen subiéndose en un único request (`useForm({ forceFormData: true })`, suficiente para su tamaño típico); **solo los videos usan carga por bloques**, orquestada por `App\Services\Multimedia\CargaResumibleService` y consumida desde el frontend por `resources/js/composables/useCargaResumible.ts` + `resources/js/components/Multimedia/CargaVideoResumible.vue`.

### Flujo

1. **Iniciar** (`POST /multimedia/cargas`): el frontend envía `nombre_original`/`tipo`/`tamano_total_bytes`; el backend calcula `total_bloques = ceil(tamaño / tamaño_de_bloque)` y crea un `CargaMultimedia` con `identificador` (UUID público, nunca el `id` autoincremental). Si ya existe una carga `en_progreso`/`pausada` con el mismo nombre y tamaño para ese usuario, la reanuda en vez de duplicarla (idempotencia de `iniciar()`).
2. **Bloques** (`POST /multimedia/cargas/{identificador}/bloques`): cada bloque se guarda como archivo independiente numerado (`temporales/cargas/{identificador}/{n}.part`), lo que permite recibirlos **fuera de orden** y reintentar uno solo sin repetir los demás. Reenviar el mismo número de bloque es idempotente (no duplica bytes contados).
3. **Pausar/Reanudar** (`POST .../pausar`, `POST .../reanudar`): pausar aborta el envío en curso desde el cliente (XHR `abort()`) y marca la carga `pausada` en el servidor, que deja de aceptar bloques hasta que se reanude explícitamente.
4. **Cancelar** (`DELETE /multimedia/cargas/{identificador}`): borra la carpeta de bloques temporales y marca `cancelada`.
5. **Ensamblado** (automático al recibir el último bloque pendiente): `MediaStorageService::ensamblarBloques()` concatena los bloques en orden (streaming, sin cargar el archivo completo en memoria), valida que el tamaño final coincida con `tamano_total_bytes`, calcula `sha256` y lo compara contra `hash_esperado` si el cliente lo envió. Si algo no coincide, la carga queda `error` (con el mensaje en `CargaMultimedia.error`) y **no** se crea el `RecursoMultimedia`. Si todo es correcto, se crea el recurso (`estado = pendiente` para video) y se despacha `ProcesarVideoJob`, igual que en el flujo de subida directa.
6. **Recuperar tras recargar la página**: el frontend guarda `{identificador, nombreOriginal, tamanoTotalBytes}` en `localStorage`. Si el usuario recarga la página con una carga incompleta, el diálogo se lo indica y, si vuelve a seleccionar **el mismo archivo** (el navegador no permite recuperar el contenido de un archivo local automáticamente por seguridad), continúa desde los bloques ya recibidos en vez de empezar de cero.

### Configuración (tamaño de bloque, límites, expiración, limpieza)

```env
# MEDIA_CHUNK_SIZE_MB=8          # tamaño de bloque sugerido por el backend
# MEDIA_CARGA_EXPIRA_HORAS=24    # una carga sin completar más allá de esto se marca "expirada"
# MEDIA_MAX_UPLOAD_MB=2048       # tamaño total máximo de un video (suma de todos los bloques)
```

- **Directorio temporal**: `temporales/cargas/{identificador}/` dentro del disco `nas` (mismo disco que el archivo final, no un directorio del sistema operativo aparte) — así funciona igual con `NAS_DRIVER=local` o `sftp`.
- **Límites de PHP** (`php.ini`) que deben ajustarse en el servidor: `upload_max_filesize` y `post_max_size` deben ser mayores a `MEDIA_CHUNK_SIZE_MB` (no al tamaño total del video: cada bloque llega en un request separado). Con el valor por defecto (8 MB), un `upload_max_filesize=16M`/`post_max_size=16M` da margen suficiente para los encabezados multipart. `max_execution_time`/`max_input_time` no necesitan ser grandes por este flujo (cada request es corto), a diferencia del procesamiento FFmpeg (que corre en cola, no en un request HTTP).
- **Límites de Nginx**: ver `deploy/nginx/multimedia.conf`, bloque `location /multimedia/cargas/` (`client_max_body_size` acorde al tamaño de bloque, no al video completo) y `location = /multimedia` (la subida directa de documentos/imágenes sí necesita `client_max_body_size` acorde a `MEDIA_MAX_UPLOAD_MB`).
- **Política de limpieza**: el comando `capacitacion:limpiar-cargas-expiradas` (`App\Console\Commands\LimpiarCargasMultimediaExpiradasCommand`, programado cada hora en `routes/console.php`) marca como `expirada` cualquier carga sin completar cuyo `expira_en` ya pasó, y borra su carpeta de bloques temporales. Una carga `completada`/`cancelada`/`expirada` nunca dispara la limpieza dos veces (el propio estado ya excluye esas cargas de la consulta).

Pruebas: `tests/Feature/Multimedia/CargaResumibleTest.php` (bloques fuera de orden, reintento idempotente, pausa/reanudación, cancelación, verificación de hash, aislamiento entre usuarios).

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

## 4. `X-Accel-Redirect` con Nginx (Fase 9, real — no solo documentado)

`MediaStorageService::respuesta()` (usada por segmentos `.ts`, descargas de evidencias de actividades/cuestionarios y cualquier otro archivo servido por streaming) soporta dos modos, controlados por `config('media.x_accel_redirect')` (`MEDIA_X_ACCEL_REDIRECT`):

- **Desactivado (por defecto, y siempre en este entorno de desarrollo sin Nginx)**: streaming directo vía `FilesystemAdapter::response()`, igual que en fases anteriores. Correcto en cualquier entorno, solo mantiene ocupado un worker de PHP-FPM durante toda la transferencia.
- **Activado**: `MediaStorageService` no lee el archivo; responde solo con el header `X-Accel-Redirect: {MEDIA_X_ACCEL_INTERNAL_PREFIX}/{ruta}` (prefijo por defecto `/protegido-nas`) y cuerpo vacío. Nginx intercepta esa respuesta y sirve el archivo directamente desde disco usando el `location internal` de `deploy/nginx/multimedia.conf`, sin volver a pasar por PHP. El navegador nunca ve la ruta física real del NAS, solo la ruta protegida que Nginx resuelve puertas adentro (la ubicación es `internal`, así que una petición externa directa a esa ruta devuelve 404).

La autorización (usuario, asignación al curso, token firmado, límite de avance del video) ocurre siempre **antes** de decidir si se activa `X-Accel-Redirect` — Nginx nunca decide quién puede ver el archivo, solo lo transmite después de que Laravel ya lo autorizó. Ver `app/Http/Controllers/MiCapacitacion/ReproduccionController.php::segmento()`.

Configuración real (`deploy/nginx/multimedia.conf`, agregar dentro del `server{}` del sitio):

```nginx
location /protegido-nas/ {
    internal;
    alias /mnt/mrlana-capacitacion/;   # mismo directorio que NAS_ROOT
}
```

Pruebas: `tests/Feature/Multimedia/XAccelRedirectTest.php` verifica que el header se emite correctamente activado/desactivado y que la ruta expuesta nunca contiene la ruta física del servidor — con `Storage::fake`, sin necesidad de un Nginx real corriendo. La verificación end-to-end (Nginx real sirviendo el archivo) queda pendiente de un entorno de producción real, como se declara en `docs/AUDITORIA_CUMPLIMIENTO.md` sección 9.

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
