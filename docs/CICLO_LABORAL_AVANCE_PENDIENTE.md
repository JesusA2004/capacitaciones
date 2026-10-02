> **HISTÓRICO** — nota de traspaso previa al commit 6d521ba. El estado vigente está en [CICLO_LABORAL_FINAL_IMPLEMENTADO.md](CICLO_LABORAL_FINAL_IMPLEMENTADO.md).

# Cierre del ciclo laboral — estado de avance (traspaso)

Última actualización: 2026-10-01. Nada está commiteado todavía (todo en el working tree de `main`).

## Hecho (backend)

- **Auditoría**: `docs/AUDITORIA_CICLO_LABORAL_FINAL.md` (baseline: 847 pruebas verdes, PHPStan 0, Pint/ESLint/vue-tsc/build OK). Falta llenar su §9 con ese resultado.
- **Migraciones nuevas**: `2026_10_01_090000_create_ciclo_laboral_final_tables.php` (aprobaciones, registros de candidato, onboarding, activos, reingresos, columnas de puestos/sucursales/document_types/cierres/tareas) y `2026_10_01_090100_consolidar_estados_candidato.php` (mapeo de estados viejos + `etapa_maxima`). **No se han corrido en la BD local de desarrollo** (solo en tests SQLite).
- **Enums**: `EtapaCicloLaboral`, `EtapaAprobacion`, `EstadoAprobacion`, `ProcesoAprobacion`, `EstadoOnboarding`, `EstadoAvanceOnboarding`, `TipoModuloOnboarding`, `EstadoEntregaActivo`, `EstadoReingreso`, `ResultadoEtapaCandidato`, `ResultadoReferencia`, `TipoEvidenciaCandidato`, `GrupoPuestoIndicador`; reescritos `EstadoCandidato`, `EstadoCierreLaboral`, `EstadoAltaColaborador`, `TipoTarea`; extendidos `TipoBaja`, `FuenteCandidato`, `EstadoFlujoDocumento`.
- **Services nuevos**: `CicloLaboral/{OrganizacionJerarquiaService, AprobacionService, CicloLaboralService, TimelineService, ReingresoService}`, `Reclutamiento/{CandidatoWorkflowService, CandidatoPresenter}`, `Onboarding/{OnboardingService (nuevo), OnboardingCatalogoService}` (el checklist viejo se renombró a `ChecklistAdministrativoService`), `Colaboradores/IdentidadColaboradorService`, `Reportes/TableroRhService`.
- **Services reescritos/extendidos**: `ContratacionCandidatoService` (solo con autorización RH, crea colaborador + QR ligado), `AltaColaboradorService` (contratos al completar expediente → onboarding → activación), `ContratoLaboralService` (paquete generado/firmado, vencimiento por puesto, guard de reentrancia), `EvaluacionPeriodoPruebaService` (jefe = preautorización, RH autoriza), `CierreLaboralService` (solicitar → preautorizar → RH → finiquito autorizado → pago programado → firma/pago → cerrar), `IncorporacionInvitacionService` (QR solo a candidato autorizado/colaborador existente; registro reutiliza al colaborador), `IncorporacionService::aprobarIncorporacion` (= expediente aprobado, ya no activa), `TareaService` (candidato/sucursal/etapa/urgencia), `AuditoriaService` (agrega colaborador_id/candidato_id para la timeline).
- **Permisos** nuevos en `RolesYPermisosSeeder` (ciclo.preautorizar, ciclo.autorizar_rh, candidatos.evaluar, onboarding.*, cierres.solicitar, cierres.programar_pago, reingresos.*).
- **Pruebas**: `tests/Feature/CicloLaboral/CicloLaboralFinalTest.php` — E2E + escenarios A–J: **13/13 verdes**. Se actualizaron AltaColaboradorTest, CierreLaboralFiniquitoTest, ContratacionCandidatoTest, CandidatoTest, AltaDigitalTest, CandidatoAltaQrFlujoTest, IncorporacionInvitacionTest, IncorporacionInvitacionApiTest, IncorporacionQrTest, ActasEstructuraTareasTest; `IncorporacionInvitacionFactory` y helper `clColaboradorEnContratacion()` en `tests/Pest.php`. Re-corrida de CicloLaboral + Rh + Api + Onboarding + IncorporacionQr + DatabaseSeederProduccion: **361/361 verdes** (falta la suite completa).
- **API**: `Api\V1\Rh\CierreLaboralController` reescrito + rutas nuevas de cierre en `routes/api.php`.
- **Web (Inertia)**: rutas del workflow de candidatos en `routes/rh.php`; `Rh\CandidatoController` reescrito; controladores nuevos SIN rutas aún: `Rh\PendienteController`, `Rh\CicloColaboradorController`, `Rh\OnboardingController`, `Rh\EvaluacionPeriodoPruebaController`, `Rh\CierreLaboralController`.
- **Vue**: componentes `components/ciclo/*`, `components/Dashboard/TableroRh.vue`; páginas `Rh/Candidatos/Show.vue` (reescrita), `Rh/Candidatos/Index.vue` (kanban solo cierra con motivo), `Rh/Pendientes/Index.vue`, `Rh/Colaboradores/Ciclo.vue`, `Rh/Reingresos/Index.vue`, `Rh/Onboarding/Configuracion.vue`, `Portal/MiProceso.vue`; tipos `types/cicloLaboral.ts`; tokens de paleta `--mrl-*` en `app.css`.

## Siguiente (en este orden)

1. (Hecho: suites afectadas 361/361.)
2. Crear `Rh\ReingresoController`, `Rh\DocumentoLaboralController` (web: descargar/imprimir/firma-fisica/envio/recepcion/archivar), `Portal\MiProcesoController`; archivo `routes/ciclo-laboral.php` (requerirlo desde `routes/web.php`) con nombres que ya usan las páginas Vue: `rh.pendientes.index`, `rh.colaboradores.ciclo`, `rh.colaboradores.cierres.store`, `rh.onboarding.{activos,completar,retroalimentar,configuracion,modulos.store/update,activos.catalogo.store/update}`, `rh.evaluaciones.{capturar,autorizar,devolver}`, `rh.cierres.{preautorizar,autorizar,rechazar,devolver,aviso,calcularFiniquito,autorizarFiniquito,programarPago,cita,finiquitoFirmado,confirmarPago,cerrar,cancelar}`, `rh.reingresos.{index,store,decidir}`, `rh.documentos-laborales.{descargar,imprimir,firmaFisica,envio,recepcion,archivar}`, `portal.onboarding.evaluacion`, `portal.mi-proceso`. Envío físico: pedir paquetería/guía en la UI.
3. `DashboardController`: pasar prop `tablero` (TableroRhService, filtros `tablero_mes`, `tablero_sucursal_id`); `IndicadoresRhService` → embudo por hitos.
4. Sidebar (`AppSidebar.vue`/NavigationService): Mis pendientes, Reingresos, Onboarding; "Mi proceso" en modo colaborador.
5. API v1: candidatos (index/show/acciones), onboarding (propio y RH), reingresos, `GET /rh/pendientes` sobre TareaService, `GET /rh/tablero`, estado del ciclo por persona, bootstrap con `ciclo_laboral`; mismos services.
6. Scheduler: `cierres:revisar-fechas` (CierreLaboralService::revisarFechasEfectivas) en `routes/console.php`.
7. Seeders: `CicloLaboralDemoSeeder` (etapas 2–6 vía services), `meses_periodo_prueba`/`grupo_indicador` en `PuestoJerarquiaSeeder`, tipos de activo y módulos demo, `vigencia_meses` en DocumentTypeSeeder.
8. `php artisan wayfinder:generate --with-form`; correr types/lint/build/PHPStan/Pint y suite completa (xdebug off).
9. `docs/CICLO_LABORAL_FINAL_IMPLEMENTADO.md`.
10. Después: app `mr-lana-people-app` (bloques 12–16).

## Lecciones de esta sesión

- En PHP el ÚLTIMO operando de `??` no tiene semántica isset: `?? $x->y` truena si `$x` es null (rompió la apertura de todas las tareas).
- No crear archivos `config/*.php` que referencien enum cases aún inexistentes mientras corre Pest.
- Para editar PHP con `\` usar Edit o `String.fromCharCode(92)` en node.
