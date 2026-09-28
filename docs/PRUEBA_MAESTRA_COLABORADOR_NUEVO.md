# Prueba manual maestra — colaborador nuevo (QR → autoservicio)

Checklist paso a paso del flujo completo de alta digital por QR, de punta a
punta (Portal RH + app móvil). Pensada para ejecutarse en un dispositivo
Android físico con un APK `preview` o `development` reciente — ver
`docs/DEVICE_QA.md` para comandos `adb` y anchos de pantalla a revisar en
paralelo.

Requiere: una cuenta RH con permiso de alta (`colaboradores.alta` o
equivalente en `RolesYPermisosSeeder`), un teléfono con la app instalada, y
acceso a los logs del backend (`storage/logs/laravel.log`) por si algo falla
a mitad de camino.

## Flujo

1. **RH crea el alta/invitación.** Portal RH → módulo de alta digital →
   capturar datos del nuevo colaborador → generar invitación. Confirmar que
   se genera un QR/enlace (`App\Models\IncorporacionInvitacion`,
   `token_hash` — el token plano solo vive en la respuesta de creación, no
   se puede recuperar después).
2. **Genera QR.** El QR debe apuntar al scheme/App Link real que la app
   escanea (`incorporacion/qr/{token}` en móvil — confirmar contra
   `app.json`, no asumir).
3. **APK limpio.** Instalar sobre un dispositivo sin sesión previa (o cerrar
   sesión de cualquier cuenta anterior primero).
4. **Escanear el QR** desde la pantalla de login → cámara pide permiso →
   lee el token.
5. **Registro.** La app llama a `validar($token)` y luego `registrar($token)`
   con los datos que pida el formulario — confirmar que un dato faltante da
   un error entendible, no un 500 crudo.
6. **Login automático.** Tras `registrar()` exitoso, la app debe entrar
   directo (mismo patrón que `loginWithToken()`), sin pedir email/contraseña
   de nuevo.
7. **Incorporación.** La cuenta nueva debe caer en la pantalla de
   incorporación (progreso de expediente), no en el dashboard normal de
   autoservicio — confirmar que el copy es de bienvenida a SU incorporación,
   no la guía genérica de la app (ver sección 76 del encargo original).
8. **Subir documentos.** Cámara, galería y archivos — probar los tres
   caminos, y cancelar el picker a medio camino (no debe dejar el uploader
   en un estado roto).
9. **RH revisa.** Desde Portal RH (o la app RH si aplica), el documento
   subido aparece como pendiente de revisión.
10. **Rechazar uno.** RH marca un documento como rechazado con un motivo.
11. **Colaborador corrige.** La app debe mostrar claramente cuál documento
    fue rechazado y por qué, permitiendo volver a subirlo.
12. **Aprobar.** RH aprueba el documento corregido.
13. **Completar expediente.** Subir/aprobar el resto de documentos
    obligatorios hasta 100% (ver regla `ProgresoExpediente`: obligatorios
    activos = denominador, obligatorios **aprobados** = numerador — 9/10 con
    1 en revisión NUNCA debe mostrar 100%).
14. **Activar colaborador.** RH activa la cuenta (`EstadoUsuario::Activo`).
15. **Entrar autoservicio.** Reabrir la app (o simplemente navegar) — ya no
    debe verse la pantalla de incorporación, sino el dashboard normal de
    colaborador.
16. **Solicitud.** Crear una solicitud simple (ej. permiso) desde
    autoservicio.
17. **Préstamo.** Solicitar un préstamo (monto + motivo).
18. **Documento.** Ver un documento laboral generado (si RH ya generó
    alguno para este colaborador, ej. contrato).
19. **Firma.** Firmar digitalmente el documento si `requiere_firma_digital`.
20. **Notificación.** Confirmar que las acciones anteriores generaron
    notificaciones in-app visibles en el centro de notificaciones.
21. **Push.** Confirmar que al menos una de esas acciones generó un push
    real recibido en el teléfono (ver `docs/PUSH_QA.md`).
22. **Logout/login.** Cerrar sesión y volver a entrar con la misma cuenta —
    todo el estado (expediente, solicitudes) debe seguir ahí.
23. **Background >5 min.** Dejar la app en segundo plano más de 5 minutos
    → al volver, debe pedir `LockScreen` (ver `docs/DEVICE_QA.md`).
24. **Biometría.** Si el dispositivo tiene biometría, activarla desde
    Configuración y confirmar que el primer desbloqueo la ofrece.
25. **Kill app.** Matar el proceso completamente (deslizar fuera de
    recientes) y reabrir.
26. **Biometría en frío.** Debe pedir `LockScreen` de inmediato al reabrir
    (sin importar cuánto tiempo pasó) y, si la biometría está activada,
    intentarla automáticamente antes de mostrar el formulario de
    contraseña.

## Estados a probar del token de invitación (aparte del feliz de arriba)

- **Vencido**: dejar pasar el tiempo de expiración configurado y escanear
  — debe dar un mensaje claro de "invitación vencida", nunca un error
  técnico.
- **Usado**: escanear el mismo QR una segunda vez tras completar el
  registro — debe rechazarlo explícitamente (no permitir un segundo
  registro con el mismo token).
- **Revocado**: RH revoca la invitación antes de que el colaborador
  escanee — el escaneo debe fallar con un mensaje claro.
- **Correo diferente**: si el formulario de registro pide confirmar datos y
  el colaborador intenta usar un correo distinto al que RH capturó,
  confirmar qué hace el backend (aceptar, rechazar, o ignorar el campo).
- **Pérdida de red a mitad del registro**: cortar la conexión justo después
  de enviar el formulario — la app no debe quedar en un estado ambiguo
  (ej. mostrar éxito sin haberlo confirmado, o perder los datos capturados
  sin poder reintentar).
- **Double submit**: tocar "Registrarme" dos veces rápido (o simular una
  doble petición) — debe crear **una sola** cuenta, nunca dos.
- **QR reutilizado en otro dispositivo**: escanear el mismo QR válido desde
  un segundo teléfono antes de completar el registro en el primero — el
  comportamiento exacto depende de si la invitación es de un solo uso desde
  el primer `validar()` o solo se consume en `registrar()`; confirmar cuál
  es y que no permite dos registros exitosos.

## Qué reportar si algo falla

Para cada falla: paso exacto, mensaje mostrado (o ausencia de mensaje),
captura de pantalla, y si es posible el log correspondiente en
`storage/logs/laravel.log` del momento del error.
