# Fase 1 — MR. LANA PEOPLE (Reclutamiento y Administración de Personal)

Documento maestro de la Fase 1. Para el detalle técnico de cada módulo, ver el
documento específico referenciado en cada sección.

## Módulos

| # | Módulo | Estado al iniciar esta fase | Documento |
|---|---|---|---|
| 1 | Dashboard RH | Existe (`Dashboard/Global.vue`), se amplía | `docs/REPORTES_RH.md` (métricas) |
| 2 | Multiempresa | Existe (`Empresa`, `Sucursal.empresa_id`) | `docs/MULTIEMPRESA.md` |
| 3 | Empresas / Sucursales / Departamentos / Puestos | Existe | `docs/MULTIEMPRESA.md` |
| 4 | Jerarquía de puestos | Nuevo | `docs/JERARQUIA_PUESTOS.md` |
| 5 | Perfiles de puesto | Nuevo | `docs/PERFILES_PUESTO.md` |
| 6 | Reclutamiento / prospectos / candidatos | Nuevo | `docs/RECLUTAMIENTO.md` |
| 7 | Vacantes | Nuevo | `docs/VACANTES.md` |
| 8 | Alta digital de colaborador | Nuevo | `docs/ALTA_DIGITAL_COLABORADOR.md` |
| 9 | Onboarding administrativo | Nuevo | `docs/ONBOARDING_ADMINISTRATIVO.md` |
| 10 | Expediente digital tipo carpetas | Existe, se extiende | `docs/EXPEDIENTES_DIGITALES.md` |
| 11 | Documentos en Synology NAS | Existe, se extiende | `docs/SYNOLOGY_STORAGE.md` |
| 12 | Plantillas y formatos precargados | Nuevo | `docs/PLANTILLAS_FORMATOS.md` |
| 13 | Estatus laboral, IMSS, periodo de prueba | Nuevo (campos en `users`) | `docs/EXPEDIENTES_DIGITALES.md` |
| 14 | Vacaciones | Nuevo | `docs/VACACIONES.md` |
| 15 | Permisos / incapacidades / solicitudes internas | Nuevo | `docs/SOLICITUDES_INTERNAS.md` |
| 16 | Reportes RH | Nuevo | `docs/REPORTES_RH.md` |
| 17 | Portal del colaborador (vista limitada) | Existe (`Dashboard/Colaborador.vue`), se amplía | — |
| 18 | Roles y permisos | Existe, se amplía | `docs/ROLES_PERMISOS.md` |

Capacitación y Desempeño/Nine Box **no forman parte de esta fase**. Ver `docs/ROADMAP.md`.

## Entregable funcional mínimo esperado

**RH/Admin**: iniciar sesión, ver dashboard RH, administrar empresas/sucursales/
departamentos/puestos, ver jerarquía de puestos, crear vacantes, registrar candidatos,
dar seguimiento a candidatos, adjuntar CV, aprobar candidato para alta, generar liga de
alta, revisar alta, crear colaborador, ver expediente tipo carpeta, subir documentos,
aprobar/rechazar documentos, generar formatos precargados, controlar IMSS/periodo de
prueba, revisar solicitudes, aprobar vacaciones/permisos/incapacidades, ver reportes RH.

**Colaborador**: iniciar sesión o acceder al portal limitado, ver sus datos básicos, ver
vacaciones restantes, crear solicitud, consultar estado de solicitudes, ver
notificaciones básicas.

**Candidato**: acceder con liga segura, capturar datos, subir documentos, subir foto,
enviar información.

## Fuera de alcance

Ver `docs/LIMITACIONES.md`.
