# MR. LANA PEOPLE — Etapa 1 · Matriz de trazabilidad e informe de implementación

> Documento vivo. Mapea cada pantalla/instrucción del PDF *"Propuesta de mejoras, Etapa 1"*
> (`docs/propuesta/Mr_Lana_People_Propuesta_de_mejoras_Etapa1.pdf`, 26 pp., 29/10/2026) contra
> el código real, la diferencia detectada, la modificación propuesta y la prueba que la verifica.
>
> **SHA inicial backend:** `c9b8c217f0a6af559694f5d7ba4b1a0b9ec0196d` (rama `main`)
> **SHA inicial app:** `40b9e828baa41e2c28d513230fb81b198d2fc3c7` (rama `main`, árbol limpio) — ⚠️ el repo está **adelante** del SHA `643b368f` del brief; la app ya tiene módulo de candidatos completo (Expo SDK 57, expo-router, react-query, zustand). Docs de la app desactualizados.
> **Fecha de inicio de auditoría:** 2026-10-29

## Leyenda de estado

- 🔴 **Pendiente** — no implementado / solo diseño en PDF.
- 🟡 **Parcial** — existe base reutilizable, requiere cambios.
- 🟢 **Hecho y verificado** — implementado, persiste y probado.
- 🔵 **Reutilizado sin cambios** — ya cumple la propuesta.

## Decisiones / tensiones detectadas (requieren confirmación RH/Dirección/Jurídico)

| # | Tema | Hallazgo | Propuesta |
|---|---|---|---|
| D1 | Paleta | El PDF fija una "paleta oficial" corporativa (petróleo `#0D3E43`, esmeralda `#225C54`, bosque `#2F5937`, bronce `#754711`, dorado `#AF8B51`, crema `#E9D6B0`) + semáforo (`#1FA774`/`#D99A1E`/`#D64550`). `CLAUDE.md` exige **solo tema claro** con paleta pastel/crema existente y prohíbe fondos oscuros. | Tratar la paleta del PDF como **tokens de acento/chips/semáforos y encabezados**, no como cambio de tema. Centralizar en `resources/css/app.css` (web) y `src/constants/colors.ts` (app). Sin `dark:*`, sin fondo oscuro salvo login. |
| D2 | Rotación doble motor | Existen 2 motores con **distinta fórmula y fuente**: (A) `RotacionConsultaService` (Excel, denominador = plantilla final) alimenta el Tablero; (B) `TableroRotacionService` (Excel+BD, denominador = plantilla promedio, por `fecha_baja`) alimenta Bajas. | Unificar hacia la fórmula del PDF (promedio) con fuente híbrida conciliada por `mes_inicio_sistema`. El Tablero debe consumir el mismo servicio que Bajas. |
| D3 | Corporativo 121 vs 151 | Tablero muestra 121 autorizadas porque faltan/incompletas las filas `headcount_targets` del Corporativo (puestos "sin plaza autorizada"). | Reconciliación de datos (script de diagnóstico, carga auditable de plazas de Corporativo), no cambio de fórmula. |
| D4 | NR → indemnización | El PDF marca NR-01/03/05/06 como "Implica indemnización" (liquidación), pero la indemnización real depende del supuesto jurídico. | Implementar la marca como **regla configurable + advertencia**, nunca como cálculo legal garantizado. Validación jurídica por caso. |
| D5 | Plazo aviso rescisión | PDF usa "5 días hábiles" con `addWeekdays(5)` implícito. | Implementar cómputo con días hábiles **y festivos oficiales**, configurable; etiquetar como apoyo administrativo, no notificación legal. |
| D6 | Layout NOI | No se dispone del layout real de Aspel NOI. | Capa de mapeo configurable + exportador "Pendiente de validar con NOI". No declarar comprobado sin el layout. |
| D7 | Matriz de mérito | Requiere evaluación de desempeño vigente; puede no existir (solo inducción/periodo de prueba). | Si no hay evaluación válida → "Sin evaluación de desempeño vigente". Nunca convertir recomendación en aumento automático. |

---

## Incremento 1 — Inventario y reconciliación

### Script de diagnóstico (solo lectura) — 🟢 hecho

`php artisan etapa1:diagnostico [--json]` (`app/Console/Commands/Etapa1DiagnosticoCommand.php`): no escribe nada; cubre los 12 puntos de §12. Resultados en el entorno de desarrollo (datos demo, no producción — las cifras del PDF 151/134/17 son de producción):

| Punto | Hallazgo (dev) |
|---|---|
| 1 Corporativo | **0 plazas autorizadas** vs 15 vigentes → confirma la causa de "121 vs 151". Requiere carga auditable de plazas (D3), **sin fabricar**. |
| 2 Activo vs plaza | 177 vigentes, 123 ocupadas, **54 fuera de plantilla / excedentes** por definir. |
| 3 Directorio | **121 de 169 activos excluidos** (grupo `gestor`) → ~48 visibles (coincide con "hoy muestra 50" del PDF). |
| 4 Incapacidades hist. | 1 solicitud histórica a preservar. |
| 5 Bajas Jurídico | **0 bajas** en `en_revision_juridico` (migración de estados sin riesgo), **9 causales** con `requiere_juridico`. |
| 6–7 Bajas/Finiquitos | 0 bajas, 0 finiquitos en dev. |
| 8 Rotación | 0 Excel, corte `2026-10`, 0 bajas sistema. |
| 9 Salarios | sin tabla tabulador; 168 vigentes sin sueldo capturado (demo). |
| 10 Agenda | 21 entrevistas + 16 socioeconómicos ya registrados (realizados). |
| 11 Alta IMSS | 0 con IMSS (demo). |
| 12 NOI | faltantes de RFC/CURP/NSS/salario en demo. |

### Tablero de RH (PDF pág. 3–4)

| # inst. | Comportamiento actual | Archivo real | Diferencia | Modificación | Prueba | Estado |
|---|---|---|---|---|---|---|
| 1 Plantilla única | `HeadcountService::totalesGenerales()` computa en vivo; `headcount_targets` guarda solo autorizadas; Corporativo = sucursal `CORP01`. Tablero muestra 121 por faltar targets de Corporativo. | `app/Services/Headcount/HeadcountService.php`, `app/Models/HeadcountTarget.php`, `config/headcount.php` | Falta reconciliar plazas de Corporativo (121→151). Dos cómputos solapados (`totalesGenerales` vs `coberturaDetallada`). | Script diagnóstico (D3) + consolidar semántica de población por KPI. | Aceptación #1, #2 | 🟡 |
| 2 Cuatro bloques, dato único | Tablero actual repite plantilla activa/vacantes/cobertura. | `app/Services/Reportes/TableroRhService.php::construir()`, `resources/js/pages/Dashboard/*` | Reagrupar en Plantilla / Movimiento / Reclutamiento / Permanencia; cada dato 1 vez. | Rediseñar DTO + página. | Visual pág. 3 | 🟡 |
| 3 Rotación del mes ↔ Bajas | Tablero usa motor (A) Excel. | `TableroRhService::rotacionDelMes()` → `RotacionConsultaService` | Debe usar bajas del sistema por último día (motor B). | Enrutar a servicio unificado (D2). | Aceptación #16 | 🟡 |
| 4 Gráfica 12m con meta | Existe serie mensual. | `TableroRhService::turnoverMensual()` | Falta valor sobre barra, meta punteada, semáforo, "en curso", selector 6/12/24. | Front + DTO. | Visual pág. 3 | 🟡 |
| 5 Cobertura Corp vs Suc | `resumenPorSucursal()` disponible. | `HeadcountService` | Falta barra semáforo por ubicación (verde ≥95 / ámbar 85–94 / rojo <85). | Front. | Visual | 🟡 |
| 6 Embudo real | `embudo()` usa `etapa_maxima`. | `TableroRhService::embudo()` | Verificar que cada etapa cuente solo a quienes llegaron + % de paso. | Ajuste cómputo. | Visual | 🟡 |
| 7 Tiempo por nivel | `tiempoPorNivel()`. | `TableroRhService::tiempoPorNivel()` | Barras horizontales escala automática. | Front. | Visual | 🟡 |
| 8 Plantilla por sucursal | `resumenPorSucursal()`. | idem | Barras escala común 100%, chip %, orden por cobertura, Corporativo al final, clic filtra todo el tablero. | Front + estado de filtro. | Visual | 🟡 |

### Directorio (PDF pág. 5) — 🟢 hecho y verificado

Commit: relajar exclusión de gestores + tarjeta/filtro/etiqueta "Datos por completar".

| # inst. | Comportamiento actual | Archivo real | Modificación aplicada | Prueba | Estado |
|---|---|---|---|---|---|
| 1 Mostrar todas las activas | Excluía `grupo_documental='gestor'` y `nombre LIKE 'Gestor%'`. | `config/directorio.php`, `DirectorioColaboradoresService.php` | `excluir_grupos`/`excluir_puestos` → `[]` (mecanismo conservado por si RH quiere ocultar algo); gating de contacto intacto en `aArray()`/`puedeVerContacto()`. | `DirectorioTest` (gestores aparecen; API también) | 🟢 |
| 2 Tarjetas Corp/Suc + total | Tarjeta "Sucursales" contaba # sucursales, no personas. | `Directorio/Index.vue` | 4 tarjetas: Personas activas / Corporativo / Sucursales (personas) / Datos por completar; Corp+Suc = activas por construcción. | `DirectorioTest` (invariante Corp+Suc=activas) | 🟢 |
| 3 Aviso + filtro datos por completar | Sin etiqueta ni filtro. | servicio + Vue | Bandera `datos_por_completar` (gateada a RH) en `aArray()`; etiqueta por fila + filtro "Solo datos por completar" (tarjeta clicable). | `DirectorioSeguridadTest` (campo en lista cerrada), `DirectorioTest` (bandera null para operativo) | 🟢 |

> Pendiente menor: truncado "Ver los N →" por grupo (hoy lista completa) y regresión visual con captura del app corriendo.

---

## Incremento 2 — Bajas y Rotación

### Bajas (PDF pág. 10, 15) — flujo sin Jurídico, documentos de salida

| # inst. | Comportamiento actual | Archivo real | Diferencia | Modificación | Prueba | Estado |
|---|---|---|---|---|---|---|
| 1 Un apartado, 4 pestañas | Hay "Bajas" y "Bajas y finiquitos" duplicadas en sidebar. | `resources/js/pages/Rh/Bajas/*`, `Rh/Cierres/*`, `useMainNavItems.ts` | Unificar en Bajas con pestañas Bajas/Finiquitos/Rotación/Catálogo. | Reestructurar nav + página con tabs; redirecciones de rutas viejas. | Aceptación #18 | 🟡 |
| 2 Flujo sin Jurídico | Gate `causal.requiere_juridico` → `EstatusBaja::EnRevisionJuridico`; cierre bloqueado. | `app/Enums/EstatusBaja.php`, `app/Services/Bajas/BajaService.php` (117-138, 305-312, 388-444), `BajaConsultaService.php`, `Models/Baja.php:187`, `Models/CausalRotacion.php` | Quitar gate obligatorio; Jurídico solo consulta. Estados: En trámite→Faltan docs→Lista para cerrar→Cerrada (+No procede). | Migración auditable de filas `en_revision_juridico`; refactor estados; Policy. | Aceptación #11, #12 | 🟡 |
| 3 Listado bajas | Existe listado. | `BajaConsultaService`, `Rh/Bajas/Index.vue` | Columnas: colaborador/sucursal/folio/último día/forma/causal/estatus/docs/finiquito; etiqueta "Integrar expediente probatorio" en rescisión. | Front + consulta. | Visual pág. 10 | 🟡 |
| 4 Generar documento salida | Motor documentos maestros existe (contratos). | `docs/MOTOR_DOCUMENTOS_MAESTROS.md`, servicios NAS | Generador por forma (RV carta / NR convenio / RE aviso / OT constancia) con vista previa, PDF, subir firmado, historial NAS. | Nuevo servicio + plantillas maestras. | Aceptación #13, #15 | 🔴 |
| 5 Aviso rescisión hechos | — | — | Campos: causal, fracción art. 47, hechos circunstanciados, entrega, testigos, evidencia. | Nuevo. | Aceptación #13 | 🔴 |
| 6 Entrega + plazo | — | — | Entregado/Se negó; alerta plazo (D5) con días hábiles+festivos; aviso "apoyo administrativo". | Nuevo + servicio de cómputo. | Visual pág. 10 | 🔴 |

### Finiquitos (PDF pág. 11, 16)

**Hallazgos:** `FiniquitoService` (único), modelos `FiniquitoCalculo`/`FiniquitoConcepto`/`FiniquitoAjuste` (append-only, auditable con motivo/usuario/valor_calculado/valor_final). Montos `DECIMAL(10,2)`. Calcula sueldo pendiente, vacaciones pendientes+proporcionales, prima vacacional (25% config), aguinaldo proporcional (15 días, año calendario), indemnización. **Estados reales: `Borrador|Revisado|Aprobado|Firmado|Pagado` (5)** — "Calculado"=Borrador, `Aprobado` extra entre Revisado y Firmado. PDF firmado físico ya existe (`marcarFirmadoFisicamente`). `asegurarNoFirmado()` bloquea editar tras Firmado/Pagado (no hay flujo de corrección post-firma).

| # inst. | Diferencia | Modificación | Prueba | Estado |
|---|---|---|---|---|
| F1 Pestaña independiente | No existe índice/pestaña propia; se llega por cierres. | Listado con filtros, estado, monto, fecha pago; abre/edita sin entrar al expediente (mismo registro). | Aceptación #14 | 🔴 |
| F2 Estados (mapear a 4) | 5 estados reales. | Presentar Calculado(Borrador)→Revisado→Firmado→Pagado; mostrar `Aprobado` como subestado, no romper. | Visual pág. 11 | 🟡 |
| F3 Detalle editable | Ya existe con ajustes auditables. | Reusar; recalcular no borra ajustes sin advertencia. | — | 🔵 |
| F4 Finiquito vs liquidación | Distinción solo por monto indemnización. | Derivar tipo de `TipoBaja::contemplaIndemnizacion()` + marca causal (D4); etiquetar. | Aceptación #14 | 🟡 |

### Catálogo de causales (PDF pág. 14, 16)

**Hallazgos:** 17 causales en `config/bajas.php` → `causales_rotacion`. Columnas: `forma_salida`, `requiere_juridico` (**= la etiqueta "Jurídico"**), `comentario_obligatorio`, `alerta`, `excluye_indemnizacion` (**negativa**, solo RE-01), `legado`, `reemplazada_por_id`. Seeder idempotente (no sobrescribe ediciones RH). **RE-06 NO existe. Grupo OT NO existe (solo RV/NR/RE).** `FormaSalida` tiene 3 casos.

| # inst. | Diferencia | Modificación | Prueba | Estado |
|---|---|---|---|---|
| C1 Etiqueta "Integrar expediente probatorio" | Hoy "Jurídico" via `requiere_juridico`. | Renombrar la etiqueta UI; el flag deja de ser compuerta (Incr.2) y pasa a ser aviso probatorio. Conservar históricos. | Aceptación #11 | 🟡 |
| C2 Marca "Implica indemnización" + RE-06 | Solo existe `excluye_indemnizacion` (negativa). | Agregar flag **positivo** `implica_indemnizacion` en NR-01/03/05/06 (regla+advertencia, D4). RE-06 **no crear** (no existe). | Aceptación #14 | 🔴 |
| C3 Grupo OT nuevo | No existe. | Agregar `FormaSalida::OT` + OT-01 Fallecimiento / OT-02 Incapacidad permanente total / OT-03 Jubilación o pensión, con flujos/documentos propios (no forzar misma carta). | — | 🔴 |

### Rotación (PDF pág. 12–13, 17)

**Hallazgos:** motor (B) `TableroRotacionService` ya es híbrido (Excel+BD, promedio, por `fecha_baja`, corte `mes_inicio_sistema`, `plantilla_mensual`), con dedup por mes y conciliación por corte. Motor (A) `RotacionConsultaService` (Excel, plantilla final) alimenta Tablero y reporte.

| # inst. | Diferencia | Modificación | Prueba | Estado |
|---|---|---|---|---|
| R1 Cinco vistas | Existe tablero rotación. | Vistas Global/Corporativo/Todas/ Por sucursal/Por causales con filtros periodo/puesto/forma. | Aceptación #18 | 🟡 |
| R2 Fórmula promedio | (B) ya la usa; (A) no. | Unificar Tablero hacia (B); documentar fórmula única (acumulada + promedio) usada en Tablero/Rotación/Reportes (D2). | Aceptación #18 | 🟡 |
| R3 Fecha último día + bajas abiertas | (B) cuenta cerradas `es_rotacion`. | Contar por `fecha_baja` aunque expediente abierto (último día ya ocurrido). | Aceptación #16 | 🟡 |
| R4 Gráfica apilada | Existe SVG. | Barras apiladas por forma + línea índice + meta punteada. | Visual pág. 12 | 🟡 |
| R5 Semáforos editables | Bandas en `config/bajas.php`. | Metas por segmento (Suc 4/6, Corp 1.7/2.5, Global 3/4.5) editables por RH en Configuración. | Semáforos | 🟡 |
| R6 Indicadores complementarios | Parcial. | Voluntaria/involuntaria, temprana <90d, comparativo mes anterior, causal principal. | Visual pág. 12–13 | 🟡 |
| R7 Por sucursal/causales | Parcial. | Promedio mensual con semáforo (no acumulado); top causales filtrable; clic abre detalle. | Aceptación #17, #19 | 🟡 |

---

## Incremento 3 — Incapacidades (PDF pág. 7–9)

**Hallazgos clave:**
- `App\Enums\TipoSolicitudInterna::Incapacidad` existe pero es **legado** (`esLegado()=true` → no creable; sin tarjeta, sin POST). La instrucción "retirar Incapacidad de Solicitudes" ya está de facto cumplida; solo queda limpiar UI residual (iconos/descr.) y **preservar históricos** `solicitudes_internas.tipo='incapacidad'` como lectura.
- Nómina: `app/Services/Nomina/*`, modelo `ReciboNomina` (`recibos_nomina`), quincena Q1 1–15 / Q2 16–fin (`NominaQuincenalService::periodo()`). Columnas `dias_falta`/`dias_incapacidad`/`dias_pagados` **huérfanas** (solo 0 auto o Excel manual; no recalculan sueldo). Estado `EstadoReciboNomina` = `Borrador|Emitido|Cancelado` (no "pagado"). `ReciboNominaService::actualizar()` edita emitido regenerando PDF + auditando.
- **No existe motor de incidencias** que vincule incapacidades/permisos sin goce → recibo. Reporte "Incapacidades" actual es solo listado de solicitudes.

| # inst. | Comportamiento actual | Archivo real | Diferencia | Modificación | Prueba | Estado |
|---|---|---|---|---|---|---|
| 1 Módulo propio en Permanencia | Incapacidad es tipo de solicitud legado. | `app/Enums/TipoSolicitudInterna.php`, `useMainNavItems.ts` | No hay módulo, tablas ni expediente de incapacidad. | Nuevas tablas `incapacidades` + `incapacidad_documentos` + cadena riesgo; modelo, Service, Policy, enum ramo/tipo; item nav Permanencia→Incapacidades. | Aceptación #6 | 🔴 |
| 2 Tipo de riesgo trabajo | — | — | 3 ramos (enf. general / riesgo trabajo / maternidad) + subtipo RT (accidente trabajo / trayecto / enf. trabajo); trayecto separado de siniestralidad. | Enums + columnas. | Pruebas RT | 🔴 |
| 3 Inicial/subsecuente por folio | — | — | Cadena inicial↔subsecuente; serie+folio IMSS obligatorio y único. | Columna `incapacidad_inicial_id`, índice único folio. | Aceptación #7; folio duplicado | 🔴 |
| 4 Fecha término automática | `FechasSolicitudService` ya hace inicio+(días−1) para incapacidad legada. | `app/Services/Solicitudes/FechasSolicitudService.php` | Reusar la fórmula en el nuevo servidor; campo no editable. | Service. | Aceptación #8 (21/10+14=03/11) | 🟡 |
| 5 Documentos por tipo | NAS + storage existe. | servicios NAS/documentos | Certificado por incapacidad; ST-7/ST-2/ST-3 por RT; maternidad sin ST-7/ST-2. | Config de documentos por ramo + NAS. | ST-7/ST-2/ST-3; archivo NAS | 🔴 |
| 6 Historial por riesgo | — | — | Línea de tiempo por riesgo (apertura→ST-7→inicial→subsecuentes→ST-2/ST-3); PDF expediente. | Service + vista + PDF. | Historial | 🔴 |
| 7 Estatus Activa/Concluida | — | — | Enf.gral/maternidad concluyen por fecha término; RT concluye al cargar ST-2. | Enum estatus + reglas. | RT abierto/concluido | 🔴 |
| 8 Impacto en nómina | Columnas huérfanas, sin motor. | `ReciboNomina`, `NominaQuincenalService`, `LoteNominaService` | **Servicio central de incidencias**: consolida incapacidades+permisos, evita traslapes, días naturales vs salariales, respeta altas/bajas a media quincena, sin recibos negativos, ajusta borradores, no sobrescribe emitidos sin auditoría. | Nuevo `IncidenciasNominaService` + integración recibo + reporte Incidencias. | Aceptación #9, #10 (16–31 oct = 11 días) | 🔴 |

---

## Incremento 4 — Tablero y Directorio (diseño final)

> Depende de Incremento 1 (fuente única) + fidelidad visual págs. 3 y 5.

---

## Incremento 5 — Administración de salarios (PDF pág. 18–19)

**Hallazgos:** `CompensacionService` es la autoridad (mensual = fuente, diario = mensual÷30, `historial_salarios` append-only, emite `MovimientoLaboral`). Montos `DECIMAL(10,2)`. **NO existe tabulador** (ni columnas min/medio/max en `puestos`), **ni evaluación de desempeño** (solo inducción + periodo de prueba; flags `evaluacion_desempeno`/`nine_box` apagados y sin backend). Permisos de salario hoy = `expedientes.*` + alcance; no hay permiso `salarios.*`.

| # inst. | Diferencia | Modificación | Prueba | Estado |
|---|---|---|---|---|
| 1 Módulo en Organización | No existe. | Página `Administracion/Salarios/Index.vue` + nav grupo `organizacion`; tarjetas resumen. | — | 🔴 |
| 2 Tabulador por puesto | Greenfield. | Tabla `tabuladores_salariales` (puesto, min/medio/max DECIMAL, fuente, fecha, vigencia, versión) + historial; regla min≤medio≤máx; importación Excel con vista previa (no cambia sueldos). | #20 | 🔴 |
| 3 Posición por persona | — | Barra bajo mín/mín-medio/medio-máx/sobre máx usando sueldo vigente real. | — | 🔴 |
| 4 Compa-ratio | — | `sueldo vigente / medio × 100`. | — | 🔴 |
| 5 Tope por desempeño | Sin evaluación de desempeño. | Mostrar tope; si no hay evaluación vigente → "Sin evaluación de desempeño vigente" (D7). | #21 | 🔴 |
| 6 Matriz de mérito | — | Matriz editable por RH; **nunca** aplica aumentos (solo sugiere; todo cambio pasa por el proceso de historial salarial). | #21 | 🔴 |
| 7 Alertas | — | Bajo mínimo / sobre máximo / sin tabulador. | — | 🔴 |
| 8 Acceso restringido | Solo `expedientes.*`. | Permisos nuevos `salarios.{ver,importar,editar,exportar}`; auditoría de accesos/cambios; bloquear salario en API/reportes/export/URL sin permiso. | #20, #34 | 🔴 |

---

## Incremento 6 — Reportes de RH (PDF pág. 20–22)

**Hallazgos clave:** Motor único `ReportesRhService::catalogo()/generar()` → `{titulo,columnas,filas}`. Export Excel `App\Exports\ReporteRhExport` (PhpSpreadsheet/Maatwebsite), PDF DomPDF `resources/views/pdf/reporte-rh.blade.php`. `colaboradores.fecha_alta_imss` **ya existe** separado de `fecha_ingreso`. **No hay** export NOI ni `config/noi.php`; "NOI" en el repo es solo `numero_empleado`. Sin generador propio ni reportes guardados. Sin API de reportes (es web, está bien).

| # inst. | Comportamiento actual | Archivo real | Diferencia | Modificación | Prueba | Estado |
|---|---|---|---|---|---|---|
| 1 Catálogo detalle + layout lateral | Página única con selector. | `resources/js/pages/Rh/Reportes/Index.vue`, `Rh/ReporteRhController.php` | Falta menú lateral de reportes + área de contenido; retirar "Rotación" (vive en Bajas). | Front + agregar casos. | Visual pág. 20 | 🟡 |
| 2 Altas del mes (nominal) | `altas_por_mes` es agregado 12m. | `ReportesRhService::altasPorMes()` | Falta lista nominal (nombre/ingreso/puesto/sucursal). | Nuevo caso + `movimientos_laborales`. | — | 🟡 |
| 3 Bajas del mes (nominal) | `bajas_por_mes` agregado. | `ReportesRhService::bajasPorMes()` | Lista nominal (nombre/último día/sucursal/forma+causal). | Nuevo caso. | — | 🟡 |
| 4 Altas para NOI | No existe. | — | Reporte con ~25 columnas NOI; capa de mapeo configurable + exportador "Pendiente de validar con NOI" (D6). | Nuevo + `config/noi.php`. | Aceptación #22 | 🔴 |
| 5 Incidencias de nómina | Solo listado `incapacidades`. | `ReportesRhService` | Consolidar permisos+incapacidades por periodo (del motor del Incr. 3). | Nuevo caso ligado a `IncidenciasNominaService`. | — | 🔴 |
| 6 Aniversario alta IMSS | `aniversarios` usa `fecha_ingreso`. | `ReportesRhService` (131) | Nuevo reporte por `fecha_alta_imss` + filtro mes. | Nuevo caso (dato ya existe). | — | 🟡 |
| 7 Generador propio ("Mis reportes") | No existe. | — | Fuentes permitidas (colaboradores/bajas/incapacidades/solicitudes/candidatos), columnas, filtros, orden, vista previa, guardar/duplicar/eliminar/re-ejecutar; sin SQL arbitrario; alcance + permisos por campo; sueldo solo con permiso. | Nueva tabla `reportes_guardados` + servicio con allow-lists + Policy. | Aceptación #23 | 🔴 |
| 8 Altas históricas | Reporte muestra 0 en meses previos. | — | Mecanismo auditable de carga histórica (sin fabricar altas desde bajas). | Importador + diagnóstico. | — | 🔴 |

---

## Incremento 7 — Agenda web (PDF pág. 23) y 8 — App (PDF pág. 24–25)

**Hallazgos clave:** `CandidatoWorkflowService` es la autoridad del pipeline (`EstadoCandidato`). `candidato_entrevistas`/`candidato_socioeconomicos` registran **solo eventos realizados** (`realizada_en`/`fecha_visita` validados `before_or_equal:now/today`): **no hay cita programada, ni estatus, ni quién agendó, ni recordatorios, ni reprogramación**. API candidatos ya existe (`Api/V1/Rh/CandidatoController`: entrevista, socioeconomico, etc.). La app ya consume el workflow.

**Diseño:** la Agenda es una **capa de agendamiento nueva** (entidad `agenda_eventos`) que al marcar "Realizado/Viable" **delega** en `registrarEntrevista`/`registrarSocioeconomico` existentes — nunca duplica el pipeline ni los estados.

| # inst. | Diferencia | Modificación | Prueba | Estado |
|---|---|---|---|---|
| Agenda web (pág. 23) | No hay entidad de cita ni calendario. | Nueva tabla `agenda_eventos` (candidato_id, sucursal_id, tipo entrevista/socio, fecha, hora, estatus Programado/Realizado/NoAsistió/Reprogramado/Cancelado, agendado_por, origen, cv, notas, historial); servicio + controlador web+API; indicadores; calendario semana/mes/lista; anti-duplicado (candidato + idempotencia). | Aceptación #24, #27, #28, #29 | 🔴 |
| Notificaciones (pág. 23) | Notifs hoy por tarea/pendiente, no por cita. | Al agendar → push a gerente+subgerente de la sucursal (Expo), recordatorio 1 día antes y el mismo día; reprogramar/cancelar actualiza recordatorios; fallo push no cancela cita. | Aceptación #25, #26, #31 | 🔴 |
| App Mi agenda / Entrevista / Socioeconómico (pág. 24) | App consume workflow pero no hay agenda. | 3 pantallas Expo; resultados llegan al backend que hace la transición; viable→avanza, no viable→motivo, no asistió→reprogramar; socio guarda formato+evidencia. | Aceptación #25–#30, #32 | 🔴 |
| Récord por sucursal (pág. 23) | — | Tabla de indicadores reales por sucursal. | Aceptación #26 | 🔴 |

---

## Comandos de calidad (ejecutar antes de cada commit)

```
composer run lint:check      # Pint
composer run types:check     # PHPStan/Larastan nivel 7 → 0 errores
php artisan test             # Pest (background)
npm run lint:check           # ESLint
npm run types:check          # vue-tsc (regenerar Wayfinder --with-form si cambian rutas/controladores)
npm run build                # build producción
```
