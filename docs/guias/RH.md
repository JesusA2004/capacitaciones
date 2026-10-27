<!-- Archivo GENERADO por `npm run guia:docs` desde resources/js/lib/guia. No lo edites a mano: cambia el contenido allá y vuelve a generar. -->

# Manual operativo de Recursos Humanos

De la plantilla autorizada a la baja y el reingreso: todo lo que hace Reclutamiento / RH en MR. LANA PEOPLE, paso por paso y en el orden del proceso. Para el flujo completo con todos los roles, ver [FLUJO_COMPLETO.md](FLUJO_COMPLETO.md).

## Reclutamiento / RH

Lleva el proceso de punta a punta: vacantes, campañas, candidatos, autorizaciones, contratación, expedientes, contratos, nómina y bajas. Gerencia de RH (rh_admin) además autoriza y configura; el auxiliar opera sin decisiones finales.

**Dónde trabaja:** Web (principal) y app en modo Gestión RH.

**Roles del sistema:** `rh_admin`, `rh_auxiliar`

### Tu checklist

- [ ] Revisar vacantes reales → [De dónde nace una vacante](#articulo-plantilla-y-vacantes)
- [ ] Crear campañas sobre vacantes reales → [Crear una campaña de reclutamiento](#articulo-campanas)
- [ ] Registrar candidatos → [Registrar un candidato](#articulo-registrar-candidato)
- [ ] Filtro RH y psicométricos → [Psicométricos](#articulo-psicometricos)
- [ ] Autorización final de candidatos → [Autorización de RH](#articulo-autorizacion-rh)
- [ ] Generar el QR de contratación → [Iniciar la contratación (QR de contratación)](#articulo-iniciar-contratacion)
- [ ] Revisar documentos y datos del alta → [Documentos del alta y revisión de RH](#articulo-documentos-de-alta)
- [ ] Expedientes y datos faltantes → [Qué le falta a un expediente (y cómo avisar)](#articulo-completitud-expediente)
- [ ] Generar contratos → [Contrato de capacitación inicial](#articulo-contrato-inicial)
- [ ] Cerrar onboarding (alta real) → [Onboarding: la bienvenida del colaborador](#articulo-onboarding)
- [ ] Autorizar evaluaciones → [Evaluaciones de periodo de prueba](#articulo-evaluacion-capacitacion)
- [ ] Autorizar solicitudes → [Revisar solicitudes (gerente, regional y RH)](#articulo-revisar-solicitudes)
- [ ] Preparar y emitir nómina → [Recibos de nómina: preparar, revisar y emitir](#articulo-nomina-lotes)
- [ ] Enviar avisos → [Avisos de Recursos Humanos](#articulo-avisos)
- [ ] Bajas y finiquitos → [Baja de un colaborador (forma de salida y causal)](#articulo-baja)
- [ ] Reingresos → [Reingreso de un excolaborador](#articulo-reingreso)

### Lo que NO le toca

- Decidir intervenciones sobre sus propios rechazos (eso es del Regional o de Dirección Comercial).
- Editar su propio expediente.

---

## 1. Reclutamiento

<a id="articulo-plantilla-y-vacantes"></a>

### De dónde nace una vacante

> Una vacante no se captura: PEOPLE la calcula restando a la plantilla autorizada las personas que hoy ocupan el puesto.

#### Qué es

Una vacante es una plaza autorizada que hoy no tiene titular. Nadie escribe «vacante abierta»: PEOPLE compara, para cada sucursal y puesto, la plantilla autorizada contra los colaboradores vigentes que ocupan ese puesto, y lo que falta es la vacante real.

#### Quién lo hace

Gerencia de RH captura la plantilla autorizada (Organización → Sucursales). Todos los demás solo consultan Vacantes: la pantalla es informativa.

> **Importante:** Una sola fuente para todos: Vacantes, Campañas, Candidatos y la contratación consultan el mismo cálculo (plantilla autorizada − ocupados). No existen listas de vacantes paralelas.

#### Antes de empezar

- La sucursal y el puesto deben existir en Administración.
- La plantilla autorizada de ese puesto en esa sucursal debe estar capturada (viene del Excel de headcount de Dirección y Gerencia de RH la mantiene).

#### Pasos

1. **Plantilla autorizada** — Es cuántas plazas de un puesto autorizó Dirección en una sucursal. Ejemplo: Sucursal Tlaxcala, Gestor, plantilla autorizada 5.
2. **Ocupados** — PEOPLE cuenta en vivo a los colaboradores vigentes (activos o en incorporación) titulares de ese puesto en esa sucursal. El Gestor Volante cuenta como Gestor. Ejemplo: 4 gestores.
3. **Faltantes = vacantes reales** — Plantilla autorizada − ocupados, nunca menos de cero. Ejemplo: 5 − 4 = 1 vacante real de Gestor en Tlaxcala.
4. **Qué ve RH en Vacantes** _(Web)_ — Arriba, cuántas plazas faltan de cada puesto y en qué sucursales (un clic filtra). Debajo, una fila por vacante: puesto, sucursal, plazas que faltan, días abierta, candidatos que lleva y el contexto «4 de 5 ocupadas».

#### Qué pasa después

- Si alguien se da de baja, cambia de puesto o de sucursal, la vacante del lugar que deja se abre sola.
- Cuando alguien entra a esa plaza (contratación), la vacante baja o se cierra sola.
- Campañas, Candidatos y Contratación usan esta misma cifra: nadie puede publicar, registrar o contratar sobre una plaza que ya no falta.

#### Qué queda registrado

- Cada cambio de plantilla autorizada queda en el historial de la sucursal: quién, cuándo, antes → después y motivo.

#### Errores comunes

- **«No veo una vacante que debería existir.»** Revisa la plantilla autorizada de ese puesto en Organización → Sucursales → detalle. Si la plantilla ya está completa, no hay vacante. Si la sucursal no es de tu alcance, tampoco la verás.
- **«Quiero crear una vacante a mano.»** No se puede y no hace falta: Vacantes es 100 % derivada. Si Dirección autorizó una plaza nueva, Gerencia de RH la agrega a la plantilla y la vacante aparece.
- **«Alguien cubre temporalmente la plaza y la vacante sigue abierta.»** Es correcto: una cobertura temporal no cuenta como ocupado. La plaza sigue sin titular.

#### Relacionado con

- [Crear una campaña de reclutamiento](#articulo-campanas)
- [Registrar un candidato](#articulo-registrar-candidato)
- [Estructura, plantilla autorizada y duración por puesto](#articulo-estructura-y-plantilla)

#### En el sistema

- **Ir a Vacantes** → `/rh/vacantes` _(permiso: vacantes.ver)_
- **Ir a Sucursales** → `/administracion/sucursales` _(permiso: sucursales.administrar)_
- Guía en pantalla → `/ayuda/articulo/plantilla-y-vacantes`

<a id="articulo-campanas"></a>

### Crear una campaña de reclutamiento

> Una campaña siempre parte de una vacante real: registra qué se publicó, cuánto costó y qué produjo.

#### Qué es

La campaña es el registro de una publicación (Meta Ads, Indeed, Computrabajo, LinkedIn, referidos u otros) para cubrir una vacante. Sirve para saber cuánto cuesta conseguir un candidato y una contratación.

#### Quién lo hace

Reclutamiento / RH (permiso para administrar campañas). Gerencias no capturan campañas.

#### Antes de empezar

- Debe existir una vacante real con plaza disponible. Si la lista de vacantes del formulario está vacía, no hay plazas faltantes.
- Ten a la mano el texto del anuncio, la liga y el reporte o factura del proveedor.

#### Pasos

1. **Abre Campañas** _(Web)_ — Reclutamiento → Campañas → «Nueva campaña».
2. **Elige la vacante real** — Por ejemplo «Gestor — Tlaxcala». PEOPLE toma de ella la empresa, la sucursal, el departamento y el puesto: no se vuelven a capturar.
3. **Captura la campaña** — Canal, responsable, inicio y fin (opcional), presupuesto, gasto real, sueldo publicado, nombre (opcional), texto del anuncio (copy), liga del anuncio, impresiones y clics (opcionales, del reporte del proveedor), candidatos generados (opcional) y observaciones.
4. **Adjunta la evidencia** — Arte y documentos en PDF o imagen: PDF de Meta Ads, factura, captura o reporte del proveedor. Los archivos son privados y se abren desde la propia campaña.
5. **Guarda** — La campaña aparece como tarjeta con su embudo y sus costos.

#### Lo que se captura

- **Vacante y canal:** Vacante real · Canal · Responsable
- **Fechas y dinero:** Inicio · Fin (opcional) · Presupuesto · Gasto real · Sueldo publicado
- **Publicación:** Nombre de la campaña (opcional) · Texto del anuncio (copy) · Liga del anuncio · Impresiones (opcional) · Clics (opcional)
- **Evidencia y resultado:** Arte y documentos (PDF o imagen, privados) · Candidatos generados (opcional) · Observaciones

#### Qué pasa después

- Cada candidato que registres ligado a esta campaña alimenta su embudo: candidatos → contactados (llegaron a entrevista) → entrevistas → psicométricos → socioeconómicos → contratados.
- La tarjeta calcula costo por candidato (gasto ÷ candidatos), costo por colaborador contratado (gasto ÷ contratados), conversión (contratados ÷ candidatos) y días para cubrir.
- Ejemplo: $4,500 gastados, 30 candidatos y 2 contratados → $150 por candidato y $2,250 por contratación.

#### Qué queda registrado

- La campaña y sus adjuntos quedan en la bitácora con quién la creó y modificó.

#### Errores comunes

- **«El sueldo publicado es distinto al del puesto.»** Está bien: el sueldo publicado vive solo en la campaña y no modifica el catálogo de Puestos ni el sueldo con que se contrata.
- **«El embudo de la campaña sale en ceros.»** Al registrar a cada candidato, elige esa campaña en el campo «Campaña». Si no hay ninguno ligado, PEOPLE atribuye a la campaña los contratados de su misma vacante.
- **«La vacante no aparece al crear la campaña.»** Esa plaza ya se cubrió o la plantilla está completa. Revisa Vacantes.

#### Relacionado con

- [De dónde nace una vacante](#articulo-plantilla-y-vacantes)
- [Registrar un candidato](#articulo-registrar-candidato)

#### En el sistema

- **Ir a Campañas** → `/rh/campanas` _(permiso: reclutamiento.campanas.ver)_
- Guía en pantalla → `/ayuda/articulo/campanas`

<a id="articulo-registrar-candidato"></a>

### Registrar un candidato

> El candidato se registra sobre una vacante real; de ella salen la empresa, la sucursal, el departamento y el puesto.

#### Qué es

Es dar de alta a una persona interesada en una plaza. Desde ese momento entra al tablero de Candidatos en la fase «Filtro RH».

#### Quién lo hace

Reclutamiento / RH (permiso para crear candidatos).

> **Atención:** Candidato espontáneo: marca «Candidato espontáneo / sin vacante actual» solo si todavía no hay vacante para esa persona. Puede avanzar en el proceso, pero NO se puede contratar hasta vincularlo a una vacante real disponible (desde «Editar datos»).

#### Antes de empezar

- Ten el nombre y al menos un dato de contacto (teléfono o correo).
- Identifica la vacante real a la que aplica y, si llegó por una campaña, cuál.

#### Pasos

1. **Abre Candidatos** _(Web)_ — Reclutamiento → Candidatos → «Nuevo candidato».
2. **Datos de contacto** — Nombre, apellidos, teléfono y correo.
3. **Elige la vacante** — Por ejemplo «Gestor — Tlaxcala». PEOPLE muestra el puesto y la sucursal que toma de ella: no se capturan de nuevo.
4. **Campaña y fuente** — Elige la campaña de esa vacante por la que llegó (si aplica) y la fuente o canal: pauta Meta, grupos de Facebook, WhatsApp, Indeed, Computrabajo, LinkedIn, referido, orgánico u otro.
5. **Observaciones y guardar** — Agrega lo que el equipo deba saber y guarda.
6. **Sube el CV** — En la ficha del candidato, «Subir CV» (PDF o Word). Se descarga desde la ficha sin exponer dónde se guarda.

#### Lo que se captura

- **Contacto:** Nombre · Apellidos · Teléfono · Correo
- **Plaza:** Vacante (obligatoria si no es espontáneo) · Campaña de esa vacante (opcional) · Fuente / canal
- **Candidato espontáneo:** Puesto de interés · Sucursal preferida
- **Otros:** Observaciones · CV (desde la ficha)

#### Qué pasa después

- El candidato queda en «Filtro RH» y se abre el pendiente de revisión para Reclutamiento.
- Su ficha registra «Candidato registrado (fuente…)» como primer evento del historial.

#### Qué queda registrado

- Ficha del candidato con su historial (cada paso con fecha, responsable y nota) y la bitácora de auditoría.

#### Errores comunes

- **«Esta vacante ya fue cubierta. Elige otra vacante disponible.»** Alguien ocupó la última plaza mientras capturabas. Elige otra vacante o registra al candidato como espontáneo.
- **«La campaña no aparece en la lista.»** Solo se ofrecen las campañas de la vacante elegida. Elige primero la vacante.

#### Relacionado con

- [Las fases del candidato](#articulo-etapas-del-candidato)
- [Filtro RH (primer contacto)](#articulo-filtro-rh)
- [Crear una campaña de reclutamiento](#articulo-campanas)

#### En el sistema

- **Ir a Candidatos** → `/rh/candidatos` _(permiso: candidatos.ver)_
- Guía en pantalla → `/ayuda/articulo/registrar-candidato`

<a id="articulo-etapas-del-candidato"></a>

### Las fases del candidato

> El tablero tiene 6 fases y 2 columnas de cierre. Cada avance se hace con su acción en la ficha, nunca arrastrando.

#### Qué es

Es el recorrido de cada candidato, visible como columnas en Reclutamiento → Candidatos. Las fases agrupan los pasos internos para que RH vea solo lo importante.

#### Quién lo hace

RH, Gerente, Dirección Comercial.

> **Importante:** Arrastrar una tarjeta solo sirve para cerrar el proceso (a Rechazado o Desistió, con motivo). Para avanzar se usa la acción de la ficha («Revisar perfil», «Registrar entrevista»…): así cada paso deja su registro completo.

> **Nota:** La ficha muestra siempre quién tiene la siguiente acción (Reclutamiento, Gerente de sucursal o RH) y solo ofrece los botones que a ti te tocan.

#### Antes de empezar

- El candidato ya está registrado.

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

#### Qué queda registrado

- Historial del candidato: cada cambio de fase con estado anterior → nuevo, quién y nota.
- Fase máxima alcanzada (alimenta el embudo del tablero de Inicio).

#### Errores comunes

- **«No puedo arrastrar la tarjeta a la siguiente columna.»** Es a propósito. Abre la ficha y usa la acción del paso.
- **«El candidato ya está en otra fase. Recarga la ficha.»** Otra persona avanzó el paso al mismo tiempo. Recarga y revisa el historial.

#### Relacionado con

- [Filtro RH (primer contacto)](#articulo-filtro-rh)
- [Entrevista con el gerente](FLUJO_COMPLETO.md#articulo-entrevista-gerente)
- [Autorización de RH](#articulo-autorizacion-rh)

#### En el sistema

- **Ir a Candidatos** → `/rh/candidatos` _(permiso: candidatos.ver)_
- Guía en pantalla → `/ayuda/articulo/etapas-del-candidato`

<a id="articulo-filtro-rh"></a>

### Filtro RH (primer contacto)

> Reclutamiento contacta al candidato (normalmente por WhatsApp) y registra si continúa o no.

#### Qué es

Es la primera revisión del perfil. El contacto se hace fuera del sistema (WhatsApp, llamada); PEOPLE NO envía ese mensaje, solo registra el resultado.

#### Quién lo hace

RH.

#### Antes de empezar

- El candidato está en «Filtro RH».

#### Pasos

1. **Contacta al candidato** — Por WhatsApp o llamada, fuera de PEOPLE.
2. **Abre su ficha** _(Web y app)_ — Candidatos → tarjeta del candidato.
3. **«Revisar perfil»** — Elige el resultado: continúa (viable) o no continúa. Escribe observaciones. La fecha y el responsable se guardan solos.
4. **Si no continúa** — El motivo es obligatorio (catálogo de motivos de rechazo) y decides si la persona puede considerarse de nuevo en el futuro.

#### Qué pasa después

- Continúa → pasa a «Entrevista» y le llega el pendiente al gerente de la sucursal.
- No continúa → queda en «Rechazado» con su motivo.

#### Qué queda registrado

- Fecha, responsable, notas y resultado en el historial del candidato.

#### Errores comunes

- **«No me deja guardar el rechazo.»** Elige un motivo del catálogo: es obligatorio.

#### Relacionado con

- [Entrevista con el gerente](FLUJO_COMPLETO.md#articulo-entrevista-gerente)
- [Las fases del candidato](#articulo-etapas-del-candidato)

#### En el sistema

- **Ir a Candidatos** → `/rh/candidatos` _(permiso: candidatos.ver)_
- Guía en pantalla → `/ayuda/articulo/filtro-rh`

<a id="articulo-psicometricos"></a>

### Psicométricos

> Reclutamiento envía el link y carga el resultado (PDF o imagen). Cargar NO decide: queda pendiente de revisión hasta que el responsable lo abre y dice viable o no viable.

#### Qué es

Son las pruebas psicométricas del candidato. Se aplican en la plataforma del proveedor; PEOPLE guarda el link, el resumen y los reportes, y registra la revisión del gerente.

#### Quién lo hace

Reclutamiento envía y carga; el responsable de la vacante (gerente de la sucursal; para una vacante de gerente, el Regional) revisa.

#### Antes de empezar

- El candidato pasó la entrevista.

#### Pasos

1. **«Registrar link de psicométricas»** _(RH)_ — Pega la liga que se le envió al candidato (opcional, para dejar constancia).
2. **«Cargar resultados psicométricos»** _(RH)_ — Resumen y hasta 5 reportes en PDF o imagen (JPG, PNG, WEBP). El candidato queda «Revisión de psicométricas»: todavía no avanza.
3. **«Ver psicométrico» y «Revisar psicométrico»** _(Gerente)_ — El responsable abre el PDF (botón arriba de la ficha) y decide Viable o No viable, con observaciones. Solo entonces avanza.
4. **Ver la evidencia sin salir de PEOPLE** — En la ficha, «Evaluaciones registradas» → clic en el archivo: el PDF y las imágenes se abren en un visor dentro de PEOPLE.

#### Qué pasa después

- Viable → «Socioeconómico».
- No viable → «Rechazado» con motivo.

#### Qué queda registrado

- Link, fecha de envío, resumen, reportes, revisión y responsable.

#### Errores comunes

- **«¿Dónde quedó guardado el reporte?»** En el almacenamiento privado de PEOPLE. Nunca se muestra la ruta del servidor: se abre siempre desde la ficha.

#### Relacionado con

- [Estudio socioeconómico](#articulo-socioeconomico)
- [Entrevista con el gerente](FLUJO_COMPLETO.md#articulo-entrevista-gerente)

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

#### Antes de empezar

- Las psicométricas quedaron en perfil.

#### Pasos

1. **«Cargar estudio socioeconómico»** — Fecha de visita, dirección visitada y checklist: vivienda en orden, vive con su familia, tiempo en la vivienda (años y meses) y resguardo de motocicleta. Queda «pendiente de revisión».
2. **Riesgos y observaciones** — Riesgos o inconsistencias detectados y observaciones.
3. **Evidencias** — Hasta 12 archivos: fotos (JPG, PNG, WEBP), PDF o video (MP4, MOV).
4. **«Revisar socioeconómico»** _(Gerente)_ — El responsable abre el video o el PDF («Ver socioeconómico» / «Ver video») y decide Viable o No viable (si no es viable, motivo obligatorio).
5. **Abrir las evidencias** — En la ficha, clic en cada archivo: imagen y PDF se ven en línea; el video se reproduce dentro de PEOPLE.

#### Qué pasa después

- Revisado viable → «Validación de referencias».
- Revisado no viable → «Rechazado».

#### Qué queda registrado

- Estudio completo con visitador, checklist, riesgos y evidencias en la ficha.

#### Errores comunes

- **«El video no sube.»** Usa MP4 o MOV y revisa el tamaño máximo permitido.

#### Relacionado con

- [Validación de referencias](#articulo-referencias)
- [Psicométricos](#articulo-psicometricos)

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

#### Antes de empezar

- El socioeconómico quedó viable.

#### Pasos

1. **«Registrar referencia»** — Empresa, contacto, teléfono, relación o puesto, resultado y observaciones. Repite por cada referencia.
2. **«Concluir validación de referencias»** — Indica si las referencias son favorables. Se exige al menos una registrada.

#### Qué pasa después

- Favorables → «Autorización de RH».
- No favorables → «Rechazado» con motivo.

#### Qué queda registrado

- Cada referencia con quién la validó y cuándo.

#### Errores comunes

- **«Registra al menos una referencia laboral validada antes de continuar.»** La ficha lo avisa antes de concluir. Captura una referencia con «Registrar referencia» (botón en el mismo aviso) y vuelve a concluir.

#### Relacionado con

- [Autorización de RH](#articulo-autorizacion-rh)

#### En el sistema

- **Ir a Candidatos** → `/rh/candidatos` _(permiso: candidatos.ver)_
- Guía en pantalla → `/ayuda/articulo/referencias`

<a id="articulo-autorizacion-rh"></a>

### Autorización de RH

> Solo RH autoriza la contratación, después de que el responsable revisó entrevista, psicométricos y socioeconómico y se validaron referencias. El gerente no autoriza contrataciones.

#### Qué es

Es la decisión de contratar. Siempre es de Recursos Humanos; no hay preautorización del gerente.

#### Quién lo hace

RH.

> **Importante:** Caso normal: RH aprueba → el candidato continúa. NO se manda al Regional ni a Dirección Comercial. Ellos solo intervienen si, después de un rechazo de RH, el gerente pide una intervención.

#### Antes de empezar

- Las referencias se concluyeron favorables.

#### Pasos

1. **«Autorizar contratación»** _(RH)_ — RH da la autorización final. El candidato queda «Autorizado por RH» y aparece el botón para generar el QR.
2. **O bien «Devolver a referencias»** _(RH)_ — Con motivo, si falta algo: regresa a la validación de referencias.
3. **O bien «Rechazar»** _(RH)_ — Con motivo del catálogo: el candidato queda «Rechazado por RH».

#### Qué pasa después

- Autorizado → RH ve «Generar QR de contratación».
- Rechazado por RH → el gerente que entrevistó puede aceptar el rechazo o «Solicitar intervención».

#### Notificaciones

- «Autorización de RH» como pendiente a quien autoriza (prioridad alta).

#### Qué queda registrado

- Aprobación con el nombre de quien autorizó (queda fijo aunque después cambie el jefe).

#### Errores comunes

- **«No veo el botón Autorizar contratación.»** La autorización requiere el permiso de RH y alcance sobre ese candidato. El gerente nunca autoriza contrataciones.

#### Relacionado con

- [Intervención sobre un rechazo de RH](FLUJO_COMPLETO.md#articulo-intervencion)
- [Iniciar la contratación (QR de contratación)](#articulo-iniciar-contratacion)

#### En el sistema

- **Ir a Mis pendientes** → `/rh/pendientes`
- Guía en pantalla → `/ayuda/articulo/autorizacion-rh`

## 2. Contratación

<a id="articulo-iniciar-contratacion"></a>

### Iniciar la contratación (QR de contratación)

> Con el candidato autorizado por RH, «Generar QR de contratación» crea a la persona en contratación, ocupa la plaza y entrega un QR de un solo uso.

#### Qué es

Es el paso que convierte al candidato autorizado en una persona en contratación. Todo ocurre en un solo movimiento y sin duplicar a nadie.

#### Quién lo hace

RH con permiso para contratar candidatos, dentro de su alcance.

> **Importante:** La sucursal, el departamento y el puesto salen SIEMPRE de la vacante. Aunque alguien manipule el formulario, PEOPLE no contrata en otra plaza.

#### Antes de empezar

- El candidato está «Autorizado por RH».
- Está vinculado a una vacante real (un espontáneo primero se vincula desde «Editar datos»).
- El puesto tiene configurada su duración de capacitación inicial (Configuración → Parámetros de RH).

#### Pasos

1. **Abre la ficha del candidato** _(Web)_ — Candidatos → candidato autorizado.
2. **«Generar QR de contratación»** — Captura sueldo mensual, fecha de ingreso, modalidad (capacitación inicial) y, si quieres, el fin del periodo. Vacío = según la duración del puesto.
3. **Confirma** — PEOPLE bloquea la vacante y revisa que la plaza siga libre ANTES de crear a la persona. Si dos personas intentan ocupar la última plaza al mismo tiempo, la segunda recibe «La vacante ya fue cubierta».

#### Qué pasa después

- Se crea el colaborador con estatus «en incorporación», su número de empleado y su expediente, ligado al candidato (no son dos personas).
- Se crea su contrato de capacitación inicial con fecha de fin calculada por el puesto.
- La vacante cuenta la plaza como ocupada (si era la última, se cierra).
- El CV del candidato se copia a su expediente.
- Se genera el QR de registro: temporal (24 h por defecto, nunca más de 24 h), de un solo uso y revocable.
- El candidato pasa a «En contratación».

#### Qué queda registrado

- Historial del candidato: «Inició la contratación: colaborador N creado y QR de registro generado».
- Bitácora: contratación iniciada con candidato, colaborador e invitación.

#### Errores comunes

- **«Este candidato es espontáneo: vincúlalo a una vacante disponible antes de iniciar su contratación.»** Abre «Editar datos», elige la vacante real y vuelve a intentarlo.
- **«Configura la duración de capacitación inicial para este puesto…»** Gerencia de RH entra a Administración → Configuración → Parámetros de RH y captura los meses del puesto. No existe una duración genérica.
- **«La vacante ya fue cubierta.»** Alguien ocupó la última plaza antes. Vincula al candidato a otra vacante disponible.
- **«Ya existe una cuenta con ese correo: si es un reingreso, búscalo en Reingresos.»** Es alguien que ya trabajó en MR. LANA: usa Reingresos para no duplicar a la persona.

#### Relacionado con

- [El QR de incorporación](#articulo-qr-incorporacion)
- [Registro del colaborador en la app](FLUJO_COMPLETO.md#articulo-registro-en-app)
- [Del candidato al colaborador (sin duplicar a nadie)](#articulo-candidato-a-colaborador)

#### En el sistema

- **Ir a Candidatos** → `/rh/candidatos` _(permiso: candidatos.ver)_
- Guía en pantalla → `/ayuda/articulo/iniciar-contratacion`

<a id="articulo-qr-incorporacion"></a>

### El QR de incorporación

> No es solo un formulario: es la puerta de entrada de la persona. Al escanearlo crea su cuenta sobre el mismo colaborador que preparó RH.

#### Qué es

Es un código temporal y de un solo uso, ligado al candidato y a su colaborador. Nadie puede registrarse en la app sin una invitación activa.

#### Quién lo hace

RH, Colaborador.

#### Antes de empezar

- La contratación ya se inició desde la ficha del candidato.

#### Pasos

1. **Qué ve RH** _(RH)_ — En la ficha del candidato, el bloque «QR de contratación» con su estado y vencimiento, y «Ver / regenerar QR». En el detalle: copiar liga, descargar PNG, regenerar o revocar.
2. **Qué recibe la persona** _(Colaborador)_ — El QR impreso o la liga (por WhatsApp, por ejemplo). Al escanearlo, su celular abre la pantalla de incorporación de MR. LANA PEOPLE: si tiene la app, la abre; si no, ofrece «Descargar app».
3. **Qué ve el gerente** _(Gerente)_ — El candidato en la fase «Contratación» del tablero y, más adelante, los contratos por imprimir en sus pendientes.
4. **Si venció o no se usó** _(RH)_ — Reclutamiento → Invitaciones QR → «Nueva invitación»: elige a la persona en contratación (solo aparecen quienes no tienen un QR vigente) y su vigencia de 1 a 24 horas.

#### Qué pasa después

- Al usarse, la invitación queda «Usada» y no vuelve a funcionar.
- Un token inválido, vencido o revocado muestra un mensaje claro, nunca un error.

#### Qué queda registrado

- Cada invitación: quién la creó, vigencia, usos, quién la usó y cuándo. Nunca se guarda el código en claro.

#### Errores comunes

- **«El QR ya no funciona.»** Venció (vive como máximo 24 h) o ya se usó. Regénéralo desde la invitación o crea una nueva.
- **«No encuentro a la persona en Nueva invitación.»** Solo aparecen personas en contratación sin un QR vigente y sin cuenta. Si su QR sigue vigente, compártelo de nuevo.

#### Relacionado con

- [Iniciar la contratación (QR de contratación)](#articulo-iniciar-contratacion)
- [Registro del colaborador en la app](FLUJO_COMPLETO.md#articulo-registro-en-app)

#### En el sistema

- **Ir a Invitaciones QR** → `/rh/incorporacion/invitaciones` _(permiso: rh.incorporacion.invitaciones.ver)_
- Guía en pantalla → `/ayuda/articulo/qr-incorporacion`

## 3. Alta del colaborador

<a id="articulo-documentos-de-alta"></a>

### Documentos del alta y revisión de RH

> La persona sube los 11 documentos obligatorios desde la app; RH los aprueba, rechaza o pide corrección.

#### Qué es

Es la integración del expediente de la persona nueva. Solo se piden los documentos obligatorios del alta; los demás (contratos firmados, permisos…) nacen después.

#### Quién lo hace

Colaborador, RH.

#### Antes de empezar

- La persona ya creó su cuenta con el QR.

#### Pasos

1. **Sube cada documento** _(Colaborador · App)_ — App → «Mi incorporación»: toca cada documento pendiente y súbelo (foto o PDF).
2. **Revisa en el expediente** _(RH · Web y app)_ — Expedientes → la persona → «Documentos»: abre cada archivo sin salir de PEOPLE y aprueba, rechaza (con motivo) o pide corrección.
3. **Aprovecha la lectura automática** _(RH)_ — De identificaciones como INE, CURP o constancia fiscal, PEOPLE puede proponer datos (nombre, CURP, RFC…). RH revisa y decide aplicarlos o ignorarlos.

#### Lo que se captura

- **Documentos obligatorios del alta:** Solicitud de empleo (formato interno) · 2 fotografías tamaño infantil · Acta de nacimiento · Identificación oficial · Número de Seguridad Social · CURP · Constancia de situación fiscal (RFC) · Comprobante de domicilio · Comprobante de estudios · Carta de recomendación (la segunda carta es opcional: con una aprobada el expediente queda completo; cada carta se sube como foto o PDF, o las dos en un solo archivo marcando «trae también la otra carta») · Datos bancarios (cuenta, tarjeta y CLABE)

#### Qué pasa después

- Cada documento rechazado le llega a la persona con su motivo (notificación y push) para que lo vuelva a subir.
- Las cartas de recomendación se revisan por separado: puedes aprobar las dos, o aprobar una y rechazar la otra. Solo la carta 1 es obligatoria.
- Cuando el último obligatorio queda aprobado, RH recibe «Expediente completo» y el pendiente «Generar paquete de contratación».
- Un documento ya aprobado no se reemplaza directo: la persona solicita el cambio y RH lo autoriza.

#### Notificaciones

- Documento aprobado / rechazado → colaborador (notificación + push).
- Expediente completo → RH con alcance.

#### Qué queda registrado

- Cada versión del documento con quién lo subió, quién lo revisó, cuándo y el motivo.

#### Errores comunes

- **«El avance no sube.»** Solo cuentan los documentos APROBADOS; cargado o en revisión todavía no.
- **«No puedo volver a subir un documento aprobado.»** Toca «Solicitar cambio»: cuando RH lo autorice, podrás subir la nueva versión.

#### Relacionado con

- [Completar o corregir datos (colaborador)](#articulo-completar-datos)
- [Contrato de capacitación inicial](#articulo-contrato-inicial)
- [El expediente del colaborador](#articulo-expediente)

#### En el sistema

- **Ir a Expedientes** → `/rh/expedientes` _(permiso: expedientes.ver_todos o expedientes.ver_sucursal)_
- Guía en pantalla → `/ayuda/articulo/documentos-de-alta`

<a id="articulo-candidato-a-colaborador"></a>

### Del candidato al colaborador (sin duplicar a nadie)

> Candidato y colaborador son la misma persona: PEOPLE los liga desde la contratación y conserva todo el historial.

#### Qué es

Es la regla con la que PEOPLE convierte a un candidato en colaborador. Nunca crea dos personas independientes.

#### Quién lo hace

RH.

#### Antes de empezar

- La contratación se inició desde la ficha del candidato.

#### Pasos

1. **Al generar el QR** — Se crea el colaborador ligado al candidato: número de empleado, expediente, empresa, sucursal, puesto, contrato de capacitación inicial y ocupación de la plaza.
2. **Al registrarse con el QR** — Se crea su cuenta sobre ese mismo colaborador.
3. **Al firmar los contratos** — El candidato pasa a «Contratado» y empieza el onboarding.
4. **Al cerrar el onboarding** — El colaborador queda ACTIVO (alta real) y se avisa a RH y Sistemas.

#### Qué pasa después

- La ficha del candidato y el expediente del colaborador apuntan uno al otro: el reclutamiento queda como antecedente consultable.
- Su actividad laboral empieza con el movimiento «Alta».

#### Qué queda registrado

- Ciclo laboral de la persona: cada etapa con fecha y responsable.

#### Errores comunes

- **«La CURP/RFC/NSS ya pertenece a otra persona.»** PEOPLE impide duplicados. Si esa persona ya trabajó aquí, usa Reingresos.

#### Relacionado con

- [Alta real: colaborador activo](#articulo-alta-activa)
- [Reingreso de un excolaborador](#articulo-reingreso)

#### En el sistema

- **Ir a Expedientes** → `/rh/expedientes` _(permiso: expedientes.ver_todos o expedientes.ver_sucursal)_
- Guía en pantalla → `/ayuda/articulo/candidato-a-colaborador`

<a id="articulo-alta-activa"></a>

### Alta real: colaborador activo

> La persona queda activa cuando su expediente está aprobado, sus contratos firmados y su onboarding cerrado. Entonces se avisa a RH y a Sistemas.

#### Qué es

Es el momento en que la persona deja de estar «en incorporación» y empieza a operar. PEOPLE lo hace al cerrar el onboarding.

#### Quién lo hace

RH, Sistemas.

#### Antes de empezar

- Expediente con documentos obligatorios aprobados.
- Contratos de contratación firmados.
- Onboarding completado (lecciones aprobadas y equipo entregado).

#### Pasos

1. **RH cierra el onboarding** _(RH)_ — Ciclo laboral de la persona → «Cerrar onboarding». PEOPLE la activa en ese mismo paso.
2. **Sistemas prepara accesos** _(Sistemas)_ — Recibe «Alta: Nombre (Puesto, Sucursal). Prepara su cuenta, accesos y equipo.»

#### Qué pasa después

- El colaborador recibe «¡Bienvenido a MR. LANA! Ya tienes acceso completo a tu portal.»
- Se recalcula la vacante de su sucursal y puesto.

#### Notificaciones

- RH: documentación y seguimiento.
- Sistemas: cuentas, accesos y equipo.
- Colaborador: bienvenida.

#### Qué queda registrado

- Bitácora «colaborador activado» y actividad laboral.

#### Errores comunes

- **«No se puede activar todavía: está en …»** Falta algo del checklist: documentos, firma de contratos u onboarding. El ciclo laboral muestra qué y a quién le toca.

#### Relacionado con

- [Onboarding: la bienvenida del colaborador](#articulo-onboarding)
- [Cuentas, credenciales y roles (Sistemas)](#articulo-usuarios-y-roles)

#### En el sistema

- **Ir a Mis pendientes** → `/rh/pendientes`
- Guía en pantalla → `/ayuda/articulo/alta-activa`

## 4. Expediente

<a id="articulo-expediente"></a>

### El expediente del colaborador

> La carpeta digital de la persona, organizada en pestañas. Nada se borra nunca.

#### Qué es

Es todo lo que RH guarda de una persona: datos, documentos, cuenta, onboarding, avisos, vacaciones, recibos, préstamos, solicitudes e historial.

#### Quién lo hace

RH ve todos los expedientes; gerencias, los de su sucursal; el colaborador (incluidas las coordinadoras), solo el suyo (Mi expediente).

#### Antes de empezar

- Tener permiso de expedientes (RH o gerencia) o ser el propio colaborador.

#### Pasos

1. **Busca a la persona** _(Web)_ — Expedientes → busca por nombre o número de empleado.
2. **Revisa las pestañas** — Resumen · Datos personales · Datos laborales · Cuenta · Documentos · Onboarding · Avisos / Comunicaciones · Vacaciones · Recibos de nómina · Préstamos · Solicitudes · Historial RH.
3. **Documentos del proceso** — Debajo están los documentos generados por PEOPLE (contratos, permisos, préstamo, baja) con su estado de firma y del original físico.

#### Qué pasa después

- El colaborador ve su propio expediente en Mi expediente (sin la pestaña Cuenta ni Checklist).

#### Qué queda registrado

- Historial RH: cada cambio con quién, cuándo y valores anteriores.

#### Errores comunes

- **«Quiero corregir mi propio expediente y no puedo.»** Nadie edita su propio expediente, ni RH. Usa «Solicitar corrección»: otra persona de RH lo autoriza.
- **«Di de baja a alguien y no desaparece.»** Es correcto: la baja bloquea el acceso y conserva todo el historial para un posible reingreso.

#### Relacionado con

- [Qué le falta a un expediente (y cómo avisar)](#articulo-completitud-expediente)
- [Completar o corregir datos (colaborador)](#articulo-completar-datos)
- [Actividad laboral (historial de movimientos)](#articulo-actividad-laboral)

#### En el sistema

- **Ir a Expedientes** → `/rh/expedientes` _(permiso: expedientes.ver_todos o expedientes.ver_sucursal)_
- **Ir a Mi expediente** → `/mi-expediente`
- Guía en pantalla → `/ayuda/articulo/expediente`

<a id="articulo-completitud-expediente"></a>

### Qué le falta a un expediente (y cómo avisar)

> El expediente no muestra solo un porcentaje: dice qué sección está completa y cuál no, y RH puede avisarle al colaborador.

#### Qué es

Es el semáforo por secciones del expediente: Identidad, Fiscal, Laboral, Domicilio, Contacto, Contacto de emergencia, Beneficiario y Documentos.

#### Quién lo hace

RH, Colaborador.

#### Antes de empezar

- Abrir el expediente de la persona.

#### Pasos

1. **Lee el aviso de datos faltantes** _(RH)_ — Por ejemplo: Identidad ✓ · Fiscal ✓ · Laboral ✓ · Domicilio ⚠ (falta el código postal) · Contacto de emergencia ✗ · Documentos ✓.
2. **«Avisar al colaborador»** _(RH)_ — Le llega una notificación y un push con la lista de lo que falta. Se puede repetir una vez cada 24 horas.
3. **El colaborador completa** _(Colaborador · App)_ — App → Inicio → «Completa tu información» → envía los datos a RH.

#### Lo que se captura

- **Identidad:** Sexo · Fecha de nacimiento · Estado civil · CURP
- **Fiscal:** RFC · Número de seguridad social (NSS)
- **Laboral (lo captura RH):** Puesto · Sucursal · Fecha de ingreso · Sueldo · Tipo de contrato
- **Domicilio:** Domicilio · Código postal
- **Contacto:** Teléfono celular · Correo electrónico
- **Contacto de emergencia:** Nombre · Parentesco · Teléfono
- **Beneficiario:** Nombre · Parentesco

#### Qué pasa después

- Cuando RH autoriza los datos propuestos, el semáforo se recalcula solo.

#### Notificaciones

- Aviso de datos faltantes → colaborador (notificación + push).

#### Qué queda registrado

- Último aviso enviado (fecha y quién) en el propio expediente.

#### Errores comunes

- **«El botón Avisar está deshabilitado.»** Ya se avisó en las últimas 24 horas, o la persona ya envió una solicitud que está en revisión.

#### Relacionado con

- [Completar o corregir datos (colaborador)](#articulo-completar-datos)
- [Contrato de capacitación inicial](#articulo-contrato-inicial)

#### En el sistema

- **Ir a Expedientes** → `/rh/expedientes` _(permiso: expedientes.ver_todos o expedientes.ver_sucursal)_
- Guía en pantalla → `/ayuda/articulo/completitud-expediente`

<a id="articulo-completar-datos"></a>

### Completar o corregir datos (colaborador)

> El colaborador propone sus datos; RH revisa y autoriza; el expediente se actualiza solo.

#### Qué es

Es una solicitud de «Actualización de datos». El colaborador nunca edita su expediente directo: propone y RH decide.

#### Quién lo hace

Colaborador, RH.

#### Antes de empezar

- Tener cuenta en la app o en el portal.

#### Pasos

1. **Abre «Completa tu información»** _(Colaborador · Web y app)_ — App → Inicio → «Completa tu información» (o en la web, Mi expediente → «Solicitar corrección»).
2. **Captura lo que falta** _(Colaborador)_ — Solo aparecen los datos que tu contrato pide y no tienes.
3. **«Enviar a RH»** _(Colaborador)_ — Verás «Enviado a Recursos Humanos».
4. **RH revisa** _(RH)_ — Solicitudes → la solicitud muestra el dato, el valor actual y el valor propuesto. RH autoriza o rechaza.

#### Qué pasa después

- Al autorizar, los datos pasan solos al expediente y la completitud se recalcula.

#### Qué queda registrado

- Solicitud con valores anteriores y nuevos, quién autorizó y cuándo.

#### Errores comunes

- **«Ya envié mis datos y no cambian.»** Espera la autorización de RH; puedes seguirla en Mis solicitudes.

#### Relacionado con

- [Qué le falta a un expediente (y cómo avisar)](#articulo-completitud-expediente)
- [Revisar solicitudes (gerente, regional y RH)](#articulo-revisar-solicitudes)

#### En el sistema

- **Ir a Mi expediente** → `/mi-expediente`
- Guía en pantalla → `/ayuda/articulo/completar-datos`

## 5. Contratos

<a id="articulo-contrato-inicial"></a>

### Contrato de capacitación inicial

> PEOPLE llena el contrato con los datos del expediente; la duración la define el puesto. Si falta un dato, no genera nada a medias.

#### Qué es

Es el primer contrato de la persona (capacitación inicial = periodo de prueba), acompañado del contrato de confidencialidad y el de no competencia.

#### Quién lo hace

RH.

> **Importante:** No hay una duración genérica: si el puesto no tiene meses de capacitación configurados, PEOPLE bloquea la contratación con un mensaje claro. Se configura en Configuración → Parámetros de RH.

> **Atención:** Si el formato pide un dato que el expediente no tiene, PEOPLE responde «Faltan N dato(s) requerido(s)» con la lista exacta y NO genera el documento.

#### Antes de empezar

- Expediente con los documentos obligatorios aprobados (RH recibe el pendiente «Generar paquete de contratación»).
- Los formatos de Jurídico cargados en Documentos maestros.
- La duración del puesto configurada (por ejemplo, Gestor 2 meses; Gerente y Regional 3 meses).

#### Pasos

1. **Abre la ficha del colaborador** _(Web)_ — Expedientes → la persona (o su Ciclo laboral) → sección de documentos de Contratación.
2. **«Generar paquete de contratación»** — PEOPLE llena cada formato con nombre, CURP, RFC, NSS, domicilio, empresa, sucursal, puesto, sueldo, fechas, contacto de emergencia, beneficiario y demás datos del expediente.
3. **Revisa** — Previsualiza el PDF. Si cambió un dato, «Regenerar con datos actuales». También puedes «Descargar Word».

#### Qué pasa después

- Los contratos quedan listos para imprimir y firmar; el gerente recibe el pendiente.

#### Notificaciones

- «Contratos listos» a quien opera los documentos.

#### Documentos que se generan

- Contrato de capacitación inicial
- Contrato de confidencialidad
- Contrato de no competencia

#### Qué queda registrado

- Documento generado con versión del formato, datos usados, quién y cuándo.

#### Errores comunes

- **«¿Por qué el contrato no se genera?»** Falta un dato del expediente (te dice cuál) o falta el formato oficial en Documentos maestros.
- **«Falta el formato oficial.»** Gerencia de RH carga el original de Jurídico en Documentos maestros y lo activa.

#### Relacionado con

- [Firma de contratos y control del original](#articulo-firma-y-original)
- [Documentos maestros (formatos de Jurídico)](#articulo-documentos-maestros)
- [Estructura, plantilla autorizada y duración por puesto](#articulo-estructura-y-plantilla)

#### En el sistema

- **Ir a Expedientes** → `/rh/expedientes` _(permiso: expedientes.ver_todos o expedientes.ver_sucursal)_
- Guía en pantalla → `/ayuda/articulo/contrato-inicial`

<a id="articulo-firma-y-original"></a>

### Firma de contratos y control del original

> Cada documento sigue su ruta: imprimir → firma (y huella) → envío a corporativo → recepción → subir firmado → archivar.

#### Qué es

Es el control del papel. PEOPLE sabe en todo momento dónde está el original firmado de cada contrato.

#### Quién lo hace

El gerente imprime, recaba firma y huella y envía; RH recibe, escanea y archiva.

#### Antes de empezar

- El documento está generado.

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

#### Qué queda registrado

- Cada paso del original con fecha, quién y evidencia.

#### Errores comunes

- **«No me deja archivar.»** Primero sube el documento firmado (escaneo).

#### Relacionado con

- [Contrato de capacitación inicial](#articulo-contrato-inicial)
- [Onboarding: la bienvenida del colaborador](#articulo-onboarding)

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

#### Antes de empezar

- Los contratos de contratación están firmados (el onboarding empieza solo).
- RH configuró las lecciones y el equipo en Onboarding.

#### Pasos

1. **Lecciones de bienvenida** _(Colaborador · Web y app)_ — App → «Lecciones de bienvenida» (o Mi portal → «Lo que necesitas hacer»). Cada lección tiene su material (video o documento por liga), texto e instrucciones y una evaluación.
2. **Avance que ve el colaborador** _(Colaborador)_ — «Se habilita después» (todavía bloqueada) → «Lista para responder» → «Aprobada». Si no alcanza la calificación: «Recursos Humanos te dejará un comentario».
3. **Si no aprueba (menos de 8)** _(RH)_ — RH escribe la retroalimentación y habilita la reevaluación. La persona vuelve a intentarlo.
4. **Entrega de equipo** _(Gerente)_ — En el Ciclo laboral de la persona, «Registrar entrega de activos»: qué se entregó; se genera la carta responsiva.
5. **«Cerrar onboarding»** _(RH)_ — Con lecciones aprobadas y equipo entregado, RH lo cierra. En ese momento la persona queda ACTIVA.

#### Qué pasa después

- Alta real: avisos a RH y Sistemas y bienvenida al colaborador.
- Empieza a correr el periodo de capacitación inicial hasta su evaluación.

#### Notificaciones

- «Onboarding: refuerzo» a RH cuando alguien no aprueba una lección.

#### Documentos que se generan

- Carta responsiva por cada entrega de equipo.

#### Qué queda registrado

- Cada intento con calificación, la retroalimentación de RH y cada entrega de equipo.

#### Errores comunes

- **«No me deja cerrar el onboarding.»** PEOPLE dice qué falta: alguna lección sin aprobar o equipo sin entregar.

#### Relacionado con

- [Configurar las lecciones y el equipo](#articulo-configurar-onboarding)
- [Alta real: colaborador activo](#articulo-alta-activa)
- [Evaluaciones de periodo de prueba](#articulo-evaluacion-capacitacion)

#### En el sistema

- **Ir a Onboarding** → `/rh/onboarding/configuracion` _(permiso: onboarding.gestionar)_
- **Ir a Mi portal** → `/mi-portal`
- Guía en pantalla → `/ayuda/articulo/onboarding`

<a id="articulo-induccion-mr-lana"></a>

### Inducción Mr. Lana y su evaluación

> RH presenta la inducción a las personas que eligió, al terminar «Envía la evaluación», el colaborador la contesta en la app y RH califica: con 24/30 o más queda listo para empezar a laborar.

#### Qué es

Es la primera de las dos evaluaciones de ingreso (la segunda es la evaluación de periodo de prueba). La inducción institucional es el documento oficial (Quiénes somos, Misión/visión/principios, Cultura, Organigrama, Reglamento interior, Modelo de negocio y Empresa con factor humano) y su evaluación es el formato oficial FO-RH-EI de 30 puntos.

#### Quién lo hace

RH elige a quién le presenta la inducción, la presenta (en sala o por Meet), envía la evaluación y califica los casos prácticos. El colaborador contesta su evaluación en la app (o en el portal web).

#### Antes de empezar

- La persona está en su proceso de ingreso (contratos firmados). Si RH se la presenta antes, la asistencia queda registrada y su evaluación se habilita sola en cuanto inicie su onboarding.

#### Pasos

1. **Elegir a quién se le presenta** _(RH · Web)_ — Desarrollo → «Presentar inducción». ANTES de iniciar, busca y agrega a uno o varios colaboradores (solo los de tu alcance; «Agregar a los pendientes de presentar» los suma de una vez) y pulsa «Iniciar presentación».
2. **Presentar y «Enviar evaluación»** _(RH · Web)_ — Presenta el PDF oficial. Al terminar, pulsa «Enviar evaluación»: en ese momento se habilita la evaluación de cada asistente y le llega el aviso en la app «Contesta la evaluación de la Inducción Mr. Lana». Antes de ese botón no se envía nada; repetirlo no duplica nada.
3. **Contestar la evaluación** _(Colaborador · Web y app)_ — El aviso abre directo la evaluación en la app. Secciones A (opción múltiple), B (verdadero/falso), C (relaciona columnas), D (casos prácticos) y E (opinión, no califica). Se envía una sola vez; la clave de respuestas nunca llega al teléfono ni al navegador.
4. **RH revisa y califica** _(RH · Web)_ — Le llega el aviso «Evaluación de inducción por calificar». En la revisión ve cada pregunta con la respuesta del colaborador y si acertó (A/B/C se califican solas), marca la rúbrica oficial de los casos prácticos (D), puede dejar un comentario y ve el total /30. Desde ahí también ve la vista previa del formato FO-RH-EI, lo descarga o lo guarda en el expediente.
5. **Si requiere reforzamiento** _(RH)_ — Menos de 24/30: RH deja retroalimentación y habilita la reevaluación (plazo de 5 días hábiles). El colaborador no puede reintentar antes.
6. **Seguimiento** _(RH · Web)_ — En «Presentar inducción» RH ve a cada persona en ingreso: Pendiente de presentar → Presentada · por contestar → Por calificar (D) → Aprobada / Reforzamiento, y si ya tiene Contrato indeterminado.
7. **Qué ve el colaborador** _(Colaborador · Web y app)_ — El colaborador nunca presenta: en Mi espacio → «Inducción Mr. Lana» solo ve el PDF mientras la sesión de RH en la que lo incluyeron está en curso. Antes de la sesión ve que RH se la presentará; después, su evaluación por contestar y, al terminarla, que ya la completó (sin el contenido).

#### Qué pasa después

- Aprobada (24/30 o más): se aprueba el módulo de inducción y el formato FO-RH-EI se guarda solo en el expediente (carpeta Otros). Si ya no quedan módulos de puesto ni activos por entregar, el onboarding se cierra y la persona queda activa: en el tablero de candidatos pasa de «Capacitación inicial» a «Contrato indeterminado». Si quedan pendientes, sigue el onboarding normal.
- La segunda evaluación es la de periodo de prueba, que se abre 15 días hábiles antes de que venza su contrato.

#### Notificaciones

- Al colaborador: «Contesta la evaluación de la Inducción Mr. Lana» (al Enviar evaluación), aprobada, reforzamiento y reevaluación habilitada.
- A RH: evaluación por calificar y reforzamiento requerido.

#### Qué queda registrado

- Cada sesión: quién presentó, cuándo y a quién, y qué pasó con cada asistente.
- Cada intento con sus respuestas, calificación, nivel, comentario y quién calificó; queda en el historial de la persona.

#### Errores comunes

- **«No me aparece la evaluación.»** RH todavía no pulsa «Enviar evaluación» de la sesión donde te presentó la inducción, o aún no inicia tu proceso de ingreso.
- **«No encuentro a la persona en el buscador.»** Solo aparecen colaboradores no dados de baja dentro de tu alcance.

#### Relacionado con

- [Onboarding: la bienvenida del colaborador](#articulo-onboarding)
- [Configurar las lecciones y el equipo](#articulo-configurar-onboarding)

#### En el sistema

- **Presentar inducción** → `/induccion/presentacion` _(permiso: onboarding.gestionar)_
- **Ir a Mis pendientes** → `/mi-proceso`
- Guía en pantalla → `/ayuda/articulo/induccion-mr-lana`

<a id="articulo-configurar-onboarding"></a>

### Configurar las lecciones y el equipo

> RH define las lecciones (institucionales y por puesto) y el catálogo de equipo que se entrega.

#### Qué es

Es el catálogo que usa PEOPLE para armar el onboarding de cada persona.

#### Quién lo hace

RH.

#### Antes de empezar

- Tener el permiso de gestionar onboarding.

#### Pasos

1. **Abre Onboarding** _(Web)_ — Desarrollo → Onboarding.
2. **Crea una lección** — Título, tipo (institucional o del puesto), puesto (si es del puesto), orden, calificación mínima, video o material por liga (URL), contenido o instrucciones y su evaluación (preguntas con opciones).
3. **Equipo de trabajo** — Da de alta cada tipo de equipo (nombre y clave): uniforme, credencial, etc.

#### Lo que se captura

- **Lección:** Título · Tipo · Puesto (si aplica) · Orden · Calificación mínima · Video / material (URL) · Contenido / instrucciones · Evaluación (preguntas y opciones)
- **Equipo:** Nombre · Clave

#### Qué pasa después

- Las personas que entren a onboarding después del cambio ya lo ven.

#### Qué queda registrado

- Cambios del catálogo en la bitácora.

#### Errores comunes

- **«No guarda la lección.»** Una lección necesita al menos una pregunta; una lección de puesto necesita su puesto.

#### Relacionado con

- [Onboarding: la bienvenida del colaborador](#articulo-onboarding)

#### En el sistema

- **Ir a Onboarding** → `/rh/onboarding/configuracion` _(permiso: onboarding.gestionar)_
- Guía en pantalla → `/ayuda/articulo/configurar-onboarding`

## 7. Evaluación inicial

<a id="articulo-evaluacion-capacitacion"></a>

### Evaluaciones de periodo de prueba

> Un solo módulo con tres pestañas: Próximas / por asignar, En proceso y Evaluadas. El jefe directo contesta el FO-RH-EPP-001; RH decide e imprime el PDF para firma.

#### Qué es

Es la evaluación de término de periodo de prueba (FO-RH-EPP-001) con la que se decide si la persona sigue (contrato por tiempo indeterminado) o no. La contesta su jefe directo del organigrama, nunca el colaborador.

#### Quién lo hace

Evalúa el jefe inmediato (normalmente el gerente de la sucursal); autoriza RH.

#### Antes de empezar

- La persona tiene un contrato de capacitación inicial vigente.

#### Pasos

1. **PEOPLE la abre sola** _(Automático)_ — Cada mañana revisa los vencimientos. 15 días hábiles antes del fin crea la evaluación y avisa al jefe directo. RH también puede adelantarla desde la pestaña «Próximas / por asignar» con «Asignar evaluación».
2. **El jefe evalúa** _(Gerente · Web y app)_ — Evaluaciones de periodo de prueba → pestaña «En proceso» → «Contestar evaluación». Califica los 9 criterios del FO-RH-EPP-001 (4 Muy bueno a 1 Deficiente), responde las 5 preguntas filtro y escribe tus comentarios; PEOPLE calcula si se renueva.
3. **RH decide** _(RH)_ — Pestaña «En proceso» → chip «Por decidir RH» → «Revisar y decidir»: confirma renovar o no renovar. Si decide distinto a lo que recomendó el jefe, el comentario es obligatorio. También puede devolverla para corrección.
4. **Ver respuestas e imprimir para firma** _(RH)_ — Pestaña «Evaluadas» → «Ver respuestas» (calificación de cada criterio, preguntas filtro, total y resultado) → «Imprimir PDF para firma»: primero se ve la vista previa; firman el jefe inmediato, la Gerencia de RH y el colaborador.

#### Lo que se captura

- **Paso 1 · Desempeño (4 Muy bueno · 3 Bueno · 2 Regular · 1 Deficiente):** Conoce y hace bien su trabajo · Cumple sus metas · Trabaja sin errores · Asistencia y puntualidad · Cumplimiento a políticas y procesos operativos · Uniforme, imagen y reglas internas · Trato al cliente · Trabajo en equipo y actitud · Aprende e iniciativa
- **Paso 2 · Preguntas filtro (Sí / No):** Un solo «Sí» = no se renueva
- **Paso 3 · Resultado:** Se renueva solo sin ningún 1, sin ningún «Sí» y con el total mínimo · Comentarios del jefe inmediato

#### Qué pasa después

- Renovar → PEOPLE prepara el contrato por tiempo indeterminado.
- No renovar → se abre el cierre laboral por «No renovación del periodo de prueba», ya autorizado, con fecha efectiva al fin del contrato.

#### Notificaciones

- «Evaluación pendiente» al jefe evaluador.
- «Contrato por vencer» a la gerencia de la sucursal, al regional, a la Gerencia de RH y a RH con alcance.
- «Evaluación capturada» a RH; «Evaluación devuelta» al jefe.

#### Qué queda registrado

- Evaluación con criterios, calificaciones, recomendación, autorización y comentarios; queda en el expediente.

#### Errores comunes

- **«No veo la evaluación todavía.»** Se habilita sola 15 días hábiles antes del vencimiento, o antes si RH la asigna desde «Próximas / por asignar». Si dice «Aún no se puede asignar», genera primero su contrato de capacitación inicial.
- **«RH no puede autorizar.»** Solo se autoriza una evaluación capturada por el jefe.

#### Relacionado con

- [Contrato por tiempo indeterminado](#articulo-contrato-indeterminado)
- [Baja de un colaborador (forma de salida y causal)](#articulo-baja)

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

#### Antes de empezar

- Evaluación autorizada por RH con decisión «renovar».

#### Pasos

1. **PEOPLE lo prepara** _(Automático)_ — El contrato de capacitación queda «Renovado» y se crea el nuevo, por tiempo indeterminado, con sus documentos.
2. **Previsualiza e imprime** _(Gerente)_ — En el Ciclo laboral de la persona, los documentos del contrato: vista previa, imprimir y la ruta de firma del original.

#### Qué pasa después

- La persona queda «Activo · periodo de prueba superado».

#### Documentos que se generan

- Contrato por tiempo indeterminado.

#### Qué queda registrado

- Contrato anterior marcado como renovado y contrato nuevo, ambos en el expediente.

#### Errores comunes

- **«Solo un contrato vigente puede renovarse.»** Ya se renovó: revisa los documentos del ciclo laboral.

#### Relacionado con

- [Evaluaciones de periodo de prueba](#articulo-evaluacion-capacitacion)
- [Firma de contratos y control del original](#articulo-firma-y-original)

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

#### Antes de empezar

- Tener cuenta en la app o en el portal.

#### Pasos

1. **Elige el permiso** _(Colaborador · Web y app)_ — Mis solicitudes → «Nueva solicitud» → Permiso. Elige: permiso para faltar, para salir temprano o para llegar tarde.
2. **Llena el formulario** _(Colaborador)_ — Fecha del permiso; días (si vas a faltar), hora de salida o de entrada; tipo: con goce de sueldo, sin goce de sueldo o permiso especial. Si es especial, la causal: paternidad, luto, lactancia, cumpleaños o productividad. Observaciones y evidencia (opcional).
3. **Visto bueno del gerente** _(Gerente)_ — El gerente de tu sucursal (o tu jefe directo si no hay gerente).
4. **Visto bueno del regional** _(Regional)_ — El regional de tu región.
5. **RH autoriza** _(RH)_ — Con ambos vistos buenos la solicitud pasa sola a «Pendiente de autorizar». RH la aprueba o rechaza.
6. **Documento listo** _(Gerente)_ — Ya autorizado, el formato de permiso se genera para imprimir y firmar (sección «Formato de permiso» de la solicitud).

#### Lo que se captura

- **Permiso:** Permiso solicitado (faltar / salir temprano / llegar tarde) · Fecha del permiso · Días (al faltar) · Hora de salida o de entrada · Tipo de permiso (con goce / sin goce / especial) · Causal del permiso especial · Observaciones · Evidencia (opcional)

#### Qué pasa después

- El colaborador ve el estado en su app; el permiso queda en la pestaña Solicitudes de su expediente.

#### Notificaciones

- Cada cambio de estado → colaborador (notificación + push).
- Solicitud nueva → quien debe dar el visto bueno.

#### Documentos que se generan

- Formato de permiso MR. LANA (al autorizar).

#### Qué queda registrado

- Historial de la solicitud: cada visto bueno, autorización, corrección o rechazo con fecha y quién.

#### Errores comunes

- **¿Por qué no puedo imprimir un permiso?** Todavía no lo autoriza RH, o falta el formato oficial cargado en Documentos maestros.
- **«Mi solicitud sigue en Recibida.»** Falta el visto bueno del gerente o del regional; la «Cadena de autorización» del detalle dice cuál.

#### Relacionado con

- [Revisar solicitudes (gerente, regional y RH)](#articulo-revisar-solicitudes)
- [Vacaciones](#articulo-vacaciones)

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

#### Antes de empezar

- Tener saldo de vacaciones (lo ves en Mi portal o en la pestaña Vacaciones de la app).

#### Pasos

1. **Elige los días** _(Colaborador · Web y app)_ — Nueva solicitud → Vacaciones (o app → Vacaciones). Toca cada día en el calendario (hasta 60). El domingo no se puede elegir; el sábado sí cuenta.
2. **Revisa tu saldo** _(Colaborador)_ — Arriba ves: saldo antes → días elegidos → saldo después («Te quedarían N días»).
3. **Vistos buenos y autorización** — Igual que un permiso: gerente → regional → RH autoriza.

#### Qué pasa después

- Al autorizar se descuentan los días del saldo.

#### Documentos que se generan

- Formato de vacaciones (al autorizar, para firma).

#### Qué queda registrado

- Días solicitados y autorizados, y movimientos del saldo.

#### Errores comunes

- **«No me deja elegir el domingo.»** Es correcto: el domingo no cuenta como día de vacaciones.
- **«El saldo después sale en rojo.»** Estás pidiendo más días de los que tienes.

#### Relacionado con

- [Pedir y autorizar un permiso](#articulo-permisos)
- [Revisar solicitudes (gerente, regional y RH)](#articulo-revisar-solicitudes)

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

#### Antes de empezar

- Tener cuenta en la app o en el portal.

#### Pasos

1. **Solicita** _(Colaborador · Web y app)_ — App → Préstamos → «Solicitar»: monto y motivo. (En el portal web también puedes proponer el plazo.)
2. **Vistos buenos** — Gerente → regional.
3. **RH autoriza** _(RH)_ — Monto autorizado, plazo, periodicidad y pago programado (o rechaza).
4. **«Generar documentos del préstamo»** _(RH)_ — Carta de consentimiento y retención, contrato de préstamo y pagaré, llenados con los datos del expediente. Se imprimen y firman.
5. **Confirmar entrega** _(RH)_ — En el expediente → Préstamos: fecha de otorgamiento, fecha del primer descuento, periodicidad y pago programado. Desde ahí cuenta el saldo.
6. **Registrar pagos** _(RH)_ — Cada pago se registra en el préstamo y el saldo se actualiza.

#### Qué pasa después

- El colaborador ve su préstamo, saldo y movimientos en la app (Préstamos).

#### Documentos que se generan

- Carta de consentimiento y retención
- Contrato de préstamo personal
- Pagaré

#### Qué queda registrado

- Movimientos del préstamo (entrega, pagos) con quién y cuándo; nunca se edita el saldo a mano.

#### Errores comunes

- **«El saldo no baja solo.»** PEOPLE no descuenta de nómina: RH registra cada pago.

#### Relacionado con

- [Revisar solicitudes (gerente, regional y RH)](#articulo-revisar-solicitudes)
- [El expediente del colaborador](#articulo-expediente)

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

#### Antes de empezar

- Tener permiso de revisar o aprobar solicitudes y alcance sobre la persona.

#### Pasos

1. **Elige el tipo** _(Web)_ — Personal → Solicitudes: una tarjeta por tipo (préstamos, permisos, vacaciones…) con recibidas, por autorizar y en corrección. «Ver todas» abre el tablero completo.
2. **Abre la solicitud** — Revisa motivo, evidencias y la «Cadena de autorización» (quién ya dio visto bueno).
3. **Gerente / regional** _(Gerente · Web y app)_ — «Visto bueno» (web) o desde la app (Equipo → pendientes).
4. **RH** _(RH)_ — Aprobar, rechazar o requerir corrección (las dos últimas exigen comentario).

#### Qué pasa después

- El colaborador recibe cada cambio de estado; lo autorizado queda en su expediente.

#### Qué queda registrado

- Historial completo de la solicitud.

#### Errores comunes

- **«No puedo autorizar.»** Faltan vistos buenos, o tú diste uno (quien da visto bueno no autoriza).
- **«No puedo rechazar.»** Escribe un comentario: es obligatorio.

#### Relacionado con

- [Pedir y autorizar un permiso](#articulo-permisos)
- [Vacaciones](#articulo-vacaciones)
- [Préstamos personales](#articulo-prestamos)
- [Completar o corregir datos (colaborador)](#articulo-completar-datos)

#### En el sistema

- **Ir a Solicitudes** → `/rh/solicitudes` _(permiso: solicitudes.revisar o solicitudes.aprobar)_
- Guía en pantalla → `/ayuda/articulo/revisar-solicitudes`

## 9. Nómina

<a id="articulo-nomina-lotes"></a>

### Recibos de nómina: preparar, revisar y emitir

> Recibos simples, no CFDI. PEOPLE prepara el lote como borrador; RH lo revisa uno por uno y solo al «Emitir» se publica y se avisa.

#### Qué es

Es el recibo interno que recibe cada colaborador por periodo. PEOPLE NO timbra (no es CFDI) ni calcula ISR/IMSS de nómina.

#### Quién lo hace

RH.

> **Importante:** Preparar ≠ publicar. La separación existe para que nadie reciba un recibo con errores: RH revisa todo antes. La emisión automática viene APAGADA; PEOPLE solo prepara el lote quincenal unos días antes del pago y RH lo emite.

#### Antes de empezar

- Cada colaborador debe tener sueldo capturado; quien no lo tiene se reporta como error y no recibe recibo.

#### Pasos

1. **Elige la periodicidad** _(Web)_ — Personal → Recibos de nómina. Semanal: lunes a domingo. Quincenal: del 1 al 15 y del 16 al último día real del mes (octubre 16–31, abril 16–30, febrero 16–28 o 29).
2. **«Preparar lote»** — Desde el sueldo de cada persona (importar el lote desde un archivo es solo del super administrador). TODOS quedan como borrador: no se publican, no se notifican y el trabajador no los ve.
3. **«Revisar lote»** — Resumen: esperados, preparados, errores, advertencias, percepciones, deducciones y neto. Filtra correctos, advertencias o errores.
4. **Revisa persona por persona** — Vista previa del PDF real con el formato oficial, con «Anterior» y «Siguiente». Corrige conceptos si hace falta.
5. **«Emitir N recibos»** — Solo entonces cada recibo se marca emitido y se congela su PDF en el expediente. RH lo imprime y lo entrega al colaborador: el recibo NO se publica en Mi espacio (web ni app).

#### Qué pasa después

- Un lote emitido no se emite dos veces (si se cortó a mitad, volver a emitir publica solo los pendientes).
- «Cancelar lote» (con motivo) cancela sus recibos y permite volver a preparar el periodo. Nunca se borra nada.

#### Notificaciones

- Sin aviso al colaborador: su recibo se entrega impreso (el autoservicio de recibos está apagado).

#### Documentos que se generan

- Recibo de nómina en PDF (dice «RECIBO DE NÓMINA») archivado en el expediente.

#### Qué queda registrado

- Lote con quién lo preparó, revisó y emitió; snapshot del PDF de cada recibo.

#### Errores comunes

- **«Un colaborador sale en Errores.»** Normalmente no tiene sueldo capturado: captúralo en Datos laborales y vuelve a preparar.
- **¿Por qué el colaborador no ve su recibo en la app?** Por diseño: el recibo se entrega IMPRESO. RH genera el PDF desde el lote emitido y lo imprime; el colaborador no tiene «Mis recibos».

#### Relacionado con

- [Revisar solicitudes (gerente, regional y RH)](#articulo-revisar-solicitudes)

#### En el sistema

- **Ir a Recibos de nómina** → `/rh/nomina/lotes` _(permiso: nomina.recibos.ver)_
- Guía en pantalla → `/ayuda/articulo/nomina-lotes`

## 10. Avisos

<a id="articulo-avisos"></a>

### Avisos de Recursos Humanos

> RH envía un aviso (título, mensaje e imagen) a toda la empresa o a una persona; llega por notificación y push.

#### Qué es

Es el canal oficial de comunicados de RH.

#### Quién lo hace

RH, Colaborador.

#### Antes de empezar

- Tener el permiso de enviar avisos.

#### Pasos

1. **Abre Avisos** _(RH · Web)_ — Personal → Avisos.
2. **Redacta** _(RH)_ — A quién le llega (toda la empresa o un colaborador), título, mensaje e imagen opcional.
3. **Envía** _(RH)_ — Se publica al momento.
4. **El colaborador lo lee** _(Colaborador · Web y app)_ — Le llega un push; lo abre en Avisos (app o web) y queda marcado como leído.

#### Qué pasa después

- Los avisos personales quedan en el expediente de la persona (pestaña Avisos / Comunicaciones).

#### Notificaciones

- Push + bandeja de avisos a cada destinatario.

#### Qué queda registrado

- Aviso, destinatarios y lecturas.

#### Errores comunes

- **«No encuentro al colaborador.»** Escribe al menos parte de su nombre; solo aparecen personas de tu alcance.

#### Relacionado con

- [Cómo usar la app MR. LANA PEOPLE](FLUJO_COMPLETO.md#articulo-app-colaborador)

#### En el sistema

- **Ir a Avisos (RH)** → `/rh/avisos` _(permiso: avisos.enviar)_
- **Ir a mis Avisos** → `/avisos`
- Guía en pantalla → `/ayuda/articulo/avisos`

## 11. Vida laboral

<a id="articulo-directorio"></a>

### Directorio y correo corporativo

> Todos ven nombre, puesto, área y sucursal de las personas activas. El correo y teléfono corporativos dependen de tu rol: un colaborador operativo ve los de su jefe directo; el personal corporativo autorizado, RH y Sistemas ven todos. Nunca datos personales.

#### Qué es

El directorio muestra nombre, puesto, área y sucursal. El contacto corporativo (correo y teléfono) se muestra según el permiso «Ver contactos de todo el directorio»; sin él, solo el de tu jefe directo. El correo corporativo es distinto del correo con el que la persona inicia sesión.

#### Quién lo hace

Colaborador, Gerente, Regional, Dirección Comercial, Sistemas, RH.

#### Antes de empezar

- RH capturó el correo/teléfono corporativo en el expediente (Datos personales) de quien lo tiene.

#### Pasos

1. **Buscar** _(Web y app)_ — Directorio (web o app): por nombre o número de empleado, o filtra por puesto, sucursal o departamento.
2. **Contactar** _(App)_ — En la app, toca el correo o el teléfono para escribir o llamar.

#### Errores comunes

- **«Alguien no tiene correo en el directorio.»** No lo tiene capturado: PEOPLE nunca inventa uno ni usa su correo de acceso o personal. RH lo captura en su expediente.

#### Relacionado con

- [El expediente del colaborador](#articulo-expediente)

#### En el sistema

- **Ir al Directorio** → `/directorio`
- Guía en pantalla → `/ayuda/articulo/directorio`

<a id="articulo-celebraciones"></a>

### Cumpleaños y aniversarios

> PEOPLE felicita solo a las 8:00 (cumpleaños y, si está activado, aniversarios). RH ve el calendario y puede avisar a todos.

#### Qué es

Son las celebraciones del equipo: el cumpleaños (fecha de nacimiento) y el aniversario laboral (fecha de ingreso).

#### Quién lo hace

RH, Colaborador.

#### Antes de empezar

- La persona debe tener fecha de nacimiento y fecha de ingreso capturadas.

#### Pasos

1. **Felicitación automática** _(Automático)_ — A las 8:00 (hora de México) el cumpleañero recibe su felicitación en el portal y por push. RH recibe un recordatorio a las 7:30.
2. **Qué hace RH** _(RH)_ — Personal → Celebraciones: cumpleañeros de hoy, calendario y próximos. «Ver tarjeta», «Descargar», «Copiar», «Enviar al colaborador» y «Avisar a todos».
3. **Aniversarios** _(RH)_ — Misma pantalla, pestaña Aniversarios. En su configuración: activar, mensaje con {anios}, envío automático y fondo de la tarjeta.
4. **Qué ve el colaborador** _(Colaborador · Web y app)_ — Su tarjeta y el Muro de felicitaciones, donde el equipo deja mensajes.

#### Qué pasa después

- Cada botón muestra si ya se envió y cuándo, para no felicitar dos veces.

#### Qué queda registrado

- Envíos con fecha y quién; mensajes del muro.

#### Errores comunes

- **«Alguien no aparece.»** Le falta fecha de nacimiento o de ingreso en su expediente.

#### Relacionado con

- [Cómo usar la app MR. LANA PEOPLE](FLUJO_COMPLETO.md#articulo-app-colaborador)

#### En el sistema

- **Ir a Celebraciones** → `/rh/cumpleanos` _(permiso: rh.cumpleanos.ver)_
- **Ir al Muro de felicitaciones** → `/celebraciones`
- Guía en pantalla → `/ayuda/articulo/celebraciones`

<a id="articulo-actas"></a>

### Acta administrativa circunstanciada

> El gerente o RH levanta el acta administrativa circunstanciada sobre alguien de su alcance en seis pasos, revisa la vista previa del PDF real y genera el acta definitiva, que queda congelada en el expediente.

#### Qué es

Un documento administrativo factual con lo que pasó: tipo de incidencia, hechos, personas que intervienen, manifestaciones, anexos y firmas. Registra hechos, no califica ni sanciona. Es el único tipo de acta que se levanta.

#### Quién lo hace

Gerente o subgerente: personas de su sucursal. RH y corporativo con alcance global: toda la organización.

#### Antes de empezar

- La persona está activa y dentro de tu alcance.

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

#### Relacionado con

- [El expediente del colaborador](#articulo-expediente)
- [Baja de un colaborador (forma de salida y causal)](#articulo-baja)

#### En el sistema

- **Ir a Actas** → `/rh/actas` _(permiso: actas.ver)_
- Guía en pantalla → `/ayuda/articulo/actas`

<a id="articulo-actividad-laboral"></a>

### Actividad laboral (historial de movimientos)

> Cada alta, cambio de puesto, de sucursal, de sueldo, ascenso o baja se agrega al historial; nunca lo reemplaza.

#### Qué es

Es la línea de tiempo laboral de la persona. Cada movimiento queda con su fecha, quién lo hizo y los valores antes y después.

#### Quién lo hace

RH.

#### Antes de empezar

- Abrir el expediente de la persona.

#### Pasos

1. **Haz el cambio** _(Web)_ — Expediente → Datos laborales (puesto, sucursal, departamento, sueldo…).
2. **PEOPLE lo registra** _(Automático)_ — Se crea el movimiento: alta, baja, promoción, cambio de puesto, sucursal, departamento, jefe, empresa o sueldo, cobertura temporal o reingreso.
3. **Consulta** — Pestaña «Historial RH» del expediente.

#### Qué pasa después

- Si cambia de puesto o de sucursal, la vacante del lugar que deja se abre sola.
- El jefe directo se recalcula desde el organigrama.

#### Qué queda registrado

- Movimiento laboral con antes → después.

#### Errores comunes

- **«Quiero borrar un movimiento.»** No se puede: el historial es permanente. Registra el movimiento correcto encima.

#### Relacionado con

- [El expediente del colaborador](#articulo-expediente)
- [De dónde nace una vacante](#articulo-plantilla-y-vacantes)

#### En el sistema

- **Ir a Expedientes** → `/rh/expedientes` _(permiso: expedientes.ver_todos o expedientes.ver_sucursal)_
- Guía en pantalla → `/ayuda/articulo/actividad-laboral`

## 12. Baja y finiquito

<a id="articulo-baja"></a>

### Baja de un colaborador (forma de salida y causal)

> La baja NO es una solicitud: el gerente (de su personal) o RH la registra en «Bajas» y queda aplicada en ese momento. Antes de confirmar ves la renuncia o el formato que se generará y el finiquito propuesto. Después se completan los documentos, Jurídico revisa si la causal lo pide y RH la cierra.

#### Qué es

Es el expediente de la baja con folio BAJ-AAAA-NNNN: forma de salida y causal de lista cerrada (sin «Otro»), documentos, bitácora de cambios y el cierre laboral (finiquito). Con esos datos se mide la rotación. Nunca borra a la persona: bloquea su acceso y conserva todo para un posible reingreso.

#### Quién lo hace

Registra el gerente (solo su personal) o RH. RH corrige la causal con motivo, completa datos, cierra, reabre y marca «No procede». Jurídico revisa las causales que lo requieren y no cierra. Contraloría consulta las de faltantes (RE-02) y robo o fraude (RE-03). Nadie más entra a «Bajas»; Dirección solo ve el tablero de rotación y Sistemas recibe el aviso para retirar accesos y equipo.

> **Importante:** Al REGISTRAR la baja se suspende de inmediato el acceso de la persona a la app y al portal. Si RH decide que no procede, la revierte y el acceso vuelve.

> **Importante:** En rescisión, «¿Recontratable?» es «No» y solo RH lo cambia. El aviso de rescisión se adjunta (el sistema no lo genera).

#### Antes de empezar

- La persona está activa y es de tu sucursal (o eres RH).
- Solo puede haber una baja abierta por persona.

#### Pasos

1. **«Registrar baja»** _(Gerente)_ — Cierres laborales → Bajas → «Registrar baja»: 1 ¿quién se va? (sucursal, puesto e ingreso salen solos), 2 ¿cómo se va? (tres tarjetas), 3 ¿por qué se va? (causal con su ayuda, comentario —obligatorio en RV-05 y en rescisión, mínimo 20 caracteres—, último día laborado y ¿recontratable?; en rescisión además fecha en que se conoció la falta y entrega del aviso).
2. **Vista previa y confirmar** _(Gerente)_ — «Vista previa» abre la renuncia (renuncia voluntaria) o el formato de la causal y el finiquito propuesto, con flechas para pasar de uno a otro. Nada se guarda hasta «Sí, registrar baja». Al confirmar: accesos suspendidos, documentos generados y finiquito propuesto. En rescisión no se genera documento: el aviso de rescisión se adjunta.
3. **Documentos y Jurídico** _(RH)_ — En el expediente de la baja o en Bajas y finiquitos → «Documentos»: imprime y firma lo generado, adjunta lo que falta. Si la causal lo requiere, la baja pasa sola a Jurídico, que la aprueba o la devuelve a RH con observaciones.
4. **Finiquito y «Cerrar baja»** _(RH)_ — RH revisa / ajusta el finiquito y registra su entrega (Bajas y finiquitos). Con los documentos obligatorios completos, «Cerrar baja»: la persona queda inactiva y su acceso bloqueado de forma definitiva. Una baja cerrada solo se reabre con motivo; «No procede» la revierte y devuelve el acceso.

#### Qué pasa después

- Baja cerrada → cuenta en la rotación de su mes (salvo que RH la marque como movimiento interno).
- La vacante de esa sucursal y puesto se abre sola.
- Si alguien con baja «No recontratable» vuelve como candidato, Reclutamiento ve una alerta.

#### Notificaciones

- «Baja registrada» a RH, a la regional y a Sistemas: quién la dio, a quién, forma · causal y último día.
- Causal con Jurídico: «Baja por revisar (Jurídico)».
- RV-04 (clima laboral): correo a RH para entrevista de salida y NOM-035.
- RE-02 y RE-03: aviso a Contraloría (RE-03 también a Jurídico).
- Antigüedad menor a 90 días: rotación temprana a RH.

#### Documentos que se generan

- Renuncia: carta de renuncia firmada, entrevista de salida, finiquito firmado
- No renovación: convenio o aviso de término, finiquito o liquidación firmado
- Rescisión: acta administrativa con testigos, aviso de rescisión entregado, finiquito firmado
- Adicionales según la causal (evidencia de desempeño, autorización de Dirección General, arqueo, evidencia de la falta)

#### Qué queda registrado

- Bitácora de la baja: quién, cuándo, valor anterior y nuevo de cada cambio (causal, recontratable, fechas, documentos, estatus).

#### Errores comunes

- **«No me deja registrar.»** Faltan persona, forma, causal, último día laborado o ¿recontratable? (y en rescisión los datos del aviso). La pantalla marca cada campo.
- **«RH no puede cerrar.»** Falta un documento obligatorio, la revisión de Jurídico o el finiquito revisado. El expediente dice exactamente qué falta.

#### Relacionado con

- [Finiquito](#articulo-finiquito)
- [Reingreso de un excolaborador](#articulo-reingreso)
- [Cuentas, credenciales y roles (Sistemas)](#articulo-usuarios-y-roles)

#### En el sistema

- **Ir a Bajas** → `/rh/bajas` _(permiso: bajas.ver o bajas.revisar_juridico o bajas.consulta_contraloria)_
- **Documentos de las bajas** → `/rh/cierres` _(permiso: cierres.ver)_
- **Tablero de rotación** → `/reportes/rotacion/tablero` _(permiso: bajas.tablero.ver)_
- Guía en pantalla → `/ayuda/articulo/baja`

<a id="articulo-finiquito"></a>

### Finiquito

> PEOPLE calcula sueldo pendiente, aguinaldo, vacaciones, prima vacacional e ISR; RH revisa, ajusta montos y registra la entrega, que cierra la baja.

#### Qué es

Es el cálculo del pago final de la persona que se va.

#### Quién lo hace

RH.

#### Antes de empezar

- La baja ya está registrada (Bajas o ciclo laboral): el finiquito propuesto se calcula al registrarla.

#### Pasos

1. **«Calcular finiquito»** — Desde el ciclo laboral. El sueldo se toma del salario diario registrado (× 30) y puedes cambiarlo. Al terminar se abre el desglose.
2. **Revisa los conceptos** — Percepciones: sueldo pendiente, aguinaldo proporcional, vacaciones proporcionales, prima vacacional, indemnización y bonos extra (si aplican). Deducciones: ISR retenido, otras deducciones y adeudos.
3. **Ajusta montos** — «Ver / editar finiquito» (en el estado del cierre o en la tarjeta «Finiquito» de Documentos de la baja): el lápiz de cada concepto cambia su importe con motivo; «Agregar renglón» suma una percepción o deducción. El total lo recalcula PEOPLE y queda quién y cuándo editó.
4. **Vista previa PDF** — Revisa el formato del finiquito con los montos actuales.
5. **«Registrar entrega de finiquito»** — Fecha, forma de pago y comentario. El finiquito queda congelado (solo lectura) y el cierre laboral completo.

#### Qué pasa después

- Después de la entrega el finiquito se puede ver e imprimir, pero ya no editar.

#### Documentos que se generan

- Finiquito en PDF

#### Qué queda registrado

- Cálculo, cada ajuste con motivo y autor, y la entrega (fecha, forma de pago, quién).

#### Errores comunes

- **«No puedo ajustar el finiquito.»** Ya se registró su entrega: queda congelado tal como se entregó.
- **«No me deja registrar la entrega.»** La baja todavía no llega a su fecha efectiva, o la fecha de entrega es futura.
- **«Un ajuste no se guarda.»** Escribe el motivo: todo ajuste queda auditado.

#### Relacionado con

- [Baja de un colaborador (forma de salida y causal)](#articulo-baja)

#### En el sistema

- **Ir a Solicitudes** → `/rh/solicitudes` _(permiso: solicitudes.revisar o solicitudes.aprobar)_
- Guía en pantalla → `/ayuda/articulo/finiquito`

## 13. Reingresos

<a id="articulo-reingreso"></a>

### Reingreso de un excolaborador

> Nunca se crea otra persona: se busca a la anterior, RH decide y se reactiva con su mismo historial en un ciclo laboral nuevo.

#### Qué es

Es volver a contratar a alguien que ya trabajó en MR. LANA. Se conserva su cuenta, su expediente y toda su vida laboral anterior.

#### Quién lo hace

Gerente, RH.

#### Antes de empezar

- La persona tiene la baja concluida (no puede tener un cierre sin terminar ni estar activa).

#### Pasos

1. **Busca a la persona** _(Web y app)_ — Desarrollo → Reingresos: por número de empleado, CURP, RFC, NSS o nombre. Ves su causa de salida antes de solicitar.
2. **Solicita el reingreso** _(Gerente)_ — Quien solicita propone (gerente o RH).
3. **RH decide** _(RH)_ — «Decidir reingreso»: viable o no viable (con motivo).
4. **Nuevo ciclo** — Si es viable: se reactiva la MISMA persona, se piden solo documentos vencidos o faltantes, nuevo contrato, firmas y onboarding aplicable, hasta quedar activa.

#### Qué pasa después

- La vida laboral anterior (cierres, contratos, evaluaciones, documentos) se conserva intacta.

#### Qué queda registrado

- Movimiento «Reingreso» en su actividad laboral y la decisión de RH.

#### Errores comunes

- **«Una persona activa no puede reingresar.»** Revisa que la baja esté concluida.
- **«Ya existe una cuenta con ese correo.»** Es un reingreso: búscala en Reingresos en lugar de registrarla como candidato nuevo.

#### Relacionado con

- [Baja de un colaborador (forma de salida y causal)](#articulo-baja)
- [Del candidato al colaborador (sin duplicar a nadie)](#articulo-candidato-a-colaborador)

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

#### Antes de empezar

- Participar en algún paso del ciclo laboral (tu rol lo determina).

#### Pasos

1. **Abre Mis pendientes** _(Web y app)_ — Inicio → Mis pendientes (o la pestaña Pendientes de la app).
2. **Atiende lo urgente** — Filtra por etapa, sucursal o prioridad; primero lo vencido.
3. **Abre el pendiente** — Te lleva directo a la ficha del candidato o al ciclo laboral de la persona, donde está el botón de la acción.

#### Qué pasa después

- La campana de notificaciones también te avisa de cada pendiente nuevo.

#### Errores comunes

- **«Un pendiente no es mío.»** Los pendientes se asignan por el organigrama (jefe directo) y por permisos. Si el jefe de alguien está mal, se corrige en el organigrama, no a mano.

#### Relacionado con

- [Las fases del candidato](#articulo-etapas-del-candidato)
- [Firma de contratos y control del original](#articulo-firma-y-original)
- [Evaluaciones de periodo de prueba](#articulo-evaluacion-capacitacion)

#### En el sistema

- **Ir a Mis pendientes** → `/rh/pendientes`
- Guía en pantalla → `/ayuda/articulo/mis-pendientes`

<a id="articulo-estructura-y-plantilla"></a>

### Estructura, plantilla autorizada y duración por puesto

> Empresas, sucursales, departamentos y puestos. Gerencia de RH captura la plantilla autorizada (con motivo) y la duración de capacitación de cada puesto.

#### Qué es

Es el esqueleto del sistema: de aquí salen las vacantes, el organigrama y los contratos.

#### Quién lo hace

La plantilla autorizada solo la cambia Gerencia de RH (y super administración).

> **Importante:** Organigrama, Matriz comercial, Headcount y Vacantes son cosas distintas: el organigrama dice quién reporta a quién; la matriz, qué ruta cubre cada gestor; el headcount, cuántas plazas hay; las vacantes, cuántas faltan.

> **Nota:** Sucursales y Departamentos son módulos distintos: en Sucursales vive la plantilla autorizada de cada sucursal; en Departamentos, el catálogo de áreas y el cambio de departamento en lote. Cambiar de departamento no cambia puesto, sucursal ni plantilla.

#### Antes de empezar

- Tener permisos de administración de catálogos.

#### Pasos

1. **Plantilla autorizada** _(Web)_ — Organización → Sucursales → detalle de la sucursal → lápiz en el puesto (o «Agregar puesto»): nueva cifra y motivo obligatorio. La vacante del par se recalcula al guardar.
2. **Duración de capacitación por puesto** — Administración → Configuración → Parámetros de RH: meses de capacitación inicial de cada puesto (por ejemplo, Gestor 2; Gerente y Regional 3) y su grupo (Gestores, Gerencia…).
3. **Otros parámetros de RH** — Calificación mínima del onboarding, días de aviso antes del vencimiento (15), calificación mínima de la evaluación, causas de baja solicitables, domicilio del patrón en documentos y vigencia de documentos.
4. **Organigrama** — Organización → Organigrama: quién reporta a quién. De aquí sale el jefe directo de cada persona.
5. **Mover personas entre departamentos** _(Web)_ — Organización → Departamentos → abre el departamento: marca a todas o a algunas personas (el buscador filtra la lista mientras escribes), elige el departamento destino, revisa el resumen («Vas a mover 3 personas de Ventas a Operaciones») y confirma. Cada persona queda con su movimiento laboral «Cambio de departamento».

#### Qué pasa después

- Cada cambio queda en la bitácora con antes → después.

#### Qué queda registrado

- Historial de plantilla por sucursal y bitácora de configuración.

#### Errores comunes

- **«No puedo editar la plantilla.»** Solo Gerencia de RH (permiso de editar headcount).
- **«No aparece la casilla para moverme a mí.»** Nadie cambia su propio departamento: pídeselo a otra persona de RH.

#### Relacionado con

- [De dónde nace una vacante](#articulo-plantilla-y-vacantes)
- [Contrato de capacitación inicial](#articulo-contrato-inicial)

#### En el sistema

- **Ir a Sucursales** → `/administracion/sucursales` _(permiso: sucursales.administrar)_
- **Ir a Parámetros de RH** → `/administracion/configuracion/parametros-rh` _(permiso: configuracion.rh)_
- **Ir al Organigrama** → `/administracion/jerarquia-puestos` _(permiso: organigrama.ver)_
- **Ir a Departamentos** → `/administracion/departamentos` _(permiso: departamentos.administrar)_
- Guía en pantalla → `/ayuda/articulo/estructura-y-plantilla`

<a id="articulo-usuarios-y-roles"></a>

### Cuentas, credenciales y roles (Sistemas)

> Sistemas recibe las altas y bajas para preparar o retirar accesos. Las cuentas nunca se borran: se bloquean.

#### Qué es

La cuenta (usuario y contraseña) es distinta de la persona (expediente). Quitar el acceso nunca borra al colaborador.

#### Quién lo hace

Sistemas, RH.

> **Nota:** Usuario = primer nombre + primer apellido sin acentos (por ejemplo «Juan Perez»); si se repite, «Juan Perez2». El correo es opcional. La contraseña temporal obliga a cambiarla al primer inicio.

#### Antes de empezar

- Tener permiso de usuarios o de roles.

#### Pasos

1. **Alta → preparar accesos** _(Sistemas)_ — Llega «Alta: Nombre (Puesto, Sucursal). Prepara su cuenta, accesos y equipo.» Revisa sus roles en Administración → Usuarios.
2. **«Generar credenciales»** _(RH)_ — Expediente → pestaña Cuenta (o Usuarios → «Nuevo usuario»): crea la cuenta o da una contraseña temporal nueva. Muestra usuario y contraseña una sola vez para entregarlos en privado.
3. **Baja → retirar accesos** _(Sistemas)_ — Llega «Baja: Nombre… Retira accesos, cuentas y equipo.» PEOPLE ya bloqueó su acceso a la app y al portal; Sistemas retira lo externo (correo, equipo).
4. **Roles y permisos** _(Sistemas)_ — Administración → Roles y permisos: clona un rol parecido y ajusta permisos.

#### Qué pasa después

- Revocar acceso solo bloquea el inicio de sesión (incapacidad, suspensión); el empleo no cambia.

#### Notificaciones

- Alta y baja de colaborador → RH y Sistemas.

#### Qué queda registrado

- Cambios de cuenta y roles en la bitácora.

#### Errores comunes

- **«No puedo generar credenciales a alguien de baja.»** Es correcto: primero debe reactivarse o reingresar.
- **«No puedo generarme credenciales a mí mismo.»** Por seguridad nadie opera su propia cuenta.

#### Relacionado con

- [Alta real: colaborador activo](#articulo-alta-activa)
- [Baja de un colaborador (forma de salida y causal)](#articulo-baja)

#### En el sistema

- **Ir a Usuarios** → `/administracion/usuarios` _(permiso: usuarios.ver)_
- **Ir a Roles y permisos** → `/administracion/roles` _(permiso: roles.administrar)_
- Guía en pantalla → `/ayuda/articulo/usuarios-y-roles`

<a id="articulo-configuracion-notificaciones"></a>

### A quién le llega cada notificación

> Configuración → Notificaciones decide quién se entera de cada evento. Notificar no es autorizar.

#### Qué es

Es el ruteo de avisos: para cada momento del sistema eliges a quién avisar.

#### Quién lo hace

RH, Sistemas.

> **Importante:** Cambiar quién se ENTERA no cambia quién AUTORIZA. Recursos Humanos siempre da la autorización final.

#### Antes de empezar

- Permiso de configuración de notificaciones.

#### Pasos

1. **Abre Notificaciones** _(Web)_ — Administración → Configuración → Notificaciones.
2. **Edita un evento** — Por ejemplo «Alta de colaborador»: RH y Sistemas. Puedes elegir quien lo pidió, su jefe directo, el gerente, el regional, Gerencia de RH, RH, quienes pueden hacer cierta acción o personas con nombre.
3. **Usa el «?»** — Cada opción explica para qué sirve antes de cambiarla.

#### Qué pasa después

- Si un evento se queda sin destinatario, PEOPLE avisa al respaldo seguro (RH) y lo registra.

#### Qué queda registrado

- Cada cambio de regla queda en la bitácora.

#### Relacionado con

- [Cuentas, credenciales y roles (Sistemas)](#articulo-usuarios-y-roles)

#### En el sistema

- **Ir a Notificaciones** → `/administracion/configuracion/notificaciones` _(permiso: configuracion.notificaciones)_
- Guía en pantalla → `/ayuda/articulo/configuracion-notificaciones`

<a id="articulo-documentos-maestros"></a>

### Documentos maestros (formatos de Jurídico)

> Los formatos originales se cargan una vez; PEOPLE los llena con los datos de cada persona en su proceso.

#### Qué es

Son los formatos oficiales de MR. LANA (contratos, permiso, préstamo, baja, actas). No se escriben marcadores: PEOPLE pone los datos donde corresponden.

#### Quién lo hace

Gerencia de RH (y Jurídico consulta).

#### Antes de empezar

- Tener el original que entregó Jurídico.

#### Pasos

1. **Carga la versión** _(Web)_ — Administración → Documentos maestros → el documento → cargar nueva versión del original.
2. **Prueba** — Revisa los campos detectados y genera una prueba con un colaborador.
3. **Activa** — Los procesos nuevos usan la versión activa; lo ya generado no cambia.

#### Qué pasa después

- Si a un puesto le falta su formato, el proceso avisa «Falta el formato oficial» en lugar de usar otro.

#### Qué queda registrado

- Quién cargó, probó, activó o desactivó cada versión.

#### Errores comunes

- **«El contrato dice Falta el formato oficial.»** Carga y activa la versión de ese documento.

#### Relacionado con

- [Contrato de capacitación inicial](#articulo-contrato-inicial)
- [Pedir y autorizar un permiso](#articulo-permisos)
- [Préstamos personales](#articulo-prestamos)

#### En el sistema

- **Ir a Documentos maestros** → `/rh/documentos-maestros` _(permiso: plantillas_documentales.administrar)_
- Guía en pantalla → `/ayuda/articulo/documentos-maestros`

<a id="articulo-directorio"></a>

### Directorio y correo corporativo

> Todos ven nombre, puesto, área y sucursal de las personas activas. El correo y teléfono corporativos dependen de tu rol: un colaborador operativo ve los de su jefe directo; el personal corporativo autorizado, RH y Sistemas ven todos. Nunca datos personales.

#### Qué es

El directorio muestra nombre, puesto, área y sucursal. El contacto corporativo (correo y teléfono) se muestra según el permiso «Ver contactos de todo el directorio»; sin él, solo el de tu jefe directo. El correo corporativo es distinto del correo con el que la persona inicia sesión.

#### Quién lo hace

Colaborador, Gerente, Regional, Dirección Comercial, Sistemas, RH.

#### Antes de empezar

- RH capturó el correo/teléfono corporativo en el expediente (Datos personales) de quien lo tiene.

#### Pasos

1. **Buscar** _(Web y app)_ — Directorio (web o app): por nombre o número de empleado, o filtra por puesto, sucursal o departamento.
2. **Contactar** _(App)_ — En la app, toca el correo o el teléfono para escribir o llamar.

#### Errores comunes

- **«Alguien no tiene correo en el directorio.»** No lo tiene capturado: PEOPLE nunca inventa uno ni usa su correo de acceso o personal. RH lo captura en su expediente.

#### Relacionado con

- [El expediente del colaborador](#articulo-expediente)

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

#### Relacionado con

- [Importar salarios y vacaciones (REPORTE_TRABAJADORES_VAC)](FLUJO_COMPLETO.md#articulo-importador-salarios)
