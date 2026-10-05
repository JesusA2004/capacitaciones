# Autenticación MR. LANA PEOPLE

- **Usuario: primer nombre + primer apellido** (p. ej. `Jesus Arizmendi`).
- **Contraseña inicial: temporal, generada por el sistema** (se pide cambiarla al primer inicio de sesión).
- **El correo es opcional** y ya no se usa como identificador principal para iniciar sesión.

Colaborador = la persona; User = su cuenta de acceso. Son modelos separados: quitar el acceso nunca borra al colaborador ni su expediente.

## Nombre de usuario (`users.username`, UNIQUE)

`App\Services\Autenticacion\NombreUsuarioService`.

| Datos | Usuario |
|---|---|
| Nombre `JESUS ENRIQUE`, paterno `ARIZMENDI`, materno `PEREZ` | `Jesus Arizmendi` |
| Nombre `MARIA FERNANDA`, paterno `LOPEZ` | `Maria Lopez` |
| Nombre `JUAN CARLOS`, paterno `DE LA CRUZ` | `Juan De la Cruz` |
| Segundo / tercer `Jesus Arizmendi` | `Jesus Arizmendi2` / `Jesus Arizmendi3` |

- Solo el **primer** nombre; el apellido paterno **completo** tal como está guardado; nunca el materno.
- Sin acentos (`Jesús` → `Jesus`, `Muñoz` → `Munoz`), en «Tipo Título», espacios simples.
- Colisiones: sufijo numérico determinístico (nunca aleatorio ni UUID). El índice UNIQUE es la garantía final; si dos altas chocan, se reintenta con el siguiente sufijo.
- Una cuenta ya creada **nunca cambia su username sola** (reimportar, editar el nombre…). Administración > Usuarios puede editarlo a mano.
- Cuentas creadas fuera del Excel (alta digital, administración, QR) lo reciben automáticamente al crearse (hook `creating` de `User`); como ahí solo existe `apellidos` combinado, el paterno es su primera palabra (más partículas: «De la Cruz Hernández» → «De la Cruz»).
- Las cuentas que ya existían recibieron su username en la migración `2026_10_05_180000_usuario_como_identificador_de_acceso` con la misma regla, en orden de id.

## Inicio de sesión (web y app, mismas reglas)

`App\Services\Autenticacion\AutenticacionService`, usado por `FortifyServiceProvider` (web) y `Api\V1\AuthController` (app).

- **Usuario**: se recortan espacios al inicio/fin, se colapsan los internos y se compara sin distinguir mayúsculas ni acentos. `  jesus   ARIZMENDI ` = `Jesus Arizmendi`.
- **Contraseña**: solo `trim()` de espacios al inicio/fin. Mayúsculas, espacios internos y especiales se respetan.
- Compatibilidad temporal: si lo escrito es un correo registrado, también identifica la cuenta.
- Solo entran colaboradores `activo` / `en_incorporacion` sin acceso revocado.
- Límite de intentos con la misma llave para cualquier variante escrita del usuario.

Web: formulario «Usuario» + «Contraseña» (campo `username`). API:

```http
POST /api/v1/login
{ "username": "Jesus Arizmendi", "password": "...", "device_name": "..." }
→ { "token": "...", "debe_cambiar_contrasena": true, "usuario": { "id", "username", "nombre", "apellidos", "correo", "estatus", "roles" } }
```

## «Generar credenciales» (nunca se pide correo)

`App\Services\Autenticacion\CredencialesService` — botón en el expediente (pestaña «Cuenta») y en Administración → Usuarios («Nuevo usuario» lo abre solo al crear la cuenta):

- Sin cuenta → la crea (usuario por la regla de arriba, rol `colaborador`) con contraseña temporal.
- Con cuenta → conserva su usuario y le da otra contraseña temporal (la anterior deja de funcionar).
- Muestra **usuario y contraseña** con botones para copiar cada uno o ambos; la contraseña no se vuelve a mostrar. Se entrega en privado.
- No aplica a colaboradores de baja/suspendidos. Requiere `usuarios.crear` (sin cuenta) o `usuarios.editar` (con cuenta), dentro del alcance, y nunca sobre la propia cuenta.
- Web: `POST /rh/colaboradores/{colaborador}/credenciales`; API: `POST /api/v1/rh/colaboradores/{colaborador}/credenciales`. Respuesta `{ data: { usuario, contrasena, cuenta_nueva, correo } }` con `Cache-Control: no-store`.

Ningún flujo de creación de cuenta exige correo: alta de RH, alta digital/pública, registro por QR (la respuesta incluye `usuario.username`), migración y Administración. Si hay correo, se guarda y se usa para el enlace de «¿Olvidaste tu contraseña?».

## Contraseña temporal y primer inicio de sesión

- `App\Services\Administracion\GeneradorPasswordService`: exactamente 8 caracteres, al menos una mayúscula, una minúscula, un número y un especial de `!@#$%&*?`; `random_int` (CSPRNG) también para revolver; sin datos personales.
- En `users.password` solo va `Hash::make()`. El texto plano solo existe en la lista **cifrada** de credenciales de la migración (o se muestra una vez al admin en «Establecer contraseña»). Nunca en logs, manifiesto ni plan.
- `users.debe_cambiar_contrasena = true` → `App\Http\Middleware\ExigirCambioContrasena`:
  - web: cualquier pantalla redirige a `/cambiar-contrasena` (solo se permite cambiarla o cerrar sesión);
  - API: todo responde `403 { "codigo": "cambio_contrasena_requerido" }` salvo `me`, `logout` y `POST /api/v1/cambiar-contrasena` (`password_actual`, `password`, `password_confirmation`).
- La nueva contraseña sigue `Password::defaults()`.

## Correo

Opcional (`users.email` NULL permitido, UNIQUE cuando existe). Se guarda si viene; no se inventa. Sirve para notificaciones por correo y para «¿Olvidaste tu contraseña?»; quien no tiene correo pide a RH una contraseña temporal.
