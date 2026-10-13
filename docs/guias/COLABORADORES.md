<!-- Archivo GENERADO por `npm run guia:docs` desde resources/js/lib/guia. No lo edites a mano: cambia el contenido allá y vuelve a generar. -->

# Cómo usar la app MR. LANA PEOPLE

Guía sencilla para el colaborador: registrarte con tu QR, entrar, completar tus datos, tus lecciones de bienvenida, pedir permisos, vacaciones y préstamos, y ver tus avisos y recibos. Lo mismo está en la app y en «Mi espacio» de la web.

## Colaborador

Usa la app (o «Mi espacio» en la web) para completar su expediente, responder sus lecciones, pedir permisos, vacaciones y préstamos, y ver avisos y recibos.

**Dónde trabaja:** App MR. LANA PEOPLE (y portal web).

**Roles del sistema:** `colaborador`

### Tu checklist

- [ ] Registrarme con el QR → [Registro del colaborador en la app](#articulo-registro-en-app)
- [ ] Iniciar sesión y usar la app → [Cómo usar la app MR. LANA PEOPLE](#articulo-app-colaborador)
- [ ] Subir mis documentos → [Documentos del alta y revisión de RH](#articulo-documentos-de-alta)
- [ ] Completar mis datos → [Completar o corregir datos (colaborador)](#articulo-completar-datos)
- [ ] Lecciones de bienvenida → [Onboarding: la bienvenida del colaborador](#articulo-onboarding)
- [ ] Pedir un permiso → [Pedir y autorizar un permiso](#articulo-permisos)
- [ ] Pedir vacaciones → [Vacaciones](#articulo-vacaciones)
- [ ] Pedir un préstamo → [Préstamos personales](#articulo-prestamos)
- [ ] Ver mis recibos → [Tu recibo de nómina (impreso)](#articulo-mis-recibos)
- [ ] Avisos → [Avisos de Recursos Humanos](#articulo-avisos)
- [ ] Cumpleaños y aniversarios → [Cumpleaños y aniversarios](#articulo-celebraciones)

### Lo que NO le toca

- Editar su expediente directo (propone y RH autoriza).

---

## 2. Contratación

<a id="articulo-qr-incorporacion"></a>

### El QR de incorporación

> No es solo un formulario: es la puerta de entrada de la persona. Al escanearlo crea su cuenta sobre el mismo colaborador que preparó RH.

#### Qué es

Es un código temporal y de un solo uso, ligado al candidato y a su colaborador. Nadie puede registrarse en la app sin una invitación activa.

#### Quién lo hace

RH, Colaborador.

#### Pasos

1. **Qué ve RH** _(RH)_ — En la ficha del candidato, el bloque «QR de contratación» con su estado y vencimiento, y «Ver / regenerar QR». En el detalle: copiar liga, descargar PNG, regenerar o revocar.
2. **Qué recibe la persona** _(Colaborador)_ — El QR impreso o la liga (por WhatsApp, por ejemplo). Al escanearlo, su celular abre la pantalla de incorporación de MR. LANA PEOPLE: si tiene la app, la abre; si no, ofrece «Descargar app».
3. **Qué ve el gerente** _(Gerente)_ — El candidato en la fase «Contratación» del tablero y, más adelante, los contratos por imprimir en sus pendientes.
4. **Si venció o no se usó** _(RH)_ — Reclutamiento → Invitaciones QR → «Nueva invitación»: elige a la persona en contratación (solo aparecen quienes no tienen un QR vigente) y su vigencia de 1 a 24 horas.

#### Qué pasa después

- Al usarse, la invitación queda «Usada» y no vuelve a funcionar.
- Un token inválido, vencido o revocado muestra un mensaje claro, nunca un error.

#### Errores comunes

- **«El QR ya no funciona.»** Venció (vive como máximo 24 h) o ya se usó. Regénéralo desde la invitación o crea una nueva.
- **«No encuentro a la persona en Nueva invitación.»** Solo aparecen personas en contratación sin un QR vigente y sin cuenta. Si su QR sigue vigente, compártelo de nuevo.

#### En el sistema

- **Ir a Invitaciones QR** → `/rh/incorporacion/invitaciones` _(permiso: rh.incorporacion.invitaciones.ver)_
- Guía en pantalla → `/ayuda/articulo/qr-incorporacion`

## 3. Alta del colaborador

<a id="articulo-registro-en-app"></a>

### Registro del colaborador en la app

> La persona escanea el QR, confirma sus datos básicos y crea su contraseña. Después sube documentos y completa su información.

#### Qué es

Es lo que hace la persona nueva desde su celular para crear su cuenta. El formulario es corto a propósito: lo laboral ya lo definió RH.

#### Quién lo hace

Colaborador.

> **Importante:** La persona NO captura empresa, sucursal, puesto, vacante, fecha de ingreso ni tipo de contrato: vienen del proceso de RH y de la vacante. Así no quedan a criterio del candidato.

#### Pasos

1. **Escanea el QR** _(App)_ — Con la cámara del celular o desde «Escanear QR» en la pantalla de inicio de sesión de la app.
2. **Confirma tus datos** _(App)_ — Nombre (obligatorio), apellidos, correo electrónico (opcional) y teléfono.
3. **Crea tu contraseña** _(App)_ — Mínimo 8 caracteres y confírmala. Tu usuario se genera solo: primer nombre + primer apellido (por ejemplo «Juan Perez»).
4. **Sigue la hoja de ruta** _(App)_ — La app muestra tres fases: Datos personales → Documentos → Revisión RH.

#### Qué pasa después

- Se crea su cuenta (rol colaborador) sobre el MISMO colaborador que preparó RH; la invitación queda usada.
- Su acceso es de incorporación: puede entrar a la app a subir documentos y completar datos, pero todavía no es colaborador activo.

#### Errores comunes

- **«El correo no coincide con la invitación.»** Usa el correo que registró RH o déjalo vacío.
- **«Ese correo ya está registrado.»** Pide a RH que revise: puede tratarse de un reingreso.

#### En el sistema

- **Descargar la app** → `/app`
- Guía en pantalla → `/ayuda/articulo/registro-en-app`

<a id="articulo-documentos-de-alta"></a>

### Documentos del alta y revisión de RH

> La persona sube los 11 documentos obligatorios desde la app; RH los aprueba, rechaza o pide corrección.

#### Qué es

Es la integración del expediente de la persona nueva. Solo se piden los documentos obligatorios del alta; los demás (contratos firmados, permisos…) nacen después.

#### Quién lo hace

Colaborador, RH.

#### Pasos

1. **Sube cada documento** _(Colaborador · App)_ — App → «Mi incorporación»: toca cada documento pendiente y súbelo (foto o PDF).
2. **Revisa en el expediente** _(RH · Web y app)_ — Expedientes → la persona → «Documentos»: abre cada archivo sin salir de PEOPLE y aprueba, rechaza (con motivo) o pide corrección.
3. **Aprovecha la lectura automática** _(RH)_ — De identificaciones como INE, CURP o constancia fiscal, PEOPLE puede proponer datos (nombre, CURP, RFC…). RH revisa y decide aplicarlos o ignorarlos.

#### Qué pasa después

- Cada documento rechazado le llega a la persona con su motivo (notificación y push) para que lo vuelva a subir.
- Cuando el último obligatorio queda aprobado, RH recibe «Expediente completo» y el pendiente «Generar paquete de contratación».
- Un documento ya aprobado no se reemplaza directo: la persona solicita el cambio y RH lo autoriza.

#### Errores comunes

- **«El avance no sube.»** Solo cuentan los documentos APROBADOS; cargado o en revisión todavía no.
- **«No puedo volver a subir un documento aprobado.»** Toca «Solicitar cambio»: cuando RH lo autorice, podrás subir la nueva versión.

#### En el sistema

- **Ir a Expedientes** → `/rh/expedientes` _(permiso: expedientes.ver_todos o expedientes.ver_sucursal)_
- Guía en pantalla → `/ayuda/articulo/documentos-de-alta`

## 4. Expediente

<a id="articulo-expediente"></a>

### El expediente del colaborador

> La carpeta digital de la persona, organizada en pestañas. Nada se borra nunca.

#### Qué es

Es todo lo que RH guarda de una persona: datos, documentos, cuenta, onboarding, avisos, vacaciones, recibos, préstamos, solicitudes e historial.

#### Quién lo hace

RH ve todos los expedientes; gerencias y coordinadoras, los de su sucursal; el colaborador, solo el suyo (Mi expediente).

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

<a id="articulo-completitud-expediente"></a>

### Qué le falta a un expediente (y cómo avisar)

> El expediente no muestra solo un porcentaje: dice qué sección está completa y cuál no, y RH puede avisarle al colaborador.

#### Qué es

Es el semáforo por secciones del expediente: Identidad, Fiscal, Laboral, Domicilio, Contacto, Contacto de emergencia, Beneficiario y Documentos.

#### Quién lo hace

RH, Colaborador.

#### Pasos

1. **Lee el aviso de datos faltantes** _(RH)_ — Por ejemplo: Identidad ✓ · Fiscal ✓ · Laboral ✓ · Domicilio ⚠ (falta el código postal) · Contacto de emergencia ✗ · Documentos ✓.
2. **«Avisar al colaborador»** _(RH)_ — Le llega una notificación y un push con la lista de lo que falta. Se puede repetir una vez cada 24 horas.
3. **El colaborador completa** _(Colaborador · App)_ — App → Inicio → «Completa tu información» → envía los datos a RH.

#### Qué pasa después

- Cuando RH autoriza los datos propuestos, el semáforo se recalcula solo.

#### Errores comunes

- **«El botón Avisar está deshabilitado.»** Ya se avisó en las últimas 24 horas, o la persona ya envió una solicitud que está en revisión.

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

#### Pasos

1. **Abre «Completa tu información»** _(Colaborador · Web y app)_ — App → Inicio → «Completa tu información» (o en la web, Mi expediente → «Solicitar corrección»).
2. **Captura lo que falta** _(Colaborador)_ — Solo aparecen los datos que tu contrato pide y no tienes.
3. **«Enviar a RH»** _(Colaborador)_ — Verás «Enviado a Recursos Humanos».
4. **RH revisa** _(RH)_ — Solicitudes → la solicitud muestra el dato, el valor actual y el valor propuesto. RH autoriza o rechaza.

#### Qué pasa después

- Al autorizar, los datos pasan solos al expediente y la completitud se recalcula.

#### Errores comunes

- **«Ya envié mis datos y no cambian.»** Espera la autorización de RH; puedes seguirla en Mis solicitudes.

#### En el sistema

- **Ir a Mi expediente** → `/mi-expediente`
- Guía en pantalla → `/ayuda/articulo/completar-datos`

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

<a id="articulo-induccion-mr-lana"></a>

### Inducción Mr. Lana y su evaluación

> El PDF oficial de la inducción, página por página; al terminarlo se habilita la evaluación oficial de 30 puntos (mínimo 24).

#### Qué es

Es la inducción institucional en su documento oficial (Quiénes somos, Misión/visión/principios, Cultura, Organigrama, Reglamento interior, Modelo de negocio y Empresa con factor humano), tal cual lo entregó la empresa. El avance se guarda en el servidor y se retoma donde se quedó.

#### Quién lo hace

El colaborador recorre el documento y presenta su evaluación; RH califica los casos prácticos y, si hace falta, da reforzamiento. RH también puede PRESENTAR la inducción por Meet.

#### Pasos

1. **Recorrer la inducción** _(Colaborador · Web y app)_ — Mis pendientes → «Inducción Mr. Lana» (web o app). Cada página mostrada cuenta («12 / 30»); en la última, «Terminé la inducción» lleva a la evaluación.
2. **Presentar la evaluación** _(Colaborador · Web y app)_ — Secciones A (opción múltiple), B (verdadero/falso), C (relaciona columnas), D (casos prácticos) y E (opinión, no califica). La clave de respuestas nunca llega al teléfono ni al navegador.
3. **RH califica los casos prácticos** _(RH · Web)_ — Con la rúbrica oficial (solo RH la ve). Resultado: 27–30 Sobresaliente · 24–26 Aprobado · 0–23 Requiere reforzamiento.
4. **Si requiere reforzamiento** _(RH)_ — RH deja retroalimentación y habilita la reevaluación (plazo de 5 días hábiles). El colaborador no puede reintentar antes.
5. **Modo presentación (Meet)** _(RH · Web)_ — Desarrollo → «Presentar inducción»: los mismos capítulos para impartirla en sesión, sin registrar avance de nadie.

#### Qué pasa después

- Aprobada: se aprueba el módulo de inducción y se desbloquea SOLO el siguiente módulo del onboarding.

#### Errores comunes

- **«No me aparece la evaluación.»** Primero hay que recorrer todas las páginas de la inducción desde Mis pendientes y pulsar «Terminé la inducción» (el modo presentación no cuenta).

#### En el sistema

- **Presentar inducción** → `/induccion/presentacion` _(permiso: onboarding.gestionar)_
- **Ir a Mis pendientes** → `/mi-proceso`
- Guía en pantalla → `/ayuda/articulo/induccion-mr-lana`

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

## 9. Nómina

<a id="articulo-mis-recibos"></a>

### Tu recibo de nómina (impreso)

> El recibo de nómina se entrega IMPRESO: RH lo genera en PDF y te lo da; no se consulta en Mi espacio ni en la app.

#### Qué es

Cada periodo RH emite el recibo interno de nómina (PDF con el formato oficial), lo archiva en tu expediente y lo imprime para entregártelo.

#### Quién lo hace

Colaborador, RH.

#### Pasos

1. **RH emite e imprime** _(RH · Web)_ — Personal → Recibos de nómina: RH emite el lote y descarga/imprime el PDF de cada recibo.
2. **Recibes tu recibo impreso** _(Colaborador)_ — Tu gerente o RH te lo entrega en sucursal.

#### Qué pasa después

- El PDF queda archivado en tu expediente (lo consulta RH).

#### Errores comunes

- **«No veo mis recibos en la app.»** Es correcto: el recibo se entrega impreso. Si no lo recibiste, pídelo a Recursos Humanos.

#### En el sistema

- **Ir a Recibos de nómina** → `/rh/nomina/lotes` _(permiso: nomina.recibos.ver)_
- Guía en pantalla → `/ayuda/articulo/mis-recibos`

## 10. Avisos

<a id="articulo-avisos"></a>

### Avisos de Recursos Humanos

> RH envía un aviso (título, mensaje e imagen) a toda la empresa o a una persona; llega por notificación y push.

#### Qué es

Es el canal oficial de comunicados de RH.

#### Quién lo hace

RH, Colaborador.

#### Pasos

1. **Abre Avisos** _(RH · Web)_ — Personal → Avisos.
2. **Redacta** _(RH)_ — A quién le llega (toda la empresa o un colaborador), título, mensaje e imagen opcional.
3. **Envía** _(RH)_ — Se publica al momento.
4. **El colaborador lo lee** _(Colaborador · Web y app)_ — Le llega un push; lo abre en Avisos (app o web) y queda marcado como leído.

#### Qué pasa después

- Los avisos personales quedan en el expediente de la persona (pestaña Avisos / Comunicaciones).

#### Errores comunes

- **«No encuentro al colaborador.»** Escribe al menos parte de su nombre; solo aparecen personas de tu alcance.

#### En el sistema

- **Ir a Avisos (RH)** → `/rh/avisos` _(permiso: avisos.enviar)_
- **Ir a mis Avisos** → `/avisos`
- Guía en pantalla → `/ayuda/articulo/avisos`

## 11. Vida laboral

<a id="articulo-app-colaborador"></a>

### Cómo usar la app MR. LANA PEOPLE

> Iniciar sesión, cambiar tu contraseña, tu foto, tus datos, lecciones, solicitudes, vacaciones, préstamos, avisos, directorio e inducción.

#### Qué es

Es la guía rápida para el colaborador. Lo mismo está en el portal web («Mi espacio»).

#### Quién lo hace

Colaborador.

#### Pasos

1. **Iniciar sesión** _(App)_ — Usuario = tu primer nombre + tu primer apellido (por ejemplo «Juan Perez»), sin acentos; y tu contraseña.
2. **Cambiar la contraseña temporal** _(App)_ — Si RH te dio una contraseña temporal, la app te pide cambiarla al entrar; no puedes usar nada más hasta hacerlo.
3. **Inicio** _(App)_ — Lo que necesitas hacer (documentos, firmas, lecciones), «Completa tu información» si te falta algo, y accesos rápidos.
4. **Perfil y foto** _(App)_ — Tu primera foto queda al instante. Si ya tienes foto oficial, la nueva queda como propuesta hasta que RH la apruebe.
5. **Expediente** _(App)_ — Tus documentos y su estado; sube lo que te pidan.
6. **Solicitudes y Vacaciones** _(App)_ — Permisos, vacaciones y préstamos, con su estado.
7. **Lecciones de bienvenida** _(App)_ — Si eres nuevo, respóndelas desde Inicio.
8. **Avisos y notificaciones** _(App)_ — La campana muestra tus notificaciones; Avisos, los comunicados de RH.
9. **Directorio e Inducción** _(App)_ — Directorio de contacto corporativo y la Inducción Mr. Lana. Tu recibo de nómina se entrega impreso (no está en la app).
10. **Celebraciones** _(App)_ — Felicitaciones de cumpleaños y aniversario, y el muro de felicitaciones.

#### Qué pasa después

- Si olvidaste tu contraseña y no tienes correo registrado, pide a RH una contraseña temporal.

#### Errores comunes

- **«No puedo entrar.»** Revisa tu usuario (nombre + primer apellido). Si sigue fallando, pide a RH que genere credenciales nuevas.
- **«No puedo cambiar mis datos.»** Usa «Completa tu información» o «Solicitar corrección»: RH los autoriza.

#### En el sistema

- **Descargar la app** → `/app`
- **Ir a Mi portal** → `/mi-portal`
- Guía en pantalla → `/ayuda/articulo/app-colaborador`

<a id="articulo-celebraciones"></a>

### Cumpleaños y aniversarios

> PEOPLE felicita solo a las 8:00 (cumpleaños y, si está activado, aniversarios). RH ve el calendario y puede avisar a todos.

#### Qué es

Son las celebraciones del equipo: el cumpleaños (fecha de nacimiento) y el aniversario laboral (fecha de ingreso).

#### Quién lo hace

RH, Colaborador.

#### Pasos

1. **Felicitación automática** _(Automático)_ — A las 8:00 (hora de México) el cumpleañero recibe su felicitación en el portal y por push. RH recibe un recordatorio a las 7:30.
2. **Qué hace RH** _(RH)_ — Personal → Celebraciones: cumpleañeros de hoy, calendario y próximos. «Ver tarjeta», «Descargar», «Copiar», «Enviar al colaborador» y «Avisar a todos».
3. **Aniversarios** _(RH)_ — Misma pantalla, pestaña Aniversarios. En su configuración: activar, mensaje con {anios}, envío automático y fondo de la tarjeta.
4. **Qué ve el colaborador** _(Colaborador · Web y app)_ — Su tarjeta y el Muro de felicitaciones, donde el equipo deja mensajes.

#### Qué pasa después

- Cada botón muestra si ya se envió y cuándo, para no felicitar dos veces.

#### Errores comunes

- **«Alguien no aparece.»** Le falta fecha de nacimiento o de ingreso en su expediente.

#### En el sistema

- **Ir a Celebraciones** → `/rh/cumpleanos` _(permiso: rh.cumpleanos.ver)_
- **Ir al Muro de felicitaciones** → `/celebraciones`
- Guía en pantalla → `/ayuda/articulo/celebraciones`

## 14. Administración

<a id="articulo-app-movil"></a>

### La app móvil: descarga y versiones

> Mientras no esté en Play Store, la app se descarga desde /app; Sistemas publica cada versión.

#### Qué es

Es la app MR. LANA PEOPLE para el colaborador (y su modo de gestión para RH y gerentes).

#### Quién lo hace

Sistemas, Colaborador.

#### Pasos

1. **Descargar** _(Colaborador)_ — Cualquier persona abre la página «Descargar app» (también aparece al escanear un QR sin la app).
2. **Publicar una versión** _(Sistemas · Web)_ — Administración → Versiones de app → «Subir versión» (queda en borrador) → publicar. «Actualización obligatoria» obliga a todos a actualizar.

#### Qué pasa después

- La app avisa sola cuando hay una versión nueva.

#### Errores comunes

- **«Todos tienen que actualizar.»** Marca una versión como obligatoria solo cuando sea necesario.

#### En el sistema

- **Descargar la app** → `/app`
- **Ir a Versiones de app** → `/administracion/app-versiones` _(permiso: app_releases.ver)_
- Guía en pantalla → `/ayuda/articulo/app-movil`

<a id="articulo-directorio"></a>

### Directorio y correo corporativo

> Contacto corporativo de cualquier colaborador activo, visible para todos; nunca datos personales.

#### Qué es

El directorio muestra nombre, puesto, sucursal, departamento, correo corporativo y teléfono corporativo. El correo corporativo es distinto del correo con el que la persona inicia sesión.

#### Quién lo hace

Colaborador, RH.

#### Pasos

1. **Buscar** _(Web y app)_ — Directorio (web o app): por nombre o número de empleado, o filtra por puesto, sucursal o departamento.
2. **Contactar** _(App)_ — En la app, toca el correo o el teléfono para escribir o llamar.

#### Errores comunes

- **«Alguien no tiene correo en el directorio.»** No lo tiene capturado: PEOPLE nunca inventa uno ni usa su correo de acceso o personal. RH lo captura en su expediente.

#### En el sistema

- **Ir al Directorio** → `/directorio`
- Guía en pantalla → `/ayuda/articulo/directorio`
