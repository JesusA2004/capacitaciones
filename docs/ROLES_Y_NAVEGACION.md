# Roles y navegación

MR. LANA PEOPLE separa dos experiencias completamente distintas dentro del mismo sistema — ver `App\Services\Navigation\NavigationService`:

- **Modo colaborador**: el portal personal de cualquier empleado (mi portal, mis solicitudes, mi expediente, mis notificaciones, mi perfil).
- **Modo operativo**: las herramientas de RH/gerencia/dirección para operar el sistema (dashboard, colaboradores, solicitudes por revisar, vacantes, candidatos, organigrama, formatos, reportes, cumpleaños, administración).

Un usuario **nunca** ve ambos mezclados en el mismo menú. Una misma CUENTA puede representar a la vez a un colaborador real y a alguien con permisos operativos; ninguna capacidad depende del nombre del rol:

- **Modo colaborador ("Mi espacio")** — capacidad central `NavigationService::puedeUsarModoColaborador()`, expuesta como Gate `modo-colaborador`: la cuenta está enlazada a un **Colaborador activo** y no tiene el acceso bloqueado (`User::puedeAccederPortal()`), **o** tiene el permiso explícito `portal.ver` (rol `colaborador`).
- **Modo operativo ("Operación RH")** — `dashboard.global.ver` o `dashboard.sucursal.ver`.

Consecuencia (regresión corregida el 2026-09-28): un super_admin, rh_admin o gerente cuya cuenta está enlazada a su Colaborador activo ve **siempre** el selector [Mi espacio] [Operación RH], sin tener que asignarle además el rol `colaborador`. Una cuenta sin colaborador enlazado (o con el colaborador inactivo/suspendido, o con acceso bloqueado) solo tiene el modo operativo. Como el login web exige colaborador activo/en incorporación (`FortifyServiceProvider`, `EnsureCuentaActiva`), en la práctica toda cuenta administrativa que inicia sesión es también un colaborador.

El Gate `modo-colaborador` protege las rutas personales (`mi-portal`, `mi-perfil`, `mis-notificaciones`, `mi-expediente`, `mi-expediente/foto`) y `DashboardController` (el "Inicio" respeta el modo elegido). **Mi espacio siempre opera sobre el colaborador de la cuenta autenticada** (`$request->user()->colaborador`): esas rutas no reciben ningún id, así que ser administrador nunca abre el "Mi espacio" de otra persona.

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

Los permisos `portal.*` (`portal.ver`, `portal.perfil.ver`, `portal.solicitudes.ver`, `portal.solicitudes.crear`, `portal.notificaciones.ver`) siguen siendo del rol `colaborador` y ningún rol operativo los tiene por default — pero ya **no** son la única llave de "Mi espacio": una cuenta operativa enlazada a un Colaborador activo lo tiene por el Gate `modo-colaborador` (ver arriba).

## Bloqueo de cuenta

Un colaborador dado de baja (`estatus` distinto de `activo`/`en_incorporacion`) no puede iniciar sesión: `App\Providers\FortifyServiceProvider::configureActions()` lo bloquea en el login web, `Api\V1\AuthController::login()` en la app móvil, y `App\Http\Middleware\EnsureCuentaActiva` cierra cualquier sesión web que ya estuviera abierta en la siguiente petición. Ver `docs/SOLICITUDES_UNIFICADAS.md` para el flujo completo de baja.

## Coordinadoras: usan PEOPLE como colaboradoras (2026-10-15)

Decisión del negocio: la **Coordinadora** (roles `coordinadora` y `coordinadora_regional`) usa PEOPLE como **colaboradora**, no como operadora de RH. Ambos roles tienen exactamente el catálogo de `colaborador` (`RolesYPermisosSeeder::PERMISOS_ROL_COLABORADOR`: su expediente, sus documentos, sus solicitudes/vacaciones, Mi espacio, app y notificaciones propias) y:

- no aparecen en `config/permisos_modulos.php → alcance_por_rol` (alcance «Propios»), ni en `AlcanceOrganizacionalService::ROLES_ALCANCE_SUCURSAL`, ni en `MobileBootstrapService::ROLES_MANAGER` — el bootstrap móvil devuelve `capabilities.rh = false` y `capabilities.manager = false`;
- sin modo operativo (no tienen `dashboard.*.ver`): solo «Mi espacio»; la Guía las trata como colaboradoras (`resources/js/lib/guia/roles.ts`);
- reciben 403 en expedientes ajenos, solicitudes/vacaciones/documentos de RH (web y API), usuarios, reportes, cierres y finiquitos. Programar el pago de un finiquito (`cierres.programar_pago`) queda en RH; rehabilitar acceso (`usuarios.restablecer_acceso`), en RH y `gerente_regional`.

## Revocar permisos en producción (mecanismo explícito)

`people:sincronizar-permisos` (y `db:seed --force`) es **no destructivo** a propósito: solo agrega lo que falta y nunca quita lo que un administrador agregó desde «Roles y permisos». Por eso **quitar un permiso del seeder no lo quita en producción**. Cuando el negocio decide QUITAR permisos a un rol, se hace con una migración de datos que nombra SOLO a los roles afectados y llama a `App\Services\Permisos\AlineacionPermisosRolService::alinearAlBase([...])`: deja cada rol con exactamente su base del seeder (`syncPermissions`), crea los permisos base que falten, limpia la caché de Spatie y, si el rol no existe, no hace nada. Idempotente. Ninguna otra migración ni comando lo aplica a todos los roles.

| Migración | Roles | Qué revoca |
|---|---|---|
| `2026_10_15_180000_coordinadoras_usan_people_como_colaboradoras` | `coordinadora`, `coordinadora_regional` | Todo lo operativo (expedientes/documentos de sucursal o región, revisar solicitudes, bandeja móvil RH, usuarios, reportes RH, organigrama, cierres/pago de finiquito, finiquitos, onboarding, contactos de todo el directorio). Quedan con el catálogo de `colaborador`. |
| `2026_10_15_180100_auditor_minimo_privilegio` | `auditor` | Recibos de nómina, préstamos y actas (con sus permisos de alcance) y cualquier permiso fuera de su base: el auditor es solo lectura de empresas, expedientes, documentos, vacaciones, solicitudes, reportes RH, reclutamiento y ciclo laboral (contratos, evaluaciones, cierres, documentos laborales). |

En producción basta `php artisan migrate --force` (seguido, como siempre, de `php artisan people:sincronizar-permisos`). Si después se personaliza uno de esos roles desde «Roles y permisos», la migración ya corrió y no lo vuelve a tocar.

`sistemas` conserva su **lectura global para soporte** sin acciones de decisión (decisión documentada en `docs/FLUJO_OPERATIVO_Y_PERMISOS.md` §4); no se le aplicó revocación.
