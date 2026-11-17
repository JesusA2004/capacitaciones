# Capacitación (Fase 3 de PEOPLE)

Algo tipo Google Classroom + «Certificación Mr. Lana», construido **sobre el código de capacitación que ya existía** (cursos, módulos/lecciones, cuestionarios, actividades, sesiones en vivo, asignaciones, progreso y constancias). Bandera `capacitacion` **encendida por defecto desde 2026-11-17** (ver `docs/FEATURE_FLAGS.md`); `certificacion` también.

## Qué ya existía (no se rehízo)

| Pieza | Dónde |
|---|---|
| Cursos, módulos, lecciones, constructor | `CursoController`, `CursoBuilderService`, `Cursos/Constructor.vue` |
| Asignaciones por usuario/sucursal/puesto… | `AsignacionService`, `Asignaciones/*` |
| Progreso y bloqueo por requisitos/orden | `App\Services\Capacitacion\ProgresoService` |
| Cuestionarios, actividades, calificación | `Cuestionarios/*`, `Actividades/*`, `respuestas.ver`/`respuestas.calificar` |
| Sesiones en vivo y asistencias | `Reuniones/*` |
| Constancias (folio, PDF, verificación pública) | `CertificadoService`, `MiCapacitacion\CertificadoController`, `constancias.verificar` |
| Reporte de cumplimiento | `reportes.cumplimiento.*` |

## Qué se agregó en la Fase 3

- **Portada operativa** `GET /capacitacion` (`capacitacion.index`, `PortadaCapacitacionService`, `Capacitacion/Index.vue`): indicadores, cursos terminados por mes, avance de inscripciones, tarjetas de aulas con su avance, próximas sesiones. Acceso: `CursoPolicy::portada` (`cursos.ver`/`asignaciones.ver`/`respuestas.ver`/`reportes.globales`/`reportes.sucursal`).
- **Aula tipo Classroom** `GET /cursos/{curso}/aula` (`cursos.aula`, `AulaService`, `Cursos/Aula.vue`) con cuatro secciones:
  - *Tablón*: próximas sesiones + entregas, cuestionarios enviados y constancias recientes (derivado de lo existente).
  - *Trabajo asignado*: lecciones de cuestionario/actividad/sesión con cuántos las completaron y cuántas esperan calificación.
  - *Calificaciones*: participante × trabajo calificable (mejor calificación de cuestionario, última entrega de actividad).
  - *Participantes*: inscritos con su progreso y su constancia.
  Acceso: `CursoPolicy::aula` (`cursos.ver`).
- **Mi capacitación** (Mi espacio) `GET /mi-capacitacion`, `/mi-capacitacion/pendientes`, `/mi-capacitacion/certificados` (`MiCapacitacionService`, `MiCapacitacion/Index.vue`): mis aulas con progreso y fecha límite, pendientes (solo lecciones desbloqueadas, las actividades entregadas aparecen «En revisión»), mis constancias descargables y mi nivel de certificación. Siempre opera sobre el usuario autenticado.
- **Certificación Mr. Lana** `GET /capacitacion/certificacion` (`capacitacion.certificacion`, `CertificacionMrLanaService`, `App\Enums\NivelCertificacion`): niveles Bronce/Plata/Oro según **constancias emitidas**; umbrales en `config/capacitacion.php` (`CERTIFICACION_UMBRAL_BRONCE/PLATA/ORO`, por defecto 1/3/5). Tablero de quién está en qué nivel. Acceso: `CursoPolicy::certificacion` + `feature:certificacion`.

Todo lo que cuenta personas usa la regla de alcance de capacitación `AlcanceOrganizacionalService::idsColaboradoresParaRevision()` (alcance de sucursal → solo su gente; instructor/administración de capacitación/RH → todos), igual que «Calificar cuestionarios/actividades».

## Navegación

Ver `docs/ROLES_Y_NAVEGACION.md`: grupo «Capacitación» en Operación RH (dentro de la etapa 6, sin número) y «Mi capacitación» en Mi espacio.

## Pendientes de negocio (base mínima, no inventada)

1. **Rutas de certificación por puesto**: hoy el nivel cuenta constancias; falta definir qué cursos exactos forman cada nivel/ruta por puesto, y si la certificación vence o se renueva.
2. **Tablón con publicaciones propias** (avisos del instructor, comentarios): requiere una tabla nueva; hoy el tablón es solo actividad derivada.
3. **Portada del curso**: el modelo tiene `imagen_portada_path` pero no hay carga de imagen; se usa una portada decorativa por id.
4. **Quién administra capacitación**: los permisos `cursos.*`, `asignaciones.*`, `respuestas.*` solo los tienen `super_admin`, `administrador_capacitacion` e `instructor` (más `asignaciones.ver`/reportes en algunas gerencias). Si RH (`rh_admin`) debe operar el módulo, se otorga desde «Roles y permisos» o con una migración de datos explícita.
5. **Inscripciones por `User`**: capacitación sigue ligada a la cuenta (`user_id`), no a `Colaborador`. Funciona porque todo colaborador activo tiene cuenta; migrarlo a Colaborador-first es una decisión aparte.
6. `induccion_sesiones_legacy` **sigue apagado**: la inducción institucional no se mezcla con este módulo.
