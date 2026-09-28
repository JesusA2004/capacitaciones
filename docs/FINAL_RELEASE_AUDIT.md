# Auditoría final de release — 28/09/2026

Snapshot fechado de una sesión de cierre. Si lees esto mucho después de la
fecha de arriba, verifica contra el código actual antes de confiar en
cualquier afirmación de "implementado" — este documento describe un punto
en el tiempo, no una garantía permanente.

Commits base de esta sesión: backend `cc4beeb`, móvil `e69cf7b` (ambos
`main`, sin cambios sin commitear al iniciar).

## Qué se cerró en esta sesión

| Área | Resultado |
|---|---|
| Onboarding móvil | Revertido de por-usuario a global por instalación, con migración limpia de la llave antigua. Reaparecía en cada cambio de cuenta — era la queja explícita del negocio. |
| Experiencia (Mi espacio/Gestión RH) | De global-por-dispositivo a por-cuenta, ya no se borra en logout, con migración desde la llave antigua. |
| Biometría | De global a por-cuenta — corrige una fuga real: la cuenta B heredaba la biometría activada por la cuenta A en el mismo teléfono. |
| Bloqueo en frío | `appLockStore.lock()` invocado en `restoreSession()` — un proceso matado y reabierto con sesión guardada ahora siempre exige desbloqueo, sin importar el tiempo transcurrido. Antes lo evadía por completo. |
| Reautenticación | `POST /api/v1/reautenticar` nuevo (Hash::check, throttled, sin crear/revocar tokens). `LockScreen` ya no llama a `login()` para desbloquear — antes minteaba un token nuevo de Sanctum en cada desbloqueo sin revocar el anterior. |
| Motor DOCX — variables manuales | `document_templates.variables_manuales` + `VariableMappingService`: detecta marcadores `{{...}}` sin mapear, permite declarar etiqueta/tipo/obligatoriedad/default sin que RH memorice códigos, bloquea la generación (`puede_generar`) si falta una variable manual requerida (un dato base vacío sigue siendo solo aviso, sin cambios). Editor visual en Portal RH (`PlantillaVariablesDialog.vue`). |
| Motor DOCX — móvil | `POST /rh/formatos/{plantilla}/preparar` y `.../generar`, nuevos. El wizard móvil ya existía completo pero apagado (backend real no existía); ahora está conectado. Incluye verificación de alcance (`alcanzaColaborador`) que el panel web no tenía porque no la necesitaba (RH ya filtra por alcance antes de llegar al formulario). |
| QR de incorporación — condición de carrera | `IncorporacionInvitacionService::registrarUsuario()` ahora re-lee la invitación con `lockForUpdate()` dentro de la transacción antes de verificar `tieneUsosDisponibles()` — dos registros concurrentes con el mismo token de un solo uso ya no podían crear dos colaboradores; el resguardo final (`QueryException` de `users.email` único) se convierte en un error 422 limpio (`correo_registrado`) en vez de un 500. |
| QR de incorporación — tests desactualizados | 4 tests en `IncorporacionInvitacionTest.php` fallaban por un refactor real ya aplicado (Alta Digital QR simplificado: el formulario ahora pide `candidato_id`+`duracion_horas`, no `nombre_prellenado`/`email` sueltos) — corregidos, no era un bug de la app. |
| Préstamos — aprobación sin revisar | `SolicitudesService::cambiarEstado()` bloquea aprobar una solicitud tipo préstamo por el botón genérico de "Aprobar" (usado por cualquier otro tipo de solicitud): sin este guardado, `PrestamoService::crearDesdeSolicitud()` caía en sus defaults (monto tal cual lo pidió el colaborador, plazo inventado) simulando una autorización real que RH nunca hizo. Solo `PrestamoAutorizacionService::autorizar()` (pantalla "Autorizar préstamo") puede aprobar un préstamo. |
| Vacaciones — contador desactualizado | `Api\V1\Rh\ColaboradorController::show()` contaba `vacaciones_pendientes` solo de la tabla legacy `solicitudes_vacaciones`, que ya no recibe nada del flujo unificado actual — mostraba 0 pendientes aunque hubiera una solicitud real esperando revisión. Corregido para contar `solicitudes_internas` tipo `vacaciones`. |
| Notificaciones sin paginación | `GET /notificaciones` solo devolvía las 30 más recientes sin forma de ver historial más viejo. Ahora pagina de verdad (`meta.current_page/last_page/total`); móvil usa `useInfiniteQuery` con scroll infinito. El badge de la app ya no depende de esa lista (podía subcontar con más de 30 no leídas) — usa el contador autoritativo de `mobile/bootstrap`. |
| Seguridad — Sanctum sin expiración | `config/sanctum.php` tenía `expiration: null` (nunca expiraban) sin ningún pruning — un dispositivo perdido/robado quedaba con acceso indefinido. Ahora 90 días por defecto (`SANCTUM_TOKEN_EXPIRATION_MINUTES`) + `sanctum:prune-expired` diario en el scheduler. **Cambio de comportamiento que requiere aviso antes de deploy**: sesiones móviles con más de 90 días de inactividad exigirán login real la próxima vez (la app ya maneja esto correctamente — mismo camino que un 401 cualquiera). |
| Seguridad — DOCX zip bomb | Subir una plantilla DOCX solo validaba `mimes:docx` + tamaño subido, nunca el tamaño DESCOMPRIMIDO — un ZIP pequeño con razón de compresión absurda podía agotar memoria al leerlo con PhpWord. `DocxUploadValidator` ahora rechaza cualquier DOCX cuyo contenido descomprimido exceda 100 MB, en la subida (antes de guardarlo). |
| Seguridad — reasignación de push token | `PushTokenService::registrar()` reasigna un token a otra cuenta sin dejar rastro (necesario para "cambiar de cuenta en el mismo teléfono", no se bloqueó) — ahora registra un `Log::warning` con el hash del token (nunca el token en claro) cuando la reasignación cruza cuentas, para auditoría si alguna vez se abusa. |
| Seguridad — `MobileDevice::$hidden` | El modelo no ocultaba `push_token` de una eventual serialización JSON (nada lo serializa hoy, pero cerraba el hueco antes de que alguien agregue un listado de dispositivos). |

Ver el detalle completo (archivos, comandos, tests) en el mensaje de cierre
de la sesión de conversación — este documento resume, no repite todo.

## Tests

- Nuevos: `AuthApiTest` (+4 reautenticación), `PlantillaVariablesTest` (8),
  `RhFormatoGenerarApiTest` (5), `onboardingStore.test.ts`,
  `experienceStore.test.ts` (reescritos), `biometricStore.test.ts`,
  `appLockStore.test.ts` (nuevos), extensiones a `authStore.test.ts`,
  `DocxUploadValidatorTest` (3), `PushTokenServiceTest` (2), extensión a
  `PrestamoFlujoTest`/`RhColaboradorApiTest`/`NotificacionApiTest`/
  `DispositivoApiTest`/`IncorporacionInvitacionApiTest`, 4 tests corregidos
  en `IncorporacionInvitacionTest`.
- Backend: suite completa `php artisan test` — **738/758 passed** antes de
  las fases 5-7 (9 fallas + 8 errores, verificados idénticos en un `git
  worktree` limpio del commit base `cc4beeb`, ninguno es una regresión de
  esta sesión). Se relanzó una segunda vez después de las fases 5-7 para
  confirmar que los fixes nuevos no rompieron nada — ver el mensaje de
  cierre de la conversación para el resultado exacto de esa segunda corrida
  (tardó ~2h, no se esperó a que terminara para seguir documentando).
- Móvil: `npx jest` — 416/416 passed. `npx tsc --noEmit` limpio. `npx
  expo-doctor` 21/21. `npx expo export --platform android` exitoso.
- Web: `npm run build` exitoso, `vue-tsc`/ESLint limpios.

## Fallas preexistentes confirmadas (no regresiones)

Verificado corriendo los mismos archivos de prueba en un worktree aislado
(`git worktree add`, sin ningún cambio de esta sesión) en el commit base
`cc4beeb`:

- `tests/Feature/Asignaciones/AsignacionTest.php`
- `tests/Feature/MovimientosLaborales/MovimientosLaboralesTest.php`
- `tests/Feature/Onboarding/OnboardingServiceTest.php` (checklist de
  incorporación del colaborador — no confundir con el onboarding móvil,
  que es un módulo distinto sin relación)
- `tests/Feature/Rh/AltaDigitalTest.php`
- `tests/Feature/Rh/CandidatoTest.php`
- `tests/Feature/Rh/DocumentExtractionTest.php`
- `tests/Feature/Administracion/OrganigramaPersonasTest.php`
- `tests/Feature/Rh/ExpedienteTest.php`

Ninguno de estos archivos aparece en el diff de esta sesión. Algunas fallas
parecen bugs reales preexistentes (p. ej. rutas `rh.vacantes.cubrir` y
`administracion.usuarios.destroy` referenciadas por tests pero no
registradas; valores de enum de `Candidato` que no coinciden entre el test
y el código actual) — quedan documentadas aquí, no corregidas, porque
corregirlas no era parte de lo que se me pidió en esta sesión.

**Actualización**: `tests/Feature/Rh/IncorporacionInvitacionTest.php` SÍ se
corrigió en esta sesión (ver tabla de arriba) — 4 de sus tests fallaban por
un refactor real ya aplicado en la app (`candidato_id`/`duracion_horas` en
vez de campos sueltos), no por un bug. Se sacó de esta lista porque ya no
aplica: el archivo pasa completo ahora.

## Pendientes reales

Ver `docs/RELEASE_CHECKLIST.md` sección 7 y el cierre de la conversación de
esta sesión para el listado completo de fases no ejecutadas a profundidad
de implementación.
