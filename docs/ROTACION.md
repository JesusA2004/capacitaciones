# Rotación de personal

Reportes → **Rotación de personal** (`reportes.rotacion.tablero.index`; también como pestaña «Rotación» de Bajas, `rh.bajas.rotacion`). Una sola pantalla con cinco áreas conectadas: **Resumen**, **Tendencias**, **Causales**, **Sucursales** e **Histórico de bajas**. Cada cifra o gráfica abre el histórico ya filtrado, y al volver se conservan los filtros (todo vive en la query string).

La carga del Excel de totales mensuales (`INDICE DE ROTACIÓN DE PERSONAL`) sigue en `reportes.rotacion.index` (`RotacionPersonalController`).

## Fuente única

`App\Services\Rotacion\RotacionMotorService` es el **único** lugar que decide qué es una baja de rotación. Lo usan la pantalla, las exportaciones, el Tablero de RH (`TableroRhService` → `TableroRotacionService::serieParaTablero`) y el comando de diagnóstico.

| Fuente | Tabla | Qué trae |
|---|---|---|
| Sistema | `bajas` | Bajas `es_rotacion`, en trámite o cerradas, por su último día laborado ya transcurrido. «No procede» y movimientos internos no cuentan. Incluye los cierres anteriores al módulo (`origen = cierre_previo`). |
| Histórico individual | `bajas_historicas` | Bajas de periodos anteriores, una fila por evento, cargadas con `BajasHistoricasImportService`. Solo cuentan las de estado `valida`. |
| Histórico agregado | `rotacion_periodos` | Total mensual por sucursal del Excel (a lo más voluntarias/involuntarias). Sin persona, causal ni antigüedad. |

### Regla de la celda (mes × sucursal)

- **Total** = bajas individuales de la celda; si el Excel trae un total **mayor**, la diferencia se cuenta como **sin desglose** — solo en un mes **sin histórico individual** y anterior al inicio del módulo.
- **Mes con histórico individual**: si existe al menos una baja histórica no descartada (válida o ligada a una del sistema) en ese mes, en cualquier sucursal o unidad (`RotacionMotorService::mesesConDetalleHistorico`), el detalle individual es la fuente de **todas** las celdas del mes: el total del Excel nunca se suma, solo se compara. Así el histórico 2026 de RH (106 bajas, enero a octubre) reemplaza a los totales del Excel (48 de enero a mayo, con Tenango) y la diferencia queda en «Calidad del dato».
- Si individuales y Excel **no coinciden**, la celda es una **discrepancia**: se muestra en «Calidad del dato» y en el diagnóstico, nunca se oculta.
- Un total mensual del Excel solo entra si el mes cae **completo** en el rango (o es el mes en curso con corte a hoy): no se recorta a días. Los meses recortados se informan.
- Un mes sin ninguna fuente anterior al inicio del módulo (`bajas.tablero.mes_inicio_sistema`) es **«sin datos»**, no cero. Los meses futuros no se dibujan.

**Total de bajas = voluntarias + involuntarias + sin clasificar** en todo periodo (`resumen.identidad`). Lo sin desglose del Excel cae en «sin clasificar» (la tarjeta dice cuántas son de ese tipo y cuántas se pueden revisar una por una).

### Filtros de detalle

Puesto, área, tipo de salida, causal homologada y antigüedad solo se pueden responder con bajas individuales. Con alguno de ellos activo, el total es el de las bajas individuales que cumplen el filtro y el tablero informa cuántas bajas del Excel **no se pudieron desglosar** (`no_desglosables`): nunca un cero engañoso. El índice de rotación no aplica con filtros de detalle (no hay plantilla por causal o antigüedad).

### Sin doble conteo

- Una histórica ligada a una baja del sistema (`baja_id`, estado `duplicada`) no cuenta.
- El importador liga solo los casos claros (misma persona, ±7 días) y los marca; los demás pares parecidos aparecen como **posibles duplicados** para que RH los ligue a mano (`conciliacion`).
- Un reingreso que vuelve a salir es **otro evento** (otra fecha): la llave del evento es persona + último día + sucursal.

## Clasificación y causales

Tres cosas distintas, que se conservan todas:

1. **Motivo original** (`motivo_original`): lo que capturó RH. Nunca se sobrescribe.
2. **Causal homologada** (`causales_analiticas`): categoría para la estadística, con su clasificación voluntaria / involuntaria / sin clasificar y la marca «estructural».
3. **Forma de salida administrativa** (`bajas.forma_salida`, causal del catálogo `causales_rotacion`): la del flujo jurídico de Bajas.

Resolución de la causal homologada de una baja: corrección propia (`bajas.causal_analitica_id` o la de la histórica) → mapeo de su causal del catálogo (`causales_rotacion.causal_analitica_id`) → «Sin homologar» (clasificada por su forma de salida) → «Sin causal registrada» (sin clasificar).

«Abandono de empleo o faltas» es **voluntaria en la estadística histórica** y **rescisión en el flujo de Bajas**: la clasificación analítica no es una conclusión jurídica.

El mapeo inicial está en la migración `2026_11_09_100000_rotacion_historico_y_causales_analiticas` y en `config/rotacion.php → homologacion_inicial` (lo aplica el sembrado del catálogo a causales nuevas). Se cambia desde la pantalla con comentario obligatorio; el diálogo dice cuántas bajas se reclasifican.

## Definiciones

| Indicador | Fórmula |
|---|---|
| Participación | bajas del grupo ÷ bajas del alcance × 100 |
| Índice mensual | bajas del mes ÷ plantilla promedio del mes × 100 (`FormulaRotacion`) |
| Plantilla promedio | (plantilla al inicio + plantilla al cierre) ÷ 2 |
| Rotación del periodo | bajas del periodo ÷ promedio de las plantillas promedio mensuales × 100 |
| Promedio de índices | promedio de los índices mensuales con dato |
| Promedio mensual de bajas | bajas ÷ meses considerados (eventos por mes, no %) |
| Rotación temprana | bajas antes de 6 meses ÷ bajas con fecha de ingreso válida |

Sin plantilla, el índice es `null` («Sin datos de plantilla»), nunca 0. El índice global nunca es la suma de los de cada sucursal: se calcula con las bajas y plantillas del conjunto. La plantilla sale de `TableroRotacionService::plantillaMes()` (foto mensual → Excel → reconstruida por fechas).

### Antigüedad

Meses calendario desde la fecha de ingreso **de esa relación laboral** hasta el último día laborado: `[0, 3)`, `[3, 6)`, `[6, 12)`, `[12, 36)`, `[36, ∞)`. Sin fecha de ingreso, o con baja anterior al ingreso, el registro va a «Sin fecha de ingreso válida», se excluye de los denominadores y se informa (`excluidas`, `inconsistentes`).

**Antigüedad aproximada** (solo bajas históricas): si la fuente trae la antigüedad en años redondeados («2.5 a») y no la fecha de ingreso, se guarda `bajas_historicas.antiguedad_dias` (años × 365.25) y `rango_antiguedad`; la fecha de ingreso sigue **vacía** (no se inventa). El rango se deduce solo si el intervalo del redondeo (±0.05 años) no cruza un límite (`RangoAntiguedad::deAniosRedondeados`); «1.0 a» y «3.0 a» no se pueden deducir y el archivo debe indicar el «Rango de antigüedad» (queda nota). Cuentan en su rango y en promedio/mediana; «Calidad del dato» las informa (`antiguedad_aproximada`) y siguen como pendientes de «Completar fecha de ingreso»: en cuanto se captura la fecha real, ésta manda. La antigüedad en días («159 d») sí da la fecha exacta: ingreso = último día − 159.

## Correcciones (auditadas)

`CorreccionRotacionService` (permiso `bajas.gestionar`): comentario obligatorio, transacción y fila en `rotacion_correcciones` con antes y después.

- **Corregir causal**: cambia solo la causal homologada. En una baja del sistema no toca la causal administrativa, la forma de salida, los documentos firmados ni el cierre: eso se rectifica en Bajas.
- **Completar fecha de ingreso**: solo bajas históricas (la del sistema sale del expediente y se corrige ahí).
- **Ligar duplicado / excluir / restablecer** una histórica: nunca se borra.
- **Mapear causal del catálogo** y **editar categorías**: con aviso de cuántas bajas se reclasifican.

## Permisos

| Acción | Permiso |
|---|---|
| Ver el tablero | `bajas.tablero.ver`, acotado a las sucursales del alcance (`RotacionMotorService::sucursalesVisibles`) |
| Ver personas (histórico, relación individual en exportaciones, bitácora) | `bajas.ver` o `bajas.gestionar` |
| Corregir, conciliar, homologar, metas | `bajas.gestionar` |
| Cargar histórico individual | `rotacion.cargar` (super administración) |

Una sucursal o empresa fuera del alcance en la URL se ignora; las correcciones validan que el registro esté dentro del alcance.

## Exportaciones

Excel (una hoja por sección + relación individual si se pueden ver personas), PDF **resumen ejecutivo** (horizontal) y PDF **reporte detallado** (vertical). Todas llaman a `RotacionExportService::datos()` con los mismos filtros de la pantalla.

## Diagnóstico del histórico (solo lectura)

```bash
php artisan rotacion:diagnostico --anio=2026 --hasta=2026-10-23
```

Por mes: total, bajas del sistema, históricas individuales, total del Excel, sin desglose, descuadres y personas dadas de baja en su expediente sin ningún registro de baja (posibles eventos faltantes). Además: identidad del total, rotación temprana, pendientes de calidad, ranking de causales y sucursales. **No modifica nada.**

## Unidades externas

Bajas de unidades de negocio que **no son sucursales de People** (Itzamarketing; Zen Japonés de Lerom restaurantes). Se confirman una por una en `config/rotacion.php → unidades_externas` (nombre y grupo). La baja histórica se guarda con `sucursal_id` nulo y `unidad_externa`; **no se crea ninguna sucursal ni empresa**, así que headcount, vacantes, organigrama, matriz comercial, alcances y el menú no cambian (muchas pantallas listan sucursales sin filtrar `activo`: una sucursal «inactiva» sí se habría colado).

- Solo se ven con alcance global en Rotación (`bajas.gestionar` o alcance global) y sin empresa ni sucursal elegida (`FiltrosRotacion::externas`).
- En el motor se agrupan con un id **negativo** estable (`BajaHistorica::idUnidadExterna`) para que ranking, matriz, histórico y exportaciones las traten como una ubicación más; su «empresa» es el grupo de la configuración.
- Cuentan en el total, causales, antigüedad y ranking; **no** en el índice (su plantilla no está en People) ni en el Tablero de RH (que siempre filtra por sucursales).
- Una sucursal que no está en el catálogo ni en `unidades_externas` sigue siendo un error de carga.

## Cargar el histórico individual

Rotación → Histórico → «Cargar histórico» (`rotacion.cargar`): descargar el formato (`Nombre completo`, `Número de empleado`, `Sucursal`, `Puesto`, `Área`, `Fecha de ingreso`, `Último día laborado`, `Motivo original`, `Causal homologada`), subirlo, revisar la vista previa (errores bloquean; avisos se cargan para revisión) y confirmar con motivo. No crea colaboradores, sucursales ni puestos.

### Histórico 2026 de RH (comando)

Fuente: `docs/rotacionNuevo/Reporte_Rotacion_Personal_2026 (1).pdf` (anexo «Detalle de bajas 2026», págs. 8 a 11: 106 filas) y `Dashboard_Rotacion_2026 (2).pdf` (totales). El anexo se extrajo con coordenadas de palabra (`pdftotext -tsv` de poppler) y se revisó fila por fila contra la imagen de cada página. Quedó versionado en:

- `claude/rotacion/bajas_historicas_2026.csv` — una fila por baja: columnas del formato + «Antigüedad (texto)», «Rango de antigüedad», «Nota» y, para auditoría, «# anexo», «Causal (reporte)» y «Tipo (reporte)» (el importador ignora columnas que no conoce). La causal va por su **clave** de `causales_analiticas` (estable aunque se renombre la categoría). «— Sin motivo anotado —» se carga como motivo vacío.
- `claude/rotacion/bajas_historicas_2026.esperado.json` — totales publicados (por mes y tipo, causal, tipo, antigüedad y ubicación) para conciliar.

```bash
php artisan rotacion:cargar-historico                    # vista previa: no escribe nada
php artisan rotacion:cargar-historico --aplicar          # carga (todo o nada)
php artisan rotacion:cargar-historico --aplicar --force  # en producción (APP_ENV=production)
# opciones: {archivo} --esperado= --usuario=correo --motivo="..."; alias rotacion:cargar-historico-2026
```

Usa `BajasHistoricasImportService` (`leer` → `registrar` → `aplicar`, la misma regla que la pantalla) desde `CargaHistoricoRotacionService`. Imprime nuevas / sin cambio / duplicadas con el sistema / errores, avisos agrupados (`-v` los lista), y una tabla **Esperado vs. Archivo vs. Rotación** (solo histórico y todas las fuentes, con `RotacionAnalisisService`, el mismo cálculo de la pantalla) más los descuadres con el Excel de totales. Audita en `importaciones_excel` (tipo `bajas_historicas`: usuario —`--usuario` o el primer super_admin—, fecha, motivo, archivo, servidor y entorno). Es **idempotente** (llave persona + fecha + sucursal/unidad): la segunda corrida dice «Sin cambios» y no escribe. Nunca borra ni modifica bajas.

Una baja de este archivo que ya existe como baja del sistema (misma persona, ±7 días) se guarda **ligada** (duplicada) y cuenta la del sistema, con la causal del sistema: el comando la lista para revisarla.

Decisiones del histórico 2026 (ver nota de cada fila): «1.0 a» (#101) va a 1 a 3 años porque el resumen del reporte ya tiene los 26 de 6 a 12 meses con antigüedad en días; de los dos «3.0 a» (#69 y #75) el resumen obliga a que uno quede en «3 años o más» y otro en «1 a 3 años» y el anexo no dice cuál: se asignaron provisionalmente (#69 → 3 años o más, #75 → 1 a 3 años) y conviene completar su fecha de ingreso real.
