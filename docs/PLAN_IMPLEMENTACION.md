# Plan de Implementación — Portal de Capacitación Mr. Lana

Este documento es la bitácora viva del proyecto. Se actualiza al cerrar cada fase con lo terminado, lo pendiente, las decisiones tomadas y los archivos principales tocados. No se elimina información de fases anteriores.

Leyenda de estado: ✅ Terminado · 🔄 En proceso · ⏳ Pendiente · 🚫 Bloqueado

## Resumen de fases

| Fase | Contenido | Estado |
|---|---|---|
| 1 | Idioma, diseño base, roles/permisos, estructura organizacional | ✅ Terminada |
| 2 | Cursos, módulos, lecciones, asignaciones, progreso | ✅ Terminada |
| 3 | Biblioteca multimedia, NAS, FFmpeg, HLS, reproductor | ✅ Terminada |
| 4 | Banco de preguntas, cuestionarios, actividades, calificación | ✅ Terminada |
| 5 | Sesiones en vivo (manual, Google Meet, Zoom), asistencias | ✅ Terminada |
| 6 | Dashboards, gráficas, reportes, exportaciones | ✅ Terminada |
| 7 | Notificaciones, correos, calendario, constancias | ✅ Terminada |
| 8 | Seguridad, rendimiento, pruebas finales, documentación final | ✅ Terminada |

---

## Fase 1 — Idioma, diseño base, roles y estructura organizacional ✅

### Alcance cubierto

- **Idioma**: `APP_LOCALE=es`, `lang/es/{auth,validation,passwords,pagination}.php`, `lang/es.json` (traduce strings de framework usados por las notificaciones nativas de reset de contraseña/verificación de correo), toda la UI de autenticación y settings traducida al español mexicano.
- **Registro público deshabilitado**: se decidió que los colaboradores no se auto-registran (sección 28 del encargo: "usa invitación o enlace de establecimiento de contraseña"). Se removieron la ruta/página de registro de Fortify y se implementó creación de usuarios vía invitación (ver Fase 1 — Usuarios).
- **Diseño de marca**: variables CSS semánticas en `resources/css/app.css` (`--brand-primary`, `--brand-secondary`, `--brand-foreground`, `--success`, `--warning`, `--danger`), mapeadas sobre las variables shadcn existentes (`--primary`, `--sidebar-primary`, `--ring`) en modo claro y oscuro. Paleta aproximada dada por el usuario (no fue posible extraer la paleta real de mr-lana.com porque el fetch solo devolvió texto plano, sin CSS).
- **Roles y permisos**: Spatie Laravel Permission. 7 roles (`super_admin`, `administrador_capacitacion`, `instructor`, `gerente_sucursal`, `supervisor`, `colaborador`, `auditor`) y el catálogo completo de permisos de la sección 9 del encargo (incluye permisos de fases futuras, para no tener que re-sembrar por módulo). Pantalla completa de administración de roles (crear/editar/clonar/asignar permisos, ver conteo de usuarios).
- **Auditoría**: Spatie Activitylog en `User` y `Sucursal` (`logOnlyDirty`), listo para ampliarse a más modelos en fases siguientes.
- **Estructura organizacional**: `Sucursal`, `Departamento`, `Puesto`, perfil extendido de `User` (apellidos, número de empleado, teléfono, foto, sucursal principal + adicionales, departamento, puesto, jefe, fecha de ingreso, estatus, zona horaria, preferencias de notificaciones). CRUD completo con Inertia + DataTable reutilizable.
- **Aislamiento por sucursal**: `App\Services\AlcanceOrganizacionalService` centraliza qué sucursales/usuarios puede ver cada rol; se usa tanto en Policies como en el scoping de las consultas del listado de colaboradores (autorización real en backend, no solo oculta en el frontend).
- **Usuarios por invitación**: `UsuarioController::store` crea el usuario con una contraseña aleatoria irrecuperable y reutiliza el broker de "recuperar contraseña" de Fortify (`Password::sendResetLink`) para que el colaborador establezca su propia contraseña por correo. Nunca se envían contraseñas en texto plano.
- **Frontend**: `useAlertas` (SweetAlert2, único punto de entrada para confirmaciones/avisos modales), `usePermisos`, `useFiltros`, `usePaginacion`, `Components/DataTable/DataTable.vue` (tabla genérica con paginación/orden/filtrado server-side reutilizada en las 4 pantallas de administración), `Components/Common/EmptyState.vue`.
- **Base de datos de desarrollo**: se migró de SQLite a MariaDB (WAMP), reflejando el motor real de producción.

### Decisiones clave

1. **MariaDB desde Fase 1** (no SQLite) para detectar temprano incompatibilidades de tipos/índices reales de producción.
2. **Registro público eliminado**: modelo de "usuario invitado por un administrador" en vez de auto-registro, acorde al carácter interno/empresarial del portal.
3. **Longitud de columnas `name`/`guard_name` de Spatie reducida a 125** en la migración publicada, para evitar el error de MariaDB "1071 Specified key too long" con utf8mb4 en el índice único compuesto.
4. **`Sucursal` requiere `protected $table = 'sucursales'`** explícito: la pluralización automática de Eloquent para palabras en español no es confiable (genera `sucursals`).
5. **DataTable genérico** (`<script setup generic="T">`) en vez de una tabla por pantalla, para evitar duplicar lógica de paginación/estados vacíos/carga.
6. **Traducciones de notificaciones nativas de Laravel** (reset de contraseña, verificación de correo) vía `lang/es.json` (mecanismo de "cadena como clave" de Laravel), sin necesidad de sobrescribir las clases de notificación del framework.

### Archivos principales

- Backend: `app/Models/{User,Sucursal,Departamento,Puesto}.php`, `app/Enums/EstadoUsuario.php`, `app/Services/{AlcanceOrganizacionalService,RolPermisoService}.php`, `app/Policies/*`, `app/Http/Controllers/Administracion/*`, `app/Http/Requests/Administracion/*`, `database/migrations/2026_07_02_*`, `database/seeders/*`.
- Frontend: `resources/js/pages/Administracion/**`, `resources/js/components/Administracion/**`, `resources/js/components/DataTable/**`, `resources/js/composables/{usePermisos,useAlertas,useFiltros,usePaginacion}.ts`.
- Rutas: `routes/administracion.php`.
- Pruebas: `tests/Feature/Administracion/*.php`.

### Verificación realizada

- `php artisan migrate:fresh --seed` sobre MariaDB: OK.
- `php artisan test`: 43 pasaron, 3 omitidas (dependen de la feature de dos factores de Fortify, no habilitada todavía), 0 fallidas.
- `composer types:check` (PHPStan/Larastan nivel 7): 0 errores.
- `composer lint:check` (Pint): sin diferencias.
- `npm run lint:check` (ESLint): sin errores.
- `npm run format:check` (Prettier): sin diferencias.
- `npm run types:check` (vue-tsc): sin errores.
- `npm run build`: compiló correctamente.

### Pendiente / notas para fases futuras

- Autenticación de dos factores para administradores (sección 28) queda pendiente para la Fase 8 (seguridad), condicionada a si Fortify la habilita en este proyecto.
- El campo `foto_path` de `User` ya existe en el modelo de datos, pero la carga real de fotos de perfil se resolverá junto con la biblioteca multimedia (Fase 3).
- La vista previa de asignación masiva antes de confirmar (sección 17) se construye en la Fase 2 junto con el motor de asignaciones.

---

## Fase 2 — Cursos, módulos, lecciones, asignaciones, progreso ✅

### Alcance cubierto

- **Constructor de cursos**: `Curso`, `CursoModulo`, `Leccion`, con requisitos previos entre cursos (`curso_requisito_previo`) y entre lecciones (`requisitos_leccion`, grafo explícito además del orden secuencial simple). Reordenamiento de módulos/lecciones mediante botones subir/bajar (sin librería de drag-and-drop: no estaba en el stack aprobado, y los botones son más simples y accesibles por teclado). Publicar/archivar con permiso dedicado `cursos.publicar`.
- **Tipos de lección habilitados en esta fase**: `video`, `documento`, `guia`, `texto`, `enlace`, `confirmacion`. Los tipos `cuestionario`, `actividad` y `sesion_en_vivo` ya existen en el enum `TipoLeccion` (modelo de datos completo desde ahora) pero su constructor/selector en la interfaz se habilita en las Fases 4 y 5, cuando existan las entidades correspondientes.
- **Motor de asignaciones**: `Asignacion` (polimórfica vía `asignable_type`/`asignable_id`, preparada para asignar lecciones/cuestionarios/actividades/sesiones en fases futuras sin cambiar el esquema), `AsignacionDestino` (usuario/sucursal/departamento/puesto/rol/todos) y `AsignacionUsuario` (materialización idempotente, con índice único `(asignacion_id, user_id)`). `App\Services\Asignaciones\AsignacionService` centraliza la resolución de destinatarios; `App\Jobs\MaterializarAsignacionJob` hace el trabajo pesado en cola.
- **Vista previa antes de confirmar** (sección 17 del encargo): endpoint `POST /asignaciones/previsualizar` (fetch + cookie `XSRF-TOKEN`, sin usar Axios) que calcula el total de usuarios afectados y una muestra, sin persistir nada, antes de que el administrador confirme con el diálogo `confirmarAsignacionMasiva` de SweetAlert2.
- **Aplicación automática a usuarios nuevos**: al crear un colaborador (`UsuarioController::store`), `AsignacionService::aplicarVigentesA()` revisa todas las asignaciones activas y crea las que le correspondan según su sucursal/departamento/puesto/roles recién asignados.
- **Progreso e inscripciones**: `InscripcionCurso` (una por usuario+curso) y `ProgresoLeccion` (una por usuario+lección). `App\Services\Capacitacion\ProgresoService` calcula si una lección está bloqueada (por requisito explícito o, si el curso `requiere_orden`, por no haber completado la lección obligatoria anterior en la secuencia completa del curso —todos los módulos, no solo el módulo actual—), permite marcarla como completada, y al completarse todas las lecciones obligatorias marca la inscripción y la `AsignacionUsuario` correspondiente como completadas.
- **"Mi capacitación"**: vistas del colaborador (`MiCapacitacion/Index.vue`, `MiCapacitacion/Show.vue`) mostrando sus cursos asignados, con lecciones bloqueadas/desbloqueadas/completadas y motivo de bloqueo visible.
- Inscripción automática: cuando el motor de asignaciones materializa una `Asignacion` cuyo `asignable` es un `Curso`, se crea automáticamente la `InscripcionCurso` correspondiente.

### Decisiones clave

1. **Reordenamiento con botones, no drag-and-drop**: no se agregó ninguna librería de arrastrar-y-soltar porque no estaba en el stack aprobado (sección 3 del encargo); los botones subir/bajar cumplen el mismo requisito funcional y son accesibles por teclado sin dependencias nuevas.
2. **`Asignacion` es polimórfica desde ahora** (`asignable_type`/`asignable_id`) aunque en esta fase solo se usa con `Curso`, para no tener que migrar el esquema cuando se agregue asignación directa de lecciones/cuestionarios/actividades/sesiones en fases posteriores.
3. **Finalización manual para lecciones de video en esta fase**: el botón "Marcar como completada" aplica igual a todos los tipos de lección por ahora. La validación respaldada por el servidor contra la reproducción real (heartbeats, segundos únicos vistos, imposibilidad de adelantar) es explícitamente alcance de la Fase 3 y sustituirá esta finalización manual **solo para lecciones de tipo video**; los demás tipos (texto, documento, guía, enlace, confirmación) seguirán usando finalización manual porque no tiene sentido "reproducirlos".
4. **`Asignacion`, al igual que `Sucursal`, requiere `protected $table` explícito** (`asignaciones`) por la misma razón de pluralización en español; se aplicó preventivamente también a `Leccion` (`lecciones`).
5. **Eliminar un `CursoModulo` hace cascada física (hard delete) sobre sus `Leccion`** aunque `Leccion` tiene soft deletes: la FK de `lecciones.curso_modulo_id` usa `cascadeOnDelete()` a nivel de base de datos, y `CursoModulo` no tiene soft deletes. Es un compromiso aceptado para esta fase (documentado aquí); si se necesita conservar el historial de lecciones de un módulo eliminado, habría que mover la limpieza a un observer de Eloquent en vez de la FK cascade.
6. **`php artisan wayfinder:generate` siempre con `--with-form`**: ejecutarlo sin esa bandera regenera todos los helpers de rutas sin las variantes `.form()`, rompiendo silenciosamente cualquier página que use el componente `<Form>` de Inertia (ver incidente registrado más abajo).

### Incidente y corrección durante esta fase

Al ejecutar `php artisan wayfinder:generate` sin `--with-form` (para registrar las rutas nuevas de Cursos), se regeneraron **todos** los helpers de rutas/acciones del proyecto sin las variantes `.form()`, rompiendo en silencio `Login.vue`, `ForgotPassword.vue`, `ResetPassword.vue`, `Profile.vue`, `Security.vue` y `DeleteUser.vue`. Se detectó de inmediato con `npm run types:check` (vue-tsc) y se corrigió regenerando con `php artisan wayfinder:generate --with-form`. Ninguna otra corrección de contenido fue necesaria: los archivos de idioma (`lang/es/*.php`, `lang/es.json`), las páginas de autenticación traducidas y `config/fortify.php` de la Fase 1 se revisaron línea por línea (sintaxis PHP con `php -l`, ausencia de claves duplicadas, ausencia de fragmentos de Laravel en `Welcome.vue`) y no se encontró ningún daño.

### Archivos principales

- Backend: `app/Models/{Curso,CursoModulo,Leccion,Asignacion,AsignacionDestino,AsignacionUsuario,InscripcionCurso,ProgresoLeccion}.php`, `app/Enums/{EstadoCurso,TipoLeccion,EstadoAsignacion,TipoDestinoAsignacion,EstadoProgreso}.php`, `app/Services/Capacitacion/{CursoBuilderService,ProgresoService}.php`, `app/Services/Asignaciones/AsignacionService.php`, `app/Jobs/MaterializarAsignacionJob.php`, `app/Policies/{CursoPolicy,AsignacionPolicy}.php`, `app/Http/Controllers/{Cursos,Asignaciones,MiCapacitacion}/*`, `database/migrations/2026_07_03_*`.
- Frontend: `resources/js/pages/{Cursos,Asignaciones,MiCapacitacion}/**`, `resources/js/components/{Cursos,Asignaciones}/**`, `resources/js/lib/http.ts` (cliente fetch mínimo para la vista previa de asignaciones).
- Rutas: `routes/{cursos,asignaciones,mi-capacitacion}.php`.
- Pruebas: `tests/Feature/{Cursos,Asignaciones}/*.php` (28 pruebas nuevas).

### Verificación realizada

- `php artisan migrate:fresh --seed` sobre MariaDB: OK (incluye el curso de inducción de ejemplo con módulos y lecciones).
- `php artisan test`: 67 pasaron, 3 omitidas (2FA), 0 fallidas.
- `composer types:check` (PHPStan nivel 7): 0 errores.
- `composer lint:check` (Pint): sin diferencias.
- `npm run lint:check`, `npm run format:check`, `npm run types:check`: sin errores.
- `npm run build`: compiló correctamente.

### Pendiente / notas para fases futuras

- El selector de tipo de lección en el constructor solo ofrece los tipos ya utilizables (video/documento/guía/texto/enlace/confirmación); se ampliará a cuestionario/actividad/sesión en vivo en las Fases 4 y 5.
- La finalización de lecciones de video será reemplazada por la validación real de reproducción en la Fase 3.
- El selector de "colaborador específico" en el motor de asignaciones usa un `Select` simple con todos los usuarios; para organizaciones con muchos colaboradores convendría sustituirlo por un Combobox con búsqueda en servidor (componentes `Command`/`Popover` mencionados en la sección 3, todavía no agregados al proyecto).

---

## Fase 3 — Biblioteca multimedia, NAS, FFmpeg, HLS y control de avance ✅

### Alcance cubierto

- **Almacenamiento**: disco dedicado `nas` (`config/filesystems.php`, driver `local` o `sftp` según `NAS_DRIVER`) con `App\Services\Multimedia\MediaStorageService` como única puerta de entrada (nombres internos UUID, nunca se expone la ruta física real al frontend). Ver `docs/CONFIGURACION_NAS.md`.
- **Biblioteca multimedia**: `RecursoMultimedia` (video/documento/imagen), pantalla `Multimedia/Index.vue` con carga (`MultimediaUploadDialog.vue`, progreso vía `useForm({ forceFormData: true })`) y sondeo automático (`router.reload` cada 5s) mientras algún recurso está `pendiente`/`procesando`. Selector de recurso multimedia integrado en el constructor de lecciones (`LeccionFormDialog.vue`) para lecciones de tipo `video`/`documento`.
- **Procesamiento**: `App\Jobs\ProcesarVideoJob` (idempotente) usa `App\Services\Multimedia\FfmpegService` para inspeccionar (`ffprobe`), generar miniatura y convertir a HLS solo en las resoluciones ≤ a la original. Detalle completo en `docs/PROCESAMIENTO_VIDEO.md`.
- **Reproductor y control de avance**: reproductor HLS (`hls.js`) en "Mi capacitación" (`ReproductorVideo.vue`) con heartbeats periódicos. El anti-adelanto está respaldado por el servidor en dos capas: (1) `intervalos_video_vistos` mide lo realmente visto por tramos fusionados, no por lo que el cliente afirme, y (2) el manifiesto y los segmentos HLS que sirve `ReproduccionController` se truncan/rechazan según ese avance real, así que ni siquiera un cliente HLS armado a mano puede pedir un segmento no autorizado. Las lecciones de video se completan automáticamente al alcanzar `config('media.video.completion_percent')` (98% por defecto) y ya no tienen botón de finalización manual.
- **URLs firmadas de corta duración**: el manifiesto maestro, las variantes y los segmentos se sirven exclusivamente a través de `URL::temporarySignedRoute` (`config('media.token_ttl')`, 600s por defecto) más el middleware `auth`, nunca como rutas públicas o predecibles.

### Decisiones clave

1. **Dos tablas, no tres, para el control de avance**: se descartó una tabla adicional de eventos crudos de heartbeat (`eventos_progreso_video`) porque `intervalos_video_vistos` (tramos ya fusionados) es suficiente tanto para el porcentaje visto como para el límite de avance, y evita una tabla que crecería sin límite práctico. `sesiones_reproduccion` queda solo como ancla/auditoría de cada vez que se monta el reproductor, no como fuente de verdad del avance.
2. **Truncar el manifiesto HLS en vez de solo bloquear el heartbeat**: bloquear únicamente la llamada de heartbeat habría dejado que un cliente HLS modificado pidiera igualmente los segmentos futuros directamente por URL. Al truncar la variante servida (y validar también cada segmento individual por su índice), el anti-adelanto queda respaldado por lo que el servidor entrega, no por la buena fe del reproductor.
3. **Sin tabla de eventos por heartbeat, pero sí con `#EXT-X-PLAYLIST-TYPE:EVENT` mientras el video no esté desbloqueado por completo**: es el mecanismo estándar de HLS para decirle al reproductor "vuelve a pedir este manifiesto, puede haber más" en vez de inventar un protocolo propio de recarga.
4. **X-Accel-Redirect documentado pero no implementado**: es una optimización de rendimiento para producción (Nginx sirve el `.ts` sin pasar por PHP-FPM), no un requisito de corrección; el entorno de desarrollo no tiene Nginx para probarlo, así que se documenta en `docs/PROCESAMIENTO_VIDEO.md` en vez de escribir código que no puede verificarse aquí.
5. **FFmpeg/FFprobe no están instalados en este entorno Windows/WAMP**: las pruebas automatizadas del job de procesamiento (`ProcesarVideoJobTest`) usan `Process::fake()` para cubrir idempotencia y manejo de errores; la conversión real a HLS queda como verificación manual pendiente en un entorno con los binarios instalados (pasos exactos en `docs/CONFIGURACION_NAS.md`).

### Archivos principales

- Backend: `app/Models/{RecursoMultimedia,CargaMultimedia,SesionReproduccion,IntervaloVideoVisto}.php`, `app/Enums/{TipoRecursoMultimedia,EstadoMultimedia}.php`, `app/Services/Multimedia/{MediaStorageService,FfmpegService,ReproduccionVideoService,ManifiestoHlsService}.php`, `app/Jobs/ProcesarVideoJob.php`, `app/Policies/RecursoMultimediaPolicy.php`, `app/Http/Controllers/{Multimedia/RecursoMultimediaController,MiCapacitacion/ReproduccionController}.php`, `database/migrations/2026_07_03_153440_*` a `2026_07_03_180001_*`.
- Frontend: `resources/js/pages/Multimedia/Index.vue`, `resources/js/components/Multimedia/MultimediaUploadDialog.vue`, `resources/js/components/MiCapacitacion/ReproductorVideo.vue`, integración en `Cursos/Constructor.vue` → `ModuloCard.vue` → `LeccionFormDialog.vue` y en `MiCapacitacion/Show.vue`.
- Rutas: `routes/multimedia.php`, rutas de reproducción agregadas a `routes/mi-capacitacion.php`.
- Pruebas: `tests/Feature/Multimedia/{RecursoMultimediaTest,ProcesarVideoJobTest}.php`, `tests/Feature/Cursos/ReproduccionVideoTest.php` (15 pruebas nuevas).
- Documentación: `docs/CONFIGURACION_NAS.md`, `docs/PROCESAMIENTO_VIDEO.md` (nuevos).

### Verificación realizada

- `php artisan migrate:fresh --seed` sobre MariaDB: OK.
- `php artisan test`: 82 pasaron, 3 omitidas (2FA, sin relación con esta fase), 0 fallidas.
- `composer types:check` (PHPStan/Larastan nivel 7): 0 errores.
- `composer lint:check` (Pint): sin diferencias.
- `npm run lint:check`, `npm run format:check`, `npm run types:check`: sin errores.
- `npm run build`: compiló correctamente.

### Pendiente / notas para fases futuras

- Verificación manual de la conversión real a HLS con FFmpeg/FFprobe instalados (no disponible en este entorno de desarrollo); pasos detallados en `docs/CONFIGURACION_NAS.md`.
- `X-Accel-Redirect` para servir segmentos `.ts` vía Nginx directamente queda documentado como optimización de producción, no implementado (sin Nginx en este entorno para probarlo).
- `cargas_multimedia` ya existe en el modelo de datos para un futuro flujo de carga por partes (`chunked upload`) de archivos muy grandes; esta fase sigue subiendo el archivo completo en una sola petición porque es suficiente para los tamaños de video esperados y evita complejidad adicional no solicitada.

---

## Fase 4 — Banco de preguntas, cuestionarios, actividades y calificación ✅

### Alcance cubierto

- **Banco de preguntas reutilizable**: `BancoPregunta` → `Pregunta` (tipos `opcion_unica`, `opcion_multiple`, `verdadero_falso`, `respuesta_corta`) → `OpcionPregunta`. Un mismo banco puede alimentar preguntas a varios cuestionarios de distintos cursos. Pantalla `Cuestionarios/BancosPreguntas/{Index,Show}.vue`, con `PreguntaFormDialog.vue` reemplazando siempre el conjunto completo de opciones al editar (no hay diff parcial de opciones).
- **Constructor de cuestionario**: `Cuestionario` es 1:1 con una `Leccion` de tipo `cuestionario` (igual que `RecursoMultimedia` con las lecciones de video en la Fase 3). Se configuran calificación mínima, intentos máximos, tiempo límite, aleatorización y si se muestra retroalimentación; las preguntas se seleccionan de cualquier banco y su orden/puntos por cuestionario se guarda en la tabla pivote `cuestionario_pregunta` (`Cuestionarios/Constructor.vue`).
- **Motor de intentos** (`App\Services\Evaluacion\IntentoCuestionarioService`): un intento por vez por usuario (se reanuda el que esté `en_progreso` en vez de crear uno nuevo), respeta `intentos_maximos`. Al enviar, las preguntas de opción/verdadero-falso se califican solas comparando las opciones marcadas como correctas; las de `respuesta_corta` quedan con `es_correcta = null` hasta que un instructor las califica manualmente (permiso `respuestas.calificar`). Un intento solo pasa a `calificado` cuando **todas** sus preguntas tienen una respuesta calificada (automática o manual); solo entonces se decide si el usuario aprobó (`calificacion_minima`) y, si aprobó, se completa la lección vía `ProgresoService`.
- **Actividades** (entregas de archivo/texto/enlace): `Actividad` es 1:1 con una `Leccion` de tipo `actividad`. `EntregaActividad` es su propio historial: cada reenvío (tras un rechazo) crea una fila nueva con `version` incrementada, en vez de existir una tabla de historial aparte. Los archivos entregados reutilizan `RecursoMultimedia`/`MediaStorageService` (el mismo disco `nas` de la Fase 3) en vez de duplicar lógica de almacenamiento. Un colaborador no puede volver a entregar mientras tenga una entrega `entregada` (pendiente) o `aprobada`; solo puede reenviar tras un rechazo.
- **Calificación por instructor**: pantallas separadas para cuestionarios (`Cuestionarios/Calificaciones/*`, respuestas de tipo `respuesta_corta` pendientes) y actividades (`Actividades/Calificaciones/*`, entregas `entregada` pendientes), ambas gated por `respuestas.ver`/`respuestas.calificar`. La descarga de un archivo entregado usa el mismo helper de streaming de `MediaStorageService` construido en la Fase 3.
- **"Mi capacitación"**: las lecciones de tipo `cuestionario` y `actividad` ya no usan el botón genérico "Marcar como completada" (igual que las de video desde la Fase 3): un cuestionario se completa solo al aprobar un intento, y una actividad solo al ser aprobada por un instructor.

### Decisiones clave

1. **`Cuestionario`/`Actividad` son 1:1 con `Leccion`, no un catálogo independiente asignable por separado**: sigue el mismo patrón que `RecursoMultimedia` en video — cada lección de ese tipo tiene exactamente una configuración de cuestionario/actividad. Simplifica el modelo y coincide con cómo ya funciona el resto del constructor de cursos.
2. **Sin tabla de "historial de entregas" separada**: cada reenvío de una actividad crea una nueva fila en `entregas_actividad` con `version` incrementada; la propia tabla es su historial, igual que se decidió para `intervalos_video_vistos` en la Fase 3 (evitar tablas adicionales que solo duplicarían datos ya presentes).
3. **Las entregas de archivo reutilizan `RecursoMultimedia`/`MediaStorageService`** en vez de un mecanismo de almacenamiento propio: es el mismo disco `nas`, el mismo helper de streaming para descargas, y evita mantener dos rutas de subida de archivos en paralelo. Contrapartida aceptada: el archivo de una entrega aparece técnicamente en la tabla `recursos_multimedia` (no en una tabla exclusiva de entregas), aunque no se lista en la biblioteca multimedia visible para los colaboradores.
4. **Calificación automática de opción múltiple es todo-o-nada**: se considera correcta solo si el conjunto exacto de opciones marcadas coincide con el conjunto exacto de opciones correctas (sin crédito parcial). Mantiene la calificación simple y predecible; no se pidió calificación parcial en el encargo.
5. **Un intento con preguntas de `respuesta_corta` puede quedar "enviado" indefinidamente hasta que un instructor lo revise**: es el comportamiento esperado (requiere revisión humana), no un error; la pantalla de calificaciones existe precisamente para ese flujo.
6. **Índices únicos con nombre explícito en `intentos_cuestionario` y `respuestas_cuestionario`**: los nombres autogenerados por Laravel para los índices compuestos de 3 columnas excedían el límite de 64 caracteres de MariaDB ("1071 Specified key too long"), el mismo tipo de error ya visto con las columnas de Spatie Permission en la Fase 1; se resolvió igual, acortando el nombre del índice explícitamente en la migración.

### Archivos principales

- Backend: `app/Models/{BancoPregunta,Pregunta,OpcionPregunta,Cuestionario,IntentoCuestionario,RespuestaCuestionario,Actividad,EntregaActividad}.php`, `app/Enums/{TipoPregunta,EstadoIntentoCuestionario,TipoEntregaActividad,EstadoEntregaActividad}.php`, `app/Services/Evaluacion/{BancoPreguntaService,CuestionarioService,IntentoCuestionarioService,ActividadService,EntregaActividadService}.php`, `app/Http/Controllers/{Cuestionarios,Actividades,MiCapacitacion}/*`, `database/migrations/2026_07_04_*`.
- Frontend: `resources/js/pages/{Cuestionarios,Actividades}/**`, `resources/js/pages/MiCapacitacion/{Cuestionario,Actividad}.vue`, integración en `LeccionFormDialog.vue` y `MiCapacitacion/Show.vue`.
- Rutas: `routes/{cuestionarios,actividades}.php`, ampliaciones en `routes/{cursos,mi-capacitacion}.php`.
- Pruebas: `tests/Feature/{Cuestionarios,Actividades}/*.php` (23 pruebas nuevas).

### Verificación realizada

- `php artisan migrate:fresh --seed` sobre MariaDB: OK.
- `php artisan test`: 103 pasaron, 3 omitidas (2FA, sin relación con esta fase), 0 fallidas.
- `composer types:check` (PHPStan/Larastan nivel 7): 0 errores.
- `composer lint:check` (Pint): sin diferencias.
- `npm run lint:check`, `npm run types:check`: sin errores.
- `npm run build`: compiló correctamente.

### Pendiente / notas para fases futuras

- La aleatorización de preguntas (`aleatorizar_preguntas`) se recalcula en cada carga de la página del intento en vez de fijarse una sola vez por intento (no hay columna para persistir el orden aleatorio); un usuario que recargue la página durante un intento podría ver las preguntas en otro orden. No afecta la corrección de la calificación, solo es una variación menor de experiencia.
- No hay crédito parcial en preguntas de opción múltiple ni límite de tiempo aplicado estrictamente en el servidor (el `tiempo_limite_minutos` se expone al frontend para mostrarlo, pero el envío tardío no se rechaza); ambos quedan como posibles mejoras si se solicitan explícitamente.
- Los archivos de entregas de actividad son técnicamente visibles para quien administre la biblioteca multimedia general (comparten la tabla `recursos_multimedia`); si en el futuro se requiere ocultarlos de ese listado, se puede agregar una columna de "origen"/"visibilidad" a `recursos_multimedia` sin romper lo existente.

---

## Fase 5 — Sesiones en vivo (manual, Google Meet, Zoom) y asistencias ✅

### Alcance cubierto

- **`SesionEnVivo`**: 1:1 con una `Leccion` de tipo `sesion_en_vivo` (mismo patrón que `RecursoMultimedia`/`Cuestionario`/`Actividad`). Se configuran título, descripción, proveedor, fecha/hora, duración y (para el proveedor manual) el enlace de la reunión.
- **Tres proveedores intercambiables** detrás de la interfaz `App\Integrations\Reuniones\ProveedorSesionEnVivo`: `ManualProveedor` (el instructor escribe el enlace a mano), `GoogleMeetProveedor` (Google Calendar + solicitud de conferencia de Meet, autenticado con una cuenta de servicio con delegación de dominio) y `ZoomProveedor` (API de Zoom vía Server-to-Server OAuth). Ambas integraciones externas están deshabilitadas por defecto (`GOOGLE_MEET_ENABLED`/`ZOOM_ENABLED`) y se degradan con gracia si faltan credenciales: la sesión se guarda igual, sin bloquear al instructor.
- **Materialización automática de asistencias**: al programar una sesión, se crea una `Asistencia` en estado `pendiente` para cada colaborador ya inscrito en el curso (`AsistenciaService::materializarParaSesion()`), lista para que el instructor la marque durante o después de la sesión.
- **Marcado y corrección de asistencia**: marcar por primera vez (`pendiente` → `presente`/`ausente`/`tarde`) requiere el permiso operativo `sesiones.administrar` y completa automáticamente la lección si el resultado es `presente`/`tarde` (vía `ProgresoService`, igual que en las fases anteriores). Corregir una asistencia que ya tenía un estado definitivo exige además el permiso `asistencias.corregir` **y** un motivo obligatorio; el registro guarda quién corrigió y por qué.
- **"Mi capacitación"**: las lecciones de tipo `sesion_en_vivo` muestran fecha, descripción, botón para unirse al enlace (si ya existe) y el estado de la propia asistencia del colaborador; no tienen botón de "marcar como completada" (se completa solo cuando el instructor registra la asistencia).

### Decisiones clave

1. **Interfaz común (`ProveedorSesionEnVivo`) en vez de un `if/else` por proveedor en el servicio**: `SesionEnVivoService` resuelve la implementación con un `match` sobre el enum `ProveedorSesion` y llama siempre a los mismos dos métodos (`crearReunion`/`cancelarReunion`); agregar un cuarto proveedor en el futuro (p. ej. Microsoft Teams) no requeriría tocar el servicio, solo una clase nueva.
2. **Fallo de la API externa nunca bloquea la creación de la sesión**: `SesionEnVivoService::crear()`/`cancelar()` capturan cualquier excepción de la llamada al proveedor con `report()` y continúan; el instructor puede agregar un enlace manual después. Una integración de terceros caída no debe impedir el trabajo operativo del día a día.
3. **Google Meet sin el SDK oficial `google/apiclient`**: se probó instalarlo pero se descartó porque arrastra `google/apiclient-services`, una dependencia con clases para cientos de APIs de Google y un autoload de decenas de miles de archivos, poco práctico en este entorno de desarrollo. En su lugar, `GoogleMeetProveedor` firma el JWT de la cuenta de servicio a mano con `openssl_sign()` (funciones nativas de PHP) y llama al API REST de Calendar con el cliente HTTP de Laravel — mismo patrón ya usado para Zoom, sin dependencias adicionales.
4. **Autenticación por cuenta de servicio/Server-to-Server, no OAuth interactivo por instructor**: ambas integraciones actúan como un usuario configurado centralmente (`GOOGLE_IMPERSONATED_USER`/`ZOOM_HOST_EMAIL`), evitando que cada instructor tenga que autorizar la aplicación individualmente.
5. **Índice único con nombre explícito en `asistencias`**: mismo problema recurrente desde la Fase 1 (nombre autogenerado por Laravel excede los 64 caracteres de MariaDB); se acortó explícitamente (`asistencias_unico`).

### Archivos principales

- Backend: `app/Models/{SesionEnVivo,Asistencia}.php`, `app/Enums/{ProveedorSesion,EstadoSesionEnVivo,EstadoAsistencia}.php`, `app/Integrations/Reuniones/{ProveedorSesionEnVivo,ManualProveedor,GoogleMeetProveedor,ZoomProveedor}.php`, `app/Services/Reuniones/{SesionEnVivoService,AsistenciaService}.php`, `app/Http/Controllers/{Reuniones,MiCapacitacion}/*`, `database/migrations/2026_07_05_*`.
- Frontend: `resources/js/pages/Reuniones/**`, `resources/js/pages/MiCapacitacion/SesionEnVivo.vue`, integración en `LeccionFormDialog.vue` y `MiCapacitacion/Show.vue`.
- Rutas: `routes/reuniones.php`, ampliaciones en `routes/{cursos,mi-capacitacion}.php`.
- Pruebas: `tests/Feature/Reuniones/*.php` (14 pruebas nuevas, incluyendo `Http::fake()` para ambas integraciones externas).
- Documentación: `docs/SESIONES_EN_VIVO.md` (nuevo).

### Verificación realizada

- `php artisan migrate:fresh --seed` sobre MariaDB: OK.
- `php artisan test`: 116 pasaron, 3 omitidas (2FA, sin relación con esta fase), 0 fallidas.
- `composer types:check` (PHPStan/Larastan nivel 7): 0 errores.
- `composer lint:check` (Pint): sin diferencias.
- `npm run lint:check`, `npm run types:check`: sin errores.
- `npm run build`: compiló correctamente.

### Pendiente / notas para fases futuras

- No hay credenciales reales de Google ni de Zoom en este entorno; la verificación manual contra las APIs reales queda pendiente (pasos detallados en `docs/SESIONES_EN_VIVO.md`).
- El registro automático de asistencia por join/leave del proveedor (webhooks de Zoom, eventos de Calendar) no está implementado en esta fase: la toma de asistencia es siempre manual por el instructor. Es una mejora natural para una fase futura si se solicita.
- La lección de sesión en vivo no valida que la fecha/hora ya haya pasado antes de permitir marcar asistencia; se confía en que el instructor la tome en el momento correcto.

---

## Fase 6 — Dashboards, reportes y exportaciones ✅

### Alcance cubierto

- **Tres dashboards por rol** en la misma ruta `dashboard` (`App\Http\Controllers\DashboardController` decide cuál renderizar según los permisos del usuario, en este orden de prioridad: `dashboard.global.ver` → `Dashboard/Global.vue`; `dashboard.sucursal.ver` → `Dashboard/Sucursal.vue`; si no tiene ninguno de los dos → `Dashboard/Colaborador.vue`). El colaborador ve sus cursos en progreso/completados y sus próximas sesiones en vivo; el dashboard de sucursal añade cumplimiento agregado, entregas/cuestionarios pendientes de calificar (si el usuario tiene `respuestas.calificar`) y las próximas sesiones de su alcance; el global agrega lo mismo sin restricción de sucursal.
- **`App\Services\Reportes\MetricasDashboardService`**: agrega las métricas específicas de "qué hacer hoy" (próximas sesiones, pendientes de calificar) que no vienen del reporte de cumplimiento histórico.
- **`App\Services\Reportes\ReporteCumplimientoService`**: la fuente única tanto del resumen general/por sucursal de los dashboards como de la pantalla completa de reporte (`Reportes/Cumplimiento.vue`) y de la exportación a Excel — un mismo cálculo, reutilizado en los tres lugares, para que dashboard/reporte/exportación nunca puedan divergir en sus números.
- **Reutiliza `AlcanceOrganizacionalService`** (Fase 1) para todo el aislamiento por sucursal: un gerente/supervisor solo ve el cumplimiento y los colaboradores de su propia sucursal, tanto en el dashboard como en el reporte y en el archivo exportado.
- **Reporte de cumplimiento** (`Reportes/Cumplimiento.vue`): tabla paginada con filtros por sucursal, departamento y curso, reutilizando el componente `DataTable` genérico.
- **Exportación a Excel** (`Maatwebsite\Excel`, paquete `maatwebsite/excel`): `App\Exports\CumplimientoExport` reutiliza `ReporteCumplimientoService::todosLosColaboradores()` (misma consulta que la pantalla, sin paginar) y respeta el permiso `reportes.exportar` y el mismo aislamiento por sucursal.

### Decisiones clave

1. **Un único servicio de cumplimiento para pantalla y exportación**: en vez de que `CumplimientoExport` repita la consulta o la lógica de filtros, `ReporteCumplimientoService` expone tanto `porColaborador()` (paginado, para la pantalla) como `todosLosColaboradores()` (colección completa, para Excel), ambos delegando en el mismo método privado `consultaColaboradores()`. Evita que un cambio futuro en los filtros o el aislamiento por sucursal se aplique en un lugar y se olvide en el otro.
2. **El dashboard decide su propia vista según permisos, no según rol**: `DashboardController` usa `$usuario->can('dashboard.global.ver')`/`can('dashboard.sucursal.ver')`, no `hasRole()`, para que sea consistente con el resto del sistema (roles y permisos son editables desde Administración → Roles y permisos; el dashboard debe seguir esa configuración, no una lista de roles cableada).
3. **Métricas de "pendientes de calificar" solo se calculan si el usuario puede calificar**: `MetricasDashboardService::pendientesDeCalificar()` retorna 0 de inmediato si el usuario no tiene `respuestas.calificar`, evitando una consulta innecesaria y evitando mostrar un número que no le compete a ese usuario.
4. **Índice único con nombre explícito no fue necesario en esta fase** (no se crearon tablas nuevas): los dashboards y reportes son enteramente de lectura sobre datos ya existentes (asignaciones, inscripciones, sesiones), sin nuevas tablas.

### Archivos principales

- Backend: `app/Http/Controllers/DashboardController.php`, `app/Http/Controllers/Reportes/{ReporteCumplimientoController,ExportacionCumplimientoController}.php`, `app/Services/Reportes/{MetricasDashboardService,ReporteCumplimientoService}.php`, `app/Exports/CumplimientoExport.php`.
- Frontend: `resources/js/pages/Dashboard/{Colaborador,Sucursal,Global}.vue`, `resources/js/pages/Reportes/Cumplimiento.vue`, `resources/js/components/Dashboard/**`.
- Rutas: `routes/reportes.php`; `routes/web.php` cambió la ruta `dashboard` de `Route::inertia` a `DashboardController::index`.
- Pruebas: `tests/Feature/{Dashboard,Reportes}/*.php` (10 pruebas nuevas).

### Verificación realizada

- `php artisan migrate:fresh --seed` sobre MariaDB: OK.
- `php artisan test`: 126 pasaron, 3 omitidas (2FA, sin relación con esta fase), 0 fallidas.
- `composer types:check` (PHPStan/Larastan nivel 7): 0 errores.
- `composer lint:check` (Pint): sin diferencias.
- `npm run lint:check`, `npm run types:check`: sin errores.
- `npm run build`: compiló correctamente.

### Pendiente / notas para fases futuras

- El reporte de cumplimiento no tiene gráficas (solo tabla + barras simples de porcentaje); si se pide una visualización más rica (líneas de tendencia en el tiempo, comparativos entre periodos), se puede agregar una librería de gráficas ligera sin tocar el modelo de datos, ya que los servicios de agregación ya existen.
- Solo se exportó el reporte de cumplimiento; reportes adicionales (asistencia a sesiones en vivo, resultados de cuestionarios) usarían el mismo patrón (`Export` + `Controller` + reutilizar el servicio de agregación correspondiente) si se solicitan.

---

## Fase 7 — Notificaciones, correos, calendario y constancias ✅

### Alcance cubierto

- **Notificaciones internas + por correo**: sistema nativo de notificaciones de Laravel (canales `database` + `mail`), disparadas en los puntos donde ya existía la lógica de negocio correspondiente (no se creó un mecanismo aparte): nueva asignación (`AsignacionService`), cuestionario calificado (`IntentoCuestionarioService`), actividad calificada (`EntregaActividadService`), sesión en vivo programada (`AsistenciaService::materializarParaSesion`). Cada notificación respeta `User::prefiereNotificacionPorCorreo()` (lee `preferencias_notificaciones`, columna ya existente desde la Fase 1): el canal `database` siempre se entrega, el canal `mail` solo si el usuario no lo desactivó explícitamente.
- **Campana de notificaciones**: `resources/js/components/NotificationBell.vue` + `useNotificaciones.ts` (sondeo cada 30s vía el mismo cliente fetch mínimo de `lib/http.ts`, sin usar Inertia para no navegar/reemplazar props), integrada en `AppSidebarHeader.vue`. Permite marcar una o todas como leídas.
- **Recordatorios programados** (`routes/console.php` + `Schedule::command()`): `capacitacion:recordar-fechas-limite` (diario, asignaciones que vencen en 3 días), `capacitacion:recordar-sesiones-proximas` (cada 15 min, sesiones que empiezan en la próxima hora), `capacitacion:recordar-calificaciones-pendientes` (diario, resumen para quien tenga `respuestas.calificar`, solo si hay algo pendiente). Los dos primeros usan una columna `recordatorio_enviado_en` (en `asignaciones_usuario` y `sesiones_en_vivo`) para no volver a notificar en cada ejecución del scheduler.
- **Calendario** (`App\Services\Calendario\CalendarioService` + `Calendario/Index.vue`): vista de mes construida a mano con `date-fns` (sin librería de calendario adicional), mostrando las fechas límite propias del usuario, las sesiones en vivo a las que tiene asistencia registrada y, si administra sesiones (`sesiones.administrar`), también las que él mismo programó.
- **Constancias de finalización en PDF** (`barryvdh/laravel-dompdf`): `App\Services\Certificados\CertificadoService::emitirSiAplica()` se dispara automáticamente dentro de la misma transacción que marca una `InscripcionCurso` como completada (`ProgresoService::recalcularInscripcion`), solo si `Curso.genera_constancia` es verdadero, con folio corto y legible (`MRL-XXXXXXXX`) para que el colaborador lo pueda anotar a mano. Descarga autenticada desde "Mi capacitación" (`CertificadoController::descargar`) y verificación **pública** por folio (`CertificadoVerificacionController`, sin sesión iniciada, expone solo nombre/curso/fecha, nunca correo ni otros datos personales).

### Decisiones clave

1. **Las notificaciones se disparan desde los servicios existentes, no desde un listener de eventos aparte**: se evaluó usar eventos de dominio (`AsignacionCreada`, `CuestionarioCalificado`, etc.) con listeners dedicados, pero se descartó por ahora: los servicios ya son el único punto de entrada para esas operaciones (no hay otro código que las dispare), así que una capa de eventos habría sido indirección sin beneficio real en esta fase. Si en el futuro se necesita que otra parte del sistema reaccione a los mismos hechos, es el momento natural de introducir eventos.
2. **Preferencias de notificación solo controlan el canal de correo, nunca el interno**: la campana (canal `database`) siempre recibe todo; desactivar una preferencia solo evita el correo. Así el usuario nunca pierde visibilidad del hecho en el portal, solo reduce el ruido en su bandeja de entrada.
3. **`recordatorio_enviado_en` en vez de una tabla de log de recordatorios**: es la misma decisión de diseño que `intervalos_video_vistos` (Fase 3) y `entregas_actividad` (Fase 4) — una columna de marca de tiempo en la tabla ya existente es suficiente para la idempotencia requerida, sin crear una tabla nueva solo para evitar reenvíos.
4. **Calendario propio, no una librería de calendario de terceros**: el encargo no aprobó ninguna librería de calendario en el stack (sección 3); una cuadrícula de mes con `date-fns` (ya instalado desde la Fase 1) cubre el requisito sin agregar una dependencia nueva de UI.
5. **Verificación de constancias es pública a propósito**: el caso de uso real de un "folio verificable" es que un tercero (otra empresa, un auditor externo) confirme la validez sin necesitar una cuenta en el portal. Se limitó cuidadosamente la información expuesta (nombre, curso, fecha) para no filtrar datos personales sensibles del colaborador.
6. **`barryvdh/laravel-dompdf` en vez de un servicio externo de generación de PDF**: genera el PDF en el propio proceso PHP a partir de una vista Blade, sin dependencias de sistema (a diferencia de wkhtmltopdf) ni llamadas a APIs externas; suficiente para un documento de una sola página con estilos simples.
7. **Se evaluó y descartó instalar `google/apiclient`** (ya intentado y revertido en la Fase 5) — la integración de Google Meet sigue usando llamadas REST directas firmadas a mano con OpenSSL, mismo patrón que Zoom.

### Archivos principales

- Backend: `app/Notifications/*.php`, `app/Console/Commands/Recordar*Command.php`, `app/Services/Calendario/CalendarioService.php`, `app/Services/Certificados/CertificadoService.php`, `app/Models/Certificado.php`, `app/Http/Controllers/{NotificacionController,CalendarioController,CertificadoVerificacionController,MiCapacitacion/CertificadoController}.php`, `database/migrations/2026_07_06_*`, `resources/views/pdf/constancia.blade.php`.
- Frontend: `resources/js/components/NotificationBell.vue`, `resources/js/composables/useNotificaciones.ts`, `resources/js/pages/Calendario/Index.vue`, `resources/js/pages/Constancias/Verificar.vue`, cambios en `MiCapacitacion/Show.vue` y `AppSidebarHeader.vue`.
- Rutas: `routes/{notificaciones,reportes}.php` ya existentes ampliadas; `routes/web.php` agrega `calendario` y la ruta pública `constancias.verificar`; `routes/mi-capacitacion.php` agrega `constancias.descargar`; `routes/console.php` agrega el scheduler.
- Pruebas: `tests/Feature/{Notificaciones,Calendario,Certificados}/*.php` (25 pruebas nuevas).

### Verificación realizada

- `php artisan migrate:fresh --seed` sobre MariaDB: OK.
- `php artisan test`: 147 pasaron, 3 omitidas (2FA, sin relación con esta fase), 0 fallidas.
- `composer types:check` (PHPStan/Larastan nivel 7): 0 errores.
- `composer lint:check` (Pint): sin diferencias.
- `npm run lint:check`, `npm run types:check`: sin errores.
- `npm run build`: compiló correctamente.

### Pendiente / notas para fases futuras

- El envío real de correos no se probó contra un proveedor SMTP real (el entorno de desarrollo usa `MAIL_MAILER=log`); antes de producción, configurar un proveedor real (Postmark/SES/SMTP corporativo) y enviar una notificación de prueba de cada tipo.
- El calendario no distingue aún "fecha límite vencida" visualmente de "próxima a vencer"; es una mejora de UI menor si se solicita.
- No se implementó un registro automático de asistencia por join/leave de Zoom/Meet (webhooks), mencionado como posible mejora desde la Fase 5; la toma de asistencia sigue siendo manual por el instructor.

---

## Fase 8 — Seguridad, rendimiento, pruebas finales y documentación ✅

### Alcance cubierto

- **Auditoría de seguridad**: revisión sistemática de autenticación (Fortify: rate limiting, política de contraseñas fuertes en producción, ya correctas desde fases previas), autorización (Policies/permisos en los ~30 controladores del proyecto) y aislamiento por sucursal (`AlcanceOrganizacionalService`, uso consistente confirmado). Se encontró y corrigió una **clase real de vulnerabilidad IDOR**: en rutas con varios segmentos de recurso anidados (curso/módulo/lección, sesión/asistencia), Laravel resuelve cada segmento de forma independiente sin verificar que el hijo pertenezca realmente al padre de la URL. Se agregó la verificación explícita (`abort_unless($hijo->padre_id === $padre->id, 404)`) en `CursoModuloController`, `LeccionController`, `CuestionarioController`, `ActividadController`, `Reuniones\SesionEnVivoController` y `Reuniones\AsistenciaController`.
- **Rendimiento**: se agregaron 4 índices en columnas de filtro/orden que las consultas de dashboards/reportes/recordatorios ya usaban con frecuencia pero que no estaban indexadas (`asignaciones_usuario.(estado, fecha_limite)`, `sesiones_en_vivo.(estado, fecha_inicio)`, `intentos_cuestionario.estado`, `entregas_actividad.estado`). Se identificaron y corrigieron dos problemas de consultas N+1 en el detalle de "Mi capacitación" (`MiCapacitacionController::show` no hidrataba la relación inversa lección→módulo→curso; `ProgresoService::leccionCompletada()` disparaba una consulta nueva por cada lección) mediante hidratación manual de relaciones y memoización por instancia/usuario.
- **Pruebas finales y análisis estático**: ejecución completa de la suite de Pest, PHPStan/Larastan nivel 7, Pint, ESLint, Prettier, vue-tsc y build de producción sobre el proyecto completo (las 8 fases), sin regresiones.
- **Documentación final**: `docs/SEGURIDAD.md` (nuevo, consolidando autenticación/autorización/multimedia/rutas públicas/auditoría/rendimiento y un checklist de despliegue a producción), actualización de `docs/ARQUITECTURA.md` (stack final, estructura real de `app/` tras las 8 fases, sección de seguridad ampliada) y de este documento.

### Decisiones clave

1. **Corrección con `abort_unless(...)`, no con "scoped bindings" de Laravel**: se evaluó adoptar el enrutamiento con `Route::scopeBindings()`/`->scoped()` de Laravel para resolver esto de raíz a nivel de framework, pero se descartó por el alcance del cambio (afectaría la definición de todas las rutas anidadas del proyecto) frente al beneficio marginal sobre la verificación explícita ya aplicada; el resultado funcional es el mismo (404 ante una combinación de IDs que no corresponde) con un cambio mínimo y focalizado.
2. **Índices agregados en una migración separada, no retroactivamente en cada migración original**: mantiene el historial de migraciones como un registro fiel de cuándo se tomó cada decisión, en vez de reescribir migraciones ya aplicadas en otros entornos.
3. **Memoización por instancia (no caché compartido/Redis) para `ProgresoService`**: el problema era estrictamente por-petición (N consultas dentro de una misma carga de página), así que una caché en memoria del ciclo de vida de la instancia es suficiente y no introduce los riesgos de invalidación de una caché compartida entre peticiones.
4. **`docs/SEGURIDAD.md` como documento único de seguridad**, en vez de dividir por fase: a diferencia del resto de la documentación (que crece fase por fase), seguridad se beneficia de un documento consolidado y consultable de una sola vez antes de un despliegue, con referencias cruzadas a los documentos específicos (`CONFIGURACION_NAS.md`, `PROCESAMIENTO_VIDEO.md`, `SESIONES_EN_VIVO.md`) para el detalle de cada mecanismo.

### Archivos principales

- Backend: cambios puntuales en `app/Http/Controllers/{Cursos/CursoModuloController,Cursos/LeccionController,Cuestionarios/CuestionarioController,Actividades/ActividadController,Reuniones/SesionEnVivoController,Reuniones/AsistenciaController}.php`, `app/Http/Controllers/MiCapacitacion/MiCapacitacionController.php`, `app/Services/Capacitacion/ProgresoService.php`, `database/migrations/2026_07_07_090000_agregar_indices_de_rendimiento.php`.
- Pruebas: `tests/Feature/Seguridad/IntegridadRelacionesAnidadasTest.php` (6 pruebas), `tests/Feature/Rendimiento/ConsultasMiCapacitacionTest.php` (1 prueba).
- Documentación: `docs/SEGURIDAD.md` (nuevo), `docs/ARQUITECTURA.md` y este documento actualizados.

### Verificación realizada

- `php artisan migrate:fresh --seed` sobre MariaDB: OK.
- `php artisan test`: 154 pasaron, 3 omitidas (2FA, sin relación con esta fase), 0 fallidas — 157 pruebas totales en todo el proyecto.
- `composer types:check` (PHPStan/Larastan nivel 7): 0 errores.
- `composer lint:check` (Pint): sin diferencias.
- `npm run lint:check`, `npm run format:check`, `npm run types:check`: sin errores.
- `npm run build`: compiló correctamente.

### Pendiente / notas para el futuro del proyecto

- No se adoptó `Route::scopeBindings()` a nivel de framework; si el proyecto crece con más rutas anidadas, vale la pena revisitar esa decisión para evitar tener que recordar agregar la verificación manualmente en cada controlador nuevo.
- El checklist de despliegue a producción en `docs/SEGURIDAD.md` (caché de config/rutas/vistas, scheduler, colas, HTTPS, correo real, FFmpeg real, credenciales de Google Meet/Zoom) no se ejecutó contra un entorno de producción real — este proyecto se desarrolló íntegramente en un entorno local (Windows/WAMP).
- Con las 8 fases del encargo original completas, cualquier trabajo adicional (nuevas integraciones, reportes adicionales, mejoras de UX señaladas en las notas de cada fase) parte de una base con pruebas, análisis estático y documentación al día.
