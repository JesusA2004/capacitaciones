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

## El mismo patrón, extendido a los listados operativos

Los 8 listados operativos de RH (Vacantes, Candidatos, Altas digitales, Plantillas,
Formatos, Solicitudes, Expedientes, Vacaciones) reutilizan las mismas dos piezas
genéricas que este módulo originó — `App\Exports\ReporteRhExport` y
`resources/views/pdf/reporte-rh.blade.php` — para su propia exportación Excel/PDF,
cada uno con sus propios filtros y su propia query (ver
`docs/ARQUITECTURA_SERVICES.md`, sección "Patrón de listados con filtros +
exportación"). Este módulo (Reportes RH) sigue siendo el único con selector de
*reporte* (múltiples tablas agregadas); los demás exportan directamente el listado tal
como se ve filtrado en pantalla.

## Rotación de personal (carga de Excel)

Pantalla aparte en el menú operativo (grupo «Organización», debajo de Reportes):
`/reportes/rotacion` (`reportes.rotacion.*`). No sustituye al KPI en vivo del
dashboard (`MetricasRhDashboardService::rotacion()`, calculado de altas/bajas del
sistema): guarda la rotación **que RH reporta en su Excel** «INDICE DE ROTACIÓN DE
PERSONAL» (archivo real en `docs/rotacion/` y `claude/rotacion/`).

- **Estructura leída del Excel real** (`App\Services\Rotacion\RotacionExcelLector`):
  hojas `INDICADOR 2025`, `INDICADOR X SUCURSAL 2025`, `SUCURSALES 2026` (año = nombre
  de la hoja). Un bloque por sucursal: nombre arriba (celda combinada F:J), fila de
  meses B..M (`ENERO`…`SEPT`, `OCT`, `NOV`, `DIC`) + `TOTALES` en N; en la columna A
  `ALTAS`, `BAJAS`, `PLANTILLA` (= anterior + altas − bajas), la plantilla inicial
  (número suelto en A debajo de PLANTILLA) y `ROTACIÓN` (= bajas × 100 / plantilla del
  mes). N/O traen «PROMEDIO MENSUAL» y «ROTACIÓN ANUAL»; al pie, resúmenes («Bajas
  enero», «Rotación General», «ROTACIÓN MENSUAL SUCURSALES»). El lector se guía por
  etiquetas, no por posiciones. No hay bajas voluntarias/involuntarias ni departamento
  en el archivo real; el formato actualizado agrega las dos filas de bajas como
  opcionales.
- **Flujo**: subir → análisis (vista previa con hallazgos `error` / `advertencia` /
  `informativo`, `App\Enums\NivelHallazgoRotacion`) → confirmar o descartar. Nada se
  ignora en silencio: bloques sin nombre (el consolidado de `INDICADOR 2025` y dos
  bloques sueltos), sucursales que no están en el catálogo, coincidencias aproximadas
  de nombre («SAN LUIS» → «San Luis Potosí»), ROTACIÓN del Excel distinta de la
  recalculada (Atlacomulco enero está ÷ 3), PLANTILLA que no cuadra, TOTALES que no
  suman, errores `#REF!` del resumen, meses sin captura.
- **Tablas**: `rotacion_cargas` (archivo original en el disco `nas` bajo `rotacion/`,
  `subido_por`, `aplicado_por`, `resumen`, `detalle` con periodos + hallazgos) y
  `rotacion_periodos` (único por sucursal + año + mes). Volver a subir un mes lo
  reemplaza: la carga guarda los valores anteriores y la bitácora registra
  `rotacion.carga_aplicada` con antes/después.
- **Consulta** (`RotacionConsultaService`): últimos 12 meses (`config/rotacion.php`),
  filtro por sucursal, índice mensual consolidado = Σ bajas × 100 / Σ plantilla final,
  promedio mensual y rotación acumulada por sucursal. Alcance del módulo `rotacion`
  (`config/permisos_modulos.php`) vía `AlcanceModuloService`.
- **Permisos**: `rotacion.ver` (rh_admin, rh_auxiliar, dirección, director comercial,
  auditor, sistemas) y `rotacion.cargar` (rh_admin; super_admin todo). En producción:
  `php artisan migrate --force && php artisan people:sincronizar-permisos`.
- **Formato actualizado** (`RotacionFormatoService`, botón «Descargar formato»): misma
  estructura que `SUCURSALES 2026`, con las sucursales activas del catálogo (nombres
  exactos), prellenado con los meses ya cargados, ROTACIÓN sin `#DIV/0!` y PROMEDIO /
  ROTACIÓN ANUAL como fórmulas.
- Alias de nombres confirmados por el negocio: `config/rotacion.php → alias_sucursales`.

## Pendiente

- Gráficas (el dashboard RH ya tiene varias, ver `docs/PORTAL_RH.md`; esta pantalla es tabular a propósito, pensada para exportar).
- Reportes de "documentos pendientes"/"documentos rechazados" filtrados por `tipo_documento` desde la UI (el filtro existe en el servicio, falta el `<Select>` de tipos de documento en `Rh/Reportes/Index.vue`).
