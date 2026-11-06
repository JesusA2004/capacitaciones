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
| Configuración | `config/recepcion_documental.php` (reglas y su activación, nombres de tipos, columna de fecha de nómina, programación) |
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
- Reglas apagadas: sus renglones no se tocan.
- Corte **cerrado**: nunca se recalcula (422 desde la web; el comando lo omite).

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
| Documentos de baja: **solo** carta de renuncia firmada (renuncia voluntaria) y finiquito firmado | Baja (`bajas`) en revisión o cerrada, origen sistema | Mes de `fecha_baja` (DATE) | Del catálogo «Documentos requeridos de la baja» de su forma de salida, solo las filas cuyo `documento_sistema` está en `recepcion_documental.bajas.documentos_sistema` (`carta_renuncia`, `finiquito`). Entrevista de salida, acta con testigos, aviso de rescisión y evidencias de la causal siguen en el expediente de la baja, pero **no** se esperan en recepción (desde 2026-11-06; sus pendientes en cortes abiertos pasan solos a «No aplica», los recibidos se conservan). Liga la carta de renuncia (`GeneratedDocument`) y el finiquito (`finiquito_calculos.generated_document_id`). Baja cancelada/rechazada → «No aplica» automático |
| Formato de vacaciones firmado | Solicitud interna de vacaciones **aprobada** | Mes de `revisado_en` en CDMX | 1 por solicitud, solo si `solicitudes.formatos.vacaciones.requiere_firma` |
| Contrato de crédito, Pagaré, Carta de retención | Préstamo autorizado (no cancelado) | Mes de `autorizado_en` en CDMX | `documentos_maestros.documentos_prestamo` (3 por préstamo) |
| Recibo de nómina firmado | Recibo **emitido** real (`recibos_nomina.estado = emitido`) | Mes de `fecha_pago` (configurable a `periodo_fin`) | 1 por recibo emitido; borradores no cuentan, cancelados pasan a «No aplica». Nunca se suponen 4 semanas ni 2 quincenas |
| INFONAVIT | — | — | **Registrada y apagada** (pendiente de definir por RH) |
| Pensión alimenticia | — | — | **Registrada y apagada** (pendiente de definir por RH) |
| Listas de asistencia | — | — | **Registrada y apagada** (pendiente de definir por RH) |

Decisión de bajas: se usa `fecha_baja` (fecha legal) y no la fecha de cada documento porque el paquete de salida viaja junto; usar la firma de cada pieza lo repartiría entre meses.

## Permisos

Módulo `recepcion_documental` (Permanencia): acceso `recepcion_documental.ver`; alcances `ver_todos / ver_region / ver_sucursal` (por sucursal del renglón, vía `AlcanceOrganizacionalService::limitarPorSucursal`); acciones `generar`, `recibir` (palomear / regresar a pendiente), `observar` (observación, no recibido, no aplica), `cerrar`, `reabrir`, `descargar_pdf`.

- `rh_admin`: todas. `rh_auxiliar`: todas menos `reabrir`. `super_admin`: todo (catálogo operativo). Colaborador y demás roles: ninguna (403 web y API).
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

## Pendientes de definir por RH

1. **INFONAVIT**: qué documento se firma (aviso de retención, alta/modificación de crédito), con qué evento del sistema se detecta y si es uno por crédito o por movimiento. No hay modelo ni evento en el sistema.
2. **Pensión alimenticia**: qué documento (oficio, constancia de descuento), en qué evento y si se espera por resolución o por cada recibo.
3. **Listas de asistencia**: cuál se recibe en original (diaria/semanal/quincenal), por sucursal o por persona y desde qué registro se calcula.
4. **No competencia por puesto**: el negocio pidió 3 originales por alta para todos, pero el paquete configurado (`documentos_maestros.paquetes.alta`) solo incluye «No competencia» para Gestor y Gerente (y no hay plantilla para otros grupos). El módulo sigue la configuración; si RH decide que todos la firman, se agrega la clave al paquete de cada grupo (y Jurídico entrega el formato) y la regla lo tomará sola.
5. **Registros viejos** con `generated_documents.requiere_envio_corporativo = 0`: no se usan como criterio; las reglas se basan en el evento y el paquete, no en esa bandera.
6. Documentos generados con envío a corporativo que no son de alta/baja/préstamo (actas administrativas, permiso extraordinario con goce): no tienen regla todavía; agregar una regla si RH los concilia en este control.
