# Módulo de Bajas y Causales

Especificación funcional de la Gerencia de RH v1.0 (20-oct-2026). Cada baja de personal se registra con dos datos de **lista cerrada** —la **forma de salida** (cómo se va) y la **causal** (por qué se va)— y con eso se mide la rotación global, por sucursal, por puesto y por causal.

## Catálogo (dos niveles)

| Forma | Clave | Descripción | Tipo de baja del cierre laboral |
|---|---|---|---|
| Renuncia voluntaria | `RV` | La persona decide irse. | `renuncia` |
| No renovación | `NR` | La empresa decide no continuar, sin que exista una falta grave. | `fin_contrato` (valor histórico, compatible con la API móvil) |
| Rescisión | `RE` | La empresa termina la relación por una falta grave de la persona (art. 47 LFT). | `rescision` |

- `App\Enums\FormaSalida` (RV/NR/RE) ↔ `App\Enums\TipoBaja` 1:1; la tabla `formas_salida` guarda nombre/descripción/orden.
- Las **17 causales** (RV-01…RV-06, NR-01…NR-06, RE-01…RE-05) con nombre, ayuda «cuándo usarla», requiere Jurídico, comentario obligatorio y alerta están en `config/bajas.php` (`causales`) y viven en `causales_rotacion` (columnas `forma_salida`, `requiere_juridico`, `comentario_obligatorio`, `alerta`, `legado`). Textos confirmados por RH.
- **Documentos requeridos** (`documentos_requeridos_baja`): los de la forma + los adicionales de la causal (NR-01 evidencia de desempeño, NR-05/NR-06 autorización de Dirección General, RE-02 arqueo o auditoría, RE-03 evidencia de la falta). Un espacio se cumple con un adjunto (PDF o imagen) o, si el documento lo lleva el sistema (`documento_sistema`), con lo que ya está en el cierre laboral: carta de renuncia / aviso de terminación marcados como firmados físicamente, o el finiquito entregado. El **aviso de rescisión siempre se adjunta** (el sistema no lo genera).
- RN-15: el catálogo lo administra **solo RH** (`finiquitos.causales_rotacion`) en **una sola pantalla**: Bajas y finiquitos → pestaña «Causales» (`rh.cierres.index?seccion=causales`), también enlazada desde Configuración → «Catálogo de bajas». Las causales **nunca se borran** (no hay ruta de borrado): se desactivan. Una causal nueva recibe la siguiente clave de su forma (RV-07…).
- La causal «SC» (sin causal) solo existía en el histórico de Excel y **no** se usa.

### Conversión del subcatálogo anterior (migración `2026_10_20_200000_modulo_bajas_y_causales`)

`App\Services\Bajas\MigracionCatalogoBajasService` (idempotente):

1. Guarda en `cierres_laborales.causal_rotacion_original` / `solicitudes_internas.causal_rotacion_original` el «Tipo · Causal» que tenían.
2. Crea el expediente de baja de cada cierre laboral existente (`origen = cierre_previo`): «Cerrada» si la baja ya se ejecutó, «En revisión RH» si sigue abierto. Sin documentos obligatorios ni alertas.
3. Convierte el catálogo con `config/bajas.php` → `causales_anteriores`. La equivalencia directa se actualiza **en su lugar** (conserva su id); las demás quedan `legado` (inactivas, `reemplazada_por_id`) y sus registros se reapuntan a la nueva. Lo que no mapea limpio deja la baja marcada (`causal_migrada_de`, aviso «Causal migrada: revisar» en bandeja y expediente) y un `Log::warning` con el reporte.

| Anterior | Nueva | Limpio |
|---|---|---|
| mejor_oferta | RV-01 | sí |
| inconformidad_condiciones | RV-02 | sí |
| motivos_personales, cambio_residencia, motivos_salud | RV-05 | sí |
| renuncia_voluntaria, mutuo_acuerdo | RV-05 | **no** (revisar) |
| estudios | RV-06 | sí |
| bajo_desempeno (era rescisión) | NR-01 | sí |
| no_acredita_periodo_prueba | NR-02 | sí |
| termino_contrato_determinado, conclusion_obra | NR-04 | sí |
| abandono_trabajo, faltas_injustificadas | RE-01 | sí |
| falta_probidad | RE-03 | sí |
| incumplimiento_reglamento | RE-05 | sí |
| desobediencia, otra_art_47 (incluye «despido» y «baja inmediata» anteriores) | RE-05 | **no** (revisar) |

El tipo de baja del cierre/solicitud se sincroniza con la forma de su causal (p. ej. «Bajo desempeño» pasa de rescisión a No renovación); el tipo anterior queda en `causal_rotacion_original`.

## Flujo y estatus (`App\Enums\EstatusBaja`) — baja DIRECTA (2026-10-20)

La baja **no es una solicitud** ni pasa por aprobación. El gerente (de su personal, por alcance) o RH la **registra** y queda aplicada en ese momento. Antes de confirmar, en el mismo formulario, se ve la **vista previa** de lo que se generará: la renuncia / el formato de la causal (`GET /rh/documentos-proceso/previa/baja/{colaborador}`, `VistaPreviaFlujoService`) y el **finiquito propuesto** (`GET /rh/bajas/previa/finiquito`, `BajaService::vistaPreviaFiniquito` → `FiniquitoService::propuestaEnMemoria` + `vistaPreviaPdf`). Todo se arma en memoria: nada se guarda hasta confirmar.

`En trámite` (`en_revision_rh`) → [`En revisión Jurídico` si la causal lo requiere, RN-06 — automático al registrar] → `Cerrada` (RH). `No procede` (`cancelada`): RH la revierte, la persona sigue laborando y recupera el acceso.

**Legado** (ya no se crean): `Borrador` y `Rechazada`. La migración `2026_10_20_250000_bajas_directas_sin_borrador` (idempotente, con entrada en la bitácora) convierte `Borrador` → «No procede» (nunca se aplicó: convertirlo en baja aplicada dejaría sin acceso a alguien que sigue laborando; sus datos quedan intactos) y `Rechazada` → «En trámite» (ya estaba aplicada; el motivo se conserva).

| Paso / acción | Quién | Efecto |
|---|---|---|
| **Registrar baja** (`rh.bajas.store`, también «Iniciar baja» del ciclo laboral con `volver=ciclo`; API `POST /api/v1/rh/bajas`) | Gerente (`bajas.solicitar` + alcance) o RH (`bajas.gestionar`) | RN-01…RN-11 completas **antes** de tocar nada. En una transacción: folio BAJ-AAAA-NNNN, cierre laboral aplicado (`CierreLaboralService::aplicarDesdeBaja`: solicitud, cierre, **acceso suspendido**, documentos de la causa **generados** —renuncia en RV; evaluación/aviso en NR-02/NR-04 según `clavesCausa`; RE nada: el aviso de rescisión se adjunta). Después, sin deshacer la baja si falla: **finiquito propuesto** (`calcularFiniquito` con el salario registrado; sin salario queda «Finiquito pendiente» para RH) y avisos. Si la causal requiere Jurídico, nace «En revisión Jurídico». |
| Completar / adjuntar documentos | Gerente (alcance) o RH | En el expediente de la baja o en Bajas y finiquitos → «Documentos». |
| Aprobar / Devolver (Jurídico) | Jurídico (`bajas.revisar_juridico`) | Aprobar → regresa a «En trámite» lista para cerrar. Devolver con observaciones → «En trámite» con el motivo; RH corrige y la reenvía («Enviar a Jurídico») o la marca «No procede». Jurídico no cierra. |
| Cambiar causal (motivo) | RH | Bitácora con usuario, fecha, valor anterior y nuevo; si la nueva requiere Jurídico, pasa sola a Jurídico; sincroniza el cierre. |
| Completar / corregir datos | RH | Fecha, comentario, recontratable (RN-09), aviso de rescisión, «cuenta en rotación» (movimiento interno, RN-14). |
| Cerrar | RH | Datos completos + Jurídico (si aplica) + documentos obligatorios completos (RN-05) + finiquito revisado o entregado + ya pasó el último día. Colaborador **Inactivo** y **bloqueo definitivo** (`CierreLaboralService::ejecutarBaja` → `BajaColaboradorService`) si la entrega del finiquito no lo había hecho ya. |
| Reabrir (motivo) | RH | Cerrada → En trámite (RN-13). |
| No procede (motivo) | **Solo RH** | Cancela el cierre laboral y devuelve el acceso. Nada se borra. |

Ya no existen «Guardar borrador», «Enviar a RH», «Validar» ni el rechazo al gerente (rutas web retiradas). **API v1 compatible**: `store` registra directo (`enviar` se acepta y se ignora), `enviar` responde la baja sin cambios, `update` solo lo acepta RH (corrige datos) y `rechazar` es la devolución de Jurídico. Las APK instaladas siguen usando `POST /api/v1/rh/colaboradores/{c}/cierres` (atajo): crea la baja «En trámite» (causal y campos nuevos opcionales; RH no la cierra sin completarlos). La no renovación desde la evaluación del periodo de prueba crea su baja con NR-02.

### Reglas (BajaReglasService)

RN-01 forma y causal obligatorias · RN-02 la causal es de la forma y cambiar la forma la limpia · RN-03 ayuda debajo del combo · RN-04 comentario ≥ 20 en RV-05 y toda RE · RN-05 documentos · RN-06 Jurídico · RN-07 en RE conocimiento de la falta, forma y fecha de entrega del aviso; > 30 días → «Puede haber prescrito el derecho a rescindir (art. 517 LFT). Consulte a Jurídico» · RN-08 aviso ante el Tribunal > 5 días hábiles después de la baja → aviso a Jurídico · RN-09 en RE «No» por defecto y solo RH lo cambia; un candidato con baja previa «No» o «Con reservas» muestra alerta en su ficha (coincidencia por nombre normalizado, teléfono o correo: el candidato no tiene CURP) · RN-10 fecha ≥ ingreso y ≤ hoy + 15 días · RN-11 una baja abierta por colaborador · RN-12 alertas · RN-13 cerrada no se edita, reabrir con motivo · RN-14 movimientos internos no cuentan · RN-15 catálogo solo RH.

### Alertas (capa única de notificaciones)

Por `NotificadorRhService` / `NotificacionEventosService` (eventos en `config/configuracion_sistema.php`, editables en Configuración → Notificaciones); un fallo nunca deshace la acción. Una sola vez por baja (`bajas.alertas`) y con entrada en bitácora.

| Evento | Destinatarios | Cuándo |
|---|---|---|
| `cierre_baja_solicitada` «Baja registrada» | RH, regional de la persona y **Sistemas** (retirar accesos, cuentas y equipo) | Al registrar (módulo «Bajas», ciclo laboral, API): quién la dio, a quién (puesto · sucursal), forma · causal y último día. El actor recibe la confirmación «Baja registrada». |
| `baja_revision_juridico` | bajas.revisar_juridico | Al registrar una causal con Jurídico; RH la (re)envía; la nueva causal lo requiere |
| `baja_por_revisar` | bajas.gestionar (RH) | Jurídico aprobó o devolvió |
| `baja_alerta_clima_laboral` (+ **correo**) | bajas.gestionar | RV-04 |
| `baja_alerta_contraloria` | bajas.consulta_contraloria | RE-02, RE-03 |
| `baja_alerta_juridico` | bajas.revisar_juridico | RE-03; RN-08 |
| `baja_rotacion_temprana` | bajas.gestionar | antigüedad < 90 días |
| `baja_resuelta` (resultado) | quien la registró | cierre, no procede |

## Permisos (módulo «bajas» en `config/permisos_modulos.php`)

«Bajas» es **solo** para gerentes (su personal, por alcance), RH y super admin; de solo lectura, Jurídico (su revisión) y Contraloría (RE-02/RE-03), como define la especificación.

| Rol | Permisos | Ve |
|---|---|---|
| gerente_sucursal / gerente / gerente_regional | bajas.ver, bajas.solicitar, bajas.tablero.ver (+ alcance sucursal/región) | Registra la baja de su personal; ve sus bajas; tablero de su alcance |
| rh_admin | bajas.ver, bajas.solicitar, bajas.gestionar, bajas.tablero.ver | Todo |
| super_admin | todo el catálogo operativo | Todo |
| rh_auxiliar | bajas.ver, bajas.tablero.ver | Lectura (es RH; no registra) |
| juridico | bajas.revisar_juridico | Rescisiones y causales con Jurídico (lectura + aprobar/devolver) |
| contraloria | bajas.consulta_contraloria | Solo RE-02 y RE-03, lectura |
| direccion, director_comercial, auditor, sistemas | bajas.tablero.ver | Solo el tablero de rotación (sin «Bajas») |
| jefe_directo | — | Sin acceso a «Bajas» ni al atajo del ciclo laboral |

La migración `2026_10_20_250000_bajas_directas_sin_borrador` revoca en bases existentes **solo** estos permisos: a `jefe_directo` bajas.ver/solicitar (+ alcances), cierres.solicitar y solicitudes.bajas.crear; a `auditor` y `sistemas` bajas.ver/solicitar (+ alcances). Nada más de esos roles. El atajo del ciclo laboral (`ColaboradorPolicy::iniciarCierre`, `CierreLaboralService::solicitar`) acepta RH con alcance, el gerente con `bajas.solicitar` y alcance del módulo «bajas», o el gerente en la cadena de mando con `cierres.solicitar`.

Jurídico y Contraloría entran al modo operativo con su permiso de bajas (`NavigationService::tieneModoOperativo`) y su «Inicio» los lleva a la bandeja. Sincronizar en producción: `php artisan migrate --force && php artisan people:sincronizar-permisos`.

## Pantallas y rutas

| Pantalla | Ruta | Menú |
|---|---|---|
| **Bajas** (bandeja: números grandes por estatus, colaborador, forma · causal, estatus, días en trámite, avance de documentos N/M, filtros) | `rh.bajas.index` (`/rh/bajas`) | 7 Cierres laborales → «Bajas» |
| Registrar baja (¿quién?, ¿cómo se va?, ¿por qué?; vista previa de renuncia/formato y finiquito; confirmación) | `rh.bajas.create` → `rh.bajas.store` | botón «Registrar baja» |
| Expediente (datos, documentos con vista previa, bitácora, acciones) | `rh.bajas.show` | — |
| Vista previa del finiquito propuesto (PDF inline, no-store, sin escribir) | `rh.bajas.previa.finiquito` | — |
| **Bajas y finiquitos → Documentos** (recopilación por persona: tarjetas con foto/iniciales, puesto, sucursal, forma · causal con su color, último día, estatus, avance N/M y la lista de documentos —generados, finiquito, entrega del finiquito, adjuntos— con estado generado/firmado/pendiente; botón grande «Vista previa» abre el visor en galería; adjuntar el que falta y descargar; filtros por estatus, sucursal, forma, documentos completos/incompletos y buscador sin mínimo) | `rh.cierres.index` (pestaña por defecto; `BajaRecopilacionService`, alcance del módulo «cierres») | 7 Cierres laborales → «Bajas y finiquitos» |
| Cierres y finiquitos (estado del cierre y del finiquito) | `rh.cierres.index?seccion=cierres` | pestaña |
| Catálogo | `rh.cierres.index?seccion=causales` | Bajas y finiquitos → «Causales» y Configuración → «Catálogo de bajas» |
| Rotación | `rh.bajas.rotacion` (compatibilidad: `reportes.rotacion.tablero.index`; exporta con `.excel`, `.pdf`) | Cierres laborales → Bajas → pestaña «Rotación» |
| Rotación histórica (carga del Excel) | `reportes.rotacion.index` | enlace «Histórico» desde el tablero |
| API v1 | `api.v1.rh.bajas.*` (catálogo, listado, detalle, registrar, compatibilidad enviar/actualizar/rechazar, adjuntar) | — |

En la recopilación, el avance N/M cuenta los documentos **obligatorios para cerrar** (los mismos de RN-05); el filtro «documentos completos/incompletos» y el número «Faltan documentos» se calculan sobre las 300 bajas más recientes del filtro (constante LIMITE_CALCULO de BajaRecopilacionService).

## Plantilla mensual (`plantilla_mensual`)

- **Foto**: `bajas:foto-plantilla` en el scheduler — día 1 a las 00:15 (`activos_inicio`) y último día del mes a las 23:50 (`activos_cierre`): colaboradores vigentes (activos / en incorporación) por sucursal principal. `plantilla_promedio = (inicio + cierre) / 2`. Sin captura manual.
- **Backfill**: `php artisan bajas:plantilla-backfill [--desde=AAAA-MM] [--hasta=AAAA-MM]` (por defecto los últimos 24 meses) reconstruye meses sin foto con las fechas de ingreso/baja de todos los colaboradores (incluidos los dados de baja): activo en la fecha = ingresó en o antes y no tiene fecha de baja o es posterior. Usa la sucursal **actual** de cada persona (no hay historial confiable de cambios de sucursal) y no cuenta a quien no tiene fecha de ingreso (lo informa). Nunca pisa una foto.

## Rotación: un solo motor (Etapa 1, PDF págs. 12, 13 y 17)

`TableroRotacionService` es la definición única; lo consumen la pestaña Rotación de Bajas (`rh.bajas.rotacion`), el Tablero de RH y las exportaciones. La fórmula vive en `App\Services\Rotacion\FormulaRotacion`:

- **Rotación = bajas del periodo ÷ plantilla promedio × 100**; plantilla promedio = (inicio + cierre) ÷ 2. Sin plantilla el índice es `null` («sin datos»), nunca 0 ni división entre cero.
- **Meses anteriores** a `bajas.tablero.mes_inicio_sistema` (`BAJAS_MES_INICIO_SISTEMA`, por defecto `2026-10`): agregados del **Excel** importado (`rotacion_periodos`). Un mes sin Excel queda sin datos: no se reconstruye historia.
- **Desde ese mes**: **bajas del sistema** en el mes de su **último día laborado, aunque el expediente siga en trámite**. No cuentan «No procede», estatus del flujo anterior, movimientos internos (`es_rotacion = false`) ni un último día futuro. Una fila de Excel de esos meses no se suma, así que nada se cuenta dos veces.
- **Plantilla desde el corte**: `plantilla_mensual`; si falta, la del Excel; si tampoco, reconstruida por fechas de ingreso y baja con la sucursal ACTUAL de cada persona (no hay historial confiable de cambios de sucursal). La baja conserva la sucursal donde ocurrió.
- **Desglose**: forma, causal, puesto, antigüedad y expediente solo existen para bajas del sistema. El Excel se pinta como «sin desglose» y solo aporta voluntarias/involuntarias si las trae.
- **Promedio mensual**: promedio de los índices con dato; no incluye el mes en curso si hay meses cerrados. **Acumulada**: bajas del año ÷ promedio de las plantillas promedio mensuales.
- **Voluntaria** = RV; **involuntaria** = NR + RE; OT (Otras terminaciones) se muestra aparte y sigue dentro del total.
- **Global = Corporativo + Sucursales en BAJAS, no en índices**: cada índice divide entre la plantilla de su segmento, así que el índice global no es la suma ni el promedio de los otros dos. `resumen.comprobacion` lo verifica con los mismos filtros.
- **Vistas**: Global, Corporativo, Todas las sucursales, Por sucursal y Por causales, con filtros desde/hasta, puesto, forma y sucursal. Excel y PDF exportan la vista activa con sus filtros (`ReporteRhExport` + `pdf.reporte-rh`).
- **Metas** (`rotacion_metas`, `RotacionMetaService`): por segmento, editables con `bajas.gestionar` desde la pestaña. Un cambio agrega una fila con vigencia y motivo; cada mes se mide contra la meta vigente en ese mes. Valores sembrados de la propuesta: Sucursales 4/6, Corporativo 1.7/2.5, Global 3/4.5. Aquí el semáforo es verde hasta el primer límite, ámbar hasta el segundo y rojo arriba.
- La carga del Excel sigue en `reportes.rotacion.index` (botón «Histórico»), ya con la misma fórmula. `rotacion_periodos.indice` e `indice_excel` solo conservan lo que traía el archivo para auditar la carga. `RotacionFormatoService` sigue generando el formato de RH tal cual.

