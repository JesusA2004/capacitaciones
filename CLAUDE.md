# CLAUDE.md

Guía para agentes de IA (Claude Code y similares) trabajando en este repositorio. Léela antes de tocar código — complementa, no repite, `README.md` y `docs/ARQUITECTURA.md`.

## Qué es este proyecto

MR. LANA PEOPLE: portal de RH (reclutamiento, administración de personal, solicitudes, headcount/vacantes, organigrama, reportes) construido sobre Laravel 13 + Inertia.js 3 + Vue 3 + TypeScript. Capacitación (curso/cuestionarios/sesiones en vivo) existe completa en el código pero está oculta tras el feature flag `capacitacion` — **no la elimines**, ver `docs/CAPACITACION_PROXIMAMENTE.md`.

## Reglas permanentes

- **Español mexicano en todo lo user-facing**: UI, mensajes de validación, nombres de rutas/variables/comentarios. El código de infraestructura (nombres de clases del framework, imports) va en inglés como siempre.
- **La lógica de negocio vive en `app/Services/*`, nunca en los controladores.** Un controlador Inertia y su equivalente en `app/Http/Controllers/Api/V1/*` deben llamar al mismo Service — nunca dupliques una regla de negocio entre web y API móvil. Ver `docs/ARQUITECTURA_SERVICES.md`.
- **La autorización real vive en el backend** (Policies + permisos de Spatie Permission), nunca solo en el frontend. El sidebar oculta accesos como complemento de UX, no como control de seguridad.
- **No inventes datos ni adivines estructura.** Si un Excel/PDF real está en `claude/` (headcount, formatos oficiales, rotación de personal), léelo con la librería correspondiente (PhpSpreadsheet, FPDI) en vez de asumir su forma. Una fila/columna que no cuadra se reporta explícitamente (auditable), nunca se ignora en silencio.
- **Nunca borres usuarios ni expedientes.** Una baja de colaborador bloquea acceso (`estatus`, tokens Sanctum, dispositivos móviles) y conserva todo su historial — ver `docs/SOLICITUDES_UNIFICADAS.md`.
- **Modo colaborador vs. modo operativo nunca se mezclan** en el mismo menú — ver `docs/ROLES_Y_NAVEGACION.md` antes de tocar `AppSidebar.vue` o agregar una pantalla nueva.
- **No confundas Organigrama, Matriz comercial, Headcount y Vacantes** — son 4 conceptos relacionados pero distintos, ver `docs/ORGANIGRAMA.md` y `docs/HEADCOUNT_Y_VACANTES.md` antes de tocar cualquiera de los cuatro.

## Convenciones de código que ya existen (no las reinventes)

- **`protected $table` explícito** en cualquier modelo cuyo nombre en español no pluralice bien con las reglas de Eloquent (`Sucursal` → `sucursales`, no `sucursals`).
- **Índices únicos con nombre explícito** en migraciones con 3+ columnas compuestas: el nombre autogenerado por Laravel excede el límite de 64 caracteres de MariaDB ("1071 Specified key too long").
- **`DB::transaction()`** en cualquier operación que escriba en más de una tabla relacionada (crear solicitud + historial, aprobar baja + revocar tokens + sincronizar vacante, etc.).
- **Un fallo al notificar (`Notification`/push) nunca deshace la acción principal** — se captura con `try/catch` y se registra en el log (`Log::warning`), nunca se relanza.
- **`AlcanceOrganizacionalService`** centraliza qué sucursales/colaboradores puede ver cada usuario — reutilízalo en cualquier query nueva que liste colaboradores/solicitudes/expedientes, no reinventes el scoping.
- **Enums de PHP 8** (`App\Enums\*`) para cualquier catálogo cerrado de valores (tipo, estado, género, etc.), con un método `etiqueta()` para el texto en español — nunca strings mágicos sueltos en el código.

## PHPStan (Larastan nivel 7) — lecciones ya aprendidas en este repo

- `(array) $coleccion` sobre un objeto `Illuminate\Support\Collection` **corrompe su estructura** (castea las propiedades internas del objeto, no sus elementos) — nunca uses ese cast para "convertir" una Collection; si un valor puede ser Collection o array, verifica con `instanceof` o tipa el parámetro como `Collection|array`.
- `User::query()->find($id)` sobre un modelo con `SoftDeletes`/ambigüedad de tipo de Eloquent puede inferirse como `Model|Collection<...>|null` — usa `->where('id', $id)->first()` cuando PHPStan se queje de `argument.type` con `find()`.
- Cuando PHPStan reporta `nullsafe.neverNull`, generalmente tiene razón (su análisis de flujo probó que la expresión no puede ser null en ese punto) — cambia `?->` por `->`, no lo ignores.
- Para forzar que PHPStan infiera `string` en vez de `non-falsy-string` (que rompe la covarianza de `Collection<TKey,...>`), usa `sprintf()` en vez de interpolación/concatenación de strings.
- **Nunca** agregues `@phpstan-ignore`, `@var` para sobreescribir un tipo inferido, ni anchas un tipo solo para silenciar un error — corrige la causa real. `composer run types:check` debe quedar en 0 errores antes de cualquier commit.

## Validación obligatoria antes de proponer un commit

```bash
composer run lint:check    # Pint
composer run types:check   # PHPStan/Larastan nivel 7
php artisan test           # Pest — 0 fallidas (omitidas por 2FA están bien)
npm run lint:check         # ESLint
npm run types:check        # vue-tsc
npm run build               # Build de producción
```

Si tocaste rutas o controladores, regenera los helpers de Wayfinder con `--with-form` (ver README) antes de correr `npm run types:check` — si no, vue-tsc falla por helpers desactualizados, no por un error real tuyo.

`php artisan test` sobre la suite completa puede tardar varios minutos — córrelo en background (`run_in_background`) y no edites archivos PHP mientras corre (una edición a mitad de la corrida puede producir fallas falsas por condición de carrera entre el autoload y tu edición, no por un bug real).

## Estructura de alto nivel

Ver `docs/ARQUITECTURA.md` para el detalle completo. Resumen:

- `app/Models` — Eloquent, nombres en español.
- `app/Services/<Dominio>/*` — lógica de negocio, un servicio por responsabilidad.
- `app/Http/Controllers/*` (web/Inertia) y `app/Http/Controllers/Api/V1/*` (API móvil, Sanctum) — delgados, delegan a Services.
- `app/Enums` — catálogos cerrados.
- `app/Policies` — autorización por modelo.
- `resources/js/pages/**` — una página Inertia por ruta; `resources/js/components/**` — componentes reutilizables; `resources/js/types/**` — un archivo de tipos TS por dominio, re-exportado desde `resources/js/types/index.ts`.
- `routes/*.php` — un archivo por dominio (no todo en `web.php`).
- `config/solicitudes.php`, `config/vacaciones.php`, `config/features.php`, `config/headcount.php` (si existe) — configuración de negocio versionada, no hardcodeada en servicios.
- `claude/` — insumos reales que entrega el negocio (Excel de headcount, Excel de rotación de personal, PDFs de formatos oficiales) — nunca los borres ni asumas su contenido sin leerlos.

## Seeders de desarrollo

`php artisan db:seed` (o `migrate:fresh --seed`) debe dejar el sistema usable de punta a punta: todo colaborador activo con puesto/sucursal/departamento/género, matriz comercial cargada, roles con contraseña `Capacitacion2026!` — ver tabla completa en `README.md`. Nunca uses `fake()`/Faker en un seeder que corra en producción (`composer install --no-dev` no lo instala) — solo en `database/factories/*` (usadas por tests, no por seeders de producción).
