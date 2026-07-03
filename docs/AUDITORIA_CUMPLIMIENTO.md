# Auditoría de cumplimiento contra el encargo original

Fecha: 2026-07-03. Este documento es el resultado de una auditoría independiente de `docs/PLAN_IMPLEMENTACION.md`: revisa el estado **real** del código (migraciones, modelos, servicios, controladores, jobs, rutas, componentes Vue y pruebas), no lo que la bitácora de fases declaró como "✅ Terminada". Donde ambos documentos coinciden, se referencia `PLAN_IMPLEMENTACION.md`; donde difieren, este documento prevalece porque está basado en evidencia de código, no en la narrativa de cierre de cada fase.

Leyenda: ✅ Completo y verificable · 🔶 Parcial · 🎭 Simulado (solo mocks/`Http::fake`, sin implementación real) · 🔑 Pendiente de credenciales/licenciamiento · 🏗️ Pendiente de infraestructura (Nginx/FFmpeg/NAS reales) · ⛔ No implementado.

A partir de esta auditoría se ejecutó la **Fase 9** (ver `PLAN_IMPLEMENTACION.md`), que implementa todo lo que puede construirse y probarse sin credenciales reales, y deja preparada —con código real, no solo documentación— la integración de lo que sí las requiere.

---

## 1. Asistencia automática real de Google Meet

| Punto del encargo | Estado antes de la Fase 9 | Evidencia |
|---|---|---|
| Creación de reuniones vía Calendar | ✅ | `GoogleMeetProveedor::crearReunion()` |
| Consulta de `conferenceRecords`/`participants`/`participantSessions` | ⛔ | La interfaz `ProveedorSesionEnVivo` solo tiene `crearReunion`/`cancelarReunion`/`estaDisponible`; no existe ningún cliente de la Google Meet REST API. El scope OAuth configurado (`calendar.events`) ni siquiera alcanza para leer asistencia. |
| Hora de entrada/salida, reconexiones, minutos totales | ⛔ | `Asistencia.unido_en`/`salido_en`/`duracion_segundos` existen en el esquema desde la Fase 5 pero **ningún código los escribe nunca** (confirmado por auditoría de código: cero referencias de escritura fuera del modelo). |
| Asociación por correo, participantes anónimos/ambiguos → `pendiente_revision` | ⛔ | No existe tabla de participantes ni lógica de asociación. |
| Reintentos, sincronización manual/Job/Scheduler, idempotencia, evidencia del cálculo | ⛔ | No existe ningún Job de sincronización de asistencia (solo `MaterializarAsignacionJob`, ajeno a este tema). |
| Estados obligatorios (`pendiente`, `presente`, `asistencia_parcial`, `ausente`, `pendiente_revision`, `corregida_manualmente`) | 🔶 | `EstadoAsistencia` solo tenía `pendiente/presente/ausente/tarde`; faltaban `asistencia_parcial`, `pendiente_revision`, `corregida_manualmente`. |
| Reglas configurables por sesión (% mínimo, minutos mínimos, tolerancia, pre/post) | ⛔ | No existían columnas de configuración en `sesiones_en_vivo`. |

**Implementado en la Fase 9:** arquitectura completa de sincronización (tablas, servicios, Jobs, reglas configurables, estados obligatorios) descrita en las secciones 4-5 de este documento y en `docs/GOOGLE_MEET.md`. La llamada real a la Google Meet API **no puede ejecutarse ni probarse contra Google** en este entorno (no hay credenciales de Workspace ni cuenta de servicio real) — el cliente HTTP, el parseo de la respuesta, la asociación por correo y el cálculo de asistencia sí están implementados y cubiertos con pruebas usando `Http::fake()` con payloads con la forma real documentada por Google. Esto se declara explícitamente como 🔑 **pendiente de credenciales**, no como terminado.

## 2. Asistencia automática real de Zoom

| Punto del encargo | Estado antes de la Fase 9 |
|---|---|
| Creación/actualización/cancelación de reuniones | ✅ (creación y cancelación; no había `actualizar` que recreara la reunión externa) |
| Consulta de reportes de participantes | ⛔ |
| Webhooks (`meeting.ended`, `participant_joined/left`) con validación de firma | ⛔ — `services.zoom.webhook_secret` está declarado en config pero no se usa en ningún lugar; no existe ruta ni controlador de webhook |
| Idempotencia, Job, reintentos/backoff | ⛔ |

**Implementado en la Fase 9:** cliente de Report API, endpoint de webhook con verificación HMAC de firma (`x-zm-signature`) e idempotencia real, Jobs con reintentos/backoff. Igual que Meet, la llamada de red real contra Zoom es 🔑 pendiente de credenciales; el contrato, la firma y el cálculo están implementados y probados.

## 3-4. Tablas y modelo de datos para reuniones

Antes de la Fase 9 solo existían `sesiones_en_vivo` y `asistencias` (sin columnas de detalle de conexión). **Implementado en la Fase 9**: `registros_sesion`, `sesiones_participante`, `entradas_salidas_participante` (hija de sesiones_participante), `conexiones_integracion`, `webhooks_recibidos`, `sincronizaciones_reunion`, más columnas de reglas de asistencia en `sesiones_en_vivo` y de detalle en `asistencias`.

## 5. Jobs y Scheduler para reuniones

Antes: ⛔ ninguno existía. **Implementado en la Fase 9**: `SincronizarSesionGoogleMeetJob`, `SincronizarSesionZoomJob`, `ProcesarWebhookZoomJob`, `CalcularAsistenciasSesionJob`, más tareas de Scheduler de respaldo.

## 6. Corrección manual de asistencia

Antes: 🔶 parcial — `Asistencia.corregido_por`/`motivo_correccion` existían y el flujo exigía motivo, pero no se guardaba estado/minutos anteriores, IP, user-agent ni origen, y la pantalla no mostraba "datos de la API vs. resultado corregido" porque no existían datos de API que mostrar. **Ampliado en la Fase 9** con `HistorialCorreccionAsistencia` (o columnas equivalentes) y la información en la pantalla de asistencias.

## 7. Carga de video por bloques y reanudable

Antes: ⛔ **simulado por completo** — `cargas_multimedia` es una tabla fantasma (migración + modelo + factory sin ningún Controller/Service/Job que la use; 0 referencias en `resources/js/`). La carga real es un único `POST` con el archivo completo vía `useForm({forceFormData:true})`. El propio `PLAN_IMPLEMENTACION.md` (Fase 3, "Pendiente") ya lo admitía. **Implementado en la Fase 9**: sesión de carga, recepción por bloques, reanudación, pausa/cancelación, ensamblado, hash, expiración — descrito en la sección 7 de este documento.

## 8. Procesamiento real de video

🔶 Parcial, honesto desde el origen: el pipeline (ffprobe → miniatura → ffmpeg → HLS → variantes → manifiesto) está **codificado** en `FfmpegService`/`ProcesarVideoJob`, pero **nunca se ejecutó contra binarios reales** en este entorno Windows/WAMP (no hay FFmpeg instalado) — las pruebas usan `Process::fake()`. Esto ya estaba correctamente documentado como pendiente en `docs/PROCESAMIENTO_VIDEO.md` y `PLAN_IMPLEMENTACION.md` Fase 3. La Fase 9 no cambia este hecho (no se puede instalar FFmpeg en este entorno de auditoría); se añaden pruebas de contrato adicionales y se documenta explícitamente cómo se comportan skip cuando no hay binario, y qué pasos exactos verificar cuando sí lo haya.

## 9. X-Accel-Redirect y Nginx

Antes: ⛔ **documentado como "no implementado"** explícitamente en `PLAN_IMPLEMENTACION.md` Fase 3 ("es una optimización de rendimiento para producción... se documenta... en vez de escribir código que no puede verificarse aquí"). No existía `docs/nginx/` ni `deploy/nginx/`. **Implementado en la Fase 9**: `deploy/nginx/multimedia.conf` real y comentado, y el backend ahora sabe emitir la cabecera `X-Accel-Redirect` cuando `config('media.x_accel_redirect')` está activo (desactivado por defecto; en WAMP/desarrollo sigue sirviendo por streaming directo). Sigue siendo 🏗️ pendiente de infraestructura real para la verificación end-to-end (no hay Nginx delante de este entorno de desarrollo), pero el código y la configuración ya existen y se pueden activar con una variable de entorno.

## 10. Cuestionarios: tiempo y orden aleatorio

Antes: ⛔ ambos puntos explícitamente reconocidos como pendientes en `PLAN_IMPLEMENTACION.md` Fase 4 ("Pendiente / notas"):
- `tiempo_limite_minutos` se exponía al frontend pero **nunca se validó en el backend**; el frontend ni siquiera tenía temporizador.
- `aleatorizar_preguntas` se recalculaba con `shuffle()` en cada `GET`, no se fijaba una vez por intento; no existía aleatorización de opciones.

**Implementado en la Fase 9**: ver sección 10 más abajo.

## 11. Tipos de pregunta faltantes

Antes: ⛔ `TipoPregunta` solo tenía `opcion_unica`, `opcion_multiple`, `verdadero_falso`, `respuesta_corta`. **Implementado en la Fase 9**: `respuesta_larga`, `escala`, `carga_archivo`.

## 12. Privacidad de evidencias de actividades

Antes: ⛔ **hallazgo de seguridad confirmado por auditoría de código**: las evidencias de `EntregaActividad` se guardan como `RecursoMultimedia` normal (mismo `tipo=documento`, mismo `subido_por`), sin ninguna columna de origen/visibilidad, y `RecursoMultimediaController@index` las lista sin excluirlas — cualquier rol con `multimedia.administrar` (sin `respuestas.ver`) puede verlas/descargarlas/borrarlas desde la biblioteca general. **Corregido en la Fase 9**.

## 13. Auditoría de seguridad y aislamiento

Antes: 🔶 parcial — existía `tests/Feature/Seguridad/IntegridadRelacionesAnidadasTest.php` (6 pruebas de IDOR en rutas anidadas) y `tests/Feature/Administracion/AislamientoSucursalTest.php`, pero:
- `RecursoMultimediaPolicy`, `CuestionarioPolicy`, `ActividadPolicy`, `SesionEnVivoPolicy` son permisos Spatie **planos**, sin `AlcanceOrganizacionalService` — un instructor ve/califica entregas de **cualquier** sucursal, no solo la propia.
- No había pruebas de webhooks inválidos/duplicados (no existían webhooks) ni de sincronización repetida.

**Ampliado en la Fase 9** con nuevas pruebas y, donde el hallazgo lo ameritaba (evidencias privadas), con corrección de código real, no solo con una prueba que documenta el hueco.

## 14-15. Documentación de Google Meet y resumen de activación

Antes: `docs/SESIONES_EN_VIVO.md` cubre la integración de creación de reuniones, pero no la sincronización de asistencia (no existía). No había `docs/GOOGLE_MEET.md`, `docs/ACTIVACION_GOOGLE_MEET.md` ni `docs/RESUMEN_ACTIVACION_PRODUCCION.md`. **Creados en la Fase 9.**

---

## Nota metodológica

Esta auditoría se basó en lectura directa del código fuente (no en `PLAN_IMPLEMENTACION.md`), incluyendo: todos los modelos, servicios, controladores, form requests y rutas de los módulos de Reuniones, Multimedia y Evaluación; las migraciones completas de cada tabla involucrada; los `Policies`; `AlcanceOrganizacionalService`; y las pruebas existentes en `tests/Feature/Seguridad/`. Los hallazgos de "tabla fantasma" (`cargas_multimedia`) y "evidencias sin privacidad" (`recursos_multimedia`) se confirmaron con búsquedas de texto (`grep`) de cada símbolo en todo `app/` y `resources/js/`, no por inspección superficial.
