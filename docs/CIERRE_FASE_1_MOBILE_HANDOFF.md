# Cierre Fase 1 — Handoff para la app móvil (Expo)

> Documento de traspaso. Lista los cambios de contrato API/negocio del cierre de Fase 1 (backend/web) que deben replicarse en `JesusA2004/mr-lana-people-app`. **El repo Expo NO se modificó en esta pasada** (así se acordó). Aquí quedan los endpoints, payloads, estados, permisos, features y pantallas afectadas.

Fecha de corte: cierre Fase 1. Rama backend: `cierre-definitivo` (sin merge aún).

---

## 0. Feature flags nuevos (capabilities)

El backend introdujo flags de compatibilidad (en `config/features.php`, default `false`). La app debe asumir el **flujo nuevo**; lo legacy solo existe para históricos:

| Flag | Default | Qué apaga |
|---|---|---|
| `cierre_flujo_legacy` | false | Pasos viejos de cierre (programar pago/cita/finiquito firmado/confirmar pago/autorizar finiquito). |
| `induccion_sesiones_legacy` | false | "Sesión en vivo" de inducción (presentación por RH). |
| `documentos_flujo_legacy` | false | Pasos de envío/recepción/escaneo corporativo de documentos. |

La app NO debe exponer acciones de estos flujos legacy.

---

## 1. Baja / cierre laboral / finiquito

**Regla vigente:** un jefe directo válido o RH **inicia la baja sin autorización posterior**. Ya NO existen como pasos del flujo moderno: "Autorizar baja", "Devolver baja", "Rechazar baja", "Autorizar finiquito", programar pago, cita de finiquito, confirmar pago.

- Endpoints API legacy ahora gateados tras `cierre_flujo_legacy` (devuelven error si se invocan sobre un cierre nuevo): `POST api/v1/cierres/{cierre}/pago/programar`, `/cita`, `/finiquito/firmado`, `/finiquito/pago`. **Retirar de la UI móvil.**
- La bandeja de pendientes móvil **ya NO debe listar `baja_colaborador` con acciones aprobar/rechazar** (antes era un callejón sin salida: el backend las rechazaba). La baja se gestiona en el módulo de Cierre laboral.
- **Finiquito:** el cálculo consume la fuente salarial única (`CompensacionService`); el neto se recalcula en servidor; al registrar entrega se **congela un snapshot inmutable** y el cierre queda `Pagado` (solo lectura). **ISR** es un concepto manual editable por RH/Nómina (default 0), NO se calcula automáticamente — la app no debe asumir ISR calculado.
- Fechas legales (`fecha_baja`, `fecha_efectiva`) ahora en zona `America/Mexico_City` (antes podían salir un día adelantadas).

## 2. Documentos laborales físicos (firma)

- Nuevo criterio único de "documento concluido" en backend (`FirmaDocumentalService`): un documento que requiere firma física/impresión solo cuenta como firmado cuando `firmaFisicaRegistrada()` (estado `FirmadoFisicamente`), **no** en `PendienteImpresion`/`Impreso`/`PendienteFirmaFisica`. La app no debe tratar "impreso" como "firmado".
- `FirmadoFisicamente` es estado **final**: un documento firmado físicamente ya no se puede cancelar.
- Pasos legacy de envío/recepción/escaneo corporativo gateados tras `documentos_flujo_legacy`; `registrarEnvio` solo procede desde `FirmadoFisicamente`.

## 3. Testigos (negativa de firma / firma física)

Nuevo endpoint de búsqueda de testigos por alcance:

- `GET rh/documentos-proceso/testigos` (web) y su equivalente API móvil `rh.documentos-proceso.testigos` — devuelve colaboradores ACTIVOS del alcance del actor (los del centro de trabajo del afectado primero), para elegir testigos por `colaborador_id`.
- Los payloads de **negativa de firma** y **firma física** ahora aceptan `testigos[].colaborador_id` (preferido) o `testigos[].externo` (nombre+cargo manual, solo para testigo externo). `nombre` ya no es requerido si viene `colaborador_id`.
- El backend **congela snapshot inmutable** (nombre, puesto, sucursal, actor, fecha/hora, motivo, testigos). La app debe mandar `colaborador_id`, no texto libre, para testigos internos.

## 4. Responsivas (3 familias)

Hoy el módulo de responsivas es **solo web/Inertia** (no hay controlador API v1). Si la app móvil va a consumirlas, hace falta construir el API v1 replicando `ResponsivaService`. Contrato de negocio nuevo a replicar:

- 3 familias en `TipoResponsiva`: `bien_mueble`, `equipo_electronico`, `vehiculo`. Cada una con campos legales propios en una columna JSON `datos` (bien mueble: tipo/marca/modelo/inventario/serie/color/material/estado/ubicación; electrónico: tipo/marca/modelo/serie/inventario, IMEI1/IMEI2/teléfono si celular/tablet, accesorios; vehículo: marca/submarca/modelo-año/color/NIV-VIN/motor/placas, kilometraje/combustible/exterior/interior/daños, accesorios-documentos).
- Distingue `registrado_por` (capturó en PEOPLE) vs `entregado_por` (entregó físicamente).
- Flujo: preview → generar PDF (Browsershot) → marcar impreso → marcar firmado físicamente → devolución/cancelación. Snapshot inmutable.
- **Retirado:** `POST responsivas/{id}/firmada` (subir escaneo firmado) — responde 405. El `GET` de escaneos legacy queda solo lectura. La app NO debe permitir subir escaneo en el flujo nuevo.

## 5. Inducción (flujo nuevo)

- **Retirada del camino principal** la "sesión en vivo" de inducción: `api.v1.rh.induccion.sesiones.*` queda tras `induccion_sesiones_legacy`. La inducción se **asigna** al colaborador (aviso) y la ve por su cuenta.
- Sin cambio de ruta, ahora accesibles con solo tener la inducción asignada (sin sesión): `induccion.pdf` / `presentacion` / `pagina` / `terminar`.
- **Material de explicación nuevo (audio XOR video):**
  - `GET colaborador/induccion/material/{avance}` → `{tipo: 'audio'|'video', audio_url?, video_disponible}`.
  - Video (obligatorio, anti-adelanto server-side): `POST colaborador/induccion/video/{avance}/iniciar` y `POST .../heartbeat`. Shape idéntico a `ReproduccionController` de Capacitación: `{sesion_id, posicion_inicial, segundo_maximo_permitido, porcentaje_visto, completada, url_manifiesto, heartbeat_segundos}`. El cliente no puede adelantar; "completada" la marca el servidor (≥98%); el progreso sobrevive reinicio.
  - Audio (narración continua, no bloquea): `POST colaborador/induccion/audio/{avance}/progreso` (telemetría).
- La **evaluación** se desbloquea cuando el material (si es video) está completo — ya NO por "presentada por RH".
- **Caveat móvil:** los endpoints de streaming HLS firmados viven en el área web (sesión). `url_manifiesto` apunta ahí. La integración Expo reutiliza esas URLs firmadas o requerirá un espejo bajo Sanctum (tarea futura a definir).

## 6. Solicitudes / vacaciones

- Las lecturas móviles de vacaciones se unificaron a `solicitudes_internas` (antes leían la tabla legacy `solicitudes_vacaciones` y "desaparecían"). La app debe consumir las vacaciones como un `tipo` del bucket unificado de solicitudes y aprobarlas por `/rh/solicitudes/*`.
- `/rh/pendientes` y `/rh/solicitudes` ahora devuelven el **mismo conjunto** (alcance de módulo + Policy).
- `baja_colaborador` excluida de la bandeja de pendientes móvil.
- Incorporación: los endpoints aceptan ambas familias de permiso (`rh.incorporaciones.*` **o** `rh.expedientes.incorporacion.*`).
- Adjuntar documento a solicitud: el gate es `can('view', solicitud)` (igual que web), ya no "ser dueño".
- **Pendiente de decisión:** `/api/v1/rh/vacaciones/*` (binding sobre `SolicitudVacaciones`) se dejó como lectura legacy; el bug "RH no ve vacaciones nuevas" se resolvió por las superficies vivas. Migrar este endpoint a `SolicitudInterna` implica cambio de rutas + reescribir 2 tests de contrato.

## 7. Notificaciones

- Eventos de negocio: `colaborador_alta` y `colaborador_baja` notifican a RH y **Sistemas** (para preparar/gestionar accesos/equipo), al actor y responsables necesarios — **nunca a toda la plantilla**.
- Eventos nuevos en catálogo: `expediente_completo` (→ quien genera contratos), `cierre_negativa_firma` (→ quien gestiona cierres).
- Deep links de inducción → `portal.induccion.contenido`.
- Push Expo: sin cambio de mecanismo; respetar idempotencia y destinatarios exactos (no spam).

## 8. Multimedia / NAS (operación)

- Escrituras al NAS ahora verificadas (SHA-256 + existencia): un recurso no queda `Disponible` si el archivo no se confirmó. La app debe manejar estado `Error` de recursos.
- Nginx/CORS corregidos al dominio real `people.mr-lana.com` y raíz `NAS_ROOT` (antes apuntaban al portal viejo `capacitacion.mrlana.com` / `/mnt/mrlana-capacitacion`). Reproducción desde otra sesión/equipo requiere el dominio y signed URLs correctos.
- Nuevo diagnóstico de servidor: `php artisan people:diagnostico-multimedia` (no afecta la app; útil para soporte).

---

## Pantallas móviles afectadas (resumen para Expo)

| Pantalla / módulo | Cambio |
|---|---|
| Bandeja de pendientes RH | Quitar `baja_colaborador`; vacaciones como solicitud unificada; mismo conjunto que solicitudes. |
| Detalle de baja / cierre | Quitar pasos legacy (pago/cita/firmado/confirmar); baja directa sin autorización. |
| Finiquito | Snapshot inmutable; ISR manual; fuente salarial única; fechas México. |
| Documentos físicos (firma/negativa) | Selector de testigos por `colaborador_id`; "impreso" ≠ "firmado"; snapshot. |
| Responsivas (si se implementa en móvil) | 3 familias + `datos` JSON; sin subir escaneo; requiere construir API v1. |
| Inducción | Sin sesión en vivo; material audio/video; video obligatorio anti-seek; evaluación desbloqueada por material. |
| Vacaciones | Consumir `solicitudes_internas`; aprobar por `/rh/solicitudes`. |
| Notificaciones | Destinatarios exactos (Sistemas en alta/baja); eventos nuevos. |

## Pendientes a definir con el negocio antes de replicar en móvil
1. ¿Construir API v1 de responsivas para móvil o dejarlas solo web?
2. ¿Migrar `/api/v1/rh/vacaciones/*` a `SolicitudInterna` (rompe 2 tests de contrato) o dejar legacy?
3. Espejo Sanctum de los endpoints de streaming HLS de inducción para la app.
