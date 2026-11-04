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

## Pendientes

### P0
Ninguno conocido.

### P1 — requieren decisión de RH/negocio (la estructura ya existe)
1. **INFONAVIT, pensión alimenticia y listas de asistencia** en Recepción documental: no hay plantilla, evento ni granularidad en el sistema; reglas registradas y apagadas.
2. **No competencia**: hoy el paquete de alta la incluye solo para Gestor y Gerente; si todos la firman, agregarla al paquete de cada grupo (Jurídico entrega el formato).
3. **Solicitud de Empleo / Estudio**: confirmar «No. de folio» (folio/CIC de la INE o clave de elector), si la enfermedad crónica se imprime con detalle, la casilla «Adobe»→Gas, mínimo de 2 referencias, versión única «Gestor 2025» para todos los puestos.
4. **Despliegue coordinado**: la app instalada no tiene los pasos de foto/solicitud; mientras no salga la versión nueva, desplegar el backend con `REGISTRO_APP_FOTO_OBLIGATORIA=false` (la solicitud solo se exige a apps que mandan `X-People-Solicitud-Empleo: 1`).
5. **Producción**: `php artisan migrate --force`, `php artisan people:sincronizar-permisos`, configurar `MAIL_*` y `SISTEMAS_ALTA_CORREO_*`, `queue:restart`.

### P2
- Alta IMSS sin seguimiento (ningún activo con acuse/fecha); sin pendiente ni KPI.
- Tablero del gerente dice «nada urgente» mientras sus listas muestran faltantes (otra fuente de datos).
- Silenciar avisos por sucursal solo existe en web (no en la app).
- La app no tiene el flujo de subir contrato firmado aunque el bootstrap lo permite.
- Director ve lista vacía de Colaboradores en la app (recomendado retirarle `rh.colaboradores.ver`).
- Gerente y gerente_sucursal con catálogos distintos en el seeder (decisión de negocio).
- Avisos duplicados ocasionales (misma aprobación dos veces con segundos de diferencia).
- Croquis capturado en la app (hoy se dibuja a mano en el formato impreso).

### P3
- En producción confirmar `APP_DEBUG=false` (respuestas locales traen stack trace).
- Volumen de avisos sin leer en roles RH (agrupar avisos de documentos pendientes).
