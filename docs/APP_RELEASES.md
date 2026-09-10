# Descarga de app / APK

Mientras MR. LANA PEOPLE no esté en Google Play, los colaboradores la descargan
directo del sitio. Tabla `mobile_app_releases` — ver migraciones
`2026_09_10_090102_create_mobile_app_releases_table` y
`2026_09_10_090103_add_ios_urls_to_mobile_app_releases_table`.

## Páginas públicas (sin sesión)

```
GET /app             Descarga MR. LANA PEOPLE (version actual, notas, botón de descarga)
GET /app/versiones    Historial de versiones publicadas
GET /app/descargar     Atajo humano: redirige a /app/descargar/android
GET /app/descargar/{platform}   Descarga real (streaming, nunca expone file_path)
```

Si no hay ninguna versión Android publicada, `/app` muestra "La descarga aún no está
disponible. Recursos Humanos te avisará cuando esté lista." — sin botón falso. Solo
Android tiene archivo real por ahora; `/app/descargar/ios` responde `404` controlado
(ver sección iOS más abajo).

Con `?token=...&from=qr` (llegando desde `/incorporacion/qr/{token}`, ver
`docs/API_MOVIL.md` "Registro por QR temporal"), la página conserva el token en la URL
(nunca en sesión) y, tras el botón de descarga, muestra los pasos "instala → vuelve al
QR → continuar en la app" con un botón que abre el deep link
`mrlanapeople://incorporacion/qr/{token}`. Abrir `/app` nunca marca el QR como usado —
eso solo ocurre en `POST /api/v1/incorporacion/invitaciones/{token}/registrar`.

## Panel admin/RH

```
GET    /administracion/app-versiones                       (permiso app_releases.ver)
POST   /administracion/app-versiones                        (app_releases.crear)
GET    /administracion/app-versiones/{release}               (app_releases.ver)
GET    /administracion/app-versiones/{release}/descargar      (app_releases.descargar)
POST   /administracion/app-versiones/{release}/publicar       (app_releases.publicar)
POST   /administracion/app-versiones/{release}/despublicar    (app_releases.publicar)
DELETE /administracion/app-versiones/{release}                (app_releases.eliminar)
```

URL en español (`app-versiones`, como en el encargo); nombre de ruta en inglés
(`administracion.app-releases.*`) para que coincida con el modelo `MobileAppRelease` y
los servicios `App\Services\AppReleases\*`.

Reglas:

- Solo `platform=android` acepta subida real hoy; `.apk` validado por extensión
  (`mimes:` no reconoce `.apk`) y tamaño máximo `config('mobile_releases.max_upload_mb')`
  (`MOBILE_APK_MAX_MB`, 250 MB por defecto).
- El archivo se guarda en el disco `nas` (mismo disco que expedientes/reclutamiento/
  multimedia), nunca en `public/`. `MobileAppRelease::$hidden = ['file_path']`: ni
  Inertia ni la API exponen la ruta física — la descarga siempre pasa por
  `App\Services\AppReleases\AppReleaseStorageService::respuesta()`.
- Al subir se calcula `sha256` (mismo patrón que `DocumentoStorageService::hashSha256`).
- **Publicar** (`AppReleaseService::publicar()`) marca `is_published=true`,
  `is_latest=true` y desmarca `is_latest` de cualquier otra versión de la misma
  plataforma (transacción, nunca dos "latest" publicadas a la vez).
- **Despublicar** quita `is_published` e `is_latest`.
- Un admin con `app_releases.descargar` puede descargar una versión **no publicada**
  (para probarla); el público solo descarga la publicada+latest, vía `/app/descargar/*`.

## API para la app móvil

```
GET /api/v1/app/releases/latest?platform=android|ios   (público, sin auth:sanctum)
GET /api/v1/app/releases?platform=android|ios            (público)
```

```json
{
  "data": {
    "platform": "android",
    "version": "1.0.0",
    "build_number": "1",
    "download_url": "https://people.mr-lana.com/app/descargar/android",
    "install_url": null,
    "store_url": null,
    "file_size": 123456,
    "sha256": "...",
    "changelog": "...",
    "minimum_required": false,
    "published_at": "..."
  }
}
```

Sin versión publicada para esa plataforma: `{"data": null}` con `404`.

### iOS (futuro)

`mobile_app_releases.platform` ya admite `ios` (enum `App\Enums\PlataformaApp`); solo
no hay subida de archivo real todavía (no hay `.ipa` que distribuir directo). Cuando
exista distribución real (TestFlight/App Store):

1. RH crea el release con `platform=ios` y llena `install_url` (TestFlight) y/o
   `store_url` (App Store) — columnas ya migradas, sin archivo (`file_path` null).
2. `download_url` queda `null` para esa versión (no hay APK/IPA que servir); la app
   usa `install_url`/`store_url` en su lugar. Nunca hardcodeado en la app: siempre sale
   de `GET /api/v1/app/releases/latest?platform=ios`.
3. `GET /api/v1/app/config` también expone `ios.install_url`/`ios.store_url` (mismo
   criterio: null hasta que exista una versión publicada con esos campos).

## `GET /api/v1/app/config`

Además de las claves ya documentadas en `docs/API_MOVIL.md` (`maintenance`,
`minimum_version`, `latest_version`, `force_update`, `features`, conservadas por
compatibilidad), ahora expone:

```json
{
  "minimum_android_version": "1.0.0",
  "minimum_android_build": null,
  "minimum_ios_version": null,
  "minimum_ios_build": null,
  "download_url": "https://people.mr-lana.com/app/descargar/android",
  "update_url": "https://people.mr-lana.com/app",
  "ios": { "install_url": null, "store_url": null }
}
```

`minimum_android_version` cae a `APP_MOBILE_MIN_VERSION` si `APP_MOBILE_MIN_ANDROID_VERSION`
no está definida (compatibilidad). `download_url` sale de la versión Android
publicada+latest real (`AppReleaseService::latestPublicada()`), nunca de config estática.

## Login y enlaces

El login (`resources/js/pages/auth/Login.vue`) muestra un bloque "También puedes usar
MR. LANA PEOPLE desde tu celular" con botón "Descargar app" hacia `/app`. La pantalla
del QR (`docs/API_MOVIL.md`) enlaza a `/app?from=qr&token=...`.

## Permisos

`app_releases.ver`, `app_releases.crear`, `app_releases.publicar`,
`app_releases.eliminar`, `app_releases.descargar` (`RolesYPermisosSeeder`).
`super_admin`/`rh_admin`: todos. `rh_auxiliar`: solo `app_releases.ver`.

## Configuración

`config/mobile_releases.php` — variables `.env`:

```
MOBILE_APP_DOWNLOAD_ENABLED=true
MOBILE_APK_MAX_MB=250
```

`MOBILE_APP_DOWNLOAD_ENABLED=false` apaga `/app`, `/app/versiones` y la descarga
pública (`/app/descargar/*` responde `404` controlado) sin borrar versiones ya subidas.

## Nginx: /app colisiona con el WebSocket de Reverb

El prefijo público `/app` (esta página) y el protocolo WebSocket de Reverb (compatible
con Pusher, `/app/{REVERB_APP_KEY}`, ver `docs/PUSH_NOTIFICATIONS.md`) comparten el
mismo segmento de URL por coincidencia de nombres. `deploy/nginx/reverb.conf` ya trae
los `location =` exactos necesarios para que `/app`, `/app/descargar` y
`/app/versiones` se sirvan por Laravel y no se los trague el `proxy_pass` a Reverb — si
se reescribe ese archivo o se arma el vhost a mano, hay que conservar esos bloques
**antes** del `location /app/` genérico (aunque el orden en sí no importa: un match
exacto siempre gana sobre un prefijo en Nginx).

## Probar manualmente

```bash
# Subir y publicar un APK de prueba desde /administracion/app-versiones (rh_admin)
# Luego:
curl -I http://localhost/app/descargar/android   # 200, Content-Disposition con la version
curl http://localhost/api/v1/app/releases/latest?platform=android
curl http://localhost/api/v1/app/releases/latest?platform=ios   # 404, data: null
```

## Tests

`tests/Feature/Administracion/AppReleasesTest.php` (subida, permisos, publicar/
despublicar, descarga pública, no exposición de `file_path`, eliminación) y
`tests/Feature/Api/AppReleaseApiTest.php` (latest, config).
