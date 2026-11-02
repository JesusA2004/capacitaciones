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

- **Total** = bajas individuales de la celda; si el Excel trae un total **mayor**, la diferencia se cuenta como **sin desglose**.
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

## Cargar el histórico individual

Rotación → Histórico → «Cargar histórico» (`rotacion.cargar`): descargar el formato (`Nombre completo`, `Número de empleado`, `Sucursal`, `Puesto`, `Área`, `Fecha de ingreso`, `Último día laborado`, `Motivo original`, `Causal homologada`), subirlo, revisar la vista previa (errores bloquean; avisos se cargan para revisión) y confirmar con motivo. No crea colaboradores, sucursales ni puestos.
