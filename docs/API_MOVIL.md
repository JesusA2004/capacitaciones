# API móvil (v1)

API JSON versionada para la futura app móvil/web móvil de colaboradores (proyecto aparte — aquí solo se deja la API lista, documentada y probada, ver sección 3 del encargo). Prefijo `/api/v1`, namespace `App\Http\Controllers\Api\V1`.

## Autenticación

Laravel Sanctum, **tokens personales puros** (sin cookies, sin CSRF, sin `EnsureFrontendRequestsAreStateful`): cada dispositivo hace login y recibe su propio token, independiente de la sesión web.

```
POST /api/v1/login        { email, password, device_name? }  →  { token, usuario }
POST /api/v1/logout       (Bearer token)                     →  revoca el token actual
GET  /api/v1/me           (Bearer token)                     →  usuario, roles, permisos
```

Cada request autenticado manda `Authorization: Bearer <token>`. Puede iniciar sesión un usuario `activo` o `en_incorporacion` (colaborador con cuenta que todavía está completando su expediente documental, ver sección "Incorporación documental" abajo); `inactivo`/`suspendido` no pueden. La respuesta de `/login` incluye `usuario.estatus` para que la app decida qué pantalla mostrar primero.

Un colaborador nuevo no llega a `/login` por su cuenta: primero se registra con un QR temporal de RH (`POST /api/v1/incorporacion/invitaciones/{token}/registrar`, sección "Registro por QR temporal" abajo), que ya le devuelve su propio token Sanctum sin pasar por `/login`.

## Regla de oro: mismos Services que la web

Ningún controlador de `Api\V1` calcula nada por su cuenta. Todos llaman exactamente al mismo Service que usan los controladores Inertia — ver `docs/ARQUITECTURA_SERVICES.md`:

| Endpoint | Service |
|---|---|
| `colaborador/perfil`, `colaborador/dashboard` | `App\Services\Colaboradores\ColaboradorPerfilService` |
| `colaborador/vacaciones`, `vacaciones/*` | `App\Services\Vacaciones\VacacionesService` |
| `colaborador/solicitudes`, `solicitudes/*` | `App\Services\Solicitudes\SolicitudesService` |
| `colaborador/notificaciones`, `notificaciones/*` | `App\Services\Colaboradores\NotificacionesService` |
| `colaborador/incorporacion/*`, `rh/expedientes/*` | `App\Services\Incorporacion\IncorporacionService` |

Los mismos `FormRequest` de la web (`StoreSolicitudInternaRequest`, `StoreSolicitudVacacionesRequest`) se reutilizan tal cual en la API — su `authorize()` no depende de parámetros de ruta web, así que funcionan igual en ambos contextos. `IncorporacionService` es nuevo (no existía versión web previa): centraliza las reglas de estado/transición para que el endpoint de colaborador y el de RH calculen exactamente lo mismo, y reutiliza `App\Services\Expedientes\ExpedienteService`/`DocumentoStorageService` (los mismos que usa el Portal RH web) para no duplicar el cálculo de documentos vigentes ni el guardado en el disco NAS.

## Endpoints (datos propios del colaborador)

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

## Registro por QR temporal

Un colaborador **no puede registrarse libremente** desde la app: solo puede crear su cuenta si escaneó un QR de incorporación generado antes por RH desde el Portal RH (`/rh/incorporacion/invitaciones`). Flujo completo:

1. RH genera una invitación (`POST /rh/incorporacion/invitaciones`, web): elige empresa/sucursal/departamento/puesto, datos prellenados opcionales y vigencia (24h/3 días/7 días/personalizada — `INCORPORACION_QR_TTL_HOURS` si no se especifica).
2. El sistema genera un token largo y aleatorio (`Str::random(64)`) y **solo guarda su hash** (`hash('sha256', $token)`, igual que Sanctum) en `incorporacion_invitaciones.token_hash` — el token plano nunca toca la base de datos.
3. El QR codifica la liga web universal `INCORPORACION_QR_URL_BASE/{token}` (por defecto `{APP_URL}/incorporacion/qr/{token}`, ej. `https://people.mr-lana.com/incorporacion/qr/{token}`), para que funcione aunque el celular no tenga la app instalada. RH la ve **una sola vez** (al crear o regenerar la invitación) en el Portal RH; no hay forma de recuperarla después salvo regenerar.
4. La app extrae el `{token}` de la liga escaneada y llama a la API pública (sin `auth:sanctum`, sin sesión — el token del QR es la única puerta de entrada):

```
GET  /api/v1/incorporacion/invitaciones/{token}/validar     ¿el QR sirve? + datos prellenados + fases
GET  /api/v1/incorporacion/invitaciones/{token}/fases       solo la hoja de ruta (opcional)
POST /api/v1/incorporacion/invitaciones/{token}/registrar   crea la cuenta en estatus en_incorporacion
```

5. Si el QR es válido, `POST .../registrar` crea al colaborador (`estatus = en_incorporacion`, rol `colaborador`, empresa/sucursal/departamento/puesto heredados de la invitación), marca la invitación como usada (o incrementa `usos_count` si `max_usos > 1`) y devuelve un token Sanctum — la app ya puede seguir con los endpoints normales (`/colaborador/incorporacion`, sección de abajo). El usuario **no** queda activo todavía; eso solo pasa cuando RH aprueba la incorporación completa.

`validar`/`registrar` responden con **el mismo motivo explícito** cuando el QR no sirve — nunca dejan pasar el registro:

| Motivo | HTTP |
|---|---|
| `invalido` (token que no existe) | 404 |
| `vencido` | 410 |
| `revocado` | 410 |
| `usado` (ya alcanzó `max_usos`) | 410 |
| `correo_no_coincide` (la invitación traía `email` predefinido y no coincide con el del body de `registrar`) | 422 |

```json
// GET .../validar (200)
{
  "valida": true,
  "estado": "activo",
  "expires_at": "2026-09-08T12:00:00+00:00",
  "datos_prellenados": { "nombre": "...", "email": "...", "telefono": "...", "empresa": "...", "sucursal": "...", "departamento": "...", "puesto": "..." },
  "fases": [
    { "clave": "datos_personales", "nombre": "Datos personales", "orden": 1 },
    { "clave": "documentos", "nombre": "Documentos", "orden": 2 },
    { "clave": "revision", "nombre": "Revisión RH", "orden": 3 }
  ]
}

// Cualquier motivo invalido (404/410/422)
{ "valida": false, "estado": "vencido", "message": "El código QR venció. Solicita a RH que genere uno nuevo." }
```

`POST .../registrar` body: `name`, `apellidos?`, `email`, `password`+`password_confirmation`, `telefono?`, `curp?`, `rfc?`, `nss?`, `fecha_nacimiento?`, `direccion?`, `contacto_emergencia_nombre?`, `contacto_emergencia_telefono?`. Respuesta (201):

```json
{
  "token": "...",
  "usuario": { "id": 1, "name": "...", "email": "...", "estatus": "en_incorporacion", "roles": ["colaborador"], "permisos": [] },
  "siguiente_paso": "incorporacion"
}
```

Servicio: `App\Services\Incorporacion\IncorporacionInvitacionService` (generar/hashear/validar token, marcar uso, revocar, regenerar, generar el QR en SVG/PNG con `bacon/bacon-qr-code` — ya viene con `laravel/fortify`, no es una dependencia nueva). Web RH: `App\Http\Controllers\Rh\IncorporacionInvitacionController` (`/rh/incorporacion/invitaciones`, permisos `rh.incorporacion.invitaciones.*`). No existe todavía una página pública `/incorporacion/qr/{token}` para quien escanea sin tener la app instalada — por ahora esa liga solo la consume la app móvil vía la API pública de arriba; queda pendiente si se necesita un landing de "instala la app" (fuera de alcance de este encargo).

## Incorporación documental (colaborador)

Un colaborador puede tener cuenta (y token de la app) **antes** de estar activo: mientras RH revisa su expediente, `estatus = en_incorporacion`. La app **nunca muestra el expediente completo** a este colaborador — solo el estado de cada documento requerido y qué acción puede tomar. Permiso: `colaborador.incorporacion.*` (rol `colaborador`).

```
GET  /api/v1/colaborador/incorporacion              estado general + progreso + checklist de documentos
GET  /api/v1/colaborador/incorporacion/resumen       alias del anterior
POST /api/v1/colaborador/incorporacion/documentos/{documentoRequerido}/subir             archivo (pdf/jpg/jpeg/png, máx. config('expedientes.max_upload_mb'))
POST /api/v1/colaborador/incorporacion/documentos/{documento}/solicitar-cambio           sin body; pide a RH permiso para reemplazar un documento ya subido
```

`{documentoRequerido}`/`{documento}` son el `id` del **tipo de documento** (`document_types.id`, el mismo `id` que trae cada entrada de `documentos[]` en la respuesta de arriba) — la app nunca necesita conocer el id interno de `employee_documents`.

Reglas de negocio (todas viven en `App\Services\Incorporacion\IncorporacionService`, no en el controlador):

- Un documento se puede **subir directo** (`puede_subir: true`) si nunca se subió, o si quedó `rechazado`/`requiere_correccion`/`vencido`.
- Un documento **ya subido** (`en_revision`, `cargado` o `aprobado`) no se puede reemplazar subiendo encima: hay que **solicitar cambio** (`puede_solicitar_cambio: true` en `en_revision`/`aprobado`) y esperar a que RH lo autorice (`autorizar-cambio`, ver abajo). Solo entonces `puede_reemplazar: true` (estado `cambio_autorizado`) y el endpoint de `subir` vuelve a aceptar archivo.
- Cada archivo subido queda automáticamente en `en_revision` (reutiliza `DocumentoStorageService::subirVersion`, la misma lógica de versionado que usa RH desde la web: la versión anterior se archiva).
- `estado` general de la incorporación (`incompleto | en_revision | completo | aprobado | rechazado`) se calcula sobre los documentos **obligatorios** (`document_types.requerido = true`); `aprobado`/`rechazado` son la decisión final de RH (no se puede "recalcular" de vuelta a `completo` sola: la pone/quita RH).
- `puede_acceder_portal` refleja `estatus === activo` (RH lo activa al aprobar la incorporación). `puede_subir_documentos`/`puede_solicitar_cambios` son `false` una vez que la incorporación quedó `aprobado` — de ahí en adelante el expediente se gestiona por el Portal RH normal.

Respuesta de cada documento (nunca incluye quién lo subió/revisó, comentarios de RH, ni el id interno del archivo — eso es exclusivo de la vista de RH):

```json
{
  "id": 3,
  "tipo": "ine",
  "nombre": "INE",
  "obligatorio": true,
  "estado": "pendiente",
  "mensaje": "Sube tu INE por ambos lados",
  "motivo_rechazo": null,
  "puede_subir": true,
  "puede_reemplazar": false,
  "puede_solicitar_cambio": false,
  "fecha_subida": null,
  "fecha_revision": null
}
```

`estado` es el valor crudo de `App\Enums\EstadoDocumento` (o `"pendiente"` si nunca se subió): `pendiente | cargado | en_revision | aprobado | rechazado | requiere_correccion | vencido | cambio_solicitado | cambio_autorizado`.

## Expedientes (RH, app móvil)

RH sí puede ver expedientes completos desde la app, siempre dentro de su alcance organizacional (`App\Services\AlcanceOrganizacionalService`, el mismo que usa el Portal RH web) y con los permisos `rh.expedientes.*` (roles `rh_admin` con todo, `rh_auxiliar` solo lectura: `ver`/`detalle`/`documentos.ver`, sin aprobar/rechazar/autorizar).

```
GET  /api/v1/rh/expedientes?estado=&empresa_id=&sucursal_id=&departamento_id=&busqueda=&page=
GET  /api/v1/rh/expedientes/{colaborador}
GET  /api/v1/rh/expedientes/{colaborador}/documentos/{documento}/ver
POST /api/v1/rh/expedientes/{colaborador}/documentos/{documento}/aprobar             { comentario? }
POST /api/v1/rh/expedientes/{colaborador}/documentos/{documento}/rechazar            { motivo }
POST /api/v1/rh/expedientes/{colaborador}/documentos/{documento}/autorizar-cambio
POST /api/v1/rh/expedientes/{colaborador}/aprobar-incorporacion
POST /api/v1/rh/expedientes/{colaborador}/rechazar-incorporacion                     { motivo }
```

- `estado` filtra por el mismo valor calculado que ve el colaborador (`incompleto|en_revision|completo|aprobado|rechazado`); `empresa_id`/`sucursal_id`/`departamento_id`/`busqueda` son los mismos filtros que `Rh\ExpedienteController` en la web.
- Aquí `{documento}` **sí** es el id real de `employee_documents` (route-bound a `EmployeeDocument`, no a `DocumentType`): RH ya vio ese id en el detalle del expediente (`documento_id` de cada entrada en `documentos[]`). Si el documento no pertenece al `{colaborador}` de la URL, responde 404.
- `.../ver` nunca regresa una ruta física del NAS: hace streaming del archivo directo desde Laravel (`DocumentoStorageService::respuesta`, `Content-Disposition: inline`), autenticado por el mismo Bearer token — a diferencia de `rh.expedientes.foto` (web, sesión), este endpoint sí funciona desde un cliente 100% nativo.
- `aprobar-incorporacion` responde `422` si falta algún documento obligatorio por aprobar; si todos están aprobados, activa al colaborador (`estatus = activo`) y ya puede usar el portal/app normal.
- `rechazar-incorporacion` exige `motivo` y **no** activa al colaborador — puede seguir corrigiendo documentos y RH puede volver a intentar `aprobar-incorporacion` después.

La app móvil **nunca** se conecta directo al Synology/NAS: el flujo siempre es `App móvil → API Laravel (Sanctum) → Storage/NAS` (`config('expedientes.disk')`, disco `nas` → `NAS_DRIVER`/`NAS_ROOT` en `.env`, en producción `NAS_ROOT=/mnt/people-storage`). Ningún endpoint expone `disk`/`path`/rutas SMB — ambos campos están en `$hidden` en `EmployeeDocument`.

## Qué NO expone la API

Comentarios internos de RH, quién subió/revisó cada documento, ni el id interno de `employee_documents` en la vista del **colaborador** (sí en la de RH). Sueldos y otros datos sensibles no viven en `User`/`EmployeeDocument`, así que ningún endpoint los expone. Un colaborador solo puede leer/crear lo suyo — `SolicitudInterna::show`/`index` filtran siempre por `user_id` del token autenticado, sin excepción de rol (a diferencia de la web, donde RH sí ve las de todos), y `colaborador/incorporacion` es siempre sobre el usuario autenticado (no hay forma de pedir la incorporación de otro colaborador desde esos endpoints). Reclutamiento, candidatos, vacantes, reportes y plantillas **no tienen API** todavía — quedan solo en web.

## JSON Resources

`App\Http\Resources\Api\V1\SolicitudInternaResource` / `SolicitudVacacionesResource` — transforman el modelo a JSON explícito (nunca se exponen columnas crudas como `disk`/`path`). Los endpoints de perfil/dashboard/notificaciones/incorporación/expedientes devuelven directamente el array que ya arma el Service correspondiente (ya es una forma segura y mínima, no hace falta una Resource adicional encima).

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

Tests: `tests/Feature/Api/AuthApiTest.php`, `tests/Feature/Api/ColaboradorApiTest.php`, `tests/Feature/Api/IncorporacionApiTest.php`, `tests/Feature/Api/IncorporacionInvitacionApiTest.php`, `tests/Feature/Rh/IncorporacionInvitacionTest.php`.

## Pendiente

- Endpoints de incapacidades/permisos como recursos dedicados (hoy se consultan a través de `/solicitudes` filtrando por `tipo`, no hay un endpoint separado — no hacía falta duplicar la ruta).
- Rate limiting específico de la API (hoy usa el throttle por defecto de Laravel).
- `colaborador/perfil.foto_url` apunta a una ruta protegida por **sesión web** (`rh.expedientes.foto`), no por token Sanctum: un cliente 100% nativo (sin cookies de sesión) no podrá cargarla directamente. Para la app móvil real, esto necesitará una URL firmada de corta duración (`URL::temporarySignedRoute`, mismo patrón que ya usa la biblioteca multimedia — ver `docs/SEGURIDAD.md`) o servir la imagen en base64 dentro del propio JSON. Se dejó documentado en vez de resuelto a medias.
