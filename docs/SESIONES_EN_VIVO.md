# Sesiones en vivo: proveedor manual, Google Meet y Zoom

Una lección de tipo `sesion_en_vivo` tiene exactamente una `SesionEnVivo` asociada (mismo patrón 1:1 que `RecursoMultimedia` con las lecciones de video, o `Cuestionario`/`Actividad` con las suyas). Al programarla, el sistema puede generar el enlace de la reunión automáticamente (Google Meet o Zoom) o dejar que el instructor lo escriba a mano (proveedor `manual`).

## Arquitectura

`App\Services\Reuniones\SesionEnVivoService` no conoce los detalles de cada proveedor: delega en la interfaz `App\Integrations\Reuniones\ProveedorSesionEnVivo`, con tres implementaciones intercambiables:

- `ManualProveedor` — no llama a ninguna API; el enlace ya lo escribió el instructor en el formulario.
- `GoogleMeetProveedor` — crea un evento de Google Calendar con una solicitud de conferencia de Meet.
- `ZoomProveedor` — crea una reunión vía la API de Zoom (Server-to-Server OAuth).

Si crear la reunión en el proveedor externo falla (credenciales inválidas, servicio caído, etc.), `SesionEnVivoService::crear()` captura la excepción con `report()` y la sesión se guarda igual: el instructor puede agregar un enlace manual después. Un fallo de una API externa nunca debe impedir programar la sesión.

## Proveedor manual (siempre disponible)

No requiere configuración. El instructor selecciona "Enlace manual" al programar la sesión y escribe el enlace de la reunión (Teams, Meet, Zoom, o cualquier otro creado fuera del sistema) directamente en el campo `enlace_reunion`.

## Google Meet

Se autentica con una **cuenta de servicio con delegación de dominio en todo el dominio** (domain-wide delegation), actuando como el usuario configurado en `GOOGLE_IMPERSONATED_USER` — así no hace falta un flujo interactivo de OAuth por cada instructor.

### Configuración

1. En Google Cloud Console: crear un proyecto, habilitar la API de Google Calendar, y crear una cuenta de servicio.
2. Descargar el archivo JSON de credenciales de la cuenta de servicio.
3. En el panel de administración de Google Workspace: autorizar esa cuenta de servicio para delegación de dominio con el scope `https://www.googleapis.com/auth/calendar.events`.
4. Variables de entorno:

```env
GOOGLE_MEET_ENABLED=true
GOOGLE_SERVICE_ACCOUNT_PATH=/ruta/absoluta/al/credenciales.json
GOOGLE_IMPERSONATED_USER=coordinador@mrlana.com
```

### Por qué no se usó el SDK oficial (`google/apiclient`)

Se probó instalar `google/apiclient`, el SDK oficial de Google para PHP, pero se descartó: ese paquete arrastra `google/apiclient-services`, una dependencia con clases generadas para cientos de APIs de Google (no solo Calendar), cuyo autoload de decenas de miles de archivos resultó poco práctico en este entorno de desarrollo. En su lugar, `GoogleMeetProveedor` firma el JWT de la cuenta de servicio a mano con las funciones nativas de OpenSSL de PHP (`openssl_sign`) y llama directamente al API REST de Calendar con el cliente HTTP de Laravel (`Illuminate\Support\Facades\Http`) — el mismo patrón ya usado para Zoom. El resultado es funcionalmente equivalente y no agrega una dependencia pesada.

## Zoom

Se autentica con **Server-to-Server OAuth** (la app actúa como un usuario concreto de la cuenta de Zoom, configurado en `ZOOM_HOST_EMAIL`), sin flujo interactivo de autorización por cada instructor.

### Configuración

1. En [Zoom App Marketplace](https://marketplace.zoom.us/): crear una app de tipo "Server-to-Server OAuth".
2. Anotar el Account ID, Client ID y Client Secret que Zoom genera.
3. Variables de entorno:

```env
ZOOM_ENABLED=true
ZOOM_ACCOUNT_ID=...
ZOOM_CLIENT_ID=...
ZOOM_CLIENT_SECRET=...
ZOOM_HOST_EMAIL=coordinador@mrlana.com
```

`ZoomProveedor` obtiene un token de cuenta (`grant_type=account_credentials`) y lo cachea 50 minutos (el token real dura 60) para no pedir uno nuevo en cada llamada.

## Degradación con gracia

Ambas integraciones (`estaDisponible()`) verifican que `*_ENABLED` esté en `true` **y** que las credenciales necesarias estén presentes antes de llamar a la API. Si falta cualquiera de las dos condiciones, `crearReunion()`/`cancelarReunion()` no hacen nada: la sesión se guarda o cancela igual, sin generar ni intentar cancelar un enlace externo. Esto es intencional: el proyecto no debe fallar al programar una sesión solo porque una integración externa no está configurada todavía.

## Asistencias y su corrección auditada

`Asistencia` se materializa automáticamente (estado `pendiente`) para todos los colaboradores inscritos en el curso, en cuanto se programa la sesión (`AsistenciaService::materializarParaSesion()`).

- **Marcado inicial** (de `pendiente` a `presente`/`ausente`/`tarde`): requiere el permiso operativo `sesiones.administrar`. Completa automáticamente la lección si el resultado es `presente` o `tarde` (vía `ProgresoService`, igual que video/cuestionario/actividad en fases anteriores).
- **Corrección** (cambiar una asistencia que ya tenía un estado distinto a `pendiente`): requiere el permiso adicional `asistencias.corregir` **y** un motivo obligatorio (`AsistenciaController::marcar()` rechaza la corrección sin motivo con un mensaje de error, antes de tocar la base de datos). El registro guarda quién corrigió (`corregido_por`) y por qué (`motivo_correccion`), para que quede auditable quién ajustó un registro ya cerrado y con qué justificación.

## Limitación conocida de este entorno de desarrollo

No hay credenciales reales de Google ni de Zoom configuradas aquí, así que las integraciones no se probaron contra las APIs reales. Las pruebas automatizadas (`tests/Feature/Reuniones/{GoogleMeetProveedorTest,ZoomProveedorTest}.php`) usan `Illuminate\Support\Facades\Http::fake()` para simular las respuestas de ambos proveedores y verifican que las peticiones se arman correctamente (incluyendo, para Google Meet, la firma real del JWT con una llave RSA de prueba). Antes de usar cualquiera de las dos integraciones en producción:

1. Configurar las credenciales reales según las secciones anteriores.
2. Programar una sesión de prueba con cada proveedor y confirmar que `SesionEnVivo.enlace_reunion` queda con un enlace real y funcional.
3. Cancelarla y confirmar que la reunión también se cancela/elimina en el proveedor externo.
