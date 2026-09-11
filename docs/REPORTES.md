# Reportes

Un solo módulo: `Reportes` (`/reportes`, `App\Http\Controllers\Reportes\ReporteGeneralController`). El módulo viejo `Reportes RH` (`/rh/reportes`, `Rh\ReporteRhController`) sigue respondiendo por compatibilidad pero **ya no aparece en el menú** — el sidebar solo muestra "Reportes".

`ReporteGeneralController::index()` reutiliza `MetricasRhDashboardService::global()` — la misma fuente que ya alimenta el dashboard — más un resumen de "otros módulos" (vacantes abiertas, candidatos viables, vacaciones solicitadas, solicitudes pendientes) tomado de `ReportesRhService`. Lo que ves en pantalla es exactamente lo que exportas: `index()`/`exportarExcel()`/`exportarPdf()` piden los mismos datos.

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
