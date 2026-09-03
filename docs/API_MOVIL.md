# API móvil (v1)

API JSON versionada para la futura app móvil/web móvil de colaboradores (proyecto aparte — aquí solo se deja la API lista, documentada y probada, ver sección 3 del encargo). Prefijo `/api/v1`, namespace `App\Http\Controllers\Api\V1`.

## Autenticación

Laravel Sanctum, **tokens personales puros** (sin cookies, sin CSRF, sin `EnsureFrontendRequestsAreStateful`): cada dispositivo hace login y recibe su propio token, independiente de la sesión web.

```
POST /api/v1/login        { email, password, device_name? }  →  { token, usuario }
POST /api/v1/logout       (Bearer token)                     →  revoca el token actual
GET  /api/v1/me           (Bearer token)                     →  usuario, roles, permisos
```

Cada request autenticado manda `Authorization: Bearer <token>`. Un usuario inactivo (`estatus !== activo`) no puede iniciar sesión.

## Regla de oro: mismos Services que la web

Ningún controlador de `Api\V1` calcula nada por su cuenta. Todos llaman exactamente al mismo Service que usan los controladores Inertia — ver `docs/ARQUITECTURA_SERVICES.md`:

| Endpoint | Service |
|---|---|
| `colaborador/perfil`, `colaborador/dashboard` | `App\Services\Colaboradores\ColaboradorPerfilService` |
| `colaborador/vacaciones`, `vacaciones/*` | `App\Services\Vacaciones\VacacionesService` |
| `colaborador/solicitudes`, `solicitudes/*` | `App\Services\Solicitudes\SolicitudesService` |
| `colaborador/notificaciones`, `notificaciones/*` | `App\Services\Colaboradores\NotificacionesService` |

Los mismos `FormRequest` de la web (`StoreSolicitudInternaRequest`, `StoreSolicitudVacacionesRequest`) se reutilizan tal cual en la API — su `authorize()` no depende de parámetros de ruta web, así que funcionan igual en ambos contextos.

## Endpoints (Fase 1 — solo datos propios del colaborador)

```
GET  /api/v1/colaborador/perfil          nombre, puesto, sucursal, fecha de ingreso, antigüedad
GET  /api/v1/colaborador/dashboard       perfil + vacaciones + solicitudes recientes + notificaciones
GET  /api/v1/colaborador/vacaciones      alias de vacaciones/saldo
GET  /api/v1/colaborador/solicitudes
POST /api/v1/colaborador/solicitudes
GET  /api/v1/colaborador/notificaciones

GET  /api/v1/vacaciones/saldo
GET  /api/v1/vacaciones/solicitudes
POST /api/v1/vacaciones/solicitudes

GET  /api/v1/solicitudes
POST /api/v1/solicitudes
GET  /api/v1/solicitudes/{solicitud}

GET  /api/v1/notificaciones
POST /api/v1/notificaciones/{notificacion}/leer
```

## Qué NO expone la API en Fase 1

Documentos internos/confidenciales, expedientes de otros colaboradores, reportes RH, candidatos, vacantes internas, sueldos, datos de otros colaboradores. Un colaborador solo puede leer/crear lo suyo — `SolicitudInterna::show`/`index` filtran siempre por `user_id` del token autenticado, sin excepción de rol (a diferencia de la web, donde RH sí ve las de todos). Módulos de RH (reclutamiento, candidatos, vacantes, reportes, plantillas, expedientes, documentos) **no tienen API** todavía — quedan solo en web (ver sección 19 del encargo).

## JSON Resources

`App\Http\Resources\Api\V1\SolicitudInternaResource` / `SolicitudVacacionesResource` — transforman el modelo a JSON explícito (nunca se exponen columnas crudas como `disk`/`path`). Los endpoints de perfil/dashboard/notificaciones devuelven directamente el array que ya arma el Service correspondiente (ya es una forma segura y mínima, no hace falta una Resource adicional encima).

## Probar la API

```bash
# Login
curl -X POST http://localhost:8000/api/v1/login \
  -H "Content-Type: application/json" \
  -d '{"email":"colaborador1@mrlana.test","password":"Capacitacion2026!","device_name":"curl"}'

# Con el token devuelto:
curl http://localhost:8000/api/v1/colaborador/dashboard \
  -H "Authorization: Bearer <token>"
```

Tests: `tests/Feature/Api/AuthApiTest.php`, `tests/Feature/Api/ColaboradorApiTest.php`.

## Pendiente

- Endpoints de incapacidades/permisos como recursos dedicados (hoy se consultan a través de `/solicitudes` filtrando por `tipo`, no hay un endpoint separado — no hacía falta duplicar la ruta).
- Rate limiting específico de la API (hoy usa el throttle por defecto de Laravel).
- `colaborador/perfil.foto_url` apunta a una ruta protegida por **sesión web** (`rh.expedientes.foto`), no por token Sanctum: un cliente 100% nativo (sin cookies de sesión) no podrá cargarla directamente. Para la app móvil real, esto necesitará una URL firmada de corta duración (`URL::temporarySignedRoute`, mismo patrón que ya usa la biblioteca multimedia — ver `docs/SEGURIDAD.md`) o servir la imagen en base64 dentro del propio JSON. Se dejó documentado en vez de resuelto a medias.
