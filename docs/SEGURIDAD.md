# Seguridad — Portal de Capacitación Mr. Lana

Este documento resume las decisiones y mecanismos de seguridad del proyecto completo (Fases 1–8) y sirve como checklist antes de desplegar a producción.

## Autenticación

- Laravel Fortify gestiona login, restablecimiento de contraseña y (opcionalmente) autenticación de dos factores.
- **Registro público deshabilitado** (Fase 1): los colaboradores no se auto-registran. Un administrador los crea desde **Administración → Colaboradores**; el alta genera una contraseña aleatoria irrecuperable (nunca se muestra ni se envía) y reutiliza el flujo de "restablecer contraseña" de Fortify para que el propio colaborador la establezca por correo.
- **Contraseñas**: `App\Providers\AppServiceProvider` define `Password::defaults()` — en producción exige mínimo 12 caracteres, mayúsculas/minúsculas, letras, números y símbolos (`app()->isProduction()` relaja la regla solo en entornos de desarrollo/pruebas, nunca en producción).
- **Rate limiting**: Fortify limita los intentos de login a 5 por minuto por combinación email+IP (`config/fortify.php`, limiter `login`), mitigando fuerza bruta.
- **Verificación de correo**: habilitada vía el middleware `verified` en todas las rutas autenticadas.

## Autorización

- Cada acción administrativa pasa por una **Policy** de Laravel o por `$usuario->can('permiso.especifico')` (Spatie Laravel Permission). Nunca se confía en ocultar un botón en el frontend como único mecanismo de control de acceso — ver `docs/ARQUITECTURA.md`.
- **Roles y permisos son editables** desde **Administración → Roles y permisos**; el catálogo sembrado (`RolesYPermisosSeeder`) es solo el punto de partida, no un valor fijo en código.
- **Aislamiento por sucursal** (`App\Services\AlcanceOrganizacionalService`, Fase 1): centraliza qué sucursales/colaboradores puede ver cada rol (`super_admin`/`administrador_capacitacion`/`auditor` ven todo; `gerente_sucursal`/`supervisor` solo su sucursal principal + adicionales autorizadas; el resto solo a sí mismos). Se usa tanto en Policies como en el `WHERE` de las consultas de listados y reportes — nunca solo en la vista.
- **Verificación de relaciones anidadas** (hallazgo y corrección de la Fase 8): en rutas con varios segmentos de recurso (p. ej. `cursos/{curso}/modulos/{modulo}/lecciones/{leccion}`, o `sesiones/{sesion}/asistencias/{asistencia}`), Laravel resuelve cada segmento **de forma independiente** por su ID — no valida automáticamente que el hijo en efecto pertenezca al padre indicado en la URL. Sin una comprobación explícita, alguien con el permiso correcto para *algún* recurso del mismo tipo podía combinar IDs de recursos sin relación real entre sí (por ejemplo, marcar la asistencia de una sesión ajena pasando una sesión propia junto con el ID de una asistencia de otra sesión). Se auditaron y corrigieron todos los controladores con este patrón (`CursoModuloController`, `LeccionController`, `CuestionarioController`, `ActividadController`, `Reuniones\SesionEnVivoController`, `Reuniones\AsistenciaController`), agregando `abort_unless($hijo->padre_id === $padre->id, 404)` antes de operar. Cubierto por `tests/Feature/Seguridad/IntegridadRelacionesAnidadasTest.php`.

## Multimedia y archivos

- La biblioteca multimedia (Fase 3) nunca expone la ruta física real del disco `nas` al frontend: los nombres de archivo internos son UUID no adivinables, y el reproductor de video, los manifiestos HLS y los segmentos se sirven exclusivamente a través de **URLs firmadas de corta duración** (`URL::temporarySignedRoute`, `config('media.token_ttl')`, 600s por defecto) detrás del middleware `auth`.
- **Control de avance de video respaldado por el servidor** (no solo por el reproductor): el manifiesto y los segmentos HLS que el servidor entrega se truncan/rechazan según lo que el usuario realmente ha visto (`intervalos_video_vistos`), así que ni un cliente HLS armado a mano puede pedir un segmento no autorizado. Ver `docs/PROCESAMIENTO_VIDEO.md`.
- **Validación de subida de archivos**: tipo MIME y extensión restringidos por tipo de recurso (`StoreRecursoMultimediaRequest`, `StoreEntregaActividadRequest`), límite de tamaño configurable (`MEDIA_MAX_UPLOAD_MB`).

## Rutas públicas (sin sesión iniciada)

Solo dos rutas son intencionalmente públicas, y ambas exponen el mínimo de información necesario:

- `/` (bienvenida).
- `/constancias/verificar/{folio}` (Fase 7): confirma si un folio de constancia es válido y muestra únicamente nombre, curso y fecha — nunca correo, teléfono u otro dato personal. Pensada para que un tercero (otra empresa, un auditor externo) verifique una constancia sin necesitar una cuenta en el portal.

## Auditoría

- Spatie Activitylog registra cambios en modelos sensibles (`User`, `Sucursal`, `Curso`, …) con `logOnlyDirty()` y sin capturar nunca contraseñas ni tokens (los `casts()`/`$fillable` de `User` excluyen explícitamente `password`, `two_factor_secret`, `two_factor_recovery_codes`, `remember_token` de la serialización — ver `#[Hidden(...)]` en `app/Models/User.php`).
- La corrección de asistencias (Fase 5) exige permiso adicional (`asistencias.corregir`) y motivo obligatorio, guardando quién corrigió y por qué — no solo el activity log genérico, sino un campo de auditoría específico del dominio (`Asistencia.corregido_por`/`motivo_correccion`).

## Rendimiento (Fase 8)

- Índices agregados en columnas de filtro/orden frecuentes que no quedaban indexadas automáticamente por las relaciones (`asignaciones_usuario.(estado, fecha_limite)`, `sesiones_en_vivo.(estado, fecha_inicio)`, `intentos_cuestionario.estado`, `entregas_actividad.estado`).
- Se identificaron y corrigieron dos problemas de consultas N+1 en el detalle de "Mi capacitación" (`MiCapacitacionController::show`, `ProgresoService::leccionCompletada`), reduciendo el número de consultas de una lista que crecía con el número de lecciones del curso a un número fijo. Cubierto por `tests/Feature/Rendimiento/ConsultasMiCapacitacionTest.php`.

## Antes de desplegar a producción

1. **Variables de entorno**: `APP_ENV=production`, `APP_DEBUG=false` (nunca `true` en producción — expondría trazas de error con rutas del servidor y, potencialmente, credenciales en variables de entorno volcadas). `APP_KEY` generado y único por entorno (`php artisan key:generate`).
2. **Caché de configuración/rutas/vistas** (mejora el tiempo de arranque de cada request):
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan event:cache
   ```
   Recordatorio: tras cualquier cambio de `.env` en producción hay que volver a correr `config:cache`, porque con la config cacheada Laravel deja de leer `.env` directamente.
3. **Colas**: correr `php artisan queue:work` (o Horizon) bajo un supervisor de procesos (Supervisor/systemd) para los jobs en cola (procesamiento de video, notificaciones, materialización de asignaciones). En desarrollo `QUEUE_CONNECTION=sync`/`database` es suficiente; en producción se recomienda Redis.
4. **Scheduler**: agregar la entrada de cron estándar de Laravel (`* * * * * php artisan schedule:run`) para que se ejecuten los recordatorios automáticos de la Fase 7.
5. **Correo**: configurar `MAIL_MAILER` con un proveedor real (no `log`) y enviar una notificación de prueba de cada tipo antes de habilitarlo para todos los usuarios.
6. **HTTPS obligatorio**: sirve detrás de un proxy con TLS; confirmar que `APP_URL` usa `https://` y que las cookies de sesión son `secure` (`SESSION_SECURE_COOKIE=true`) en producción.
7. **NAS y FFmpeg**: instalar FFmpeg/FFprobe reales y configurar el disco `nas` según `docs/CONFIGURACION_NAS.md` — no probado de extremo a extremo en este entorno de desarrollo (Windows/WAMP sin esos binarios).
8. **Integraciones externas**: configurar credenciales reales de Google Meet/Zoom (`docs/SESIONES_EN_VIVO.md`) solo si se van a usar; ambas se degradan con gracia si quedan deshabilitadas.

## Limitaciones conocidas de este entorno de desarrollo

- FFmpeg/FFprobe no están instalados (Windows/WAMP): la conversión real de video a HLS no se probó de extremo a extremo aquí; las pruebas automatizadas cubren el control de flujo con `Process::fake()`.
- No hay credenciales reales de correo SMTP, Google Meet ni Zoom: las pruebas automatizadas cubren estas integraciones con `Notification::fake()`/`Http::fake()`, pero no se verificaron contra los servicios reales.
