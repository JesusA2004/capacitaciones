# Auditoría final — requisitos del PDF vs. código real

Fecha: 2026-10-02 · Backend/web `capacitaciones` @ `153236b` + cambios de
validación final (sin commit) · App `mr-lana-people-app` @ `5862d41` +
cambios de validación final (sin commit).

Este documento cierra la matriz de `docs/AUDITORIA_CICLO_LABORAL_FINAL.md`
(diagnóstico del 2026-10-01): para cada requisito del PDF del ciclo laboral
indica dónde vive hoy en backend, web, API y app, qué prueba lo cubre y el
resultado. Describe el sistema **real** al cierre de esta validación.

Leyenda de resultado: **OK** = implementado y cubierto por prueba automática ·
**OK (manual)** = implementado, verificado a mano (sin prueba automática
dedicada) · **Externo** = depende de material/credenciales fuera del código.

## 1. Reglas transversales

| Requisito PDF | Backend | Web | API | App | Prueba | Resultado |
|---|---|---|---|---|---|---|
| Preautoriza el superior operativo, autoriza RH; quien preautoriza no autoriza | `AprobacionService` (`ciclo.preautorizar` / `ciclo.autorizar_rh`); `SolicitudesService::cambiarEstado()` bloquea al mismo usuario | Cadena de autorización en el detalle de solicitud | `POST /api/v1/equipo/solicitudes/{id}/visto-bueno` | Pendientes de equipo / Gestión RH | `CicloLaboralFinalTest` («el motor de aprobaciones no permite saltar RH…»), `J: doble autorización…` | OK |
| Jefe directo = organigrama (sin captura manual) | `JefeDirectoService` es el único que escribe `colaboradores.jefe_id`; `organigrama:sincronizar-jefes` | No existe pantalla ni selector de jefe | No hay endpoint para fijar jefe | No hay selector de jefe | Pruebas de organigrama/jefe directo | OK |
| Alcance organizacional (un gerente no ve otra sucursal) | `AlcanceOrganizacionalService` | Listados filtrados | Mismo service | — | `I: un gerente de la sucursal A no ve…` | OK |
| El colaborador no ve estados internos sensibles | `CicloLaboralService::misPendientes()` / `miProceso()` | «Mis pendientes», Mi expediente sin pestaña Onboarding ni fechas de periodo de prueba | `GET /api/v1/colaborador/mi-proceso` | «Lo que necesitas hacer» consume solo `mi-proceso` | `CicloLaboralApiTest`, `miProceso.test.ts` | OK |

## 2. Etapa 1 — Reclutamiento

| Requisito PDF | Backend | Web | API | App | Prueba | Resultado |
|---|---|---|---|---|---|---|
| Pipeline perfil → entrevista → psicométricas → socioeconómico → referencias → preautorización → autorización RH | `CandidatoWorkflowService` (única autoridad de transiciones) | Candidatos → detalle | `/api/v1/rh/candidatos/*` | Candidatos (listado + ficha + workflow) | `E2E: candidato → contratación…`, `A`, `B` | OK |
| Evidencia socioeconómica (fotos/video privados) | `candidato_evidencias` en disco privado | Ficha del candidato | Mismo endpoint | Carga de foto/PDF/video | Pruebas de reclutamiento | OK |
| QR solo para candidato autorizado por RH | `IncorporacionInvitacionService::crear()` valida autorización | Liga/QR desde la ficha | — | Registro por QR | `A: contratación exitosa genera un solo QR…` | OK |

## 3. Etapa 2 — Contratación y expediente digital

| Requisito PDF | Backend | Web | API | App | Prueba | Resultado |
|---|---|---|---|---|---|---|
| Registro sin duplicar persona | `AltaColaboradorService::validarNoDuplicado` + `ContratacionCandidatoService` | Altas | `/api/v1/colaborador/incorporacion/*` | Incorporación | `A`, `AltaColaboradorTest` | OK |
| Expediente completo = obligatorios **aprobados** (sin opcionales) | `ExpedienteService::estadoDocumental` | Expediente | `GET /api/v1/colaborador/expediente` | Expediente | `C: documento rechazado…`, `ExpedienteAltaCatalogoRealTest` | OK |
| Contratos impresos → firma física/huella → envío → recepción → escaneo → archivo | `FlujoDocumentalService` | Documentos laborales | `/api/v1/rh/documentos-laborales/*` | Documentos laborales | `PDF etapa 2: contratos impresos…`, `FlujoDocumentalFisicoTest` | OK |
| Sin texto jurídico en código | `config/contratos.php` + plantillas cargadas por RH; plantilla faltante → tarea | Plantillas y formatos | — | — | `MotorDocumentalTest` | OK (plantillas reales: **Externo**) |

## 4. Etapa 3 — Onboarding

| Requisito PDF | Backend | Web | API | App | Prueba | Resultado |
|---|---|---|---|---|---|---|
| Evaluación con mínimo 8: < 8 no pasa (refuerzo RH + reintento), ≥ 8 pasa | `OnboardingService` (`calificacion >= calificacion_minima`; `ciclo_laboral.onboarding.calificacion_minima` = 8) | Onboarding RH (retroalimentación) | `POST /api/v1/colaborador/onboarding/avances/{avance}/evaluacion`, `.../retroalimentacion` | Lecciones (`/lecciones`) + Onboarding RH móvil | `PDF etapa 3: 7.9 no pasa…, 8.0 sí pasa` | OK |
| Entrega de activos + responsivas | `OnboardingService` / motor documental | Onboarding | `POST /api/v1/rh/onboarding/{proceso}/activos` | Onboarding RH | `CicloLaboralFinalTest` | OK (material de inducción: **Externo**) |

## 5. Etapa 4 — Periodo de prueba

| Requisito PDF | Backend | Web | API | App | Prueba | Resultado |
|---|---|---|---|---|---|---|
| Duración por puesto: Gestor 2 meses, Gerente y Regional 3 | `puestos.meses_periodo_prueba` (semilla en `ciclo_laboral.periodo_prueba.meses_por_puesto`), `ContratoLaboralService::fechaFinPeriodoPrueba()` | Configuración → puestos | — | — | `PDF etapa 4: la duración sale de la configuración del puesto…` | OK |
| Aviso/evaluación 15 días antes, idempotente | `contratos:revisar-vencimientos` (`VencimientoContratosService`) | Mis pendientes | — | Tareas | `PDF etapa 4: el comando… 10 veces crea UNA evaluación` | OK |
| Jefe recomienda; RH decide renovación/no renovación | `EvaluacionPeriodoPruebaService` + `AprobacionService` | Ciclo laboral | `/api/v1/evaluaciones/*` | Evaluaciones | `F: el jefe recomienda NO renovar…` | OK |

## 6. Etapas 6-7 — Cierre laboral, baja y reingreso

| Requisito PDF | Backend | Web | API | App | Prueba | Resultado |
|---|---|---|---|---|---|---|
| Al solicitar la baja se suspende el acceso al instante; estatus laboral no cambia hasta la fecha efectiva | `CierreLaboralService::solicitar` + `BajaColaboradorService` (cuenta inactiva, tokens y dispositivos revocados) | Solicitud de baja | `POST /api/v1/rh/colaboradores/{id}/cierres` | Cierres RH | `PDF etapas 6-7…`, `BajaSuspendeAccesoTest` | OK |
| Regionales y RH avisados; pueden rehabilitar acceso | `BajaColaboradorService` + notificaciones | Expediente | — | — | `un regional puede rehabilitar el acceso…` | OK |
| Finiquito: calcular, revisar, autorizar, programar pago, firma, pago; firmado/pagado queda bloqueado | `FiniquitoService`, `FiniquitoCalculoPolicy` (sin ajustes ni nueva carga si está firmado o pagado) | `FiniquitoPanel.vue` en el detalle de la solicitud | `/api/v1/rh/cierres/*` | Cierres RH | `G: cierre — gerente solicita…`, `CierreLaboralFiniquitoTest` | OK |
| Nunca se borran usuarios ni expedientes | Historial y relaciones con `withTrashed()` (p. ej. `MovimientoLaboral`, historial de solicitudes) | El detalle de solicitud abre aunque el colaborador esté dado de baja | — | — | `G` (cierre conservando el expediente) | OK |
| Reingreso = misma persona, misma cuenta e historial | `ReingresoService` | Reingresos | `/api/v1/rh/reingresos/*` | Reingresos | `H: reingreso reutiliza al mismo colaborador…`, `PDF etapas 6-7…` | OK |

## 7. Solicitudes, vacaciones y recibos (lo que toca el ciclo)

| Requisito | Backend | Web | API | App | Prueba | Resultado |
|---|---|---|---|---|---|---|
| Estados finales: **aprobada**, rechazada, cancelada. No existe «cerrada» | `EstadoSolicitudInterna` (7 casos; `esFinal()` = aprobada/rechazada/cancelada). Migración `2026_10_02_180000_quitar_estado_cerrada_de_solicitudes` convierte las antiguas a `aprobada`, conserva el historial (acción → `comentario` con su texto) y retira `solicitudes.cerrar` | Tablero sin columna «Cerrada» | Igual | Timeline: Enviada → En revisión → Aprobada | `SolicitudesTableroKanbanTest`, `request.test.ts` | OK |
| Solicitud finalizada no acepta adjuntos | `SolicitudesService::adjuntarDocumento()` | Mismo service | Mismo service | — | Pruebas de solicitudes | OK |
| Vacaciones: flujo unificado; gerente/regional dan visto bueno, RH autorización final | `VacacionesService` sobre el motor de solicitudes + `AprobacionJerarquicaService` | Vacaciones | `/api/v1/vacaciones/*`, `/api/v1/rh/vacaciones/*` | Vacaciones | `VacacionesPermisosDocumentalTest` | OK |
| Recibo de nómina semanal (PDF «RECIBO DE NÓMINA» en el expediente) | `ReciboNominaService` | Recibos | `/api/v1/colaborador/recibos/*`, `/api/v1/rh/recibos/*` | Recibos (PDF sin conexión) | `ReciboNominaSemanalTest` | OK |

## 8. Resultado de la validación final

Ver `docs/CIERRE_QA_FINAL.md` (sección «Validación final 2026-10-02»)
para los números exactos de cada suite.
