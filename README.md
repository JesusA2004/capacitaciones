# Portal de Capacitación Mr. Lana

Portal empresarial de capacitación, inducción, evaluación y seguimiento para colaboradores de Mr. Lana. Construido sobre Laravel + Inertia + Vue.

## Stack

Laravel 13 · PHP 8.3+ · Inertia.js 3 · Vue 3 (Composition API) + TypeScript · Tailwind CSS 4 · shadcn-vue (`reka-ui`) · Spatie Laravel Permission · Spatie Laravel Activitylog · hls.js · FFmpeg/FFprobe · Laravel Excel · Laravel Dompdf · date-fns · Pest 4 · MariaDB/MySQL.

Ver `docs/ARQUITECTURA.md` para el detalle de la organización del código y `docs/PLAN_IMPLEMENTACION.md` para el estado de cada fase del proyecto.

## Requisitos

- PHP 8.3+
- Composer
- Node.js 20+ y npm
- MariaDB o MySQL (en desarrollo se usa el de WAMP)
- FFmpeg/FFprobe (solo para procesar videos de la biblioteca multimedia; ver `docs/PROCESAMIENTO_VIDEO.md` y `docs/CONFIGURACION_NAS.md`)

## Instalación

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Configura en `.env` la conexión a tu base de datos MariaDB/MySQL (ver variables `DB_*`). Crea la base de datos antes de migrar:

```sql
CREATE DATABASE capacitaciones CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php artisan migrate --seed
```

## Desarrollo

```bash
composer dev
```

Levanta en paralelo el servidor de Laravel, el worker de colas (`queue:listen`) y Vite. La app queda disponible en `http://localhost:8000`.

## Usuarios de desarrollo (seeder)

`database/seeders/UsuarioDemoSeeder.php` crea un colaborador por cada rol del sistema. **Contraseña de todos ellos, exclusiva para desarrollo: `Capacitacion2026!`** (nunca usar en producción).

| Correo | Rol |
|---|---|
| superadmin@mrlana.test | super_admin |
| admin.capacitacion@mrlana.test | administrador_capacitacion |
| instructor@mrlana.test | instructor |
| gerente.sucursal@mrlana.test | gerente_sucursal |
| supervisor@mrlana.test | supervisor |
| colaborador1@mrlana.test / colaborador2@mrlana.test | colaborador |
| auditor@mrlana.test | auditor |

`database/seeders/CursoInduccionSeeder.php` crea además un curso de inducción de ejemplo publicado, con módulos y lecciones (texto, video/documento simulados y confirmación de lectura).

## Comandos habituales

```bash
php artisan test           # Pruebas (Pest)
composer types:check       # PHPStan / Larastan (nivel 7)
composer lint:check        # Pint (estilo PHP)
npm run lint:check         # ESLint
npm run format:check       # Prettier
npm run types:check        # vue-tsc
npm run build               # Build de producción del frontend
```

`composer ci:check` corre lint, format, types y pruebas en una sola invocación.

Después de crear o modificar rutas/controladores, regenera los helpers tipados de Wayfinder si no tienes `composer dev` corriendo. **Usa siempre `--with-form`**: sin esa bandera se regeneran todos los helpers sin las variantes `.form()` que usa el componente `<Form>` de Inertia, rompiendo páginas existentes.

```bash
php artisan wayfinder:generate --with-form
```

## Tareas programadas (recordatorios)

`routes/console.php` registra el scheduler de recordatorios automáticos (fechas límite por vencer, sesiones en vivo próximas, calificaciones pendientes). En desarrollo se pueden ejecutar manualmente:

```bash
php artisan capacitacion:recordar-fechas-limite
php artisan capacitacion:recordar-sesiones-proximas
php artisan capacitacion:recordar-calificaciones-pendientes
```

En producción, agrega la entrada de cron estándar de Laravel apuntando a `schedule:run` cada minuto. El correo saliente usa `MAIL_MAILER` (`log` por defecto en desarrollo, sin enviar correos reales).

## Documentación

- `docs/ARQUITECTURA.md` — organización del backend y frontend, convenciones.
- `docs/MODELO_DATOS.md` — esquema de base de datos por fase.
- `docs/PLAN_IMPLEMENTACION.md` — bitácora de avance por fase, decisiones tomadas.
- `docs/CONFIGURACION_NAS.md` — disco de almacenamiento multimedia (local vs. SFTP).
- `docs/PROCESAMIENTO_VIDEO.md` — pipeline de FFmpeg/HLS y control de avance del reproductor.
- `docs/SESIONES_EN_VIVO.md` — proveedor manual y configuración de las integraciones con Google Meet y Zoom.
- `docs/SEGURIDAD.md` — autenticación, autorización, aislamiento por sucursal, y checklist de despliegue a producción.

## Notas de seguridad

- Los colaboradores no se auto-registran: los crea un administrador desde **Administración → Colaboradores**, y el sistema les envía un correo para establecer su propia contraseña (nunca se genera ni se envía una contraseña en texto plano).
- La autorización de cada acción vive en el backend (Policies + permisos de Spatie), no solo en la interfaz.
- Ver `docs/SEGURIDAD.md` para el detalle completo y el checklist antes de desplegar a producción (`APP_DEBUG=false`, caché de config/rutas/vistas, HTTPS, colas, scheduler, correo real).
