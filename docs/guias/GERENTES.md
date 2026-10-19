<!-- Archivo GENERADO por `npm run guia:docs` desde resources/js/lib/guia. No lo edites a mano: cambia el contenido allá y vuelve a generar. -->

# Guía rápida para Gerentes

Lo que le toca al **gerente de sucursal** (entrevistas, socioeconómico, preautorizaciones, firmas, entrega de equipo, evaluaciones y vistos buenos), al **gerente regional** y a **Dirección Comercial**. Solo aparece lo que su rol realmente puede hacer.

## Gerente de sucursal

Entrevista y revisa a los candidatos de su sucursal (psicométricos y socioeconómico: viable o no viable), da visto bueno a las solicitudes de su equipo, levanta actas, inicia la baja de alguien de su equipo, imprime y recaba firmas, entrega equipo y evalúa la capacitación inicial.

**Dónde trabaja:** Web o app (Gestión RH: Pendientes, Equipo).

**Roles del sistema:** `gerente_sucursal`, `gerente`, `subgerente`, `jefe_directo`

### Tu checklist

- [ ] Entrevistas de candidatos → [Entrevista con el gerente](#articulo-entrevista-gerente)
- [ ] Revisar psicométricos (viable / no viable) → [Psicométricos](#articulo-psicometricos)
- [ ] Revisar el estudio socioeconómico → [Estudio socioeconómico](#articulo-socioeconomico)
- [ ] Solicitar intervención (si RH rechaza) → [Intervención sobre un rechazo de RH](#articulo-intervencion)
- [ ] Imprimir, firmar y enviar contratos → [Firma de contratos y control del original](#articulo-firma-y-original)
- [ ] Entregar equipo (onboarding) → [Onboarding: la bienvenida del colaborador](#articulo-onboarding)
- [ ] Evaluación de capacitación inicial → [Evaluación de capacitación inicial](#articulo-evaluacion-capacitacion)
- [ ] Visto bueno de permisos y vacaciones → [Revisar solicitudes (gerente, regional y RH)](#articulo-revisar-solicitudes)
- [ ] Levantar un acta → [Acta administrativa circunstanciada](#articulo-actas)
- [ ] Iniciar la baja de alguien de tu equipo → [Baja de un colaborador (cierre laboral)](#articulo-baja)
- [ ] Directorio → [Directorio y correo corporativo](#articulo-directorio)
- [ ] Mis pendientes → [Mis pendientes: tu bandeja de trabajo](#articulo-mis-pendientes)

### Lo que NO le toca

- Autorizar una contratación (siempre es RH).
- Imprimir un permiso antes de que RH lo autorice.
- Capturar campañas o la plantilla autorizada.

## Gerente Regional

Monitorea las sucursales de su región, da el segundo visto bueno de solicitudes, entrevista y revisa candidatos a Gerente de sucursal y decide intervenciones de Gestor o Gestor Volante cuando el gerente las pide.

**Dónde trabaja:** App y web, con alcance de región.

**Roles del sistema:** `gerente_regional`

### Tu checklist

- [ ] Visto bueno de solicitudes de su región → [Revisar solicitudes (gerente, regional y RH)](#articulo-revisar-solicitudes)
- [ ] Intervenciones de Gestor / Volante (solo si se piden) → [Intervención sobre un rechazo de RH](#articulo-intervencion)
- [ ] Entrevistar y revisar candidatos a Gerente de sucursal → [Entrevista con el gerente](#articulo-entrevista-gerente)
- [ ] Monitorear vacantes de la región → [De dónde nace una vacante](#articulo-plantilla-y-vacantes)

### Lo que NO le toca

- Autorizar contrataciones (siempre es RH).
- Decidir intervenciones de puestos superiores (eso es de Dirección Comercial).

## Dirección Comercial

Consulta global de indicadores, vacantes y candidatos. Decide únicamente las intervenciones de puestos superiores a Gestor/Volante cuando un gerente las solicita.

**Dónde trabaja:** Web y app (consulta).

**Roles del sistema:** `director_comercial`

### Tu checklist

- [ ] Intervenciones de puestos superiores → [Intervención sobre un rechazo de RH](#articulo-intervencion)
- [ ] Consultar vacantes → [De dónde nace una vacante](#articulo-plantilla-y-vacantes)
- [ ] Consultar el embudo de candidatos → [Las fases del candidato](#articulo-etapas-del-candidato)

### Lo que NO le toca

- Aprobar candidatos normales, solicitudes o vacaciones.
- Intervenciones de Gestor o Gestor Volante (son del Regional).

---

## 1. Reclutamiento

<a id="articulo-plantilla-y-vacantes"></a>

### De dónde nace una vacante

> Una vacante no se captura: PEOPLE la calcula restando a la plantilla autorizada las personas que hoy ocupan el puesto.

#### Qué es

Una vacante es una plaza autorizada que hoy no tiene titular. Nadie escribe «vacante abierta»: PEOPLE compara, para cada sucursal y puesto, la plantilla autorizada contra los colaboradores vigentes que ocupan ese puesto, y lo que falta es la vacante real.

#### Quién lo hace

Gerencia de RH captura la plantilla autorizada (Administración → Sucursales). Todos los demás solo consultan Vacantes: la pantalla es informativa.

> **Importante:** Una sola fuente para todos: Vacantes, Campañas, Candidatos y la contratación consultan el mismo cálculo (plantilla autorizada − ocupados). No existen listas de vacantes paralelas.

#### Pasos

1. **Plantilla autorizada** — Es cuántas plazas de un puesto autorizó Dirección en una sucursal. Ejemplo: Sucursal Tlaxcala, Gestor, plantilla autorizada 5.
2. **Ocupados** — PEOPLE cuenta en vivo a los colaboradores vigentes (activos o en incorporación) titulares de ese puesto en esa sucursal. El Gestor Volante cuenta como Gestor. Ejemplo: 4 gestores.
3. **Faltantes = vacantes reales** — Plantilla autorizada − ocupados, nunca menos de cero. Ejemplo: 5 − 4 = 1 vacante real de Gestor en Tlaxcala.
4. **Qué ve RH en Vacantes** _(Web)_ — Arriba, cuántas plazas faltan de cada puesto y en qué sucursales (un clic filtra). Debajo, una fila por vacante: puesto, sucursal, plazas que faltan, días abierta, candidatos que lleva y el contexto «4 de 5 ocupadas».

#### Qué pasa después

- Si alguien se da de baja, cambia de puesto o de sucursal, la vacante del lugar que deja se abre sola.
- Cuando alguien entra a esa plaza (contratación), la vacante baja o se cierra sola.
- Campañas, Candidatos y Contratación usan esta misma cifra: nadie puede publicar, registrar o contratar sobre una plaza que ya no falta.

#### Errores comunes

- **«No veo una vacante que debería existir.»** Revisa la plantilla autorizada de ese puesto en Administración → Sucursales → detalle. Si la plantilla ya está completa, no hay vacante. Si la sucursal no es de tu alcance, tampoco la verás.
- **«Quiero crear una vacante a mano.»** No se puede y no hace falta: Vacantes es 100 % derivada. Si Dirección autorizó una plaza nueva, Gerencia de RH la agrega a la plantilla y la vacante aparece.
- **«Alguien cubre temporalmente la plaza y la vacante sigue abierta.»** Es correcto: una cobertura temporal no cuenta como ocupado. La plaza sigue sin titular.

#### En el sistema

- **Ir a Vacantes** → `/rh/vacantes` _(permiso: vacantes.ver)_
- **Ir a Sucursales** → `/administracion/sucursales` _(permiso: sucursales.administrar)_
- Guía en pantalla → `/ayuda/articulo/plantilla-y-vacantes`

<a id="articulo-etapas-del-candidato"></a>

### Las fases del candidato

> El tablero tiene 6 fases y 2 columnas de cierre. Cada avance se hace con su acción en la ficha, nunca arrastrando.

#### Qué es

Es el recorrido de cada candidato, visible como columnas en Reclutamiento → Candidatos. Las fases agrupan los pasos internos para que RH vea solo lo importante.

#### Quién lo hace

RH, Gerente, Dirección Comercial.

> **Importante:** Arrastrar una tarjeta solo sirve para cerrar el proceso (a Rechazado o Desistió, con motivo). Para avanzar se usa la acción de la ficha («Revisar perfil», «Registrar entrevista»…): así cada paso deja su registro completo.

> **Nota:** La ficha muestra siempre quién tiene la siguiente acción (Reclutamiento, Gerente de sucursal o RH) y solo ofrece los botones que a ti te tocan.

#### Pasos

1. **1 · Filtro RH** _(RH)_ — Reclutamiento revisa si el perfil cubre la vacante (primer contacto).
2. **2 · Entrevista** _(Gerente)_ — El gerente de la sucursal entrevista y registra el resultado.
3. **3 · Psicométricos** — Reclutamiento envía el link y registra resultados; el gerente los revisa.
4. **4 · Socioeconómico** _(Gerente)_ — El gerente visita el domicilio y registra el estudio con evidencias.
5. **5 · Contratación** — Agrupa: validación de referencias → autorización de RH → QR de contratación → registro y contratos.
6. **6 · Contratado** — Contratos firmados: la persona ya es colaborador e inicia onboarding.
7. **Cerrados: Rechazado · Desistió** — «No viable», «No seleccionado», «No respondió» y «Rechazado por RH» son MOTIVOS dentro de «Rechazado», no fases propias. El motivo sale del catálogo y queda en la ficha.

#### Qué pasa después

- Cada avance abre el pendiente del siguiente responsable en «Mis pendientes» (web) y en la app.
- Un candidato puede cerrarse desde reclutamiento mientras no haya entrado a contratación.

#### Errores comunes

- **«No puedo arrastrar la tarjeta a la siguiente columna.»** Es a propósito. Abre la ficha y usa la acción del paso.
- **«El candidato ya está en otra fase. Recarga la ficha.»** Otra persona avanzó el paso al mismo tiempo. Recarga y revisa el historial.

#### En el sistema

- **Ir a Candidatos** → `/rh/candidatos` _(permiso: candidatos.ver)_
- Guía en pantalla → `/ayuda/articulo/etapas-del-candidato`

<a id="articulo-entrevista-gerente"></a>

### Entrevista con el gerente

> El responsable de la vacante entrevista y registra fecha, observaciones y resultado: el gerente de la sucursal; si la vacante es de Gerente de sucursal, el Gerente Regional.

#### Qué es

Es la entrevista del responsable de la vacante (sale del organigrama: vacante → sucursal → puesto). Una vacante de gerente nunca se entrevista a sí misma: la entrevista el Regional. Entrevistar no es autorizar: la contratación la autoriza RH.

#### Quién lo hace

Gerente, Regional.

#### Pasos

1. **Abre el pendiente** _(Web y app)_ — Mis pendientes o Candidatos → ficha del candidato.
2. **«Registrar entrevista»** — Fecha y hora, observaciones y resultado (viable / no viable).
3. **Si no es viable** — Motivo obligatorio del catálogo.

#### Qué pasa después

- Viable → pasa a «Psicométricos» y Reclutamiento recibe el pendiente de enviar el link.
- No viable → «Rechazado» con motivo.

#### Errores comunes

- **«No me aparece el botón Registrar entrevista.»** Solo lo ve quien tiene permiso de evaluar candidatos y alcance sobre esa sucursal. Revisa que la vacante sea de tu sucursal.

#### En el sistema

- **Ir a Mis pendientes** → `/rh/pendientes`
- Guía en pantalla → `/ayuda/articulo/entrevista-gerente`

<a id="articulo-psicometricos"></a>

### Psicométricos

> Reclutamiento envía el link y carga el resultado (PDF o imagen). Cargar NO decide: queda pendiente de revisión hasta que el responsable lo abre y dice viable o no viable.

#### Qué es

Son las pruebas psicométricas del candidato. Se aplican en la plataforma del proveedor; PEOPLE guarda el link, el resumen y los reportes, y registra la revisión del gerente.

#### Quién lo hace

Reclutamiento envía y carga; el responsable de la vacante (gerente de la sucursal; para una vacante de gerente, el Regional) revisa.

#### Pasos

1. **«Registrar link de psicométricas»** _(RH)_ — Pega la liga que se le envió al candidato (opcional, para dejar constancia).
2. **«Cargar resultados psicométricos»** _(RH)_ — Resumen y hasta 5 reportes en PDF o imagen (JPG, PNG, WEBP). El candidato queda «Revisión de psicométricas»: todavía no avanza.
3. **«Ver psicométrico» y «Revisar psicométrico»** _(Gerente)_ — El responsable abre el PDF (botón arriba de la ficha) y decide Viable o No viable, con observaciones. Solo entonces avanza.
4. **Ver la evidencia sin salir de PEOPLE** — En la ficha, «Evaluaciones registradas» → clic en el archivo: el PDF y las imágenes se abren en un visor dentro de PEOPLE.

#### Qué pasa después

- Viable → «Socioeconómico».
- No viable → «Rechazado» con motivo.

#### Errores comunes

- **«¿Dónde quedó guardado el reporte?»** En el almacenamiento privado de PEOPLE. Nunca se muestra la ruta del servidor: se abre siempre desde la ficha.

#### En el sistema

- **Ir a Candidatos** → `/rh/candidatos` _(permiso: candidatos.ver)_
- Guía en pantalla → `/ayuda/articulo/psicometricos`

<a id="articulo-socioeconomico"></a>

### Estudio socioeconómico

> Se carga el estudio (video, PDF o fotos de la visita). Cargar NO es aprobar: el responsable lo abre y decide viable o no viable.

#### Qué es

Es la visita domiciliaria al candidato. PEOPLE guarda los datos de la visita, un checklist, los riesgos y las evidencias, separadas de las del psicométrico.

#### Quién lo hace

Gerente, RH.

#### Pasos

1. **«Cargar estudio socioeconómico»** — Fecha de visita, dirección visitada y checklist: vivienda en orden, vive con su familia, años de arraigo y resguardo de motocicleta. Queda «pendiente de revisión».
2. **Riesgos y observaciones** — Riesgos o inconsistencias detectados y observaciones.
3. **Evidencias** — Hasta 12 archivos: fotos (JPG, PNG, WEBP), PDF o video (MP4, MOV).
4. **«Revisar socioeconómico»** _(Gerente)_ — El responsable abre el video o el PDF («Ver socioeconómico» / «Ver video») y decide Viable o No viable (si no es viable, motivo obligatorio).
5. **Abrir las evidencias** — En la ficha, clic en cada archivo: imagen y PDF se ven en línea; el video se reproduce dentro de PEOPLE.

#### Qué pasa después

- Revisado viable → «Validación de referencias».
- Revisado no viable → «Rechazado».

#### Errores comunes

- **«El video no sube.»** Usa MP4 o MOV y revisa el tamaño máximo permitido.

#### En el sistema

- **Ir a Candidatos** → `/rh/candidatos` _(permiso: candidatos.ver)_
- Guía en pantalla → `/ayuda/articulo/socioeconomico`

<a id="articulo-referencias"></a>

### Validación de referencias

> Se registra al menos una referencia laboral validada; al concluir, el candidato pasa directo a la autorización de RH.

#### Qué es

Es la verificación de referencias laborales del candidato (empresa, contacto, resultado).

#### Quién lo hace

RH, Gerente.

#### Pasos

1. **«Registrar referencia»** — Empresa, contacto, teléfono, relación o puesto, resultado y observaciones. Repite por cada referencia.
2. **«Concluir validación de referencias»** — Indica si las referencias son favorables. Se exige al menos una registrada.

#### Qué pasa después

- Favorables → «Autorización de RH».
- No favorables → «Rechazado» con motivo.

#### Errores comunes

- **«Registra al menos una referencia laboral validada antes de continuar.»** La ficha lo avisa antes de concluir. Captura una referencia con «Registrar referencia» (botón en el mismo aviso) y vuelve a concluir.

#### En el sistema

- **Ir a Candidatos** → `/rh/candidatos` _(permiso: candidatos.ver)_
- Guía en pantalla → `/ayuda/articulo/referencias`

<a id="articulo-intervencion"></a>

### Intervención sobre un rechazo de RH

> Solo si el gerente la pide: Regional (gestor o volante) o Dirección Comercial (puestos superiores) deciden.

#### Qué es

Es una excepción. Cuando RH rechaza a un candidato, el gerente que lo entrevistó puede aceptar el rechazo o pedir que se reconsidere. Nunca es automático.

#### Quién lo hace

Solicita: el gerente que entrevistó. Decide: el Gerente Regional de la sucursal si el puesto es Gestor o Gestor Volante; Dirección Comercial para cualquier puesto superior.

#### Pasos

1. **«Solicitar intervención»** _(Gerente)_ — En la ficha, el gerente explica por qué debería reconsiderarse (motivo obligatorio).
2. **Decisión** _(Regional)_ — Quien corresponde ve «Aprobar intervención» o «Confirmar rechazo», con comentario.

#### Qué pasa después

- Aprobada → el candidato pasa a «Autorizado por RH» y puede contratarse (nunca se le inventa una vacante).
- Rechazo confirmado → queda rechazado.
- La decisión es definitiva y queda en la ficha.

#### Errores comunes

- **«No veo Solicitar intervención.»** Solo aparece al gerente con permiso de evaluar y si no hay ya una intervención pendiente.

#### En el sistema

- **Ir a Candidatos** → `/rh/candidatos` _(permiso: candidatos.ver)_
- Guía en pantalla → `/ayuda/articulo/intervencion`

## 4. Expediente

<a id="articulo-expediente"></a>

### El expediente del colaborador

> La carpeta digital de la persona, organizada en pestañas. Nada se borra nunca.

#### Qué es

Es todo lo que RH guarda de una persona: datos, documentos, cuenta, onboarding, avisos, vacaciones, recibos, préstamos, solicitudes e historial.

#### Quién lo hace

RH ve todos los expedientes; gerencias, los de su sucursal; el colaborador (incluidas las coordinadoras), solo el suyo (Mi expediente).

#### Pasos

1. **Busca a la persona** _(Web)_ — Expedientes → busca por nombre o número de empleado.
2. **Revisa las pestañas** — Resumen · Datos personales · Datos laborales · Cuenta · Documentos · Onboarding · Avisos / Comunicaciones · Vacaciones · Recibos de nómina · Préstamos · Solicitudes · Historial RH.
3. **Documentos del proceso** — Debajo están los documentos generados por PEOPLE (contratos, permisos, préstamo, baja) con su estado de firma y del original físico.

#### Qué pasa después

- El colaborador ve su propio expediente en Mi expediente (sin la pestaña Cuenta ni Onboarding).

#### Errores comunes

- **«Quiero corregir mi propio expediente y no puedo.»** Nadie edita su propio expediente, ni RH. Usa «Solicitar corrección»: otra persona de RH lo autoriza.
- **«Di de baja a alguien y no desaparece.»** Es correcto: la baja bloquea el acceso y conserva todo el historial para un posible reingreso.

#### En el sistema

- **Ir a Expedientes** → `/rh/expedientes` _(permiso: expedientes.ver_todos o expedientes.ver_sucursal)_
- **Ir a Mi expediente** → `/mi-expediente`
- Guía en pantalla → `/ayuda/articulo/expediente`

## 5. Contratos

<a id="articulo-firma-y-original"></a>

### Firma de contratos y control del original

> Cada documento sigue su ruta: imprimir → firma (y huella) → envío a corporativo → recepción → subir firmado → archivar.

#### Qué es

Es el control del papel. PEOPLE sabe en todo momento dónde está el original firmado de cada contrato.

#### Quién lo hace

El gerente imprime, recaba firma y huella y envía; RH recibe, escanea y archiva.

#### Pasos

1. **«Imprimir»** _(Gerente)_ — Se marca como impreso.
2. **«Registrar firma»** _(Gerente)_ — Fecha de firma con huella y testigos cuando el formato lo pide.
3. **«Enviar original a corporativo»** _(Gerente)_ — Paquetería, guía, fecha y comprobante.
4. **«Registrar recepción en corporativo»** _(RH)_ — Fecha de recepción.
5. **«Subir documento firmado»** _(RH)_ — El escaneo queda en el expediente.
6. **«Archivar»** _(RH)_ — Cierra la ruta del original.

#### Qué pasa después

- Con el paquete firmado, el candidato pasa a «Contratado» y empieza el onboarding.
- Las fechas futuras se rechazan: cada paso pide su dato real.

#### Errores comunes

- **«No me deja archivar.»** Primero sube el documento firmado (escaneo).

#### En el sistema

- **Ir a Mis pendientes** → `/rh/pendientes`
- Guía en pantalla → `/ayuda/articulo/firma-y-original`

## 6. Onboarding

<a id="articulo-onboarding"></a>

### Onboarding: la bienvenida del colaborador

> Lecciones de bienvenida con evaluación (mínimo 8) y entrega del equipo de trabajo. Al cerrarlo, la persona queda activa.

#### Qué es

Onboarding es la bienvenida estructurada de una persona nueva: conoce la empresa (inducción institucional), aprende su puesto (inducción al puesto) y recibe su equipo (uniforme, credencial, etc.) con su carta responsiva. El colaborador nunca ve la palabra «onboarding»: para él son «lecciones de bienvenida».

#### Quién lo hace

El colaborador responde las lecciones; RH da retroalimentación si no aprueba; el gerente (o RH) registra la entrega de equipo; RH cierra el onboarding.

> **Nota:** Ejemplo de ruta: 01 Bienvenida a MR. LANA (video) · 02 Políticas de crédito (documento) · 03 Evaluación (cuestionario). Las lecciones de puesto solo aparecen a quien ocupa ese puesto.

#### Pasos

1. **Lecciones de bienvenida** _(Colaborador · Web y app)_ — App → «Lecciones de bienvenida» (o Mi portal → «Lo que necesitas hacer»). Cada lección tiene su material (video o documento por liga), texto e instrucciones y una evaluación.
2. **Avance que ve el colaborador** _(Colaborador)_ — «Se habilita después» (todavía bloqueada) → «Lista para responder» → «Aprobada». Si no alcanza la calificación: «Recursos Humanos te dejará un comentario».
3. **Si no aprueba (menos de 8)** _(RH)_ — RH escribe la retroalimentación y habilita la reevaluación. La persona vuelve a intentarlo.
4. **Entrega de equipo** _(Gerente)_ — En el Ciclo laboral de la persona, «Registrar entrega de activos»: qué se entregó; se genera la carta responsiva.
5. **«Cerrar onboarding»** _(RH)_ — Con lecciones aprobadas y equipo entregado, RH lo cierra. En ese momento la persona queda ACTIVA.

#### Qué pasa después

- Alta real: avisos a RH y Sistemas y bienvenida al colaborador.
- Empieza a correr el periodo de capacitación inicial hasta su evaluación.

#### Errores comunes

- **«No me deja cerrar el onboarding.»** PEOPLE dice qué falta: alguna lección sin aprobar o equipo sin entregar.

#### En el sistema

- **Ir a Onboarding** → `/rh/onboarding/configuracion` _(permiso: onboarding.gestionar)_
- **Ir a Mi portal** → `/mi-portal`
- Guía en pantalla → `/ayuda/articulo/onboarding`

## 7. Evaluación inicial

<a id="articulo-evaluacion-capacitacion"></a>

### Evaluación de capacitación inicial

> 15 días antes de que venza la capacitación, PEOPLE abre la evaluación al jefe; él califica y recomienda; RH autoriza.

#### Qué es

Es la evaluación con la que se decide si la persona sigue (contrato por tiempo indeterminado) o no. Usa los 7 criterios del formato oficial de Jurídico.

#### Quién lo hace

Evalúa el jefe inmediato (normalmente el gerente de la sucursal); autoriza RH.

#### Pasos

1. **PEOPLE la abre sola** _(Automático)_ — Cada mañana revisa los vencimientos. 15 días antes del fin (por ejemplo, un Gestor con 2 meses se avisa al mes y medio) crea la evaluación y avisa.
2. **El jefe evalúa** _(Gerente · Web y app)_ — Desarrollo → Evaluación de capacitación inicial → la persona → «Evaluar periodo de prueba». Califica cada criterio de 0 a 10 con comentario, elige «Recomiendo renovar» o «No renovar» y escribe observaciones.
3. **RH autoriza** _(RH)_ — «Autorización final de RH»: confirma renovar o no renovar. Si decide distinto a lo que recomendó el jefe, el comentario es obligatorio. También puede devolverla para corrección.

#### Qué pasa después

- Renovar → PEOPLE prepara el contrato por tiempo indeterminado.
- No renovar → se abre el cierre laboral por «No renovación del periodo de prueba», ya autorizado, con fecha efectiva al fin del contrato.

#### Errores comunes

- **«No veo la evaluación todavía.»** Se habilita 15 días antes del vencimiento. El ciclo laboral muestra cuántos días faltan.
- **«RH no puede autorizar.»** Solo se autoriza una evaluación capturada por el jefe.

#### En el sistema

- **Ir a Evaluaciones** → `/rh/evaluaciones` _(permiso: evaluaciones.capturar o evaluaciones.autorizar)_
- Guía en pantalla → `/ayuda/articulo/evaluacion-capacitacion`

<a id="articulo-contrato-indeterminado"></a>

### Contrato por tiempo indeterminado

> Con la evaluación autorizada para renovar, PEOPLE prepara el contrato indeterminado. Pulsar dos veces no lo duplica.

#### Qué es

Es el contrato que sigue a una capacitación inicial aprobada. Empieza el día siguiente al fin del contrato anterior.

#### Quién lo hace

RH, Gerente.

> **Nota:** Doble clic seguro: la autorización solo procede sobre una evaluación «capturada» y la renovación solo sobre un contrato «vigente». Un segundo clic recibe un aviso y no crea otro contrato.

#### Pasos

1. **PEOPLE lo prepara** _(Automático)_ — El contrato de capacitación queda «Renovado» y se crea el nuevo, por tiempo indeterminado, con sus documentos.
2. **Previsualiza e imprime** _(Gerente)_ — En el Ciclo laboral de la persona, los documentos del contrato: vista previa, imprimir y la ruta de firma del original.

#### Qué pasa después

- La persona queda «Activo · periodo de prueba superado».

#### Errores comunes

- **«Solo un contrato vigente puede renovarse.»** Ya se renovó: revisa los documentos del ciclo laboral.

#### En el sistema

- **Ir a Evaluaciones** → `/rh/evaluaciones` _(permiso: evaluaciones.capturar o evaluaciones.autorizar)_
- Guía en pantalla → `/ayuda/articulo/contrato-indeterminado`

## 8. Solicitudes

<a id="articulo-permisos"></a>

### Pedir y autorizar un permiso

> Faltar, salir temprano o llegar tarde, con o sin goce o especial. Gerente y regional dan visto bueno; RH autoriza y el formato queda listo para imprimir.

#### Qué es

Es la solicitud de permiso del colaborador. Usa el Formato de permiso oficial de MR. LANA, que PEOPLE llena cuando RH lo autoriza.

#### Quién lo hace

Colaborador, Gerente, Regional, RH.

> **Importante:** El formato de permiso solo se puede imprimir cuando RH ya lo autorizó. Antes de eso el gerente no puede imprimirlo.

> **Nota:** Quien da un visto bueno no puede dar la autorización final. Un nivel sin persona asignada se salta (queda registrado) y, si no hay ninguno, la solicitud va directo a RH.

#### Pasos

1. **Elige el permiso** _(Colaborador · Web y app)_ — Mis solicitudes → «Nueva solicitud» → Permiso. Elige: permiso para faltar, para salir temprano o para llegar tarde.
2. **Llena el formulario** _(Colaborador)_ — Fecha del permiso; días (si vas a faltar), hora de salida o de entrada; tipo: con goce de sueldo, sin goce de sueldo o permiso especial. Si es especial, la causal: paternidad, luto, lactancia, cumpleaños o productividad. Observaciones y evidencia (opcional).
3. **Visto bueno del gerente** _(Gerente)_ — El gerente de tu sucursal (o tu jefe directo si no hay gerente).
4. **Visto bueno del regional** _(Regional)_ — El regional de tu región.
5. **RH autoriza** _(RH)_ — Con ambos vistos buenos la solicitud pasa sola a «Pendiente de autorizar». RH la aprueba o rechaza.
6. **Documento listo** _(Gerente)_ — Ya autorizado, el formato de permiso se genera para imprimir y firmar (sección «Formato de permiso» de la solicitud).

#### Qué pasa después

- El colaborador ve el estado en su app; el permiso queda en la pestaña Solicitudes de su expediente.

#### Errores comunes

- **¿Por qué no puedo imprimir un permiso?** Todavía no lo autoriza RH, o falta el formato oficial cargado en Documentos maestros.
- **«Mi solicitud sigue en Recibida.»** Falta el visto bueno del gerente o del regional; la «Cadena de autorización» del detalle dice cuál.

#### En el sistema

- **Ir a Mis solicitudes** → `/solicitudes` _(permiso: portal.solicitudes.ver o solicitudes.crear)_
- **Ir a Solicitudes (RH)** → `/rh/solicitudes` _(permiso: solicitudes.revisar o solicitudes.aprobar)_
- Guía en pantalla → `/ayuda/articulo/permisos`

<a id="articulo-vacaciones"></a>

### Vacaciones

> Eliges los días en el calendario; el domingo no cuenta y el sábado sí. Ves tu saldo antes y después de pedir.

#### Qué es

Es la solicitud de días de vacaciones del saldo que te corresponde.

#### Quién lo hace

Colaborador, Gerente, Regional, RH.

#### Pasos

1. **Elige los días** _(Colaborador · Web y app)_ — Nueva solicitud → Vacaciones (o app → Vacaciones). Toca cada día en el calendario (hasta 60). El domingo no se puede elegir; el sábado sí cuenta.
2. **Revisa tu saldo** _(Colaborador)_ — Arriba ves: saldo antes → días elegidos → saldo después («Te quedarían N días»).
3. **Vistos buenos y autorización** — Igual que un permiso: gerente → regional → RH autoriza.

#### Qué pasa después

- Al autorizar se descuentan los días del saldo.

#### Errores comunes

- **«No me deja elegir el domingo.»** Es correcto: el domingo no cuenta como día de vacaciones.
- **«El saldo después sale en rojo.»** Estás pidiendo más días de los que tienes.

#### En el sistema

- **Ir a Mis solicitudes** → `/solicitudes` _(permiso: portal.solicitudes.ver o solicitudes.crear)_
- Guía en pantalla → `/ayuda/articulo/vacaciones`

<a id="articulo-prestamos"></a>

### Préstamos personales

> El colaborador pide monto y motivo; RH autoriza monto y plazo; se generan contrato, pagaré y carta de consentimiento; RH confirma la entrega.

#### Qué es

Es el préstamo interno al colaborador. PEOPLE lleva su saldo de forma administrativa; NO aplica descuentos en la nómina ni se conecta con el sistema de nómina.

#### Quién lo hace

Colaborador, Gerente, Regional, RH.

#### Pasos

1. **Solicita** _(Colaborador · Web y app)_ — App → Préstamos → «Solicitar»: monto y motivo. (En el portal web también puedes proponer el plazo.)
2. **Vistos buenos** — Gerente → regional.
3. **RH autoriza** _(RH)_ — Monto autorizado, plazo, periodicidad y pago programado (o rechaza).
4. **«Generar documentos del préstamo»** _(RH)_ — Carta de consentimiento y retención, contrato de préstamo y pagaré, llenados con los datos del expediente. Se imprimen y firman.
5. **Confirmar entrega** _(RH)_ — En el expediente → Préstamos: fecha de otorgamiento, fecha del primer descuento, periodicidad y pago programado. Desde ahí cuenta el saldo.
6. **Registrar pagos** _(RH)_ — Cada pago se registra en el préstamo y el saldo se actualiza.

#### Qué pasa después

- El colaborador ve su préstamo, saldo y movimientos en la app (Préstamos).

#### Errores comunes

- **«El saldo no baja solo.»** PEOPLE no descuenta de nómina: RH registra cada pago.

#### En el sistema

- **Ir a Mis solicitudes** → `/solicitudes` _(permiso: portal.solicitudes.ver o solicitudes.crear)_
- Guía en pantalla → `/ayuda/articulo/prestamos`

<a id="articulo-revisar-solicitudes"></a>

### Revisar solicitudes (gerente, regional y RH)

> Gerente → regional dan visto bueno; RH autoriza, rechaza o pide corrección. El tablero por tipo muestra qué falta.

#### Qué es

Es la bandeja donde se atienden las solicitudes de los colaboradores.

#### Quién lo hace

Gerente, Regional, RH.

> **Nota:** Columnas: Solicitudes recibidas (esperan vistos buenos) → Pendiente de autorizar → Requiere corrección → Aprobadas / No aprobadas. Una solicitud aprobada ya terminó: lo que sigue (documentos, pago) vive en su propio proceso.

#### Pasos

1. **Elige el tipo** _(Web)_ — Personal → Solicitudes: una tarjeta por tipo (préstamos, permisos, vacaciones…) con recibidas, por autorizar y en corrección. «Ver todas» abre el tablero completo.
2. **Abre la solicitud** — Revisa motivo, evidencias y la «Cadena de autorización» (quién ya dio visto bueno).
3. **Gerente / regional** _(Gerente · Web y app)_ — «Visto bueno» (web) o desde la app (Equipo → pendientes).
4. **RH** _(RH)_ — Aprobar, rechazar o requerir corrección (las dos últimas exigen comentario).

#### Qué pasa después

- El colaborador recibe cada cambio de estado; lo autorizado queda en su expediente.

#### Errores comunes

- **«No puedo autorizar.»** Faltan vistos buenos, o tú diste uno (quien da visto bueno no autoriza).
- **«No puedo rechazar.»** Escribe un comentario: es obligatorio.

#### En el sistema

- **Ir a Solicitudes** → `/rh/solicitudes` _(permiso: solicitudes.revisar o solicitudes.aprobar)_
- Guía en pantalla → `/ayuda/articulo/revisar-solicitudes`

## 11. Vida laboral

<a id="articulo-directorio"></a>

### Directorio y correo corporativo

> Todos ven nombre, puesto, área y sucursal de las personas activas. El correo y teléfono corporativos dependen de tu rol: un colaborador operativo ve los de su jefe directo; el personal corporativo autorizado, RH y Sistemas ven todos. Nunca datos personales.

#### Qué es

El directorio muestra nombre, puesto, área y sucursal. El contacto corporativo (correo y teléfono) se muestra según el permiso «Ver contactos de todo el directorio»; sin él, solo el de tu jefe directo. El correo corporativo es distinto del correo con el que la persona inicia sesión.

#### Quién lo hace

Colaborador, Gerente, Regional, Dirección Comercial, Sistemas, RH.

#### Pasos

1. **Buscar** _(Web y app)_ — Directorio (web o app): por nombre o número de empleado, o filtra por puesto, sucursal o departamento.
2. **Contactar** _(App)_ — En la app, toca el correo o el teléfono para escribir o llamar.

#### Errores comunes

- **«Alguien no tiene correo en el directorio.»** No lo tiene capturado: PEOPLE nunca inventa uno ni usa su correo de acceso o personal. RH lo captura en su expediente.

#### En el sistema

- **Ir al Directorio** → `/directorio`
- Guía en pantalla → `/ayuda/articulo/directorio`

<a id="articulo-actas"></a>

### Acta administrativa circunstanciada

> El gerente o RH levanta el acta administrativa circunstanciada sobre alguien de su alcance en seis pasos, revisa la vista previa del PDF real y genera el acta definitiva, que queda congelada en el expediente.

#### Qué es

Un documento administrativo factual con lo que pasó: tipo de incidencia, hechos, personas que intervienen, manifestaciones, anexos y firmas. Registra hechos, no califica ni sanciona. Es el único tipo de acta que se levanta.

#### Quién lo hace

Gerente o subgerente: personas de su sucursal. RH y corporativo con alcance global: toda la organización.

#### Pasos

1. **1. Incidencia y 2. Hechos** _(Web)_ — Actas → «Levantar acta»: elige a la persona, el tipo de incidencia, cuándo y dónde ocurrió, y narra los hechos en orden. Empresa, centro de trabajo, domicilio y datos del trabajador los pone PEOPLE. Al continuar se guarda el borrador.
2. **3. Participantes** _(Web)_ — Tu carácter (RH, gerente, jefe directo…), si participa el jefe inmediato, y de 0 a 2 testigos de hechos y de 0 a 2 testigos de asistencia, todos activos y elegidos del buscador. Una persona interviene una sola vez y el trabajador nunca es testigo.
3. **4. Manifestaciones y 5. Anexos** _(Web)_ — Captura lo que dijo el trabajador o marca «No quiso manifestar»; cada testigo da la suya. Sube cada anexo con su descripción, fecha o identificación y su relación con los hechos.
4. **6. Revisión: vista previa y acta definitiva** _(Web)_ — «Vista previa PDF» abre el PDF real sin guardar nada. «Generar acta definitiva» lo congela en el expediente; desde ahí ya no se edita.
5. **Imprimir, firmar y entregar copia** — Se imprime a escala 100 %, firman a mano quienes intervinieron y se registra la entrega de la copia (o por qué no fue posible). Si el trabajador no firma, se registra la negativa: no implica aceptación.

#### Qué pasa después

- El acta queda en el expediente de la persona con su historial.

#### Errores comunes

- **«No me deja guardar los testigos.»** Cada testigo debe estar activo, no puede ser el trabajador del acta y una persona solo interviene una vez (jefe, testigo de hechos o de asistencia).
- **«La vista previa no se abre.»** Falta algo indispensable: el paso 6 lista qué (tipo de incidencia, fecha de los hechos, hechos, tu carácter o la manifestación del trabajador).

#### En el sistema

- **Ir a Actas** → `/rh/actas` _(permiso: actas.ver)_
- Guía en pantalla → `/ayuda/articulo/actas`

## 12. Baja y finiquito

<a id="articulo-baja"></a>

### Baja de un colaborador (cierre laboral)

> El jefe directo (o RH) inicia la baja desde el colaborador y queda aplicada de inmediato: sin solicitud previa, sin autorización de RH y sin subir archivos. Al iniciarla se suspende el acceso; siguen los documentos de la baja y el finiquito.

#### Qué es

Es el cierre de la relación laboral. Nunca borra a la persona: bloquea su acceso y conserva expediente, contratos, evaluaciones y documentos para un posible reingreso.

#### Quién lo hace

Inicia el jefe directo de la persona (su cadena de mando real) o RH; nadie más puede iniciarla y no necesita autorización. Después se generan los documentos, RH calcula el finiquito y, al registrar su entrega, el cierre queda completo.

> **Importante:** Al INICIAR la baja se suspende de inmediato el acceso de la persona a la app y al portal. Si RH cancela el cierre, el acceso vuelve solo. Su estatus laboral (inactivo, vacante) se ejecuta al concluir el cierre.

#### Pasos

1. **«Iniciar baja»** _(Gerente)_ — Expedientes → la persona de tu equipo → Ciclo laboral → «Iniciar baja»: causa (renuncia, no renovación, bajo desempeño, baja inmediata…), motivo y fecha efectiva. No se sube ningún archivo. Al confirmar, la baja queda aplicada (sin autorización de RH) y el sistema prepara los documentos de la causa.
2. **Renuncia / aviso en «Documentos de la baja»** _(RH)_ — El sistema genera la renuncia (o el aviso de terminación): «Imprimir» y, ya firmada en papel, «Marcar como firmado físicamente». No se sube ningún archivo. Si la persona se niega a firmar, «El colaborador se negó a firmar/recibir».
3. **«Calcular finiquito»** _(RH)_ — Muestra al momento el desglose por concepto; «Ver / editar finiquito» para ajustar montos con motivo (ver el artículo de finiquito). No hay que autorizarlo.
4. **«Registrar entrega de finiquito»** _(RH)_ — Desde la fecha efectiva: fecha de entrega, forma de pago y comentario. El finiquito se congela, la baja se ejecuta y el cierre laboral queda completo.

#### Qué pasa después

- Baja ejecutada → aviso a RH y a Sistemas: «Retira accesos, cuentas y equipo».
- La vacante de esa sucursal y puesto se abre sola.
- La persona queda disponible para un posible reingreso.

#### Errores comunes

- **«No me deja concluir.»** El cierre se completa al registrar la entrega del finiquito, que solo se permite desde la fecha efectiva.
- **«¿Cómo hago una baja?»** Desde el Ciclo laboral de la persona. Expedientes → la persona → Ciclo laboral → «Iniciar baja». No se pide desde Solicitudes.

#### En el sistema

- **Ir a Expedientes** → `/rh/expedientes` _(permiso: expedientes.ver_todos o expedientes.ver_sucursal)_
- Guía en pantalla → `/ayuda/articulo/baja`

## 13. Reingresos

<a id="articulo-reingreso"></a>

### Reingreso de un excolaborador

> Nunca se crea otra persona: se busca a la anterior, RH decide y se reactiva con su mismo historial en un ciclo laboral nuevo.

#### Qué es

Es volver a contratar a alguien que ya trabajó en MR. LANA. Se conserva su cuenta, su expediente y toda su vida laboral anterior.

#### Quién lo hace

Gerente, RH.

#### Pasos

1. **Busca a la persona** _(Web y app)_ — Desarrollo → Reingresos: por número de empleado, CURP, RFC, NSS o nombre. Ves su causa de salida antes de solicitar.
2. **Solicita el reingreso** _(Gerente)_ — Quien solicita propone (gerente o RH).
3. **RH decide** _(RH)_ — «Decidir reingreso»: viable o no viable (con motivo).
4. **Nuevo ciclo** — Si es viable: se reactiva la MISMA persona, se piden solo documentos vencidos o faltantes, nuevo contrato, firmas y onboarding aplicable, hasta quedar activa.

#### Qué pasa después

- La vida laboral anterior (cierres, contratos, evaluaciones, documentos) se conserva intacta.

#### Errores comunes

- **«Una persona activa no puede reingresar.»** Revisa que la baja esté concluida.
- **«Ya existe una cuenta con ese correo.»** Es un reingreso: búscala en Reingresos en lugar de registrarla como candidato nuevo.

#### En el sistema

- **Ir a Reingresos** → `/rh/reingresos` _(permiso: reingresos.solicitar o reingresos.gestionar)_
- Guía en pantalla → `/ayuda/articulo/reingreso`

## 14. Administración

<a id="articulo-mis-pendientes"></a>

### Mis pendientes: tu bandeja de trabajo

> Todo lo que alguien espera que hagas (entrevistar, autorizar, evaluar, entregar equipo, firmar) en un solo lugar.

#### Qué es

Es la bandeja de tareas del ciclo laboral. Cada pendiente es una acción concreta; al resolverla en su pantalla desaparece sola.

#### Quién lo hace

RH, Gerente, Regional.

#### Pasos

1. **Abre Mis pendientes** _(Web y app)_ — Inicio → Mis pendientes (o la pestaña Pendientes de la app).
2. **Atiende lo urgente** — Filtra por etapa, sucursal o prioridad; primero lo vencido.
3. **Abre el pendiente** — Te lleva directo a la ficha del candidato o al ciclo laboral de la persona, donde está el botón de la acción.

#### Qué pasa después

- La campana de notificaciones también te avisa de cada pendiente nuevo.

#### Errores comunes

- **«Un pendiente no es mío.»** Los pendientes se asignan por el organigrama (jefe directo) y por permisos. Si el jefe de alguien está mal, se corrige en el organigrama, no a mano.

#### En el sistema

- **Ir a Mis pendientes** → `/rh/pendientes`
- Guía en pantalla → `/ayuda/articulo/mis-pendientes`

<a id="articulo-directorio"></a>

### Directorio y correo corporativo

> Todos ven nombre, puesto, área y sucursal de las personas activas. El correo y teléfono corporativos dependen de tu rol: un colaborador operativo ve los de su jefe directo; el personal corporativo autorizado, RH y Sistemas ven todos. Nunca datos personales.

#### Qué es

El directorio muestra nombre, puesto, área y sucursal. El contacto corporativo (correo y teléfono) se muestra según el permiso «Ver contactos de todo el directorio»; sin él, solo el de tu jefe directo. El correo corporativo es distinto del correo con el que la persona inicia sesión.

#### Quién lo hace

Colaborador, Gerente, Regional, Dirección Comercial, Sistemas, RH.

#### Pasos

1. **Buscar** _(Web y app)_ — Directorio (web o app): por nombre o número de empleado, o filtra por puesto, sucursal o departamento.
2. **Contactar** _(App)_ — En la app, toca el correo o el teléfono para escribir o llamar.

#### Errores comunes

- **«Alguien no tiene correo en el directorio.»** No lo tiene capturado: PEOPLE nunca inventa uno ni usa su correo de acceso o personal. RH lo captura en su expediente.

#### En el sistema

- **Ir al Directorio** → `/directorio`
- Guía en pantalla → `/ayuda/articulo/directorio`

<a id="articulo-historial-persona"></a>

### Historial (timeline) de una persona

> Una sola línea de tiempo de candidato a excolaborador: contratación, inducción, salarios, vacaciones, solicitudes, periodo de prueba, baja y reingreso.

#### Qué es

El historial no es un registro aparte: lee lo que ya existe (bitácora, seguimientos, salarios, ajustes de vacaciones y solicitudes) y lo ordena por fecha.

#### Quién lo hace

RH, Gerente.

#### Pasos

1. **Abrir el ciclo laboral de la persona** _(Web y app)_ — Desde su expediente o Mis pendientes → «Ciclo laboral»; en la app, la ficha de onboarding de la persona.
