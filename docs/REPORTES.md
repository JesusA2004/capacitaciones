# Reportes

Un solo módulo, una sola implementación: `Reportes` (`/reportes`, `App\Http\Controllers\Rh\ReporteRhController` + `App\Services\Reportes\ReportesRhService`) — tabla filtrable por empresa/sucursal/departamento/puesto, con catálogo de reportes agrupado (Plantilla, Altas y bajas, Reclutamiento, Expedientes y documentos, Vacaciones/permisos/solicitudes, Fechas relevantes). El sidebar solo muestra "Reportes"; la ruta `rh.reportes.*` (`/rh/reportes`) sigue existiendo y apunta al **mismo controlador**, solo para no romper el nombre de ruta usado en pruebas — no es una segunda pantalla ni una segunda consulta.

Antes existía un `ReporteGeneralController` que reutilizaba `MetricasRhDashboardService::global()` (los mismos componentes del Dashboard) con un `setInterval` de 45s haciendo polling — era una segunda implementación del mismo reporte, no una vista distinta, así que se eliminó junto con `Reportes/Index.vue` y `ReporteGeneralExport`. `index()`/`exportarExcel()`/`exportarPdf()` de `ReporteRhController` piden exactamente los mismos datos (`ReportesRhService::generar()`), así que lo que exportas es siempre lo que ves en pantalla, sin polling: el usuario actualiza con los filtros o recargando la página.

## Dashboard — Rotación de personal

El dashboard (`Dashboard/Global.vue`/`Dashboard/Sucursal.vue`, ambos vía `App\Http\Controllers\DashboardController`) ya no tiene un título fijo "Portal RH": ese espacio ahora es el bloque de **rotación de personal en tiempo real** (`resources/js/components/Dashboard/RotacionPersonal.vue`), con filtros de sucursal y rango de fechas que actualizan los datos sin recargar la página (`GET /dashboard/rotacion`, JSON).

KPIs (`App\Services\Reportes\MetricasRhDashboardService::rotacion()`):

- **Plantilla actual**: colaboradores activos dentro del alcance/filtro.
- **Altas / Bajas del periodo**: contadas desde `movimientos_laborales` (tipo `alta`/`baja`), **no** desde `users.deleted_at` — una baja hecha vía Solicitudes no hace soft-delete del usuario (solo bloquea acceso, ver `docs/SOLICITUDES_UNIFICADAS.md`), así que `movimientos_laborales` es la única fuente que cuenta ambos orígenes (baja administrativa directa y baja vía solicitud) sin perder ninguna.
- **% Rotación** = bajas del periodo ÷ plantilla actual × 100 — misma fórmula que el índice de rotación histórico de dirección (`claude/rotacion/`).
- **Cumplimiento headcount**: reutiliza `HeadcountService::totalesGenerales()` (ver `docs/HEADCOUNT_Y_VACANTES.md`), acotado a la sucursal filtrada si aplica.
- **Composición por género**: cuenta `users.genero` (`masculino`/`femenino`/`sin_especificar` — nunca se infiere, es un dato que captura el colaborador o RH; "sin especificar" se calcula por resta contra la plantilla total, no leyendo su propia clave, para no contar dos veces un valor `NULL`).
- **Altas/bajas por sucursal**: agrupado desde los mismos movimientos, para la gráfica de barras horizontales.
- **Tendencia mensual**: últimos 6 meses de altas/bajas (no se reconstruye una plantilla histórica exacta — no hay snapshots mensuales guardados — solo altas/bajas, que sí son reconstruibles con exactitud desde `movimientos_laborales`).

Exportar: botones Excel (`App\Exports\RotacionPersonalExport`) y PDF (`resources/views/pdf/rotacion-personal.blade.php`, con gráficas nativas del mismo `ChartRenderer` que usa el resto de exportaciones) — ambos respetan los filtros activos en pantalla.

Gráficas renderizadas con **ApexCharts** (`vue3-apexcharts`) — primera librería de gráficas del lado del navegador en el proyecto (las exportaciones Excel/PDF siguen usando gráficas nativas server-side, sin relación con esta).

## Qué falta por cruzar aquí

El encargo pide que Reportes también incluya vistas por región/zona/ruta (matriz comercial) y un desglose de solicitudes por tipo con exportación — todavía no están en `/reportes`, solo los KPIs de rotación descritos arriba. La matriz comercial y su cobertura se consultan hoy en `Administración → Matriz comercial` (`docs/ORGANIGRAMA.md`), no en Reportes.
