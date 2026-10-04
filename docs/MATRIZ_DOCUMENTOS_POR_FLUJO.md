# Matriz de documentos por flujo

Qué documento oficial genera PEOPLE, en qué momento y desde dónde. La regla
vive en `App\Services\DocumentosMaestros\DocumentoProcesoService` y en
`config/documentos_maestros.php` (`paquetes`, `documentos_por_causa`,
`documentos_negativa`, `documentos_prestamo`, `tipos_solicitud_permiso`).
Web y app solo muestran lo que devuelve el backend
(`/rh/documentos-proceso/*`, `/api/v1/rh/documentos-proceso/*`).

Nunca se genera desde "Formatos/Plantillas": eso es administración
(Administración → Documentos maestros).

## Elección de la variante

Puesto exacto + empresa → puesto exacto → grupo documental + empresa → grupo
documental → formato general (solo si el tipo no tiene variantes o si
`fallback_general` lo permite; la confidencialidad general aplica a todos
los puestos sin convenio propio) → **422
DOCUMENT_TEMPLATE_MISSING** ("No está cargado el formato de … para …").

El grupo documental se asigna por puesto en Administración → Parámetros RH
(columna "Contratos (grupo documental)").

## Alta (contratación) — ficha del colaborador, "Documentos de contratación"

Se habilita cuando el expediente queda completo (todos los obligatorios
aprobados). Botón **Generar paquete de contratación** (o uno por uno).

| Grupo | Capacitación inicial | Confidencialidad | No competencia |
|---|---|---|---|
| Gestor | Gestor | Gestores | Gestores |
| Gerente | Gerente (v2 Jurídico) | Gerente (v1; la v2 está bloqueada) | Gerentes |
| Subgerente | Subgerente | Subgerente | — (no hay formato) |
| Regional | Regional | General MR. LANA | — |
| Coordinadora | Coordinadora administrativa | Coordinadoras | — |
| Administrativo confianza / no confianza | Administrativos | General MR. LANA | — |
| Cualquier otro puesto (Sistemas, Dirección Comercial, etc.) | según su grupo | General MR. LANA | — |

Duración del contrato: la del propio contrato (inicio → fin), que nace de
`puestos.meses_periodo_prueba` (Gestor 2, Gerente/Subgerente/Regional/
Coordinadora 3). Nunca la del texto de ejemplo.

La Regional usa los mismos formatos que la Coordinadora de sucursal cuando
no tiene uno propio: es el mismo puesto con alcance sobre varias sucursales.

Los blancos del original que no son datos del colaborador (p. ej. estado y
ciudad de jurisdicción en los convenios de Gerente/Subgerente/Coordinadoras)
se imprimen tal cual vienen, en blanco.

En **cualquier modalidad** (capacitación inicial, indeterminado, periodo de
prueba 39-A, tiempo determinado) el paquete lleva: el contrato de la
modalidad + la confidencialidad (todos los puestos) + la no competencia
(Gestor y Gerente). Periodo de prueba y tiempo determinado todavía no tienen
contrato de Jurídico → ese renglón sale "Formato no cargado"; los convenios
sí se generan. Un puesto sin grupo documental (Sistemas, Dirección…) recibe
su confidencialidad y su contrato de capacitación aparece como "Formato no
cargado" hasta que Jurídico entregue uno.

Modo de generación: por defecto RH pulsa **Generar paquete de contratación**
cuando el expediente queda completo. Con
`DOCUMENTOS_ALTA_GENERACION_AUTOMATICA=true` se genera solo.

Transición: mientras una clave no tenga ningún master importado
(`people:importar-formatos-juridicos`), se usa la plantilla que RH ya tenía
cargada; en cuanto existe un master, la resolución es estricta (variante
correcta o "Formato no cargado").

Después: descargar/imprimir → firma y huella → (envío a corporativo →
recepción) → escaneo → archivo.

## Renovación — evaluación autorizada con continuidad

Contrato por tiempo indeterminado del grupo (Gestor, Gerente, Subgerente,
Regional, Coordinadora, Administrativo confianza/no confianza).

## Evaluación de capacitación — evaluación / cierre

`Formato de evaluación de capacitación inicial`: solo con resultado **NO
acredita** validado por RH (su sección VI solo contempla esa determinación).
Los 7 criterios oficiales (`contratos.criterios_evaluacion`) marcan
Acredita/No acredita; ELABORÓ = quien capturó, VALIDÓ = RH que autorizó.

## Baja — cierre laboral, "Documentos de la baja"

| Causa | Documentos | Cuándo |
|---|---|---|
| Renuncia voluntaria | Formato de renuncia MR. LANA + finiquito (módulo) | El gerente lo genera en cuanto solicita la baja, con el colaborador presente |
| No renovación / fin de contrato | Evaluación de capacitación + Aviso de terminación + finiquito (módulo) | Al autorizar RH (se preparan solos); el aviso va fechado el día del vencimiento |
| Bajo desempeño, rescisión, otras | Finiquito (módulo) | Jurídico no ha entregado formatos para estas causas |

### Procedimiento integral de baja (vencimiento de capacitación)

El documento *PROCEDIMIENTO INTEGRAL DE BAJA DE COLABORADOR* es la
especificación del flujo (no se imprime para nadie). Etapas en PEOPLE:

| Fase del procedimiento | En PEOPLE |
|---|---|
| 1. Preparación 3-5 días antes (evaluación, KPI's, borrador de aviso, cálculo preliminar) | Scheduler avisa 15 días antes y crea la evaluación; evidencia de desempeño se adjunta al cierre; cálculo de finiquito |
| 2. Evaluación formal (jefe + RH) | Captura del jefe → validación RH → formato oficial |
| 3. Notificación (aviso, evaluación, finiquito) | Documentos de la baja |
| 3-A Firma | Registrar firma física → pago |
| 3-B Negativa | **El colaborador se negó a firmar/recibir** → Acta administrativa de negativa con 2 testigos; finiquito a disposición; no se trata como firmado |
| 4. Notificación complementaria el mismo día | Etapa "Notificación complementaria" con evidencia obligatoria |
| 5. Baja operativa (IMSS, asistencia, accesos, aviso interno) | Etapas registrables; accesos se suspenden al solicitar la baja y se marcan cancelados al ejecutarla |
| 6. Consignación preventiva | Etapa "Consignación preventiva del finiquito" con evidencias |
| 7. Checklist final | Se muestra en el cierre; **no se cierra el expediente** hasta cumplir lo que aplique |

## Negativa de firma

Solo existe como rama del cierre (Fase 3-B). Acta precargada con lugar,
fecha/hora, RH participante, jefe, colaborador, puesto, inicio/fin del
contrato y testigos (nombre y cargo); las firmas son físicas.

## Permiso — solicitud aprobada, "Formato de permiso"

Tipos: con/sin goce, por horas, salida temprano, llegada tarde, permisos
especiales. Overlay sobre el PDF original (las dos copias de la hoja):
nombre, departamento, fecha(s), permiso solicitado, horas, modalidad
(tiempo x tiempo / descuento vía nómina / permiso especial) y motivo.
Firmas de jefe, RH y colaborador en blanco.

Permiso extraordinario con goce (maternidad): solo si RH marca la solicitud
con modalidad `extraordinario_maternidad`.

## Préstamo — autorización final de RH, "Documentos del préstamo"

Del original *Contrato de crédito para colaboradores*: carta de no adeudo y
retención en nómina, contrato de crédito para trabajadores y pagaré.

## Activos — entrega de activo, "Responsiva"

Jurídico no ha entregado formato de carta responsiva: se usa la plantilla
anterior si RH tenía una; si no, "Formato no cargado".
