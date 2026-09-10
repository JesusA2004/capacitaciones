# Backend móvil v5 — resumen

Segunda fase del backend para la app móvil de MR. LANA PEOPLE: mientras Fase 1 (`docs/API_MOVIL.md`) cubría solo al colaborador viendo lo suyo, esta fase agrega el contexto de arranque de la app (bootstrap/config), push notifications reales (Expo), y el trabajo de RH/aprobadores completo desde la app (dashboard, bandeja unificada, solicitudes, vacaciones, documentos, incorporaciones, colaboradores) — ver `docs/RH_MOBILE_API.md`. También se agregaron notificaciones en tiempo real al portal web (`docs/PUSH_NOTIFICATIONS.md`).

## Dónde está cada cosa

| Qué | Dónde |
|---|---|
| Contrato completo de la API de colaborador (Fase 1) | `docs/API_MOVIL.md` |
| Contrato completo de la API de RH/aprobadores (Fase 2) | `docs/RH_MOBILE_API.md` |
| Push (Expo) y tiempo real web (Reverb) | `docs/PUSH_NOTIFICATIONS.md` |
| Permisos nuevos | `database/seeders/RolesYPermisosSeeder.php`, bloque "Backend movil v5" |
| Migraciones nuevas | `database/migrations/2026_09_10_090000_create_mobile_devices_table.php` |

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
```

(Detalle completo, request/response y reglas de cada uno: `docs/API_MOVIL.md` y `docs/RH_MOBILE_API.md`.)

## Componentes nuevos

- **Bootstrap**: `App\Services\Mobile\MobileBootstrapService` + `Api\V1\MobileBootstrapController`.
- **App config**: `config/mobile.php` + `Api\V1\AppConfigController`.
- **Push**: `App\Models\MobileDevice`, `App\Services\MobilePush\{PushTokenService,PushNotifier,ExpoPushService}`, `App\Jobs\SendExpoPushJob`, `config/expo.php`.
- **Notificaciones movil**: `App\Notifications\Mobile\*` (8 clases) + `App\Notifications\Mobile\Concerns\BroadcastsNotificacion`.
- **RH movil**: `App\Services\RhMobile\{RhDashboardService,RhPendientesService,WorkflowService,ResponsableResolverService}` + controladores `Api\V1\Rh\{Dashboard,Pendiente,Solicitud,Vacacion,Documento,Incorporacion,Colaborador}Controller`.
- **Tiempo real web**: Laravel Reverb (`config/reverb.php`, `config/broadcasting.php`, `routes/channels.php`) + `resources/js/echo.ts` + `resources/js/composables/useNotificacionesTiempoReal.ts`.

## Permisos nuevos

Ver el bloque "Backend movil v5" en `RolesYPermisosSeeder`: `mobile.bootstrap.ver`, `app.config.ver`, `dispositivos.push_token.{registrar,revocar}`, `rh.mobile.dashboard.ver`, `rh.pendientes.ver`, `rh.{solicitudes,vacaciones,documentos,incorporaciones,colaboradores}.{ver,detalle,aprobar,rechazar,...}`, `notificaciones.leer_todas`, `solicitudes.{configuracion.ver,adjuntos.subir}`. Todos se crean con `Permission::firstOrCreate(guard_name: 'web')` y se asignan por rol (ver detalle de qué rol tiene qué en `docs/RH_MOBILE_API.md`, sección "Permisos nuevos").

## Variables de entorno nuevas

Ver `.env.example`, bloques "App movil v5", "Push notifications via Expo" y "Notificaciones en tiempo real del portal web": `APP_MOBILE_*`, `EXPO_PUSH_*`, `REVERB_*`/`VITE_REVERB_*`, `BROADCAST_CONNECTION=reverb`.

## Comandos para desplegar en VPS

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan migrate --force
php artisan db:seed --force            # RolesYPermisosSeeder es idempotente (firstOrCreate)
php artisan optimize:clear
php artisan permission:cache-reset
php artisan config:cache
php artisan route:cache

# Procesos supervisados (systemd/Supervisor), ademas de php-fpm/Nginx:
php artisan queue:work --tries=3       # notificaciones + push (ShouldQueue)
php artisan reverb:start --host=0.0.0.0 --port=8080    # WebSocket, detras de Nginx (proxy_pass + upgrade)
```

Nginx debe reenviar la ruta del WebSocket de Reverb (`/app/*`) al puerto interno 8080 con `Upgrade: websocket` — ver `deploy/nginx/`. `REVERB_HOST=people.mr-lana.com`, `REVERB_PORT=443`, `REVERB_SCHEME=https` en producción.

## Qué NO se implementó en esta fase (alcance explícitamente fuera)

- Motor de workflow multi-etapa real (hoy una sola etapa "rh", extensible — ver `WorkflowService`).
- Señal de `prioridad` distinta de `"normal"` en la bandeja de pendientes.
- Web Push (Service Worker + VAPID) para notificar con el navegador completamente cerrado — el tiempo real web solo funciona con la sesión/pestaña abierta.
- Cambios en el repo de la app móvil (`mr-lana-people-app`): fuera de alcance explícito del encargo.
