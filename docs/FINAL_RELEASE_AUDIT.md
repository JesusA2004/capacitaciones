# Auditoría final de release — 28/09/2026 (cierre real)

Snapshot fechado de una sesión de cierre. Si lees esto mucho después de la
fecha de arriba, verifica contra el código actual antes de confiar en
cualquier afirmación de "implementado" — este documento describe un punto
en el tiempo, no una garantía permanente.

Commits base de esta sesión: backend `39ac646`, móvil `cb39751` (ambos
`main`, sin cambios sin commitear al iniciar).

Esta sesión retoma exactamente donde la anterior (commits `cc4beeb`/
`e69cf7b`, ver historial git) dejó pendiente: cerrar la suite completa a
verde, corregir las rutas/enums que esa sesión documentó pero no arregló, e
implementar el riesgo real detectado en variables automáticas del motor
DOCX.

## Qué se cerró en esta sesión

| Área | Resultado |
|---|---|
| Suite backend | 738/758 → **783/783 (3 omitidas por 2FA, ver CLAUDE.md)** (ver sección Tests). Los 8 archivos que la sesión anterior dejó documentados como "preexistentes" quedaron corregidos — la mayoría no eran bugs de rutas inexistentes sino contratos de test desactualizados o bugs reales de producto que esas mismas rutas rotas ocultaban. |
| Rutas `rh.vacantes.cubrir` / `administracion.usuarios.destroy` | Confirmadas **intencionalmente ausentes**, no bugs. `docs/HEADCOUNT_Y_VACANTES.md` documenta que las vacantes se derivan 100% de headcount ("nunca se capturan a mano"); `administracion.usuarios.destroy` viola directamente la regla "Nunca borres usuarios ni expedientes" de CLAUDE.md — la baja laboral real es `rh.expedientes.dar-de-baja`. Se corrigieron los tests para usar el contrato real, no se inventaron rutas. |
| Enum de `Candidato` | Confirmado: una sola definición real (`App\Enums\EstadoCandidato`, pipeline de 10 fases + 4 estados de salida). Los tests usaban un vocabulario obsoleto (`nuevo`, `aprobado_gerencia`, `rechazado`, `contactado`) de un diseño anterior — corregidos contra el enum real. Ningún consumidor (frontend incluido, que lee `estados`/`transicionesPermitidas` dinámicos del backend) usaba los valores viejos. |
| **Bug real — baja de colaborador nunca soft-eliminaba** | `BajaColaboradorService::ejecutar()` actualizaba `estatus`/`estado_alta` pero nunca llamaba `$colaborador->delete()` — el soft-delete que `reactivar()` (`restore()`) y las rutas `withTrashed()` de Expedientes daban por hecho simplemente nunca ocurría. Corregido. |
| **Bug real — corrección de CURP/RFC/NSS vía extracción de documentos se perdía** | `DocumentExtractionService` (revisión de datos detectados por OCR en documentos subidos) comparaba y escribía en `users.curp/rfc/nss` — una columna legacy que ningún otro flujo lee. El dato real vive en `Colaborador` (misma fuente que `PlaceholderResolver`/generación de contratos). RH aceptaba una corrección en pantalla y el contrato seguía sin el dato correcto. Corregido: ahora opera sobre `Colaborador`. |
| **Bug real — asignaciones de capacitación por sucursal/depto/puesto nunca alcanzaban a nadie** | `AsignacionService` filtraba usuarios comparando `users.sucursal_principal_id/departamento_id/puesto_id` — columnas legacy que **ni siquiera son `Fillable`** en el modelo `User` (ver su atributo `#[Fillable(...)]`, que deliberadamente las excluye desde la separación Usuario/Colaborador). Cualquier asignación de curso "por sucursal/depto/puesto" nunca alcanzaba a nadie nuevo. Corregido para filtrar vía la relación `colaborador`. |
| **Bug real — nuevo colaborador nunca heredaba asignaciones vigentes** | `AsignacionService::aplicarVigentesA()` existía completo (con su propio job/notificación) pero no se llamaba desde ningún flujo de alta real. Conectado en `AltaColaboradorService::registrar()` y `ConversionColaboradorService::convertir()` (los dos únicos puntos donde se crea un colaborador+cuenta nuevos). |
| **Bug de datos demo — seeder aprobaba préstamos con el botón genérico** | `SolicitudesDemoSeeder` y `SolicitudFormatoOficialTest` llamaban `SolicitudesService::aprobar()` directo sobre una solicitud tipo préstamo — exactamente lo que el guardado de la sesión anterior bloquea a propósito ("Un préstamo no se aprueba con el botón genérico"). No era un bug de producto: el guardado funciona correctamente: era el seeder/test los que no se habían actualizado al agregarlo. Corregidos para usar `PrestamoAutorizacionService::autorizar()` (mismo flujo real de la pantalla "Autorizar préstamo"). Afectaba en cascada a `DatabaseSeederProduccionTest` y `PeopleDiagnosticoCommandTest` (ambos corren el seeder). |
| Motor DOCX — variables **automáticas** requeridas | Antes: solo una variable manual (sin dato real, ej. `{{numero_de_obra}}`) podía bloquear `puede_generar`; un dato automático vacío (`{{curp}}`, `{{domicilio}}`, `{{fecha_ingreso}}`...) nunca bloqueaba, aunque el colaborador no lo tuviera capturado — hueco silencioso real. Ahora RH puede marcar **cualquier** marcador detectado (automático o manual) como requerido desde Portal RH → Formatos → Variables; retrocompatible (plantillas existentes quedan igual hasta que RH marque algo explícitamente). Mensaje de error humanizado ("Falta CURP." / "Faltan datos obligatorios: X, Y."). Mobile (`generar.tsx`) ahora respeta `puede_generar` del backend en vez de recalcular solo con variables manuales. |
| DOCX → PDF, fidelidad | El módulo de plantillas (`FormatoController` web y móvil) generaba PDF solo con PhpWord+DomPDF (aproximado). Se encontró que el módulo de "formatos oficiales" ya tenía un conversor desacoplado (`ConversorDocxPdf`) que prefiere LibreOffice headless si `FORMATOS_LIBREOFFICE_PATH` está configurado, con fallback automático — se reutilizó en ambos `descargarPdf()` (antes solo lo usaba formatos oficiales). LibreOffice no está instalado en este entorno de desarrollo; documentado el comando de instalación en `docs/PLANTILLAS_FORMATOS.md` y `.env.example`. |
| Seguridad — tokens Sanctum | `SANCTUM_TOKEN_EXPIRATION_MINUTES=129600` (90 días) y `sanctum:prune-expired --hours=24` diario confirmados correctos, sin cambios necesarios. |
| Migraciones | `php artisan migrate:status` — todas aplicadas, ninguna pendiente (incluida `add_variables_manuales_a_document_templates`). |
| Móvil | Sin cambios de comportamiento nuevos más allá de `generar.tsx` (arriba). Cross-account (biometría/experiencia), cold start, reautenticación sin token nuevo, y onboarding-una-sola-vez ya estaban cubiertos por tests de la sesión anterior — se verificaron de nuevo tras los cambios de esta sesión. |

## Tests nuevos/corregidos en esta sesión

- `tests/Feature/MovimientosLaborales/MovimientosLaboralesTest.php` — reescrito contra el contrato real (`rh.expedientes.datos-laborales.update`/`dar-de-baja`); se retiraron 2 tests de "cubrir vacante manual", una función que nunca existió así (contradice `docs/HEADCOUNT_Y_VACANTES.md`).
- `tests/Feature/Rh/ExpedienteTest.php`, `tests/Feature/Rh/AltaDigitalTest.php`, `tests/Feature/Onboarding/OnboardingServiceTest.php`, `tests/Feature/Rh/DocumentExtractionTest.php`, `tests/Feature/Administracion/OrganigramaPersonasTest.php`, `tests/Feature/Rh/CandidatoTest.php`, `tests/Feature/Asignaciones/AsignacionTest.php` — corregidos contra el contrato/modelo real (varios tenían el mismo patrón: asumir un dato en `User` que en realidad vive en `Colaborador`).
- `tests/Feature/Api/AuthApiTest.php` — nuevo test de 10 llamadas a `/reautenticar` con viaje en el tiempo (el endpoint está throttled a 5/min, intencional) verificando que `personal_access_tokens` no cambia.
- `tests/Feature/Rh/PlantillaVariablesTest.php` — 7 tests nuevos: marcar automática como requerida, rechazar automática no detectada, 4 escenarios de `puede_generar` (automática opcional/requerida vacía/requerida con valor/mezcla con manual), y generación real end-to-end (DOCX abierto como ZIP/XML verificando reemplazo real de placeholders, no solo JSON).
- `tests/Feature/Formatos/ConversorDocxPdfTest.php` — nuevo, prueba el selector LibreOffice/DomPDF sin depender de un LibreOffice real instalado.

## Resultado de validación

```
BACKEND
php artisan test:                783/783 (3 omitidas por 2FA, ver CLAUDE.md)
PINT:                             PASS
PHPSTAN (nivel 7):                PASS

WEB
types:check (vue-tsc):            PASS
lint:check (ESLint):              PASS
build:                            PASS

MOBILE
typecheck:                        PASS
lint:                             PASS
test:                             416/416 PASS
expo-doctor:                      21/21 PASS
expo export --platform android:   PASS

MIGRATIONS PENDING:                Ninguna
```

## Pendientes reales (no bloquean release, requieren decisión de negocio/QA física)

- **LibreOffice no está instalado en este servidor de desarrollo** — la conversión PDF sigue funcionando (fallback PhpWord/DomPDF), pero sin fidelidad exacta hasta que se instale en producción (comando en `docs/PLANTILLAS_FORMATOS.md`).
- **QA en dispositivo físico** (no ejecutable desde este entorno): biometría real, notificaciones push reales, cámara/escaneo de documentos, comportamiento con red intermitente.
- El código muerto identificado (`MovimientoLaboralService::registrarBaja()`'s parámetro `$crearVacante`, `registrarCoberturaTemporal()`, nunca invocados desde ningún controlador) se documenta aquí pero **no se tocó** — no es bug de esta sesión, es una decisión de producto (¿se retira la cobertura manual o se termina de conectar?) fuera del alcance de "cerrar lo que hay", ver `docs/HEADCOUNT_Y_VACANTES.md`.
