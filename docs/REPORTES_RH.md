# Reportes RH

Pantalla `/rh/reportes` (permiso `reportes_rh.ver`) con un selector de reporte, filtros comunes (empresa, sucursal, departamento, puesto) y exportación a Excel/PDF (`reportes_rh.exportar`).

## Un único servicio, una única consulta

`App\Services\Reportes\ReportesRhService::generar(clave, usuario, filtros)` devuelve siempre la misma forma genérica:

```php
['titulo' => string, 'columnas' => string[], 'filas' => array<array<string|int|float|null>>]
```

La pantalla, el Excel (`App\Exports\ReporteRhExport`) y el PDF (`resources/views/pdf/reporte-rh.blade.php`) consumen **exactamente esa misma tabla** — no hay una consulta para pantalla y otra distinta para exportar. Todos los reportes respetan el alcance organizacional del usuario (`AlcanceOrganizacionalService`): un `gerente_sucursal` nunca ve filas de otra sucursal, ni exportando.

## Catálogo de reportes

| Grupo | Reportes |
|---|---|
| Plantilla | Número de empleados, por empresa, por sucursal, por puesto, con/sin IMSS, en periodo de prueba |
| Altas y bajas | Altas por mes, bajas por mes, rotación (últimos 12 meses) |
| Reclutamiento | Vacantes abiertas, vacantes cubiertas, candidatos viables, candidatos por sucursal/puesto |
| Expedientes y documentos | Expedientes completos/incompletos, documentos pendientes, documentos rechazados |
| Vacaciones, permisos y solicitudes | Vacaciones disponibles, vacaciones solicitadas, solicitudes pendientes, incapacidades |
| Fechas relevantes | Cumpleaños próximos (30 días), aniversarios laborales próximos (30 días) |

`ReportesRhService::catalogo()` es la única fuente de verdad de esta lista — agregar un reporte nuevo es: una entrada en `catalogo()`, un `case` en el `match` de `generar()`, y el método privado que arma la tabla.

## Filtros

`empresa_id`, `sucursal_id`, `departamento_id`, `puesto_id`, `colaborador_id`, `fecha_inicio`, `fecha_fin`, `estado`, `tipo_solicitud`, `tipo_documento` — cada reporte usa los que le aplican (p. ej. "empleados por puesto" ignora `fecha_inicio`).

## Rutas

```
GET /rh/reportes              Inertia::render('Rh/Reportes/Index', ...)
GET /rh/reportes/excel?reporte=...&empresa_id=...   descarga .xlsx
GET /rh/reportes/pdf?reporte=...&empresa_id=...     descarga .pdf
```

## Pendiente

- Gráficas (el dashboard RH ya tiene varias, ver `docs/PORTAL_RH.md`; esta pantalla es tabular a propósito, pensada para exportar).
- Reportes de "documentos pendientes"/"documentos rechazados" filtrados por `tipo_documento` desde la UI (el filtro existe en el servicio, falta el `<Select>` de tipos de documento en `Rh/Reportes/Index.vue`).
- Extender el mismo patrón de filtros + exportar Excel/PDF a los demás listados del sistema (vacantes, candidatos, expedientes, vacaciones) — hoy solo Reportes RH y Reporte de Cumplimiento (`docs/AUDITORIA_CUMPLIMIENTO.md`) lo tienen.
