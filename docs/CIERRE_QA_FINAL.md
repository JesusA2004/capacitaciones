# Cierre QA — sesión 2026-10-01 (continuación)

Base revisada: `mrlanaPeople` rama `main` @ `3cbe0f0` (backend/web) ·
`mr-lana-people-app` rama principal @ `8870bd1` (móvil).

Esta sesión partió de un cierre del ciclo laboral ya implementado y
documentado (`docs/AUDITORIA_CICLO_LABORAL_FINAL.md`,
`docs/CICLO_LABORAL_FINAL_IMPLEMENTADO.md`). Se verificó contra el código
real (no solo la documentación) y se cerraron los gaps concretos
encontrados, priorizando cambios directos sobre auditoría repetida.

## Qué se verificó como correcto (lectura directa del código, no solo del doc)

- Separación preautorización/autorización: `SolicitudesService::cambiarEstado()`
  (`app/Services/Solicitudes/SolicitudesService.php:443`) bloquea
  explícitamente que quien dio el visto bueno también autorice.
- Cadena gerente → regional → RH en solicitudes
  (`AprobacionJerarquicaService`), exactamente como describe
  `config/solicitudes.php`.
- Ruta `Administración → Configuración → Jefes directos` registrada y
  completa (`routes/administracion.php`).
- `config/configuracion_sistema.php`: apariencia (13 colores), parámetros
  RH, 13 eventos de notificación con destinatarios/fallback — coincide con
  lo documentado.
- Dashboard operativo = solo `TableroRh` (sin rastro del dashboard viejo),
  con `pagina-ancha` en las pantallas nuevas revisadas.
- Wayfinder sin drift (`php artisan wayfinder:generate --with-form` no
  generó cambios).

## Bugs corregidos (web)

1. **Responsive roto en "Mi expediente"** — `ExpedienteDetalle.vue`: el
   contenedor de pestañas tenía `items-start` sin condicionar a `lg:`, lo
   que en móvil (`flex-col`) encogía toda la columna de contenido a su
   ancho mínimo en vez de ocupar el 100 % — se veía recortado en Avisos y
   en el resto de pestañas. Cambiado a `items-stretch` en móvil,
   `lg:items-start` solo en el layout de escritorio con sidebar.
   `resources/js/components/Rh/ExpedienteDetalle.vue`.
2. **Módulo Sucursales** — listado de tabla reemplazado por grid de cards
   (hover, clic directo abre la sucursal sin pasar por el menú "⋮", que
   queda solo para Editar/Eliminar); columna "Empresa" retirada y
   sustituida por agrupación por empresa (solo cuando hay más de una,
   ahorra espacio); filtro de empresa agregado al toolbar.
   `resources/js/pages/Administracion/Sucursales/Index.vue`.

## App móvil — cerrado esta sesión

Ver detalle completo en `mr-lana-people-app/docs/FINAL_MOBILE_AUDIT.md`,
sección "Cierre — reclutamiento, reingresos y tema (2026-10-01)":

- Reclutamiento móvil completo (`/rh/candidatos`, listado + ficha +
  workflow completo hasta autorización RH).
- Reingresos móvil completo (`/rh/reingresos`, buscar/historial/solicitar/
  decidir).
- Consumo del tema institucional (`GET /app/theme`) con respaldo total a la
  paleta estática — con la limitación arquitectónica documentada (no hay
  almacenamiento síncrono para repintar `StyleSheet.create` en vivo; sí
  repinta `expo-system-ui` y cualquier estilo en línea nuevo vía
  `useAppThemeColor`).
- Deep links/push: `Candidato`, `Reingreso` y `CierreLaboral` no tenían
  ningún caso en `appLinks.ts` (caían al respaldo genérico); se agregó
  resolución por `related_type` + los 8 eventos nuevos de
  `configuracion_sistema.php → eventos` a `RH_PUSH_TYPES`.

## Migraciones nuevas

Ninguna en esta sesión (el esquema de base de datos no cambió).

## Permisos nuevos

Ninguno nuevo: los endpoints de candidatos/reingresos consumidos desde
móvil ya usaban los permisos existentes (`candidatos.ver`, `candidatos.editar`,
`candidatos.evaluar`, `candidatos.rechazar`, `reingresos.gestionar`,
`reingresos.solicitar`, `ciclo.preautorizar`, `ciclo.autorizar_rh`).

## Endpoints

No se creó ningún endpoint nuevo: todos los que consume la app móvil nueva
(`/rh/candidatos/*`, `/rh/reingresos/*`, `/app/theme`) ya existían y están
probados en el backend.

## Pruebas ejecutadas

| Suite | Resultado |
|---|---|
| Backend `php -d xdebug.mode=off vendor/bin/pest` (completa) | **883 pruebas, 880 passed, 3 skipped, 0 failed** |
| `composer run types:check` (PHPStan nivel 7) | 0 errores |
| `composer run lint:check` (Pint) | OK |
| `npm run types:check` (vue-tsc) | OK |
| `npm run lint:check` (ESLint) | OK |
| `npm run build` | OK (20.0 s) |
| Móvil `npx tsc --noEmit` | 0 errores |
| Móvil `npx eslint .` | 0 errores |
| Móvil `npx jest` | **42/42 suites, 428/428 pruebas** (12 nuevas) |
| Móvil `npx expo-doctor` | 20/21 (solo versiones patch de Expo SDK desactualizadas, preexistente) |

La suite backend completa (883 pruebas) se corrió ANTES de los cambios de
esta sesión (que son solo frontend Vue + mobile — no tocan PHP) y quedó
verde; no se volvió a correr completa después porque ningún archivo PHP
cambió. Los checks de PHPStan/Pint/vue-tsc/ESLint/build sí se repitieron
sobre el estado final.

## Pendientes externos reales

Solamente cosas que dependen del usuario o de terceros:

- **Plantillas jurídicas reales** (contratos, avisos de no renovación,
  rescisión, finiquito, responsivas) y **material de inducción** — el
  sistema crea el pendiente "plantilla faltante" y nunca inventa texto
  legal; cargarlas es trabajo de RH/Jurídico, no de desarrollo.
- **App Links HTTPS** (deep links universales iOS/Android): requiere el
  SHA-256 real de la firma de EAS y el Apple Team ID — no se pueden generar
  sin las credenciales de la cuenta de desarrollador
  (`docs/APP_LINKS_SETUP.md` en el repo móvil).
- **QA en teléfono físico**: push en segundo plano/cold start, visor de PDF
  en Android, teclado edge-to-edge, cámara/galería, tema oscuro real,
  pantallas de 320 px — no reproducible desde este entorno.
- **Credenciales EAS de producción** para publicar una build nueva con
  estos cambios.

## Lo que NO quedó como pendiente (y por qué no se hizo en esta sesión)

- **Migrar "Lo que necesitas hacer" del colaborador al DTO unificado
  `GET /colaborador/mi-proceso`**: la pantalla actual (`(tabs)/index.tsx`)
  ya compone la misma información seguro (verificado: no muestra nombres de
  etapa interna, periodo de prueba, evaluación del jefe ni recomendaciones)
  a partir de endpoints más antiguos (`alta`, documentos pendientes,
  préstamos, recibos). Migrarla al endpoint unificado es una consolidación
  arquitectónica real pero de alcance grande (reescribir una pantalla
  madura de ~400 líneas con muchos hooks) — no es un bug de seguridad ni
  una funcionalidad rota, así que se documentó en vez de arriesgar una
  reescritura a medias.
- **Repintado en vivo de toda la app al cambiar el tema**: ver limitación
  arquitectónica explicada arriba (sin almacenamiento síncrono disponible).
  Agregar uno (MMKV) requiere una dependencia nativa nueva + rebuild de
  EAS — decisión de stack que le corresponde al usuario, no se tomó sola.
- **Video como evidencia del estudio socioeconómico en móvil**: el backend
  ya acepta `mp4/mov`, pero el componente compartido `DocumentUploadSheet`
  solo permite PDF/imagen. Ampliarlo es un cambio a un componente
  compartido usado en 5 pantallas más — se dejó fuera para no tocar flujos
  ya probados sin necesidad inmediata (las evidencias fotográficas/PDF ya
  funcionan).
