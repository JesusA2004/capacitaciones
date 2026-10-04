# Cierre QA — validación final 2026-10-02

Base: backend/web `capacitaciones` @ `153236b` · app `mr-lana-people-app` @
`5862d41`, más los cambios de esta validación (sin commit). La matriz
requisito del PDF ↔ código está en `docs/AUDITORIA_PDF_VS_CODIGO_FINAL.md`;
las reglas vigentes, en `docs/CICLO_LABORAL_FINAL_IMPLEMENTADO.md` (sección 14).

## Backend/web — hallazgos y correcciones

1. **vue-tsc fallaba**: `Rh/Candidatos/Show.vue` usaba `fuente_etiqueta`, que
   `CandidatoPresenter::detalle()` ya enviaba pero faltaba en el tipo
   `CandidatoFicha` (`resources/js/types/cicloLaboral.ts`).
2. **ESLint**: 21 errores de orden de imports (efecto del reemplazo masivo de
   selects) y un import `Input` sin uso en `Rh/Aniversarios/Configuracion.vue`.
3. **Prettier**: 3 archivos sin formato (`CrudActionMenu.vue`, `DataTable.vue`,
   `ExpedienteDetalle.vue`).
4. **Tipo de solicitud mostrado en crudo**: el detalle RH y el del
   colaborador pintaban `baja_colaborador` como «Baja Colaborador» (y el tipo de
   baja `no_renovacion` como «No Renovacion»). Ahora los controladores envían
   `tipoEtiqueta` / `tipoBajaEtiqueta` desde `etiqueta()` de los enums; prueba
   en `SolicitudInternaTest`.
5. **Referencias residuales al sistema anterior** (comentarios, fixtures y
   títulos de prueba): «cerrada» en solicitudes y recibo «interno/no fiscal»
   en `SolicitudesService`, `Api\V1\SolicitudController`,
   `SolicitudesDemoSeeder`, `ReciboNominaService`, `ReciboNomina`,
   `FormatoDocumentosWordTest`, `ReciboNominaSemanalTest`, y en
   `docs/API_MOVIL.md`, `docs/backend-rh-completion.md`,
   `docs/ROLES_PERMISOS_RH.md`. Se conservan los «cerrada» que sí son del
   sistema actual: actas administrativas (`EstadoActa::Cerrada`), vacantes
   cerradas automáticamente y el muro de cumpleaños.

Revisado sin cambios: la migración
`2026_10_02_180000_quitar_estado_cerrada_de_solicitudes` (columnas `string`,
sin depender de SQLite, transacción, `cerrada` → `aprobada`, historial
conservado como `comentario`, permiso `solicitudes.cerrar` retirado);
`adjuntarDocumento()` bloqueando solicitudes finales no rompe el cierre
laboral (la solicitud de baja solo se aprueba en `ejecutarBaja`, después del
aviso; el finiquito firmado se guarda en `FiniquitoCalculo`).

Migraciones nuevas en esta validación: ninguna.

## App — hallazgos y correcciones

1. **Tema dinámico incompleto (codemod)**: varios componentes migrados a
   `useColores()` conservaban mapas de color a nivel de módulo con la paleta
   del arranque, así que no repintaban al cambiar claro/oscuro ni al llegar
   el color institucional. El más visible: **`Button`** (fondo de cada
   variante congelado → los botones nunca tomaban el primario
   institucional). También `ToastHost`, `RequestStatusTimeline`,
   `ApprovalTimeline`, `AnimatedProgressBar`, `SkeletonCardList`,
   `PrestamoDecision`, `MascotAssistant`, `DocumentStatusBadge`,
   `ConfidenceBadge`, Expediente, Tareas y Configuración (estado de push).
   Ahora son funciones de la paleta resueltas dentro del componente.
2. **108 imports `Colors` sin uso** que dejó el codemod (el componente ya usa
   `const Colors = useColores()`) y un import duplicado de `ThemeProvider` en
   `src/app/_layout.tsx`.
3. **Copia visible**: el timeline de solicitudes decía «Aprobada / cerrada» →
   «Aprobada». Comentarios de recibos alineados a «recibo de nómina».
4. **Expo Doctor**: 5 dependencias con parche desfasado del SDK 57
   (`expo`, `expo-camera`, `expo-constants`, `expo-document-picker`,
   `expo-router`); actualizadas con `npx expo install --fix`.

Verificado sin cambios: `react-hooks/rules-of-hooks` está activo en
`expo lint` y quedó en 0 errores (sin hooks condicionales ni fuera de
componente); `_layout.tsx` mantiene a propósito `Colors` de arranque para el
fondo nativo previo al provider; `Confetti` usa colores de arranque solo para
partículas decorativas.

Nota de entorno: un `expo start` que llevaba abierto desde antes de crear
`src/app/(app)/lecciones/` regenera `.expo/types/router.d.ts` con la ruta
`/lecciones/index`, y entonces `tsc` marca `/lecciones` como inválida. No es
un error del código: reiniciar Metro (o `npx expo start` limpio) lo resuelve.

## Revisión rápida de pantallas web tocadas

Recorridas con Playwright (Chrome, 1440 px) contra `localhost:8000`: Dashboard,
Solicitudes por tipo, listado, detalle SOL-000012 (baja con finiquito pagado),
Configuración → Notificaciones, Configuración → Apariencia (super_admin;
`rh_admin` recibe 403 porque no tiene `configuracion.apariencia`, intencional),
Sucursal, Alta, Mis pendientes y Candidato. Todas 200, sin errores de
JavaScript (solo el WebSocket de Reverb, que no corre en local), sin
«undefined/NaN» ni textos retirados.

La app no se abrió en dispositivo ni emulador en esta validación: su
cobertura fue `tsc`, `expo lint` y Jest.

## Resultado de la validación

| Check | Resultado |
|---|---|
| `php artisan test` (suite completa) | **900 pruebas: 897 passed, 3 skipped (2FA), 0 failed** |
| PHPStan nivel 7 | OK, 0 errores |
| Pint | OK |
| vue-tsc | OK |
| ESLint web | OK |
| Prettier | OK |
| Pruebas JS web (`npm run test:js`) | 18/18 |
| Vite build | OK |
| App `tsc --noEmit` | OK |
| App `expo lint` | OK (0 errores, 0 warnings) |
| App Jest | 51/51 suites, 523/523 pruebas |
| App `expo-doctor` | 21/21 |

## Pendientes externos reales

- **Plantillas jurídicas reales** (contratos, no renovación, rescisión,
  finiquito, responsivas) y **material de inducción**: el sistema crea el
  pendiente «plantilla faltante» y nunca inventa texto legal.
- **EAS/Apple**: credenciales de producción para publicar la build, SHA-256
  de la firma y Apple Team ID para App Links/Universal Links.
- **Hardware/producción**: QA en teléfono físico (push en segundo plano y
  arranque en frío, cámara/galería, visor PDF Android, tema oscuro real,
  pantallas de 320 px) y Reverb/WebSockets en el servidor de producción.
