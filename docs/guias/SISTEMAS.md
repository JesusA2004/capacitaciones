<!-- Archivo GENERADO por `npm run guia:docs` desde resources/js/lib/guia. No lo edites a mano: cambia el contenido allá y vuelve a generar. -->

# Guía de Sistemas

Sistemas recibe cada alta y cada baja de colaborador para preparar o retirar cuentas, accesos y equipo, y administra cuentas, roles, notificaciones y versiones de la app. No tiene acceso a expedientes ni a nómina.

## Sistemas

Recibe cada alta y baja: prepara cuenta, accesos y equipo, y los retira al salir. Administra roles, cuentas y versiones de la app. No ve expedientes ni nómina.

**Dónde trabaja:** Web (Administración).

**Roles del sistema:** `sistemas`

### Tu checklist

- [ ] Alta: preparar cuenta, accesos y equipo → [Alta real: colaborador activo](#articulo-alta-activa)
- [ ] Baja: retirar accesos y equipo → [Baja de un colaborador (cierre laboral)](#articulo-baja)
- [ ] Cuentas, credenciales y roles → [Cuentas, credenciales y roles (Sistemas)](#articulo-usuarios-y-roles)
- [ ] A quién llega cada notificación → [A quién le llega cada notificación](#articulo-configuracion-notificaciones)
- [ ] Publicar versiones de la app → [La app móvil: descarga y versiones](#articulo-app-movil)

### Lo que NO le toca

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

## 12. Baja y finiquito

<a id="articulo-baja"></a>

### Baja de un colaborador (cierre laboral)

> El jefe solicita, el superior preautoriza y RH autoriza. Al solicitarla se suspende el acceso; la baja se ejecuta en la fecha efectiva, ya pagado y firmado el finiquito.

#### Qué es

Es el cierre de la relación laboral. Nunca borra a la persona: bloquea su acceso y conserva expediente, contratos, evaluaciones y documentos para un posible reingreso.

#### Quién lo hace

Solicita el jefe o gerente (o RH). Preautoriza el superior según el organigrama (implícita si quien solicita ya es su superior). Autoriza RH. Programa el pago la coordinación regional. El gerente cita, recaba firma y huella. RH confirma el pago y concluye.

> **Importante:** Al SOLICITAR la baja se suspende de inmediato el acceso de la persona a la app y al portal. Si la baja se rechaza o cancela, el acceso vuelve solo. Su estatus laboral no cambia hasta la autorización de RH y la fecha efectiva.

#### Antes de empezar

- La persona está activa y dentro de tu alcance.

#### Pasos

1. **«Solicitar baja»** _(Gerente)_ — Expedientes → la persona → Ciclo laboral → «Solicitar baja»: causa (renuncia, no renovación, bajo desempeño, baja inmediata…), motivo, fecha efectiva y evidencias (PDF o imagen).
2. **«Preautorizar baja»** _(Regional)_ — El superior operativo la preautoriza o la rechaza.
3. **«Autorizar baja»** _(RH)_ — RH autoriza, devuelve o rechaza. Al autorizar se preparan los documentos de la causa.
4. **«Registrar renuncia / aviso firmado»** _(RH)_ — Se sube la renuncia o el aviso firmado.
5. **Finiquito** _(RH)_ — «Calcular finiquito» y «Autorizar finiquito» (ver el artículo de finiquito).
6. **«Programar pago»** _(Regional)_ — Fecha, monto y método.
7. **«Registrar cita» y «Registrar firma y huella»** _(Gerente)_ — El gerente cita a la persona, recaba la firma y la huella del finiquito y sube el documento firmado.
8. **«Confirmar pago»** _(RH)_ — RH confirma que se pagó.
9. **«Concluir cierre laboral»** _(RH)_ — Desde la fecha efectiva: la baja se ejecuta y el expediente queda cerrado. PEOPLE recuerda a RH los cierres pagados cuya fecha ya llegó.

#### Qué pasa después

- Baja ejecutada → aviso a RH y a Sistemas: «Retira accesos, cuentas y equipo».
- La vacante de esa sucursal y puesto se abre sola.
- La persona queda disponible para un posible reingreso.

#### Notificaciones

- «Baja solicitada» a regionales y RH.
- «Preautorización» al superior; «Autorización» a RH; «Pago por programar» a la coordinación; «Cita de finiquito» al jefe.
- Baja ejecutada → RH y Sistemas.

#### Documentos que se generan

- Documentos de la causa (aviso de término, actas, según la causa)
- Finiquito

#### Qué queda registrado

- Cierre laboral con cada paso, aprobaciones, documentos y evidencias; expediente conservado.

#### Errores comunes

- **«No me deja concluir.»** La baja exige finiquito firmado y pago confirmado, y no se ejecuta antes de la fecha efectiva.
- **«¿Cómo hago una baja?»** Desde el Ciclo laboral de la persona. Expedientes → la persona → Ciclo laboral → «Solicitar baja».

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
