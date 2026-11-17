# Roles y navegación

MR. LANA PEOPLE separa dos experiencias completamente distintas dentro del mismo sistema — ver `App\Services\Navigation\NavigationService`:

- **Modo colaborador**: el portal personal de cualquier empleado (Mi inicio, Mi expediente, Mis solicitudes, Mi recibo —solo con autoservicio—, Mis notificaciones, Mi inducción, Mi capacitación, Mis seguimientos técnicos, Celebraciones, Directorio, Mi agenda, Ayuda).
- **Modo operativo**: las herramientas de RH/gerencia/dirección ordenadas por el **ciclo de vida laboral del colaborador** (Inicio → 1 Reclutamiento → 2 Contratación → 3 Onboarding → 4 Cierre del periodo de prueba → 5 Permanencia → 6 Capacitación (etapa Desarrollo) → 7 Cierres laborales), más los bloques transversales Organización, Seguimiento, Reportes y Configuración.

Un usuario **nunca** ve ambos mezclados en el mismo menú. Una misma CUENTA puede representar a la vez a un colaborador real y a alguien con permisos operativos; ninguna capacidad depende del nombre del rol:

- **Modo colaborador ("Mi espacio")** — capacidad central `NavigationService::puedeUsarModoColaborador()`, expuesta como Gate `modo-colaborador`: la cuenta está enlazada a un **Colaborador activo** y no tiene el acceso bloqueado (`User::puedeAccederPortal()`), **o** tiene el permiso explícito `portal.ver` (rol `colaborador`).
- **Modo operativo ("Operación RH")** — `dashboard.global.ver` o `dashboard.sucursal.ver`; también Jurídico (`bajas.revisar_juridico`) y Contraloría (`bajas.consulta_contraloria`), cuyo «Inicio» es la bandeja de bajas.

Consecuencia (regresión corregida el 2026-09-28): un super_admin, rh_admin o gerente cuya cuenta está enlazada a su Colaborador activo ve **siempre** el selector [Mi espacio] [Operación RH], sin tener que asignarle además el rol `colaborador`. Una cuenta sin colaborador enlazado (o con el colaborador inactivo/suspendido, o con acceso bloqueado) solo tiene el modo operativo. Como el login web exige colaborador activo/en incorporación (`FortifyServiceProvider`, `EnsureCuentaActiva`), en la práctica toda cuenta administrativa que inicia sesión es también un colaborador.

El Gate `modo-colaborador` protege las rutas personales (`mi-portal`, `mi-perfil`, `mis-notificaciones`, `mi-expediente`, `mi-expediente/foto`) y `DashboardController` (el "Inicio" respeta el modo elegido). **Mi espacio siempre opera sobre el colaborador de la cuenta autenticada** (`$request->user()->colaborador`): esas rutas no reciben ningún id, así que ser administrador nunca abre el "Mi espacio" de otra persona.

## Selector "Mi espacio" / "Operación RH"

Solo aparece cuando `NavigationService::modosDisponibles()` devuelve los dos modos. Vive en `AppSidebar.vue`, se controla con `useNavegacion()` (`resources/js/composables/useNavegacion.ts`) y el modo elegido se guarda en la cookie `experiencia_modo` (no en base de datos — es una preferencia de navegador, no un dato del usuario). Al cambiar de modo se hace un `POST /modo-navegacion` (`App\Http\Controllers\NavigationModeController`) que valida que el modo pedido esté entre los disponibles (403 si no) y redirige a `dashboard` u `portal.index` según corresponda.

Sin cookie y con ambos modos disponibles, el sistema prioriza **operativo** por default (es la herramienta de trabajo principal de quien administra el sistema).

## Estructura canónica del menú (navegación operativa)

La navegación vive en **una sola fuente declarativa**: `resources/js/composables/useMainNavItems.ts` (`NavGroup[]`), consumida por `AppSidebar.vue` (sidebar completo) y `MobileBottomNav.vue` (barra inferior móvil). Nunca se duplica en otro componente. Cada grupo declara `id`, `numero` (etapa 1–7; los transversales no lo llevan), `titulo`, `icono` e `items`, y cada acceso se muestra **solo si el usuario tiene el permiso real que exige su controlador**.

El orden es exactamente el ciclo de vida laboral + transversales. **«Inicio» va siempre desplegado** (`NavGroup.fijo`, 2026-11-12): los demás grupos arrancan plegados salvo el de la pantalla actual, y por eso «Solicitudes» —que vivía dentro de «5 Permanencia»— no se encontraba; ahora está en «Inicio», a la vista. Es la bandeja operativa; «Mis solicitudes» (las propias) sigue solo en Mi espacio.

| # | Grupo | Accesos | Permiso de cada acceso | Ruta |
|---|---|---|---|---|
| — | **Inicio** (siempre desplegado) | Tablero de RH (Jurídico/Contraloría: «Mi bandeja de bajas») | abierto (contenido por `dashboard.*.ver`); una sola página `Dashboard/Operativo` para global y sucursal: cambia el alcance de los datos | `dashboard` |
| | | Directorio (contactos; `directorio.ver` o Corporativo — no es «Colaboradores», ver abajo) | feature `directorio` | `directorio.index` |
| | | Pendientes | `ciclo.*`/`evaluaciones.*`/`onboarding.entregar_activos`/`cierres.*`/`expedientes.revisar`/`solicitudes.*`/`nomina.recibos.crear`/`candidatos.*` | `rh.pendientes.index` |
| | | Solicitudes (bandeja de RH/gerencia; eliminar pruebas: `solicitudes.eliminar`) | `solicitudes.revisar`/`aprobar`/`rh.solicitudes.ver` | `rh.solicitudes.index` |
| 1 | **Reclutamiento y selección** | Vacantes | `vacantes.ver` | `rh.vacantes.index` |
| | | Campañas | `reclutamiento.campanas.ver` | `rh.campanas.index` |
| | | Descriptivos y perfiles | `perfiles_puesto.ver` | `rh.perfiles-puesto.index` |
| | | Candidatos | `candidatos.ver` | `rh.candidatos.index` |
| | | Agenda de entrevistas | `agenda.ver` | `agenda.index` |
| 2 | **Contratación y expediente** | Personas en contratación | `contratos.ver` | `rh.contratacion.index` |
| | | Invitaciones QR (solo personas ya en contratación; antes estaba en el grupo 1) | `rh.incorporacion.invitaciones.ver` | `rh.incorporacion.invitaciones.index` |
| | | Pendientes de alta en sistemas (`docs/SISTEMAS_PENDIENTES_ALTA.md`) | `sistemas.altas.ver` (marcar: `sistemas.altas.atender`) | `sistemas.pendientes-alta.index` |
| | | Pendientes de baja en sistemas | `sistemas.altas.ver` | `sistemas.pendientes-baja.index` |
| | | Validación de documentos | `documentos.revisar` | `rh.validacion-documentos.index` |
| | | Contratos de ingreso (vista por paquete documental de quien está entrando) | `contratos.ver` | `rh.contratos-ingreso.index` |
| | | Contratos de la plantilla (toda la plantilla activa por fecha de ingreso; «Generar todos») | `contratos.ver` (generar: `documentos_laborales.generar`) | `rh.contratos.index` |
| | | Cartas compromiso | `cartas_compromiso.ver` | `rh.cartas-compromiso.index` |
| 3 | **Onboarding** | Inducción institucional | `onboarding.gestionar` + `onboarding.presentar_induccion` | `induccion.presentacion` |
| | | Inducción al puesto | `onboarding.gestionar` + `onboarding.induccion_puesto` | `rh.induccion-puesto.index` |
| | | Configuración de onboarding | `onboarding.gestionar` + `onboarding.configurar` | `rh.onboarding.configuracion` |
| | | Calificaciones y refuerzo (inducción FO-RH-EI + lecciones, retroalimentación y reevaluación) | `onboarding.gestionar` + `onboarding.calificar` | `rh.onboarding.calificaciones` |
| | | Activos y responsivas | `responsivas.ver` | `rh.activos-responsivas.index` |
| 4 | **Cierre del periodo de prueba** | Evaluaciones de periodo de prueba (UN solo acceso, pestañas «Próximas / por asignar», «En proceso» con chip «Por decidir RH» y «Evaluadas») | `evaluaciones.*` | `rh.evaluaciones.index` |
| 5 | **Permanencia** | Colaboradores | `expedientes.ver_todos`/`ver_region`/`ver_sucursal`/`ver_equipo` | `rh.expedientes.index` |
| | | Incapacidades | `incapacidades.ver` | `rh.incapacidades.index` |
| | | Actas administrativas | `actas.ver` | `rh.actas.index` |
| | | Recibos de nómina (lotes: preparar → revisar → emitir; `docs/NOMINA_QUINCENAL.md`) | `nomina.recibos.ver` | `rh.nomina.lotes.index` |
| | | Avisos (enviar a toda la empresa o a una persona; historial) | `avisos.ver`/`avisos.enviar` | `rh.avisos.index` |
| | | Celebraciones | `rh.cumpleanos.ver`/`celebraciones.ver` | `rh.cumpleanos.index` / `celebraciones.index` |
| 6 | **Capacitación** (Fase 3, etapa 6 · Desarrollo, igual que `config/permisos_modulos.php → etapas.desarrollo`) · feature `capacitacion` | Portada de capacitación | permisos de capacitación (`CursoPolicy::portada`) | `capacitacion.index` |
| | | Cursos y aulas (constructor + aula tipo Classroom) | `cursos.ver` | `cursos.index` |
| | | Asignaciones | `asignaciones.ver` | `asignaciones.index` |
| | | Calificar cuestionarios · Calificar actividades | `respuestas.ver` | `calificaciones.cuestionarios.index` / `calificaciones.actividades.index` |
| | | Banco de preguntas | `cuestionarios.administrar` | `bancos-preguntas.index` |
| | | Biblioteca multimedia | `multimedia.administrar` | `multimedia.index` |
| | | Calendario de sesiones | `sesiones.administrar` | `calendario` |
| | | Reporte de cumplimiento | `reportes.globales`/`reportes.sucursal` | `reportes.cumplimiento.index` |
| | | Certificación Mr. Lana | feature `certificacion` + `cursos.ver`/`asignaciones.ver`/`respuestas.ver` | `capacitacion.certificacion` |
| 7 | **Cierres laborales** | Bajas (un apartado con pestañas Bajas · Finiquitos · Rotación · Catálogo de causales; abre la primera pestaña que el backend permite — `docs/BAJAS_Y_CAUSALES.md`) | `bajas.ver`/`bajas.revisar_juridico`/`bajas.consulta_contraloria`/`finiquitos.ver`/`bajas.tablero.ver`/`rotacion.ver`/`cierres.ver` | `rh.bajas.index` (o la pestaña permitida) |
| | | Reingresos | `reingresos.solicitar`/`gestionar` | `rh.reingresos.index` |
| — | **Organización** | Organigrama | `organigrama.ver` | `administracion.jerarquia-puestos.index` |
| | | Responsables de aprobación | `organigrama.ver` + `responsables_aprobacion.ver`/`administrar` | `administracion.responsables-aprobacion.index` |
| | | Puestos y salarios | `puestos.administrar` o `salarios.ver` | `administracion.puestos.index` |
| | | Sucursales | `sucursales.administrar` | `administracion.sucursales.index` |
| | | Departamentos (catálogo + mover personas en lote) | `departamentos.administrar` (mover: + `expedientes.editar`) | `administracion.departamentos.index` |
| | | Empresas | `empresas.ver` | `administracion.empresas.index` |
| — | **Seguimiento** (Fase 2, `docs/SEGUIMIENTO.md`) | Por sucursal · Por gestor | feature `seguimiento` + `seguimiento.ver` | `seguimiento.index` / `seguimiento.gestores.index` |
| | | Nine Box | features `seguimiento` + `nine_box` + `nine_box.ver` | `seguimiento.nine-box.index` |
| | | Seguimientos técnicos · Calendario de seguimientos | `evaluaciones_tecnicas.ver` / `evaluaciones_tecnicas.configurar` | `evaluaciones-tecnicas.index` / `evaluaciones-tecnicas.configuracion` |
| | | Vínculos con Reportes | feature `seguimiento` + `seguimiento.vincular` | `seguimiento.vinculos.index` |
| — | **Reportes** | Rotación de personal (tablero: histórico + bajas del sistema) | `bajas.tablero.ver` | `reportes.rotacion.tablero.index` |
| | | Reportes de RH | `reportes_rh.ver` | `reportes.index` |
| | | Mis reportes | `reportes_rh.mis_reportes` | `rh.reportes.mis-reportes.index` |
| | | Recepción documental | `recepcion_documental.ver` | `rh.recepcion-documental.index` |
| — | **Configuración** · solo administrador | Formularios de evaluación | `formularios_evaluacion.administrar` | `rh.formularios-evaluacion.index` |
| | | Documentos maestros | `plantillas_documentales.administrar` | `rh.documentos-maestros.index` |
| | | Catálogo de causales (misma pantalla que la pestaña de Bajas) | `finiquitos.causales_rotacion` + `finiquitos.ver` | `rh.bajas.causales` |
| | | Usuarios y permisos | `usuarios.ver` | `administracion.usuarios.index` |
| | | Roles y permisos | `roles.administrar`/`notificaciones_rol.editar` | `administracion.roles.index` |
| | | Importar datos · solo super_admin (única entrada para TODAS las cargas por Excel: migración, headcount, salarios y vacaciones, contactos de emergencia, «Carga de rotación (Excel)», perfiles, recibos) | `expedientes.migrar`/`headcount.importar`/`colaboradores.importar_salarios_vacaciones`/`rotacion.cargar`/`perfiles_puesto.importar`/`contactos_emergencia.importar`/`nomina.recibos.importar` | `administracion.importar-datos.index` |
| | | Ajustes y notificaciones | `configuracion.ver` | `administracion.configuracion.index` |
| | | Notificaciones del sistema | `notificaciones.ver_todas` | `administracion.notificaciones-sistema.index` |
| | | Versiones de app | `app_releases.ver` | `administracion.app-releases.index` |

Íconos sin repetir (2026-11-17): Usuarios `ShieldCheck` / Roles `KeyRound`; Vacantes `Briefcase` / Puestos y salarios `BadgeDollarSign` / Departamentos `FolderTree`.

### URL canónicas y redirecciones compatibles (2026-11-17)

| Pantalla | URL canónica | URL vieja (se conserva) |
|---|---|---|
| Rotación de personal | `/reportes/rotacion/tablero` (`reportes.rotacion.tablero.index`) | `/rh/bajas/rotacion` (`rh.bajas.rotacion`) → **301** con los mismos filtros. La pestaña «Rotación» de Bajas ya apunta a la canónica. |
| Reportes de RH | `/reportes` (`reportes.index`) | `/rh/reportes` (`rh.reportes.index`) → **301** con los mismos filtros. Las exportaciones siguen en `/rh/reportes/excel` y `/rh/reportes/pdf`. |
| Mi expediente | `/mi-expediente` | `/mi-perfil` (`portal.perfil`) → redirige a Mi expediente a quien tiene `expedientes.ver`; sin ese permiso sigue mostrando el perfil básico. |
| Recibos de nómina | `/rh/nomina/lotes` | `/rh/nomina` queda como «Recibos y descargas» (ajustes y descargas de la quincena; «Preparar lote» crea el lote). `POST rh/expedientes/{id}/recibos-nomina` ya no crea recibos: redirige a `/rh/nomina?colaborador=…`. |

Dos pantallas distintas que **no** se consolidan: **Directorio** (contactos, `directorio.ver`, también para gestores sin el módulo de expedientes) y **Colaboradores** (expedientes con alcance). «Contratos de ingreso» y «Personas en contratación» comparten consulta; se mantienen separadas hasta que RH confirme si usa la vista por paquete documental.

Las vistas de **evaluación de periodo de prueba** (Evaluaciones de periodo de prueba, Evaluación y decisión) son filtros `?estado=` reales de la **misma** pantalla (`rh.evaluaciones.index`), no pantallas duplicadas. «Calificaciones y refuerzo» (grupo 3) es otra cosa: las calificaciones de la **inducción** (`rh.onboarding.calificaciones`, SesionInduccionService::calificacionesRh).

«Periodos por vencer» ya NO es pantalla propia (2026-10-22): es la pestaña «Próximas / por asignar» de `rh.evaluaciones.index` (`rh.periodos-prueba.index` solo redirige ahí, `?tab=proximas`; los enlaces viejos `?estado=…` caen en la pestaña equivalente). `PeriodosPruebaService` detecta por **fecha de ingreso** y `puestos.meses_periodo_prueba` a quien sigue en periodo de prueba aunque su contrato o su evaluación todavía no existan en el sistema (alerta a `contratos.regularizacion.dias_alerta_periodo_prueba` = 15 días naturales; el aviso automático que abre la evaluación sigue en días hábiles). «Contratos» (`rh.contratos.index`, `RegularizacionContratosService`) muestra qué contratos le corresponden a cada colaborador activo y genera los que falten (individual o en lote en la cola, idempotente).

**Colaborador ("Mi espacio")**: Mi inicio, Mi expediente (si `expedientes.ver`), Mis solicitudes (si `portal.solicitudes.ver`/`solicitudes.crear`), Mi recibo (solo si `features.recibos_colaborador`), Mis notificaciones, Mi inducción, Mi capacitación (si `features.capacitacion`: mis aulas, pendientes y mis certificados — `mi-capacitacion.index`), Celebraciones, Ayuda.

## Expedientes vs. Usuarios vs. Roles y permisos

Tres conceptos relacionados pero distintos, cada uno con su propia pantalla:

- **Expedientes** (`rh.expedientes`): el colaborador/persona — datos laborales, sucursal, departamento, puesto, IMSS, historial. Un colaborador puede existir sin cuenta de acceso.
- **Usuarios** (`administracion.usuarios`, permiso `usuarios.ver`): listado global de CUENTAS de acceso (correo, roles asignados, estado de acceso, correo verificado, 2FA, último acceso) — `App\Http\Controllers\Administracion\UsuarioController::index()`. No administra datos laborales ni crea colaboradores.
- **Roles y permisos** (`administracion.roles`): el catálogo/configuración de roles en sí, no las cuentas que los tienen.

La cuenta de acceso de un colaborador puede administrarse desde cualquiera de los dos lugares con datos de esa cuenta — la pestaña «Cuenta» del expediente (contextual, cuando ya estás viendo a ese colaborador) o el listado global de Usuarios (para gestionar cuentas sin pasar por un expediente primero) — ambos llaman a los mismos endpoints de `UsuarioController`, así que nunca quedan desincronizados.

Lo que **ya no existe** como entrada de menú (aunque las rutas sigan vivas por compatibilidad): "Vacaciones" y "Vacaciones (revisión)" como módulos aparte (vacaciones vive dentro de Solicitudes), "Reclutamiento" (se resume en Vacantes), "Reportes RH" duplicado (queda solo "Reportes de RH", URL `/reportes`), "Jerarquía de puestos" como texto visible (la pantalla y el menú dicen "Organigrama" — ver `docs/ORGANIGRAMA.md`), "Bajas y finiquitos" (`rh.cierres.index`) como entrada propia (todo vive en «Bajas» con pestañas), "Recibo simple de pago" (ahora «Recibos de nómina»), "Enviar notificación" (ahora «Avisos»), "Catálogo de bajas" (ahora «Catálogo de causales»), "Importar salarios y vacaciones" y "Contactos de emergencia" en Permanencia (solo en Configuración → Importar datos), "Mi perfil" (redirige a Mi expediente) y el **grupo "Administración"** disperso: esas entradas se reagruparon en **Organización** y **Configuración**. Cumpleaños + Aniversarios + Muro se unifican en **"Celebraciones"**. La carga del Excel de rotación (`reportes.rotacion.index`) se llama «Carga de rotación (Excel)» para no confundirse con el tablero «Rotación de personal».

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

`people:sincronizar-permisos` (y `db:seed --force`) es **no destructivo** a propósito: crea los permisos nuevos del catálogo y otorga sus defaults **solo de esos permisos nuevos** (a los roles base y, por `herencia`, a quien ya tenía el permiso general del que se separaron — incluidos roles hechos a mano y permisos directos); a un rol que ya existía nunca le re-otorga un permiso que ya existía (lo que se quitó desde «Roles y permisos» se queda quitado) y nunca quita lo que un administrador agregó. Por eso **quitar un permiso del seeder no lo quita en producción**. Cuando el negocio decide QUITAR permisos a un rol, se hace con una migración de datos que nombra SOLO a los roles afectados y llama a `App\Services\Permisos\AlineacionPermisosRolService::alinearAlBase([...])`: deja cada rol con exactamente su base del seeder (`syncPermissions`), crea los permisos base que falten, limpia la caché de Spatie y, si el rol no existe, no hace nada. Idempotente. Ninguna otra migración ni comando lo aplica a todos los roles.

| Migración | Roles | Qué revoca |
|---|---|---|
| `2026_10_15_180000_coordinadoras_usan_people_como_colaboradoras` | `coordinadora`, `coordinadora_regional` | Todo lo operativo (expedientes/documentos de sucursal o región, revisar solicitudes, bandeja móvil RH, usuarios, reportes RH, organigrama, cierres/pago de finiquito, finiquitos, onboarding, contactos de todo el directorio). Quedan con el catálogo de `colaborador`. |
| `2026_10_20_250000_bajas_directas_sin_borrador` | `jefe_directo`, `auditor`, `sistemas` | **Solo** los permisos de «Bajas» (bajas.ver, bajas.solicitar y sus alcances) y, a `jefe_directo`, el atajo `cierres.solicitar` / `solicitudes.bajas.crear`: «Bajas» es de gerentes, RH y super admin. Revocación puntual (no `alinearAlBase`): nada más de esos roles cambia. |
| `2026_10_15_180100_auditor_minimo_privilegio` | `auditor` | Recibos de nómina, préstamos y actas (con sus permisos de alcance) y cualquier permiso fuera de su base: el auditor es solo lectura de empresas, expedientes, documentos, vacaciones, solicitudes, reportes RH, reclutamiento y ciclo laboral (contratos, evaluaciones, cierres, documentos laborales). |
| `2026_10_20_230000_importaciones_solo_super_admin` | todos menos `super_admin` (roles y permisos directos de usuarios) | SOLO los permisos de importación/carga masiva: `expedientes.migrar` (migración y carga inicial), `headcount.importar`, `colaboradores.importar_salarios_vacaciones`, `rotacion.cargar`, `nomina.recibos.importar`, `contactos_emergencia.importar`, `perfiles_puesto.importar`. RH conserva la consulta (tablero e histórico de rotación, headcount, expedientes, recibos, perfiles) y generar recibos desde el sueldo. No usa `alinearAlBase`: no toca ningún otro permiso. |
| `2026_11_13_500200_sistemas_sin_descarga_ni_alcance_de_recibos_y_finiquitos` | `sistemas` | Lo que la herencia le dejó de recibos y finiquitos tras retirarle `nomina.recibos.ver`/`finiquitos.ver` (`nomina.recibos.descargar`, `*.ver_todos`/`ver_region`/`ver_sucursal`/`ver_equipo`): con descargar + alcance «Todos» podía bajar el lote completo de recibos para imprimir. Revocación puntual y auditada. |

En producción basta `php artisan migrate --force` (seguido, como siempre, de `php artisan people:sincronizar-permisos`). Si después se personaliza uno de esos roles desde «Roles y permisos», la migración ya corrió y no lo vuelve a tocar.

`sistemas` conserva su **lectura global para soporte** sin acciones de decisión (decisión documentada en `docs/FLUJO_OPERATIVO_Y_PERMISOS.md` §4); no se le aplicó revocación.

## Cuentas de rol superior (2026-11-04)

Quien no puede DAR un rol tampoco puede cambiar la contraseña, el usuario/correo ni revocar el acceso de una cuenta que lo tiene (sería suplantarla): `RolPermisoService::esCuentaSuperior()` usa los mismos niveles de `config/permisos_modulos.php → asignacion_roles` (super_admin 100, direccion 90, sistemas 85, rh_admin 80, el resto 10; un rol personalizado es superior si tiene un permiso que el actor no tiene) y lo aplican `UserPolicy::update`, `revocarAcceso` y `restablecerPassword` (web y API `credenciales`). El listado de Usuarios oculta «Editar cuenta» y «Generar credenciales» por fila (`puede_editar`, `puede_restablecer_password`). super_admin nunca tiene superior.

## Pantalla «Roles y permisos» (matriz por acción, 2026-10-22)

`administracion.roles.index` (`RolController` → `App\Services\Permisos\CatalogoPermisosService::paraPantalla()`) presenta cada rol como una matriz súper específica, armada **solo** desde `config/permisos_modulos.php`:

- **Etapas** iguales al sidebar (`etapas`: Inicio, 1 Reclutamiento … 7 Cierres laborales, Organización, Reportes, Configuración, Mi espacio). Cada módulo cae en su etapa por su clave `etapa` o por `etapa_por_modulo`; sin ninguna, en «Otros módulos».
- **Módulo** = tarjeta con interruptor de **Acceso** (`acceso`, puede ser `null` en módulos que solo agrupan acciones), **Alcance de datos** (Propios/Equipo/Sucursal/Región/Todos, solo los niveles que el módulo define) y **cada acción** como interruptor con su descripción (`descripciones`) en un tooltip. «Todo el módulo» / «Quitar todo».
- Búsqueda de permisos (sin acentos), «Solo lo que tiene», **comparar** con otro rol (resalta diferencias, «Solo diferencias») y **copiar** los permisos de otro rol al borrador; contador de cambios sin guardar y guardado con confirmación.
- Pestaña **«Qué puede hacer»**: resumen en español llano, módulo por módulo.
- **«Otros permisos»**: todo permiso de la base o del seeder que no esté en ningún módulo, agrupado por prefijo — nada queda oculto.
- `super_admin` es de solo lectura (acceso total; `RolPolicy::update` lo niega). Los roles del seeder no se renombran ni se eliminan (el código los reconoce por nombre y el sincronizador los recrearía); sus permisos sí se ajustan.
- Guardar valida cada permiso contra el catálogo existente en la base (`CatalogoPermisosService::asignables()`); un permiso fuera del catálogo que el rol ya tenía se puede conservar, no agregar. Cada cambio queda en la bitácora (`AuditoriaService`, eventos `rol_creado`, `rol_permisos_actualizados`, `rol_clonado`, `rol_eliminado`, con `agregados`/`quitados`).

**Dividir un permiso general en acciones** (p. ej. `sucursales.administrar` → `sucursales.crear`/`editar`/`eliminar`): el general se queda como `acceso` del módulo (es lo que revisa el sidebar), las acciones nuevas se declaran en `acciones` + `herencia` (`nuevo => general`) + `descripciones`, y la Policy exige acceso **y** acción. No se tocan los arreglos de roles del seeder: `RolesYPermisosSeeder::permisosBasePorRol()` deriva los defaults de la herencia y `people:sincronizar-permisos` los otorga en producción a quien tenía el general — nadie gana ni pierde nada por el despliegue.

## Pestaña «Notificaciones» y recorrido por rol (2026-11)

- Roles y permisos → **Notificaciones**: qué acción le avisa a qué rol (ver `NOTIFICACIONES_EVENTOS.md`). Edita `notificaciones_rol.editar` (RH Admin, Sistemas, super_admin); RH Admin entra sólo a esta pestaña, sin ver ni tocar permisos.
- Subgerente: sigue en Mi espacio, con la entrada «Mi agenda».
- Jurídico y Contraloría: su inicio se llama «Mi bandeja de bajas», porque no tienen tablero.
- «Solicitudes» aparece también a quien sólo consulta (`rh.solicitudes.ver`), igual que `SolicitudInternaPolicy`.
- Gerente y Subgerente no ven en agenda ni en candidatos a quien aspira a Subgerente o Gerente (`AprobadorEntrevistaService::noVeReemplazos`).
- Gerencias tienen `rh.colaboradores.detalle`: en la app abren la ficha que ya ven en web.
- `SidebarPorRolTest` recorre cada rol: su menú responde 200 y lo ajeno 403. Falla si aparece una entrada nueva sin registrar.
