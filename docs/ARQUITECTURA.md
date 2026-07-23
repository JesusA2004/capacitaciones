# Arquitectura — Portal de Capacitación Mr. Lana

> A partir del checkpoint "Portal RH" el producto se presenta principalmente como Portal Integral de Colaboradores y RH; capacitación se conserva por completo pero queda oculta tras un feature flag. Ver `docs/PORTAL_RH.md` y `docs/CAPACITACION_PROXIMAMENTE.md`. Este documento describe la arquitectura tal como quedó al cierre de las Fases 1–8 originales y sigue vigente para todo lo que capacitación conserva.

Proyecto completo (Fases 1–8): portal de capacitación empresarial con cursos, biblioteca multimedia con procesamiento y streaming de video, cuestionarios/actividades, sesiones en vivo con integraciones externas, dashboards/reportes/exportaciones, notificaciones/calendario/constancias, y una auditoría final de seguridad y rendimiento. Ver `docs/PLAN_IMPLEMENTACION.md` para el detalle fase por fase.

## Stack

- **Backend**: Laravel 13, PHP 8.3+, Inertia Laravel 3, Laravel Fortify (autenticación), Spatie Laravel Permission (roles/permisos), Spatie Laravel Activitylog (auditoría), Laravel Excel (exportaciones), Laravel Dompdf (constancias en PDF), Pest 4 (pruebas), Larastan/PHPStan nivel 7, Pint (estilo).
- **Base de datos**: MariaDB/MySQL (utf8mb4). SQLite solo se usó en el arranque del starter kit; el proyecto migró a MariaDB desde la Fase 1 porque es el motor real de producción.
- **Frontend**: Vue 3 (Composition API, `<script setup>`), TypeScript estricto, Inertia Vue 3, Tailwind CSS 4, componentes shadcn-vue sobre `reka-ui`, Lucide Vue (iconos), SweetAlert2 (alertas), hls.js (reproductor de video), date-fns (calendario), Vite, Wayfinder (rutas/acciones tipadas generadas automáticamente desde las rutas y controladores de Laravel).
- **Multimedia**: FFmpeg/FFprobe (procesamiento y empaquetado HLS), disco `nas` configurable (local o SFTP, ver `docs/CONFIGURACION_NAS.md`).
- **Colas/scheduler**: `database` en desarrollo (`QUEUE_CONNECTION`), Redis recomendado en producción. El scheduler de Laravel (`routes/console.php`) ejecuta los recordatorios automáticos de la Fase 7; ver `docs/SEGURIDAD.md` para la entrada de cron necesaria en producción.

## Organización del backend (`app/`)

```
app/
  Enums/            Estados y tipos cerrados (EstadoUsuario, y los que se agreguen por fase)
  Http/
    Controllers/
      Administracion/   Controladores delgados: solo orquestan Form Request -> Servicio -> respuesta Inertia
      Settings/          (ya existente del starter kit)
    Requests/
      Administracion/   Validación de entrada (una request Store/Update por entidad)
    Resources/          Transformación a array cuando se necesita forma explícita (p. ej. RolResource)
  Models/              Eloquent. Relaciones y casts, sin lógica de negocio pesada
  Policies/            Autorización por modelo (auto-descubiertas por convención de nombre;
                       Role de Spatie se registra a mano en AppServiceProvider por vivir en el
                       namespace del paquete)
  Providers/
  Jobs/                Trabajo pesado en cola: MaterializarAsignacionJob (Fase 2),
                       ProcesarVideoJob (Fase 3, FFmpeg/HLS)
  Notifications/       Notificaciones (Fase 7): asignacion creada, cuestionario/actividad calificados,
                       sesion programada/proxima, fecha limite proxima, calificaciones pendientes
  Console/Commands/    Comandos de recordatorio programados via Schedule (Fase 7)
  Integrations/        Reuniones/ (Fase 5): contrato ProveedorSesionEnVivo + ManualProveedor/
                       GoogleMeetProveedor/ZoomProveedor
  Exports/             Exportaciones a Excel (Fase 6): CumplimientoExport
  Services/            Lógica de negocio reutilizable entre controladores, agrupada por dominio:
                       Asignaciones/, Capacitacion/ (constructor de cursos, progreso/bloqueo),
                       Multimedia/, Evaluacion/, Reuniones/, Reportes/, Calendario/, Certificados/.
                       AlcanceOrganizacionalService y RolPermisoService (Fase 1) quedan en la raiz de Services/
                       por ser transversales a todo el sistema, no de un dominio de negocio especifico.
```

Carpetas anunciadas en el encargo (`Actions/`, `DTOs/`, `Events/`, `Listeners/`, `Queries/`, `Support/`) no se generaron: ningún módulo llegó a necesitarlas realmente durante las 8 fases (los Jobs/Notifications/Services existentes cubrieron cada caso sin una capa adicional de indirección).

### Reglas seguidas

- **Controladores delgados**: validan (Form Request), autorizan (`$this->authorize()` / Policy), delegan al Service cuando hay lógica no trivial, y devuelven `Inertia::render()` o `redirect()`. Ningún controlador de esta fase supera ~70 líneas.
- **Autorización real en el backend**: cada acción administrativa pasa por una Policy o por `$user->can('permiso.especifico')`. Ocultar un botón en el frontend es solo UX, nunca el mecanismo de control de acceso.
- **Aislamiento organizacional centralizado**: `AlcanceOrganizacionalService` es la única fuente de verdad sobre "qué sucursales/usuarios puede ver este usuario". Tanto `UserPolicy` como `UsuarioController::index` lo usan, para que política y listado nunca diverjan.
- **Nomenclatura**: entidades de dominio en español sin acentos ni ñ (`Sucursal`, `Departamento`, `Puesto`, `EstadoUsuario`), sufijos técnicos de Laravel en inglés (`Controller`, `Request`, `Policy`, `Service`, `Seeder`, `Factory`).
- **Relaciones polimórficas para elementos asignables**: `Asignacion` usa `asignable_type`/`asignable_id` (Fase 2 solo implementa `Curso`) para que las Fases 4-5 puedan asignar lecciones, cuestionarios, actividades y sesiones en vivo sin migrar el esquema de nuevo.
- **Pluralización en español de nombres de tabla**: la pluralización automática de Eloquent falla con palabras en español (`sucursal`→`sucursals`, `leccion`→`leccions`, `asignacion`→`asignacions`). Cada vez que se crea un modelo nuevo hay que verificar `(new Modelo)->getTable()` contra el nombre real de la migración y declarar `protected $table` explícito si no coincide.

## Organización del frontend (`resources/js/`)

```
resources/js/
  actions/            Generado por Wayfinder (php artisan wayfinder:generate) — no editar a mano
  routes/             Generado por Wayfinder — no editar a mano
  components/
    ui/               Primitivas shadcn-vue (Button, Dialog, Sheet, Table, Select, ...)
    DataTable/         Tabla generica reutilizable: paginación/orden/filtrado server-side
    Common/            EmptyState y otros componentes de proposito general
    Administracion/    Formularios especificos de cada entidad (Dialog/Sheet + useForm)
  composables/         usePermisos, useAlertas, useFiltros, usePaginacion, useAppearance, ...
  layouts/             AppLayout (sidebar), AuthLayout, SettingsLayout
  pages/               Una carpeta por modulo (Administracion/, auth/, settings/); nombre de archivo
                       coincide con el componente Inertia::render() del controlador
  types/               Tipos compartidos (barril en types/index.ts)
```

### Reglas seguidas

- **Wayfinder en vez de URLs a mano**: todas las llamadas a rutas usan los helpers generados (`RolController.store.url()`, `index.url()`, etc.), nunca strings de ruta escritos a mano. Tras crear o modificar un controlador/ruta, correr `php artisan wayfinder:generate --with-form` (o tenerlo corriendo vía `composer dev`) antes de referenciarlo en Vue. **Importante**: la bandera `--with-form` es obligatoria; sin ella se regeneran todos los helpers del proyecto sin las variantes `.form()` usadas por el componente `<Form>` de Inertia, rompiendo en silencio cualquier página existente que lo use (incidente real documentado en `docs/PLAN_IMPLEMENTACION.md`, Fase 2).
- **Fetch solo para lo que Inertia no cubre**: la vista previa de asignaciones masivas no debe navegar ni reemplazar props de la página, así que usa `resources/js/lib/http.ts` (`postJson`), un cliente mínimo que lee la cookie `XSRF-TOKEN` que Laravel ya establece (sin Axios). El resto de la aplicación usa exclusivamente `router`/`useForm`/`<Form>` de Inertia.
- **Un composable por responsabilidad**: `usePermisos` (lectura de roles/permisos compartidos por Inertia), `useAlertas` (SweetAlert2 centralizado), `useFiltros`/`usePaginacion` (estado de tablas server-side). No se mezclan responsabilidades de permisos, alertas y datos dentro de un mismo composable.
- **Formularios en Dialog/Sheet reutilizando `useForm`**: para formularios con listas dinámicas (checkboxes de permisos/roles/sucursales) se usa `useForm` imperativo de Inertia en vez del componente declarativo `<Form>`, porque los checkboxes de reka-ui no garantizan serialización nativa de arreglos por `name`.
- **DataTable genérico**: acepta columnas tipadas y una `RespuestaPaginada<T>` (forma exacta del paginador de Laravel). Los slots con nombre (`#celda-<clave>`) no deben contener puntos literales (`.`) porque Vue los interpreta como modificadores de `v-slot`; para columnas de relaciones anidadas se usa una clave plana (p. ej. `departamento`, no `departamento.nombre`) y se accede a la relación dentro del propio slot.
- **Alertas centralizadas**: confirmaciones destructivas (`confirmarEliminacion`), de publicación, de asignación masiva, etc. viven únicamente en `useAlertas`. Los mensajes flash pasivos de Laravel/Inertia (`Inertia::flash('toast', ...)`) siguen usando el mecanismo existente basado en `vue-sonner` (`resources/js/lib/flashToast.ts`) para notificaciones no bloqueantes; SweetAlert2 se reserva para confirmaciones y avisos que requieren una decisión explícita del usuario.

## Identidad visual

Variables de marca centralizadas en `resources/css/app.css` (`--brand-primary`, `--brand-secondary`, `--brand-foreground`), mapeadas sobre las variables semánticas de shadcn (`--primary`, `--sidebar-primary`, `--ring`) para que todos los componentes existentes hereden la marca sin tener que tocarlos uno por uno. No hay colores hexadecimales sueltos en componentes: todo pasa por estas variables.

## Seguridad, aislamiento por sucursal y rendimiento

Ver `docs/SEGURIDAD.md` para el detalle completo (autenticación, autorización, control de avance de video, rutas públicas, auditoría, y checklist de despliegue a producción — ampliado y cerrado en la Fase 8). Resumen:

- Autorización con Policies de Laravel + permisos granulares de Spatie, editables desde la interfaz.
- Middleware `auth`/`verified` en todas las rutas autenticadas.
- Aislamiento por sucursal aplicado en policies y en el `WHERE` de las consultas (no solo en la vista).
- Contraseñas nunca se envían por correo: la creación de colaboradores reutiliza el flujo de "restablecer contraseña" de Fortify.
- Verificación explícita de relaciones padre-hijo en rutas anidadas (curso/módulo/lección, sesión/asistencia), corregida como hallazgo de la auditoría de la Fase 8.
- Índices en columnas de filtro/orden frecuentes y corrección de dos problemas de consultas N+1, también de la Fase 8.
