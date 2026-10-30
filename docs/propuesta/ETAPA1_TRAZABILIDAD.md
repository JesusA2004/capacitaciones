# MR. LANA PEOPLE — Etapa 1 · Matriz de trazabilidad e informe de implementación

> Documento vivo. Mapea cada pantalla/instrucción del PDF *"Propuesta de mejoras, Etapa 1"*
> (`docs/propuesta/Mr_Lana_People_Propuesta_de_mejoras_Etapa1.pdf`, 26 pp., 29/10/2026) contra
> el código real, la diferencia detectada, la modificación propuesta y la prueba que la verifica.
>
> **SHA inicial backend:** `c9b8c217f0a6af559694f5d7ba4b1a0b9ec0196d` (rama `main`)
> **SHA inicial app:** `40b9e828baa41e2c28d513230fb81b198d2fc3c7` (rama `main`, árbol limpio) — ⚠️ el repo está **adelante** del SHA `643b368f` del brief; la app ya tiene módulo de candidatos completo (Expo SDK 57, expo-router, react-query, zustand). Docs de la app desactualizados.
> **Fecha de inicio de auditoría:** 2026-10-29
>
> **Corrección de SHA (2026-10-30):** el SHA `40b9e828` de la línea anterior no corresponde al estado real. Al revisar, `main` local y remoto de la app coincidían en `643b368`, sin commits adelantados; el único cambio local era `app.json` con `versionCode` 20, sin publicar.
> **Base del Incremento 1:** backend `9c747ce`; app `2f7ffa8` (incluye `b684d69` y la alineación de Expo).

## Estados del encargo (se usan desde el Incremento 1)

Pendiente · En desarrollo · Implementado sin validar · Probado y aprobado · Bloqueado por información externa. Los símbolos 🔴🟡🟢🔵 de filas anteriores son el diagnóstico inicial y **no** certifican un requisito hasta revisarlo con estos estados.

## Incremento 1 — Plantilla única y Directorio (estado comprobado 2026-10-30)

Validación ejecutada: 220 pruebas Pest de Headcount, Directorio, Dashboard, Permisos, Reportes e `ImportarDatosHubTest` (220 aprobadas), Pint y PHPStan en 0, ESLint, vue-tsc y build correctos. **La suite completa no se corrió en este incremento.** Navegador con Playwright y datos de desarrollo: Directorio en escritorio y a 390 px, «Ver los N», filtro, descargas Excel/PDF, 403 de RH en plazas y vista previa con un error de catálogo que bloquea confirmar.

| Requisito | Implementación | Prueba | Estado |
|---|---|---|---|
| Definiciones de población en una sola fuente | `HeadcountService::poblacion()`/`conciliar()`; definiciones en `docs/HEADCOUNT_Y_VACANTES.md` | `PoblacionFuenteUnicaTest` | Probado y aprobado |
| Misma cifra en Tablero, Directorio y Reportes | `summary.plantilla_activa.poblacion`, `inicio.plantilla.activos`, reporte `plantilla_conciliacion`; el Directorio cuenta el mismo estatus `activo` | `PoblacionFuenteUnicaTest` | Probado y aprobado |
| Importar plazas con vista previa, validaciones, duplicados, confirmación y bitácora; solo super_admin | `PlazasAutorizadasImportService`, `PlazasAutorizadasController`, Configuración → Importar datos → Plazas autorizadas | `PlazasAutorizadasImportTest` | Implementado sin validar en navegador el paso de aplicar (cubierto solo por Pest, para no escribir plazas en la BD local) |
| Cargar las plazas reales de Corporativo (28) y conciliar 151 / 134 / 17 | Mecanismo listo; **no se cargó ninguna plaza** | — | Bloqueado por información externa: falta el catálogo autorizado por puesto y datos de producción |
| Directorio 1–4: activos sin depender de contacto, cuatro tarjetas, Corp + Suc = activas, Sucursales cuenta personas | `DirectorioColaboradoresService`, `Directorio/Index.vue` | `DirectorioTest`, `DirectorioEtapa1Test` | Probado y aprobado |
| Directorio 5–6: «Datos por completar» según permisos; API sin campos sensibles | Bandera y filtro `solo_por_completar` solo para quien ve todos los contactos; lista cerrada en `aArray()` | `DirectorioEtapa1Test`, `DirectorioSeguridadTest` | Probado y aprobado |
| Directorio 7: filtros, búsqueda, paginación y exportaciones | Web: lista completa plegada con «Ver los N»; API paginada; Excel/PDF con `reportes_rh.exportar` | `DirectorioEtapa1Test` y navegador | Probado y aprobado |
| Directorio 8–9: foto oficial y protección de datos | Sin cambios; foto solo si alcanza el expediente | `DirectorioFotoTest`, `DirectorioPrivacidadTest` | Probado y aprobado |
| Directorio 10: diseño y responsive de la pág. 5 | Tarjetas a todo el ancho, botón «Solo datos por completar», exportación arriba a la derecha | Captura 1440 y 390 px, sin scroll horizontal | Implementado sin validar: falta el visto bueno visual contra el PDF con datos reales |
| Diseño final de cuatro bloques y gráficas del Tablero | Fuera de este incremento (Incremento 5) | — | Pendiente |

**Diferencias de criterio a confirmar por RH:**

- «Datos por completar» ahora se activa si falta correo **o** teléfono (como dice la pág. 5), o si falta puesto o sucursal. Antes solo si faltaban ambos contactos. En desarrollo marca a 189 de 189 porque los datos demo no traen esos campos.
- En incorporación ocupa plaza pero no aparece en el Directorio. Si hay personas en ese estado o excedentes, Autorizadas − Activas no da las Vacantes. La pág. 2 usa la resta simple: confirmar con las cifras reales.

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

## Incremento 2 — Bajas, finiquitos y catálogo (estado comprobado 2026-10-30)

> Sustituye a las tablas 🟡/🔴 de Bajas, Finiquitos y Catálogo de más abajo, que se conservan como diagnóstico inicial. Rotación (6.8–6.9) es el Incremento 3: la pestaña solo monta el tablero existente.

| Requisito | Implementación | Comprobación | Estado |
|---|---|---|---|
| Un apartado con 4 pestañas (pág. 10, 15) | `PestanasBajas.vue`, `rh.bajas.finiquitos.index`, `rh.bajas.rotacion`, `rh.bajas.causales`. Menú: una entrada «Bajas». `rh/cierres` y `reportes/rotacion/tablero` siguen vivas para enlaces guardados, con las pestañas | Pest + navegador | Probado y aprobado (falta validación de RH) |
| Flujo sin Jurídico | Sin acciones ni tareas de Jurídico en web y API; Jurídico entra a bajas abiertas (antes caía a un estatus vacío). Aviso RE-03 y plazo se conservan como informativos | Escenario 17 | Probado y aprobado |
| Tablero y listado | 5 tarjetas filtrables, tira de flujo, foto, puesto, sucursal, folio, último día, forma, causal, «Integrar expediente probatorio», avance, finiquito. Los agrupadores son derivados (`cubo`) y no cambian el estatus persistente | Pest + captura | Probado y aprobado |
| Generador de salida | `BajaDocumentoSalidaService`: RV carta (documento maestro), NR convenio, RE aviso, OT constancia (familias de Documentos administrativos); vista previa, PDF, firmado, versiones en NAS con motivo | Escenarios 19–21, 23 + navegador (RE) | NR/RE/OT probados. RV: carta por motor maestro cubierta solo en panel; generación con master real depende de `docs/formatos_fuente` |
| Aviso de rescisión | `baja_avisos_rescision`: fundamento, hechos, fecha/hora, lugar, constataron, relato, testigos, evidencias, fecha, método, entrega | Escenario 21 + navegador | Probado y aprobado |
| Plazo por negativa | `CalendarioLaboralService::sumarDiasHabilesSinFestivos` + `config/calendario_laboral.php`; validación del área con bitácora | Escenario 22; 23/10 → 30/10/2026; 16-sep y tercer lunes de nov. | Probado; días inhábiles del Tribunal **Bloqueado por información externa** (Jurídico) |
| Pestaña Finiquitos | `FiniquitoConsultaService`: mismo `FiniquitoCalculo`, Calculado → Revisado (incluye Aprobado) → Firmado → Pagado, tarjetas, filtros, recalcular, revisar, ajustar, recibo, firmado, pago | Escenarios 24, 25, 27 + navegador | Probado y aprobado |
| Implica indemnización | `tipo_documento`, `tipoPropuesto()`, `cambiarTipo()` con motivo; NR-01/03/05/06 → liquidación con advertencia | Escenario 26 | Probado; criterio legal por caso a cargo de Jurídico |
| Catálogo | Dos columnas, «Genera», etiquetas, leyenda, fundamento, Excel. RE-06 no existe: nada que migrar. OT sin indemnización | Pest + captura | Probado y aprobado |
| Migraciones | `2026_10_30_120000` ya no borra `juridico_por/en`; `130000` idempotente; nueva `2026_10_31_090000` | Copia aislada de la BD local (11 bajas, 6 finiquitos, una forzada a Jurídico): datos preservados, bitácora creada, segunda corrida sin cambios, rollback y reaplicación | Probado con datos de desarrollo; falta copia de producción |

**Cambio de cálculo a validar:** con causal de catálogo, la indemnización estimada solo aplica a las marcadas (antes también RE-02…RE-05, NR-02 y NR-04). Afecta cálculos nuevos o recalculados; los finiquitos firmados y pagados no cambian.

**Pendientes externos:** textos de convenio, aviso y constancia son formatos base con nota visible hasta que Jurídico los valide; fundamento de RE-02 y RE-05 sin fracción capturada (el catálogo no la citaba); beneficiarios en OT-01.

## Bajas 2b — Expediente único, corrección y catálogo administrable (pedido del usuario, 2026-10-30)

| Requisito | Implementación | Prueba | Estado |
|---|---|---|---|
| Baja y finiquito en una sola pantalla | `PanelFiniquito.vue` en el expediente y en la pestaña Finiquitos, mismo registro | `CorreccionYCatalogoBajasTest`; navegador en copia aislada | Probado y aprobado |
| Corregir documento con vista previa regenerada | `EditorDocumentoSalida.vue`, `baja_documento_ediciones`; convenio, aviso y constancia: lugar y fecha, texto principal, fundamento (aviso) y texto adicional; carta: datos variables del maestro, sin los de la empresa | Pest; carta con maestro real en local (en CI se omite); convenio en navegador | Probado y aprobado |
| Corregir importes con vista previa | `FiniquitoService::vistaPreviaCorregida` (en memoria) y `corregir` (un motivo, bitácora, regresa a Calculado); firmado o pagado: 403 | Pest; navegador | Probado y aprobado |
| Catálogo administrable | Subcatálogos `causal_grupos` por forma, documento que genera por causal, historial en pantalla; permisos `causales_rotacion.*` asignables | Pest; navegador | Probado y aprobado |
| Formas de salida nuevas | Las 4 formas siguen fijas: deciden documento, finiquito y rotación. RH agrega subcatálogos y causales dentro de ellas | — | Decisión registrada |

## Incremento 3 — Rotación unificada (estado comprobado 2026-10-30)

Estados permitidos por el encargo. Las filas R1-R7 del diagnóstico inicial quedan sustituidas por esta tabla. Detalle de reglas: `docs/BAJAS_Y_CAUSALES.md`, sección «Rotación: un solo motor».

| Requisito | Implementación | Prueba | Estado |
|---|---|---|---|
| Motor único (D2) | `TableroRotacionService` + `FormulaRotacion`; Tablero de RH e histórico del Excel usan la misma fórmula. Se retiró `serieMensualPorSucursales` | `RotacionUnificadaTest`, `DashboardFuenteCanonicaTest`, `RotacionPersonalCargaTest` | Implementado sin validar con datos reales |
| Escenario 28: baja pendiente cuenta por último día | Cuenta En trámite y Cerrada; excluye No procede, flujo anterior, internos y futuras | Pest | Probado y aprobado |
| Escenario 29: Excel y sistema sin duplicar | Corte `mes_inicio_sistema` | Pest (frontera del corte) | Probado y aprobado |
| Escenario 30: Global = Corporativo + Sucursales | En bajas; el índice no se suma (documentado) | Pest | Probado y aprobado |
| Escenario 31: cinco vistas | Pestaña Rotación con filtros | Pest + Playwright en copia aislada | Probado y aprobado |
| Escenario 32: exportaciones por vista | Excel y PDF con filtros en título y filas | Pest; descargas probadas en navegador para 2 vistas | Probado y aprobado |
| Metas con vigencia e historial | `rotacion_metas`, `bajas.gestionar` | Pest + edición real en copia aislada | Probado y aprobado |
| Gráfica, semáforos, comparativo, por sucursal, por causales | Pág. 12-13, detalle de bajas por sucursal | Capturas 1500 px y 390 px contra PDF, con datos de muestra | Implementado sin validar con datos reales |
| Mes sin datos, mes en curso, cambio de sucursal, alcance y permisos | Sin 0% falso ni división entre cero | Pest | Probado y aprobado |
| Escenario 19: carta RV | Generar, versionar, descargar y firmar con el maestro real. Corrige un fallo silencioso: el firmado con huella no avanzaba el flujo | `BajaDirectaDocumentosTest` (requiere `docs/formatos_fuente`, no versionado: en CI se omite) | Probado y aprobado en local |
| Reporte «Rotación (altas vs. bajas)» | Sigue siendo un neto de movimientos, no un índice; se revisa con Reportes | — | Pendiente (Incremento 7) |

Limitación conocida: la plantilla reconstruida por fechas usa la sucursal actual de cada persona, porque no hay historial confiable de cambios de sucursal. Solo aplica a meses sin foto ni Excel.

## Incremento 4 — Incapacidades e incidencias de nómina (estado comprobado 2026-10-30)

Sustituye a la sección «Incremento 3 — Incapacidades» del diagnóstico inicial. Sin API móvil: la captura es de RH en el portal y la app no la usa.

| Requisito | Implementación | Prueba | Estado |
|---|---|---|---|
| Banda de color, leyenda y filtro en Solicitudes | `Rh/Solicitudes/Index.vue`, tokens `--tipo-solicitud-*` | Captura contra pág. 6 | Probado y aprobado |
| Escenario 6: no se crea por Solicitudes | `esLegado()` ya lo impedía en web y API; el filtro solo la ofrece si hay históricos | `IncapacidadesTest`, `PermisoOficialTest` | Probado y aprobado |
| Escenario 7: histórico consultable | Sin conversión; etiqueta «(histórico)»; no afecta nómina | Pest | Probado y aprobado |
| Módulo y permisos | `incapacidades`, `incapacidad_documentos`; `incapacidades.*` en rh_admin y rh_auxiliar (este sin corregir). Requiere `people:sincronizar-permisos` al desplegar | Pest (acceso sin permisos) | Probado y aprobado |
| Escenarios 8-11: certificado, subsecuente, folio único, término | `IncapacidadService`; índice único `folio_vigente` | Pest + flujo real en navegador (21/10 + 14 = 3 nov) | Probado y aprobado |
| Documentos IMSS en NAS | Disco de expedientes, original, checksum, usuario; un reemplazo conserva el anterior | Pest con disco falso + carga en navegador con disco local | Implementado sin validar en el NAS real |
| Escenario 12 y estatus derivado | ST-7 antes de ST-2; ST-2 concluye; enfermedad general y maternidad al llegar el término | Pest | Probado y aprobado |
| Historial y PDF del expediente | `Rh/Incapacidades/Expediente.vue`, `pdf.incapacidades.expediente` | Pest; descarga 200 en navegador, contenido del PDF no revisado a ojo | Probado y aprobado |
| Escenarios 13-16: quincenas, 11 días, sin duplicar, sin negativos | `IncidenciasNominaService` clasifica cada día una sola vez; neto negativo rechazado en `ReciboNominaService` | Pest | Probado y aprobado |
| Recibo borrador y emitido | Borrador se actualiza; emitido genera `nomina_rectificaciones`, RH aplica o descarta con nota | Pest (aplicar con PDF real en disco falso) | Probado y aprobado |
| Alta y baja dentro del periodo | Misma regla que el ingreso a media quincena; baja vigente o `fecha_baja` | Pest | Probado y aprobado |
| Lote semanal y quincena automática | Preparan con el servicio; `recalcular` lo reaplica | Pest | Probado y aprobado |
| Reporte «Incidencias de nómina» | Lee `resumen()`, sin otra fórmula; la pantalla final de Reportes es del Incremento 7 | Pest | Probado y aprobado |
| Regla de días pagados | `config/nomina_incidencias.php`, `validada_por_rh = false`, aviso visible en pantallas | Pest de las 3 reglas | Bloqueado por información externa (validación de RH) |
| Riesgo sin dictamen ST-2 (no reconocido) | Queda abierto; no hay vía de cierre sin ST-2 | — | Bloqueado por información externa |

**Decisión registrada, 16–31 oct con 11 días de incapacidad:** la regla vigente paga 5 días (16 naturales − 11), la misma que el sistema ya aplicaba al ingreso a media quincena. El PDF dice «se descuentan 11 días», lo que con base 15 daría 4 (`resta_directa`). Cambiar de regla es una línea de configuración, y RH debe elegirla antes de emitir recibos reales. La empresa no paga días de incapacidad en el recibo (`incapacidad_pagada_por_empresa` vacío): el subsidio del IMSS queda fuera.

## Incremento 2 — Bajas y Rotación (diagnóstico inicial)

### Bajas (PDF pág. 10, 15) — flujo sin Jurídico, documentos de salida

| # inst. | Comportamiento actual | Archivo real | Diferencia | Modificación | Prueba | Estado |
|---|---|---|---|---|---|---|
| 1 Un apartado, 4 pestañas | Hay "Bajas" y "Bajas y finiquitos" duplicadas en sidebar. | `resources/js/pages/Rh/Bajas/*`, `Rh/Cierres/*`, `useMainNavItems.ts` | Unificar en Bajas con pestañas Bajas/Finiquitos/Rotación/Catálogo. | Reestructurar nav + página con tabs; redirecciones de rutas viejas. | Aceptación #18 | 🟡 |
| 2 Flujo sin Jurídico | **Hecho.** `registrar()`/`cambiarCausal()` ya no enrutan a `EnRevisionJuridico`; `cerrar()` sin bloqueos de Jurídico; `enviar/aprobar/devolver Jurídico` neutralizados (422, firmas conservadas); `acciones()` sin acciones de Jurídico. Jurídico conserva consulta. Cubos del tablero = 5 del PDF (en_tramite/faltan_documentos/lista_para_cerrar/cerradas/no_procede) vía `BajaDocumentosService::cuboTablero()`. Migración auditable `2026_10_30_120000_bajas_retiro_compuerta_juridico` (en_revision_juridico→en_revision_rh + bitácora). Front: tarjetas "En Jurídico"→"Faltan documentos"/"Listas para cerrar". | `app/Services/Bajas/*`, `EstatusBaja.php`, `Rh/Bajas/Index.vue`, `Rh/Cierres/Index.vue`, `types/bajas.ts`, migración | Pest Bajas verde + test aceptación #11/#12; Pint/PHPStan/ESLint/vue-tsc OK. | Aceptación #11, #12 | 🟢 |
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

### Catálogo de causales (PDF pág. 14, 16) — 🟢 hecho y verificado (catálogo)

| # inst. | Modificación aplicada | Prueba | Estado |
|---|---|---|---|
| C1 Etiqueta "Integrar expediente probatorio" | `CatalogoBajasService::causalArray()` expone `integrar_expediente_probatorio` (alias de `requiere_juridico`, misma marca, nueva lectura — aviso, no compuerta); `CausalesRotacionPanel.vue` muestra la etiqueta nueva. Columna `requiere_juridico` conservada. | CausalesRotacionTest | 🟢 |
| C2 Marca "Implica indemnización" | Nueva columna `causales_rotacion.implica_indemnizacion` (migración `2026_10_30_130000`, backfill `true` en NR-01/03/05/06); modelo + catálogo + UI la exponen como regla configurable + advertencia (D4). **El cableado al cálculo del finiquito se hará en el incremento de Finiquitos** (no se tocó la fórmula). RE-06 no existe → no se crea. | CausalesRotacionTest | 🟢 |
| C3 Grupo OT nuevo | `FormaSalida::OtraTerminacion` + `TipoBaja::OtraTerminacion` (todos los `match` completos; PHPStan 0); OT-01 Fallecimiento / OT-02 Incapacidad permanente total / OT-03 Jubilación o pensión (migración idempotente + config/seeder); documento = constancia adjunta (`documentos_maestros.otra_terminacion => []`, **no** carta/convenio/aviso); `contemplaIndemnizacion()=false` para OT (no se fuerza liquidación). OT es RH-only (no solicitable por gerente). | PantallasBajasTest, CausalesRotacionTest | 🟢 |

> **Pendiente de definición RH/Jurídico (no inventado):** flujo avanzado de OT — captura de **beneficiario** en fallecimiento y manejo de **pensión IMSS** en incapacidad permanente. Hoy OT se registra con constancia + finiquito básico.

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

## Administración de salarios (estado comprobado 2026-10-30)

**Implementado sin validar con datos reales.** Pantalla Organización → Administración de salarios (`salarios.index`), probada con Pest y en navegador sobre una copia SQLite aislada.

- **Tabulador:** captura en línea de mínimo, medio, máximo, fuente y fecha por puesto; `tabuladores_salariales` solo agrega versiones. Importación Excel solo super_admin (`salarios.importar`), con vista previa y bitácora. Exportación Excel/PDF.
- **Posición:** sueldo solo desde `CompensacionService::mensualDe()`; compa-ratio, cuatro bandas, barra por puesto, alertas. Ni el tabulador ni la matriz escriben sueldos (escenarios 33 y 36).
- **Desempeño:** no existe evaluación formal; toda fila muestra «Sin evaluación de desempeño vigente», sin tope ni margen, y la tarjeta muestra «Sin dato». La regla de topes y sugerencia está probada a nivel de servicio. **Bloqueado por información externa:** falta definir la evaluación válida.
- **Permisos:** `salarios.{ver,editar,exportar,importar}`; rh_admin ver/editar/exportar, direccion ver/exportar, super_admin todos. Gerente y rh_auxiliar reciben 403 en vista, exportaciones y escritura (escenario 35). No hay ruta API del módulo. **Fuera de este módulo:** los expedientes y sus API siguen mostrando sueldo con `expedientes.*`; no se revisó.
- **Bitácora:** `salarios.consultado`, `salarios.exportado`, `salarios.tabulador_actualizado`, `salarios.matriz_actualizada`, sin importes.
- **Pendiente visual:** comparación con pág. 18 hecha sobre captura; falta volver a capturar tras el ajuste responsive. Exportaciones PDF usan DomPDF como el resto de reportes.

## Incremento 5 — Administración de salarios, diagnóstico inicial (PDF pág. 18–19)

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
| Agenda web (pág. 23) | — | `agenda_eventos` + `agenda_evento_historial`; `AgendaEventoService`, `AgendaConsultaService`, `AgendaEventoPolicy`; `routes/agenda.php`; `Reclutamiento/Agenda/Index.vue` con semana, mes, lista, filtros, formulario, alta rápida sin duplicar, reprogramar, cancelar, empalmes y exportación Excel/PDF. Fórmulas documentadas en `AgendaConsultaService`. | `tests/Feature/Agenda/AgendaEntrevistasTest.php`; recorrido en navegador sobre copia aislada | Implementado sin validar (falta datos reales y piloto) |
| Resultados | — | Viable, No viable y socioeconómico delegan en `CandidatoWorkflowService`. Nuevo `pasarAEntrevistaPorAgenda()`: agendar a un candidato recibido lo pasa a entrevista. El estudio solo se resuelve de una vez si quien captura tiene el paso de revisión. | Escenarios 46-49 en Pest | Implementado sin validar |
| Notificaciones (pág. 23) | — | Capa única `NotificacionEventosService` a gerencia y subgerente; `agenda:enviar-recordatorios` idempotente (7:30-11:30, un día antes y el mismo día); una cita movida o cancelada deja de recordarse; un fallo de aviso no deshace la cita. | Escenarios 43-45 en Pest | Implementado sin validar (push real pendiente) |
| API móvil (pág. 24) | — | `/api/v1/agenda`, contrato en `docs/AGENDA_API_MOVIL.md`; teléfono, domicilio y CV solo con `agenda.ver_datos_candidato`. | Pest de alcance y permisos | Implementado sin validar (falta la app) |
| App Mi agenda / Entrevista / Socioeconómico (pág. 24) | App consume workflow pero no hay agenda. | 3 pantallas Expo; resultados llegan al backend que hace la transición; viable→avanza, no viable→motivo, no asistió→reprogramar; socio guarda formato+evidencia. | Aceptación #25–#30, #32 | 🔴 |
| Récord por sucursal (pág. 23) | — | Indicadores por sucursal sobre eventos reales, con comparativo del mes anterior y exportación. | Escenario 50 en Pest | Implementado sin validar |

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
