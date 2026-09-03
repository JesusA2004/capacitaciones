# MR. LANA PEOPLE

Portal Integral de Reclutamiento, Administración de Personal y Recursos Humanos para las empresas del grupo. Construido sobre Laravel + Inertia + Vue.

La Fase 1 (actual) cubre reclutamiento y administración de personal: multiempresa, estructura organizacional, jerarquía de puestos, vacantes, candidatos, alta digital, expedientes, documentos en NAS, plantillas/formatos, vacaciones, permisos/incapacidades/solicitudes, reportes RH y portal del colaborador. Desempeño/Nine Box (Fase 2) y Capacitación (Fase 3) quedan **ocultos como "Próximamente"**, sin borrarse, detrás de feature flags — ver `docs/ROADMAP.md` y `docs/FEATURE_FLAGS.md`.

## Stack

Laravel 13 · PHP 8.3+ · Inertia.js 3 · Vue 3 (Composition API) + TypeScript · Tailwind CSS 4 · shadcn-vue (`reka-ui`) · Spatie Laravel Permission · Spatie Laravel Activitylog · Laravel Sanctum (API móvil, ver `docs/API_MOVIL.md`) · hls.js · FFmpeg/FFprobe · Laravel Excel · Laravel Dompdf · date-fns · Pest 4 · MariaDB/MySQL.

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
| rh.admin@mrlana.test | rh_admin |
| rh.auxiliar@mrlana.test | rh_auxiliar |
| director.comercial@mrlana.test | director_comercial |
| gerente.regional@mrlana.test | gerente_regional |
| gerente@mrlana.test | gerente |
| subgerente@mrlana.test | subgerente |
| coordinadora.regional@mrlana.test | coordinadora_regional |
| coordinadora@mrlana.test | coordinadora |
| jefe.directo@mrlana.test | jefe_directo |

`database/seeders/CursoInduccionSeeder.php` crea además un curso de inducción de ejemplo publicado, con módulos y lecciones (texto, video/documento simulados y confirmación de lectura).

## Rutas principales

| Ruta | Qué es |
|---|---|
| `/rh/vacantes`, `/rh/candidatos` | Reclutamiento |
| `/rh/altas` | Altas digitales |
| `/rh/expedientes` | Explorador de expedientes por empresa/sucursal/colaborador |
| `/rh/plantillas`, `/rh/formatos` | Catálogo de plantillas DOCX y generación de formatos precargados |
| `/rh/solicitudes` | Revisión de solicitudes internas (RH/gerencia) |
| `/rh/vacaciones` | Revisión de solicitudes de vacaciones |
| `/rh/reportes` | Reportes RH (con exportación Excel/PDF) |
| `/solicitudes`, `/vacaciones` | Vista del colaborador sobre sus propias solicitudes/vacaciones |
| `/mi-portal`, `/mi-perfil` | Portal del colaborador (ver `docs/PORTAL_COLABORADOR.md`) |
| `/administracion/*` | Empresas, sucursales, departamentos, puestos, roles, colaboradores |

Los 8 listados operativos (Vacantes, Candidatos, Altas digitales, Plantillas, Formatos,
Solicitudes, Expedientes, Vacaciones) tienen filtros completos y botones "Excel"/"PDF"
que exportan respetando los filtros activos en pantalla.

## Endpoints API (`/api/v1`)

Autenticación por token Sanctum — ver `docs/API_MOVIL.md` para el detalle completo y
ejemplos de request/response. Resumen:

```
POST /api/v1/login                    { email, password } -> { token }
GET  /api/v1/me                       Usuario autenticado
GET  /api/v1/colaborador/perfil       Perfil del colaborador autenticado
GET  /api/v1/colaborador/dashboard
GET  /api/v1/vacaciones/saldo
GET  /api/v1/vacaciones/solicitudes
POST /api/v1/solicitudes
GET  /api/v1/solicitudes
GET  /api/v1/solicitudes/{solicitud}
GET  /api/v1/notificaciones
```

Reclutamiento, expedientes, plantillas/formatos y reportes RH **no tienen API** en
Fase 1 — quedan solo en la web (ver `docs/SEGURIDAD.md`).

## Dónde subir formatos

Las plantillas DOCX se suben desde **Plantillas** (`/rh/plantillas`) — deben traer
placeholders `{{...}}` del catálogo en `claude/formatos/placeholders/PLACEHOLDERS.md`.
Los documentos generados/firmados no se suben a mano al NAS: siempre a través de la UI
(`/rh/formatos`, o el botón "Generar formato"/"Subir firmado" dentro de una solicitud) —
ver `docs/PLANTILLAS_FORMATOS.md`.

## Cómo probar la Fase 1

Ver `docs/PRUEBAS_MANUALES.md` para el checklist paso a paso (filtros/exportación,
generar formato desde una solicitud, subir firmado, verificación de alcance por
sucursal).

## Fuera de alcance en este cierre de Fase 1

- Firma electrónica avanzada (la firma es física: se imprime, se firma en papel, se
  escanea y se sube).
- Detección automática de placeholders al subir una plantilla.
- Descarga de formatos generados por el propio colaborador (los ve listados en su
  solicitud, pero la descarga vive del lado de RH).
- Préstamo interno como tipo de solicitud sin reglas de negocio propias (montos, plazos).
- Desempeño/Nine Box y Capacitación (Fases 2 y 3), ocultos tras feature flag.

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

- `docs/ALCANCE_GENERAL.md` / `docs/FASE_1_MR_LANA_PEOPLE.md` — alcance y roadmap de la Fase 1 actual.
- `docs/ARQUITECTURA.md` — organización del backend y frontend, convenciones.
- `docs/MODELO_DATOS.md` — esquema de base de datos por fase.
- `docs/RECLUTAMIENTO.md`, `docs/VACANTES.md`, `docs/JERARQUIA_PUESTOS.md`, `docs/PERFILES_PUESTO.md` — módulos de reclutamiento y estructura organizacional.
- `docs/ALTA_DIGITAL_COLABORADOR.md`, `docs/ONBOARDING_ADMINISTRATIVO.md` — alta digital y checklist de incorporación.
- `docs/EXPEDIENTES_DIGITALES.md`, `docs/SYNOLOGY_STORAGE.md`, `docs/PLANTILLAS_FORMATOS.md` — expediente, documentos y formatos precargados.
- `docs/VACACIONES.md`, `docs/SOLICITUDES_INTERNAS.md`, `docs/REPORTES_RH.md` — procesos de RH del día a día.
- `docs/ARQUITECTURA_SERVICES.md` — por qué la lógica vive en Services y cómo se comparte entre la web (Inertia) y la API móvil.
- `docs/API_MOVIL.md` — API JSON versionada (`/api/v1`) para la futura app móvil de colaboradores: autenticación por token (Sanctum), endpoints, cómo probarla.
- `docs/PORTAL_COLABORADOR.md` — portal limitado del colaborador (`/mi-portal`), qué ve y qué no ve en Fase 1.
- `docs/ROLES_PERMISOS_RH.md`, `docs/LIMITACIONES.md`, `docs/ROADMAP.md` — gobierno del sistema y alcance/fuera de alcance.
- `docs/CONFIGURACION_NAS.md` — disco de almacenamiento (local vs. SFTP), compartido con el módulo de capacitación (oculto).
- `docs/SEGURIDAD.md` — autenticación, autorización, aislamiento por sucursal, y checklist de despliegue a producción.
- `docs/PRUEBAS_MANUALES.md` — checklist de pruebas manuales del cierre de Fase 1.

Documentación heredada del módulo de capacitación (oculto tras feature flag, no eliminado): `docs/PLAN_IMPLEMENTACION.md`, `docs/PROCESAMIENTO_VIDEO.md`, `docs/SESIONES_EN_VIVO.md`, `docs/CAPACITACION_PROXIMAMENTE.md`.

## Notas de seguridad

- Los colaboradores no se auto-registran: los crea un administrador desde **Administración → Colaboradores**, y el sistema les envía un correo para establecer su propia contraseña (nunca se genera ni se envía una contraseña en texto plano).
- La autorización de cada acción vive en el backend (Policies + permisos de Spatie), no solo en la interfaz.
- Ver `docs/SEGURIDAD.md` para el detalle completo y el checklist antes de desplegar a producción (`APP_DEBUG=false`, caché de config/rutas/vistas, HTTPS, colas, scheduler, correo real).
