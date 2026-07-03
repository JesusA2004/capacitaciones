# Modelo de Datos — Portal de Capacitación Mr. Lana

Este documento se amplía en cada fase. Cubre únicamente las entidades ya migradas.

## Fase 1 — Estructura organizacional y permisos

### `users` (tabla base + campos de perfil de colaborador agregados en Fase 1)

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | Nombre(s) |
| `apellidos` | string, nullable | |
| `numero_empleado` | string, nullable, único | |
| `email` | string, único | |
| `telefono` | string, nullable | |
| `foto_path` | string, nullable | Ruta lógica; la carga real llega en la Fase 3 (biblioteca multimedia) |
| `sucursal_principal_id` | FK nullable → `sucursales.id` | `nullOnDelete` |
| `departamento_id` | FK nullable → `departamentos.id` | `nullOnDelete` |
| `puesto_id` | FK nullable → `puestos.id` | `nullOnDelete` |
| `jefe_id` | FK nullable → `users.id` (auto-referencia) | `nullOnDelete` |
| `fecha_ingreso` | date, nullable | |
| `estatus` | string, default `activo` | Cast a enum `App\Enums\EstadoUsuario` (`activo`/`inactivo`/`suspendido`) |
| `ultimo_acceso` | timestamp, nullable | |
| `zona_horaria` | string, default `America/Mexico_City` | |
| `preferencias_notificaciones` | json, nullable | Cast a array |
| `deleted_at` | timestamp, nullable | Soft delete: "desactivar" un colaborador conserva su historial |
| `password`, `email_verified_at`, `two_factor_*`, `remember_token`, timestamps | — | Del starter kit / Fortify |

Relaciones en el modelo `User`: `sucursalPrincipal()` (belongsTo), `sucursalesAdicionales()` (belongsToMany vía `sucursal_user`), `departamento()`, `puesto()`, `jefe()` (belongsTo self), `subordinados()` (hasMany self). Trait `HasRoles` (Spatie) y `LogsActivity` (Spatie Activitylog, solo campos de identidad/organización).

### `sucursales`

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `nombre` | string | |
| `clave` | string, único | Código corto (p. ej. `MTY01`) |
| `direccion`, `ciudad`, `estado`, `telefono` | string, nullable | |
| `responsable_id` | FK nullable → `users.id` | Responsable de sucursal |
| `activo` | boolean, default true | |
| `deleted_at` | soft delete | |

Nota técnica: el modelo `Sucursal` declara `protected $table = 'sucursales'` explícitamente porque la pluralización automática de Eloquent no reconoce el plural correcto en español.

### `departamentos`

`id`, `nombre`, `descripcion` (nullable), `activo` (default true), soft delete.

### `puestos`

`id`, `nombre`, `departamento_id` (FK nullable → `departamentos.id`, `nullOnDelete`), `descripcion` (nullable), `activo` (default true), soft delete.

### `sucursal_user` (pivote)

Sucursales adicionales autorizadas para un colaborador, además de su sucursal principal.

| Columna | Tipo |
|---|---|
| `user_id` | FK → `users.id`, `cascadeOnDelete` |
| `sucursal_id` | FK → `sucursales.id`, `cascadeOnDelete` |

Índice único compuesto `(user_id, sucursal_id)` para evitar duplicados.

### Tablas de Spatie Permission (publicadas y ajustadas)

`permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`. Las columnas `name`/`guard_name` de `permissions` y `roles` se acortaron a `varchar(125)` (en vez de los 255 por defecto) para evitar el error de MariaDB "1071 Specified key too long" en el índice único compuesto `(name, guard_name)` con `utf8mb4`.

### Tablas de Spatie Activitylog

`activity_log` (con las columnas `event` y `batch_uuid` agregadas por sus migraciones de actualización).

## Enums

- `App\Enums\EstadoUsuario`: `Activo` (`activo`), `Inactivo` (`inactivo`), `Suspendido` (`suspendido`). Método `etiqueta()` para el texto mostrado en la interfaz.

## Catálogo de permisos (sembrados desde la Fase 1)

Ver `database/seeders/RolesYPermisosSeeder.php` para el listado completo (32 permisos, sección 9 del encargo) y el mapa inicial rol → permisos. El mapa es editable después desde **Administración → Roles y permisos**; lo sembrado es solo el punto de partida.

## Fase 2 — Cursos, asignaciones y progreso

### `cursos`

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `titulo` | string | |
| `descripcion`, `objetivo` | text, nullable | |
| `imagen_portada_path` | string, nullable | Ruta lógica; carga real en Fase 3 |
| `duracion_estimada_minutos` | unsignedInteger, nullable | |
| `estado` | string, default `borrador` | Cast a `App\Enums\EstadoCurso` (`borrador`/`publicado`/`archivado`) |
| `disponible_desde`, `disponible_hasta` | datetime, nullable | |
| `calificacion_minima` | unsignedTinyInteger, nullable | Porcentaje 0-100 |
| `intentos_maximos` | unsignedInteger, nullable | `null` = sin límite |
| `requiere_orden` | boolean, default true | Bloquea lecciones fuera de secuencia |
| `genera_constancia` | boolean, default false | |
| `alcance_global` | boolean, default true | |
| `etiquetas` | json, nullable | Lista simple de strings (no requiere tabla propia) |
| `responsable_id` | FK nullable → `users.id` | `nullOnDelete` |
| `publicado_en` | timestamp, nullable | |
| `deleted_at` | soft delete | |

Nota técnica: igual que `Sucursal`, el modelo `Curso` **no** necesitó `$table` explícito (Eloquent adivina `cursos` correctamente), pero `Leccion` y `Asignacion` sí lo requieren (ver abajo).

### `curso_modulos`

`id`, `curso_id` (FK, `cascadeOnDelete`), `titulo`, `descripcion` (nullable), `orden` (unsignedInteger).

### `lecciones`

| Columna | Tipo | Notas |
|---|---|---|
| `curso_modulo_id` | FK → `curso_modulos.id`, `cascadeOnDelete` | |
| `titulo` | string | |
| `tipo` | string | Cast a `App\Enums\TipoLeccion`: `video`, `documento`, `guia`, `texto`, `enlace`, `cuestionario`, `actividad`, `sesion_en_vivo`, `confirmacion`. Solo los primeros 5 + `confirmacion` tienen constructor en la interfaz por ahora |
| `contenido` | longText, nullable | Texto libre o descripción del recurso |
| `url` | string, nullable | Requerido cuando `tipo = enlace` |
| `obligatoria` | boolean, default true | |
| `orden` | unsignedInteger | Orden dentro del módulo |
| `duracion_estimada_minutos` | unsignedInteger, nullable | |
| `deleted_at` | soft delete | |

Nota técnica: el modelo `Leccion` requiere `protected $table = 'lecciones'` explícito (mismo problema de pluralización en español que `Sucursal`/`Asignacion`).

### `requisitos_leccion` (pivote, grafo de prerrequisitos entre lecciones)

`leccion_id`, `requisito_leccion_id` (ambas FK → `lecciones.id`, `cascadeOnDelete`), único compuesto. Independiente del orden secuencial simple: permite exigir explícitamente "la lección C requiere A y B".

### `curso_requisito_previo` (pivote, cursos requisito)

`curso_id`, `requisito_curso_id` (ambas FK → `cursos.id`, `cascadeOnDelete`), único compuesto.

### `asignaciones`

| Columna | Tipo | Notas |
|---|---|---|
| `nombre` | string | |
| `asignable_type`, `asignable_id` | morph | Polimórfico: en esta fase solo `App\Models\Curso`, preparado para lecciones/cuestionarios/actividades/sesiones en fases futuras sin migrar de nuevo |
| `responsable_id` | FK → `users.id`, `cascadeOnDelete` | |
| `fecha_inicio`, `fecha_limite` | datetime, nullable | |
| `obligatoria` | boolean, default true | |
| `recordatorios`, `reglas` | json, nullable | Buckets flexibles para reglas de negocio futuras (Fase 7) |
| `activa` | boolean, default true | |
| `cancelada_en` | timestamp, nullable | |

Nota técnica: requiere `protected $table = 'asignaciones'` explícito.

### `asignacion_destinos`

`asignacion_id` (FK, `cascadeOnDelete`), `tipo_destino` (cast a `App\Enums\TipoDestinoAsignacion`: `usuario`/`sucursal`/`departamento`/`puesto`/`rol`/`todos`), `destino_id` (nullable, `null` solo cuando `tipo_destino = todos`). Único compuesto `(asignacion_id, tipo_destino, destino_id)`.

### `asignaciones_usuario`

Materialización idempotente por usuario. `asignacion_id`, `user_id` (ambas FK, `cascadeOnDelete`), `estado` (cast a `App\Enums\EstadoAsignacion`: `pendiente`/`en_progreso`/`completada`/`vencida`/`cancelada`), `fecha_limite` (copia de la asignación al momento de materializar), `completado_en`. Único compuesto `(asignacion_id, user_id)` — es la clave real que evita duplicados al re-materializar.

### `inscripciones_curso`

`user_id`, `curso_id` (FK, `cascadeOnDelete`), `asignacion_usuario_id` (FK nullable → `asignaciones_usuario.id`, `nullOnDelete`; conserva de qué asignación proviene la inscripción), `estado` (cast a `App\Enums\EstadoProgreso`: `pendiente`/`en_progreso`/`completada`), `iniciado_en`, `completado_en`, `calificacion_final` (unsignedTinyInteger, nullable). Único compuesto `(user_id, curso_id)`.

### `progreso_lecciones`

`user_id`, `leccion_id` (FK, `cascadeOnDelete`), `estado` (cast a `App\Enums\EstadoProgreso`), `iniciado_en`, `completado_en`. Único compuesto `(user_id, leccion_id)`.

## Enums (Fase 2)

- `App\Enums\EstadoCurso`: `borrador` / `publicado` / `archivado`.
- `App\Enums\TipoLeccion`: `video` / `documento` / `guia` / `texto` / `enlace` / `cuestionario` / `actividad` / `sesion_en_vivo` / `confirmacion`.
- `App\Enums\EstadoAsignacion`: `pendiente` / `en_progreso` / `completada` / `vencida` / `cancelada`.
- `App\Enums\TipoDestinoAsignacion`: `usuario` / `sucursal` / `departamento` / `puesto` / `rol` / `todos`.
- `App\Enums\EstadoProgreso`: `pendiente` / `en_progreso` / `completada`.

## Fase 3 — Biblioteca multimedia, NAS, FFmpeg, HLS y control de avance

### `recursos_multimedia`

| Columna | Tipo | Notas |
|---|---|---|
| `tipo` | string | Cast a `App\Enums\TipoRecursoMultimedia`: `video` / `documento` / `imagen` |
| `nombre_original` | string | Nombre tal como lo subió el usuario (solo para mostrar) |
| `nombre_interno` | string, único | UUID + extensión; es la base real de las rutas en disco, nunca el nombre original |
| `disco` | string | Siempre `nas` en la práctica (valor de `config('media.disk')` al momento de subir) |
| `ruta_original`, `ruta_hls_manifiesto`, `ruta_miniatura` | string, nullable | Rutas lógicas dentro del disco `nas`; nunca se exponen directamente al frontend |
| `mime_type` | string, nullable | |
| `tamano_bytes`, `duracion_segundos` | unsignedInteger/unsignedBigInteger, nullable | `duracion_segundos` lo llena `FfmpegService::inspeccionar()` |
| `resolucion_original` | string, nullable | Formato `"{ancho}x{alto}"` |
| `hash_sha256` | string, nullable | |
| `estado` | string | Cast a `App\Enums\EstadoMultimedia`: `cargando` / `pendiente` / `procesando` / `disponible` / `error` |
| `error_procesamiento` | text, nullable | Mensaje capturado cuando `ProcesarVideoJob` falla |
| `metadatos` | json, nullable | Cast a array; bucket flexible para datos adicionales de FFprobe |
| `subido_por` | FK → `users.id` | |
| `deleted_at` | soft delete | |

### `cargas_multimedia`

Progreso de una carga en curso: `user_id`, `recurso_multimedia_id` (FK nullable, `nullOnDelete`), `nombre_original`, `ruta_temporal` (nullable), `tamano_total_bytes`, `bytes_recibidos`, `estado` (string, default `cargando`), `error` (text, nullable). En esta fase la subida real se hace en una sola petición con `useForm({ forceFormData: true })` de Inertia (que ya reporta progreso vía `onProgress`), así que esta tabla queda lista para un futuro flujo de carga por partes (`chunked upload`) sin necesitar otra migración.

### Columna agregada a `lecciones`

`recurso_multimedia_id`: FK nullable → `recursos_multimedia.id`, `nullOnDelete`. Une una lección de tipo `video`/`documento` con el archivo ya cargado en la biblioteca.

### `sesiones_reproduccion`

Una fila por cada vez que el reproductor HLS se monta para una lección de video. `user_id`, `leccion_id`, `recurso_multimedia_id` (FK, `cascadeOnDelete`), `ip_address`, `user_agent`, `iniciada_en`, `ultimo_heartbeat_en` (nullable), `ultima_posicion_segundos` (unsignedInteger, default 0 — última posición reportada por el cliente en esta sesión), `finalizada_en` (nullable), `completada` (boolean, default false).

Nota de diseño: esta tabla es un registro/ancla por ejecución del reproductor, **no** la fuente de verdad del avance permitido. El avance real y único visto se calcula siempre a partir de `intervalos_video_vistos`, para que el progreso sobreviva a que el usuario cierre el reproductor y lo reabra otro día (la sesión nueva simplemente retoma desde el punto ya alcanzado).

### `intervalos_video_vistos`

`user_id`, `leccion_id` (FK, `cascadeOnDelete`), `inicio_segundo`, `fin_segundo` (ambas unsignedInteger). `ReproduccionVideoService` mantiene estos tramos siempre fusionados (sin solapes ni duplicados) cada vez que llega un heartbeat válido. De aquí salen tanto el porcentaje único visto (para completar la lección) como el "segundo máximo permitido" (para el anti-adelanto). No existe una tabla adicional de eventos crudos por heartbeat: los tramos fusionados ya son un registro suficiente y evitan una tabla que crecería sin límite.

## Enums (Fase 3)

- `App\Enums\TipoRecursoMultimedia`: `video` / `documento` / `imagen`.
- `App\Enums\EstadoMultimedia`: `cargando` / `pendiente` / `procesando` / `disponible` / `error`.

## Fase 4 — Banco de preguntas, cuestionarios y actividades

### `bancos_preguntas`

`id`, `nombre`, `descripcion` (nullable), `creado_por` (FK → `users.id`, `cascadeOnDelete`), soft delete. Un banco agrupa preguntas reutilizables entre varios cuestionarios.

### `preguntas`

| Columna | Tipo | Notas |
|---|---|---|
| `banco_pregunta_id` | FK → `bancos_preguntas.id`, `cascadeOnDelete` | |
| `enunciado` | text | |
| `tipo` | string | Cast a `App\Enums\TipoPregunta`: `opcion_unica` / `opcion_multiple` / `verdadero_falso` / `respuesta_corta` |
| `puntos` | unsignedInteger, default 1 | |
| `explicacion` | text, nullable | Retroalimentación mostrada tras calificar, si el cuestionario lo permite |
| `deleted_at` | soft delete | |

### `opciones_pregunta`

`pregunta_id` (FK, `cascadeOnDelete`), `texto`, `es_correcta` (boolean, default false), `orden` (unsignedInteger). Se reemplazan por completo cada vez que se edita una pregunta (no hay diff parcial).

### `cuestionarios`

1:1 con una `Leccion` de tipo `cuestionario` (mismo patrón que `RecursoMultimedia` con las lecciones de video).

| Columna | Tipo | Notas |
|---|---|---|
| `leccion_id` | FK único → `lecciones.id`, `cascadeOnDelete` | |
| `titulo`, `instrucciones` | string / text nullable | |
| `calificacion_minima` | unsignedTinyInteger, default 80 | Porcentaje 0-100 |
| `intentos_maximos` | unsignedInteger, nullable | `null` = sin límite |
| `tiempo_limite_minutos` | unsignedInteger, nullable | Se muestra al colaborador; no se aplica como corte estricto en el servidor |
| `aleatorizar_preguntas` | boolean, default false | El orden aleatorio se recalcula en cada carga de página, no se persiste por intento |
| `mostrar_retroalimentacion` | boolean, default true | |

### `cuestionario_pregunta` (pivote)

`cuestionario_id`, `pregunta_id` (FK, `cascadeOnDelete`), `orden` (unsignedInteger), `puntos` (unsignedInteger, nullable — sobreescribe los puntos de la pregunta solo para este cuestionario). Único compuesto `(cuestionario_id, pregunta_id)`.

### `intentos_cuestionario`

`cuestionario_id`, `user_id` (FK, `cascadeOnDelete`), `numero_intento`, `estado` (cast a `App\Enums\EstadoIntentoCuestionario`: `en_progreso` / `enviado` / `calificado`), `iniciado_en`, `enviado_en`, `calificado_en` (nullable), `calificacion` (unsignedTinyInteger, nullable), `aprobado` (boolean, nullable). Único compuesto `(cuestionario_id, user_id, numero_intento)` con nombre de índice acortado explícitamente (`intentos_cuestionario_unico`; el autogenerado por Laravel excedía los 64 caracteres de MariaDB, mismo problema que las columnas de Spatie Permission en la Fase 1).

### `respuestas_cuestionario`

`intento_cuestionario_id`, `pregunta_id` (FK, `cascadeOnDelete`), `opcion_pregunta_id` (FK nullable, `nullOnDelete` — respuesta de opción única/verdadero-falso), `opciones_seleccionadas` (json, nullable — array de IDs para opción múltiple), `respuesta_texto` (text, nullable — respuesta corta), `es_correcta` (boolean, nullable: `null` mientras no se ha calificado), `puntos_obtenidos` (unsignedInteger, nullable). Único compuesto `(intento_cuestionario_id, pregunta_id)` con nombre acortado (`respuestas_cuestionario_unico`).

### `actividades`

1:1 con una `Leccion` de tipo `actividad`. `leccion_id` (FK único, `cascadeOnDelete`), `titulo`, `instrucciones` (nullable), `tipo_entrega` (cast a `App\Enums\TipoEntregaActividad`: `archivo` / `texto` / `enlace`), `calificacion_minima` (unsignedTinyInteger, default 80), `fecha_limite` (dateTime, nullable).

### `entregas_actividad`

Cada reenvío (tras un rechazo) crea una fila nueva con `version` incrementada; esta tabla **es** su propio historial, no existe una tabla de historial de entregas por separado. `actividad_id`, `user_id` (FK, `cascadeOnDelete`), `version` (unsignedInteger, default 1), `contenido_texto` (text, nullable), `url` (string, nullable), `recurso_multimedia_id` (FK nullable → `recursos_multimedia.id`, `nullOnDelete` — reutiliza el almacenamiento de la Fase 3 en vez de un mecanismo propio), `estado` (cast a `App\Enums\EstadoEntregaActividad`: `entregada` / `aprobada` / `rechazada`), `calificacion` (unsignedTinyInteger, nullable), `retroalimentacion` (text, nullable), `entregado_en`, `calificado_en` (nullable), `calificado_por` (FK nullable → `users.id`, `nullOnDelete`). Único compuesto `(actividad_id, user_id, version)`.

## Enums (Fase 4)

- `App\Enums\TipoPregunta`: `opcion_unica` / `opcion_multiple` / `verdadero_falso` / `respuesta_corta`.
- `App\Enums\EstadoIntentoCuestionario`: `en_progreso` / `enviado` / `calificado`.
- `App\Enums\TipoEntregaActividad`: `archivo` / `texto` / `enlace`.
- `App\Enums\EstadoEntregaActividad`: `entregada` / `aprobada` / `rechazada`.

## Fase 5 — Sesiones en vivo y asistencias

### `sesiones_en_vivo`

1:1 con una `Leccion` de tipo `sesion_en_vivo` (mismo patrón que `RecursoMultimedia`/`Cuestionario`/`Actividad`).

| Columna | Tipo | Notas |
|---|---|---|
| `leccion_id` | FK único → `lecciones.id`, `cascadeOnDelete` | |
| `titulo`, `descripcion` | string / text nullable | |
| `proveedor` | string | Cast a `App\Enums\ProveedorSesion`: `manual` / `google_meet` / `zoom` |
| `fecha_inicio` | dateTime | |
| `duracion_minutos` | unsignedInteger, default 60 | |
| `enlace_reunion` | string, nullable | Escrito a mano (proveedor `manual`) o generado por la integración |
| `id_reunion_externa` | string, nullable | ID del evento de Calendar o de la reunión de Zoom, para poder cancelarla después |
| `datos_proveedor` | json, nullable | Respuesta cruda relevante de la API externa (cast a array) |
| `estado` | string | Cast a `App\Enums\EstadoSesionEnVivo`: `programada` / `en_curso` / `finalizada` / `cancelada` |
| `creado_por` | FK → `users.id`, `cascadeOnDelete` | |

### `asistencias`

Se materializa una fila en estado `pendiente` por cada colaborador inscrito en el curso al momento de programar la sesión (`AsistenciaService::materializarParaSesion()`).

| Columna | Tipo | Notas |
|---|---|---|
| `sesion_en_vivo_id`, `user_id` | FK, `cascadeOnDelete` | |
| `estado` | string, default `pendiente` | Cast a `App\Enums\EstadoAsistencia`: `pendiente` / `presente` / `ausente` / `tarde` |
| `unido_en`, `salido_en` | timestamp, nullable | Reservados para un futuro registro automático por join/leave del proveedor; no se usan en esta fase (la toma de asistencia es manual) |
| `duracion_segundos` | unsignedInteger, nullable | Idem |
| `corregido_por` | FK nullable → `users.id`, `nullOnDelete` | Solo se llena cuando se corrige una asistencia que ya tenía un estado definitivo |
| `motivo_correccion` | text, nullable | Obligatorio en la petición de corrección (validado en el controlador, no a nivel de base de datos) |

Único compuesto `(sesion_en_vivo_id, user_id)` con nombre acortado (`asistencias_unico`, mismo problema recurrente de límite de 64 caracteres en MariaDB).

## Enums (Fase 5)

- `App\Enums\ProveedorSesion`: `manual` / `google_meet` / `zoom`.
- `App\Enums\EstadoSesionEnVivo`: `programada` / `en_curso` / `finalizada` / `cancelada`.
- `App\Enums\EstadoAsistencia`: `pendiente` / `presente` / `ausente` / `tarde`.

## Fase 6 — Dashboards, reportes y exportaciones

No se agregaron tablas nuevas en esta fase: los dashboards y el reporte de cumplimiento son enteramente de lectura/agregación sobre datos ya existentes (`asignaciones_usuario`, `inscripciones_curso`, `sesiones_en_vivo`, `sucursales`, `departamentos`), calculados en `App\Services\Reportes\{MetricasDashboardService,ReporteCumplimientoService}`. La exportación a Excel (`App\Exports\CumplimientoExport`, paquete `maatwebsite/excel`) tampoco introduce almacenamiento: genera el archivo al vuelo a partir de la misma consulta que la pantalla, sin persistir el resultado en `exportaciones_reporte` (esa tabla, mencionada en el encargo, queda pendiente para si se necesita un historial de exportaciones generadas; por ahora cada exportación se descarga directamente sin dejar rastro en base de datos).

## Fase 7 — Notificaciones, calendario y constancias

### `notifications`

Tabla estándar del sistema de notificaciones de Laravel (`php artisan notifications:table`): `id` (UUID), `type` (clase de la notificación), `notifiable_type`/`notifiable_id` (morph, siempre `User` en este proyecto), `data` (json con `tipo`/`titulo`/`mensaje`/`url`, ver cada clase en `app/Notifications/`), `read_at` (nullable). No se agregó ninguna tabla propia de notificaciones: la estándar de Laravel ya cubre el requisito de "campana" + historial.

### Columnas agregadas para recordatorios idempotentes

- `asignaciones_usuario.recordatorio_enviado_en` (timestamp, nullable): evita que `capacitacion:recordar-fechas-limite` notifique dos veces por la misma fecha límite.
- `sesiones_en_vivo.recordatorio_enviado_en` (timestamp, nullable): mismo propósito para `capacitacion:recordar-sesiones-proximas`.

### `certificados`

| Columna | Tipo | Notas |
|---|---|---|
| `folio` | string(20), único | Formato `MRL-XXXXXXXX`, pensado para escribirse a mano al verificar |
| `user_id` | FK → `users.id`, `cascadeOnDelete` | |
| `curso_id` | FK → `cursos.id`, `cascadeOnDelete` | |
| `inscripcion_curso_id` | FK único → `inscripciones_curso.id`, `cascadeOnDelete` | El índice único evita emitir dos constancias para la misma inscripción |
| `emitido_en` | timestamp | |

## Fase 8 — Seguridad y rendimiento

No se agregaron entidades nuevas: la auditoría de seguridad se resolvió con verificaciones en los controladores (ver `docs/SEGURIDAD.md`), no con cambios de esquema. Sí se agregaron índices sobre columnas ya existentes que las consultas de dashboards/reportes/recordatorios usaban con frecuencia sin tenerlas indexadas:

- `asignaciones_usuario`: índice compuesto `(estado, fecha_limite)`.
- `sesiones_en_vivo`: índice compuesto `(estado, fecha_inicio)`.
- `intentos_cuestionario`: índice en `estado`.
- `entregas_actividad`: índice en `estado`.

## Entidades pendientes (fases futuras)

`sesiones_participante`, `conexiones_integracion`, `webhooks_recibidos`, `sincronizaciones_reunion`, `anuncios`, `configuraciones`, `exportaciones_reporte`, `audit_logs` (nota: la auditoría de esta fase usa `activity_log` de Spatie; se evaluará si `audit_logs` es una tabla adicional específica de negocio o si Activitylog cubre el requisito si se retoma este proyecto para nuevas fases).
