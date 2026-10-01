# Auditoría del ciclo laboral — cierre definitivo MR. LANA PEOPLE

Fecha: 2026-10-01 · Repositorio: `capacitaciones` (backend + web) · Rama `main` @ `64613bf`.

Esta auditoría es el **Bloque 0** del cierre. Clasifica cada pieza que existe
en el repositorio contra el recorrido funcional definitivo:

```
Etapa 1 Reclutamiento → Etapa 2 Contratación/expediente → Etapa 3 Onboarding
→ Etapa 4 Periodo de prueba → Etapa 6 Cierre laboral / Reingreso
```

La Etapa 5 (permanencia: vacaciones, permisos, préstamos, desempeño, Nine
Box, capacitación continua, NOM-035) **no** forma parte del cierre: su código
y datos se conservan, pero no se usan como requisito ni se muestran en el
nuevo recorrido.

Clasificación usada: **OK REUTILIZAR** · **CORREGIR** · **EXTENDER** ·
**LEGACY / RETIRAR DEL FLUJO** · **FALTA**.

---

## 1. Baseline inicial (antes de tocar código)

| Comando | Resultado inicial |
|---|---|
| `composer run types:check` (PHPStan/Larastan nivel 7) | ✅ 0 errores |
| `composer run lint:check` (Pint) | ✅ passed |
| `npm run types:check` (vue-tsc) | ✅ exit 0 |
| `npm run lint:check` (ESLint) | ✅ exit 0 |
| `npm run build` | ✅ built in 14.8 s |
| `php artisan test` (Pest, `xdebug.mode=off`) | ver §1.1 |

### 1.1 Suite Pest

Ejecutada sobre `main` limpio con
`php -d xdebug.mode=off vendor/bin/pest --compact`. El resultado se anota al
terminar la corrida (ver §9, "Resultado del baseline de Pest").

---

## 2. Matriz requisito ↔ implementación

### 2.1 Arquitectura transversal

| Requisito | ¿Existe? | Archivo / Service actual | Estado real | Falta | Acción | Prueba que lo cubre |
|---|---|---|---|---|---|---|
| Lógica en Services, controladores delgados | Sí | `app/Services/**` (150+ services) | Mayoría correcta. Excepción: `Rh\CandidatoController` (449 líneas) mueve estados directamente (`actualizarEstado`) | Service de reclutamiento que sea la única autoridad de transiciones | **CORREGIR** → `CandidatoWorkflowService` | `tests/Feature/CicloLaboral/ReclutamientoFlujoTest.php` (nuevo) |
| Mismo Service para web y API | Parcial | Contratos, evaluaciones, cierres y documentos laborales solo tienen API (`Api\V1\Rh\*`); reclutamiento solo web | Web y API no cubren el mismo universo | Pantallas web de proceso + endpoints API de reclutamiento | **EXTENDER** | E2E web + API |
| Estado consolidado de la persona (`etapa`, `estado`, `responsable`, `siguiente_accion`, `bloqueos`, `timeline`, `acciones_permitidas`) | No | Fragmentos: `CandidatoTimelineService`, `AltaColaboradorService::checklist`, `OnboardingService::checklist`, `ProgresoExpediente` | Cada pantalla inventa su estado | `CicloLaboralService::obtenerEstado()` + DTO estable | **FALTA** | `CicloLaboralServiceTest` |
| Timeline única | Parcial | `seguimientos_candidato` (candidato), `activity_log` log `rh` vía `AuditoriaService`, `documento_eventos` | Tres fuentes sin unificar; candidato y colaborador no comparten historia | Lector único sobre las fuentes reales (sin crear segunda fuente de verdad) | **EXTENDER** → `TimelineService` | E2E |
| Motor de aprobaciones genérico | No | `solicitud_aprobaciones` + `AprobacionJerarquicaService` (solo `solicitudes_internas`, nivel jefe; usado por préstamos — Etapa 5) | No es polimórfico; no registra rol requerido, orden, snapshot, IP | Tabla/servicio polimórfico preautorización → RH | **FALTA** (nuevo `aprobaciones` + `AprobacionService`); `solicitud_aprobaciones` queda **LEGACY** para Etapa 5 | `AprobacionServiceTest`, escenarios A/B/E/F/G/J |
| Jerarquía de mando (persona → jefe → cadena) | Sí | `JerarquiaColaboradorService` (`jefe_id`, `gerente_id`), `JerarquiaPuestoService` (puestos) | Resuelve jefe y "es superior de", pero no expone `cadenaDeMando`, ni "quién preautoriza", ni "quién autoriza RH" | `supervisorDirectoDe`, `cadenaDeMandoDe`, `subordinadosDe`, `puedePreautorizar`, `puedeAutorizarRh` | **EXTENDER** → `OrganizacionJerarquiaService` (envuelve a `JerarquiaColaboradorService`) | `JerarquiaAprobadoresTest` |
| Alcance organizacional | Sí | `AlcanceOrganizacionalService` | Correcto y usado en contratos/cierres/evaluaciones. Candidatos usan `limitarPorSucursal` (incluye `sucursal_id NULL` como visible para todos) | Verificar IDOR en endpoints nuevos | **OK REUTILIZAR** | Escenario I |
| Bandeja de pendientes | Sí | `tareas_rh` + `TareaService` (dedupe por `clave_abierta` única) | Sólida, idempotente. Faltan tipos de tarea de reclutamiento, onboarding, reingreso, cierre (preautorización/pago) | Nuevos `TipoTarea` | **EXTENDER** | Escenarios D/E |
| Notificaciones accionables + push | Sí | `NotificadorRhService`, `PushNotifier`, `DestinoNotificacionService` | Reutilizable. `ResponsableResolverService` aún es User-first | Eventos de reclutamiento/onboarding/reingreso | **EXTENDER** | — |
| Auditoría (IP/UA) | Sí | `AuditoriaService` → Spatie Activitylog (log `rh`) | Correcta | — | **OK REUTILIZAR** | — |
| Enums para catálogos | Sí | `app/Enums/*` | Correcto | Enums nuevos para onboarding, reingreso, aprobaciones | **EXTENDER** | — |

### 2.2 Etapa 1 — Reclutamiento y selección

| Requisito | ¿Existe? | Archivo / Service actual | Estado real | Falta | Acción | Prueba |
|---|---|---|---|---|---|---|
| Registro de candidato con fuente/campaña/vacante/fecha | Sí | `Candidato` (`fuente`, `campana_reclutamiento_id`, `vacante_id`, `created_at`), `FuenteCandidato` (incluye `meta`), `CampanaReclutamiento` | Correcto | Fuente "grupos de Facebook" no existe como valor | **EXTENDER** `FuenteCandidato::FacebookGrupos` | `ReclutamientoFlujoTest` |
| Estados del pipeline | Sí | `EstadoCandidato` (recibidos, preseleccion, entrevista, psicometricos, estudio_socioeconomico, pruebas, validacion_documental, oferta_aprobacion, listo_para_contratacion, contratado + 4 salidas) | Estados de tablero kanban; el arrastre permite **saltar etapas hacia adelante** (p. ej. de `recibidos` a `contratado`) y **no existe autorización RH** | Pipeline real (perfil → entrevista → psicométricas → socioeconómico → referencias → preautorización gerente → autorización RH → contratación) | **CORREGIR** (consolidar enum + migración de datos) | `ReclutamientoFlujoTest`, escenario A |
| Revisión de perfil con motivo obligatorio | No | `actualizarEstado` acepta salida sin motivo obligatorio | — | Acción `evaluarPerfil` | **FALTA** | escenario A/B |
| Entrevista (fecha, entrevistador, observaciones, resultado) | Parcial | `candidatos.fecha_entrevista`, `resultado_entrevista` (texto libre) | Sin entrevistador ni resultado estructurado | Tabla `candidato_entrevistas` | **FALTA** | `ReclutamientoFlujoTest` |
| Psicométricas (link, realizado, resultados, revisión gerente) | No | Solo un estado | — | `candidato_psicometricas` + evidencia | **FALTA** | idem |
| Estudio socioeconómico (fecha, visitador, dirección, checklist, observaciones, resultado, fotos/video privados) | No | Solo un estado | — | `candidato_socioeconomicos` + `candidato_evidencias` (privadas, NAS) | **FALTA** | idem |
| Referencias laborales individuales | No | — | — | `candidato_referencias` | **FALTA** | idem |
| Preautorización del gerente | No | — | — | Aprobación `preautorizacion` | **FALTA** | escenario A/B/J |
| Autorización final RH (obligatoria) | No | `ContratacionCandidatoService::contratar` acepta `oferta_aprobacion`/`listo_para_contratacion` sin ninguna aprobación | **Hueco crítico**: RH/gerente puede contratar sin autorización registrada | Aprobación `autorizacion_rh` con bloqueo | **FALTA** | escenarios A/B/J |
| QR solo para candidato autorizado | No | `IncorporacionInvitacionService::crear` acepta `candidato_id` arbitrario o ninguno | **Hueco crítico** | Validación en `crear()` cuando hay candidato; QR del ciclo solo desde `CandidatoWorkflowService` | **CORREGIR** | escenarios A/B |
| Timeline del candidato | Parcial | `seguimientos_candidato` + `CandidatoTimelineService` (presentación) | Funciona pero mezcla etapas antiguas | Integrar en `TimelineService` | **EXTENDER** | E2E |
| Funnel "personas que alcanzaron cada etapa" | No | Dashboard cuenta estado actual | Una persona que avanzó "desaparece" de etapas previas | `candidatos.etapa_maxima` (hito máximo alcanzado) | **FALTA** | `DashboardRhTest` |

### 2.3 Etapa 2 — Contratación y expediente digital

| Requisito | ¿Existe? | Archivo / Service actual | Estado real | Falta | Acción | Prueba |
|---|---|---|---|---|---|---|
| Invitación QR (token no predecible, temporal, revocable, single-use, ligado al candidato) | Sí | `IncorporacionInvitacionService` (token 64 chars, solo hash en BD, TTL ≤ 24 h, `lockForUpdate`, `max_usos`) | Correcto técnicamente | Ligar a candidato autorizado (ver 2.2) | **CORREGIR** | `IncorporacionInvitacionApiTest` + escenario A |
| Registro sin duplicar persona (Candidato → Colaborador → User) | Parcial | `registrarUsuario()` crea Colaborador+User; `ContratacionCandidatoService` crea otro Colaborador por otra vía; `AltaColaboradorService::validarNoDuplicado` revisa CURP/RFC/NSS | **Tres vías de alta** (QR app, `AltaDigital` por liga, alta manual/contratación) con reglas distintas. El registro por QR **no** enlaza `candidatos.colaborador_id`, no crea contrato ni valida duplicados | Alta por QR que reutilice persona y enlace candidato | **CORREGIR**: el registro QR del candidato pasa por `ContratacionService` (mismo `AltaColaboradorService::validarNoDuplicado`) | escenarios A/H/J |
| `AltaDigital` (liga pública web) | Sí | `AltaDigital*`, `routes/alta-publica.php`, `ConversionColaboradorService` | Flujo paralelo histórico (captura web sin cuenta) | — | **LEGACY / RETIRAR DEL FLUJO** (se conserva funcional; el ciclo usa QR) | `AltaDigitalTest` existente |
| Checklist documental configurable | Sí | `document_types` (`requerido`, `aplica_alta`, `activo`, `categoria`), `ExpedienteService::estadoDocumental` | Correcto, basado en catálogo | Verificar que el seeder incluya los 11 documentos base | **OK REUTILIZAR** (completar seeder) | `ExpedienteTest` |
| Validación técnica del archivo (MIME real, tamaño, integridad) | Sí | `mimes:` (detección por contenido) + `max:` en FormRequests; `hash` sha256; versionado | Se guarda el MIME **declarado por el cliente** | Guardar MIME detectado | **CORREGIR** (menor) | escenario C |
| RH aprueba/rechaza con motivo, recarga, historial de versiones | Sí | `IncorporacionService::aprobarDocumento/rechazarDocumento`, `DocumentoStorageService::subirVersion` (`previous_version_id`, versión anterior → archivado) | Correcto | Integrar con estado del ciclo y timeline | **OK REUTILIZAR** | escenario C |
| Expediente completo = obligatorios **aprobados** | Sí | `ExpedienteService::estadoDocumental` / `AltaColaboradorService::calcularEstado` | Correcto | — | **OK REUTILIZAR** | escenario C |
| Contratos configurados (periodo de prueba, confidencialidad, no competencia) sin texto jurídico en código | Sí | `config/contratos.php` (`paquetes_alta`), `MotorDocumentalService`, `ContratoLaboralService::prepararDocumentos` (falta de plantilla → tarea `contrato_pendiente`) | Correcto | Generar solo **después** de expediente completo (hoy se generan al alta) | **CORREGIR** | E2E |
| Firma física: impresión, firma, huella, envío mensual, recepción, archivo | Sí | `FlujoDocumentalService` + `SeguimientoDocumentoFisico` + `documento_eventos` | Correcto y con `lockForUpdate` | Pantalla web (hoy solo API) | **EXTENDER** (web) | `FlujoDocumentalFisicoTest` |
| Pasa a onboarding solo con contratos firmados | No | `AltaColaboradorService::activar` activa directo | Onboarding no existe como etapa | Ver 2.4 | **CORREGIR** | E2E |

### 2.4 Etapa 3 — Onboarding

| Requisito | ¿Existe? | Archivo / Service actual | Estado real | Falta | Acción | Prueba |
|---|---|---|---|---|---|---|
| Inducción institucional (material + evaluación ≥ 8, reintentos ilimitados con refuerzo RH) | No | `OnboardingService` es solo un checklist administrativo calculado. El motor de cuestionarios de Capacitación (`IntentoCuestionarioService`) está acoplado a cursos/lecciones de usuario y oculto tras el flag `capacitacion` | — | Módulos de onboarding con evaluación propia, intentos y refuerzo | **FALTA** (nuevo dominio `onboarding_*`; Capacitación **no** se reutiliza para no mezclar conceptos) | escenario D |
| Inducción al puesto (módulos por puesto, orden, sin saltos) | No | — | — | `onboarding_modulos.puesto_id` + orden | **FALTA** | escenario D |
| Entrega de activos configurable + cartas responsivas | No | Plantilla `carta_responsiva` ya existe en `config/contratos.php` | No hay inventario/tipos de activo | `tipos_activo` + `entregas_activo` + responsiva vía motor documental | **FALTA** | E2E |
| Checklist visual (contratos, institucional, puesto, activos, responsivas) | Parcial | `OnboardingService::checklist` (administrativo) | — | Reescribir sobre el nuevo dominio, conservando el checklist administrativo como "Contratación" | **EXTENDER** | E2E |

### 2.5 Etapa 4 — Periodo de prueba

| Requisito | ¿Existe? | Archivo / Service actual | Estado real | Falta | Acción | Prueba |
|---|---|---|---|---|---|---|
| Duración por puesto (gestor 2 m, gerente 3 m, regional 3 m) | No | Fecha de fin capturada a mano en el alta | — | `puestos.meses_periodo_prueba` (seeder con valores iniciales) | **FALTA** | `PeriodoPruebaTest` |
| Scheduler 15 días antes, idempotente | Sí | `VencimientoContratosService` (`contratos:revisar-vencimientos`, `aviso_vencimiento_en`, evaluación única por contrato) | Correcto | Solo debe aplicar a quien ya completó onboarding / está activo | **OK REUTILIZAR** | `VencimientoEvaluacionTest`, escenario E |
| Evaluación (criterios, promedio, observaciones, recomendación) | Sí | `EvaluacionPeriodoPruebaService::capturar` | Correcto | Registrar captura como **preautorización** en el motor de aprobaciones | **EXTENDER** | escenario E/F |
| RH autoriza renovación / no renovación / devuelve | Sí | `::autorizar`, `::devolver` | Correcto, pero sin registro de aprobación y sin validar que el actor sea RH distinto del jefe | Aprobación RH | **EXTENDER** | escenarios E/F |
| Renovación → contrato indeterminado al flujo documental | Sí | `ContratoLaboralService::renovar` | Correcto | — | **OK REUTILIZAR** | escenario E |
| No renovación → documentos + cierre sin baja inmediata | Sí | `CierreLaboralService::iniciar` desde la evaluación | Correcto (la baja solo se ejecuta después) | El cierre debe nacer ya "autorizado por RH" | **EXTENDER** | escenario F |

### 2.6 Etapa 6 — Cierre laboral y reingreso

| Requisito | ¿Existe? | Archivo / Service actual | Estado real | Falta | Acción | Prueba |
|---|---|---|---|---|---|---|
| Causas (renuncia, no renovación, bajo desempeño, baja inmediata) | Parcial | `TipoBaja` (renuncia, despido, mutuo_acuerdo, fin_contrato, abandono, no_renovacion, otro) | Faltan "bajo desempeño" y "baja inmediata (rescisión)" | Nuevos casos; los existentes se conservan | **EXTENDER** | escenario G |
| Solicitud por jefe/gerente con evidencia | Parcial | `CierreLaboralService::iniciar` (permiso `cierres.gestionar`, solo RH) | Solo RH puede iniciar | Solicitud por jefe + preautorización operativa + autorización RH | **CORREGIR** | escenario G |
| Finiquito (generar, revisar, ajustar con auditoría, autorizar) | Sí | `FiniquitoService` (conceptos, ajustes, revisión, PDF) | Correcto | Paso "finiquito autorizado" explícito | **EXTENDER** | `CierreLaboralFiniquitoTest` |
| Regional programa el pago (fecha, monto, responsable, método) | No | `confirmarPago` solo registra referencia | — | `programarPago` | **FALTA** | escenario G |
| Gerente cita, firma/huella, pago, cierre | Parcial | `registrarFiniquitoFirmado`, `confirmarPago`, `ejecutarBaja`, `cerrarExpediente` | Correcto, conserva todo (nunca DELETE) | Cita | **EXTENDER** | escenario G |
| Baja directa extraordinaria | Sí | `Rh\ExpedienteController::darDeBaja` (`usuarios.desactivar`) | Acción extraordinaria existente | — | **OK (extraordinaria, fuera del flujo normal)** | `ExpedienteTest` |
| Reingreso sin duplicar persona | Parcial | `BajaColaboradorService::reactivar` (deshace baja), `validarNoDuplicado` (bloquea alta con CURP/RFC/NSS existentes) | No hay solicitud/decisión de reingreso, ni documentos vencidos, ni contratos de reingreso | `reingresos` + `ReingresoService` | **FALTA** | escenario H |

### 2.7 Dashboard RH

| Requisito | ¿Existe? | Archivo / Service actual | Estado real | Falta | Acción | Prueba |
|---|---|---|---|---|---|---|
| Plantilla activa vs autorizada | Parcial | `HeadcountTarget.plantilla_autorizada`, `HeadcountService` | No está en el tablero | Card | **EXTENDER** | `DashboardRhTest` |
| Vacantes abiertas (sucursales / corporativo) | Parcial | `Vacante` | No distingue corporativo | Clasificación por sucursal corporativa (`sucursales.es_corporativo`) | **EXTENDER** | idem |
| Rotación del mes (bajas / plantilla promedio) | Parcial | `MetricasRhDashboardService::rotacion` (bajas / plantilla actual) | Fórmula distinta a la pedida | Plantilla promedio | **CORREGIR** | idem |
| Costo por contratación, tiempo de contratación, permanencia, contratos por vencer, inversión del mes | Parcial | `CostoReclutamientoService`, `CampanaReclutamiento.monto` | No integrados | Cards | **EXTENDER** | idem |
| Embudo, tiempo por nivel, rotación mensual | No/Parcial | — | — | DTO `summary/recruitment_funnel/time_to_hire_by_level/turnover_monthly` | **FALTA** → `RhDashboardService` (nuevo, en `Services/Reportes`) | idem |

### 2.8 API v1 y app

| Requisito | ¿Existe? | Archivo | Estado | Falta | Acción |
|---|---|---|---|---|---|
| `GET /mobile/bootstrap` | Sí | `MobileBootstrapService` | Sin etapa laboral ni contadores del ciclo | Agregar `ciclo_laboral` y capacidades del ciclo | **EXTENDER** |
| `GET /rh/pendientes` | Sí | `RhPendientesService` (solicitudes, vacaciones, documentos, incorporaciones — Etapa 5 incluida) | Mezcla Etapa 5; no usa `tareas_rh` | Basar en `TareaService` + filtros tipo/etapa/sucursal/urgencia | **CORREGIR** |
| Candidatos API | No | — | — | `GET/POST /rh/candidatos/*` | **FALTA** |
| Onboarding / reingreso API | No | — | — | Endpoints | **FALTA** |
| Contratos/evaluaciones/cierres API | Sí | `Api\V1\Rh\*`, `EvaluacionController` | Correctos sobre services | Ajustar a aprobaciones | **EXTENDER** |
| Endpoint de prueba de push | Sí | `POST dispositivos/push-prueba` (throttle 5/min) | Envía solo al propio usuario | — | **OK** (no es endpoint de desarrollo peligroso) |

---

## 3. Duplicaciones detectadas y consolidación decidida

| Concepto | Duplicados encontrados | Decisión |
|---|---|---|
| Alta de persona | (a) `IncorporacionInvitacionService::registrarUsuario` (QR app), (b) `AltaDigital` + `ConversionColaboradorService` (liga web), (c) `AltaColaboradorService::registrar` (manual), (d) `ContratacionCandidatoService::contratar` | Ruta única del ciclo: candidato autorizado → `ContratacionService` → QR → registro que **reutiliza** el colaborador pre-creado por `AltaColaboradorService`. (b) queda legacy fuera del flujo; (c) se conserva para altas sin candidato (personal histórico), con las mismas validaciones anti-duplicado. |
| Estados de candidato | `EstadoCandidato` con fases de tablero (`pruebas`, `validacion_documental`, `oferta_aprobacion`, `listo_para_contratacion`) que no corresponden al proceso real | Se consolida el enum; migración de datos mapea valores viejos a los nuevos (ver §5). |
| Aprobaciones | `solicitud_aprobaciones` (solo solicitudes, Etapa 5) vs. aprobaciones del ciclo inexistentes | Nueva tabla polimórfica `aprobaciones` para el ciclo; `solicitud_aprobaciones` queda **legacy** (la usan préstamos — Etapa 5; se migrará cuando se abra esa etapa). |
| Estado "en qué va la persona" | `estado_alta`, `estatus`, `incorporacion_decision`, `EstadoAltaDigital`, checklist de onboarding | `CicloLaboralService` deriva un estado único a partir de las fuentes reales. `estado_alta` se mantiene como estado persistido de la Etapa 2. |
| Bandeja de pendientes | `tareas_rh` (`TareaService`) vs. `RhPendientesService` (calcula en vivo solicitudes/vacaciones/documentos/incorporaciones) | `tareas_rh` es la fuente; `RhPendientesService` queda como compatibilidad de la app (Etapa 5) y la nueva bandeja `/rh/pendientes` usa `TareaService`. |
| Vacaciones | `solicitudes_vacaciones` (legacy) + solicitudes unificadas (tipo `vacaciones`) | Etapa 5, fuera de alcance; ya documentado como legacy en `routes/web.php`. No se toca. |
| `ResponsableResolverService` | User-first; paralelo a `NotificadorRhService::responsablesDe` (Colaborador-first) | Legacy; los flujos nuevos usan `NotificadorRhService`. |

---

## 4. Legacy / fuera del recorrido

| Pieza | Consumidores | Acción en este cierre |
|---|---|---|
| Capacitación (cursos, cuestionarios, sesiones) | Rutas bajo `feature:capacitacion` | Se conserva oculta (CLAUDE.md). Onboarding **no** depende de ella. |
| Desempeño / Nine Box | Flags `desempeno`, `nine_box` en `false` | Sin cambios. |
| Vacaciones/permisos/préstamos/recibos (Etapa 5) | Web de solicitudes unificadas, API móvil | Sin cambios funcionales; se retiran de la bandeja del ciclo y del recorrido del colaborador en la app. |
| `AltaDigital` (liga pública) | `Rh\AltaDigitalController`, `routes/alta-publica.php` | Conservado; fuera del recorrido (menú secundario). |
| `CandidatoController::actualizarEstado` (arrastrar en kanban) | `Rh/Candidatos/Index.vue` | Restringido a salidas (descartar con motivo); los avances solo por acciones del workflow. |
| `RhPendientesService` | `Api\V1\Rh\PendienteController`, `RhDashboardService` móvil | Reemplazado por bandeja basada en `tareas_rh`. |
| `solicitud_aprobaciones` | Préstamos (Etapa 5) | Legacy documentado. |

---

## 5. Mapeo de estados de candidato (migración de datos)

| Valor anterior | Valor consolidado | Razón |
|---|---|---|
| `recibidos` | `recibidos` (Interesado · revisión de perfil) | Igual |
| `preseleccion` | `entrevista_pendiente` | Perfil viable, pendiente de entrevista |
| `entrevista` | `entrevista_pendiente` | La entrevista no tenía resultado estructurado |
| `psicometricos`, `pruebas` | `psicometricas_pendientes` | |
| `estudio_socioeconomico` | `socioeconomico_pendiente` | |
| `validacion_documental` | `referencias_pendientes` | |
| `oferta_aprobacion` | `preseleccion_gerente` | Sin preautorización registrada: el gerente debe preautorizar |
| `listo_para_contratacion` | `autorizacion_rh_pendiente` | Nunca hubo autorización RH registrada: RH debe autorizar |
| `contratado` | `contratado` | |
| salidas | se conservan (+ `rechazado_rh`) | |

---

## 6. Hallazgos de seguridad / IDOR

1. **Contratación sin autorización RH** (`ContratacionCandidatoService`): un usuario con permiso de candidatos podía contratar desde `oferta_aprobacion`. → Se exige aprobación RH registrada.
2. **QR para cualquier candidato** (`IncorporacionInvitacionService::crear`): → solo candidatos `autorizado_rh`.
3. **Candidatos sin sucursal visibles para todos** (`limitarPorSucursal` incluye `NULL`): aceptable para el pipeline general de reclutamiento; las acciones de gerente exigen que el candidato tenga sucursal dentro de su alcance.
4. Descargas de expediente/documentos laborales: ya pasan por controlador + Policy + alcance (`DocumentoLaboralController::descargar`, `EmployeeDocumentController::descargar`). Las evidencias nuevas (socioeconómico) siguen el mismo patrón (disco privado `nas`, nunca URL pública).

---

## 7. Plan de bloques

Ver `docs/CICLO_LABORAL_FINAL_IMPLEMENTADO.md` (entrega final) para el
resultado. Orden: B1 dominio/estados/jerarquía/aprobaciones → B2 reclutamiento
→ B3 contratación → B4 onboarding → B5 periodo de prueba → B6 cierre +
reingreso → B7 timeline/pendientes/notificaciones → B8 dashboard → B9 web →
B10 API → B11 suite completa → B12–B16 app móvil.

---

## 8. Riesgos conocidos

- La suite Pest completa tarda ~14 min con `xdebug.mode=off`; se corre en
  background por bloque y completa al final.
- Las plantillas jurídicas reales (contratos, aviso de no renovación,
  rescisión, finiquito, responsivas) **no** están en el repo: el sistema crea
  tarea/bloqueo explícito cuando faltan (nunca inventa texto).

---

## 9. Resultado del baseline de Pest

_Se completa al terminar la corrida inicial._
