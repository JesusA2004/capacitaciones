# Cumpleaños

Módulo `/rh/cumpleanos` (panel RH/admin: dashboard, calendario mensual, tarjeta de
felicitación descargable y catálogo de frases) + notificación automática al
colaborador en su cumpleaños + recordatorio diario a RH. Tablas `birthday_phrases` y
`birthday_greetings` — ver migraciones `2026_09_10_090100_create_birthday_phrases_table`
y `2026_09_10_090101_create_birthday_greetings_table`.

## Origen de la fecha de nacimiento

Usa la columna `users.fecha_nacimiento` (ya existente desde
`2026_07_22_222904_add_datos_personales_a_users_table`, ver `docs/MODELO_DATOS.md`). No
se agregó ninguna migración nueva para esto: el módulo simplemente exige que RH capture
el dato desde el expediente para que un colaborador aparezca en cumpleaños/calendario.

El **año** de nacimiento nunca se manda al frontend: los servicios y controladores solo
exponen `dia`/`mes`, y la **edad** (que sí delata el año) solo se agrega al payload si
`config('cumpleanos.show_age')` está activo (`CUMPLEANOS_SHOW_AGE`, por defecto `false`).

## Servicios

- **`App\Services\Cumpleanos\CumpleanosService`**: listados (`cumpleanosDelMes`,
  `cumpleanosDeHoy`, `proximosCumpleanos`, `colaboradoresPorPeriodo` para la API móvil),
  cálculo de edad, payload de calendario, notificación al colaborador (automática vía
  `felicitarColaborador()` y manual vía `reenviarManual()`) y recordatorio a RH
  (`notificarRh()`). Todos los listados aceptan un `?User $usuario` para acotar por
  `AlcanceOrganizacionalService` — `null` (usado por los commands) significa "toda la
  organización", nunca expuesto directo a un usuario sin permiso.
- **`App\Services\Cumpleanos\BirthdayCardService`**: dueño de crear/regenerar el
  registro `BirthdayGreeting` y renderizar la tarjeta PNG con **GD** (extensión ya
  disponible en el proyecto, sin dependencias nuevas). `generar()` es idempotente: si ya
  existe una felicitación para ese colaborador/fecha, la regresa tal cual — nunca cambia
  la frase ni la imagen. `regenerar()` fuerza una nueva frase e imagen.
- **`App\Services\Cumpleanos\CumpleanosStorageService`**: espejo de
  `DocumentoStorageService`/`CvStorageService` para el disco `nas`
  (`config('cumpleanos.disk')`). `card_path` nunca se expone al frontend.

## Frases

Catálogo `birthday_phrases` (`texto`, `categoria`, `activo`, `orden`, `usado_count`,
`ultimo_uso_at`). `BirthdayPhraseSeeder` siembra 30 frases iniciales (idempotente, usa
`firstOrCreate` por texto). La rotación (`BirthdayCardService::elegirFrase()`) elige la
frase activa menos usada recientemente (`ultimo_uso_at` ascendente, luego
`usado_count`), y al elegirla incrementa su contador — así no se repite siempre la
misma, y puede repetirse después de que las demás también se hayan usado.

Gestión desde el panel RH (permiso `rh.cumpleanos.frases.gestionar`): agregar, activar/
desactivar y eliminar frases (pestaña "Frases" de `/rh/cumpleanos`).

## Tarjeta de felicitación

PNG de `config('cumpleanos.card_width')`×`config('cumpleanos.card_height')` (por
defecto 1080×1350, recomendado para WhatsApp/redes internas). Incluye: logo
(`public/images/logoLetras.png`), globos decorativos, foto circular del colaborador (si
`config('cumpleanos.show_employee_photo')` y tiene `foto_path`), nombre completo, frase,
sucursal (si `config('cumpleanos.show_branch')`) y firma "MR. LANA". Fuente:
`vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf` (ya instalada vía
`barryvdh/laravel-dompdf`, sin dependencias nuevas).

Descarga: `GET /rh/cumpleanos/{colaborador}/felicitacion/descargar` (permiso
`rh.cumpleanos.descargar_imagen`) — nombre de archivo
`feliz-cumpleanos-nombre-apellido-2026.png`. Nunca expone la ruta física del NAS: el
archivo se sirve en streaming a través del controlador.

## Notificación automática al colaborador

Command diario `php artisan cumpleanos:enviar-felicitaciones` (scheduler:
`routes/console.php`, `dailyAt('08:00')`, zona `America/Mexico_City`):

1. Busca colaboradores **activos** cuyo día/mes de `fecha_nacimiento` sea hoy.
2. Genera (si no existe) su `BirthdayGreeting` de hoy — idempotente.
3. Si `BirthdayGreeting.enviada_at` ya tiene valor, no vuelve a notificar (correr el
   command dos veces el mismo día no duplica nada).
4. Notificación in-app (`App\Notifications\Mobile\BirthdayGreetingNotification`, canales
   `database` + `broadcast`) y push (`App\Services\MobilePush\PushNotifier`, tipo
   `cumpleanos`) si el colaborador tiene un dispositivo móvil registrado.
5. Un fallo en tarjeta/push de un colaborador se registra en log
   (`Log::error('cumpleanos: ...')`) y no interrumpe al resto del lote ni tumba el
   scheduler.

Recordatorio a RH: `php artisan cumpleanos:recordar-rh` (`dailyAt('07:30')`). Notifica a
quien tenga el permiso `rh.cumpleanos.ver` con el conteo de cumpleaños de hoy y de los
próximos 7 días — nunca nombres ni fecha completa de nacimiento en la notificación (el
detalle vive en el panel web/app). No envía nada si no hay nada que avisar.

Ambos commands son seguros de correr manualmente de más: la idempotencia vive en los
propios datos (`enviada_at`, unique `user_id`+`fecha`), no en el scheduler.

## Envío manual desde el panel RH

Botón "Enviar felicitación manual" en `/rh/cumpleanos/{colaborador}/felicitacion`
(permiso `rh.cumpleanos.notificaciones.gestionar`) llama a
`CumpleanosService::reenviarManual()`: a diferencia del envío automático, **siempre**
notifica aunque ya se haya enviado antes (acción explícita de RH), y registra
`enviada_por_id`.

## API para la app móvil (colaborador)

```
GET /api/v1/colaborador/cumpleanos/felicitacion-actual        (auth:sanctum)
GET /api/v1/colaborador/cumpleanos/felicitacion-actual/imagen (auth:sanctum)
```

Solo existe felicitación si **hoy** es el cumpleaños del colaborador autenticado; si no,
`felicitacion-actual` responde `{"data": null}` con `404` y la imagen responde `404`
controlado. Nunca genera ni notifica nada nuevo (eso es el command diario) — solo
consulta lo ya generado para hoy. Nunca devuelve la felicitación de otro colaborador.

## API para la app móvil (RH) — ver `docs/RH_MOBILE_API.md` / `docs/BACKEND_MOBILE_V5.md`

```
GET /api/v1/rh/cumpleanos?periodo=hoy|7_dias|30_dias|mes&mes=&sucursal_id=&departamento_id=&q=&page=&per_page=
GET /api/v1/rh/cumpleanos/{greeting}
GET /api/v1/rh/cumpleanos/{greeting}/imagen
GET /api/v1/rh/cumpleanos/{colaborador}/foto
```

Permiso `rh.cumpleanos.ver`, acotado por `AlcanceOrganizacionalService`. `{greeting}` es
el destino del push `{"type": "rh_cumpleanos", "resource_id": greeting_id}`. Nunca
regresa el año de nacimiento; `foto_url_api`/`card_url` son rutas protegidas, nunca la
ruta física.

## Permisos

`rh.cumpleanos.ver`, `rh.cumpleanos.calendario`, `rh.cumpleanos.descargar_imagen`,
`rh.cumpleanos.configurar`, `rh.cumpleanos.frases.gestionar`,
`rh.cumpleanos.notificaciones.gestionar` (`RolesYPermisosSeeder`). `super_admin` y
`rh_admin`: todos. `rh_auxiliar`: ver, calendario, descargar imagen (sin gestionar
frases/notificaciones ni configurar). `colaborador`: ninguno de administración (solo su
propia felicitación vía la app, sección anterior).

## Configuración

`config/cumpleanos.php` — variables `.env`:

```
CUMPLEANOS_ENABLED=true
CUMPLEANOS_NOTIFY_EMPLOYEE=true
CUMPLEANOS_NOTIFY_RH=true
CUMPLEANOS_CARD_WIDTH=1080
CUMPLEANOS_CARD_HEIGHT=1350
CUMPLEANOS_CARD_BACKGROUND=#FFF8E7
CUMPLEANOS_SHOW_BRANCH=true
CUMPLEANOS_SHOW_PHOTO=true
CUMPLEANOS_SHOW_AGE=false
CUMPLEANOS_AUTO_GENERATE_CARDS=true
```

`CUMPLEANOS_ENABLED=false` apaga el dashboard/calendario (el panel muestra un aviso
explícito, sin botones falsos) y los dos commands del scheduler (salen con éxito sin
hacer nada).

## Probar manualmente

```bash
# Capturar fecha_nacimiento = hoy a un colaborador de prueba (tinker o UI de Colaboradores)
php artisan tinker --execute="App\Models\User::first()->update(['fecha_nacimiento' => now()->subYears(30)]);"

php artisan cumpleanos:enviar-felicitaciones
php artisan cumpleanos:recordar-rh

# Panel RH
# /rh/cumpleanos  -> pestaña "Hoy" debe mostrar al colaborador
# /rh/cumpleanos/{id}/felicitacion -> ver/descargar/regenerar/enviar manual
```

## Tests

`tests/Feature/Cumpleanos/CumpleanosTest.php` (permisos, listados, idempotencia de la
felicitación, descarga sin exponer ruta física) y
`tests/Feature/Cumpleanos/EnviarFelicitacionesCommandTest.php` (idempotencia de los
commands, notificaciones). API: `tests/Feature/Api/ColaboradorCumpleanosApiTest.php` y
`tests/Feature/Api/Rh/RhCumpleanosApiTest.php`.
