# Backend móvil v5 — resumen

Segunda fase del backend para la app móvil de MR. LANA PEOPLE: mientras Fase 1 (`docs/API_MOVIL.md`) cubría solo al colaborador viendo lo suyo, esta fase agrega el contexto de arranque de la app (bootstrap/config), push notifications reales (Expo), y el trabajo de RH/aprobadores completo desde la app (dashboard, bandeja unificada, solicitudes, vacaciones, documentos, incorporaciones, colaboradores) — ver `docs/RH_MOBILE_API.md`. También se agregaron notificaciones en tiempo real al portal web (`docs/PUSH_NOTIFICATIONS.md`).

## Dónde está cada cosa

| Qué | Dónde |
|---|---|
| Contrato completo de la API de colaborador (Fase 1) | `docs/API_MOVIL.md` |
| Contrato completo de la API de RH/aprobadores (Fase 2) | `docs/RH_MOBILE_API.md` |
| Push (Expo) y tiempo real web (Reverb) | `docs/PUSH_NOTIFICATIONS.md` |
| Cumpleaños (colaborador + bandeja RH) | `docs/CUMPLEANOS.md` |
| Descarga/actualización de la app (APK + futuro iOS) | `docs/APP_RELEASES.md` |
| Permisos nuevos | `database/seeders/RolesYPermisosSeeder.php`, bloques "Backend movil v5", "Modulo de cumpleanos" y "Descarga de app / APK" |
| Migraciones nuevas | `database/migrations/2026_09_10_090000_create_mobile_devices_table.php`, `..._090100_create_birthday_phrases_table.php`, `..._090101_create_birthday_greetings_table.php`, `..._090102_create_mobile_app_releases_table.php`, `..._090103_add_ios_urls_to_mobile_app_releases_table.php` |

## Endpoints nuevos (además de los ya documentados en API_MOVIL.md)

```
GET    /api/v1/app/config                                   público
GET    /api/v1/mobile/bootstrap                              auth:sanctum
POST   /api/v1/dispositivos/push-token                       auth:sanctum
DELETE /api/v1/dispositivos/push-token                       auth:sanctum
GET    /api/v1/colaborador/foto                               auth:sanctum
POST   /api/v1/notificaciones/leer-todas                     auth:sanctum
GET    /api/v1/solicitudes/configuracion                     auth:sanctum
POST   /api/v1/solicitudes/{solicitud}/adjuntos               auth:sanctum
POST   /api/v1/broadcasting/auth                              auth:sanctum (canales Reverb, ver docs/PUSH_NOTIFICATIONS.md)

GET  /api/v1/rh/dashboard
GET  /api/v1/rh/pendientes
GET|POST /api/v1/rh/solicitudes[/{id}/aprobar|rechazar|correccion]
GET|POST /api/v1/rh/vacaciones[/{id}/aprobar|rechazar]
GET|POST /api/v1/rh/documentos[/{id}/ver|aprobar|rechazar]
GET|POST /api/v1/rh/incorporaciones[/{id}/aprobar|rechazar]
GET  /api/v1/rh/colaboradores[/{id}]

GET  /api/v1/colaborador/cumpleanos/felicitacion-actual[/imagen]   auth:sanctum (propia)
GET  /api/v1/rh/cumpleanos[?periodo=hoy|7_dias|30_dias|mes&...]    permiso rh.cumpleanos.ver
GET  /api/v1/rh/cumpleanos/{greeting}[/imagen]                     permiso rh.cumpleanos.ver
GET  /api/v1/rh/cumpleanos/{colaborador}/foto                      permiso rh.cumpleanos.ver

GET  /api/v1/app/releases/latest?platform=android|ios              público
GET  /api/v1/app/releases?platform=android|ios                     público
```

(Detalle completo, request/response y reglas de cada uno: `docs/API_MOVIL.md`, `docs/RH_MOBILE_API.md`, `docs/CUMPLEANOS.md` y `docs/APP_RELEASES.md`.)

`GET /api/v1/app/config` (ya existente, ver arriba) ahora también expone
`minimum_android_version`/`minimum_android_build`/`minimum_ios_version`/
`minimum_ios_build`, `download_url`/`update_url` y `ios.{install_url,store_url}` — las
claves anteriores (`minimum_version`, `latest_version`, `force_update`) se conservan
por compatibilidad. `features` ahora incluye `cumpleanos` (además de `rh_mobile`, ya
existente) para que la app pueda ocultar el módulo si se desactiva.

## Componentes nuevos

- **Bootstrap**: `App\Services\Mobile\MobileBootstrapService` + `Api\V1\MobileBootstrapController`.
- **App config**: `config/mobile.php` + `Api\V1\AppConfigController`.
- **Push**: `App\Models\MobileDevice`, `App\Services\MobilePush\{PushTokenService,PushNotifier,ExpoPushService}`, `App\Jobs\SendExpoPushJob`, `config/expo.php`.
- **Notificaciones movil**: `App\Notifications\Mobile\*` (8 clases) + `App\Notifications\Mobile\Concerns\BroadcastsNotificacion`.
- **RH movil**: `App\Services\RhMobile\{RhDashboardService,RhPendientesService,WorkflowService,ResponsableResolverService}` + controladores `Api\V1\Rh\{Dashboard,Pendiente,Solicitud,Vacacion,Documento,Incorporacion,Colaborador}Controller`.
- **Tiempo real web**: Laravel Reverb (`config/reverb.php`, `config/broadcasting.php`, `routes/channels.php`) + `resources/js/echo.ts` + `resources/js/composables/useNotificacionesTiempoReal.ts`.
- **Cumpleaños**: `App\Services\Cumpleanos\{CumpleanosService,BirthdayCardService,CumpleanosStorageService}`, modelos `BirthdayPhrase`/`BirthdayGreeting`, controladores `Rh\CumpleanosController` (web), `Api\V1\ColaboradorCumpleanosController` y `Api\V1\Rh\CumpleanosController`, commands `cumpleanos:enviar-felicitaciones`/`cumpleanos:recordar-rh` — ver `docs/CUMPLEANOS.md`.
- **Descarga de app**: `App\Services\AppReleases\{AppReleaseService,AppReleaseStorageService}`, modelo `MobileAppRelease`, controladores `AppDownloadController` (público), `Administracion\AppReleaseController` y `Api\V1\AppReleaseController` — ver `docs/APP_RELEASES.md`.

## Permisos nuevos

Ver el bloque "Backend movil v5" en `RolesYPermisosSeeder`: `mobile.bootstrap.ver`, `app.config.ver`, `dispositivos.push_token.{registrar,revocar}`, `rh.mobile.dashboard.ver`, `rh.pendientes.ver`, `rh.{solicitudes,vacaciones,documentos,incorporaciones,colaboradores}.{ver,detalle,aprobar,rechazar,...}`, `notificaciones.leer_todas`, `solicitudes.{configuracion.ver,adjuntos.subir}`. Todos se crean con `Permission::firstOrCreate(guard_name: 'web')` y se asignan por rol (ver detalle de qué rol tiene qué en `docs/RH_MOBILE_API.md`, sección "Permisos nuevos").

Cumpleaños (`rh.cumpleanos.{ver,calendario,descargar_imagen,configurar,frases.gestionar,notificaciones.gestionar}`) y descarga de app (`app_releases.{ver,crear,publicar,eliminar,descargar}`) se documentan en `docs/CUMPLEANOS.md` y `docs/APP_RELEASES.md` respectivamente.

## Variables de entorno nuevas

Ver `.env.example`, bloques "App movil v5", "Push notifications via Expo", "Notificaciones en tiempo real del portal web", "Modulo de cumpleanos" y "Descarga de app / APK": `APP_MOBILE_*`, `EXPO_PUSH_*`, `REVERB_*`/`VITE_REVERB_*`, `BROADCAST_CONNECTION=reverb`, `CUMPLEANOS_*`, `MOBILE_APP_DOWNLOAD_ENABLED`, `MOBILE_APK_MAX_MB`.

## Comandos para desplegar en VPS

```bash
composer install --no-dev --optimize-autoloader

# Limpiar cache de rutas/config ANTES del build de Vite: si no, el plugin
# @laravel/vite-plugin-wayfinder puede generar los helpers de `@/routes/...` a partir de
# una cache de rutas vieja y `npm run build` truena con [UNLOADABLE_DEPENDENCY] en rutas
# agregadas/renombradas en este deploy. Ver docs/DEPLOY.md.
php artisan optimize:clear

npm ci && npm run build

php artisan migrate --force
php artisan db:seed --force            # RolesYPermisosSeeder es idempotente (firstOrCreate)
php artisan permission:cache-reset
php artisan config:cache
php artisan route:cache

# Procesos supervisados (systemd/Supervisor), ademas de php-fpm/Nginx:
php artisan queue:work --tries=3       # notificaciones + push (ShouldQueue)
php artisan reverb:start --host=0.0.0.0 --port=8080    # WebSocket, detras de Nginx (proxy_pass + upgrade)

# Scheduler (cumpleanos:enviar-felicitaciones, cumpleanos:recordar-rh y los demas
# comandos programados en routes/console.php) via cron del sistema, no un proceso propio:
# * * * * * cd /var/www/people && php artisan schedule:run >> /dev/null 2>&1
```

Nginx debe reenviar la ruta del WebSocket de Reverb (`/app/*`) al puerto interno 8080 con `Upgrade: websocket` — ver `deploy/nginx/`. `REVERB_HOST=people.mr-lana.com`, `REVERB_PORT=443`, `REVERB_SCHEME=https` en producción.

**Nota:** el prefijo web público `/app` (página de descarga, `docs/APP_RELEASES.md`) y
el WebSocket de Reverb comparten el mismo segmento de URL (`/app/*`) solo por
coincidencia de nombres — no colisionan: Reverb corre en su propio puerto (8080) detrás
del `proxy_pass` de Nginx, mientras que `/app` es una ruta normal servida por
Laravel/Nginx en el puerto 443. Si se reconfigura el prefijo del WebSocket de Reverb,
revisar que no choque con rutas propias de la aplicación.

## Qué NO se implementó en esta fase (alcance explícitamente fuera)

- Motor de workflow multi-etapa real (hoy una sola etapa "rh", extensible — ver `WorkflowService`).
- Señal de `prioridad` distinta de `"normal"` en la bandeja de pendientes.
- Web Push (Service Worker + VAPID) para notificar con el navegador completamente cerrado — el tiempo real web solo funciona con la sesión/pestaña abierta.
- Cambios en el repo de la app móvil (`mr-lana-people-app`): fuera de alcance explícito del encargo.
