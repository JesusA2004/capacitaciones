<!-- Archivo GENERADO por `npm run guia:docs` desde resources/js/lib/guia. No lo edites a mano: cambia el contenido allá y vuelve a generar. -->

# Guía de Sistemas

Sistemas recibe cada alta y cada baja de colaborador para preparar o retirar cuentas, accesos y equipo, y administra cuentas, roles, notificaciones y versiones de la app. No tiene acceso a expedientes ni a nómina.

## Sistemas

Cuentas, contraseñas, roles y accesos; la app móvil y sus versiones; notificaciones; soporte y diagnóstico técnico. Recibe cada alta y baja para preparar o retirar accesos y equipo.

**Dónde trabaja:** Web (Administración).

**Roles del sistema:** `sistemas`

### Tu checklist

- [ ] Alta: preparar cuenta, accesos y equipo → [Alta real: colaborador activo](#articulo-alta-activa)
- [ ] Baja: retirar accesos y equipo → [Baja de un colaborador (forma de salida y causal)](#articulo-baja)
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

### Baja de un colaborador (forma de salida y causal)

> El gerente solicita la baja en «Solicitudes de baja» (o RH / el jefe directo la aplica desde el ciclo laboral): elige cómo se va (renuncia voluntaria, no renovación o rescisión) y por qué (la causal), adjunta los documentos y la envía a RH. Al enviarla se suspende el acceso; RH valida, la manda a Jurídico si la causal lo pide y la cierra cuando los documentos están completos.

#### Qué es

Es el expediente de la baja con folio BAJ-AAAA-NNNN: forma de salida y causal de lista cerrada (sin «Otro»), documentos, bitácora de cambios y el cierre laboral (finiquito). Con esos datos se mide la rotación. Nunca borra a la persona: bloquea su acceso y conserva todo para un posible reingreso.

#### Quién lo hace

Captura y envía el gerente (solo su personal). RH valida, rechaza con motivo, corrige la causal con motivo, cierra y reabre. Jurídico revisa las causales que lo requieren y no cierra. Contraloría consulta las de faltantes (RE-02) y robo o fraude (RE-03). Dirección solo ve el tablero de rotación.

> **Importante:** Al ENVIAR la baja se suspende de inmediato el acceso de la persona a la app y al portal. Si RH decide que la baja no procede, se cancela y el acceso vuelve.

> **Importante:** En rescisión, «¿Recontratable?» es «No» y solo RH lo cambia. El aviso de rescisión se adjunta (el sistema no lo genera).

#### Antes de empezar

- La persona está activa y es de tu sucursal o equipo (o eres RH).
- Solo puede haber una baja abierta por persona.

#### Pasos

1. **«Solicitar baja»** _(Gerente)_ — Cierres laborales → Solicitudes de baja → «Solicitar baja»: 1 colaborador (sucursal, puesto e ingreso salen solos), 2 ¿cómo se va? (tres tarjetas), 3 ¿por qué se va? (causal con su ayuda, comentario —obligatorio en RV-05 y en rescisión, mínimo 20 caracteres—, último día laborado y ¿recontratable?; en rescisión además fecha en que se conoció la falta y entrega del aviso), 4 documentos.
2. **«Guardar borrador» o «Enviar a RH»** _(Gerente)_ — El borrador no afecta a nadie. Al enviarla, el acceso de la persona se suspende de inmediato y la baja queda «En revisión RH». Puedes enviarla aunque falten documentos: los adjuntas después.
3. **RH valida (y Jurídico revisa si aplica)** _(RH)_ — En el expediente de la baja: «Validar», «Enviar a Jurídico» (si la causal lo requiere), «Rechazar» con motivo (regresa al gerente) o «Cambiar causal» con motivo. Todo queda en la bitácora.
4. **Finiquito y «Cerrar baja»** _(RH)_ — RH calcula y entrega el finiquito (Bajas y finiquitos). Con los documentos obligatorios completos, «Cerrar baja»: la persona queda inactiva y su acceso bloqueado de forma definitiva. Una baja cerrada solo se reabre con motivo.

#### Qué pasa después

- Baja cerrada → cuenta en la rotación de su mes (salvo que RH la marque como movimiento interno).
- La vacante de esa sucursal y puesto se abre sola.
- Si alguien con baja «No recontratable» vuelve como candidato, Reclutamiento ve una alerta.

#### Notificaciones

- «Baja por revisar» a RH al enviarla; «Baja aplicada» a regionales y RH.
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

- **«No me deja enviar.»** Faltan forma, causal, último día laborado o ¿recontratable? (y en rescisión los datos del aviso). La pantalla marca cada campo.
- **«RH no puede cerrar.»** Falta un documento obligatorio, la revisión de Jurídico o el finiquito revisado. El expediente dice exactamente qué falta.

#### Relacionado con

- [Finiquito](FLUJO_COMPLETO.md#articulo-finiquito)
- [Reingreso de un excolaborador](FLUJO_COMPLETO.md#articulo-reingreso)
- [Cuentas, credenciales y roles (Sistemas)](#articulo-usuarios-y-roles)

#### En el sistema

- **Ir a Solicitudes de baja** → `/rh/bajas` _(permiso: bajas.ver o bajas.revisar_juridico o bajas.consulta_contraloria)_
- **Tablero de rotación** → `/reportes/rotacion/tablero` _(permiso: bajas.tablero.ver)_
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
