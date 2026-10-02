# Ciclo laboral — implementación final (backend + web + API)

Última actualización: 2026-10-01. Complementa `docs/AUDITORIA_CICLO_LABORAL_FINAL.md` (diagnóstico) y reemplaza a `docs/CICLO_LABORAL_AVANCE_PENDIENTE.md` (nota de traspaso, ahora histórica).

## 1. Ciclo y etapas

Reclutamiento (1) → Contratación + expediente + contratos (2) → Onboarding (3) → Periodo de prueba (4) → Activo → Cierre (6) → Reingreso. La Etapa 5 (desempeño, Nine Box, capacitación continua, NOM-035) **no** forma parte de este cierre.

`CicloLaboralService::obtenerEstado()` es el único DTO de estado (web y app): `persona, etapa, estado, progreso, responsable_actual, siguiente_accion, bloqueos, pasos, aprobaciones, timeline, acciones_permitidas`. `ficha()` (pantalla "Ciclo laboral" y `GET /api/v1/rh/colaboradores/{id}/ciclo`) y `miProceso()` (portal "Mi proceso" y `GET /api/v1/colaborador/mi-proceso`) se construyen sobre él.

## 2. Aprobaciones

Superior operativo **preautoriza**; RH da la **autorización final** (`AprobacionService`, permisos `ciclo.preautorizar` / `ciclo.autorizar_rh`). Quien preautoriza no autoriza. RH no se puede saltar ni apagar desde Configuración. Cada aprobación guarda el snapshot del aprobador: si después cambia el jefe, el historial no cambia.

## 3. Organigrama de personas y jefes directos

- Fuente: `colaboradores.jefe_id` / `gerente_id` (ROL ≠ JEFE). Servicio: `OrganizacionJerarquiaService`.
- ~~Administración → Configuración → Jefes directos~~ — retirada el 2026-10-02: el jefe directo sale del organigrama (ver `docs/ORGANIGRAMA.md`, sección «Jefe directo = organigrama»).
- `validarSuperior()`: rechaza (422) ser su propio jefe, jefes inactivos y ciclos directos o indirectos. La misma regla protege la edición de datos laborales del expediente.
- `asignarSuperiores()` audita actor, antes, después y motivo (`jefe_directo_cambiado`).
- Sin jefe: no se elige a nadie al azar; la preautorización queda "no aplica" con motivo y el ruteo registra el receptor faltante en el log.

## 4. Ruteo de notificaciones

- `WorkflowRoutingService` resuelve destinatarios **dinámicos** por evento (`TipoDestinatarioNotificacion`: solicitante, colaborador, jefe_directo, gerente, superior_del_jefe, responsable_sucursal, regional, gerencia_rh, rh, usuarios_con_permiso, usuario_especifico, creador, evaluador, aprobador).
- Reglas por defecto versionadas en `config/configuracion_sistema.php` (`eventos`); cambios en `reglas_notificacion` desde Configuración → **Notificaciones** (auditados).
- `NotificadorRhService::notificarEvento()` entrega: sin duplicados, sin cuentas bloqueadas/inactivas, respetando alcance para permisos y usuarios específicos, con `route_rule` y `recipient_reason` en cada notificación, push por usuario (un fallo nunca revierte la acción), respaldo (`fallback`) y log cuando falta un receptor. Evento sin regla → respaldo seguro (RH con alcance) + log.
- **Notificar ≠ autorizar**: el ruteo nunca da permiso de decidir.
- Eventos conectados: solicitud_creada, solicitud_visto_bueno, candidato_preautorizado, contratos_listos, onboarding_refuerzo, evaluacion_pendiente, contrato_por_vencer, evaluacion_capturada, evaluacion_devuelta, cierre_preautorizacion, cierre_autorizacion_rh, pago_por_programar, cita_finiquito, reingreso_solicitado.

### Aviso "ya se debe renovar el contrato"

`contratos:revisar-vencimientos` (diario 06:30) abre, **una sola vez por contrato**, la evaluación del periodo de prueba `dias_aviso_vencimiento` días antes del fin (15 por defecto: un gestor con contrato de 2 meses se avisa al mes y 15 días). Avisa al evaluador (`evaluacion_pendiente`) y, por `contrato_por_vencer`, **siempre** a la gerencia de su sucursal, al gerente regional de su región (matriz comercial) y a la Gerencia de RH, además de RH con alcance. Duración del contrato por puesto: `puestos.meses_periodo_prueba` (gestores 2, gerencia/coordinación/regionales 3; el resto usa el defecto de 3, editable en Parámetros de RH).

## 5. Configuración del sistema

Administración → **Configuración** (permisos `configuracion.ver` + `configuracion.apariencia | organizacion | notificaciones | rh`; super_admin todo, rh_admin todo menos apariencia):

| Sección | Qué guarda | Dónde |
|---|---|---|
| Apariencia | 13 colores institucionales (#RRGGBB) | `configuraciones_sistema`; inyectados como `--mrl-*` en `app.blade.php`; `GET /api/v1/app/theme` y `theme` en bootstrap |
| Jefes directos | jefe/gerente por persona | `colaboradores` + auditoría |
| Notificaciones | destinatarios por evento | `reglas_notificacion` |
| Parámetros de RH | calificación mínima de onboarding, días de aviso, duración por defecto, mínimo de evaluación, causas de baja solicitables, meses por puesto, grupo indicador, vigencia documental | `configuraciones_sistema`, `puestos`, `document_types` |

`ConfiguracionSistemaService` aplica los valores guardados sobre `config()` al arrancar, así el resto del código no cambia. Todo cambio queda en la bitácora (`configuracion_actualizada`, `regla_notificacion_actualizada`, `configuracion_puesto_actualizada`, `configuracion_vigencia_documento`).

## 6. Web

- `routes/ciclo-laboral.php`: `rh.pendientes.index`, `rh.colaboradores.ciclo`, `rh.colaboradores.cierres.store`, `rh.onboarding.*`, `rh.evaluaciones.*`, `rh.cierres.*`, `rh.reingresos.*`, `rh.documentos-laborales.*` (incluye `escaneo`), `portal.mi-proceso`, `portal.onboarding.evaluacion`.
- Control del original físico: cada paso pide sus datos reales (fecha de firma con huella/testigos; paquetería, guía, fecha y comprobante del envío; fecha de recepción; escaneo antes de archivar). Fechas futuras rechazadas.
- Inicio operativo = solo el tablero de RH (8 indicadores, embudo por hito máximo, tiempo de contratación por nivel, rotación mensual; filtros `tablero_mes`, `tablero_sucursal_id` acotados al alcance).
- Sidebar operativo: Inicio, Mis pendientes, Expedientes, Solicitudes, Organigrama, Vacantes, Candidatos, Campañas, Onboarding, Reingresos, …, Configuración. Modo colaborador: Mi portal, Mi proceso, Mi expediente, Mis solicitudes.
- Todas las pantallas nuevas usan `pagina-ancha` (sin tope de ancho).

## 7. API v1 (mismos services que la web)

- Reclutamiento: `GET /rh/candidatos`, `GET /rh/candidatos/{id}`, `POST …/perfil|entrevista|psicometricas/enviar|psicometricas/resultados|psicometricas/revision|socioeconomico|referencias|referencias/concluir|preautorizar|autorizar|rechazar|devolver|descartar`.
- Ciclo: `GET /rh/tablero`, `GET /rh/ciclo/pendientes`, `GET /rh/colaboradores/{id}/ciclo`, `POST /rh/onboarding/{proceso}/activos|completar`, `POST /rh/onboarding/avances/{avance}/retroalimentacion`.
- Reingresos: `GET /rh/reingresos`, `GET /rh/reingresos/buscar`, `GET /rh/reingresos/historial/{id}`, `POST /rh/reingresos`, `POST /rh/reingresos/{id}/decidir`.
- Colaborador: `GET /colaborador/mi-proceso`, `POST /colaborador/onboarding/avances/{avance}/evaluacion`.
- Ya existían: evaluaciones, cierres completos, documentos laborales.
- Tema: `GET /app/theme` (pública). Bootstrap: `theme`, `ciclo_laboral` (estado propio + acciones permitidas por permiso), `pendientes`.
- Push: `type`, `resource_id`, `related_type`, `accion` en cada aviso del ciclo.

## 8. Scheduler (idempotente)

- `contratos:revisar-vencimientos` 06:30 — evaluación + avisos una sola vez por contrato.
- `cierres:revisar-fechas` 06:45 — pendiente "concluir cierre" para cierres pagados cuya fecha efectiva llegó (uno por cierre aunque corra N veces).

## 9. Seeders

- `PuestoJerarquiaSeeder`: `meses_periodo_prueba` y `grupo_indicador` (solo llena vacíos).
- `DocumentTypeSeeder`: vigencia de comprobante de domicilio y estado de cuenta (3 meses).
- `CicloLaboralDemoSeeder` (solo local/testing, vía `DemoSeeder`): plantillas, módulos y activos marcados DEMO; candidatos en perfil, entrevista, psicométricas, socioeconómico, referencias, preselección y esperando RH; personas en documentos, onboarding, onboarding < 8, periodo por vencer, periodo esperando RH, baja, pago programado y reingreso. Todo por services, reanudable e idempotente. Enciende los eventos de modelo que `DatabaseSeeder` apaga (`WithoutModelEvents`), porque el ciclo depende de sus observers.

## 10. Bugs corregidos en este cierre

- Migración `2026_10_01_090000`: índice único > 1000 bytes en MariaDB/MyISAM (`aprobable_type` ahora 100).
- Expediente de alta exigía "Contrato laboral" (obligatorio pero `aplica_alta = false`) antes de generar contratos: el ciclo quedaba bloqueado con el catálogo real. Ahora en Etapa 2 solo cuentan los obligatorios del alta.
- La ficha ofrecía "archivar" un original recibido sin escanear (el service lo rechazaba): ahora ofrece "escaneo".
- `ReingresoService::decidir` no exigía permiso de RH ni alcance; `solicitar` no exigía alcance.
- Pruebas obsoletas con el estado `listo_para_contratacion`; errores previos de PHPStan, ESLint y Prettier.

## 11. Despliegue

1. `php artisan migrate`
2. `php artisan people:sincronizar-permisos` (agrega `ciclo.*`, `onboarding.*`, `reingresos.*`, `configuracion.*`…)
3. `php artisan db:seed --class=PuestoJerarquiaSeeder` y `--class=DocumentTypeSeeder` (solo llenan vacíos)
4. Cargar plantillas reales (Jurídico) y módulos/activos de onboarding (RH) — sin ellos el sistema crea el pendiente "plantilla faltante" y no inventa texto.
5. Correr `php artisan organigrama:sincronizar-jefes` (el jefe directo sale del organigrama).

## 12. Pendientes externos

- Plantillas jurídicas reales y material de inducción.
- App móvil (`mr-lana-people-app`), estado 2026-10-01: `theme` (`GET
  /app/theme`), candidatos (`GET/POST /rh/candidatos/*`) y reingresos
  (`GET/POST /rh/reingresos/*`) **ya se consumen**; deep links por
  `related_type` cubren `Candidato`/`Reingreso`/`CierreLaboral`. Sigue
  pendiente migrar la pantalla "Lo que necesitas hacer" del colaborador al
  DTO unificado `GET /colaborador/mi-proceso` / `ciclo_laboral.propio` del
  bootstrap — hoy arma la misma información (sin filtrar nada sensible,
  verificado) a partir de endpoints más antiguos (`alta`, documentos
  pendientes, etc.) en vez de la fuente única nueva. Ver
  `docs/FINAL_MOBILE_AUDIT.md`.

## 13. Reglas agregadas el 2026-10-01 (tarde)

- **Mi espacio sin nombres de etapas.** La persona nunca ve «contratación», «onboarding» ni «periodo de prueba». `CicloLaboralService::misPendientes()` (web «Mis pendientes», tarjeta «Lo que necesitas hacer» de Mi portal, `GET /api/v1/colaborador/mi-proceso` y `ciclo_laboral.propio` del bootstrap) solo devuelve tareas en lenguaje llano (subir documentos, firmar, lecciones de bienvenida) y esperas. Solo se le piden los documentos que ella sube (`aplica_alta`); el contrato firmado lo escanea RH. En Mi expediente se ocultan la pestaña Onboarding y las fechas de periodo de prueba (también desde el servidor). Los avisos al colaborador usan el mismo lenguaje.
- **Nadie edita su propio expediente** (ni RH ni administración): datos personales, datos laborales y cambio del propio jefe responden 403; en Mi expediente aparece «Solicitar corrección», que abre la solicitud «Actualización de datos».
- **Solicitudes: gerente → regional → RH.** `AprobacionJerarquicaService` exige el visto bueno del gerente de la sucursal (o del jefe directo si no hay gerente) y después del regional de la región, para los tipos de `config/solicitudes.php` (`visto_bueno_jefe`: vacaciones, permisos, préstamos y permisos especiales). Con ambos, la solicitud pasa sola de «Recibida» a «Pendiente de autorizar»; sin ellos nadie (ni RH) la mueve ni la autoriza. Quien da el visto bueno no puede dar la autorización final. Visto bueno en web (`rh.solicitudes.visto-bueno`, con la «Cadena de autorización» en el detalle) y en la app (`POST /api/v1/equipo/solicitudes/{id}/visto-bueno`; `GET /api/v1/equipo/pendientes` solo lista lo que a ese usuario le toca).
- **Operación RH → Solicitudes** abre primero una pantalla por tipo (préstamos, permisos, vacaciones…) con recibidas, por autorizar, en corrección y últimos 30 días; cada tarjeta abre el tablero ya filtrado (`?tipo=`), y «Ver todas» abre el tablero completo (`?todas=1`). Columnas renombradas: «Solicitudes recibidas» y «Pendiente de autorizar».
- **Mis pendientes (RH/aprobadores)** rediseñado: encabezado con totales, filtros segmentados, tarjetas por prioridad con vencimiento, y gráficas por etapa (filtran al tocarlas) y por prioridad (`TareaService::distribucion()`).

