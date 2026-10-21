# Solicitudes unificadas

Un solo módulo (`App\Models\SolicitudInterna`, tabla `solicitudes_internas`) para vacaciones, permisos, préstamo interno, incapacidad, baja de colaborador y trámites administrativos. Antes existían módulos separados (vacaciones tenía su propia tabla `solicitudes_vacaciones`); esa tabla se conserva **intacta pero legacy** — sin datos que migrar porque estaba vacía al unificar — y sus endpoints siguen respondiendo por compatibilidad, pero ya no aparecen en el menú (ver `docs/ROLES_Y_NAVEGACION.md`).

## Tipos (`App\Enums\TipoSolicitudInterna`)

`vacaciones`, `permiso_con_goce`, `permiso_sin_goce`, `permiso_tiempo`, `salida_temprano`, `llegada_tarde`, `incapacidad`, `constancia_laboral`, `actualizacion_datos`, `actualizacion_bancaria`, `reposicion_documental`, `prestamo`, `baja_colaborador`, `permiso_especial_cumpleanos`, `permiso_especial_paternidad`, `permiso_especial_fallecimiento`, `solicitud_general`.

Cada tipo declara cómo captura fechas con `modoFechas()` (`App\Enums\ModoFechasSolicitud`) y el catálogo `SolicitudesService::tiposConFormulario()` manda **solo** los campos de ese tipo (web y app construyen el formulario desde ahí):

| Modo | Tipos | Campos | Regla |
|---|---|---|---|
| `duracion` | incapacidad, permiso con/sin goce, paternidad, fallecimiento | fecha de inicio + número de días | `fecha_fin = inicio + (días − 1)`, días **naturales** (incluye sábado y domingo). Ej.: 14-oct + 5 días → 18-oct |
| `dias_especificos` | vacaciones | lista de días (`dias[]`) | días sueltos, sin domingo (`config/vacaciones.php` → `dias_no_computables`), sin repetidos, desde hoy, sin traslape con otras vacaciones vigentes, contra el saldo |
| `horario` | permiso por horas, salida temprano, llegada tarde | fecha | un solo día |
| `fecha_unica` | permiso de cumpleaños | fecha | un solo día |
| `ninguna` | constancia, actualización de datos/bancaria, reposición, préstamo, general, baja | — | sin fechas |

La fecha fin **nunca** se captura: la calcula `App\Services\Solicitudes\FechasSolicitudService`. Vacaciones guarda sus días en `solicitud_vacaciones_dias` (fecha única por solicitud) como fuente real; `fecha_inicio`/`fecha_fin`/`dias_solicitados` se siguen llenando (primer día, último día, cuántos) por compatibilidad con reportes, formatos y el saldo. Clientes anteriores que mandan `fecha_inicio` + `fecha_fin` se traducen a la misma regla (rango → duración natural; rango de vacaciones → sus días sin domingo). Feriados: todavía no hay catálogo, no se descuentan.

## Flujo de creación (`App\Services\Solicitudes\SolicitudesService::crear()`)

Única puerta de entrada, usada tanto por el controlador web (`Solicitudes\SolicitudInternaController`) como por la API móvil. Reglas de negocio aplicadas ahí, no en los controladores:

- **Vacaciones**: valida el número de días elegidos contra el saldo disponible (`App\Services\Vacaciones\VacacionesService::saldo()`/`saldoColaborador()`, que suma lo ya usado/en trámite tanto del módulo legacy como del unificado por `colaborador_id` — nunca se puede rebasar el saldo por ningún camino).
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

La forma de salida, la causal, los documentos y la bitácora de cada baja viven en el **módulo de Bajas y Causales** (modelo `Baja`, ver `docs/BAJAS_Y_CAUSALES.md`): la baja es DIRECTA: el cierre laboral (y su solicitud) se crea al REGISTRARLA (gerente de su personal o RH, en «Bajas» o desde el ciclo laboral) sin aprobación de por medio, y la baja se cierra cuando sus documentos obligatorios están completos.

## Formatos oficiales por tipo

`config/solicitudes.php` mapea cada tipo al slug del formato oficial que le corresponde (`formato-vacaciones`, `formato-permiso`, `contrato-credito-colaboradores`, `formato-baja-personal`), en qué transición se genera (`generar_en`) y si espera firma de vuelta (`requiere_firma`). El enum `TipoSolicitudInterna` expone `formatoOficialSlug()`/`formatoGenerarEn()`/`formatoRequiereFirma()` leyendo de ese config — una sola fuente de verdad.

`App\Services\Solicitudes\SolicitudFormatoOficialService::generarSiAplica()` conecta esa configuración al flujo real: `SolicitudesService::cambiarEstado()` la llama automáticamente al aprobar, y genera el PDF con overlay (`OfficialFormatOverlayService`) usando los datos del colaborador más los propios de la solicitud (folio, motivo, fechas, días, monto). Es idempotente (no duplica si la solicitud ya tiene una generación) y nunca revierte la aprobación si el formato no existe/no está configurado — solo registra un `Log::warning` y la UI muestra "requiere configurar «X»" a RH (ver `Rh/Solicitudes/Show.vue`, sección "Documento oficial").

Esto es independiente de la generación **manual** desde plantillas DOCX (botón "Generar documento adicional", `App\Http\Controllers\Rh\FormatoController`), que sigue existiendo para documentos que no tienen un formato oficial fijo mapeado aquí.

Subir el PDF firmado (`FormatoOficialController::subirFirmado()`) marca la generación como `firmado` y, si el tipo tiene `documento_expediente_clave` configurada, archiva una copia como nueva versión de ese `DocumentType` en el expediente del colaborador (`DocumentoStorageService::subirVersion()`) — sin duplicar la lógica de versionado de documentos.

## Motor de aprobaciones (2026-10-20)

Única fuente de «quién aprueba qué»: `App\Services\Solicitudes\AprobacionOrganizacionalService`. Web (Inertia) y API V1 consumen su resultado; ningún controlador decide pasos por su cuenta. `AprobacionJerarquicaService` (decisiones por nivel en `solicitud_aprobaciones`) y `VistoBuenoService` siguen siendo la puerta para REGISTRAR un visto bueno; los niveles los resuelve el motor.

### Interfaz pública (para otros módulos)

| Método | Para qué |
|---|---|
| `jefeOrganizacional(Colaborador): ?Colaborador` | A quién reporta en el organigrama (`colaboradores.jefe_id`, derivado). |
| `responsableDeAprobacion(Colaborador): array` | Responsable de APROBACIÓN (primer visto bueno): `origen`, `origen_etiqueta`, `nivel`, `etiqueta_paso`, `colaboradores`, `usuarios`, `bloqueado`, `mensaje`, `regla_id`. |
| `resumenResponsables(Colaborador): array` | Para expediente/ficha/organigrama: `jefe_organizacional {id,nombre,puesto}`, `responsable_aprobaciones {id,nombre,puesto}`, `origen`, `origen_etiqueta`, `mensaje`, `bloqueado`, `distinto_del_jefe`. |
| `pasos(SolicitudInterna): list` | Pasos de visto bueno (antes de RH) con sus cuentas; un paso `bloqueado` no tiene cuentas. |
| `flujo(SolicitudInterna, ?User $actor): array` | Contrato completo para pantallas (ver abajo). |
| `puedeActuar(User, SolicitudInterna): bool` | Si el usuario puede actuar AHORA. |
| `quienesPuedenActuar(SolicitudInterna): Collection<User>` | A quién avisar: SOLO quien puede actuar ahora (vacío si el paso está bloqueado o la solicitud es final). |
| `elegibilidadPrestamo(?Colaborador, ?CarbonInterface): array` / `validarElegibilidadPrestamo()` | Regla de 6 meses completos (fecha del servidor). |

Las **correcciones** (`actualizacion_datos` y demás tipos sin visto bueno) usan el mismo `flujo()`: «Solicitud creada → Recursos Humanos».

### Regla de resolución del responsable de aprobación

1. Responsable **explícito de la persona** (Organización → Responsables de aprobación, tabla `responsables_aprobacion`).
2. Responsable **explícito de su puesto** (aplica a todas las personas del puesto).
3. **Regla por puesto**:
   - **Gerente de Sucursal** (puesto que reporta a un regional) → Gerente Regional de **su clasificación Q1/Q3** (`clasificaciones_region`): el titular o, si la plaza está vacante, quien la **cubre temporalmente**. Sin clasificación → paso **bloqueado** «Pendiente de asignar región de aprobación» (no se adivina ni sube a Dirección Comercial). Regional vacante sin cobertura → bloqueado con su motivo.
   - **Personal de sucursal** → gerencia de su sucursal (titular o cobertura vigente).
4. **Jefe organizacional** (organigrama; ya contempla coberturas).

Un responsable explícito sin cuenta activa (baja, acceso bloqueado) se salta y sigue la regla organizacional. Nunca se asigna a sí mismo.

Después del primer paso: **Regional** (personal de sucursal; el de la clasificación Q1/Q3 del gerente de su sucursal o, sin ella, el de la matriz), **Dirección Comercial** solo en los tipos de `config('solicitudes.direccion_comercial_gerencia')` (hoy: préstamo) cuando la persona es de gerencia o superior **y depende de Dirección Comercial** (RH, Contraloría y Sistemas, que dependen de Dirección General, no pasan por ella) y, al final, **Recursos Humanos** (préstamo: `prestamos.autorizar`, con captura manual de cargo/IVA; demás: `rh.solicitudes.aprobar`/`solicitudes.aprobar`).

### Reglas finales por rol

| Solicitante | Vacaciones / permisos | Préstamo | Correcciones, constancias, incapacidad |
|---|---|---|---|
| Colaborador de sucursal (gestor, subgerente, coordinadora…) | Responsable explícito **o** gerente de su sucursal (titular/cobertura) → Regional (Q1/Q3 del gerente de su sucursal; sin clasificar, el de la matriz) → RH | Igual → RH autoriza (cargo/IVA a mano) | RH |
| Gerente de Sucursal | Regional de **su** clasificación Q1/Q3 (titular o cobertura) → RH. Sin clasificar: detenido «Pendiente de asignar región de aprobación» | Regional Q1/Q3 → Dirección Comercial → RH | RH |
| Gerente Regional, Gerente de Mesa de Control, Coordinadora Regional (bajo Dirección Comercial) | Explícito o jefe organizacional (Dirección Comercial) → RH | Jefe (Dirección Comercial) → RH (Dirección Comercial no se repite) | RH |
| Dirección Comercial | Explícito o jefe organizacional (Dirección General) → RH | Igual (nunca se aprueba a sí misma) | RH |
| RH, Contraloría, Sistemas (dependen de Dirección General) | Explícito o jefe organizacional (Dirección General) → RH. **Nunca** pasan por Dirección Comercial | Igual (sin Dirección Comercial) | RH |
| Sin jefe con cuenta activa | Directo a RH | Directo a RH | RH |

- **RH** es siempre la autorización final y solo puede actuar con todos los vistos buenos dados; el botón Aprobar se ve deshabilitado con el motivo («RH podrá aprobar cuando X dé su visto bueno.»). Quien da un visto bueno no da también la autorización final. Nadie aprueba su propia solicitud (super_admin sí, auditado).
- **Contraloría** no tiene paso de aprobación en solicitudes internas (recibe alertas de bajas, `docs/BAJAS_Y_CAUSALES.md`).
- **super_admin** autoriza como RH, pero también espera los vistos buenos.
- Si Dirección General no aprueba en la práctica (p. ej. las áreas que le reportan), RH asigna un **responsable explícito** a esas personas o a su puesto: el organigrama formal no cambia.

### Contrato API del flujo (API V1, para la app)

Cada solicitud (colaborador: `GET /api/v1/solicitudes`, `GET /api/v1/solicitudes/{id}`, respuesta del `POST`; RH: `GET /api/v1/rh/solicitudes` y `/{id}`; jefe: `GET /api/v1/equipo/pendientes`) trae, **calculado para la cuenta que consulta**:

```jsonc
{
  "flujo": [
    { "orden": 1, "clave": "creada", "rol": "Solicitante", "etiqueta": "Solicitud creada",
      "responsable": { "id": 15, "nombre": "Ana López" }, "responsables": [],
      "estado": "aprobado", "estado_etiqueta": "Enviada", "fecha": "2026-10-20T14:30:00-06:00",
      "comentario": null, "decidio": null, "bloqueado": false, "puede_actuar": false, "motivo_no_puede": null },
    { "orden": 2, "clave": "jefe_inmediato", "rol": "Gerente de sucursal", "etiqueta": "Gerente de sucursal",
      "responsable": { "id": 7, "nombre": "Alberto Carlos Bueno" }, "responsables": [{ "id": 7, "nombre": "Alberto Carlos Bueno" }],
      "estado": "pendiente", "estado_etiqueta": "Pendiente", "fecha": null, "comentario": null, "decidio": null,
      "bloqueado": false, "puede_actuar": false, "motivo_no_puede": "Este paso le corresponde a Alberto Carlos Bueno." },
    { "orden": 3, "clave": "regional", "estado": "esperando", "estado_etiqueta": "Esperando paso anterior",
      "motivo_no_puede": "Gerente Regional podrá dar su visto bueno cuando Alberto Carlos Bueno dé el suyo." },
    { "orden": 4, "clave": "rh", "rol": "Recursos Humanos", "etiqueta": "RH autoriza el préstamo", "responsable": null,
      "estado": "esperando", "motivo_no_puede": "RH podrá aprobar cuando Alberto Carlos Bueno dé su visto bueno." }
  ],
  "puede_actuar_usuario_actual": false,
  "accion_usuario_actual": null,
  "motivo_no_puede": "RH podrá aprobar cuando Alberto Carlos Bueno dé su visto bueno.",
  "pendiente_de": { "clave": "jefe_inmediato", "etiqueta": "Gerente de sucursal", "nombres": ["Alberto Carlos Bueno"],
                    "texto": "Pendiente de aprobación de: Alberto Carlos Bueno" },
  "estado_flujo_texto": "Pendiente de aprobación de: Alberto Carlos Bueno"
}
```

- `estado` de cada paso: `aprobado` · `pendiente` (le toca ahora) · `esperando` (espera un paso anterior) · `no_aplica` (la solicitud se canceló o rechazó antes) · `rechazado`. `bloqueado: true` en un paso `pendiente` = la estructura lo detiene («Pendiente de asignar región de aprobación», regional vacante sin cobertura): nadie actúa hasta que RH lo corrija en el Organigrama.
- `accion_usuario_actual`: `visto_bueno` · `aprobar` · `autorizar_prestamo` · `null`.
- `responsable.id`, `responsables[].id` y `decidio.id` son ids de **colaborador** (persona).
- `motivo_no_puede` es texto humano listo para mostrarse (nunca un mensaje técnico).
- La app **no decide**: muestra Aprobar/Rechazar grandes solo si `puede_actuar_usuario_actual` es `true`. El backend vuelve a validar (422 con el mismo texto humano).
- `GET /api/v1/equipo/pendientes` devuelve `solicitudes` (me toca ahora) y `en_espera` (me tocará un paso posterior: se muestran sin botón activo), más `meta.aprobaciones`.
- **Conteo único** de «mis aprobaciones pendientes» (badge web y app): `AprobacionOrganizacionalService::conteoPendientes(User)` → `{visto_bueno, autorizar, total}`; API `GET /api/v1/equipo/aprobaciones/conteo`. Solo cuenta lo que la cuenta puede aprobar AHORA (no lo que espera un paso anterior ni al colaborador).
- Visto bueno: `POST /api/v1/equipo/solicitudes/{id}/visto-bueno` `{aprobado, comentario}`. RH autoriza con los endpoints de siempre (`/rh/solicitudes/{id}/aprobar`, `/rh/solicitudes/{id}/prestamo/autorizar`).
- Se conservan las claves anteriores (`vistos_buenos`, `acciones_permitidas`, `prestamo.visto_bueno`); `vistos_buenos[]` suma `bloqueado` y `motivo_bloqueo`. `acciones_permitidas` ya no incluye `aprobar` mientras falten vistos buenos.

### Préstamos: 6 meses completos de antigüedad

Regla única: `AprobacionOrganizacionalService::elegibilidadPrestamo`, con `config('solicitudes.prestamo_meses_minimos') = 6`.

- Se puede pedir a partir de `fecha_ingreso + 6 meses` (sin desbordar fin de mes), con la **fecha del servidor**. Ingreso 20/10/2026 → disponible desde 20/04/2027.
- Sin fecha de ingreso capturada → no disponible («Pide a RH que la capture»).
- `SolicitudesService::crear()` la valida para web, API y app: **422** en `monto_solicitado` con «Disponible al cumplir 6 meses de antigüedad: a partir del dd/mm/aaaa.».
- Web: `Solicitudes/Index` recibe `elegibilidadPrestamo` y muestra la tarjeta «Préstamo» deshabilitada con la antigüedad y la fecha.

```jsonc
// GET /api/v1/solicitudes/prestamo/elegibilidad  → { "data": { ... } }
// GET /api/v1/solicitudes/configuracion          → tipos[clave=prestamo].disponible / .elegibilidad, y elegibilidad_prestamo
{
  "cumple": false, "meses_minimos": 6, "fecha_ingreso": "2026-05-08",
  "antiguedad": { "anios": 0, "meses": 5, "dias": 12 }, "antiguedad_texto": "5 meses 12 días",
  "disponible_desde": "2026-11-08", "disponible_desde_texto": "08/11/2026", "fecha_servidor": "2026-10-20",
  "mensaje": "Disponible al cumplir 6 meses de antigüedad: a partir del 08/11/2026."
}
```

El resto del flujo del préstamo no cambia: visto bueno de gerente/jefatura → (Dirección Comercial si el solicitante es de gerencia o superior bajo Dirección Comercial) → **RH autoriza** con captura manual de cargo/IVA (`PrestamoAutorizacionService`).

### Avisos de los pasos (solo a quien puede actuar)

| Momento | Quién recibe | Evento |
|---|---|---|
| Se crea una solicitud con vistos buenos | Solicitante (confirmación) y **solo** el responsable del primer paso. RH **no** recibe «por revisar» todavía | `solicitud_creada` (confirmación), `solicitud_visto_bueno` |
| Se crea una solicitud sin vistos buenos (correcciones, constancias…) | Solicitante y RH con alcance | `solicitud_creada` |
| Un paso intermedio da su visto bueno | Siguiente responsable (tarea) y solicitante («Tu solicitud avanzó») | `solicitud_visto_bueno`, `solicitud_paso_aprobado` |
| Último visto bueno | RH que puede autorizar (`quienesPuedenActuar`) y solicitante («Pendiente de autorizar») | `solicitud_lista_para_autorizar`, `solicitud_actualizada` |
| Un paso no da el visto bueno | Solicitante (rechazada, con el motivo) | `solicitud_actualizada` |
| Paso bloqueado por la estructura | Quien puede corregir el organigrama (`organigrama.editar` con alcance) | `solicitud_aprobacion_bloqueada` |

Si cambia el responsable (Organización → Responsables de aprobación) o la región Q1/Q3 de un gerente, las solicitudes abiertas se reencaminan (`TareasSolicitudService::reevaluarPendientes`): se cierran los pendientes de quien ya no responde y se avisa a quien ahora puede actuar.

### Responsables de aprobación (Organización)

`Administración → Organización → Responsables de aprobación` (`administracion.responsables-aprobacion.*`; ver `organigrama.ver`, asignar/quitar `organigrama.editar`):

- Asigna el responsable a **una persona** o a **todo un puesto**. El motivo es obligatorio.
- Validaciones: responsable activo con cuenta, nunca él mismo, nunca dos personas que se aprueben entre sí, nunca alguien del mismo puesto que aprueba.
- Auditoría en `activity_log` (log `organigrama`, eventos `responsable_aprobacion_asignado` / `_cambiado` / `_quitado`, con `antes`/`despues`, motivo, quién y cuándo).
- No toca el organigrama, el jefe organizacional ni headcount.
- Lista a cada persona con «Jefe organizacional» y «Responsable de aprobaciones» (y su origen) y alerta de quién tiene aprobaciones detenidas.
- El organigrama marca con un chip punteado «Aprueba: …» a quien tiene responsable explícito, y su panel lo explica.

## Correcciones solicitadas por el colaborador (2026-10-20)

Nadie edita su propio expediente (ni RH ni super admin): **pide una corrección** y otra persona la revisa. La edición directa de RH/super admin desde el expediente es otro flujo (`DatosExpedienteService`). Todo vive en `App\Services\Expedientes\CorreccionesService`. Lo usan la web («Mis solicitudes», el detalle de la solicitud y «Mi expediente») y la API móvil, así que las reglas no se duplican.

### Qué cuenta como corrección

| Origen | Qué es | Cómo se resuelve |
|---|---|---|
| `solicitud` | `SolicitudInterna` de tipo `actualizacion_datos`: datos personales, domicilio estructurado, contacto de emergencia, beneficiario y **datos médicos** (`condicion_medica`, `alergias`). También `actualizacion_bancaria` y `reposicion_documental` | RH aprueba, rechaza o pide información en la bandeja de Solicitudes. Al aprobar, `ActualizacionDatosService::aplicar()` pasa los valores al expediente |
| `documento` | Documento requerido del expediente que RH **rechazó** o **pidió corregir** | El colaborador lo vuelve a subir desde «Mi expediente» → Documentos |
| `foto` | Último cambio de foto de perfil (pendiente, o resuelto en los últimos 30 días) | RH lo aprueba o lo rechaza en «Cambios de foto» |

**Datos médicos.** Son cifrados y nunca van en el JSON en claro:

- Lo propuesto se guarda en `solicitudes_internas.datos_sensibles_propuestos` (`encrypted:array`, oculto al serializar). Lo que había antes se guarda en `datos_sensibles_anteriores`.
- `datos_base` solo marca `[registrado]`, nunca el valor.
- Solo el dueño o quien tiene `expedientes.datos_medicos.ver` ve el valor en el comparativo. Los demás ven «Dato confidencial».
- Los avisos dicen «Datos médicos (confidencial)», sin decir cuál dato.
- La auditoría registra que cambió, no el valor.
- Al aprobar, el valor se aplica a `colaborador_datos_medicos` con `fuente = solicitud_correccion`.

### Estados que ve el colaborador

`CorreccionesService::estado()` traduce `EstadoSolicitudInterna`:

| Estado del colaborador | `EstadoSolicitudInterna` |
|---|---|
| `enviada` | `creada`, `enviada` |
| `en_revision` | `en_revision` |
| `aprobada` | `aprobada` |
| `rechazada` | `rechazada` |
| `requiere_informacion` | `requiere_correccion` |
| `cancelada` | `cancelada` |

Un documento rechazado aparece como `rechazada` y uno con corrección pedida como `requiere_informacion`; en los dos casos `requiere_accion = true`. Una foto pendiente es `enviada` y una rechazada es `rechazada` con `requiere_accion = true`.

### Flujo

1. **Al crear la corrección** (`SolicitudesService::crear()`):
   - El colaborador recibe una confirmación («Cambio de datos enviado»).
   - Los responsables reciben un aviso persistente «Corrección de datos por revisar» que dice qué datos se piden, nunca sus valores. El enlace abre `rh.solicitudes.show`.
   - Se abre un pendiente `TipoTarea::CorreccionPorRevisar` en la bandeja.
   - Las correcciones no requieren visto bueno de jefe (`config/solicitudes.php → visto_bueno_jefe`): las revisa directamente Recursos Humanos. Los responsables salen de la regla `solicitud_creada` de Configuración → Notificaciones: `rh.solicitudes.aprobar` con alcance sobre la persona, sin el solicitante. **Ese único punto de integración es `CorreccionesService::responsables()`.** Si el motor central llega a definir otro responsable para correcciones, solo cambia ese método.
2. **RH pide información** (`requerirCorreccion()` con comentario):
   - El colaborador recibe «Recursos Humanos necesita más información» con el comentario de RH, en la web y por push.
   - El pendiente de RH se cierra y se abre `CorreccionPorResponder` para el colaborador. Ese pendiente alimenta el contador de su bandeja y el `counts.tasks` de la app.
3. **El colaborador responde** (`CorreccionesService::responder()`, web `POST /solicitudes/{id}/responder`, API `POST /api/v1/solicitudes/{id}/responder`):
   - Lleva un mensaje obligatorio y, opcionalmente, `datos` con los valores propuestos corregidos (mismas reglas que al crear).
   - La solicitud vuelve a `enviada` (transición `requiere_correccion → enviada` en `EstadoSolicitudInterna`). En el historial quedan `respuesta_colaborador` (y `datos_corregidos` si cambió datos) y luego `enviada`.
   - Los responsables y quien pidió la información reciben un aviso **nuevo**, «Corrección respondida por revisar». La ocurrencia es la del historial, así que la idempotencia no lo absorbe.
   - El pendiente de RH se reabre y se cierra el del colaborador.
4. **RH resuelve** (aprobar o rechazar):
   - El colaborador recibe «Corrección aprobada» («tu expediente ya está actualizado») o «Corrección rechazada. Motivo: …», en la campana web y por push.
   - Ambos pendientes se cierran.

### Auditoría de cada movimiento

- **`solicitud_interna_historial`**:
  - `user_id` (quién), `created_at` (cuándo) y `comentario`.
  - `origen`: `web`, `app` o `sistema` (`App\Support\OrigenPeticion`).
  - `ip` y `user_agent`, ocultos al serializar.
- **`solicitudes_internas`**:
  - `revisado_por` y `revisado_en`: quién resolvió y cuándo.
  - `datos_base`: lo que había al solicitar.
  - `datos_propuestos`: el dato nuevo.
  - `datos_anteriores`: lo que había justo antes de aplicar.
- **`activity_log`**, evento `expediente_datos_actualizados`:
  - `cambios` por campo, con `{antes, despues}` (médicos: «Dato confidencial»).
  - El comentario con que RH aprobó y `modulo = expediente`.
  - `origen`, IP y user agent, que agrega `AuditoriaService`.
- **`activity_log`**, evento `correccion_respondida`: cuando el colaborador responde.

### API de correcciones (app móvil, Sanctum)

**`GET /api/v1/correcciones`**: las correcciones propias. Primero las que requieren acción, luego las que están en proceso, luego las resueltas (máximo 20 resueltas).

```json
{
  "data": [{
    "id": "solicitud-12",            // clave única: solicitud-{id} | documento-{id} | foto-{id}
    "origen": "solicitud",           // solicitud | documento | foto
    "recurso_id": 12,
    "folio": "SOL-000012",           // null en documento/foto
    "tipo": "actualizacion_datos",   // tipo de solicitud | documento | foto_perfil
    "titulo": "Solicitar cambio de información",
    "estado": "requiere_informacion",// enviada | en_revision | aprobada | rechazada | requiere_informacion | cancelada
    "estado_etiqueta": "Requiere información",
    "estado_descripcion": "Recursos Humanos necesita más información: respóndele para que siga su revisión.",
    "comentario_rh": "La CURP no coincide con tu acta.",
    "requiere_accion": true,         // el colaborador debe hacer algo (responder / volver a subir)
    "puede_responder": true,         // muestra el formulario de respuesta (solo solicitudes en requiere_informacion)
    "campos": ["CURP"],              // etiquetas, nunca valores; médicos = "Datos médicos (confidencial)"
    "resuelta_por": "Ana López", "resuelta_en": "2026-10-20T14:30:00-06:00",
    "creada_en": "...", "actualizada_en": "...",
    "url": "/solicitudes/12",        // pantalla web equivalente
    "api": { "detalle": "/api/v1/solicitudes/12", "responder": "/api/v1/solicitudes/12/responder" },
    "historial": [{ "accion": "enviada", "etiqueta": "Enviada a Recursos Humanos", "por": "Juan Pérez", "es_rh": false,
                    "comentario": null, "origen": "app", "origen_etiqueta": "App móvil", "en": "..." }]
  }],
  "meta": { "conteo": { "requieren_accion": 1, "en_proceso": 0, "abiertas": 1 } }
}
```

**Otros endpoints:**

- `GET /api/v1/correcciones/resumen` regresa `{ "data": { "requieren_accion", "en_proceso", "abiertas" } }`, para el badge.
- `GET /api/v1/mobile/bootstrap` agrega `counts.correcciones_pendientes` (= `requieren_accion`) y `counts.correcciones_abiertas`.
- `GET /api/v1/solicitudes/{id}` agrega `correccion` (mismo objeto, o `null` si no es corrección) e `historial`.
- `POST /api/v1/solicitudes/{id}/responder`:
  - Cuerpo: `{ "mensaje": "…", "datos": { "curp": "…" } }`; `datos` es opcional.
  - 200: `{ message, data: <corrección> }`.
  - 403: no es tu solicitud.
  - 422: `mensaje` vacío, o la solicitud no está en `requiere_informacion` (mensaje humano en `errors.mensaje`), o datos inválidos (`errors["datos.curp"]`…).
  - Throttle de 20 por minuto.
- **Push:** `data.type = "solicitud"` y `resource_id` = id de la solicitud, para abrir el detalle. Los documentos usan `type = "documento"`.
