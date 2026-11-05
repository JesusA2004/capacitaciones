# Cierre de Fase 1 — auditoría funcional (2026-11-04)

Ronda de auditoría funcional, técnica, visual y de flujos sobre web (este repo) y app (`mr-lana-people-app`). Este documento resume qué se encontró, qué se corrigió y qué queda abierto. El detalle de cada módulo vive en su propio documento:

- `docs/RECEPCION_DOCUMENTAL_RH.md` — control mensual de originales físicos.
- `docs/SOLICITUD_EMPLEO_Y_SOCIOECONOMICO.md` — captura, completitud y generación de ambos formatos.
- `docs/FOTO_EXPEDIENTE.md` — fotografía para expediente obligatoria.
- `docs/SOLICITUDES_UNIFICADAS.md` — autoservicio de solicitudes para cualquier rol.
- `docs/FEATURE_FLAGS.md` — banderas nuevas (bloqueo por expediente incompleto).
- `docs/DEPLOY.md` — correo a Sistemas al entrar a Contratación.

## Video de inducción (app)

Probado en el emulador Android contra producción (build de desarrollo 1.3.0/23, cuenta de Sistemas): el HLS firmado carga, retoma la posición guardada, reproduce con audio/imagen, el adelanto (+15 s y arrastre) se revierte con aviso, y al llegar al final el servidor marca «Viste el video completo» (100 %). El fallo reportado ya no se reproduce con los cambios de `2f211562`/`c477ca9`. Avisos `PesReader: Unexpected start code prefix` de Media3 en logcat no afectan la reproducción.

Cambios de esta ronda: el video queda bloqueado hasta terminar la presentación (423 `presentacion_incompleta`, web y app, no retroactivo); controles propios sin «adelantar» ni velocidad; reintento automático si la URL caduca; aviso sin conexión.

## Hallazgos resueltos (P0/P1)

| # | Hallazgo | Resolución |
|---|---|---|
| P1 | Gerentes/regionales/dirección no podían pedir sus propias vacaciones, permisos ni préstamos (403 tras mostrar el botón) | Cualquier persona con colaborador vinculado crea solicitudes propias; baja conserva su permiso |
| P1 | Inicio del colaborador mostraba «0 documentos pendientes» contradiciendo su portal | `/dashboard` en modo colaborador redirige a Mi portal |
| P1 | Gerencias veían recibos de nómina interna y (por código) finiquitos con importes | Permiso propio por categoría en detalle y listado |
| P1 | Gerente de sucursal abría candidatos de otras sucursales vía `gerente_involucrado_id` | Se exige alcance vigente; reconciliación de tareas |
| P1 | Dirección comercial sin permisos de entrevista/agenda en BD (drift) | Migración idempotente + prueba BD = seeder |
| P1 | Gerente con pasos y avisos de reclutamiento que no le tocan | Solo aprueba entrevista y sube contrato firmado (migración auditada) |
| P1 | Gerencias podían cambiar la contraseña de cualquiera en su alcance (suplantación) | Solo su cadena de mando; aviso al titular y cambio obligatorio |
| P1 | Reglas de «hoy» en UTC (después de 18:00 CDMX «ya pasó») | ~20 reglas y servicios usan `FechaMexico::hoy()` |
| P1 | Bloqueo de documentos dejaba a gerentes y RH sin acceso a Gestión RH en la app | Modo operativo nunca se bloquea; Mi espacio sí |
| P1 | Al nuevo ingreso se le pedía SUBIR la Solicitud de empleo que ahora genera el sistema | Requisito cubierto por el documento generado |
| P1 | Header `Link` de 16 KB en el expediente (riesgo 502 en nginx) | Limitado a 12 recursos (~1.7 KB) |
| P1 | Foto del alta pública copiada sin normalizar | Normalización 800×800 y obligatoria |

## Ronda de cierre definitivo (2026-11-04, noche)

Se contrastó cada fila P1 de arriba con código y prueba Pest: las 12 están respaldadas (ninguna era falsa). Además se corrigió:

| Sev. | Hallazgo | Resolución | Prueba |
|---|---|---|---|
| CI | El workflow `linter` fallaba desde hace días: en CI no existen los helpers generados de Wayfinder (`@/routes`, `@/actions`, en `.gitignore`) e `import/order` los clasificaba distinto (237 errores solo en CI) | `import/internal-regex: '^@/'` en `eslint.config.js` + formato Prettier de 14 archivos | ESLint en worktree limpio sin helpers: 0 errores |
| P1 | Video de inducción: varios heartbeats seguidos acreditaban video sin que pasara tiempo (la tolerancia se sumaba en cada uno); esperar 20 min y mandar un solo heartbeat marcaba 100 % | El crédito se liga al reloj del servidor: la tolerancia acepta la posición pero no cuenta como visto, el tiempo inactivo tiene tope de 3 heartbeats y el sobrante se arrastra (sin huecos en reproducción normal) | `InduccionMaterialTest` (4 nuevas) |
| P1 | Un heartbeat directo (sin «iniciar») saltaba el bloqueo de la presentación y dejaba el video como iniciado | Heartbeat web y API exigen `exigirPresentacionParaVideo` (no retroactivo: quien ya empezó sigue) | `InduccionMaterialTest` |
| P1 | La app instalada (builds 20–22) se atoraba en «Terminar registro»: no puede marcar la Solicitud/Estudio generados y el backend rechazaba esos ids (422) — afectaba a todo nuevo ingreso | Sin cabecera `X-People-Solicitud-Empleo` se descartan los formatos generados de la lista «No tengo» (registro y vista previa de carta) | `RegistroAppApiTest`, `CartaCompromisoTest` |
| P1 | XSS almacenado: el MIME declarado por el cliente se guardaba y se servía inline (adjuntos, alta pública → expediente, actas) | `App\Support\TipoContenidoSeguro`: MIME detectado por contenido, lista blanca al servir, `nosniff` | `Seguridad/AuditoriaCierreFase1Test` |
| P1 | La «firma» del alta pública (sin sesión) podía ser HTML | Validación real PNG/JPEG + tamaño máximo; Content-Type fijo al servir | ídem |
| P1 | Gerente de sucursal abría/aprobaba altas digitales de otra sucursal por id (CURP, RFC, NSS, token de la liga) | `AltaDigitalPolicy` exige alcance (mismo criterio que el listado) | ídem |
| P1 | RH/Sistemas/administrador_capacitacion podían restablecer contraseña, editar o revocar cuentas de rol superior (super_admin, Dirección) | `RolPermisoService::esCuentaSuperior()` en `UserPolicy` (web y API) | `Permisos/MatrizRolAccionCierreTest` |
| P1 | Sistemas conservaba `nomina.recibos.descargar` + alcance «Todos» (bajaba lotes de recibos) | Migración `2026_11_13_500200` (puntual, auditada) | ídem |
| P2 | Token Sanctum seguía activo si el estatus cambiaba por un camino que no revoca (migración inicial) | Middleware `RevocarTokenCuentaInactiva` en el grupo `api` | `AuditoriaCierreFase1Test` |
| P2 | RH podía gestionar su propio préstamo / acuse IMSS; avisos de privacidad sin alcance; organigrama por URL sin `organigrama.ver` | Reglas en backend (los botones ya se ocultaban) | `MatrizRolAccionCierreTest`, `SidebarPorRolTest` |
| P2 | Avisos duplicados (doble toque/reintento aprobaba dos veces) | `lockForUpdate` + revalidación en `SolicitudesService`/`VacacionesService::cambiarEstado`; la 2.ª petición recibe 422 | `AvisoAprobacionSinDuplicadosTest` |
| P2 | Tablero del gerente «nada urgente» con faltantes en sus listas | Misma consulta y permiso que la lista (`DatosFaltantesService::conteoIncompletosVisible`) | `TableroIncompletosMismaFuenteTest` |
| P2 | Alta pública sin límite de peticiones | `throttle:60,1` | — |
| App | «Regenerar» un documento del proceso mandaba `regenerar` sin `motivo` (422) | Pide el motivo (mín. 5) | jest |
| App | «Solicitar corrección» desde Perfil quedaba bloqueado con expediente incompleto (el backend sí lo permite) | Los tipos exentos del backend pasan el candado | jest |

## Pendientes

### P0
Ninguno conocido.

### Decisiones de RH / negocio abiertas en esta ronda (no se inventó la regla)
1. **Evaluación de periodo de prueba (FO-RH-EPP-001)**: el código (commit `a0d7b81d`, 2026-10-22) hace que la conteste el **jefe inmediato** (el evaluado no puede); hubo una indicación previa de que fuera autoevaluación. Confirmar: ¿la contesta el colaborador o el jefe? ¿RH/Dirección pueden capturarla como respaldo?
2. **Repasar la inducción**: la app dice «puedes repasarla cuando quieras», pero el backend niega el contenido una vez completada o sin asignación (403). ¿Se permite el repaso (lectura) o se quita el botón?
3. **Gerente: datos sensibles** — el expediente (props web) le muestra CURP/RFC/NSS/domicilio/contactos de su alcance; `Rh/Altas/Show` le entrega el token de la liga pública (puede editar datos del candidato). ¿Qué campos debe ver un gerente?
4. **Director comercial** ve la lista de Colaboradores vacía en la app: (a) retirarle `rh.colaboradores.ver/detalle` o (b) darle alcance de región/todos.
5. **Gerente vs. gerente_sucursal** con catálogos distintos (formatos, altas, préstamos, indicadores, actas.descargar): ¿mismo puesto o roles distintos?
6. **super_admin sobre lo propio** (aprueba sus solicitudes/vacaciones) y **RH sobre lo propio** (recibir sus originales, subir su contrato firmado): ¿se mantiene la excepción?
7. **Niveles de rol**: gerente y rh_auxiliar tienen el mismo nivel (10); ¿subir `rh_auxiliar`, `gerente_regional`, `director_comercial`?
8. **Sistemas y préstamos** (`prestamos.ver_todos`, con importes): ¿se retira como recibos/finiquitos?
9. **Criterio único de «datos incompletos»** (catálogo de `DatosFaltantesService` vs. columnas de los masters) en la lista de Expedientes.

### P1 — requieren decisión de RH/negocio (la estructura ya existe)
1. **INFONAVIT, pensión alimenticia y listas de asistencia** en Recepción documental: no hay plantilla, evento ni granularidad en el sistema; reglas registradas y apagadas.
2. **No competencia**: hoy el paquete de alta la incluye solo para Gestor y Gerente; si todos la firman, agregarla al paquete de cada grupo (Jurídico entrega el formato).
3. **Solicitud de Empleo / Estudio**: confirmar «No. de folio» (folio/CIC de la INE o clave de elector), si la enfermedad crónica se imprime con detalle, la casilla «Adobe»→Gas, mínimo de 2 referencias, versión única «Gestor 2025» para todos los puestos.
4. **Despliegue coordinado** (forzado de actualización solo existe en la app y solo bloquea si hay una versión Android publicada con APK; las builds 20–23 son todas `1.3.0`, así que se fuerza por **build**):
   1. Backend con `REGISTRO_APP_FOTO_OBLIGATORIA=false` e `INCORPORACION_REGISTRO_EXIGE_SOLICITUD=cliente` (la app nueva exige foto y solicitud por sí misma).
   2. Publicar el APK nuevo (build ≥ 24) como latest con «mínima requerida» y `APP_MOBILE_MIN_ANDROID_BUILD=<build>`.
   3. Cuando las builds 20–22 dejen de conectarse (bitácora de dispositivos), pasar a `REGISTRO_APP_FOTO_OBLIGATORIA=true`. Con `true` antes de forzar, los nuevos ingresos con app vieja se atoran al final del registro (422 `errors.foto` sin paso de foto).
   4. iOS: `buildNumber` sigue en "2"; subirlo antes de cualquier build de iOS.
5. **Producción**: `php artisan migrate --force`, `php artisan people:sincronizar-permisos`, configurar `MAIL_*` y `SISTEMAS_ALTA_CORREO_*`, `queue:restart`.

### P2 (alcance nuevo o decisión; no se implementó)
- Alta IMSS sin seguimiento (ningún activo con acuse/fecha); sin pendiente ni KPI.
- Silenciar avisos por sucursal solo existe en web (no en la app).
- La app no tiene el flujo de subir contrato firmado aunque el bootstrap lo permite.
- Croquis capturado en la app (hoy se dibuja a mano en el formato impreso).
- `LoteNominaController` (impresión de lote web/API) solo revisa `nomina.recibos.descargar`, no la Policy de recibos; hoy ese permiso solo lo tiene RH tras la migración de Sistemas.
- `EjecutorMigracion` debería llamar a `AccesoCuentaService::revocar()` al marcar Inactivo (hoy lo cubre el middleware, sin bitácora de dispositivos).
- Recepción documental: `verPdf` de versiones anteriores sin filtro de alcance (hoy solo roles globales lo tienen).
- `EnsureFeatureEnabled` responde página web (200) en vez de 403 JSON en rutas API detrás de una bandera apagada.
- La URL firmada de una calidad HLS dura 600 s (`media.token_ttl`); si el reproductor cambia de calidad tras 10 min podría recibir 403 (Media3 normalmente la descarta y sigue; verificar en la prueba manual larga).
- Confirmar `trustProxies`/esquema: `url_manifiesto` debe salir `https://` detrás de nginx.

### P3
- En producción confirmar `APP_DEBUG=false` (respuestas locales traen stack trace).
- Volumen de avisos sin leer en roles RH (agrupar avisos de documentos pendientes).
- Las suites de Pest corridas en paralelo sobre la misma copia del repo se borran entre sí `storage/framework/testing/disks/nas` (`Storage::fake`) y dan fallas falsas de archivos: correr la suite completa en un worktree aparte.
- BD local: a gerente/gerente_sucursal/gerente_regional les falta `candidatos.solicitar_intervencion` (la migración `500100` se corrió en local antes de su versión final). Producción no la ha corrido, no aplica allá.
