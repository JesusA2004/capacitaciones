# Inventario de formatos jurídicos

Generado a partir de `docs/formatos_fuente/` (33 archivos) y del registro
versionado `config/documentos_maestros.php`, después de correr
`php artisan people:importar-formatos-juridicos` (idempotente).

- La identidad de cada formato es su **SHA-256**, nunca el nombre del archivo.
- 35 filas: el PDF *Contrato de crédito para colaboradores* alimenta 3 masters
  (carta de retención p1, contrato p2-3, pagaré p4) y los dos archivos de
  renuncia son idénticos (mismo master, fuente duplicada).
- "Campos dinámicos" = marcadores internos que el sistema colocó (DOCX) o
  campos de overlay (PDF). Las líneas de firma/huella no cuentan: se dejan en
  blanco para la firma física.
- Estado: `listo` (activable), `bloqueado` (no se activa), `referencia` (no se
  genera para personas).

Ver también: `docs/MATRIZ_DOCUMENTOS_POR_FLUJO.md`,
`docs/FORMATOS_JURIDICOS_OBSERVACIONES.md`, `docs/MOTOR_DOCUMENTOS_MAESTROS.md`.

## Verificación de fuentes y QA visual (2026-10-04)

**Hashes revalidados por CONTENIDO** (no por nombre): para cada uno de los 33
archivos se recalculó el SHA-256 y se leyó el documento (encabezado del
texto y puestos mencionados) para confirmar que corresponde a la familia
registrada. Resultado: los 33 archivos están registrados, ningún hash
apunta a una familia equivocada y no hay archivos fuente sin registrar. Los
dos archivos de renuncia son byte a byte idénticos.

**QA visual con Microsoft Word (fidelidad nativa) + rasterizado nativo de
Windows**, por versión (similitud = mínima por página entre el ORIGINAL y el
master restaurado; páginas = original → con datos largos):

| Familia | Ver. | Páginas | Similitud | Diseño | Activa | Nota |
|---|---|---|---|---|---|---|
| acta_negativa_firma.general | v1 | 3 → 3 | 1.000 | Validado | Sí | |
| aviso_terminacion.general | v1 | 2 → 2 | 1.000 | Validado | Sí | |
| carta_renuncia.general | v1 | 1 → 1 | 1.000 | Validado | Sí | |
| contrato_capacitacion.administrativo | v1 | 9 → 9 | 1.000 | Validado | Sí | |
| contrato_capacitacion.coordinadora | v1 | 9 → 9 | 1.000 | Validado | Sí | |
| contrato_capacitacion.gerente | v1 | 7 → 8 | 1.000 | Validado | No | ⚠ con datos muy largos crece 1 página |
| contrato_capacitacion.gerente | v2 | 9 → 9 | 1.000 | Validado | Sí | |
| contrato_capacitacion.gestor | v1 | 9 → 9 | 1.000 | Validado | Sí | |
| contrato_capacitacion.regional | v1 | 7 → 7 | 1.000 | Validado | Sí | |
| contrato_capacitacion.subgerente | v1 | 9 → 9 | 1.000 | Validado | Sí | |
| contrato_confidencialidad.coordinadora | v1 | 11 → 11 | 1.000 | Validado | Sí | |
| contrato_confidencialidad.general | v1 | 5 → 5 | 1.000 | Validado | No | |
| contrato_confidencialidad.general | v2 | 11 → 11 | 1.000 | Validado | Sí | |
| contrato_confidencialidad.gerente | v1 | 11 → 11 | 1.000 | Validado | Sí | |
| contrato_confidencialidad.gerente | v2 | 21 | — | — | No | Bloqueada (borrador de otra empresa) |
| contrato_confidencialidad.gestor | v1 | 11 → 11 | 1.000 | Validado | Sí | |
| contrato_confidencialidad.subgerente | v1 | 11 → 11 | 1.000 | Validado | Sí | |
| contrato_indeterminado.administrativo_confianza | v1 | 9 → 9 | 1.000 | Validado | Sí | |
| contrato_indeterminado.administrativo_no_confianza | v1 | 9 → 10 | 1.000 | Validado | Sí | ⚠ con datos muy largos crece 1 página |
| contrato_indeterminado.coordinadora | v1 | 10 → 11 | 1.000 | Validado | Sí | ⚠ con datos muy largos crece 1 página |
| contrato_indeterminado.gerente | v1 | 10 → 10 | 1.000 | Validado | Sí | |
| contrato_indeterminado.gestor | v1 | 10 → 10 | 1.000 | Validado | Sí | |
| contrato_indeterminado.regional | v1 | 7 → 7 | 1.000 | Validado | Sí | |
| contrato_indeterminado.subgerente | v1 | 10 → 10 | 1.000 | Validado | Sí | |
| contrato_no_competencia.gerente | v1 | 5 → 5 | 1.000 | Validado | Sí | |
| contrato_no_competencia.gestor | v1 | 5 → 5 | 1.000 | Validado | Sí | |
| evaluacion_capacitacion.general | v1 | 3 → 3 | 1.000 | Validado | Sí | |
| formato_permiso.general | v1 | 1 → 1 | 1.000 | Validado | Sí | Overlay; dos copias por hoja |
| permiso_extraordinario.maternidad | v1 | 2 → 2 | 1.000 | **No validado** | Sí (no genera) | Fuente **Aptos** no instalada en el servidor |
| prestamo_consentimiento_retencion.general | v1 | 1 → 1 | 1.000 | Validado | Sí | Overlay |
| prestamo_contrato.general | v1 | 2 → 2 | 0.992 | Validado | Sí | Overlay; celda "Calle" a 3 renglones |
| prestamo_pagare.general | v1 | 1 → 1 | 1.000 | Validado | Sí | Overlay; celda "Calle y No." a 3 renglones |

Fuentes usadas por los Word: Century Gothic, Calibri, Calibri Light, Arial,
Times New Roman, Segoe UI Symbol/Emoji — todas instaladas en el servidor de
desarrollo — y **Aptos** (solo el permiso extraordinario), no instalada. En
Linux con LibreOffice hay que instalar estas fuentes (Century Gothic no
tiene sustituto de métrica compatible) antes de validar.

| # | Archivo fuente | SHA-256 | Tipo | Proceso | Evento | Empresa | Puesto/grupo | Motor | Campos dinámicos | Firma | Huella | Testigos | Versión | Master activo | Estado | Observaciones |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | ACTA ADMINISTRATIVA-POR NEGATIVA DE FIRMA Y RECEPCIÓN DE DOCUMENTOS.docx | `4e7faa4f7994…` | DOCX | Negativa de firma | negativa_firma | Todas | General | DOCX | 25 | Sí | No | 2 | v1 | Sí | listo | acta_negativa_firma.general — Solo se genera dentro del cierre laboral, cuando se registró que el colaborador se negó a firmar/recibir (Fase 3, escenario B del procedimiento integral de baja). Nunca como acta genérica. |
| 2 | AVISO DE TERMINACIÓN DE LA RELACIÓN LABORAL.docx | `66698e608154…` | DOCX | Baja / cierre laboral | no_renovacion | Todas | General | DOCX | 8 | Sí | No | No | v1 | Sí | listo | aviso_terminacion.general — El ejemplo traía una persona, fechas y duración concretas (Jesús Enrique Ocampo Pérez, 16/07/2026, dos meses): todo se vuelve dato del colaborador y de su contrato. |
| 3 | CONTRATO ACUERDO DE NO COMPETENCIA GESTORES.docx | `48b6c234e47c…` | DOCX | Contratación | contratacion | Todas | Gestor | DOCX | 20 | Sí | Sí | No | v1 | Sí | listo | contrato_no_competencia.gestor — Encabezado fijo "LA C." (femenino) aunque el titular sea hombre: se conserva tal cual. |
| 4 | CONTRATO CAPACITACION INICIAL.docx | `98d05bc162d2…` | DOCX | Contratación | contratacion | Todas | Coordinadora | DOCX | 23 | Sí | Sí | No | v1 | Sí | listo | contrato_capacitacion.coordinadora — El nombre del archivo ("CONTRATO CAPACITACION INICIAL") no indica el puesto; el contenido es para "Coordinadora Administrativa". PEOPLE lo asocia al grupo coordinadora (Coordinadora de Sucursal / Coordinadora Regional): confirmar con RH. |
| 5 | CONTRATO CAPACITACIÓN INICIAL GESTORES.docx | `0717b1b00fed…` | DOCX | Contratación | contratacion | Todas | Gestor | DOCX | 23 | Sí | Sí | No | v1 | Sí | listo | contrato_capacitacion.gestor — Cláusula DÉCIMA CUARTA: la duración ("dos meses") se toma de la configuración del puesto (puestos.meses_periodo_prueba), no del texto del ejemplo. |
| 6 | CONTRATO CONFIDENCIALIDAD GERENTE (1).docx | `eb946a2b10c4…` | DOCX | Contratación | contratacion | Todas | Gerente de sucursal | DOCX | 17 | Sí | Sí | No | v2 | No | bloqueado | contrato_confidencialidad.gerente — Borrador (usuario "Usuario", 09/09/2026): contiene DOS convenios concatenados, cláusula SEXTA sobre "giro restaurantero" y hoja de firmas a nombre de "Lerom Innovaciones Gastronómicas, S.A. de C.V." (otra empresa). No se activa: requiere versión limpia de Jurídico. |
| 7 | CONTRATO CONFIDENCIALIDAD GERENTE.docx | `5110bc540852…` | DOCX | Contratación | contratacion | Todas | Gerente de sucursal | DOCX | 22 | Sí | Sí | No | v1 | Sí | listo | contrato_confidencialidad.gerente — La declaración y la cláusula "No padecer enfermedad…" dicen "capacitación inicial de dos meses"; un gerente tiene 3 meses (puestos.meses_periodo_prueba). El sistema imprime la duración real del puesto. Confirmar con Jurídico. |
| 8 | CONTRATO CONFIDENCIALIDAD GESTORES.docx | `49cc5ac3d885…` | DOCX | Contratación | contratacion | Todas | Gestor | DOCX | 19 | Sí | Sí | No | v1 | Sí | listo | contrato_confidencialidad.gestor |
| 9 | CONTRATO CONFIDENCIALIDAD SUBGERENTE.docx | `cbc53bc4f305…` | DOCX | Contratación | contratacion | Todas | Subgerente | DOCX | 22 | Sí | Sí | No | v1 | Sí | listo | contrato_confidencialidad.subgerente — Duración "dos meses" en la declaración: se imprime la duración real del puesto (subgerente 3 meses). |
| 10 | CONTRATO DE CONFIDENCIALIDAD-COORDINADORAS.docx | `1bc28f7b1cc0…` | DOCX | Contratación | contratacion | Todas | Coordinadora | DOCX | 22 | Sí | Sí | No | v1 | Sí | listo | contrato_confidencialidad.coordinadora |
| 11 | CONTRATO DE CONFIDENCIALIDAD-Mr. Lana.docx | `9b6cda25c2d5…` | DOCX | Contratación | contratacion | Todas | General | DOCX | 18 | Sí | Sí | No | v2 | Sí | listo | contrato_confidencialidad.general — Convenio general: lo firman todos los puestos que no tienen convenio propio (documentos_maestros.fallback_general). |
| 12 | CONTRATO DE CRÉDITO_V2026.pdf | `2ae1ac4fbac5…` | PDF | Referencia (no se genera) | — | Todas | General | PDF overlay | 0 | No | No | No | v1 | No | referencia | contrato_credito_clientes.referencia — Es el contrato de crédito comercial para CLIENTES de MR. LANA (el deudor declara ser "comerciante", lleva aval, producto y CAT): no corresponde al préstamo interno a colaboradores, que usa "Contrato de crédito para colaboradores". Se inventaría como referencia; no se asigna a ningún flujo de PEOPLE. |
| 13 | CONTRATO INDETERMINADO COORDINADORAS.docx | `eae966d82eef…` | DOCX | Renovación / continuidad | renovacion | Todas | Coordinadora | DOCX | 24 | Sí | Sí | No | v1 | Sí | listo | contrato_indeterminado.coordinadora — Salario: el formato dice "$ ______ 00/100 m.n."; el sistema escribe el importe con número y letra antes de "00/100 m.n.". |
| 14 | CONTRATO INDETERMINADO GERENTE.docx | `35f0bb7b100c…` | DOCX | Renovación / continuidad | renovacion | Todas | Gerente de sucursal | DOCX | 24 | Sí | Sí | No | v1 | Sí | listo | contrato_indeterminado.gerente |
| 15 | CONTRATO INDETERMINADO GESTORES.docx | `e1e276d2f3bb…` | DOCX | Renovación / continuidad | renovacion | Todas | Gestor | DOCX | 24 | Sí | Sí | No | v1 | Sí | listo | contrato_indeterminado.gestor |
| 16 | CONTRATO INDETERMINADO MR LANA- SUBGERENTE.docx | `c0249cf8a6e9…` | DOCX | Renovación / continuidad | renovacion | Todas | Subgerente | DOCX | 23 | Sí | Sí | No | v1 | Sí | listo | contrato_indeterminado.subgerente |
| 17 | CONTRATO INDETERMINADO- ADMINISTRATIVOS-CONFIANZA-Mr. Lana.docx | `4f8d9b3617f3…` | DOCX | Renovación / continuidad | renovacion | Todas | Administrativo (confianza) | DOCX | 21 | Sí | Sí | No | v1 | Sí | listo | contrato_indeterminado.administrativo_confianza — Firma y hoja de firmas traían el año 2026 escrito ("DE 2026"); el sistema escribe la fecha completa real. |
| 18 | CONTRATO INDETERMINADO-ADMINISTRATIVOS-NO CONFIANZA- Mr. Lana.docx | `3b3ae245bd32…` | DOCX | Renovación / continuidad | renovacion | Todas | Administrativo (no confianza) | DOCX | 21 | Sí | Sí | No | v1 | Sí | listo | contrato_indeterminado.administrativo_no_confianza |
| 19 | CONTRATO NO COMPETENCIA GERENTES.docx | `2f85df2b0fa3…` | DOCX | Contratación | contratacion | Todas | Gerente de sucursal | DOCX | 21 | Sí | Sí | No | v1 | Sí | listo | contrato_no_competencia.gerente — El encabezado y las cláusulas citan "CONTRATO LABORAL POR CAPACITACIÓN INICIAL DE PERIODO DE DOS MESES"; un gerente tiene 3 meses. El sistema imprime la duración real del puesto. Confirmar con Jurídico. |
| 20 | CONTRATO-CAPACITACIÓN INICIAL GERENTE (1).docx | `1cb161c0419c…` | DOCX | Contratación | contratacion | Todas | Gerente de sucursal | DOCX | 23 | Sí | Sí | No | v2 | Sí | listo | contrato_capacitacion.gerente — v2: la cláusula PRIMERA inicia con "DEL OBJETO. .  Las partes acuerdan…" (punto duplicado y el texto del artículo 39-B quedó después del párrafo de finalidad). Se conserva tal cual; revisar redacción con Jurídico. |
| 21 | CONTRATO-CAPACITACIÓN INICIAL GERENTE.docx | `19407fad47d1…` | DOCX | Contratación | contratacion | Todas | Gerente de sucursal | DOCX | 22 | Sí | Sí | No | v1 | No | listo | contrato_capacitacion.gerente — Versión RH 07/01/2026: domicilio del patrón ya escrito, vigencia "Dos meses" (no corresponde a gerente) y sin el párrafo de finalidad de la capacitación. Sustituida por la versión de Jurídico del 15/01/2026. |
| 22 | CONTRATO-CAPACITACIÓN INICIAL-ADMINISTRATIVOS-Mr. Lana.docx | `2358611f5f3c…` | DOCX | Contratación | contratacion | Todas | Administrativo (confianza), Administrativo (no confianza) | DOCX | 23 | Sí | Sí | No | v1 | Sí | listo | contrato_capacitacion.administrativo — Lugar de trabajo y lugar de firma vienen escritos para el CORPORATIVO (Subida al Club 114) con guiones bajos alrededor ("CP. 62260_", "__CORPORATIVO__"); se conservan como texto fijo. |
| 23 | CONTRATO-CAPACITACIÓN INICIAL-SUBGERENTE.docx | `bc6a3a2e10e8…` | DOCX | Contratación | contratacion | Todas | Subgerente | DOCX | 23 | Sí | Sí | No | v1 | Sí | listo | contrato_capacitacion.subgerente |
| 24 | Contrato de crédito para colaboradores.pdf | `2fcbe22255e1…` | PDF | Préstamo | prestamo_autorizado | Todas | General | PDF overlay | 9 | Sí | No | No | v1 | Sí | listo | prestamo_consentimiento_retencion.general |
| 25 | Contrato de crédito para colaboradores.pdf | `2fcbe22255e1…` | PDF | Préstamo | prestamo_autorizado | Todas | General | PDF overlay | 24 | Sí | Sí | No | v1 | Sí | listo | prestamo_contrato.general |
| 26 | Contrato de crédito para colaboradores.pdf | `2fcbe22255e1…` | PDF | Préstamo | prestamo_autorizado | Todas | General | PDF overlay | 29 | Sí | Sí | No | v1 | Sí | listo | prestamo_pagare.general — Intereses moratorios: el pagaré deja la cantidad en blanco y PEOPLE no la tiene definida; se deja en blanco para llenado de RH/Jurídico. |
| 27 | FORMATO  DE RENUNCIA DE MR LANA (1).docx | `d229cd0563ae…` | DOCX | Baja / cierre laboral | renuncia | Todas | General | DOCX | 10 | Sí | Sí | No | v1 | Sí | listo | carta_renuncia.general — "FORMATO DE RENUNCIA DE MR LANA (1).docx" es idéntico byte a byte (mismo SHA-256): se registra como fuente duplicada del mismo master. |
| 28 | FORMATO  DE RENUNCIA DE MR LANA.docx | `d229cd0563ae…` | DOCX | Baja / cierre laboral | renuncia | Todas | General | DOCX | 10 | Sí | Sí | No | v1 | Sí | listo | carta_renuncia.general — "FORMATO DE RENUNCIA DE MR LANA (1).docx" es idéntico byte a byte (mismo SHA-256): se registra como fuente duplicada del mismo master. |
| 29 | FORMATO CONTRATO DE CONFIDENCIALIDAD PARA MR LANA.docx | `5e4f9d1e6c36…` | DOCX | Contratación | contratacion | Todas | General | DOCX | 12 | Sí | Sí | No | v1 | No | listo | contrato_confidencialidad.general — Formato 2024 ("FORMATO CONTRATO DE CONFIDENCIALIDAD PARA MR LANA"): representante escrita "LESLI MARIBEL RODRÍGUEZ JIMÉNEZ" y fecha de firma con "febrero de 202" fijo. Sustituido por el convenio general de Jurídico 2026. |
| 30 | FORMATO CONTRATO INDETERMINADO MR LANA- REGIONAL.docx | `65bf402064f4…` | DOCX | Renovación / continuidad | renovacion | Todas | Gerente regional | DOCX | 22 | Sí | Sí | No | v1 | Sí | listo | contrato_indeterminado.regional — Formato de 2024: el domicilio legal del patrón está escrito con el domicilio anterior (Domingo Diez 1003). Se conserva; confirmar con Jurídico. |
| 31 | FORMATO CONTRATO-CAPACITACIÓN INICIAL REGIONAL.docx | `14d25f64da0c…` | DOCX | Contratación | contratacion | Todas | Gerente regional | DOCX | 22 | Sí | Sí | No | v1 | Sí | listo | contrato_capacitacion.regional — Formato de 2024 (autor J. L. Rosales): el domicilio legal del patrón está escrito como "avenida Domingo Diez, número 1003, piso 3, colonia el Empleado" (domicilio anterior); los formatos 2026 usan Subida al Club 114. Se conserva tal cual: confirmar con Jurídico. |
| 32 | FORMATO DE EVALUACIÓN DE CAPACITACIÓN INICIAL (1).docx | `d6f08e3afa20…` | DOCX | Baja / cierre laboral | evaluacion | Todas | General | DOCX | 33 | Sí | No | No | v1 | Sí | listo | evaluacion_capacitacion.general — El formato es de NO acreditación: la sección VI ("DETERMINACIÓN") solo aplica cuando el colaborador no acredita. Con resultado ACREDITA PEOPLE no lo genera (no se altera el texto jurídico). Si RH requiere constancia de acreditación, Jurídico debe entregar la variante. |
| 33 | FORMATO PERMISO MR. LANA.pdf | `300fc897e2bd…` | PDF | Permiso | permiso_aprobado | Todas | General | PDF overlay | 12 | Sí | No | No | v1 | Sí | listo | formato_permiso.general — Overlay sobre el PDF original (sin rediseñar). Firmas de jefe inmediato, RH y colaborador quedan en blanco para firma física. |
| 34 | PERMISO EXTRAORDINARIO CON GOCE DE SUELDO.docx | `ea108fbcc742…` | DOCX | Permiso | permiso_extraordinario | Todas | General | DOCX | 6 | Sí | No | 2 | v1 | Sí | listo | permiso_extraordinario.maternidad — Formato específico para colaboradoras embarazadas (redacción en femenino, "protección a la maternidad"). Solo se ofrece en solicitudes de permiso con goce marcadas por RH como permiso extraordinario de maternidad. |
| 35 | PROCEDIMIENTO INTEGRAL DE BAJA DE COLABORADOR.docx | `58c0b1b63fd9…` | DOCX | Referencia (no se genera) | — | Todas | General | DOCX | 0 | No | No | No | v1 | No | referencia | procedimiento_baja.referencia — NO es plantilla: es la especificación del proceso de baja por vencimiento de capacitación inicial. Se usó para auditar el flujo de cierre (docs/MATRIZ_DOCUMENTOS_POR_FLUJO.md). Nunca se genera para un colaborador ni se archiva en expedientes. |
