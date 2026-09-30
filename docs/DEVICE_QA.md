# QA en dispositivo físico — app móvil MR. LANA PEOPLE

Checklist para probar el APK/build en un teléfono real. **Código en verde
(typecheck/lint/tests/expo export) no es lo mismo que "probado"** — hasta que alguien
marque cada punto de aquí abajo en un dispositivo físico, el estado es "NO PROBADO".

Repo: `C:\wamp64\www\mr-lana-people-app` (JesusA2004/mr-lana-people-app).

## Antes de empezar

- [ ] Backend real accesible desde el teléfono (mismo Wi-Fi que el servidor de
      desarrollo, o apuntando a un entorno de staging/producción real — nunca
      `localhost` desde el teléfono).
- [ ] Cuenta de prueba con colaborador enlazado, expediente con al menos algunos
      documentos, y (si vas a probar el cambio de modo) permisos de ambos modos
      (colaborador + operativo).
- [ ] Segunda cuenta de prueba distinta, para el caso "logout / login con otra cuenta"
      más abajo.

## 1. Instalación y primer inicio

- [ ] Instalar el APK/build en el dispositivo.
- [ ] Primer arranque: la app **nunca** se queda en el splash — termina en login o en
      la pantalla de bienvenida.
- [ ] **Bienvenida/onboarding** aparece — descripción breve, biometría opcional,
      notificaciones opcionales.
- [ ] Cerrar la app por completo y reabrir **sin haber iniciado sesión aún**: la
      bienvenida **no** debe volver a aparecer.

## 2. Login

- [ ] Login con correo/contraseña real.
- [ ] Si se ofreció activar biometría en la bienvenida y se aceptó, confirmar que
      queda activada (revisar en Configuración).
- [ ] Cerrar sesión y volver a iniciar con la misma cuenta: la bienvenida sigue sin
      reaparecer.

## 3. Biometría / bloqueo local

- [ ] Con biometría activada: cerrar la app (no logout), esperar más del tiempo de
      inactividad configurado (unos minutos), reabrir → debe pedir desbloqueo
      (huella/Face ID), **no** volver a pedir correo/contraseña completos.
- [ ] Botón "Entrar con huella/Face ID" funciona.
- [ ] Simular una biometría fallida (huella no reconocida varias veces) → debe
      ofrecer contraseña como respaldo, nunca dejar la app inaccesible.
- [ ] Cerrar la app por muy poco tiempo (segundos) y reabrir: según la política de
      timeout configurada, puede continuar sin pedir desbloqueo — confirmar que el
      comportamiento es consistente con el tiempo real transcurrido, no aleatorio.
- [ ] Confirmar que un desbloqueo local exitoso **no** vuelve a llamar al backend
      innecesariamente (la sesión del backend y el desbloqueo local son conceptos
      distintos) y que, aun así, si el token del backend ya venció, la app lo detecta
      y manda a login real (no se queda "desbloqueada" pero rota contra la API).

## 4. Mi espacio / Operación RH

- [ ] Con una cuenta que tiene ambos modos: la app permite cambiar de modo desde
      Configuración (o el punto de acceso que exista) en cualquier momento, sin tener
      que cerrar sesión.
- [ ] Con una cuenta que solo tiene un modo: no aparece selector, solo se ve el modo
      que le corresponde.
- [ ] La identidad visual cambia claramente entre modos (nunca queda ambiguo en cuál
      estás).

## 5. Solicitudes (colaborador)

- [ ] Lista de solicitudes: fechas en formato legible ("29 sep 2026"), nunca
      timestamps ISO crudos.
- [ ] Períodos como "29 sep – 2 oct 2026", no dos fechas ISO pegadas.
- [ ] No existe ninguna opción de "solicitud de baja" para el colaborador (botón,
      card, ruta o acceso rápido) — confirmar explícitamente que no aparece en ningún
      lado de la experiencia de colaborador.

## 6. Préstamo

- [ ] Solicitar préstamo: el formulario solo pide **monto solicitado** y **motivo** —
      nada de datos que el sistema ya tiene (nombre, número de empleado, puesto,
      sucursal, fecha de ingreso).
- [ ] Si la cuenta tiene permisos de autorización de préstamos: los botones de acción
      corresponden 1:1 a los permisos reales del backend (visto bueno / autorizar /
      rechazar) — nunca un botón genérico "Aprobar" duplicando una acción que el
      backend distingue.

## 7. Expediente

- [ ] El porcentaje de expediente mostrado coincide con "documentos requeridos
      aprobados / documentos requeridos total" — nunca 100% si hay documentos
      pendientes o rechazados.
- [ ] Debajo del porcentaje se ve el desglose ("X de Y documentos requeridos —
      faltan Z").
- [ ] Cards de documentos: icono según tipo de archivo, nombre, estado (Pendiente / En
      revisión / Aceptado / Rechazado / Vencido — nunca un código críptico), fecha,
      versión, acción disponible.

## 8. Formatos (si el rol de la cuenta lo permite)

- [ ] Lista de plantillas permitidas por el backend (nunca una lista inventada en el
      cliente).
- [ ] Antes de generar: variables resueltas automáticamente, variables manuales
      pendientes con etiqueta legible, variables requeridas faltantes bloqueando el
      botón de generar.
- [ ] Generar y confirmar que se puede ver/descargar Word y (si aplica) PDF.

## 9. Cumpleaños / Aniversarios

- [ ] Si el colaborador de prueba tiene un cumpleaños/aniversario disponible en el
      backend, la notificación/celebración se ve en la app, con su tarjeta.
- [ ] Mensajes dirigidos a él son visibles; mensajes de/para otros colaboradores no lo
      son (a menos que la cuenta sea RH/admin autorizado).

## 10. Push notifications

- [ ] Con la app en primer plano: llega una notificación real (de prueba) y se ve
      correctamente.
- [ ] Con la app en segundo plano: la notificación llega al sistema y, al tocarla,
      abre la app en la pantalla correcta (no la pantalla de inicio genérica, salvo
      que la notificación sea genérica).
- [ ] Cerrando la app por completo (cold start) y tocando una notificación: la app
      arranca y navega a la pantalla correcta.
- [ ] Cerrar sesión → iniciar sesión con una **cuenta distinta** en el mismo
      dispositivo → confirmar que las notificaciones de la cuenta anterior **no**
      siguen llegando a este dispositivo (revocación de token en logout).

## 11. Logout / cambio de cuenta

- [ ] Cerrar sesión limpia: vuelve a login, no deja rastro de datos de la cuenta
      anterior visible en ningún lado (ni un parpadeo con datos viejos antes de
      redirigir).
- [ ] Iniciar sesión con la segunda cuenta de prueba: no se ve ningún dato de la
      primera cuenta en ningún momento (caché de listas, nombre en el header, etc.).
- [ ] `onboardingCompleted` sigue en true tras el logout — la bienvenida no reaparece
      para la nueva sesión en el mismo dispositivo (es una bandera del dispositivo,
      no de la cuenta).

## 12. Offline

- [ ] Activar modo avión con la app abierta: aparece un aviso claro de que no hay
      conexión (nunca la app se queda "congelada" simulando que todo funciona).
- [ ] Datos ya cargados previamente (no sensibles) siguen visibles si la arquitectura
      lo permite; acciones que requieren red muestran mensaje claro en vez de fallar
      en silencio.
- [ ] Restaurar conexión: la app se recupera sin necesitar cerrar/abrir de nuevo.

## 13. Modo oscuro

- [ ] Cambiar el tema del sistema a oscuro (o el selector interno si existe): ningún
      texto queda ilegible, ningún fondo blanco "quemado" que no debería estarlo.

## Resultado

Al terminar, reportar explícitamente:

```
APK físico: PROBADO (fecha, dispositivo, versión de Android/iOS)
```

o

```
APK físico: NO PROBADO
```

Nunca reportar "probado" sin haber corrido esta lista en un dispositivo real.
