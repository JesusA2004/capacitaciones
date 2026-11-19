# Recepción documental RH

Control **mensual** de los documentos ORIGINALES firmados que RH recibe físicamente por paquetería. No es una lista manual: el sistema calcula lo esperado desde eventos reales (alta, baja, recibo emitido, vacaciones aprobadas, préstamo autorizado…) y RH solo palomea lo que llega.

> Digital ≠ original físico. Un contrato puede estar generado (y firmado) en el sistema y su original seguir sin llegar. Este módulo **referencia** el documento digital (`generated_document_id`) pero controla el papel.
>
> No revive el flujo legacy de envío/recepción a corporativo (`seguimientos_documento_fisico`, `EstadoFlujoDocumento::EnviadoCorporativo/RecibidoCorporativo`, `FlujoDocumentalService::registrarEnvio/registrarRecepcion`): ese flujo sigue retirado y no se usan sus columnas.

## Arquitectura

| Pieza | Archivo |
|---|---|
| Tablas | `database/migrations/2026_11_11_100000_create_recepcion_documental_tables.php` (`recepcion_documental_cortes`, `recepcion_documental_renglones`, `recepcion_documental_pdfs`) |
| Modelos | `RecepcionDocumentalCorte`, `RecepcionDocumentalRenglon` (origen polimórfico `origen_type/origen_id`), `RecepcionDocumentalPdf` |
| Enums | `EstadoCorteRecepcion`, `EstadoRecepcionDocumento`, `ReglaRecepcionDocumental` (todos con `etiqueta()`) |
| Catálogo de lo que se recibe (fuente de verdad) | Tabla `recepcion_documental_catalogo` (migraciones `2026_11_17_100000` y `2026_11_19_100000`), modelo `RecepcionDocumentalCatalogo`, enum `ModoCalculoRecepcion`, `CatalogoReglasRecepcion` (siembra y lee), `ReglaConfigurada` (aplica filtro y copias), `Reglas\ReglaPersonalizada` (entradas agregadas por RH), `ConfiguracionCatalogoRecepcionService` (agrega, edita, quita, audita y recalcula), `SimulacionRecalculoRecepcionService` (antes/después sin guardar), pantalla `pages/Rh/RecepcionDocumental/Catalogo.vue` |
| Configuración | `config/recepcion_documental.php` (clase de cada regla y **valores por defecto** con que se siembra el catálogo, nombres de tipos, columna de fecha de nómina, programación) |
| Reglas | `app/Services/RecepcionDocumental/Reglas/*` — una clase pequeña por evento que implementa `ReglaRecepcion` |
| Constructor del corte | `MaterializadorCorteService` (idempotente) + `EstadoCorteService` (estado calculado) |
| Consultas y acciones | `RecepcionDocumentalService` (lo usan igual la web y la API móvil) |
| PDF | `RecepcionDocumentalPdfService` + `resources/views/pdf/recepcion-documental/control.blade.php` |
| Autorización | `RecepcionDocumentalCortePolicy`, `RecepcionDocumentalRenglonPolicy`, módulo `recepcion_documental` en `config/permisos_modulos.php` |
| Web | `Rh\RecepcionDocumentalController`, `routes/recepcion-documental.php`, `pages/Rh/RecepcionDocumental/{Index,Show}.vue`, acceso «Recepción documental» en Permanencia |
| API móvil | `Api\V1\Rh\RecepcionDocumentalController` (`/api/v1/rh/recepcion-documental…`) |
| Comando | `php artisan recepcion-documental:materializar [--periodo=AAAA-MM] [--anterior]`, programado diario 06:40 (CDMX) en `routes/console.php` |

### Periodo y zona horaria

`PeriodoRecepcion`: inicio = `startOfMonth`, fin = `endOfMonth` (28/29/30/31 días, nunca hardcodeado).

- Columnas **DATE** (`fecha_ingreso`, `fecha_alta_imss`, `fecha_baja`, `fecha_pago`): fecha calendario tal cual (`rangoFechas()`).
- **Timestamps UTC** (`created_at`, `revisado_en`, `autorizado_en`): el mes se decide en hora de México (`rangoUtc()`): un evento a las 23:30 CDMX del último día cuenta en ese mes aunque en UTC ya sea el día 1.

### Materialización (idempotente)

- Índice único `rec_doc_renglon_unico (corte_id, regla, origen_type, origen_id, tipo_documento)`: correrlo N veces no duplica.
- Evento nuevo → renglón «Pendiente» con snapshot (nombre, número, puesto, sucursal, fecha y descripción del evento). El snapshot **nunca** se actualiza después.
- Renglón existente → solo se liga a la versión digital vigente si cambió.
- Renglón **pendiente** cuyo evento ya no devuelve la regla (cancelado o movido de mes) → «No aplica» con justificación automática (`no_aplica_automatico = true`); si el evento vuelve a aplicar, regresa a «Pendiente». Nada recibido se borra ni se toca.
- Entrada del catálogo **apagada** (o sin documentos): sus renglones **pendientes** pasan a «No aplica» automático con la razón «se desactivó en el catálogo»; lo recibido u observado se conserva. Al encenderla, regresan a «Pendiente» (mismo índice único, sin duplicar).
- Corte **cerrado**: nunca se recalcula (422 desde la web; el comando lo omite).

## Catálogo de lo que se recibe

Pantalla **Recepción documental → Configurar lo que recibo** (`/rh/recepcion-documental/catalogo`). Una entrada por regla (evento de origen) con: nombre visible, nota, **documentos que genera cada evento** (subconjunto de los que la regla sabe producir: `ReglaRecepcion::documentosPosibles()`), encendida/apagada, orden (arrastrar o flechas; es el orden de los bloques del corte) y, opcionalmente, solo ciertas **empresas** / **sucursales** (vacío = todas).

- **Fuente de verdad = BD.** `config/recepcion_documental.php` solo da los valores con que `CatalogoReglasRecepcion::sembrar()` crea una entrada que falta (idempotente; nunca pisa lo configurado). Se siembra sola al abrir cualquier pantalla del módulo y con `CatalogoRecepcionDocumentalSeeder`. Cambiar el `.env` después de sembrado ya no hace nada.
- **Cálculo automático mensual**: cada regla devuelve todo lo que sabe producir y `ReglaConfigurada::filtrar()` deja solo los documentos, sucursales y empresas elegidos. Ejemplo: encendidos «Altas» y «Vacaciones», en el mes se dan de alta 3 Gestores → se esperan **9 contratos** (paquete real `documentos_maestros.paquetes.alta.capacitacion_inicial.gestor` = capacitación + confidencialidad + no competencia; un puesto administrativo firma 2) más un formato por cada solicitud de vacaciones **aprobada** en el mes.
- **Copias por documento** (reglas del sistema): cada documento elegido puede pedir N copias por evento (`copias` = `{clave: n}`); la cantidad esperada del renglón se multiplica.
- **Revisar antes de guardar** (`POST catalogo/simular`, `accion` = `crear` | `actualizar` | `eliminar`): sin guardar nada, calcula con el MISMO plan que la materialización (`MaterializadorCorteService::planificar`) el «No. de papeles» de **cada corte no cerrado** (y del mes en curso aunque no exista su corte), por sucursal: *hoy* (lo que suma el corte), *sin el cambio* (recalculado con lo guardado: la diferencia con *hoy* son eventos nuevos desde el último cálculo) y *después*. Lista también los **conflictos** que provoca el cambio: originales ya marcados (recibido, con observación, no recibido) que dejarían de esperarse o que se esperarían en otra cantidad, y «No aplica» puestos a mano que se volverían a esperar. Por sucursal respeta el alcance del usuario en el módulo.
- **Al aplicar** (crear / editar / encender / apagar): el cambio del catálogo, su bitácora (`recepcion_documental_catalogo_agregado` / `_actualizado`, antes/después) y el **recálculo de todos los cortes no cerrados** van en **una sola transacción** (todo o nada; antes solo se recalculaba el mes en curso y un fallo no deshacía el cambio). El resultado (antes/después por corte y conflictos) queda en `recepcion_documental_catalogo_recalculado`. Un corte cerrado nunca cambia. Reordenar queda en `recepcion_documental_catalogo_reordenado` (no cambia cantidades).
- **Lo recibido nunca se pierde**: la materialización solo toca renglones *pendientes* (cantidad, nombre) o en «No aplica» automático (los reactiva). Lo ya marcado se conserva tal cual con su evidencia y, si ya no cuadra, aparece como conflicto (en la revisión y en el aviso tras aplicar) para corregirlo a mano.
- **Vista previa en vivo** del editor: `POST catalogo/{entrada}/vista-previa` (o `POST catalogo/vista-previa` para una entrada nueva) calcula cuántos papeles y eventos se esperarían este mes con el borrador.

### Agregar lo que debe llegar (entradas de RH)

Botón **«Agregar lo que debe llegar»**. Además de las reglas del sistema, RH agrega documentos sin evento propio. Cada entrada agregada (`modo_calculo` ≠ `regla`, `regla = personalizada`, `clave = personalizado.<slug>-<aleatorio>`, que es el `tipo_documento` de sus renglones) tiene nombre, nota, cantidad, bloque, alcance y encendido:

| Cómo se cuenta (`ModoCalculoRecepcion`) | Renglones por corte | Cantidad |
|---|---|---|
| Cantidad fija al mes (`fijo_mensual`) | 1 general, sin sucursal (origen = la propia entrada) | la cantidad |
| Por sucursal (`por_sucursal`) | 1 por sucursal **activa** (o solo las elegidas / de las empresas elegidas) | la cantidad |
| Por colaborador activo (`por_colaborador_activo`) | 1 por sucursal con activos en el mes (misma definición que listas de asistencia; opcional solo ciertas **áreas**) | activos × cantidad; el renglón muestra los nombres |
| Por cada evento (`por_evento`) | 1 por evento de la regla base del sistema (cada alta, baja, recibo…; en listas, cada semana), junto a los documentos de esa persona | la cantidad |

- **Bloque**: por defecto «Otros documentos» (`personalizada`); puede ir dentro de un bloque del sistema (p. ej. «Carta de bienvenida» por alta, en el bloque Altas). Si se cambia de bloque, sus renglones de cortes abiertos se mueven con él (con lo recibido).
- **Quitar**: apagarla deja de esperarla desde los cortes abiertos (pendientes → «No aplica» automático con la razón; lo recibido se queda). **Borrar** solo si nunca generó un renglón en ningún corte (`DELETE catalogo/{entrada}`, 422 si ya se usó); las del sistema no se borran.
- **PDF y pantalla del corte**: el bloque toma el nombre y orden del catálogo; las entradas sin persona se agrupan como «Documentos de la sucursal» / «Generales del mes» con su cantidad y explicación.
- La API móvil no cambia de contrato: los renglones agregados llegan con `regla = personalizada` (o la del bloque elegido) y, como campos nuevos opcionales, `es_personalizado` y `evento_titulo`; `agrupaciones.reglas` sigue teniendo una fila por regla.
- No existe nada «pendiente de definir»: INFONAVIT y pensión alimenticia se retiraron (nunca generaron renglones). Si RH las define, se agregan como regla nueva con su clase.

### Estados

Corte (calculado): **En preparación** (sin calcular) → **Abierto** (mes en curso) → **Con pendientes** (mes terminado y falta algo) → **Completo** (nada pendiente ni «No recibido»). **Cerrado** solo con permiso; antes de cerrar se recalcula por última vez y, si faltan originales, se exige observación. **Reabrir**: permiso `reabrir` + motivo obligatorio; incrementa `reabierto_conteo`, guarda motivo y la decisión anterior en la bitácora (mismo criterio que las reaperturas del sistema; no usa la tabla `reaperturas`, que es de decisiones comerciales con autoridad jerárquica).

Renglón: Pendiente · Recibido · Recibido con observación (observación obligatoria, incidencia) · No recibido (observación obligatoria, incidencia) · No aplica (justificación obligatoria). Cada cambio queda en `AuditoriaService` (`recepcion_documental_renglon_actualizado` con antes/después); el historial por renglón se consulta con `AuditoriaService::historial()`. Evidencia opcional (foto/escaneo) en el disco de expedientes, carpeta `RecepcionDocumental/AAAA-MM/evidencias` (nunca se borra la anterior).

Métricas (por alcance del usuario): Esperados (excluye «No aplica»), Recibidos, Pendientes, No recibidos, Con incidencia, Cumplimiento % = recibidos / esperados.

## Reglas

| Tipo esperado | Evento origen | Mes en que cae | Cantidad / regla |
|---|---|---|---|
| Contrato de capacitación inicial, Confidencialidad, No competencia (paquete de alta) | Alta: `colaboradores.fecha_ingreso` | Mes de la fecha de ingreso (DATE) | Paquete `documentos_maestros.paquetes.alta.<modalidad>.<grupo_documental>` ∪ `GeneratedDocument` reales vigentes del contrato inicial. Gestor y Gerente: 3; demás puestos: 2. Puesto «no requiere documentos laborales»: solo lo realmente generado |
| Alta IMSS firmada | `colaboradores.fecha_alta_imss` | Mes de la fecha de alta IMSS (DATE) | 1 por colaborador |
| Contrato por tiempo indeterminado | Renovación: primer `GeneratedDocument` `contrato_indeterminado` de un `ContratoLaboral` con `contrato_anterior_id` | Mes de `MIN(created_at)` en CDMX (no el mes del alta) | 1 por contrato; se liga a la versión vigente. La contratación directa indeterminada va en Altas |
| Documentos de baja: **solo** carta de renuncia firmada (renuncia voluntaria) y finiquito firmado | Baja (`bajas`) en revisión o cerrada, origen sistema | Mes de `fecha_baja` (DATE) | Del catálogo «Documentos requeridos de la baja» de su forma de salida, solo las filas cuyo `documento_sistema` está elegido en la entrada «Bajas» del catálogo (por defecto `recepcion_documental.bajas.documentos_sistema`: `carta_renuncia`, `finiquito`; se puede sumar `aviso_terminacion`). Entrevista de salida, acta con testigos, aviso de rescisión y evidencias de la causal siguen en el expediente de la baja, pero **no** se esperan en recepción (desde 2026-11-06; sus pendientes en cortes abiertos pasan solos a «No aplica», los recibidos se conservan). Liga la carta de renuncia (`GeneratedDocument`) y el finiquito (`finiquito_calculos.generated_document_id`). Baja cancelada/rechazada → «No aplica» automático |
| Formato de vacaciones firmado | Solicitud interna de vacaciones **aprobada** | Mes de `revisado_en` en CDMX | 1 por solicitud, solo si `solicitudes.formatos.vacaciones.requiere_firma` |
| Contrato de crédito, Pagaré, Carta de retención | Préstamo autorizado (no cancelado) | Mes de `autorizado_en` en CDMX | `documentos_maestros.documentos_prestamo` (3 por préstamo) |
| Recibo de nómina firmado | Recibo **emitido** real (`recibos_nomina.estado = emitido`) | Mes de `fecha_pago` (configurable a `periodo_fin`) | 1 por recibo emitido; borradores no cuentan, cancelados pasan a «No aplica». Nunca se suponen 4 semanas ni 2 quincenas |
| Lote de lista de asistencias firmadas | Semana lunes–domingo (la de la nómina semanal) × sucursal (`sucursal_principal_id`, sin corporativo salvo `RECEPCION_DOC_LISTAS_CORPORATIVO=true`) | Mes del **domingo** de la semana; solo semanas ya empezadas (CDMX) | 1 lote por sucursal y semana; cantidad = colaboradores **activos** esa semana (estatus `activo` como la nómina; ingreso ≤ domingo; con baja, solo si `fecha_baja` ≥ lunes; en incorporación no cuenta). Mientras está pendiente, cada cálculo diario actualiza la cantidad; el renglón muestra los nombres |

Decisión de bajas: se usa `fecha_baja` (fecha legal) y no la fecha de cada documento porque el paquete de salida viaja junto; usar la firma de cada pieza lo repartiría entre meses.

## Permisos

Módulo `recepcion_documental` (Permanencia): acceso `recepcion_documental.ver`; alcances `ver_todos / ver_region / ver_sucursal` (por sucursal del renglón, vía `AlcanceOrganizacionalService::limitarPorSucursal`); acciones `generar`, `recibir` (palomear / regresar a pendiente), `observar` (observación, no recibido, no aplica), `cerrar`, `reabrir`, `descargar_pdf`, `configurar` (catálogo de lo que se recibe; `RecepcionDocumentalCatalogoPolicy`).

- `rh_admin`: todas. `rh_auxiliar`: todas menos `reabrir` y `configurar` (ve el catálogo en solo lectura). `super_admin`: todo (catálogo operativo). Colaborador y demás roles: ninguna (403 web y API).
- En producción: `php artisan migrate --force` y `php artisan people:sincronizar-permisos`.

## PDF operativo

`GET /rh/recepcion-documental/{corte}/pdf?variante=control|estado` (+ los mismos filtros de pantalla). Encabezado «MR LANA — CONTROL DE RECEPCIÓN DOCUMENTAL RH», periodo `01/MM/AAAA al DD/MM/AAAA`, fecha de generación (CDMX); SUCURSAL → evento/persona → documentos con casillas dibujadas con bordes CSS (DejaVu Sans; no depende de que la fuente tenga ☐/☑), subtotales por sucursal, total general y espacio Recibido por / Fecha / Firma / Observaciones. Variante «estado»: ✓ recibido, ! observación/no recibido, N/A no aplica. Cada generación se guarda **versionada** en `RecepcionDocumental/AAAA-MM/pdf/<variante>-v<n>-<fecha>.pdf` (tabla `recepcion_documental_pdfs`) y queda en la bitácora; nunca se sobreescribe la anterior.

## API móvil (`auth:sanctum`, prefijo `/api/v1/rh/recepcion-documental`)

| Método | Ruta | Qué |
|---|---|---|
| GET | `/` | Cortes con métricas (paginado) |
| GET | `/{corte}` | Resumen: métricas, sucursales con pendientes, agrupaciones, opciones de filtro, permisos |
| GET | `/{corte}/renglones` | Renglones paginados (`sucursal_id`, `regla`, `tipo_documento`, `colaborador`, `estado`, `recibido=si|no`) |
| GET | `/renglones/{renglon}` | Detalle con historial |
| PATCH | `/renglones/{renglon}` | Marcar (`estado`, `observaciones`, `justificacion`, `cantidad_recibida`) — mismo `MarcarRecepcionRequest` que la web |

La app no calcula reglas, métricas ni estados.

## Notas para RH

1. ~~INFONAVIT y pensión alimenticia~~: retiradas del catálogo el 2026-11-17 (no había documento ni evento en el sistema; nunca generaron renglones). Se agregan como regla nueva el día que RH defina qué se firma y cuándo.
2. ~~Listas de asistencia~~: definido 2026-11-06 — lote semanal por sucursal con los activos (ver tabla).
3. Vacaciones capturadas por el endpoint legacy `solicitudes_vacaciones` no se esperan en recepción (hoy sin registros); solo las solicitudes internas de vacaciones aprobadas.
4. **No competencia por puesto**: el negocio pidió 3 originales por alta para todos, pero el paquete configurado (`documentos_maestros.paquetes.alta`) solo incluye «No competencia» para Gestor y Gerente (y no hay plantilla para otros grupos). El módulo sigue la configuración; si RH decide que todos la firman, se agrega la clave al paquete de cada grupo (y Jurídico entrega el formato) y la regla lo tomará sola.
5. **Registros viejos** con `generated_documents.requiere_envio_corporativo = 0`: no se usan como criterio; las reglas se basan en el evento y el paquete, no en esa bandera.
6. Documentos generados con envío a corporativo que no son de alta/baja/préstamo (actas administrativas, permiso extraordinario con goce): no tienen regla todavía; agregar una regla si RH los concilia en este control.
