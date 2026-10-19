<!-- Archivo GENERADO por `npm run guia:docs` desde resources/js/lib/guia. No lo edites a mano: cambia el contenido allá y vuelve a generar. -->

# Guía de Sistemas

Sistemas recibe cada alta y cada baja de colaborador para preparar o retirar cuentas, accesos y equipo, y administra cuentas, roles, notificaciones y versiones de la app. No tiene acceso a expedientes ni a nómina.

## Sistemas

Cuentas, contraseñas, roles y accesos; la app móvil y sus versiones; notificaciones; soporte y diagnóstico técnico. Recibe cada alta y baja para preparar o retirar accesos y equipo.

**Dónde trabaja:** Web (Administración).

**Roles del sistema:** `sistemas`

### Tu checklist

- [ ] Alta: preparar cuenta, accesos y equipo → [Alta real: colaborador activo](#articulo-alta-activa)
- [ ] Baja: retirar accesos y equipo → [Baja de un colaborador (cierre laboral)](#articulo-baja)
- [ ] Cuentas, contraseñas, roles y acceso → [Cuentas, credenciales y roles (Sistemas)](#articulo-usuarios-y-roles)
- [ ] A quién llega cada notificación → [A quién le llega cada notificación](#articulo-configuracion-notificaciones)
- [ ] Publicar versiones de la app → [La app móvil: descarga y versiones](#articulo-app-movil)

### Lo que NO le toca

- Decisiones de RH: autorizar contrataciones, solicitudes, nómina o bajas (aunque pueda consultarlas).
- Expedientes, documentos personales y datos de nómina.

---

## 3. Alta del colaborador

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

- [Onboarding: la bienvenida del colaborador](FLUJO_COMPLETO.md#articulo-onboarding)
- [Cuentas, credenciales y roles (Sistemas)](#articulo-usuarios-y-roles)

#### En el sistema

- **Ir a Mis pendientes** → `/rh/pendientes`
- Guía en pantalla → `/ayuda/articulo/alta-activa`

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

- [El expediente del colaborador](FLUJO_COMPLETO.md#articulo-expediente)

#### En el sistema

- **Ir al Directorio** → `/directorio`
- Guía en pantalla → `/ayuda/articulo/directorio`

## 12. Baja y finiquito

<a id="articulo-baja"></a>

### Baja de un colaborador (cierre laboral)

> El jefe directo (o RH) inicia la baja desde el colaborador y queda aplicada de inmediato: sin solicitud previa, sin autorización de RH y sin subir archivos. Al iniciarla se suspende el acceso; siguen los documentos de la baja y el finiquito.

#### Qué es

Es el cierre de la relación laboral. Nunca borra a la persona: bloquea su acceso y conserva expediente, contratos, evaluaciones y documentos para un posible reingreso.

#### Quién lo hace

Inicia el jefe directo de la persona (su cadena de mando real) o RH; nadie más puede iniciarla y no necesita autorización. Después se generan los documentos, RH calcula el finiquito y, al registrar su entrega, el cierre queda completo.

> **Importante:** Al INICIAR la baja se suspende de inmediato el acceso de la persona a la app y al portal. Si RH cancela el cierre, el acceso vuelve solo. Su estatus laboral (inactivo, vacante) se ejecuta al concluir el cierre.

#### Antes de empezar

- La persona está activa y eres su jefe directo (o RH).
- La baja NO es una solicitud: no está en «Nueva solicitud».

#### Pasos

1. **«Iniciar baja»** _(Gerente)_ — Expedientes → la persona de tu equipo → Ciclo laboral → «Iniciar baja»: causa (renuncia, no renovación, bajo desempeño, baja inmediata…), motivo y fecha efectiva. No se sube ningún archivo. Al confirmar, la baja queda aplicada (sin autorización de RH) y el sistema prepara los documentos de la causa.
2. **Renuncia / aviso en «Documentos de la baja»** _(RH)_ — El sistema genera la renuncia (o el aviso de terminación): «Imprimir» y, ya firmada en papel, «Marcar como firmado físicamente». No se sube ningún archivo. Si la persona se niega a firmar, «El colaborador se negó a firmar/recibir».
3. **«Calcular finiquito»** _(RH)_ — Muestra al momento el desglose por concepto; «Ver / editar finiquito» para ajustar montos con motivo (ver el artículo de finiquito). No hay que autorizarlo.
4. **«Registrar entrega de finiquito»** _(RH)_ — Desde la fecha efectiva: fecha de entrega, forma de pago y comentario. El finiquito se congela, la baja se ejecuta y el cierre laboral queda completo.

#### Qué pasa después

- Baja ejecutada → aviso a RH y a Sistemas: «Retira accesos, cuentas y equipo».
- La vacante de esa sucursal y puesto se abre sola.
- La persona queda disponible para un posible reingreso.

#### Notificaciones

- «Baja aplicada» a regionales y RH.
- Baja ejecutada → RH y Sistemas.

#### Documentos que se generan

- Documentos de la causa (aviso de término, actas, según la causa)
- Finiquito

#### Qué queda registrado

- Cierre laboral con cada paso, aprobaciones, documentos y evidencias; expediente conservado.

#### Errores comunes

- **«No me deja concluir.»** El cierre se completa al registrar la entrega del finiquito, que solo se permite desde la fecha efectiva.
- **«¿Cómo hago una baja?»** Desde el Ciclo laboral de la persona. Expedientes → la persona → Ciclo laboral → «Iniciar baja». No se pide desde Solicitudes.

#### Relacionado con

- [Finiquito](FLUJO_COMPLETO.md#articulo-finiquito)
- [Reingreso de un excolaborador](FLUJO_COMPLETO.md#articulo-reingreso)
- [Cuentas, credenciales y roles (Sistemas)](#articulo-usuarios-y-roles)

#### En el sistema

- **Ir a Expedientes** → `/rh/expedientes` _(permiso: expedientes.ver_todos o expedientes.ver_sucursal)_
- Guía en pantalla → `/ayuda/articulo/baja`

## 14. Administración

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
- [Baja de un colaborador (cierre laboral)](#articulo-baja)

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

<a id="articulo-app-movil"></a>

### La app móvil: descarga y versiones

> Mientras no esté en Play Store, la app se descarga desde /app; Sistemas publica cada versión.

#### Qué es

Es la app MR. LANA PEOPLE para el colaborador (y su modo de gestión para RH y gerentes).

#### Quién lo hace

Sistemas, Colaborador.

#### Antes de empezar

- Tener el archivo de la versión (APK).

#### Pasos

1. **Descargar** _(Colaborador)_ — Cualquier persona abre la página «Descargar app» (también aparece al escanear un QR sin la app).
2. **Publicar una versión** _(Sistemas · Web)_ — Administración → Versiones de app → «Subir versión» (queda en borrador) → publicar. «Actualización obligatoria» obliga a todos a actualizar.

#### Qué pasa después

- La app avisa sola cuando hay una versión nueva.

#### Errores comunes

- **«Todos tienen que actualizar.»** Marca una versión como obligatoria solo cuando sea necesario.

#### Relacionado con

- [Cómo usar la app MR. LANA PEOPLE](FLUJO_COMPLETO.md#articulo-app-colaborador)

#### En el sistema

- **Descargar la app** → `/app`
- **Ir a Versiones de app** → `/administracion/app-versiones` _(permiso: app_releases.ver)_
- Guía en pantalla → `/ayuda/articulo/app-movil`

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

- [El expediente del colaborador](FLUJO_COMPLETO.md#articulo-expediente)

#### En el sistema

- **Ir al Directorio** → `/directorio`
- Guía en pantalla → `/ayuda/articulo/directorio`
