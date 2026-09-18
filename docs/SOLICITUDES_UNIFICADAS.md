# Solicitudes unificadas

Un solo módulo (`App\Models\SolicitudInterna`, tabla `solicitudes_internas`) para vacaciones, permisos, préstamo interno, incapacidad, baja de colaborador y trámites administrativos. Antes existían módulos separados (vacaciones tenía su propia tabla `solicitudes_vacaciones`); esa tabla se conserva **intacta pero legacy** — sin datos que migrar porque estaba vacía al unificar — y sus endpoints siguen respondiendo por compatibilidad, pero ya no aparecen en el menú (ver `docs/ROLES_Y_NAVEGACION.md`).

## Tipos (`App\Enums\TipoSolicitudInterna`)

`vacaciones`, `permiso_con_goce`, `permiso_sin_goce`, `permiso_tiempo`, `salida_temprano`, `llegada_tarde`, `incapacidad`, `constancia_laboral`, `actualizacion_datos`, `actualizacion_bancaria`, `reposicion_documental`, `prestamo`, `baja_colaborador`, `permiso_especial_cumpleanos`, `permiso_especial_paternidad`, `permiso_especial_fallecimiento`, `solicitud_general`.

Cada tipo declara sus propias reglas de formulario vía métodos del enum: `usaRangoFechas()`, `usaHorario()` (un solo día, sin rango — permiso por horas/salida temprano/llegada tarde), `requiereDias()` (solo vacaciones), `requiereMonto()` (solo préstamo), `requiereColaboradorObjetivo()` (solo baja). El frontend (`Solicitudes/Index.vue`) muestra/oculta campos del formulario según esas banderas — no hay un formulario genérico único, cada tipo pide justo lo que necesita.

## Flujo de creación (`App\Services\Solicitudes\SolicitudesService::crear()`)

Única puerta de entrada, usada tanto por el controlador web (`Solicitudes\SolicitudInternaController`) como por la API móvil. Reglas de negocio aplicadas ahí, no en los controladores:

- **Vacaciones**: valida `dias_solicitados` contra el saldo disponible (`App\Services\Vacaciones\VacacionesService::saldo()`/`saldoColaborador()`, que suma lo ya usado/en trámite tanto del módulo legacy como del unificado por `colaborador_id` — nunca se puede rebasar el saldo por ningún camino).
- **Préstamo**: captura `monto_solicitado` y `plazo_meses`.
- **Baja de colaborador**: requiere el permiso `solicitudes.bajas.crear` (no el genérico `solicitudes.crear` — un gerente puede pedir la baja de su equipo sin poder crear otros tipos de solicitud sobre sí mismo) y valida, vía `SolicitudInternaPolicy::crearBaja()`, que quien la crea tenga alcance organizacional sobre el colaborador objetivo (`Colaborador`, no `User` — el campo del formulario se llama `colaborador_objetivo_id` mandado por el sujeto, pero se guarda en `objetivo_colaborador_id`, el FK real hacia `colaboradores`).

Cada solicitud guarda `colaborador_id` (la persona dueña de la solicitud, fuente de verdad) además de `user_id` (la cuenta/actor que hizo el submit, se conserva por compatibilidad) — ver `SolicitudInterna::personaSolicitante()`. Un colaborador sin cuenta de acceso puede tener saldo de vacaciones y solicitudes igual que cualquier otro; solo no puede *crear* una desde el portal (necesita sesión), pero sí puede ser el sujeto de una `baja_colaborador` creada por alguien más.

## Revisión y aprobación

Bandeja de RH/gerencia en `Rh\SolicitudController` (ruta `rh.solicitudes.*`), acotada por `AlcanceOrganizacionalService`. Transiciones: enviada → en revisión → aprobada/rechazada/requiere corrección → cerrada; el colaborador puede cancelar mientras no sea una transición final.

## Baja de colaborador: qué pasa al aprobar

Al aprobar una solicitud de tipo `baja_colaborador`, `SolicitudesService::cambiarEstado()` dispara `App\Services\Solicitudes\BajaColaboradorService::ejecutar()`, que en una sola transacción:

1. Registra el movimiento laboral de baja (`MovimientoLaboralService::registrarBaja()`) — misma fuente de auditoría que la baja administrativa directa.
2. Cambia `estatus` a `inactivo`.
3. Revoca **todos** los tokens Sanctum del colaborador (cierra la app móvil de inmediato).
4. Marca sus dispositivos móviles como revocados (`revoked_at`).
5. Sincroniza la vacante automática de su (sucursal, puesto) contra headcount (`VacanteAutoGenerationService::sincronizar()`) — si la plantilla autorizada sigue exigiendo esa plaza, se abre una vacante automática; nunca se crea una vacante manual duplicada.

Nunca borra al usuario ni su expediente. El bloqueo de login (web y móvil) y el middleware `EnsureCuentaActiva` están documentados en `docs/ROLES_Y_NAVEGACION.md`.

Existe también una baja administrativa **directa**, sin pasar por una solicitud (`Administracion\UsuarioController::destroy()`, botón "Desactivar" en Colaboradores) — para cuando RH necesita dar de baja de inmediato sin flujo de aprobación. Ambos caminos alimentan el mismo `movimientos_laborales`, así que los KPIs de rotación del dashboard (`docs/REPORTES.md`) cuentan bajas de los dos orígenes sin duplicar ni perder ninguna.

## Formatos oficiales por tipo

`config/solicitudes.php` mapea cada tipo al slug del formato oficial que le corresponde (`formato-vacaciones`, `formato-permiso`, `contrato-credito-colaboradores`, `formato-baja-personal`), en qué transición se genera (`generar_en`) y si espera firma de vuelta (`requiere_firma`). El enum `TipoSolicitudInterna` expone `formatoOficialSlug()`/`formatoGenerarEn()`/`formatoRequiereFirma()` leyendo de ese config — una sola fuente de verdad.

`App\Services\Solicitudes\SolicitudFormatoOficialService::generarSiAplica()` conecta esa configuración al flujo real: `SolicitudesService::cambiarEstado()` la llama automáticamente al aprobar, y genera el PDF con overlay (`OfficialFormatOverlayService`) usando los datos del colaborador más los propios de la solicitud (folio, motivo, fechas, días, monto). Es idempotente (no duplica si la solicitud ya tiene una generación) y nunca revierte la aprobación si el formato no existe/no está configurado — solo registra un `Log::warning` y la UI muestra "requiere configurar «X»" a RH (ver `Rh/Solicitudes/Show.vue`, sección "Documento oficial").

Esto es independiente de la generación **manual** desde plantillas DOCX (botón "Generar documento adicional", `App\Http\Controllers\Rh\FormatoController`), que sigue existiendo para documentos que no tienen un formato oficial fijo mapeado aquí.

Subir el PDF firmado (`FormatoOficialController::subirFirmado()`) marca la generación como `firmado` y, si el tipo tiene `documento_expediente_clave` configurada, archiva una copia como nueva versión de ese `DocumentType` en el expediente del colaborador (`DocumentoStorageService::subirVersion()`) — sin duplicar la lógica de versionado de documentos.
