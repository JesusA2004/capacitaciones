# PEOPLE como app instalable (PWA)

PEOPLE se puede **instalar desde el navegador** (Android, iPhone/iPad, Windows, macOS) y se abre como app, a pantalla completa, desde el ícono de inicio. **No reemplaza al APK nativo de Android**: lo complementa, sobre todo para iPhone/Mac (donde el APK no existe) y para quien prefiere no instalar nada. Dentro es exactamente el mismo sitio web: el colaborador hace todo lo que hace en el navegador (subir documentos con la cámara o desde archivos, solicitudes, carta compromiso, firmas…) y el personal administrativo, su operación diaria.

## Piezas

| Archivo | Para qué |
|---|---|
| `public/manifest.webmanifest` | Nombre «MR. LANA PEOPLE», `short_name` «PEOPLE», `start_url` `/dashboard`, `display: standalone`, colores crema (solo tema claro) e íconos 192/512 (normales y *maskable*). |
| `public/branding/icono-*.png`, `icono-maskable-*.png`, `apple-touch-icon-pwa.png` | Íconos sacados del logotipo oficial (`MrLanaPeople_icono_app.svg`); los *maskable* y el de iPhone van a sangre (sin esquinas transparentes). |
| `resources/views/app.blade.php` | `<link rel="manifest">`, etiquetas `apple-mobile-web-app-*` e ícono de iPhone. |
| `public/sw.js` | Service Worker único: **Web Push** (sin cambios) + caché mínima (abajo). |
| `public/offline.html` | Página «Sin conexión a internet» (autocontenida) que se muestra si se abre PEOPLE sin internet. |
| `resources/js/composables/useInstalarApp.ts`, `components/BannerInstalarApp.vue` | Franja «Instala PEOPLE como app» (Android/Chrome/Edge: botón «Instalar»; iPhone: instrucciones «Compartir › Agregar a inicio»). No aparece si ya está instalada; «Ahora no» la oculta 30 días; espera a que no esté la franja de notificaciones. |

## Qué guarda en caché el Service Worker (y qué no)

- **Nunca** intercepta: peticiones que no son GET (subidas de documentos, formularios, cierre de sesión), visitas de Inertia (encabezado `X-Inertia`), `/api/*` (app nativa e integraciones), `/broadcasting`, `/sanctum`, `/logout`, ni nada de otro origen (Vite en desarrollo).
- `/build/assets/*` (archivos con hash de Vite): caché primero; máximo 150 archivos.
- Páginas (navegación): **siempre a la red**; solo si no hay conexión se muestra `offline.html`. Ninguna página con sesión se guarda, así que en un equipo compartido nadie ve datos de otra persona.
- Archivos del expediente, PDFs, descargas: directo a la red, sin intervención.
- Al cambiar la estrategia, sube `VERSION` en `public/sw.js`: al activarse borra las cachés anteriores (`people-*`).

El SW se registra para toda persona al cargar PEOPLE (`registrarServiceWorker()` en `resources/js/app.ts`), active o no las notificaciones; también en iPhone sin Web Push.

## Qué revisar en el VPS (una sola vez)

1. **HTTPS obligatorio** (ya lo está `people.mr-lana.com`): sin HTTPS no hay Service Worker ni instalación.
2. **Tipo del manifiesto**: debe responder `Content-Type: application/manifest+json`. Nginx reciente ya lo trae en `mime.types`; si no, agrega dentro del `server{}`:

   ```nginx
   location = /manifest.webmanifest {
       default_type application/manifest+json;
       add_header Cache-Control "no-cache";
       try_files $uri =404;
   }
   ```

3. **`sw.js` sin caché larga** (si se cachea, los cambios tardan en llegar a los usuarios):

   ```nginx
   location = /sw.js {
       add_header Cache-Control "no-cache, no-store, must-revalidate";
       try_files $uri =404;
   }
   location = /offline.html {
       add_header Cache-Control "no-cache";
       try_files $uri =404;
   }
   ```

   Si el sitio ya tiene un bloque genérico `location ~* \.(js|css|png…)$` con `expires 1y`, estas reglas exactas (`location =`) tienen prioridad; colócalas en el mismo `server{}`.

4. Recargar y comprobar:

   ```bash
   sudo nginx -t && sudo systemctl reload nginx
   curl -sI https://people.mr-lana.com/manifest.webmanifest | grep -i content-type   # application/manifest+json
   curl -sI https://people.mr-lana.com/sw.js | grep -i cache-control                  # no-cache…
   ```

5. Después de desplegar, en Chrome de escritorio: DevTools › Application › Manifest (sin errores, «Installable») y Service Workers (`/sw.js` activo).

## Cómo se instala (para comunicar al personal)

- **Android (Chrome)**: aparece la franja «Instala PEOPLE como app» › «Instalar»; o menú ⋮ › «Instalar app». Quien ya tiene el APK nativo puede seguir usándolo.
- **iPhone/iPad (Safari)**: botón Compartir › «Agregar a inicio». Las notificaciones push en iPhone solo funcionan con PEOPLE instalada así (iOS 16.4 o superior).
- **Windows/macOS (Chrome/Edge)**: ícono de instalar en la barra de direcciones o la franja «Instalar».

## Subir documentos desde el celular

Las zonas de carga (`components/people/PeopleFileDropzone.vue`, `components/Rh/DocumentoDropzone.vue`) agregan los tipos MIME a `accept` (`lib/archivos.ts` → `aceptarEnCelular`): Android ofrece **cámara, galería y archivos**, y el iPhone convierte la foto HEIC a JPG (formato que acepta el servidor). No se fuerza `capture`, así que también se pueden elegir PDFs. En pantallas táctiles el texto dice «toca para tomar una foto o elegir un archivo», los botones son más grandes y el PDF elegido se abre con «Abrir PDF para revisarlo» (los navegadores de celular no lo pintan dentro de la página).
