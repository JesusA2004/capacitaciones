# Roles y navegación

MR. LANA PEOPLE separa dos experiencias completamente distintas dentro del mismo sistema — ver `App\Services\Navigation\NavigationService`:

- **Modo colaborador**: el portal personal de cualquier empleado (mi portal, mis solicitudes, mi expediente, mis notificaciones, mi perfil).
- **Modo operativo**: las herramientas de RH/gerencia/dirección para operar el sistema (dashboard, colaboradores, solicitudes por revisar, vacantes, candidatos, organigrama, formatos, reportes, cumpleaños, administración).

Un usuario **nunca** ve ambos mezclados en el mismo menú. El gate no depende del nombre del rol, depende de permisos:

- Tiene modo colaborador si tiene el permiso `portal.ver`.
- Tiene modo operativo si tiene `dashboard.global.ver` o `dashboard.sucursal.ver`.

El rol `colaborador` solo tiene `portal.ver` (y sus permisos personales `portal.*`) — nunca `dashboard.*.ver`. Todos los demás roles (rh_admin, gerente, auditor, etc.) tienen algún `dashboard.*.ver` y **no** tienen `portal.ver`, así que solo ven el modo operativo. Ningún rol sembrado hoy tiene ambos modos, pero el mecanismo ya soporta esa combinación (por ejemplo, un futuro rol "gerente que también es colaborador de otra área").

## Selector "Mi espacio" / "Operación RH"

Solo aparece cuando `NavigationService::modosDisponibles()` devuelve los dos modos. Vive en `AppSidebar.vue`, se controla con `useNavegacion()` (`resources/js/composables/useNavegacion.ts`) y el modo elegido se guarda en la cookie `experiencia_modo` (no en base de datos — es una preferencia de navegador, no un dato del usuario). Al cambiar de modo se hace un `POST /modo-navegacion` (`App\Http\Controllers\NavigationModeController`) que valida que el modo pedido esté entre los disponibles (403 si no) y redirige a `dashboard` u `portal.index` según corresponda.

Sin cookie y con ambos modos disponibles, el sistema prioriza **operativo** por default (es la herramienta de trabajo principal de quien administra el sistema).

## Qué se ve en cada modo

**Colaborador**: Mi portal, Mis solicitudes, Mi expediente (si tiene `expedientes.ver`), Mis notificaciones, Mi perfil, Capacitación (si el feature flag está activo).

**Operativo**: Inicio (dashboard), Expedientes (listado completo, solo con `expedientes.ver_todos`/`ver_sucursal`), Solicitudes (bandeja de revisión, con `solicitudes.revisar`/`aprobar`), Organigrama (con `organigrama.ver`), Vacantes, Candidatos, Altas digitales, Invitaciones QR, Formatos, Plantillas avanzadas, Reportes, Cumpleaños, Capacitación (si aplica) — y, aparte, un grupo "Administración" (Empresas, Usuarios, Sucursales, Departamentos, Puestos, Roles y permisos, Versiones de app).

## Expedientes vs. Usuarios vs. Roles y permisos

Tres conceptos relacionados pero distintos, cada uno con su propia pantalla:

- **Expedientes** (`rh.expedientes`): el colaborador/persona — datos laborales, sucursal, departamento, puesto, IMSS, historial. Un colaborador puede existir sin cuenta de acceso.
- **Usuarios** (`administracion.usuarios`, permiso `usuarios.ver`): listado global de CUENTAS de acceso (correo, roles asignados, estado de acceso, correo verificado, 2FA, último acceso) — `App\Http\Controllers\Administracion\UsuarioController::index()`. No administra datos laborales ni crea colaboradores.
- **Roles y permisos** (`administracion.roles`): el catálogo/configuración de roles en sí, no las cuentas que los tienen.

La cuenta de acceso de un colaborador puede administrarse desde cualquiera de los dos lugares con datos de esa cuenta — la pestaña «Cuenta» del expediente (contextual, cuando ya estás viendo a ese colaborador) o el listado global de Usuarios (para gestionar cuentas sin pasar por un expediente primero) — ambos llaman a los mismos endpoints de `UsuarioController`, así que nunca quedan desincronizados.

Lo que **ya no existe** como entrada de menú (aunque las rutas sigan vivas por compatibilidad): "Vacaciones" y "Vacaciones (revisión)" como módulos aparte (vacaciones vive dentro de Solicitudes), "Reclutamiento" (se resume en Vacantes), "Reportes RH" duplicado (queda solo "Reportes"), y "Jerarquía de puestos" como texto visible (la pantalla y el menú dicen "Organigrama" — ver `docs/ORGANIGRAMA.md`).

## Permisos personales vs operativos

Los permisos `portal.*` (`portal.ver`, `portal.perfil.ver`, `portal.solicitudes.ver`, `portal.solicitudes.crear`, `portal.notificaciones.ver`) son exclusivamente del modo colaborador. Ningún rol operativo los tiene por default — si un admin necesita también experiencia personal, hay que dárselos explícitamente desde Administración → Roles y permisos, y eso automáticamente le habilita el selector de modo.

## Bloqueo de cuenta

Un colaborador dado de baja (`estatus` distinto de `activo`/`en_incorporacion`) no puede iniciar sesión: `App\Providers\FortifyServiceProvider::configureActions()` lo bloquea en el login web, `Api\V1\AuthController::login()` en la app móvil, y `App\Http\Middleware\EnsureCuentaActiva` cierra cualquier sesión web que ya estuviera abierta en la siguiente petición. Ver `docs/SOLICITUDES_UNIFICADAS.md` para el flujo completo de baja.
