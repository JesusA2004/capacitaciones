# Checklist de release — MR. LANA PEOPLE

Referencia rápida antes de marcar una entrega como lista. No repite el
runbook operativo completo (ver `docs/DEPLOY.md` para el backend y el
`README.md` de `mr-lana-people-app` para la app) — solo enumera qué validar
y en qué orden.

## 1. Backend

```bash
composer run lint:check    # Pint
composer run types:check   # PHPStan/Larastan nivel 7 — 0 errores
php artisan test           # Pest — 0 fallidas reales (ver nota de fallas preexistentes abajo)
```

Si tocaste rutas/controladores: regenerar Wayfinder antes de `npm run
types:check` en el paso 2 (ver README, sección Wayfinder).

**Fallas preexistentes conocidas** (no relacionadas con cambios de esta
sesión — verificadas contra un `git worktree` limpio del commit base, ver
`docs/FINAL_RELEASE_AUDIT.md`): `AsignacionTest`, `MovimientosLaboralesTest`,
`OnboardingServiceTest` (checklist de incorporación del colaborador, no
confundir con el onboarding móvil), `AltaDigitalTest`, `CandidatoTest`,
`DocumentExtractionTest`, `OrganigramaPersonasTest`, `ExpedienteTest` — 9
pruebas fallidas + 8 con error en la suite completa (738/758 al 28/09/2026),
reproducidas idénticas en el commit base `cc4beeb` sin ningún cambio de esta
sesión. No las corregí (fuera del alcance de lo que se me pidió, y algunas
parecen requerir decisiones de producto — p. ej. rutas renombradas) pero
quedan documentadas aquí para que no se confundan con una regresión nueva.
(`IncorporacionInvitacionTest` SÍ se corrigió en esta sesión — ya no está en
esta lista, ver `docs/FINAL_RELEASE_AUDIT.md`.)

## 2. Web (Portal RH / Vue)

```bash
npm run types:check   # vue-tsc
npm run lint:check    # ESLint (o npm run lint si aplica --fix)
npm run build         # build de producción
```

## 3. Mobile (Expo / React Native)

Desde `mr-lana-people-app/`:

```bash
npm ci
npm run typecheck
npm run lint
npm test
npx expo-doctor
npx expo export --platform android
npx expo export --platform ios   # si hay entorno macOS disponible
```

## 4. Migraciones pendientes

```bash
php artisan migrate:status   # confirmar qué falta aplicar antes de migrate --force
```

Migraciones nuevas de esta sesión: `2026_09_28_050605_add_variables_manuales_a_document_templates`.

## 5. Feature flags / configuración a confirmar en el entorno destino

- `config('mobile.features.formatos')` (`APP_MOBILE_FORMATOS_ENABLED`, default `true`) —
  generación de formatos DOCX desde la app RH.
- `SANCTUM_TOKEN_EXPIRATION_MINUTES` (default 129600 = 90 días) — **antes
  los tokens nunca expiraban**; avisar al equipo antes de desplegar esto,
  ya que sesiones móviles con más de 90 días de inactividad exigirán login
  real la próxima vez. Corre `sanctum:prune-expired` en el scheduler
  (ya agregado a `routes/console.php`).
- Resto de flags de `config/mobile.php` sin cambios.

## 6. Verificación manual mínima

- Portal RH → Formatos → Plantillas avanzadas → una plantilla → "Variables"
  abre y guarda correctamente (ver `docs/DOCX_TEMPLATES.md`).
- App móvil → Gestión RH → Formatos → generar un documento de prueba
  (ver `docs/DEVICE_QA.md`, sección "Formatos").
- App móvil: matar el proceso con sesión iniciada y reabrir → debe pedir
  desbloqueo (ver `docs/DEVICE_QA.md`, sección "Bloqueo en frío").
- Registro por QR de incorporación completo (ver
  `docs/PRUEBA_MAESTRA_COLABORADOR_NUEVO.md`).
- Un préstamo NO se puede aprobar con el botón genérico "Aprobar" — solo
  con "Autorizar préstamo".
- Subir un DOCX de plantilla mayor a 100 MB descomprimido debe rechazarse
  en la subida (`DocxUploadValidator`).

## 7. Qué NO se tocó en esta sesión (alcance real, no MVP)

Cubierto a profundidad de implementación en esta sesión: sesión/onboarding/
experiencia/biometría/lock, motor DOCX completo (backend+web+móvil),
incorporación por QR (incluida una condición de carrera real), préstamos,
vacaciones, notificaciones (paginación), y un pase de seguridad dirigido
(Sanctum, uploads DOCX, push tokens). NO se profundizó en: UI/UX visual
completa (responsive/accesibilidad/animaciones en el resto de pantallas —
solo se auditó dark-mode/colores hardcodeados), documentos laborales más
allá de lo ya confirmado sólido, contratos (auditado ligero, sin hallazgos),
y las suites de checklist manual en teléfono físico (documentadas, no
ejecutadas por mí). No se inventó ni se marcó como "completo" nada de eso.
