# Backend RH completo — ciclo laboral de punta a punta

Documento técnico del cierre del backend + API v1 de MR. LANA PEOPLE (septiembre 2026).
Describe qué se reutilizó, qué se completó y cómo fluye un colaborador desde que es
candidato hasta que su expediente se cierra. La app móvil y las pantallas Inertia de los
módulos nuevos quedan fuera de este alcance: consumirán exactamente estos servicios.

Reglas que se respetaron en todo el trabajo (ver `CLAUDE.md`):

- La lógica vive en `app/Services/*`; los controladores API solo autorizan (Policy),
  validan (FormRequest) y delegan.
- La autorización real es backend: permiso Spatie **+** alcance organizacional
  (`AlcanceOrganizacionalService::alcanzaColaborador()`), nunca solo UI.
- Ningún archivo se guarda en BD: todo va al disco `nas` (Synology) con metadatos.
- Nunca se borra un colaborador; la baja bloquea acceso y conserva historial.
- El sistema **no redacta cláusulas**: los textos jurídicos los carga RH/Jurídico como
  plantillas; si falta una plantilla, el documento queda como pendiente explícito.
- No hay integración con NOI, TIME WORK ni timbrado. El recibo semanal es interno y no fiscal.

---

## 1. Diagnóstico inicial (auditoría)

| Área | Estado encontrado | Acción |
|---|---|---|
| Separación `User` (cuenta) / `Colaborador` (persona) | En curso | Reutilizada; todo lo nuevo es Colaborador-first |
| Alta digital (liga pública) + `ConversionColaboradorService` | Completo | Reutilizado; ahora enlaza candidato↔colaborador↔vacante |
| Registro por QR (`IncorporacionInvitacionService::registrarUsuario`) | **Roto**: escribía datos personales en `users` (no asignables) y nunca creaba el `Colaborador` | Corregido: crea Colaborador + cuenta |
| Expediente (`EmployeeDocument`, `DocumentType`, versiones, revisión) | Completo, sin categoría ni "completo" real | Mejorado: categorías, carpeta por categoría, `estadoDocumental()` |
| Storage Synology (disco `nas`, montaje Tailscale, descargas por policy) | Completo | Reutilizado; `EXPEDIENTES_DISK` configurable |
| Plantillas DOCX (`DocumentTemplate`) + formatos oficiales PDF overlay | Existentes, sin versión/clave ni flujo | Reutilizados como motores del nuevo **motor documental** |
| `GeneratedDocument` | Solo generado/entregado/firmado | Ampliado: snapshot, checksum, flujo de firmas y original físico |
| Periodo de prueba | Solo 2 fechas en colaborador, sin control | Nuevo: `ContratoLaboral`, scheduler, evaluación, renovación |
| Baja (`SolicitudInterna` tipo baja + `BajaColaboradorService`) | Completo | Reutilizado dentro del nuevo **cierre laboral** |
| Finiquito (`FiniquitoService`) | Cálculo automático + ajustes | Mejorado: conceptos percepción/deducción, totales, pago, PDF al expediente |
| Recibo de nómina (`ReciboNominaService`) | Simple, JSON, sin detalle | Completado: semanal, detalle, folio, leyenda NO FISCAL, importación CSV/XLSX, API |
| Préstamos (`PrestamoService`) | Se creaba al aprobar la solicitud | Completado: visto bueno del jefe, monto/plazo autorizados, contrato + pagaré, resguardo |
| Vacaciones/permisos | Completos (solicitudes unificadas + formato oficial) | Integrados al flujo documental: comprobante PDF en expediente + tareas |
| Actas | No existían | Nuevo módulo |
| Headcount/vacantes | Autorizada vs actual por sucursal | Mejorado: cobertura por (sucursal, puesto), excedentes, cierre de vacante con contratado |
| Indicadores | Dashboards parciales | Nuevo `IndicadoresRhService` (todo en SQL/agregados) |
| Jerarquía de personas | Solo `jefe_id` | Nuevo `gerente_id` + `JerarquiaColaboradorService` + API |
| Bandeja de pendientes | Vista calculada (4 tipos) + notificaciones sin objeto | Nueva tabla `tareas_rh` con objeto relacionado y deduplicación |
| API v1 | Sin rate limiting (ni en login) | `throttle:api`, `throttle:api-login`, `throttle:api-cargas` |
| Suite de pruebas | 590 pruebas; 101 no pasaban (deuda User→Colaborador en las pruebas) | +34 pruebas nuevas; 23 pruebas legacy corregidas; 0 regresiones. Resultado final: 624 pruebas, 543 pasan, 78 fallas preexistentes (Vacantes, Cumpleaños, MovimientosLaborales, Candidatos, MatrizComercial...), 3 omitidas por 2FA |

---

## 2. Modelo de dominio nuevo

```
Candidato ──contratar──▶ Colaborador ──1:N──▶ ContratoLaboral ──1:1──▶ EvaluacionPeriodoPrueba
    │ colaborador_id        │ jefe_id, gerente_id        │ generated_document_id          │
    ▼                       │ estado_alta, tipo_contratacion                              ▼
 Vacante (candidato/colaborador_contratado, fecha_cierre)                        CierreLaboral ──▶ SolicitudInterna (baja)
                            │                                                              └──▶ FiniquitoCalculo ──1:N──▶ FiniquitoConcepto
                            ├──1:N──▶ EmployeeDocument (expediente, por categoría)
                            ├──1:N──▶ GeneratedDocument (motor documental) ──1:1──▶ SeguimientoDocumentoFisico
                            │                                               └──1:N──▶ DocumentoEvento (bitácora)
                            ├──1:N──▶ ReciboNomina ──1:N──▶ ReciboNominaConcepto
                            ├──1:N──▶ Prestamo (contrato_documento_id, pagare_documento_id)
                            ├──1:N──▶ ActaAdministrativa ──1:N──▶ ActaAnexo
                            └──1:N──▶ TareaRh (relacionado_type/id)
SolicitudInterna ──1:N──▶ SolicitudAprobacion (visto bueno jerárquico)
```

### Enums nuevos (`app/Enums`)

`TipoContratacion`, `EstadoAltaColaborador`, `CategoriaDocumento`, `EstadoFlujoDocumento`,
`MotorPlantilla`, `EstadoContratoLaboral`, `EstadoEvaluacionPrueba`, `ResultadoEvaluacion`,
`EstadoCierreLaboral`, `TipoActa`, `EstadoActa`, `TipoTarea`, `PrioridadTarea`,
`TipoConceptoNomina`. Ampliados: `TipoBaja::NoRenovacion`, `EstadoFiniquito::Pagado`,
`EstadoUsuario::valoresVigentes()`.

---

## 3. Flujos

### 3.1 Alta del colaborador (`AltaColaboradorService`)

Entrada: alta manual (`POST /api/v1/rh/colaboradores`) o candidato contratado
(`POST /api/v1/rh/candidatos/{candidato}/contratar`, `ContratacionCandidatoService`).

En **una sola transacción**:

1. Colaborador con empresa (vía sucursal), sucursal, departamento, puesto, jefe inmediato,
   gerente, sueldo, fecha de ingreso, tipo de contratación; número de empleado `EMP-0000`
   automático si no se indica. `estatus = en_incorporacion`, `estado_alta = pendiente_documentos`.
2. Carpeta del expediente fijada en el NAS (`expediente_storage_path`).
3. `ContratoLaboral` vigente con snapshot de sueldo/puesto/sucursal; sincroniza
   `periodo_prueba_inicio/fin`.
4. Cuenta de acceso (rol `colaborador`) si hay correo — puede entrar a la app para subir
   documentos y firmar (el estatus `en_incorporacion` ya permitía login).
5. Movimiento laboral de alta + sincronización de vacante automática.
6. (Candidato) candidato → `contratado` con `colaborador_id`, seguimiento, plaza ocupada.

Después del commit (nunca revierten el alta): documentos contractuales del paquete
(`config/contratos.php → paquetes_alta`), tareas "expediente incompleto" (RH y colaborador),
correo de establecimiento de contraseña.

No duplicación: CURP/RFC/NSS ya existentes (incluso de bajas) bloquean el alta con mensaje
de reingreso; un candidato solo se convierte una vez (bloqueo de fila).

**Estado del alta** (`recalcularEstado`, disparado por `CicloLaboralDocumentoObserver` en
cualquier cambio de documento, venga de web, API o flujo):

```
pendiente_documentos → documentacion_en_revision → pendiente_contrato → pendiente_firma
   → pendiente_activacion ──(POST .../activar, RH)──▶ activo        … baja (cierre laboral)
```

Activar exige obligatorios **aprobados** y contrato principal **firmado**; deja
`estatus = activo`, `activado_en` y resuelve tareas.

> Plantilla activa: se cuentan colaboradores **vigentes** (`activo` + `en_incorporacion`).
> Un contratado en alta ya ocupa su plaza; si no, la vacante automática se reabriría al
> contratar.

### 3.2 Expediente digital

`ExpedienteService::estadoDocumental()` → `requeridos, entregados, aprobados, en_revision,
rechazados, faltantes, porcentaje (aprobados/requeridos), completo` + detalle por documento
(tipo, obligatorio, estado, versión, cargó, validó, fecha de validación, motivo de rechazo,
observaciones). **Completo = todos los obligatorios aprobados**, no solo cargados.

Metadatos por documento (tabla `employee_documents`): `colaborador_id, document_type_id
(categoría en document_types.categoria), original_name, stored_name, disk, path, mime,
extension, size, hash (checksum SHA-256), version, previous_version_id, status,
uploaded_by, reviewed_by/reviewed_at, comments, rejection_reason, origen, timestamps`.

### 3.3 Storage Synology

Disco `nas` (`config/filesystems.php`): `NAS_DRIVER=local` + `NAS_ROOT` montado desde el
Synology (producción: Tailscale → `/mnt/people-storage`), o `sftp`. En desarrollo:
`storage/app/private/capacitacion`. Estructura nueva:

```
expedientes/{EMPRESA}/{SUCURSAL}/{NUM - NOMBRE}/
    Personales/  Contratos/  Vacaciones/  Permisos/  Prestamos/  Actas/  NominaInterna/  BajaFiniquito/  Otros/
```

Los documentos ya existentes conservan su ruta (nombre físico inmutable);
`php artisan expedientes:organizar-nas` los migra al layout nuevo con verificación de hash
y rollback. Nada se sirve con URL pública: toda descarga pasa por un endpoint con Policy
(`MotorDocumentalService::respuesta()`, `ReciboNominaService::respuestaPdf()`, ...).

### 3.4 Motor documental (`MotorDocumentalService`)

```
DocumentTemplate (clave + versión, motor html|docx|pdf_overlay, categoría, tipo documental,
                  requiere_firma_digital/impresion/firma_fisica/huella/testigos)
  + PlaceholderResolver (colaborador, empresa, contrato, préstamo, cierre, acta...)
  → PDF → carpeta de su categoría en el expediente
  → GeneratedDocument (payload = SNAPSHOT, checksum, clave/versión, banderas, flujo)
```

- Versionado: `POST /api/v1/rh/plantillas-documentales` publica la versión N+1 y desactiva
  las anteriores. Los documentos emitidos conservan su versión y su snapshot: si el sueldo
  cambia de $15,000 a $18,000, el contrato histórico sigue diciendo $15,000.
- `GET .../plantillas-documentales/variables` lista las variables `{{...}}` disponibles.
- Sin plantilla activa: `422` con aviso; en flujos automáticos se abre la tarea
  "documento contractual pendiente".
- `registrarPdf()` permite a módulos con vista propia no jurídica (recibo interno,
  comprobantes, respaldo de finiquito) usar el mismo almacenamiento/snapshot/flujo.

Claves conocidas (`config/contratos.php → plantillas`): `contrato_periodo_prueba`,
`contrato_capacitacion`, `contrato_tiempo_determinado`, `contrato_confidencialidad`,
`contrato_no_competencia`, `contrato_indeterminado`, `pagare`, `contrato_prestamo`,
`aviso_termino`, `finiquito`, `comprobante_vacaciones`, `comprobante_permiso`,
`acta_administrativa`, `acta_hechos`, `carta_responsiva`, `acta_auditoria`.

### 3.5 Flujo documental, firmas y original físico (`FlujoDocumentalService`)

```
generado → pendiente_firma_colaborador → firmado_digitalmente          (aceptación digital)
         → pendiente_impresion → impreso → pendiente_firma_fisica      (original físico)
         → firmado_fisicamente → enviado_corporativo → recibido_corporativo
         → escaneado → archivado                      (cancelado en cualquier etapa no final)
```

- Los pasos que aplican dependen de las banderas copiadas de la plantilla.
- Firma digital: solo el titular; registra fecha, IP, user agent y hash del PDF aceptado.
- Firma física: exige huella/testigos cuando la plantilla lo pide.
- Original físico (`seguimientos_documento_fisico`): impresión (quién/cuándo), firma física,
  sucursal origen, envío (paquetería, guía, comprobante opcional, quién), recepción
  (quién/cuándo), escaneo (el escaneo se archiva en el expediente como `EmployeeDocument`
  aprobado del tipo documental de la plantilla).
- Cada transición: `lockForUpdate`, `DocumentoEvento` (quién, acción, estados, IP, user
  agent, observaciones), auditoría general y tareas (resuelve la etapa anterior, abre la siguiente).
- Consultas para RH: `GET /api/v1/rh/documentos-laborales/pendientes` (conteos
  imprimir / firma_colaborador / firma_fisica / enviar / recibir / escanear) y
  `GET /api/v1/rh/documentos-laborales?etapa=...`.

### 3.6 Vencimiento, evaluación y renovación

- `php artisan contratos:revisar-vencimientos` (scheduler diario 06:30 CDMX,
  `withoutOverlapping()->onOneServer()`): contratos vigentes que vencen en ≤
  `CONTRATOS_DIAS_AVISO_VENCIMIENTO` (15) días → evaluación (índice único por contrato),
  tareas "contrato por vencer" (RH) y "evaluación pendiente" (jefe), notificaciones y
  `aviso_vencimiento_en`. Idempotente: correrlo N veces no duplica nada.
- Evaluación (`EvaluacionPeriodoPruebaService`): el jefe inmediato/gerente captura criterios
  (0–10), promedio, resultado, recomendación; RH/Dirección autoriza o devuelve.
  - Renovar → `ContratoLaboralService::renovar()`: contrato anterior `renovado`, nuevo
    contrato indeterminado + documento `contrato_indeterminado` al flujo de firmas.
  - No renovar → `CierreLaboralService::iniciar()` con tipo `no_renovacion`.

### 3.7 Cierre laboral y finiquito

```
POST .../colaboradores/{id}/cierres           iniciar (crea solicitud de baja en revisión)
POST .../cierres/{id}/aviso | aviso/generar    renuncia o aviso de término (evidencia)
POST .../cierres/{id}/finiquito/calcular       cálculo automático existente
POST|PATCH|DELETE .../finiquito/conceptos      conceptos percepción/deducción (cantidad, importe, observaciones)
POST .../finiquito/revisar                     revisión RH
POST .../finiquito/documento                   PDF vía motor (plantilla "finiquito" → formato oficial → respaldo interno)
POST .../finiquito/firmado                     finiquito firmado (también al expediente)
POST .../finiquito/pago                        confirmación administrativa del pago (referencia)
POST .../cierres/{id}/ejecutar-baja            aprueba la baja: inactivo, tokens/dispositivos revocados, contrato terminado
POST .../cierres/{id}/cerrar-expediente        expediente cerrado (el colaborador NUNCA se elimina)
```

Totales: `total_percepciones`, `total_deducciones`, `neto` (y `total_ajustado = neto` por
compatibilidad). Snapshot con el desglose completo al generar el documento. Firmado o
pagado ⇒ inmutable. Exigir firma/pago antes de la baja: `config/contratos.php → cierre`.

### 3.8 Recibo interno de nómina semanal

`ReciboNominaService` + `ReciboNominaImportService`. NO es CFDI, no se timbra, no calcula
ISR/IMSS, no se integra con NOI. Detalle en `recibo_nomina_conceptos`; folio `RIN-000001`;
semana ISO y ejercicio; un recibo por colaborador y periodo (bloqueo + validación); PDF con
leyenda **"RECIBO INTERNO DE NÓMINA - NO FISCAL"** en `NominaInterna/`.
Importación CSV/XLSX (formato largo: `numero_empleado, tipo, concepto, importe, cantidad?,
observaciones?`) con `simular`, reporte por fila y lote auditable; un colaborador con alguna
fila inválida no recibe recibo incompleto.

### 3.9 Préstamos personales

```
colaborador solicita (POST /api/v1/solicitudes tipo "prestamo")
→ jefe inmediato: POST /api/v1/equipo/solicitudes/{id}/visto-bueno (aprobado sí/no)
→ RH/Dirección: POST /api/v1/rh/solicitudes/{id}/prestamo/autorizar (monto/plazo autorizados) | .../rechazar
→ contrato_prestamo + pagare (motor documental, snapshot con montos AUTORIZADOS) → firma
→ POST /api/v1/rh/prestamos/{id}/resguardar (exige ambos firmados)
```

Tipos que requieren visto bueno: `config/solicitudes.php → visto_bueno_jefe`. El saldo es
informativo; el sistema no ejecuta descuentos.

### 3.10 Vacaciones y permisos

Sin rehacer el módulo: al aprobar, `ComprobanteSolicitudService` genera el comprobante PDF
(plantilla `comprobante_*` si existe, si no un comprobante interno de datos) archivado en
`Vacaciones/` o `Permisos/`; tareas de pendiente al crear y resueltas al cerrar. Los
cálculos de saldo siguen en `VacacionesService`.

### 3.11 Actas

`ActaService`: acta administrativa, de hechos, carta responsiva, acta de auditoría — fecha,
hora, lugar, hechos, responsable, testigos, declaraciones, anexos (en `Actas/`), negativa a
firmar, seguimiento, estatus (borrador → generada → firmada/cerrada); formato por plantilla
del tipo. El implicado no puede consultarlas.

### 3.12 Plantilla autorizada vs activa y vacantes

`HeadcountService::coberturaDetallada()` por (empresa, sucursal, puesto): autorizados
(headcount_targets), activos (calculado, nunca capturado), vacantes, excedentes y cobertura.
Vacante: `candidato_contratado_id`, `colaborador_contratado_id`, `fecha_cierre`,
`diasAbierta()`; la vacante automática se cierra como **cubierta** si se ocupó por
contratación (o cancelada si el faltante desapareció por otra vía).

### 3.13 Indicadores

`GET /api/v1/rh/indicadores?desde&hasta&sucursal_id&empresa_id&dias_vencimiento` →
plantilla activa/autorizada, cobertura, vacantes, excedentes, altas/bajas del periodo,
rotación, permanencia promedio (activos y bajas), contratos por vencer, tiempo de
contratación (vacantes y candidatos), inversión de reclutamiento, costo por contratación,
embudo de candidatos. Definiciones en el docblock de `IndicadoresRhService`.

### 3.14 Organigrama y jerarquía

`JerarquiaColaboradorService`: jefe inmediato, gerente (explícito o jefe del jefe),
subordinados directos, `esSuperiorDe()`, árbol por sucursal/departamento en una sola
consulta. Las aprobaciones (visto bueno, evaluación) usan esta estructura real.

### 3.15 Bandeja de trabajo

`tareas_rh`: tipo, título, prioridad, `relacionado_type/relacionado_id`, colaborador,
destinatario (cuenta o permiso + alcance), acción, vence_en, `read_at`, `resuelta_en`.
`clave_abierta` única ⇒ sin duplicados aunque el scheduler/jobs corran varias veces.
Notificaciones nuevas (`PendienteRhNotification`) incluyen `related_type`, `related_id`,
`accion` y `prioridad`.

---

## 4. Seguridad

- **Policies nuevas**: `ColaboradorPolicy`, `GeneratedDocumentPolicy`, `ContratoLaboralPolicy`,
  `EvaluacionPeriodoPruebaPolicy`, `CierreLaboralPolicy`, `ReciboNominaPolicy`,
  `PrestamoPolicy`, `ActaAdministrativaPolicy`, `TareaRhPolicy`. Todas combinan permiso +
  alcance y prohíben operar sobre uno mismo.
- **IDOR**: autoservicio siempre desde la sesión; recursos por id siempre con Policy
  (probado: otro colaborador recibe 403 en recibos, documentos, préstamos).
- **Roles nuevos**: `direccion` (autorizaciones e indicadores), `juridico` (documentación
  jurídica y plantillas), `sistemas` (plataforma, sin expedientes ni nómina).
- **Rate limiting**: `throttle:api` (120/min por cuenta), `throttle:api-login` (10/min por
  correo+IP), `throttle:api-cargas` (30/min) en cargas/importaciones.
- **Archivos**: MIME y tamaño restringidos (`config/contratos.php`), nombres saneados
  (`sanitizarSegmento`), nunca se usa el nombre original como ruta, nunca se sobrescribe,
  `X-Content-Type-Options: nosniff` en descargas.
- **Concurrencia**: `lockForUpdate` en transiciones de documentos, evaluaciones, cierre,
  préstamos, finiquito, conversión de candidato; índices únicos en evaluación por contrato,
  decisión por nivel y tarea abierta.
- **Auditoría** (`AuditoriaService`, log `rh` de activity_log con IP/user agent, sin
  contraseñas/tokens/rutas): alta, activación, contrato creado/renovado/terminado,
  documento generado y cada transición, evaluación, cierre (cada paso), finiquito,
  recibos (individual e importación), préstamo (autorización, rechazo, resguardo), actas,
  plantillas. `Colaborador` ahora audita también jefe, gerente, sueldo, fechas y estado del alta.

---

## 5. Referencia rápida

### Migraciones creadas

| Migración | Contenido |
|---|---|
| `2026_09_22_090000_add_ciclo_laboral_a_colaboradores_table` | colaboradores: gerente, tipo_contratacion, estado_alta, candidato_id, activación, baja, cierre de expediente · candidatos: colaborador_id, contratado_en · vacantes: contratado y fecha_cierre |
| `2026_09_22_090100_add_motor_documental_a_plantillas_y_documentos` | document_types.categoria · employee_documents.origen · document_templates (clave/versión, motor, banderas) · generated_documents (snapshot, flujo, firma digital) · seguimientos_documento_fisico · documento_eventos |
| `2026_09_22_090200_create_ciclo_contractual_tables` | contratos_laborales · evaluaciones_periodo_prueba · cierres_laborales |
| `2026_09_22_090300_add_conceptos_a_finiquitos_recibos_y_prestamos` | finiquito (totales, pago) + finiquito_conceptos · recibos_nomina (folio, periodo) + recibo_nomina_conceptos · prestamos (solicitado/autorizado, documentos, resguardo) · solicitud_aprobaciones |
| `2026_09_22_090400_create_actas_y_tareas_tables` | actas_administrativas · acta_anexos · tareas_rh |

Todas son aditivas (columnas nullable o con default) y tienen `down()`.

### Variables `.env` nuevas (todas opcionales)

```env
EXPEDIENTES_DISK=nas                       # disco del expediente (default nas)
CONTRATOS_DIAS_AVISO_VENCIMIENTO=15        # días de anticipación del aviso de vencimiento
CONTRATOS_CALIFICACION_MINIMA=7            # mínimo aprobatorio sugerido (0-10)
DOCUMENTOS_LABORALES_MAX_UPLOAD_MB=20      # escaneos, avisos, anexos, comprobantes
```

### Configuración nueva

`config/contratos.php` (plantillas, paquetes de alta/renovación/préstamo, criterios,
reglas de cierre, límites de archivos) · `config/solicitudes.php → visto_bueno_jefe`.

### Pasos de despliegue

1. `git pull` y `composer install --no-dev --optimize-autoloader`.
2. `php artisan migrate --force` (aditivas).
3. `php artisan db:seed --class=RolesYPermisosSeeder --force` (permisos y roles nuevos).
4. `php artisan route:cache && php artisan config:cache && php artisan view:cache`.
5. Verificar cron del scheduler (`* * * * * php artisan schedule:run`) y el worker de colas
   (notificaciones `ShouldQueue`).
6. Opcional: `php artisan expedientes:organizar-nas` (dry run) para ver qué documentos
   anteriores se moverían a subcarpetas por categoría; aplicar con `--apply --confirm`.
7. RH/Jurídico carga las plantillas reales (`POST /api/v1/rh/plantillas-documentales`)
   para cada clave del catálogo (`GET .../plantillas-documentales` indica cuáles faltan).
8. Asignar roles `direccion`/`juridico`/`sistemas` a las cuentas que correspondan.

### Pendiente fuera de este alcance

- Pantallas Inertia (web) para los módulos nuevos y la app móvil: consumen la API v1 descrita.
- Textos jurídicos reales: los provee RH/Jurídico; el motor ya los soporta.

---

## 6. Endpoints API v1

Todos bajo `/api/v1`, `auth:sanctum` + `throttle:api`. Respuestas `{ data, meta? }`.

### Nuevos — colaborador (autoservicio)

| Método | Ruta | Uso |
|---|---|---|
| GET | `colaborador/alta` | Checklist del alta propia |
| GET | `colaborador/expediente` | Estado documental + estado del alta |
| GET | `colaborador/documentos-pendientes` | Por cargar (obligatorios) y por firmar |
| GET | `colaborador/documentos-laborales` | Contratos/documentos propios (`?estado=pendientes_firma`) |
| GET | `colaborador/documentos-laborales/{documento}/descargar` | Descarga autorizada (PDF) |
| POST | `colaborador/documentos-laborales/{documento}/firmar` | Aceptación/firma digital (`acepto=true`) |
| GET | `colaborador/contratos` | Relación contractual |
| GET | `colaborador/recibos` · `recibos/{recibo}` · `recibos/{recibo}/pdf` | Recibos internos propios |
| GET | `colaborador/prestamos` · `prestamos/{prestamo}` | Préstamos propios |
| GET | `colaborador/jerarquia` | Jefe inmediato, gerente, subordinados |

### Nuevos — jefe / evaluaciones / bandeja

| Método | Ruta | Uso |
|---|---|---|
| GET | `equipo` | Subordinados directos |
| GET | `equipo/pendientes` | Solicitudes del equipo (con visto bueno) y evaluaciones |
| POST | `equipo/solicitudes/{solicitud}/visto-bueno` | Visto bueno del jefe (`aprobado`, `comentario`) |
| GET | `evaluaciones` · `evaluaciones/{evaluacion}` | Evaluaciones visibles |
| POST | `evaluaciones/{evaluacion}/capturar` | Captura del jefe |
| POST | `evaluaciones/{evaluacion}/autorizar` · `.../devolver` | RH/Dirección |
| GET | `tareas` | Bandeja (`estado`, `tipo`, `per_page`) + conteos |
| POST | `tareas/{tarea}/leer` · `tareas/{tarea}/resolver` | Leído / resuelto |

### Nuevos — RH / Dirección / Jurídico (`/api/v1/rh`)

| Método | Ruta | Uso |
|---|---|---|
| POST | `colaboradores` | Alta manual completa |
| GET | `colaboradores/{colaborador}/alta` | Checklist del alta |
| POST | `colaboradores/{colaborador}/activar` | Activación final |
| GET | `colaboradores/{colaborador}/jerarquia` · `.../contratos` | Jerarquía / contratos |
| POST | `candidatos/{candidato}/contratar` | Candidato → colaborador |
| GET/POST/PATCH | `plantillas-documentales` · `plantillas-documentales/variables` · `plantillas-documentales/{plantilla}` | Catálogo, variables, nueva versión, banderas |
| POST | `colaboradores/{colaborador}/documentos-laborales` | Generar documento (clave/plantilla, `contrato_id`, `extra`) |
| GET | `documentos-laborales` · `documentos-laborales/pendientes` · `documentos-laborales/{documento}` · `.../descargar` | Consulta y pendientes físicos |
| POST | `documentos-laborales/{documento}/imprimir` · `firma-fisica` · `envio` · `recepcion` · `escaneo` · `archivar` · `cancelar` | Flujo físico |
| GET | `contratos/por-vencer` | Contratos por vencer (`dias`) |
| POST | `colaboradores/{colaborador}/cierres` | Iniciar cierre laboral |
| GET | `cierres` · `cierres/{cierre}` | Consulta con desglose de finiquito |
| POST | `cierres/{cierre}/aviso` · `aviso/generar` · `finiquito/calcular` · `finiquito/conceptos` · `finiquito/revisar` · `finiquito/documento` · `finiquito/firmado` · `finiquito/pago` · `ejecutar-baja` · `cerrar-expediente` · `cancelar` | Pasos del cierre |
| PATCH/DELETE | `cierres/{cierre}/finiquito/conceptos/{concepto}` | Editar/eliminar concepto |
| POST | `colaboradores/{colaborador}/recibos` | Recibo semanal individual |
| POST | `recibos/importar` | Importación CSV/XLSX (`simular`) |
| GET/POST | `recibos` · `recibos/{recibo}` · `recibos/{recibo}/pdf` · `recibos/{recibo}/regenerar-pdf` | Consulta/PDF |
| POST | `solicitudes/{solicitud}/prestamo/autorizar` · `.../rechazar` | Autorización de préstamo |
| GET/POST | `prestamos` · `prestamos/{prestamo}` · `prestamos/{prestamo}/documentos` · `prestamos/{prestamo}/resguardar` | Préstamos |
| POST | `colaboradores/{colaborador}/actas` | Nueva acta |
| GET/PATCH/POST | `actas` · `actas/{acta}` · `anexos` · `anexos/{anexo}` · `documento` · `negativa-firma` · `seguimiento` · `cerrar` | Actas |
| GET | `plantilla/cobertura` · `indicadores` · `organigrama` · `vacantes/{vacante}` | Estructura e indicadores |

### Modificados (compatibles)

| Ruta | Cambio |
|---|---|
| `POST /api/v1/login` | `throttle:api-login` |
| grupo `auth:sanctum` | `throttle:api` |
| `POST /api/v1/incorporacion/invitaciones/{token}/registrar` | Ahora crea el `Colaborador` (antes solo la cuenta) — misma respuesta |
| `POST /api/v1/solicitudes` (préstamo) | Abre pendiente de visto bueno para el jefe |
| `POST /api/v1/rh/solicitudes/{id}/aprobar` (préstamo) | Exige visto bueno del jefe cuando el colaborador tiene jefe |
| `POST /api/v1/rh/solicitudes/{id}/aprobar` (vacaciones/permisos) | Genera comprobante PDF en el expediente |
| Web `rh.solicitudes.finiquito.generar-pdf` | PDF en `BajaFiniquito/` del expediente + `GeneratedDocument`; incluye conceptos manuales |
