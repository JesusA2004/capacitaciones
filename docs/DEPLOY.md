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
npm ci && npm run build

cp .env.example .env   # solo la primera vez; luego editar con los valores reales
php artisan key:generate   # solo la primera vez

php artisan migrate --force
php artisan db:seed --force     # RolesYPermisosSeeder y BirthdayPhraseSeeder son idempotentes (firstOrCreate)

php artisan optimize:clear
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
