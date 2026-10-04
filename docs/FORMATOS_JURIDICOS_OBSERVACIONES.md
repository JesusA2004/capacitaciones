# Observaciones sobre los formatos jurídicos (para RH/Jurídico)

PEOPLE **no corrige** texto jurídico: solo sustituye datos variables. Lo
siguiente se detectó al analizar los originales; se imprime tal cual hasta
que Jurídico entregue una versión corregida (que se carga como nueva versión
en Administración → Documentos maestros).

| Archivo | Sección | Hallazgo |
|---|---|---|
| CONTRATO CONFIDENCIALIDAD GERENTE (1).docx | Todo | Contiene **dos convenios concatenados**; cláusula SEXTA habla del "giro restaurantero" y la hoja de firmas menciona a "Lerom Innovaciones Gastronómicas, S.A. de C.V." (otra empresa). Versión **bloqueada**; se usa la versión de enero 2026. |
| CONTRATO-CAPACITACIÓN INICIAL GERENTE.docx (RH 07/01/2026) | Cl. DÉCIMA CUARTA | Dice "Dos meses" para gerente (3 meses). Sustituida por la versión de Jurídico 15/01/2026. |
| CONTRATO-CAPACITACIÓN INICIAL GERENTE (1).docx | Cl. PRIMERA | "DEL OBJETO. .  Las partes acuerdan…": punto duplicado y el texto del art. 39-B quedó después del párrafo de finalidad. |
| Capacitación / indeterminados Gestor y otros | Varias cláusulas | Resaltado verde (marcas de revisión de Jurídico) en el original; se imprime con resaltado. Entregar versión limpia si no debe aparecer. |
| Confidencialidad Gerente, Subgerente, Coordinadoras | Declaración y "No padecer enfermedad…" | Dicen "capacitación inicial de dos meses"; esos puestos tienen 3 meses. PEOPLE imprime la duración real del contrato. |
| CONTRATO NO COMPETENCIA GERENTES.docx | Encabezado y cláusulas PRIMERO/SEGUNDO | "CONTRATO LABORAL … DE PERIODO DE DOS MESES" para gerentes (3 meses). PEOPLE imprime la duración real. Título "ACUERDO DE NO COMPETENCIA" duplicado. |
| CONTRATO ACUERDO DE NO COMPETENCIA GESTORES.docx | Encabezado | "LA C." fijo (femenino) aunque el titular sea hombre. Pena de $500,000.00 y obligación "vitalicia" (texto jurídico, no se modifica). |
| Convenios de confidencialidad | Declaración del receptor | "de ______ edad" (sin "años"); "Clavel de Elector" (errata). |
| Confidencialidad Gerente/Subgerente/Coordinadoras | Cl. jurisdicción | Estado y ciudad en blanco. **Decisión RH:** se imprimen en blanco, tal cual el original (PEOPLE solo llena datos del colaborador). |
| FORMATO CONTRATO DE CONFIDENCIALIDAD PARA MR LANA.docx (2024) | Firma | Representante "LESLI MARIBEL RODRÍGUEZ **JIMÉNEZ**" (en los demás: HERRERA) y fecha "febrero de 202" fija. Sustituido por el general 2026. |
| FORMATO CONTRATO … REGIONAL (capacitación e indeterminado, 2024) | Declaración I.7 | Domicilio legal del patrón con la dirección anterior (Av. Domingo Diez 1003); los 2026 usan Subida al Club 114. El indeterminado no declara si el puesto es o no de confianza. |
| Contrato de crédito para colaboradores.pdf | Declaraciones y pagaré | Domicilio Av. Domingo Diez 1003 y atencion@mr-lana.com; el V2026 (clientes) usa Subida del Club #114 y atencionclientes@. El deudor "declara ser comerciante" (texto de clientes). El pagaré deja en blanco los intereses moratorios (PEOPLE no los tiene). Cargo e IVA del contrato quedan en blanco. El PDF trae notas de revisión (no se imprimen). |
| CONTRATO DE CRÉDITO_V2026.pdf | — | Es contrato para **clientes** (aval, producto, CAT): no se asigna al préstamo de colaboradores. |
| CONTRATO INDETERMINADO COORDINADORAS.docx | Hoja de firmas | "celebrado entre celebrado entre". Salario "$ ___ 00/100 m.n." (PEOPLE escribe importe con letra antes). |
| CONTRATO CAPACITACION INICIAL.docx | Puesto | El archivo no dice el puesto; el contenido es "Coordinadora Administrativa". Asociado a Coordinadora de Sucursal / Regional (**confirmado por RH**: la Regional es la misma Coordinadora con alcance sobre varias sucursales). Su cláusula NOVENA no trae blanco de salario. |
| FORMATO DE EVALUACIÓN DE CAPACITACIÓN INICIAL (1).docx | Secciones III, IV, VI | Criterios redactados para Gerente ("subgerente y asesores"); la sección VI solo contempla NO acreditación, por eso PEOPLE no lo genera con resultado ACREDITA. |
| ACTA ADMINISTRATIVA POR NEGATIVA… | Encabezado | Ejemplo en Cuernavaca/domicilio corporativo; PEOPLE propone ciudad y domicilio de la sucursal (editable al registrar la negativa). "Tipo de contrato" fijo en capacitación inicial. |
| AVISO DE TERMINACIÓN… | Destinatario | "C.JESUS…" sin espacio (se conserva "C." pegado). |
| FORMATO DE RENUNCIA DE MR LANA (1).docx | — | Idéntico byte a byte al otro archivo de renuncia. |

## Decisiones tomadas (pendientes de validación de RH)

- **Representante**: en contratos y convenios la representante está fija en
  las declaraciones notariales (Lesli Maribel Rodríguez Herrera) — no se
  cambia. En avisos operativos (aviso de terminación, acta de negativa,
  firma del contrato de crédito) se toma de la empresa
  (`empresas.representante_legal_nombre`), con respaldo en
  `documentos_maestros.empresa_defecto`.
- **Grupos de puesto** iniciales en `documentos_maestros.grupos_por_puesto`
  (administrativos de confianza vs. no confianza según la redacción de sus
  contratos). Editables en Parámetros RH.
- **Confidencialidad general** (**decisión RH**): la firman todos los puestos
  que no tienen convenio propio — Regional, Administrativos, Sistemas,
  Dirección Comercial, etc. (`fallback_general` = `*`).
- **Reclutamiento** es administrativo de confianza (decisión RH).
- **Domicilio del patrón** («…el ubicado en ____») sí se llena (decisión RH). Configuración → Parámetros de RH → «Domicilio del patrón en contratos y convenios» elige el **fiscal** de la empresa o el **de la sucursal** del colaborador (Sucursales → calle, colonia, C.P., municipio, estado); si la sucursal no tiene domicilio capturado se usa el fiscal.
- **Historial auditable**: cada carga/activación/desactivación/prueba de un
  master y cada cambio de grupo documental o meses de un puesto queda en la
  bitácora (quién y cuándo) y se muestra en Documentos maestros y en
  Parámetros RH.
