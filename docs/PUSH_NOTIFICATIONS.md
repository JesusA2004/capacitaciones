# Notificaciones (push app móvil + tiempo real web)

Dos canales de entrega para el mismo evento de negocio, resueltos siempre en el backend — el frontend/app nunca decide destinatarios ni contenido:

1. **Push nativo (Expo)** — para la app móvil (iOS/Android), vía `App\Services\MobilePush\ExpoPushService` + `App\Jobs\SendExpoPushJob`.
2. **Tiempo real en el portal web** — vía Laravel Reverb (self-hosted, protocolo Pusher) + Laravel Echo en el frontend Inertia. Mismo servidor Reverb queda listo para que un cliente Pusher-compatible de la app móvil lo consuma en el futuro (ver "Autorización de canales" abajo).

Ambos parten de la misma notificación (`App\Notifications\Mobile\*`, canal `database` + `broadcast`): nunca hay dos piezas de lógica separadas para "avisar por push" y "avisar en tiempo real".

## 1. Push vía Expo

### Config

`config/expo.php` (`EXPO_PUSH_ENABLED`, `EXPO_PUSH_ENDPOINT`). Si `EXPO_PUSH_ENABLED=false`, `ExpoPushService::enviar()` no hace nada (no lanza error).

### Registro de dispositivo

```
POST   /api/v1/dispositivos/push-token   { token, platform, device_name?, app_version? }
DELETE /api/v1/dispositivos/push-token   { token }
```

`App\Services\MobilePush\PushTokenService::registrar()` hace `updateOrCreate` por `push_token` (nunca duplica); `revocar()` marca `revoked_at` (no borra la fila, para conservar el historial). Tabla `mobile_devices` (migración `2026_09_10_090000_create_mobile_devices_table`).

### Envío

`App\Services\MobilePush\PushNotifier::aUsuario()`/`aUsuarios()` — resuelve los `push_token` **activos** (`revoked_at IS NULL`) del usuario y encola un `SendExpoPushJob` por token. El job llama a `ExpoPushService::enviar()`, que hace `Http::post()` a `config('expo.endpoint')`.

**Nunca puede tumbar la acción principal**: cada servicio de negocio (`SolicitudesService`, `VacacionesService`, `IncorporacionService`) envuelve la notificación+push en un `notificarSinFallar()` que atrapa cualquier excepción y solo la registra en el log (`Log::warning`). El evento principal (aprobar/rechazar/crear) ya quedó persistido en base de datos antes de llamar aquí.

### Payload exacto

```json
{
  "to": "ExponentPushToken[...]",
  "title": "Nueva solicitud por revisar",
  "body": "Un colaborador envió una solicitud.",
  "data": { "type": "rh_solicitud", "resource_id": 184 }
}
```

Nunca lleva PII sensible (nombres completos, montos, CURP/RFC/NSS): el detalle se consulta ya autenticado dentro de la app.

### Tipos (`data.type`)

| Para colaborador | Para RH/aprobador |
|---|---|
| `solicitud` | `rh_solicitud` |
| `documento` | `rh_documento` |
| `vacaciones` | `rh_vacaciones` |
| `incorporacion` | `rh_incorporacion` |
| `notificacion` (genérico) | `rh_pendiente` (genérico, bandeja) |

### Eventos que disparan push (todos ya wireados)

| Evento | Servicio | Destinatario | `type` |
|---|---|---|---|
| Colaborador crea solicitud | `SolicitudesService::crear()` | Responsables con `rh.solicitudes.aprobar` en el alcance del colaborador | `rh_solicitud` |
| RH aprueba/rechaza/pide corrección de solicitud | `SolicitudesService::cambiarEstado()` | Colaborador dueño | `solicitud` |
| Colaborador solicita vacaciones | `VacacionesService::solicitar()` | Responsables con `rh.vacaciones.aprobar` | `rh_vacaciones` |
| RH aprueba/rechaza vacaciones | `VacacionesService::aprobar()`/`rechazar()` | Colaborador dueño | `vacaciones` |
| Colaborador sube documento (incorporación) | `IncorporacionService::subirDocumento()` | Responsables con `rh.documentos.ver` | `rh_documento` |
| RH aprueba/rechaza documento | `IncorporacionService::aprobarDocumento()`/`rechazarDocumento()` | Colaborador dueño | `documento` |
| Último documento obligatorio queda aprobado (incorporación "completo") | `IncorporacionService::avisarSiIncorporacionQuedoCompleta()` (interno, llamado desde `aprobarDocumento()`) | Responsables con `rh.incorporaciones.ver` | `rh_incorporacion` |
| RH aprueba/rechaza incorporación | `IncorporacionService::aprobarIncorporacion()`/`rechazarIncorporacion()` | Colaborador | `incorporacion` |

**Resolución de destinatarios**: `App\Services\RhMobile\ResponsableResolverService::paraColaborador($colaborador, $permiso)` — usuarios con el permiso dado (Spatie, vía roles o directo) cuyo `App\Services\AlcanceOrganizacionalService::puedeVerUsuario()` cubre al colaborador (empresa/sucursal/jefe directo). Nunca hardcodea usuarios ni roles específicos.

## 2. Tiempo real en el portal web (Laravel Reverb)

El portal web (Inertia) ya sondeaba notificaciones cada 30s (`resources/js/composables/useNotificaciones.ts`). Se agregó un canal en tiempo real para que una notificación nueva aparezca al instante y dispare un toast + **notificación nativa del sistema operativo** (Windows/macOS/Android vía Chrome/Edge) mientras la sesión esté abierta — sin depender del polling.

### Backend

- `laravel/reverb` (self-hosted, protocolo compatible con Pusher — no requiere cuenta externa). Config: `config/reverb.php`, `config/broadcasting.php`.
- `routes/channels.php`: canal privado `App.Models.User.{id}` (default de Laravel), autorizado solo para el propio usuario.
- Las 8 notificaciones de `App\Notifications\Mobile\*` usan el trait `App\Notifications\Mobile\Concerns\BroadcastsNotificacion` (`via()` incluye `'broadcast'`) — mismo payload que `toDatabase()`, sin datos sensibles adicionales.
- Ruta de autorización web: `POST /broadcasting/auth` (registrada automáticamente por `channels:` en `bootstrap/app.php`, sesión Laravel estándar).
- Ruta de autorización para clientes Bearer (Sanctum): `POST /api/v1/broadcasting/auth` — la app móvil **no la consume todavía** (fuera de alcance de este encargo: "NO tocar la app móvil"), pero el servidor Reverb ya queda listo para que cualquier cliente compatible con Pusher (p. ej. `pusher-js`/`laravel-echo` en React Native) se conecte con el mismo canal `App.Models.User.{id}`, autenticando con su Bearer token.

### Frontend (Inertia/Vue)

- `resources/js/echo.ts`: cliente único de Laravel Echo (`broadcaster: 'reverb'`), configurado con `VITE_REVERB_*`.
- `resources/js/composables/useNotificacionesTiempoReal.ts`: se suscribe a `private-App.Models.User.{id}` con `.notification()` (helper nativo de Echo para notificaciones de Laravel), muestra un toast (`vue-sonner`) y, si el navegador dio permiso, una `Notification` nativa del sistema operativo. Import perezoso de `@/echo` (solo cuando hay `userId`, nunca en la pantalla de login).
- `resources/js/composables/useNotificaciones.ts`: al montar, además del polling de 30s (que queda como respaldo), llama a `useNotificacionesTiempoReal()`; cualquier evento recibido dispara un refresh inmediato de la campana (`NotificationBell.vue`, sin cambios).

### Variables de entorno

```
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST=localhost        # produccion: people.mr-lana.com
REVERB_PORT=8080             # produccion: 443 (detras de Nginx)
REVERB_SCHEME=http           # produccion: https

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

### Correr Reverb

En desarrollo: `php artisan reverb:start` (puerto 8080 por defecto), en paralelo al `php artisan serve`/WAMP y al `queue:work` (las notificaciones son `ShouldQueue`, necesitan un worker corriendo — ver `docs/COLAS_Y_SCHEDULER.md`).

En producción (VPS, detrás de Nginx): `php artisan reverb:start --host=0.0.0.0 --port=8080` como proceso supervisado (systemd/Supervisor, igual patrón que `queue:work`), con Nginx haciendo `proxy_pass` a `127.0.0.1:8080` para la ruta del WebSocket (`/app/{key}`) con upgrade a `Upgrade: websocket` — ver plantilla en `deploy/nginx/`. `REVERB_HOST=people.mr-lana.com`, `REVERB_PORT=443`, `REVERB_SCHEME=https` para que el cliente se conecte por `wss://`.

### Límite conocido

No usa Service Worker ni Web Push (VAPID): la notificación nativa del navegador solo se dispara mientras la pestaña/sesión del portal esté abierta (puede estar minimizada o en segundo plano, pero el navegador debe seguir corriendo). Con el navegador completamente cerrado no llega nada — eso requeriría una suscripción Web Push aparte, fuera de alcance de este encargo.
