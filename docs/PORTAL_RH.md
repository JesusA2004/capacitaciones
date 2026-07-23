# Portal Integral de Colaboradores y RH — Mr. Lana

El sistema deja de presentarse principalmente como "Portal de Capacitación" y pasa a ser el **Portal Integral de Colaboradores y Recursos Humanos Mr. Lana**: un lugar centralizado para expedientes, documentos laborales, altas de nuevo ingreso, vacaciones, solicitudes internas y reportes de RH, con capacitación como fase futura (conservada por completo, ver `docs/CAPACITACION_PROXIMAMENTE.md`).

Este documento describe el checkpoint 1 (identidad, navegación y feature flags) y el roadmap completo. Para el mecanismo de ocultar/mostrar módulos, ver `docs/FEATURE_FLAGS.md`.

## Objetivo

Una experiencia parecida a un explorador de archivos moderno:

```
Empresa → Sucursal → Colaborador → Expediente (Documentos / Vacaciones / Solicitudes / Historial)
```

Simple, visual, sin demasiados módulos sueltos.

## Checkpoint 1 — qué cambió

- **Feature flags**: `config/features.php` (`rh_portal`, `capacitacion`), variables `RH_PORTAL_ENABLED` y `CAPACITACION_ENABLED` en `.env`/`.env.example`.
- **Middleware `feature:capacitacion`** (`App\Http\Middleware\EnsureFeatureEnabled`) protegiendo cursos, mi-capacitación, multimedia, cuestionarios, actividades, sesiones/asistencias, reportes de cumplimiento, asignaciones y calendario.
- **Identidad visual**: `APP_NAME` → "Portal RH Mr. Lana", subtítulo del logo → "Portal de Colaboradores y RH" (`resources/js/components/AppLogo.vue`), landing pública (`Welcome.vue`) ya no habla de cursos.
- **Navegación simplificada** (`resources/js/components/AppSidebar.vue`):
  - Inicio.
  - Capacitación — badge "Próximamente" (visible a todos los usuarios autenticados).
  - Administración: Colaboradores, Sucursales, Departamentos, Puestos, Roles y permisos, y Planeación RH (solo `super_admin`).
- **Pantalla "Capacitación y Desempeño" (Próximamente)**: `resources/js/pages/Capacitacion/Proximamente.vue`, ruta `capacitacion.proximamente` (`/capacitacion`).
- **Página `/planeacion-rh`**: `resources/js/pages/PlaneacionRh/Index.vue`, solo accesible por el rol `super_admin` (middleware `role:super_admin`, requiere el alias de Spatie registrado en `bootstrap/app.php`). Presenta el objetivo, la estructura empresa → sucursal → colaborador, los pilares del portal y el roadmap.

## Checkpoints 2–4 — multiempresa, roles RH, dashboard RH, expedientes, documentos NAS

Segunda tanda de trabajo, ver el detalle en cada doc dedicado:

- **Multiempresa** — `docs/MULTIEMPRESA.md`. Modelo `Empresa`, `sucursales.empresa_id`, CRUD en Administración → Empresas.
- **Roles y permisos RH** — `docs/ROLES_PERMISOS_RH.md`. Catálogo completo de permisos nuevos, roles `rh_admin`/`rh_auxiliar`/`jefe_directo`, y el tercer nivel de alcance ("subordinados directos") en `AlcanceOrganizacionalService`.
- **Dashboard RH** — `App\Services\Reportes\MetricasRhDashboardService` reemplazó el contenido de `Dashboard/Global.vue`, `Dashboard/Sucursal.vue` y `Dashboard/Colaborador.vue` (mismos nombres de componente/ruta, contenido nuevo). Ya **no** muestra cumplimiento de capacitación; muestra colaboradores activos/bajas del mes, expedientes completos/incompletos, documentos pendientes, próximos aniversarios y alertas RH. `App\Services\Reportes\MetricasDashboardService` (el de capacitación) se dejó intacta pero sin usar — no se eliminó, ver `docs/CAPACITACION_PROXIMAMENTE.md`.
- **Expedientes tipo carpetas** — `docs/EXPEDIENTES_DIGITALES.md`. Explorador visual, expediente individual con pestañas, datos personales editables.
- **Documentos en Synology** — `docs/SYNOLOGY_STORAGE.md`. `document_types`/`employee_documents`, `DocumentoStorageService`, flujo de aprobar/rechazar/pedir corrección.

### Lo que NO se hizo todavía (queda para checkpoints siguientes)

- Alta digital con liga segura (checkpoint 5).
- Avisos de privacidad y consentimientos (checkpoint 6).
- Vacaciones (checkpoint 7) — el catálogo de permisos ya existe, pero no hay tablas `vacation_*` ni pantallas.
- Solicitudes RH generales (checkpoint 8) — mismo caso: permisos `solicitudes.*` sembrados, sin tablas ni pantallas todavía.
- Reportes RH dedicados en `/rh/reportes` (checkpoint 9) — el dashboard ya muestra varias de estas métricas, pero no hay una pantalla de reportes con filtros/exportación aparte.
- Contrato, Historial RH y Bitácora dentro del expediente individual: pestañas presentes en la UI mostrando "Próximamente", sin datos reales todavía.

## Roadmap completo

| Fase | Contenido | Estado |
|---|---|---|
| **Fase 1** — Portal RH base | Empresas, sucursales, colaboradores, dashboard RH, expedientes | **Completa** |
| **Fase 2** — Documentos y alta digital | Synology, documentos, versiones (✅ completos), avisos, consentimientos, alta con liga (pendientes) | En curso |
| **Fase 3** — Vacaciones y solicitudes | Saldos, solicitudes, aprobación, calendario | Planeada |
| **Fase 4** — Reportes y seguimiento | Reportes RH, historial laboral, exportaciones | Planeada |
| **Fase 5** — Capacitación y desempeño | Cursos, videos, evaluaciones, indicadores (ya construido, oculto) | Planeada / conservada |

## Stack

Sin cambios respecto a `docs/ARQUITECTURA.md`: Laravel + Inertia + Vue 3 + TypeScript + Tailwind + shadcn-vue/reka-ui + Lucide + Unovis + MariaDB/MySQL. El almacenamiento pesado en Synology NAS (disco `nas`, ver `docs/CONFIGURACION_NAS.md`) ya se reutiliza para documentos de expediente (`docs/SYNOLOGY_STORAGE.md`) y se reutilizará también para las evidencias de consentimientos en la Fase 2.

Componente UI nuevo: `resources/js/components/ui/tabs/` (Tabs de shadcn-vue sobre primitivas de `reka-ui`, no existía en el proyecto antes de este checkpoint).
