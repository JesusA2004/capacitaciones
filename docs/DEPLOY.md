# Deploy (VPS)

Runbook consolidado para desplegar/actualizar MR. LANA PEOPLE en el VPS
(`https://people.mr-lana.com`). Cada módulo documenta sus propios detalles aparte
(enlazados abajo); esta página es la checklist operativa.

## Prerrequisitos del servidor

- PHP 8.3+ con extensión **GD** habilitada (usada por el módulo de cumpleaños para
  generar la tarjeta PNG, `App\Services\Cumpleanos\BirthdayCardService` — sin
  dependencias externas nuevas).
- MariaDB/MySQL y una cola configurada (`QUEUE_CONNECTION` en `.env` — `database` sirve
  para desarrollo, Redis se recomienda en producción).
- Nginx + PHP-FPM.
- Synology montado (NFS o SFTP) para el disco `nas` — ver `docs/CONFIGURACION_NAS.md` y
  `docs/SYNOLOGY_STORAGE.md`. En producción: `NAS_ROOT=/mnt/people-storage` (nunca se
  expone esta ruta al frontend — cualquier archivo del disco `nas` se sirve siempre por
  streaming a través de un controlador, ver los distintos `*StorageService`).
- Cron del sistema (para el scheduler de Laravel) y systemd/Supervisor (para
  `queue:work` y `reverb:start`).

## Deploy (primera vez o rutina)

```bash
composer install --no-dev --optimize-autoloader

cp .env.example .env   # solo la primera vez; luego editar con los valores reales
php artisan key:generate   # solo la primera vez

# IMPORTANTE: limpiar cache de rutas/config ANTES de `npm run build`, no despues.
# El plugin de Vite @laravel/vite-plugin-wayfinder corre `php artisan wayfinder:generate`
# como parte de `vite build` y ese comando lee las rutas registradas por Laravel: si
# `bootstrap/cache/routes-v7.php` quedo cacheado de un deploy anterior (`route:cache` al
# final de esta misma rutina), el build lee esa cache VIEJA en vez de routes/*.php nuevos
# y no genera los helpers de las rutas agregadas/renombradas en este deploy -> `npm run
# build` truena con `[UNLOADABLE_DEPENDENCY]` en imports de `@/routes/...` que en el
# working tree existen pero en el build no se generaron. `composer install` ya dispara
# esto automaticamente via el hook `post-autoload-dump` (ver composer.json), pero se deja
# explicito aqui por si se hace un deploy sin volver a correr composer install.
php artisan optimize:clear

npm ci && npm run build

php artisan migrate --force
php artisan db:seed --force     # RolesYPermisosSeeder y BirthdayPhraseSeeder son idempotentes (firstOrCreate)

php artisan permission:cache-reset
php artisan config:cache
php artisan route:cache
```

`db:seed --force` con `composer install --no-dev` funciona sin problema: los seeders de
producción (`RolesYPermisosSeeder`, `BirthdayPhraseSeeder`) no dependen de
`fakerphp/faker` (paquete `require-dev`) — solo los seeders de datos de **demostración**
(`UsuarioDemoSeeder`, etc., pensados para desarrollo) usarían `fake()`, y no deben
correrse en producción.

## Verificar rutas nuevas después de un deploy

```bash
php artisan route:list | grep cumple
php artisan route:list | grep -E "^\s+GET.*\bapp\b|app/descargar|app/versiones"
php artisan route:list | grep incorporacion/qr
php artisan route:list --path=api/v1 | grep cumple
php artisan route:list --path=api/v1 | grep app
```

## Procesos supervisados (systemd/Supervisor)

```bash
php artisan queue:work --tries=3                        # notificaciones + push (ShouldQueue)
php artisan reverb:start --host=0.0.0.0 --port=8080      # WebSocket, detras de Nginx
```

## Scheduler (cron)

Una sola línea de cron para **todos** los comandos programados de `routes/console.php`
(incluye `cumpleanos:enviar-felicitaciones` a las 08:00 y `cumpleanos:recordar-rh` a las
07:30, hora `America/Mexico_City`, además de los recordatorios de capacitación ya
existentes):

```cron
* * * * * cd /var/www/people && php artisan schedule:run >> /dev/null 2>&1
```

Correr un comando manualmente de más nunca duplica nada (ver `docs/CUMPLEANOS.md`,
sección de idempotencia) — es seguro probar con `php artisan cumpleanos:enviar-felicitaciones`
directo por SSH sin esperar al cron.

## Nginx

`deploy/nginx/reverb.conf` (WebSocket de Reverb + carve-out para que `/app`,
`/app/descargar` y `/app/versiones` se sirvan por Laravel, ver
`docs/APP_RELEASES.md`) y `deploy/nginx/multimedia.conf` (X-Accel-Redirect para
biblioteca multimedia) son **fragmentos**, no vhosts completos: se pegan dentro del
`server { ... }` del sitio ya existente, junto al `location /` que reenvía a PHP-FPM.

**Verificación obligatoria tras aplicar `deploy/nginx/reverb.conf`** (ver
`docs/APP_RELEASES.md`, sección "Nginx: /app colisiona con el WebSocket de Reverb"):
confirmar que estas cuatro URLs las responde Laravel (HTML/JSON), **no** el proxy de
Reverb (que respondería `400`/cierre de conexión al no ser un handshake WebSocket
válido):

```bash
curl -I https://people.mr-lana.com/app
curl -I https://people.mr-lana.com/app/versiones
curl -I https://people.mr-lana.com/app/descargar
curl -I https://people.mr-lana.com/app/descargar/android
```

## Subida de APK: límites de Nginx y PHP

El backend valida hasta `MOBILE_APK_MAX_MB` (250 MB por defecto, `config/mobile_releases.php`,
ver `docs/APP_RELEASES.md`), pero **Nginx y PHP tienen sus propios límites por delante**
de esa validación — si son menores, la subida falla antes de que Laravel la vea (con un
error confuso, no el mensaje amable de `StoreMobileAppReleaseRequest`). Deben permitir
**al menos 300 MB** (margen sobre los 250 MB de la app, para los encabezados
`multipart/form-data` del propio request):

```nginx
# Dentro del server{} del sitio (o en el location del formulario de subida):
client_max_body_size 300M;
```

```ini
; php.ini (o un pool de PHP-FPM dedicado si el sitio ya tiene límites más bajos
; para el resto de la app):
upload_max_filesize = 300M
post_max_size = 300M
```

```bash
sudo systemctl reload nginx
sudo systemctl restart php8.4-fpm   # o la version de PHP-FPM real del VPS
```

Si se sube un APK que excede `MOBILE_APK_MAX_MB` pero SÍ cabe en estos límites de
infraestructura, la validación de Laravel responde con un mensaje amable en MB (no la
tecnicoseca "must not be greater than N kilobytes" por defecto) — ver
`StoreMobileAppReleaseRequest::messages()`.

## Storage privado

Todo archivo subido por un usuario (documentos de expediente, CVs, formatos, tarjetas
de cumpleaños, APKs) vive en el disco `nas` (`Storage::disk('nas')`), nunca en
`public/` ni en la base de datos — solo se guarda la ruta lógica. La descarga siempre
pasa por un controlador con permiso/policy; ningún modelo expone su columna de ruta
física al frontend (`$hidden`, ver p. ej. `MobileAppRelease::$hidden`).

## Checklist post-deploy de este encargo (cumpleaños + descarga de app)

1. `php artisan route:list | grep cumple` y `... | grep app` muestran las rutas nuevas.
2. `/rh/cumpleanos` carga para un usuario `rh_admin` (permiso `rh.cumpleanos.ver`).
3. `/app` muestra "no disponible" si aún no se ha subido/publicado ningún APK, o la
   tarjeta de descarga si ya hay una versión publicada — nunca un botón que no hace
   nada.
4. `/incorporacion/qr/{token}` (con un token real generado desde RH) sigue funcionando
   igual que antes, ahora con el botón "Descargar app" adicional.
5. Cron activo (`crontab -l`) y `php artisan schedule:list` muestra
   `cumpleanos:enviar-felicitaciones`/`cumpleanos:recordar-rh`.
6. `queue:work` corriendo (las notificaciones/push son `ShouldQueue`).

## Ver también

- `docs/CUMPLEANOS.md` — módulo de cumpleaños completo.
- `docs/APP_RELEASES.md` — descarga de app / APK, incluyendo el detalle de la colisión
  con Reverb en Nginx.
- `docs/BACKEND_MOBILE_V5.md` — resto del backend móvil (bootstrap, push, RH móvil).
- `docs/CONFIGURACION_NAS.md` / `docs/SYNOLOGY_STORAGE.md` — almacenamiento NAS.
- `docs/PUSH_NOTIFICATIONS.md` — Expo (push) y Reverb (tiempo real web).
