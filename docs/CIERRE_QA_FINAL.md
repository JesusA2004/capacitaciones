# Cierre QA — motor documental fiel (2026-10-04)

Base: backend/web `capacitaciones` @ `edea56c` · app `mr-lana-people-app` @
`44704e3`, más los cambios de esta ronda (sin commit). Detalle técnico en
`docs/MOTOR_DOCUMENTOS_MAESTROS.md`; resultados por formato en
`docs/INVENTARIO_FORMATOS_JURIDICOS.md`.

## Hallazgos reales y correcciones

1. **PDF "aproximado" como documento definitivo**: sin LibreOffice/Word, el
   motor caía a PhpWord+DomPDF y lo emitía como contrato. Ahora nunca:
   422 `DOCUMENT_CONVERTER_UNAVAILABLE`; niveles nativa/alta/aproximada.
   También en el módulo heredado de formatos ("Descargar PDF").
2. **Script de Word**: podía dejar `WINWORD.EXE` huérfano al expirar el
   tiempo y usaba `SaveAs2`. Ahora: solo lectura, `ExportAsFixedFormat`,
   liberación COM y PID propio (se termina solo ese proceso).
3. **Preparación DOCX alteraba el formato** de blancos con varios formatos
   (convenios de confidencialidad, p. 11: 96.8 % de coincidencia; contrato
   indeterminado Regional p. 7: 91.6 %). Corregido (tramos con formato +
   separadores): todos los Word activos quedan en 1.000.
4. **Tabulador como texto**: un blanco de tabuladores restaurado se
   escribía como `	` dentro de `<w:t>`; ahora `<w:tab/>`.
5. **Overlay encimaba texto** que no cabía ni a 6 pt; ahora se reporta y
   bloquea (`DOCUMENT_FIELD_OVERFLOW`). Préstamo: domicilio completo no cabía
   en las celdas "Calle" (154 mm en 68 mm) y el importe con letra del pago
   invadía la leyenda impresa "Moneda Nacional": corregidos los campos.
6. **Fuente Aptos** no instalada (permiso extraordinario): detectada por el
   diagnóstico de fuentes; la versión no se valida hasta instalarla.
7. **Página extra con datos largos** (párrafos vacíos al final de 3
   originales): advertencia en la versión; bloqueo con dato señalado si
   ocurre con una persona real.
8. **`migrate:fresh --seed` dejaba todos los puestos sin grupo documental**
   (la migración que los siembra corría antes que el seeder de puestos):
   ahora `PuestoJerarquiaSeeder` aplica el valor inicial sin pisar
   decisiones de RH. Demo: plantilla DEMO de capacitación solo si no se
   importaron los formatos de Jurídico.
9. **Puesto ambiguo en silencio**: Parámetros RH permitía quitar el grupo
   documental; ahora exige decisión (grupo o "no requiere" con motivo).
10. **Mapeo de campos**: la regla "mapeados = 100 %" contaba las líneas de
    firma (que nunca se mapean); corregido.
11. **Evaluación sin cierre**: generarla daba un "dato faltante: Cierre
    laboral" no capturable; ahora se explica y, desde la evaluación, se
    genera sobre el cierre ligado.
12. **Notificaciones de documentos laborales** llevaban a RH a "Mi
    expediente"; ahora al proceso de la persona (solicitud o ciclo).
13. **Campos de relación editables** (puesto, sucursal, jefe) en el modal de
    datos faltantes: ya no se capturan desde un documento.
14. **Representante legal / domicilio fiscal** no se podían capturar en
    Empresas: agregados; la vista previa indica si se usa el predeterminado.
15. **Búsqueda de colaboradores sensible a acentos** en SQLite ("perez" no
    encontraba "Pérez"): plegado a ASCII.

## Nuevo

- QA visual por versión (Word + rasterizado nativo / pdftoppm), diagnóstico
  de fuentes, activación segura con excepción auditada (super_admin).
- Word llenado + PDF con hashes, motor, fidelidad, versión y páginas.
- Revisión excepcional de documentos firmados (permiso + motivo; el firmado
  se conserva). Descarga del Word (RH/gerente/regional; nunca el titular).
- Cobertura documental por puesto.
- Web: Documentos maestros rediseñado (KPIs, filtros, tarjetas, detalle con
  Resumen / Diseño / Probar con colaborador con buscador y pestañas Original
  vs Generado / Versiones / Diagnóstico técnico plegado; carga con zona de
  arrastre), Cobertura, Documentos del proceso con línea de tiempo, una
  acción principal y menú "…", avisos por código de error, modal de datos
  faltantes con controles por tipo.
- App: tarjeta de documentos con línea de tiempo, siguiente acción del
  backend, "Más acciones", controles por tipo (selector, fecha), revisión
  con motivo, avisos del motor; la pantalla de documento abre directo el
  visor, el Word (compartir) o el paso físico.

## Smoke manual

- Web (BD de desarrollo, Word real): Documentos maestros, Cobertura y
  Parámetros RH (200 para RH, 403 para gerente); detalle de master;
  búsqueda; original PDF (overlay y Word nativo); "Probar con colaborador"
  (9 → 9 páginas, fidelidad nativa, 0 desbordes); documentos del proceso de
  colaboradores (incluido uno dado de baja), cierre, solicitud de permiso,
  préstamo y evaluación; un colaborador no ve los de otra persona (403);
  generación real de un contrato (Word nativo, 9 páginas, PDF + Word).
- App: verificada con typecheck, ESLint, Jest (contrato del payload nuevo) y
  Expo Doctor. **No** se corrió en dispositivo/emulador en esta sesión.
- Navegador (Chrome, servidor local, Word real, 2026-10-05): carga por zona
  de arrastre de un DOCX (renuncia: QA visual automática, 100 %, 1 → 1
  página) y de un PDF (permiso: QA aprobada); "Probar con colaborador" con
  pestañas Original / Generado; regeneración desde el ciclo del colaborador
  de contrato de capacitación, confidencialidad y no competencia: POST 201,
  PDF 200 (`%PDF`, Word nativo) y Word 200 (`PK`), sin errores de consola.

## Retiro del módulo anterior de formatos (2026-10-05)

- Web: ya no existe ninguna pantalla del motor anterior. Las URLs viejas
  (`/rh/formatos`, `/rh/formatos/catalogo`, `/rh/plantillas`,
  `/rh/formatos-oficiales`, `…/generados`, `…/variables`, `…/nuevo`,
  `…/{formato}`) redirigen a Documentos maestros. Se borraron las páginas
  `Rh/Formatos`, `Rh/FormatosOficiales/*` (incluida la pestaña Variables) y
  `Rh/Plantillas`, sus diálogos de generación/subida y los métodos de
  controlador que las pintaban. Sin entrada de menú, recorrido ni ayuda.
- Expediente: se quitó el generador "Documentos oficiales". Lo que ya se
  había emitido con el motor anterior queda como **"Documentos anteriores"**
  de solo consulta (nunca se borra historial de un expediente).
- App: se borraron las pantallas "Formatos" y "Formatos oficiales" (rutas,
  API, hooks, tipos y utilidades); "Plantillas documentales" (consulta de
  los masters del motor actual) ahora se llama "Documentos maestros".
- Se conservan, sin pantalla, las descargas de lo ya generado y la subida
  del firmado (historial). **Excepción explícita:** la generación automática
  al aprobar **vacaciones** y **baja de personal**
  (`config/solicitudes.php → formatos`) sigue usando el formato oficial
  anterior, porque esos dos PDFs originales no están en este repositorio
  (`claude/formatos/originales/` está vacío) y no se puede construir su
  master sin inventar su diseño. En cuanto Jurídico/RH los suba en
  Documentos maestros se mueven al motor actual, igual que permiso y
  préstamo.
- Documentos generados cuyo archivo ya no existe en el almacenamiento (37
  registros sembrados en desarrollo): el ciclo ya no ofrece "Ver PDF"/"Word"
  (antes daba 404); muestra un aviso y la acción "Regenerar" (web y app,
  `archivo_disponible` en el payload).

## Usuarios — "Revocar acceso" (2026-10-05)

- Probado de punta a punta en el servidor local con una cuenta demo:
  revocar → la cuenta pasa a **Inactiva** (Activos 29 → 28, Inactivos
  6 → 7, se muestra "Inactiva · Activo": baja de cuenta, no laboral), se
  borran sus tokens Sanctum, la app responde 422 "Tu cuenta no está
  activa"; restablecer → vuelve a Activa y la app vuelve a entrar (200).
- **Hallazgo corregido:** el login web (`FortifyServiceProvider`) validaba
  el estatus laboral pero no `acceso_bloqueado_en`: con el acceso quitado
  la contraseña se aceptaba, se marcaba `ultimo_acceso` y solo después el
  middleware cerraba la sesión. Ahora se rechaza en el login mismo, igual
  que la API. Prueba nueva en `BajaSuspendeAccesoTest`.

## Resultado de la validación

- PHPStan nivel 7 (completo): 0 errores. Pint: OK. vue-tsc: OK. ESLint web: OK.
  Prettier: OK. `npm run build`: OK.
- Pruebas del motor documental (corridas por carpeta): flujo 15/15,
  primitivas 7/7, fidelidad del motor 22/22, cobertura y administración 9/9,
  **fidelidad real con Word** 6/6.
- App: typecheck OK, ESLint OK, Expo Doctor 21/21. Jest 508/508 tras borrar
  las pantallas del módulo anterior (se fueron sus 20 pruebas de utilidades).
- Tras retirar el módulo anterior: `tests/Feature/Rh` 143/143;
  `Solicitudes` + `DocumentosMaestros` + `CicloLaboral` 168/168 (incluye
  `BajaSuspendeAccesoTest` con la prueba nueva de login).
- **Suite PHP completa: NO concluyó.** La corrida se detuvo por falta de
  memoria del equipo (no por un fallo de pruebas); falta una corrida
  completa de `php artisan test`.

## Pendientes externos reales

- Instalar la fuente **Aptos** en el servidor (o que Jurídico entregue el
  permiso extraordinario en Century Gothic).
- Producción Linux: LibreOffice + `poppler-utils` + fuentes de los formatos
  (Century Gothic, Calibri, Arial, Times New Roman, Segoe UI Symbol), luego
  `people:importar-formatos-juridicos` para validar con LibreOffice; o
  servidor Windows con Office.
- Jurídico: contratos de periodo de prueba, tiempo determinado y carta
  responsiva; variante de evaluación "ACREDITA" si RH la requiere;
  confidencialidad de Gerente v2 limpia; confirmar observaciones de
  `docs/FORMATOS_JURIDICOS_OBSERVACIONES.md`.
- EAS/Apple para publicar la app.

---

# Ronda anterior — validación final 2026-10-02

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
