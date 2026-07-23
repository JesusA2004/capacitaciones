# Capacitación — oculta, no eliminada

A partir de este checkpoint, el sistema deja de presentarse principalmente como "Portal de Capacitación" y pasa a ser el **Portal Integral de Colaboradores y Recursos Humanos Mr. Lana**. Capacitación se convierte en una fase futura (Fase 5 del roadmap, ver `docs/PORTAL_RH.md`), pero **no se borró nada**.

## Qué se conserva intacto

- Modelos, migraciones y seeders (`CursoInduccionSeeder`, etc.).
- Controladores y Form Requests de Cursos, MiCapacitacion, Multimedia, Cuestionarios, Actividades, Reuniones y Reportes.
- Rutas (`routes/cursos.php`, `routes/mi-capacitacion.php`, `routes/multimedia.php`, `routes/cuestionarios.php`, `routes/actividades.php`, `routes/reuniones.php`, `routes/reportes.php`, `routes/asignaciones.php`).
- Componentes y páginas Vue (`resources/js/pages/Cursos`, `MiCapacitacion`, `Multimedia`, `Cuestionarios`, `Actividades`, `Reuniones`, `Reportes`).
- Policies, permisos de Spatie (`cursos.*`, `multimedia.administrar`, `cuestionarios.administrar`, `sesiones.administrar`, etc.) y datos ya cargados en la base de datos.
- Tests (`tests/Feature/Cursos`, `Multimedia`, `Cuestionarios`, `Actividades`, `Reuniones`, `Reportes`, `Rendimiento/ConsultasMiCapacitacionTest.php`).

Nada de esto se movió ni se tocó funcionalmente. Solo se le puso una puerta delante.

## Qué se ocultó

Con `CAPACITACION_ENABLED=false` (ver `docs/FEATURE_FLAGS.md`):

- El sidebar ya no lista Cursos, Mi capacitación, Asignaciones, Biblioteca multimedia, Banco de preguntas, Calificar cuestionarios/actividades, Reporte de cumplimiento ni Calendario.
- En su lugar hay un único acceso **"Capacitación"** con badge **"Próximamente"**, que lleva a `resources/js/pages/Capacitacion/Proximamente.vue` (ruta `capacitacion.proximamente`, `/capacitacion`).
- Si alguien entra por URL directa a una ruta de capacitación, el middleware `App\Http\Middleware\EnsureFeatureEnabled` (alias `feature:capacitacion`) intercepta la petición:
  - En una petición `GET` renderiza la misma pantalla "Próximamente" en esa URL.
  - En una petición de escritura (`POST`/`PUT`/`DELETE`) responde `403`.

## Pantalla "Próximamente"

- **Título**: "Capacitación y Desempeño".
- **Mensaje**: "Disponible en Fase 2. Cursos, videos, evaluaciones, seguimiento e indicadores de desempeño." (texto pedido explícitamente por el encargo; el roadmap detallado en `docs/PORTAL_RH.md` la ubica como Fase 5).
- Lista los módulos conservados (cursos, biblioteca multimedia, cuestionarios/actividades, sesiones/asistencias, reportes) para dejar claro que siguen ahí, solo ocultos.

## Cómo reactivar

Ver la sección correspondiente en `docs/FEATURE_FLAGS.md`: basta con `CAPACITACION_ENABLED=true` y limpiar config. No hace falta migrar ni re-sembrar nada.
