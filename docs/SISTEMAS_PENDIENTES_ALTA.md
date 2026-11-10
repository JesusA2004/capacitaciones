# Pendientes de alta en sistemas

Cuando una persona entra a **contratación** (o llega más adelante por otro camino), Sistemas debe darle sus usuarios en las demás plataformas. PEOPLE lo resuelve con tres piezas que comparten un solo registro (`avisos_alta_sistemas`, uno por contratación):

> **El correo NO sale al entrar a Contratación.** En ese momento apenas se genera el QR del nuevo ingreso y sus datos están vacíos. El pendiente se registra «En espera de sus datos» y el correo (y el aviso en la campana) salen **una sola vez, cuando la persona ya capturó sus datos en la app** — ver «Cuándo sale el correo».

| Pieza | Qué hace | Dónde |
|---|---|---|
| **Correo** «Alta para asignar usuarios» | Le llegan a Sistemas (y en CC a quien se configure) **todos los datos básicos** para dar de alta | `AvisoAltaSistemasService`, `AltaColaboradorSistemasMail`, vista `resources/views/mail/alta-colaborador-sistemas.blade.php` (+ `-texto`) |
| **Aviso en la plataforma** | Campana web, tiempo real y push a quien atiende Sistemas (`sistemas.altas.atender`) | Capa única de avisos, evento `sistemas_alta_pendiente` (`config/notificaciones_catalogo.php`) |
| **Bandeja** «Pendientes de alta en sistemas» | Lista con lo pendiente, «Marcar como dado de alta» (quién, cuándo, notas), copiar datos, reenviar correo, deshacer | `/sistemas/pendientes-alta` (`routes/sistemas.php`, `PendientesAltaController`, `PendientesAltaSistemasService`, `pages/Sistemas/PendientesAlta.vue`) |

El correo es el aviso; **la bandeja es el control**. Que el correo salga o falle no cambia si la persona sigue pendiente: eso solo lo cambia Sistemas al marcarla (`atendido_en`, `atendido_por`, `notas_atencion`).

## Datos del correo y de «Copiar datos»

Fuente única: `AvisoAltaSistemasService::datosBasicos()`. Lo que no esté capturado dice **«Sin capturar»** (en el correo se resalta y se cuenta cuántos faltan).

Tres tarjetas:

- **Persona**: nombre completo, CURP, RFC (en mayúsculas), NSS, fecha de nacimiento («15 de agosto de 1990 · 15/08/1990», `App\Support\FechaTexto::doble`), género.
- **Contacto**: teléfono, correo personal (o el del candidato), correo corporativo.
- **Puesto**: sucursal, puesto, departamento, jefe directo (organigrama, con su puesto), fecha de ingreso (formato doble), número de empleado, empresa (razón social), tipo de contratación, usuario de PEOPLE.

Asunto: «Alta en sistemas: {Nombre} — {Puesto}, {Sucursal}». Diseño: logo PNG de `public/branding/MrLanaPeople_horizontal_color.png` con URL absoluta (`APP_URL`), encabezado petróleo con chip «Nuevo ingreso»/«Reingreso» y la fecha de ingreso grande, botón «Abrir y copiar datos», cada valor en su celda monoespaciada con fondo suave (fácil de seleccionar), íconos emoji por dato, y al final el bloque «Para copiar y pegar» con todo línea por línea. Tablas + estilos en línea, máx. 600 px, botón VML para Outlook; sin SVG, fuentes de íconos ni JavaScript. Versión de texto plano en `mail/alta-colaborador-sistemas-texto.blade.php`.

## Cuándo sale el correo (candidatos)

1. **Fase 6 «Contratación»** (`ContratacionCandidatoService::iniciarContratacion`): se crea el colaborador y su QR. `AvisoAltaSistemasService::programar()` registra el pendiente con estado **`esperando_datos`** («En espera de sus datos»): sin correo y sin aviso en la campana. La fila aparece en la bandeja con ese estado.
2. **El nuevo ingreso captura sus datos en la app** (crea su cuenta con el QR y llena el asistente: `POST /api/v1/incorporacion/invitaciones/{token}/registrar`, `POST /api/v1/colaborador/registro/datos`). En cuanto sus **datos imprescindibles** quedan completos, `AvisoAltaSistemasService::liberarSiDatosCompletos()` encola el correo y avisa a Sistemas en la plataforma. Normalmente esto ocurre antes de que el tablero lo pase a la fase 7 «Inducción» (que empieza con los contratos firmados): la condición es que **ya tenga sus datos**, no la columna del tablero.

**Datos imprescindibles** (`AvisoAltaSistemasService::datosCompletos()`, lista `DATOS_IMPRESCINDIBLES`): CURP, RFC, NSS, fecha de nacimiento, género, teléfono y correo personal (vale el correo del candidato), además de puesto y sucursal. Un campo liberado en `config('incorporacion.registro_app.campos_opcionales')` no detiene el aviso. Correo corporativo, departamento, jefe directo y demás pueden ir como «Sin capturar».

**Un solo punto para todos los caminos**: la revisión corre en el evento `saved` de `Colaborador` (`AppServiceProvider`) cuando cambia alguno de esos campos, así que aplica igual si los datos los completa la app, RH desde la web o una corrección aprobada. Si RH ya capturó todo al iniciar la contratación, el correo sale ahí mismo (no llega vacío).

**Una sola vez**: la liberación es un cambio de estado condicional (`esperando_datos` → `pendiente`/`sin_enviar`); reintentos de la app, peticiones simultáneas o ediciones posteriores ya no encuentran la fila en espera. Además `enviar()` nunca manda un aviso que siga en `esperando_datos` y el correo ya enviado (`enviado_en`) no se repite.

**Si los datos nunca se completan**, el correo automático no sale; la fila sigue visible en la bandeja como «En espera de sus datos» y RH/Sistemas pueden mandarlo a mano con «Reenviar» (ese envío manual sí puede llevar «Sin capturar»).

**Quienes ya estaban en tránsito al desplegar este cambio** (migración `2026_11_14_400000_pone_en_espera_avisos_alta_sistemas_sin_datos`): los avisos de candidato aún no atendidos cuyo colaborador sigue sin datos pasan a `esperando_datos` (aunque ya se les hubiera mandado el correo vacío) y recibirán **una vez** el correo completo al capturarlos. Los que ya tienen sus datos no se tocan: su correo ya salió y no se duplica (sus datos actuales están en la bandeja y se pueden reenviar a mano).

## Cuándo se crea el pendiente («contratación o adelante»)

| Camino | Dónde | Origen |
|---|---|---|
| Iniciar contratación (fase 6 del tablero) — pendiente «En espera de sus datos»; el correo sale al capturarlos | `ContratacionCandidatoService::iniciarContratacion` | `candidato` |
| Reingreso autorizado | `ReingresoService::decidir` | `reingreso` |
| Alta directa de RH sin candidato (web y API `api/v1/rh/altas`) | `AltaColaboradorService::registrar` → `asegurar()` (solo si `candidato_id` es null; con candidato lo crea el primer camino) | `colaborador` |
| Activación de un alta (fin de onboarding) y alta digital aprobada | `AvisoAltaBajaService::alta()` → `asegurar()` (si ya tiene aviso, nada) | `colaborador` |
| Registro con QR **sin** candidato que RH aprueba fuera del ciclo (`IncorporacionService::aprobarIncorporacion` activa directo, sin pasar por `alta()`) | **No** dispara al instante (módulo de Incorporación fuera de este cambio): lo cubre la regularización diaria | `colaborador` |
| Quien ya estaba adelante antes de existir el aviso | `sistemas:avisos-alta-pendientes` (abajo); corre a diario a las 06:45 (`routes/console.php`, `--dias=14`, sin correo ni campana) | `candidato` / `colaborador` |

Importaciones masivas (Excel de trabajadores, migración inicial de expedientes) **no** crean avisos: son plantilla histórica. Si alguien importado tiene fecha de ingreso en los últimos 14 días, la regularización diaria sí lo agrega.

**«Abrir y copiar datos»** (botón del correo) lleva a `/sistemas/pendientes-alta/{aviso}` (`Sistemas/PendienteAltaDetalle.vue`): cada dato con su botón «Copiar», «Copiar todo», reenviar y «Marcar como dado de alta». Requiere sesión y `sistemas.altas.ver`, acotado por alcance.

Siempre idempotente: `origen_tipo + origen_id` es único; repetir la acción o reintentar la cola nunca duplica. El pendiente se crea **aunque el correo esté apagado** (estado del correo «Correo no enviado»; se puede mandar desde la bandeja cuando se encienda).

## Comando de regularización

```bash
php artisan sistemas:avisos-alta-pendientes --dry-run          # solo muestra a quién le falta
php artisan sistemas:avisos-alta-pendientes --dias=60          # crea los pendientes, SIN correo
php artisan sistemas:avisos-alta-pendientes --dias=7 --enviar  # crea y manda el correo de cada uno
```

Busca candidatos en contratación/contratados sin aviso y colaboradores activos (o en incorporación) con fecha de ingreso en los últimos `--dias` sin ningún aviso. Por defecto **no manda correos** ni avisos en la campana (para no reenviar en masa altas viejas); la bandeja y su contador ya los muestran. `--dry-run` y `--enviar` juntos se rechazan.

Reenvío puntual (soporte): `php artisan sistemas:reenviar-aviso-alta {número de empleado|id}`.

## Permisos

| Permiso | Para qué | Roles por defecto |
|---|---|---|
| `sistemas.altas.ver` | Entrar a la bandeja (acotada por `AlcanceOrganizacionalService`) | `sistemas`, `rh_admin`, `rh_auxiliar`, `super_admin` |
| `sistemas.altas.atender` | Marcar como dado de alta / deshacer, reenviar el correo, recibir el aviso y ver el contador del menú | `sistemas`, `super_admin` |

Módulo «Pendientes de alta en sistemas» en Roles y permisos (`config/permisos_modulos.php`, etapa Contratación). Autorización real en `AvisoAltaSistemasPolicy` + los services. El reenvío desde la ficha del candidato sigue siendo de RH (`candidatos.contratar`, dentro de su alcance) **o** de quien tenga `sistemas.altas.atender`.

## Navegación

Modo operativo → **2 · Contratación y expediente → Pendientes de alta en sistemas** (`sistemas.altas.ver`), con contador (`altasSistemasPendientes`, prop compartida perezosa de `HandleInertiaRequests`) solo para quien atiende. Ver `docs/ROLES_Y_NAVEGACION.md`.

## Qué falta para que funcione en producción

Checklist verificado contra `.env.example`, `config/mail.php`, `config/queue.php`, `config/notificaciones.php`, `deploy/supervisor/*` y `docs/DEPLOY.md`:

1. **SMTP real**. En local `MAIL_MAILER=log` (los correos solo quedan en `storage/logs/laravel.log`). En el `.env` de producción:
   `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT=587`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION=tls` (o `MAIL_SCHEME` según el proveedor).
2. **Remitente**: `MAIL_FROM_ADDRESS` con un buzón que el SMTP permita usar (el `.env` local trae `hello@example.com`; `.env.example` sugiere `notificaciones@mr-lana.com`) y `MAIL_FROM_NAME`. Si el dominio tiene SPF/DKIM, que el servidor SMTP esté autorizado, o el correo caerá en spam.
3. **Encender el aviso**: `SISTEMAS_ALTA_CORREO_ACTIVO=true`, `SISTEMAS_ALTA_CORREO_PARA=sistemas@mr-lana.com` (o el buzón real de Sistemas) y `SISTEMAS_ALTA_CORREO_CC=<tu correo>` (varias separadas por comas). Apagado, la bandeja y la campana funcionan igual, pero no sale correo.
4. **Worker de la cola de avisos**. `QUEUE_CONNECTION=database`: el correo sale por la cola `notifications` **después del commit**. Sin un `queue:work` corriendo, el correo y las notificaciones se quedan en la tabla `jobs`. En el servidor: `deploy/supervisor/people-notifications.conf` (`php artisan queue:work notificaciones --queue=notifications …`) y `people-worker.conf` para la cola general. En el NAS, si no hay Supervisor, un proceso equivalente (contenedor/servicio que reinicie el worker) — nunca `QUEUE_CONNECTION=sync` en producción.
5. **Scheduler**: el cron `* * * * * php artisan schedule:run` (o el Programador de tareas del NAS) para la regularización diaria y `notificaciones:reconciliar`.
6. **Desplegar**: `php artisan migrate --force` (columnas `atendido_en`, `atendido_por`, `notas_atencion`; y la que pone «en espera de sus datos» los avisos en tránsito), `php artisan people:sincronizar-permisos` (crea `sistemas.altas.ver`/`atender` y los asigna), `php artisan config:cache`, `php artisan route:cache` si se usa, `php artisan queue:restart`, `npm run build`.
7. **Usuarios con rol `sistemas`**: crear (o confirmar) las cuentas de Sistemas en Usuarios y permisos con el rol `sistemas` — son quienes reciben el aviso en la campana y ven el contador. Sin ninguna, solo llega el correo.
8. **Regularizar lo anterior**: una vez, `php artisan sistemas:avisos-alta-pendientes --dry-run` y luego `--dias=60` (sin `--enviar`, para no mandar correos viejos en masa).
9. **Probar**: `php artisan people:diagnostico-notificaciones` y `php artisan sistemas:reenviar-aviso-alta {número de empleado}` con alguien de prueba; confirmar que llega al buzón de Sistemas y al CC, y que en la bandeja el estado pasa a «Enviado».
10. **`APP_URL`** con el dominio público (`https://people.mr-lana.com`): las ligas del correo (bandeja y expediente) se arman con él.
