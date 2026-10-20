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

## Flujo y estatus (`App\Enums\EstatusBaja`)

`Borrador` (gerente) → **Enviar a RH** → `En revisión RH` → [`En revisión Jurídico` si la causal lo requiere, RN-06] → `Cerrada` (RH). `Rechazada` regresa al gerente con motivo (RH o Jurídico). `No procede` (cancelada): la persona sigue laborando.

| Acción | Quién | Efecto |
|---|---|---|
| Guardar borrador | Gerente (bajas.solicitar + alcance) | Nada fuera de la baja. Folio BAJ-AAAA-NNNN. |
| Enviar a RH | Gerente | RN-01…RN-10 completas. **Suspende el acceso** de inmediato y aplica el cierre laboral (`CierreLaboralService::aplicarDesdeBaja`: solicitud, cierre «Baja aplicada», documentos generados de la causal, finiquito). Reenvío tras rechazo: sincroniza el cierre. |
| Validar | RH (bajas.gestionar) | Revisa causal, documentos y fechas. |
| Enviar a Jurídico / Aprobar (Jurídico) | RH / Jurídico (bajas.revisar_juridico) | Jurídico no cierra. |
| Rechazar (motivo) | RH o Jurídico | Regresa al gerente; el acceso sigue suspendido (RH lo rehabilita desde Usuarios si hace falta, como hoy). |
| Cambiar causal (motivo) | RH | Bitácora con usuario, fecha, valor anterior y nuevo; reinicia Jurídico si la nueva lo requiere; sincroniza el cierre. |
| Completar / corregir datos | RH | Fecha, comentario, recontratable (RN-09), aviso de rescisión, «cuenta en rotación» (movimiento interno, RN-14). |
| Cerrar | RH | Validada + Jurídico (si aplica) + documentos obligatorios completos (RN-05) + finiquito revisado + ya pasó el último día. Colaborador **Inactivo** y **bloqueo definitivo** (cuenta, tokens Sanctum, dispositivos, vacante — `CierreLaboralService::ejecutarBaja` → `BajaColaboradorService`) si la entrega del finiquito no lo había hecho ya. |
| Reabrir (motivo) | RH | Cerrada → En revisión RH (RN-13). |
| No procede (motivo) | RH, o el gerente en borrador/rechazada | Cancela el cierre laboral y devuelve el acceso. Nada se borra. |

**Conflicto resuelto**: la suspensión de acceso al ENVIAR (regla ya aprobada) se conserva; al CERRAR se marca Inactivo y el bloqueo es definitivo (RN-11).

**Atajo de RH / jefe directo** (ciclo laboral → «Iniciar baja», web y `POST /api/v1/rh/colaboradores/{c}/cierres`): mismos campos (componente `CamposBaja.vue`), crea la baja ya «En revisión RH» con folio. Para las APK instaladas la causal y los campos nuevos son opcionales: la baja queda «causal pendiente» y RH no puede validarla ni cerrarla sin completarlos. Nunca se salta los documentos obligatorios para cerrar. La no renovación desde la evaluación del periodo de prueba crea su baja con NR-02.

### Reglas (BajaReglasService)

RN-01 forma y causal obligatorias · RN-02 la causal es de la forma y cambiar la forma la limpia · RN-03 ayuda debajo del combo · RN-04 comentario ≥ 20 en RV-05 y toda RE · RN-05 documentos · RN-06 Jurídico · RN-07 en RE conocimiento de la falta, forma y fecha de entrega del aviso; > 30 días → «Puede haber prescrito el derecho a rescindir (art. 517 LFT). Consulte a Jurídico» · RN-08 aviso ante el Tribunal > 5 días hábiles después de la baja → aviso a Jurídico · RN-09 en RE «No» por defecto y solo RH lo cambia; un candidato con baja previa «No» o «Con reservas» muestra alerta en su ficha (coincidencia por nombre normalizado, teléfono o correo: el candidato no tiene CURP) · RN-10 fecha ≥ ingreso y ≤ hoy + 15 días · RN-11 una baja abierta por colaborador · RN-12 alertas · RN-13 cerrada no se edita, reabrir con motivo · RN-14 movimientos internos no cuentan · RN-15 catálogo solo RH.

### Alertas (capa única de notificaciones)

Por `NotificadorRhService` / `NotificacionEventosService` (eventos en `config/configuracion_sistema.php`, editables en Configuración → Notificaciones); un fallo nunca deshace la acción. Una sola vez por baja (`bajas.alertas`) y con entrada en bitácora.

| Evento | Destinatarios | Cuándo |
|---|---|---|
| `baja_por_revisar` | bajas.gestionar (RH) | Al enviar / atajo; cuando Jurídico aprueba |
| `baja_revision_juridico` | bajas.revisar_juridico | RH la envía a Jurídico |
| `baja_alerta_clima_laboral` (+ **correo**) | bajas.gestionar | RV-04 |
| `baja_alerta_contraloria` | bajas.consulta_contraloria | RE-02, RE-03 |
| `baja_alerta_juridico` | bajas.revisar_juridico | RE-03; RN-08 |
| `baja_rotacion_temprana` | bajas.gestionar | antigüedad < 90 días |
| `baja_resuelta` (resultado) | quien la solicitó | rechazo, cierre, no procede |

## Permisos (módulo «bajas» en `config/permisos_modulos.php`)

| Rol | Permisos | Ve |
|---|---|---|
| gerente_sucursal / gerente / gerente_regional | bajas.ver, bajas.solicitar, bajas.tablero.ver (+ alcance sucursal/región) | Sus bajas y su personal; tablero de su alcance |
| jefe_directo | bajas.ver, bajas.solicitar (alcance equipo) | Su equipo |
| rh_admin | bajas.ver, bajas.solicitar, bajas.gestionar, bajas.tablero.ver | Todo |
| rh_auxiliar, auditor, sistemas | bajas.ver, bajas.tablero.ver | Lectura |
| juridico | bajas.revisar_juridico | Rescisiones y causales con Jurídico |
| contraloria (rol nuevo) | bajas.consulta_contraloria | Solo RE-02 y RE-03, lectura |
| direccion, director_comercial | bajas.tablero.ver | Solo el tablero |

Jurídico y Contraloría entran al modo operativo con su permiso de bajas (`NavigationService::tieneModoOperativo`) y su «Inicio» los lleva a la bandeja. Sincronizar en producción: `php artisan migrate --force && php artisan people:sincronizar-permisos`.

## Pantallas y rutas

| Pantalla | Ruta | Menú |
|---|---|---|
| Bandeja (folio, días en espera, semáforo de documentos, filtros) | `rh.bajas.index` (`/rh/bajas`) | 7 Cierres laborales → «Solicitudes de baja» |
| Solicitud del gerente (4 bloques) | `rh.bajas.create`, `rh.bajas.edit` | botón «Solicitar baja» |
| Expediente (datos, documentos con vista previa, bitácora, acciones) | `rh.bajas.show` | — |
| Catálogo | `rh.cierres.index?seccion=causales` | Bajas y finiquitos → «Causales» y Configuración → «Catálogo de bajas» |
| Tablero de rotación | `reportes.rotacion.tablero.index` (+ `.excel`, `.pdf`) | Reportes → «Rotación de personal» |
| Rotación histórica (carga del Excel) | `reportes.rotacion.index` | enlace «Histórico» desde el tablero |
| API v1 | `api.v1.rh.bajas.*` (catálogo, listado, detalle, crear/actualizar, enviar, rechazar, adjuntar) | — |

## Plantilla mensual (`plantilla_mensual`)

- **Foto**: `bajas:foto-plantilla` en el scheduler — día 1 a las 00:15 (`activos_inicio`) y último día del mes a las 23:50 (`activos_cierre`): colaboradores vigentes (activos / en incorporación) por sucursal principal. `plantilla_promedio = (inicio + cierre) / 2`. Sin captura manual.
- **Backfill**: `php artisan bajas:plantilla-backfill [--desde=AAAA-MM] [--hasta=AAAA-MM]` (por defecto los últimos 24 meses) reconstruye meses sin foto con las fechas de ingreso/baja de todos los colaboradores (incluidos los dados de baja): activo en la fecha = ingresó en o antes y no tiene fecha de baja o es posterior. Usa la sucursal **actual** de cada persona (no hay historial confiable de cambios de sucursal) y no cuenta a quien no tiene fecha de ingreso (lo informa). Nunca pisa una foto.

## Tablero de rotación: cómo se combinan las fuentes

- Meses **anteriores** a `bajas.tablero.mes_inicio_sistema` (`BAJAS_MES_INICIO_SISTEMA`, por defecto `2026-10`): agregados del **Excel de rotación** ya importado (`rotacion_periodos`: bajas y plantilla inicial/final por sucursal). La rotación histórica se quedó con ese Excel (decisión del usuario): no hay carga baja por baja.
- Desde ese mes: **bajas del sistema** cerradas con `es_rotacion = true` (los movimientos internos no cuentan) y la plantilla de `plantilla_mensual`; si a un mes/sucursal le falta plantilla se usa la del Excel.
- Índice = bajas ÷ plantilla promedio × 100 (también para los meses del Excel, con (inicial + final)/2). Acumulado = bajas del año ÷ promedio de las plantillas promedio mensuales × 100.
- **El desglose por forma, causal, puesto, antigüedad y calidad del expediente solo existe para bajas del sistema**; con esos filtros, los meses del Excel salen «sin desglose».
- Vistas: Resumen (índice del mes, acumulado, por forma, rotación temprana, antigüedad promedio, calidad del expediente), Tendencia 12 meses (global y sucursal seleccionada), Causales (barras coloreadas por forma con los tokens `--forma-salida-renuncia/-no-renovacion/-rescision` = #00AEEF/#AF8B51/#0D1B4B), Sucursales (semáforo `--semaforo-sube` #D64550 / `--semaforo-baja` #1FA774 contra el mes anterior), Puestos. Excel/PDF con los filtros aplicados (`ReporteRhExport` + `pdf.reporte-rh`).
- La pantalla anterior «Rotación de personal» (Excel) **no se duplica en el menú**: el acceso del menú abre el tablero; la carga y consulta del Excel siguen en `reportes.rotacion.index` (enlace «Histórico»). Quien solo tiene `rotacion.ver` sigue entrando a la pantalla del Excel.
- Los colores de forma vienen fijados por RH; el validador de paleta marca baja crominancia/lumen y contraste < 3:1 del teal: por eso cada barra lleva su número y porcentaje y cada vista tiene tabla.
