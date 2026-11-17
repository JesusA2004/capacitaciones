# Inventario de consumidores de la API

> Levantado el 2026-11-17 a partir del código; no es una prueba en vivo. Vuelve a levantarlo (ver «Cómo se levantó») antes de retirar o cambiar un endpoint.

## Para qué existe este documento

La API de People (`routes/api.php`) **no la usa solo la app móvil**. Hoy tiene tres familias de clientes:

| Cliente | Base que llama | Autenticación | Dónde vive su código |
|---|---|---|---|
| **App móvil actual** (Expo, `mr-lana-people-app`) | `EXPO_PUBLIC_API_URL` = `…/api/v1` | Sanctum (Bearer de usuario) | `C:\wamp64\www\mr-lana-people-app\src\api\**` (+ `src/hooks/queries/useRhCicloLaboral.ts`, `src/components/VisorPdfPaginado.tsx`) |
| **Auditoría** (backend Laravel) | `PEOPLE_API_URL` = `…/api/v1`, rutas `auditoria/*` | Token de integración servidor a servidor, habilidad `auditoria:leer` | `C:\wamp64\www\auditoria\backend\app\Support\People\*` |
| **Reportes** (Laravel) | Diseñada para `…/api/integracion/v1` | Token de integración (`ppl_int_…`) | `C:\wamp64\www\Reportes` — **hoy no tiene ningún cliente de People en su código** |

Cualquier cambio a un endpoint debe preservar **el contrato exacto**: método y ruta, parámetros de entrada (nombres y defaults), nombres y tipos de los campos de salida, **forma de la paginación** (`meta` armado a mano vs. paginador de Laravel serializado en la raíz vs. cursor) y códigos HTTP (401/403/404/410/422/423 con sus `codigo`).

**«Sin consumidor conocido» no significa «seguro de borrar».** Significa que no encontramos la llamada en el código *actual* de esos tres repositorios. En la calle hay APKs/apps ya instaladas de versiones anteriores que pueden seguir llamando endpoints que la app actual ya no llama (por eso existen, por ejemplo, `vacaciones/solicitudes` legacy y los `rh/formatos*` que responden 410). Antes de retirar algo revisa `docs/APP_RELEASES.md` y la bitácora/log de accesos de producción.

## Cómo se levantó

1. `php artisan route:list --path=api --json` → 401 rutas; se descartan 3 que no son API (`mi-proceso/induccion/capitulos/*`, web, coinciden solo porque «capitulos» contiene «api»). Quedan **398**: 10 en `api/integracion/v1` y 388 en `api/v1`.
2. **App**: se extrajo cada literal de ruta (`'/…'` o `` `/…${id}` ``) de `src/api/**`, `src/hooks/queries/useRhCicloLaboral.ts` y `src/components/VisorPdfPaginado.tsx` (todas las llamadas pasan por `apiClient`; no hay rutas armadas con `+` ni constantes base) y se cruzó segmento por segmento con las rutas, respetando el método (`apiClient.get/post/…`). Las funciones `…Path()` que solo devuelven una ruta (visor/descarga con `File.createDownloadTask` o `<Image>`) se cuentan como `GET` y se marcan «ruta de descarga/visor».
   - Además se revisó si la función del cliente la usa alguna pantalla (directo o vía hook de `src/hooks/queries`). Las que no, se marcan **⚠ cliente sin uso en pantallas**: el código del cliente existe pero ninguna pantalla actual lo dispara (puede que una versión futura o anterior sí).
   - **Consumo indirecto**: el backend arma URLs absolutas con `route('api.v1.…')` dentro de sus respuestas (`foto_url`, `pdf.url`, `permiso_pdf_url`…) y la app las abre tal cual. Esas rutas aparecen como «App (indirecto)» cuando no hay literal en la app. **Renombrar la ruta o su nombre cambia esas URLs.**
3. **Auditoría** y **Reportes**: búsqueda de `integracion/v1`, `api/v1`, `auditoria/`, `PEOPLE_*`, clientes HTTP (`Http::`) excluyendo `vendor/` y `node_modules/`.
4. **Notas de contrato**: forma de paginación detectada en el método del controlador (o en el Service que llama, un nivel) y parámetros que lee. Donde la celda está vacía no se inspeccionó a fondo (la mayoría de `show`/acciones devuelven `{data: …}` o `{message, data}`); revísalo en el controlador antes de cambiarlo.

## Los dos listados que más se preguntan: `GET rh/expedientes` y `GET rh/incorporaciones`

### `GET /api/v1/rh/expedientes` — `Api\V1\Rh\ExpedienteController@index`

- **Backend** (desde 2026-11-17): no usa `paginate()` (el `meta` se arma a mano). Sin `estado`, cuenta y pagina **en la base** (`orderBy name, id`, `LIMIT 20`) y calcula el estado de incorporación solo de las 20 filas de la página. Con `estado`, como el estado se calcula (no es columna), carga todo el alcance (`queryFiltrada`: `busqueda`, `empresa_id`, `sucursal_id`, `departamento_id`), filtra en memoria y corta con `forPage($page, 20)`. La respuesta es idéntica en ambos casos (prueba «la lista de expedientes de la api pagina en la base con el mismo meta y orden»).
- **Respuesta**:
  ```json
  {
    "data": [{ "id": 1, "nombre": "…", "correo": "…", "numero_empleado": "…", "empresa": "…", "sucursal": "…",
               "departamento": "…", "estatus_usuario": "activo", "estado_incorporacion": "completo" }],
    "meta": { "current_page": 1, "per_page": 20, "total": 57, "last_page": 3 }
  }
  ```
  Sin `links`. `per_page` **no se acepta** (fijo `POR_PAGINA = 20`).
- **App actual**: tiene cliente `rhExpedientesApi.list(params)` (`src/api/rh/expedientes.ts:22-25`) con `RhExpedientesParams = { estado, empresa_id, sucursal_id, departamento_id, busqueda, page }` (no manda `per_page`) que devuelve `response.data` sin normalizar, y el hook `useRhExpedientes` (`src/hooks/queries/useRhExpedientes.ts:7`). **Ninguna pantalla llama ese hook** (verificado también en el historial git de la app: nunca existió una pantalla de lista). Por lo tanto la app actual **no lee** `data[]` ni `meta` de este listado. La app entra a expedientes por la ficha del colaborador (`app/(app)/rh/colaboradores/[id].tsx:110`), por la incorporación (`app/(app)/rh/incorporaciones/[colaborador].tsx:146`) y por tareas (`utils/taskRoutes.ts:63`), siempre al **detalle** `GET rh/expedientes/{colaborador}`.
- Ojo: el tipo de la app para ese cliente (`PaginatedResponse<RhExpediente>`, `src/types/rh.ts:298`) describe la forma del *show* (`colaborador`, `estado`, `documentos[]`), **no** las filas planas del index. Si alguien cablea una pantalla de lista, tendrá que corregir ese tipo.

### `GET /api/v1/rh/incorporaciones` — `Api\V1\Rh\IncorporacionController@index`

- **Backend**: tampoco usa `paginate()`. Colaboradores `en_incorporacion` o con `incorporacion_decision`, dentro del alcance (`limitarColaboradoresPorAlcance`), búsqueda `q`, orden `created_at desc`; filtra `estado` en memoria y corta con `forPage($page, $per_page)` (`per_page` por defecto 15, sin tope).
- **Respuesta**:
  ```json
  {
    "data": [{ "id": "incorporacion:12", "resource_id": 12, "estado": "en_revision",
               "colaborador": { "…resumen…": "…" }, "creado_en": "2026-11-01T10:00:00-06:00",
               "acciones_permitidas": ["…"] }],
    "meta": { "current_page": 1, "per_page": 15, "total": 4 }
  }
  ```
  **Sin `last_page`** ni `links` (distinto de `rh/expedientes`).
- **App actual**: cliente `rhIncorporacionesApi.list(params)` (`src/api/rh/incorporaciones.ts:16-19`) con `RhIncorporacionesParams = { estado, q, page, per_page }`; normaliza con `normalizeRhPagina` (`src/api/normalizers/rhFotos.ts:40`), que mapea cada `data[]` con `normalizeRhIncorporacion` y **conserva `meta` tal cual** (tipo `PaginationMeta`: `current_page`, `last_page?`, `per_page`, `total`). Hook `useRhIncorporaciones` (`src/hooks/queries/useRhIncorporaciones.ts:7`). **Ninguna pantalla llama ese hook.** La app llega a las incorporaciones desde la bandeja `GET rh/pendientes` (`utils/rhRoutes.ts:9`, `utils/appLinks.ts:62`) y abre el detalle `GET rh/incorporaciones/{colaborador}` (`app/(app)/rh/incorporaciones/[colaborador].tsx`).

**Conclusión práctica**: hoy la app actual no depende de la forma de paginación de ninguno de los dos listados; sí depende de los **detalles** (`…/{colaborador}`) y de sus acciones (aprobar/rechazar, documentos). Como es API pública de la app, igual se trata como contrato: una APK anterior o una pantalla futura podría usar los clientes que ya existen.

## Tablas por grupo

Columnas: **Ruta** relativa a `/api/`. **Consumidores**: archivo:línea de la llamada (rutas de la app relativas a `mr-lana-people-app/`, de Auditoría a `auditoria/backend/`, del backend de People a `capacitaciones/`). Middleware común de `api/v1` (salvo que diga «Público»): `auth:sanctum`, `throttle:api`, `ExigirCambioContrasena`, `ExigirRegistroApp`, `ExigirDocumentosPendientes`, `ExigirExpedienteCompleto`.

### `api/integracion/v1`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `integracion/v1/capacidades` | `api.integracion.v1.capacidades`<br>CatalogoController@capacidades | «sin consumidor conocido» (pensada para Reportes; su código aún no la llama) | Token de integración, habilidad `*` |
| GET | `integracion/v1/colaboradores` | `api.integracion.v1.colaboradores.index`<br>ColaboradorController@index | «sin consumidor conocido» (pensada para Reportes; su código aún no la llama) | Token de integración, habilidad `colaboradores:leer` |
| GET | `integracion/v1/colaboradores/{colaborador}` | `api.integracion.v1.colaboradores.show`<br>ColaboradorController@show | «sin consumidor conocido» (pensada para Reportes; su código aún no la llama) | Token de integración, habilidad `colaboradores:leer` |
| GET | `integracion/v1/departamentos` | `api.integracion.v1.departamentos.index`<br>CatalogoController@departamentos | «sin consumidor conocido» (pensada para Reportes; su código aún no la llama) | Token de integración, habilidad `catalogos:leer` |
| GET | `integracion/v1/empresas` | `api.integracion.v1.empresas.index`<br>CatalogoController@empresas | «sin consumidor conocido» (pensada para Reportes; su código aún no la llama) | Token de integración, habilidad `catalogos:leer` |
| GET | `integracion/v1/movimientos/altas` | `api.integracion.v1.movimientos.altas`<br>MovimientoController@altas | «sin consumidor conocido» (pensada para Reportes; su código aún no la llama) | Token de integración, habilidad `movimientos:leer` |
| GET | `integracion/v1/movimientos/bajas` | `api.integracion.v1.movimientos.bajas`<br>MovimientoController@bajas | «sin consumidor conocido» (pensada para Reportes; su código aún no la llama) | Token de integración, habilidad `movimientos:leer` |
| GET | `integracion/v1/puestos` | `api.integracion.v1.puestos.index`<br>CatalogoController@puestos | «sin consumidor conocido» (pensada para Reportes; su código aún no la llama) | Token de integración, habilidad `catalogos:leer` |
| GET | `integracion/v1/rotacion` | `api.integracion.v1.rotacion`<br>RotacionController | «sin consumidor conocido» (pensada para Reportes; su código aún no la llama) | Token de integración, habilidad `rotacion:leer` |
| GET | `integracion/v1/sucursales` | `api.integracion.v1.sucursales.index`<br>CatalogoController@sucursales | «sin consumidor conocido» (pensada para Reportes; su código aún no la llama) | Token de integración, habilidad `catalogos:leer` |

### `api/v1/agenda`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/agenda` | `api.v1.agenda.index`<br>AgendaController@index | App: `src/api/agenda.ts:14` | meta custom |
| POST | `v1/agenda` | `api.v1.agenda.store`<br>AgendaController@store | App: `src/api/agenda.ts:31`, `src/api/agenda.ts:45` |  |
| GET | `v1/agenda/opciones` | `api.v1.agenda.opciones`<br>AgendaController@opciones | App: `src/api/agenda.ts:19` |  |
| GET | `v1/agenda/{evento}` | `api.v1.agenda.show`<br>AgendaController@show | App: `src/api/agenda.ts:24` |  |
| POST | `v1/agenda/{evento}/cancelar` | `api.v1.agenda.cancelar`<br>AgendaController@cancelar | App: `src/api/agenda.ts:56` |  |
| GET | `v1/agenda/{evento}/cv` | `api.v1.agenda.cv`<br>AgendaController@cv | App: `src/api/agenda.ts:80` (ruta de descarga/visor) |  |
| POST | `v1/agenda/{evento}/no-asistio` | `api.v1.agenda.no-asistio`<br>AgendaController@noAsistio | App: `src/api/agenda.ts:61` |  |
| POST | `v1/agenda/{evento}/reprogramar` | `api.v1.agenda.reprogramar`<br>AgendaController@reprogramar | App: `src/api/agenda.ts:51` |  |
| POST | `v1/agenda/{evento}/resultado-entrevista` | `api.v1.agenda.resultado-entrevista`<br>AgendaController@resultadoEntrevista | App: `src/api/agenda.ts:66` |  |
| POST | `v1/agenda/{evento}/resultado-socioeconomico` | `api.v1.agenda.resultado-socioeconomico`<br>AgendaController@resultadoSocioeconomico | App: `src/api/agenda.ts:75` |  |

### `api/v1/app`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/app/config` | `api.v1.app.config`<br>AppConfigController | App: `src/api/appConfig.ts:8` | Público (sin Sanctum) |
| GET | `v1/app/releases` | `api.v1.app.releases.index`<br>AppReleaseController@index | App: `src/api/appReleases.ts:24` ⚠ cliente sin uso en pantallas | Público (sin Sanctum) |
| GET | `v1/app/releases/latest` | `api.v1.app.releases.latest`<br>AppReleaseController@latest | App: `src/api/appReleases.ts:15` | Público (sin Sanctum) |
| GET | `v1/app/theme` | `api.v1.app.theme`<br>AppThemeController | App: `src/api/theme.ts:9` | Público (sin Sanctum) |

### `api/v1/auditoria`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/auditoria/catalogo` | `api.v1.auditoria.catalogo`<br>Integraciones\AuditoriaController@catalogo | Auditoría: `app/Support/People/SincronizadorPeople.php:290` | Token de integración, habilidad `auditoria:leer` |
| GET | `v1/auditoria/colaboradores` | `api.v1.auditoria.colaboradores.index`<br>Integraciones\AuditoriaController@colaboradores | Auditoría: `app/Support/People/SincronizadorPeople.php:211` (sincronización `people:sincronizar`) | Paginación por **cursor**: Auditoría manda `empresa_id`, `updated_since`, `contrato=3`, `cursor`, `per_page` (≤200, usa 100) y exige `data` como lista y `meta` como objeto; avanza con `meta.next_cursor`. Contrato `people-auditoria/1..3` — ver `docs/integraciones/AUDITORIA.md`. Token de integración, habilidad `auditoria:leer` |
| GET | `v1/auditoria/colaboradores/{colaborador}` | `api.v1.auditoria.colaboradores.show`<br>Integraciones\AuditoriaController@colaborador | «sin consumidor conocido» | Token de integración, habilidad `auditoria:leer` |
| GET | `v1/auditoria/colaboradores/{colaborador}/acta` | `api.v1.auditoria.colaboradores.acta`<br>Integraciones\AuditoriaController@colaboradorActa | Auditoría: `app/Support/People/ContextoActaPeople.php:64-65, 89-90` | Auditoría manda `contrato=3`. Token de integración, habilidad `auditoria:leer` |
| GET | `v1/auditoria/departamentos` | `api.v1.auditoria.departamentos.index`<br>Integraciones\AuditoriaController@departamentos | Auditoría: `app/Support/People/SincronizadorPeople.php:357-358` | Token de integración, habilidad `auditoria:leer` |
| GET | `v1/auditoria/puestos` | `api.v1.auditoria.puestos.index`<br>Integraciones\AuditoriaController@puestos | Auditoría: `app/Support/People/SincronizadorPeople.php:357-358` | Token de integración, habilidad `auditoria:leer` |
| GET | `v1/auditoria/sucursales` | `api.v1.auditoria.sucursales.index`<br>Integraciones\AuditoriaController@sucursales | Auditoría: `app/Support/People/SincronizadorPeople.php:323` | Auditoría manda `empresa_id`. Token de integración, habilidad `auditoria:leer` |
| GET | `v1/auditoria/sucursales/{sucursal}/acta` | `api.v1.auditoria.sucursales.acta`<br>Integraciones\AuditoriaController@sucursalActa | Auditoría: `app/Support/People/ContextoActaPeople.php:64-65, 89-90` | Token de integración, habilidad `auditoria:leer` |

### `api/v1/avisos`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/avisos` | `api.v1.avisos.index`<br>AvisoController@index | App: `src/api/avisos.ts:14` ⚠ cliente sin uso en pantallas | meta custom. Params: `per_page` |
| GET | `v1/avisos/{aviso}` | `api.v1.avisos.show`<br>AvisoController@show | App: `src/api/avisos.ts:20` |  |
| GET | `v1/avisos/{aviso}/archivo` | `api.v1.avisos.archivo`<br>AvisoController@archivo | App: `src/api/avisos.ts:26` (ruta de descarga/visor) |  |
| GET | `v1/avisos/{aviso}/imagen` | `api.v1.avisos.imagen`<br>AvisoController@imagen | «sin consumidor conocido» |  |
| POST | `v1/avisos/{aviso}/leido` | `api.v1.avisos.leido`<br>AvisoController@marcarLeido | App: `src/api/avisos.ts:31` |  |

### `api/v1/broadcasting`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| POST | `v1/broadcasting/auth` | `api.v1.broadcasting.auth`<br>Closure | «sin consumidor conocido» |  |

### `api/v1/cambiar-contrasena`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| POST | `v1/cambiar-contrasena` | `api.v1.cambiar-contrasena`<br>AuthController@cambiarContrasena | App: `src/api/auth.ts:53` |  |

### `api/v1/celebraciones`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/celebraciones/activas` | `api.v1.celebraciones.activas`<br>CelebracionController@activas | App: `src/api/celebraciones.ts:17` |  |
| GET | `v1/celebraciones/{celebracion}` | `api.v1.celebraciones.show`<br>CelebracionController@show | App: `src/api/celebraciones.ts:22` |  |
| GET | `v1/celebraciones/{celebracion}/foto` | `api.v1.celebraciones.foto`<br>CelebracionController@foto | App: `src/api/celebraciones.ts:33` (ruta de descarga/visor) |  |
| GET | `v1/celebraciones/{celebracion}/mensajes` | `api.v1.celebraciones.mensajes.index`<br>CelebracionController@mensajes | App: `src/api/celebraciones.ts:37` | paginate(per_page, def. 30, máx. 50)). Params: `per_page` |
| POST | `v1/celebraciones/{celebracion}/mensajes` | `api.v1.celebraciones.mensajes.store`<br>CelebracionController@store | App: `src/api/celebraciones.ts:47` |  |
| PATCH | `v1/celebraciones/{celebracion}/mensajes/{mensaje}` | `api.v1.celebraciones.mensajes.update`<br>CelebracionController@update | App: `src/api/celebraciones.ts:53` ⚠ cliente sin uso en pantallas |  |
| DELETE | `v1/celebraciones/{celebracion}/mensajes/{mensaje}` | `api.v1.celebraciones.mensajes.destroy`<br>CelebracionController@destroy | App: `src/api/celebraciones.ts:59` ⚠ cliente sin uso en pantallas |  |
| GET | `v1/celebraciones/{celebracion}/mensajes/{mensaje}/autor-foto` | `api.v1.celebraciones.autor-foto`<br>CelebracionController@autorFoto | «sin consumidor conocido» |  |
| GET | `v1/celebraciones/{celebracion}/mensajes/{mensaje}/foto` | `api.v1.celebraciones.mensajes.foto`<br>CelebracionController@mensajeFoto | «sin consumidor conocido» |  |
| GET | `v1/celebraciones/{celebracion}/tarjeta` | `api.v1.celebraciones.tarjeta`<br>CelebracionController@tarjeta | App: `src/api/celebraciones.ts:28` (ruta de descarga/visor) |  |

### `api/v1/colaborador`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/colaborador/alta` | `api.v1.colaborador.alta`<br>CicloLaboralColaboradorController@alta | App: `src/api/cicloLaboral.ts:114` ⚠ cliente sin uso en pantallas |  |
| GET | `v1/colaborador/carta-compromiso` | `api.v1.colaborador.carta-compromiso.show`<br>CartaCompromisoController@show | App: `src/api/cartaCompromiso.ts:15` |  |
| GET | `v1/colaborador/carta-compromiso/pdf` | `api.v1.colaborador.carta-compromiso.pdf`<br>CartaCompromisoController@pdf | App: `src/api/cartaCompromiso.ts:21` (ruta de descarga/visor) |  |
| POST | `v1/colaborador/carta-compromiso/solicitar` | `api.v1.colaborador.carta-compromiso.solicitar`<br>CartaCompromisoController@solicitar | «sin consumidor conocido» |  |
| POST | `v1/colaborador/carta-compromiso/vista-previa` | `api.v1.colaborador.carta-compromiso.vista-previa`<br>CartaCompromisoController@vistaPrevia | App: `src/api/cartaCompromiso.ts:32` |  |
| GET | `v1/colaborador/contratos` | `api.v1.colaborador.contratos`<br>CicloLaboralColaboradorController@contratos | App: `src/api/cicloLaboral.ts:138` |  |
| GET | `v1/colaborador/cumpleanos/felicitacion-actual` | `api.v1.colaborador.cumpleanos.felicitacion-actual`<br>ColaboradorCumpleanosController@felicitacionActual | App: `src/api/cumpleanos.ts:15` |  |
| GET | `v1/colaborador/cumpleanos/felicitacion-actual/imagen` | `api.v1.colaborador.cumpleanos.felicitacion-actual.imagen`<br>ColaboradorCumpleanosController@imagen | App: `src/api/cumpleanos.ts:25` (ruta de descarga/visor) |  |
| GET | `v1/colaborador/dashboard` | `api.v1.colaborador.dashboard`<br>ColaboradorController@dashboard | App: `src/api/colaborador.ts:25` |  |
| GET | `v1/colaborador/datos-faltantes` | `api.v1.colaborador.datos-faltantes`<br>CicloLaboralColaboradorController@datosFaltantes | App: `src/api/cicloLaboral.ts:119` |  |
| GET | `v1/colaborador/descriptivo-puesto` | `api.v1.colaborador.descriptivo-puesto.show`<br>DescriptivoPuestoColaboradorController@show | App: `src/api/descriptivoPuesto.ts:13` |  |
| POST | `v1/colaborador/descriptivo-puesto/{acuse}/acusar` | `api.v1.colaborador.descriptivo-puesto.acusar`<br>DescriptivoPuestoColaboradorController@acusar | App: `src/api/descriptivoPuesto.ts:23` |  |
| POST | `v1/colaborador/descriptivo-puesto/{acuse}/leido` | `api.v1.colaborador.descriptivo-puesto.leido`<br>DescriptivoPuestoColaboradorController@leido | App: `src/api/descriptivoPuesto.ts:18` |  |
| GET | `v1/colaborador/documentos-laborales` | `api.v1.colaborador.documentos-laborales.index`<br>CicloLaboralColaboradorController@documentosLaborales | App: `src/api/documentosLaborales.ts:26` | paginate() en DocumentoLaboralConsultaService::delColaborador; el controlador arma la respuesta. Params: `estado` |
| GET | `v1/colaborador/documentos-laborales/{documento}` | `api.v1.colaborador.documentos-laborales.show`<br>CicloLaboralColaboradorController@documentoLaboral | App: `src/api/documentosLaborales.ts:31` |  |
| POST | `v1/colaborador/documentos-laborales/{documento}/apertura` | `api.v1.colaborador.documentos-laborales.apertura`<br>CicloLaboralColaboradorController@aperturaDocumento | App: `src/api/documentosLaborales.ts:47` |  |
| GET | `v1/colaborador/documentos-laborales/{documento}/descargar` | `api.v1.colaborador.documentos-laborales.descargar`<br>CicloLaboralColaboradorController@descargarDocumento | App: `src/api/documentosLaborales.ts:37` (ruta de descarga/visor) |  |
| POST | `v1/colaborador/documentos-laborales/{documento}/firmar` | `api.v1.colaborador.documentos-laborales.firmar`<br>CicloLaboralColaboradorController@firmarDocumento | App: `src/api/documentosLaborales.ts:56` |  |
| GET | `v1/colaborador/documentos-laborales/{documento}/ver` | `api.v1.colaborador.documentos-laborales.ver`<br>CicloLaboralColaboradorController@verDocumento | App: `src/api/documentosLaborales.ts:42` (ruta de descarga/visor) |  |
| GET | `v1/colaborador/documentos-pendientes` | `api.v1.colaborador.documentos-pendientes`<br>CicloLaboralColaboradorController@documentosPendientes | App: `src/api/cicloLaboral.ts:129` ⚠ cliente sin uso en pantallas | paginate() en DocumentoLaboralConsultaService::delColaborador; el controlador arma la respuesta |
| GET | `v1/colaborador/expediente` | `api.v1.colaborador.expediente`<br>CicloLaboralColaboradorController@expediente | App: `src/api/cicloLaboral.ts:124` |  |
| GET | `v1/colaborador/foto` | `api.v1.colaborador.foto`<br>ColaboradorController@foto | App (indirecto): abre la URL que arma el backend en `app/Services/Colaboradores/FotoColaboradorService.php:369`, `app/Services/Mobile/MobileBootstrapService.php:127` |  |
| POST | `v1/colaborador/foto` | `api.v1.colaborador.foto.store`<br>ColaboradorController@subirFoto | App: `src/api/colaborador.ts:62` |  |
| GET | `v1/colaborador/foto/estado` | `api.v1.colaborador.foto.estado`<br>ColaboradorController@estadoFoto | App: `src/api/colaborador.ts:51` ⚠ cliente sin uso en pantallas |  |
| GET | `v1/colaborador/foto/propuesta` | `api.v1.colaborador.foto.propuesta`<br>ColaboradorController@fotoPropuesta | App (indirecto): abre la URL que arma el backend en `app/Services/Colaboradores/FotoColaboradorService.php:260` |  |
| GET | `v1/colaborador/guia` | `api.v1.colaborador.guia`<br>GuiaColaboradorController@show | App: `src/api/guia.ts:9` |  |
| GET | `v1/colaborador/incorporacion` | `api.v1.colaborador.incorporacion.index`<br>IncorporacionController@index | App: `src/api/incorporacion.ts:15` |  |
| POST | `v1/colaborador/incorporacion/documentos/{documentoRequerido}/subir` | `api.v1.colaborador.incorporacion.documentos.subir`<br>IncorporacionController@subirDocumento | App: `src/api/documentos.ts:47` |  |
| POST | `v1/colaborador/incorporacion/documentos/{documento}/solicitar-cambio` | `api.v1.colaborador.incorporacion.documentos.solicitar-cambio`<br>IncorporacionController@solicitarCambio | App: `src/api/documentos.ts:64` |  |
| GET | `v1/colaborador/incorporacion/resumen` | `api.v1.colaborador.incorporacion.resumen`<br>IncorporacionController@resumen | «sin consumidor conocido» | Alias de `GET colaborador/incorporacion` (la app usa el principal). |
| GET | `v1/colaborador/induccion` | `api.v1.colaborador.induccion.contenido`<br>MiProcesoController@contenidoInduccion | App: `src/api/induccion.ts:66` |  |
| POST | `v1/colaborador/induccion/audio/{avance}/progreso` | `api.v1.colaborador.induccion.audio-progreso`<br>MiProcesoController@audioProgresoInduccion | «sin consumidor conocido» |  |
| GET | `v1/colaborador/induccion/capitulos/{avance}` | `api.v1.colaborador.induccion.capitulos`<br>MiProcesoController@capitulosInduccion | «sin consumidor conocido» |  |
| POST | `v1/colaborador/induccion/capitulos/{avance}/pagina` | `api.v1.colaborador.induccion.pagina`<br>MiProcesoController@registrarPaginaInduccion | App: `src/api/induccion.ts:53` |  |
| POST | `v1/colaborador/induccion/capitulos/{avance}/progreso` | `api.v1.colaborador.induccion.progreso`<br>MiProcesoController@registrarProgresoInduccion | «sin consumidor conocido» |  |
| POST | `v1/colaborador/induccion/capitulos/{avance}/terminar` | `api.v1.colaborador.induccion.terminar`<br>MiProcesoController@terminarInduccion | App: `src/api/induccion.ts:59` |  |
| GET | `v1/colaborador/induccion/material/{avance}` | `api.v1.colaborador.induccion.material`<br>MiProcesoController@materialInduccion | App: `src/api/induccion.ts:32` |  |
| GET | `v1/colaborador/induccion/organigrama/{vista}` | `api.v1.colaborador.induccion.organigrama`<br>MiProcesoController@organigramaInduccion | «sin consumidor conocido» |  |
| POST | `v1/colaborador/induccion/video/{avance}/heartbeat` | `api.v1.colaborador.induccion.video.heartbeat`<br>MiProcesoController@videoHeartbeatInduccion | App: `src/api/induccion.ts:47` |  |
| POST | `v1/colaborador/induccion/video/{avance}/iniciar` | `api.v1.colaborador.induccion.video.iniciar`<br>MiProcesoController@videoIniciarInduccion | App: `src/api/induccion.ts:41` |  |
| POST | `v1/colaborador/induccion/{evaluacion}/presentar` | `api.v1.colaborador.induccion.presentar`<br>MiProcesoController@presentarInduccion | App: `src/api/induccion.ts:72` |  |
| GET | `v1/colaborador/jerarquia` | `api.v1.colaborador.jerarquia`<br>CicloLaboralColaboradorController@jerarquia | App: `src/api/cicloLaboral.ts:143` |  |
| GET | `v1/colaborador/mi-proceso` | `api.v1.colaborador.mi-proceso`<br>MiProcesoController@show | App: `src/api/cicloLaboral.ts:153` |  |
| GET | `v1/colaborador/notificaciones` | `api.v1.colaborador.notificaciones`<br>ColaboradorController@notificaciones | App: `src/api/colaborador.ts:45` ⚠ cliente sin uso en pantallas |  |
| POST | `v1/colaborador/onboarding/avances/{avance}/evaluacion` | `api.v1.colaborador.onboarding.evaluacion`<br>MiProcesoController@evaluacion | App: `src/api/cicloLaboral.ts:159` |  |
| POST | `v1/colaborador/onboarding/avances/{avance}/visto` | `api.v1.colaborador.onboarding.visto`<br>MiProcesoController@marcarContenidoVisto | «sin consumidor conocido» |  |
| GET | `v1/colaborador/perfil` | `api.v1.colaborador.perfil`<br>ColaboradorController@perfil | App: `src/api/colaborador.ts:20` |  |
| GET | `v1/colaborador/prestamos` | `api.v1.colaborador.prestamos.index`<br>CicloLaboralColaboradorController@prestamos | App: `src/api/cicloLaboral.ts:185` |  |
| GET | `v1/colaborador/prestamos/{prestamo}` | `api.v1.colaborador.prestamos.show`<br>CicloLaboralColaboradorController@prestamo | App: `src/api/cicloLaboral.ts:190` |  |
| GET | `v1/colaborador/recibos` | `api.v1.colaborador.recibos.index`<br>CicloLaboralColaboradorController@recibos | App: `src/api/cicloLaboral.ts:167` | paginate() en ReciboNominaService::delColaborador; el controlador arma la respuesta. Params: `per_page` |
| GET | `v1/colaborador/recibos/{recibo}` | `api.v1.colaborador.recibos.show`<br>CicloLaboralColaboradorController@recibo | App: `src/api/cicloLaboral.ts:172` |  |
| GET | `v1/colaborador/recibos/{recibo}/pdf` | `api.v1.colaborador.recibos.pdf`<br>CicloLaboralColaboradorController@reciboPdf | App: `src/api/cicloLaboral.ts:178` (ruta de descarga/visor) |  |
| GET | `v1/colaborador/registro` | `api.v1.colaborador.registro.show`<br>RegistroAppController@show | App: `src/api/registro.ts:23` |  |
| POST | `v1/colaborador/registro/datos` | `api.v1.colaborador.registro.datos`<br>RegistroAppController@datos | App: `src/api/registro.ts:29` |  |
| POST | `v1/colaborador/registro/finalizar` | `api.v1.colaborador.registro.finalizar`<br>RegistroAppController@finalizar | App: `src/api/registro.ts:46` |  |
| GET | `v1/colaborador/registro/solicitud` | `api.v1.colaborador.registro.solicitud.show`<br>SolicitudEmpleoRegistroController@show | App: `src/api/solicitudEmpleo.ts:15` |  |
| POST | `v1/colaborador/registro/solicitud/{seccion}` | `api.v1.colaborador.registro.solicitud.guardar`<br>SolicitudEmpleoRegistroController@guardar | App: `src/api/solicitudEmpleo.ts:21` |  |
| GET | `v1/colaborador/solicitudes` | `api.v1.colaborador.solicitudes.index`<br>ColaboradorController@solicitudes | App: `src/api/colaborador.ts:35` ⚠ cliente sin uso en pantallas | meta custom |
| POST | `v1/colaborador/solicitudes` | `api.v1.colaborador.solicitudes.store`<br>ColaboradorController@storeSolicitud | App: `src/api/colaborador.ts:40` ⚠ cliente sin uso en pantallas |  |
| GET | `v1/colaborador/vacaciones` | `api.v1.colaborador.vacaciones`<br>ColaboradorController@vacaciones | App: `src/api/colaborador.ts:30` ⚠ cliente sin uso en pantallas |  |

### `api/v1/colaboradores`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/colaboradores/{colaborador}/foto` | `api.v1.colaboradores.foto`<br>FotoColaboradorController@show | App (indirecto): abre la URL que arma el backend en `app/Services/Colaboradores/FotoColaboradorService.php:393` |  |

### `api/v1/correcciones`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/correcciones` | `api.v1.correcciones.index`<br>CorreccionController@index | App: `src/api/correcciones.ts:14` | meta custom |
| GET | `v1/correcciones/resumen` | `api.v1.correcciones.resumen`<br>CorreccionController@resumen | App: `src/api/correcciones.ts:20` ⚠ cliente sin uso en pantallas |  |

### `api/v1/cumpleanos`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/cumpleanos/muros` | `api.v1.cumpleanos.muros.index`<br>MuroCumpleanosController@index | App: `src/api/birthdayWall.ts:13` |  |
| GET | `v1/cumpleanos/muros/{greeting}` | `api.v1.cumpleanos.muros.show`<br>MuroCumpleanosController@show | App: `src/api/birthdayWall.ts:18` |  |
| GET | `v1/cumpleanos/muros/{greeting}/foto` | `api.v1.cumpleanos.muros.foto-cumpleanero`<br>MuroCumpleanosController@fotoCumpleanero | App (indirecto): abre la URL que arma el backend en `app/Services/Cumpleanos/MuroCumpleanosService.php:286` |  |
| GET | `v1/cumpleanos/muros/{greeting}/mensajes` | `api.v1.cumpleanos.muros.mensajes.index`<br>MuroCumpleanosController@mensajes | App: `src/api/birthdayWall.ts:23` | paginate(per_page, def. 20, máx. 50)). Params: `per_page` |
| POST | `v1/cumpleanos/muros/{greeting}/mensajes` | `api.v1.cumpleanos.muros.mensajes.store`<br>MuroCumpleanosController@publicar | App: `src/api/birthdayWall.ts:32` |  |
| DELETE | `v1/cumpleanos/muros/{greeting}/mensajes/{mensaje}` | `api.v1.cumpleanos.muros.mensajes.destroy`<br>MuroCumpleanosController@eliminar | App: `src/api/birthdayWall.ts:37` |  |
| GET | `v1/cumpleanos/muros/{greeting}/mensajes/{mensaje}/foto` | `api.v1.cumpleanos.muros.mensajes.foto`<br>MuroCumpleanosController@foto | App (indirecto): abre la URL que arma el backend en `app/Services/Cumpleanos/MuroCumpleanosService.php:304` |  |

### `api/v1/directorio`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/directorio` | `api.v1.directorio.index`<br>DirectorioController@index | App: `src/api/directorio.ts:33` | meta custom. Params: `per_page`, `busqueda`, `puesto_id`, `sucursal_id`, `departamento_id`, `solo_con_correo`, `solo_por_completar` |
| GET | `v1/directorio/opciones` | `api.v1.directorio.opciones`<br>DirectorioController@opciones | App: `src/api/directorio.ts:38` |  |

### `api/v1/dispositivos`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| POST | `v1/dispositivos/push-prueba` | `api.v1.dispositivos.push-prueba`<br>DispositivoController@pushPrueba | App: `src/api/dispositivos.ts:29` |  |
| POST | `v1/dispositivos/push-token` | `api.v1.dispositivos.push-token.registrar`<br>DispositivoController@registrarPushToken | App: `src/api/dispositivos.ts:15` |  |
| DELETE | `v1/dispositivos/push-token` | `api.v1.dispositivos.push-token.revocar`<br>DispositivoController@revocarPushToken | App: `src/api/dispositivos.ts:19` |  |

### `api/v1/equipo`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/equipo` | `api.v1.equipo.index`<br>EquipoController@index | App: `src/api/trabajo.ts:18` |  |
| GET | `v1/equipo/aprobaciones/conteo` | `api.v1.equipo.aprobaciones.conteo`<br>EquipoController@conteo | App: `src/api/aprobaciones.ts:20` |  |
| GET | `v1/equipo/pendientes` | `api.v1.equipo.pendientes`<br>EquipoController@pendientes | App: `src/api/trabajo.ts:25` | meta custom |
| POST | `v1/equipo/solicitudes/{solicitud}/visto-bueno` | `api.v1.equipo.solicitudes.visto-bueno`<br>EquipoController@vistoBueno | App: `src/api/trabajo.ts:30` |  |

### `api/v1/evaluaciones`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/evaluaciones` | `api.v1.evaluaciones.index`<br>EvaluacionController@index | App: `src/api/trabajo.ts:47` | paginate() en EvaluacionPeriodoPruebaService::listar; el controlador arma la respuesta. Params: `per_page`, `estado` |
| POST | `v1/evaluaciones/excepciones/{solicitud}/resolver` | `api.v1.evaluaciones.excepcion.resolver`<br>EvaluacionController@resolverExcepcion | «sin consumidor conocido» |  |
| GET | `v1/evaluaciones/{evaluacion}` | `api.v1.evaluaciones.show`<br>EvaluacionController@show | App: `src/api/trabajo.ts:52` |  |
| POST | `v1/evaluaciones/{evaluacion}/autorizar` | `api.v1.evaluaciones.autorizar`<br>EvaluacionController@autorizar | App: `src/api/trabajo.ts:62` |  |
| POST | `v1/evaluaciones/{evaluacion}/capturar` | `api.v1.evaluaciones.capturar`<br>EvaluacionController@capturar | App: `src/api/trabajo.ts:57` |  |
| POST | `v1/evaluaciones/{evaluacion}/devolver` | `api.v1.evaluaciones.devolver`<br>EvaluacionController@devolver | App: `src/api/trabajo.ts:67` |  |
| POST | `v1/evaluaciones/{evaluacion}/excepcion` | `api.v1.evaluaciones.excepcion.solicitar`<br>EvaluacionController@solicitarExcepcion | «sin consumidor conocido» |  |

### `api/v1/incorporacion`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/incorporacion/invitaciones/{token}/fases` | `api.v1.incorporacion.invitaciones.fases`<br>IncorporacionInvitacionController@fases | «sin consumidor conocido» | Público (sin Sanctum) |
| POST | `v1/incorporacion/invitaciones/{token}/registrar` | `api.v1.incorporacion.invitaciones.registrar`<br>IncorporacionInvitacionController@registrar | App: `src/api/incorporacionInvitacion.ts:20` | Público (sin Sanctum) |
| GET | `v1/incorporacion/invitaciones/{token}/validar` | `api.v1.incorporacion.invitaciones.validar`<br>IncorporacionInvitacionController@validar | App: `src/api/incorporacionInvitacion.ts:14` | Público (sin Sanctum) |

### `api/v1/induccion`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/induccion/pdf` | `api.v1.induccion.pdf`<br>MiProcesoController@pdfInduccion | App: `src/api/normalizers/induccion.ts:38` (ruta de descarga/visor) |  |
| GET | `v1/induccion/presentacion` | `api.v1.induccion.presentacion`<br>MiProcesoController@presentacionInduccion | App: `src/api/induccion.ts:26` |  |

### `api/v1/login`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| POST | `v1/login` | `api.v1.login`<br>AuthController@login | App: `src/api/auth.ts:29` | Público (sin Sanctum) |

### `api/v1/logout`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| POST | `v1/logout` | `api.v1.logout`<br>AuthController@logout | App: `src/api/auth.ts:34` |  |

### `api/v1/me`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/me` | `api.v1.me`<br>AuthController@me | App: `src/api/auth.ts:38` |  |

### `api/v1/mobile`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/mobile/bootstrap` | `api.v1.mobile.bootstrap`<br>MobileBootstrapController | App: `src/api/mobileBootstrap.ts:12` |  |

### `api/v1/notificaciones`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/notificaciones` | `api.v1.notificaciones.index`<br>NotificacionController@index | App: `src/api/notificaciones.ts:9` | meta custom. Params: `per_page`, `page` |
| POST | `v1/notificaciones/leer-todas` | `api.v1.notificaciones.leer-todas`<br>NotificacionController@marcarTodasLeidas | App: `src/api/notificaciones.ts:33` |  |
| POST | `v1/notificaciones/{notificacion}/abrir` | `api.v1.notificaciones.abrir`<br>NotificacionController@abrir | App: `src/api/notificaciones.ts:27` |  |
| POST | `v1/notificaciones/{notificacion}/leer` | `api.v1.notificaciones.leer`<br>NotificacionController@marcarLeida | App: `src/api/notificaciones.ts:14` ⚠ cliente sin uso en pantallas |  |

### `api/v1/reautenticar`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| POST | `v1/reautenticar` | `api.v1.reautenticar`<br>AuthController@reautenticar | App: `src/api/auth.ts:48` |  |

### `api/v1/rh/actas`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/actas` | `api.v1.rh.actas.index`<br>Rh\ActaController@index | App: `src/api/rh/cicloLaboral.ts:398` | paginate() en ActaService::listar; el controlador arma la respuesta. Params: `per_page`, `tipo`, `estado`, `colaborador_id` |
| GET | `v1/rh/actas/testigos` | `api.v1.rh.actas.testigos`<br>Rh\ActaController@buscarTestigos | «sin consumidor conocido» |  |
| GET | `v1/rh/actas/{acta}` | `api.v1.rh.actas.show`<br>Rh\ActaController@show | App: `src/api/rh/cicloLaboral.ts:404` |  |
| PATCH | `v1/rh/actas/{acta}` | `api.v1.rh.actas.update`<br>Rh\ActaController@update | «sin consumidor conocido» |  |
| POST | `v1/rh/actas/{acta}/anexos` | `api.v1.rh.actas.anexos.store`<br>Rh\ActaController@anexo | «sin consumidor conocido» |  |
| GET | `v1/rh/actas/{acta}/anexos/{anexo}` | `api.v1.rh.actas.anexos.show`<br>Rh\ActaController@descargarAnexo | App: `src/api/rh/cicloLaboral.ts:409` (ruta de descarga/visor) |  |
| GET | `v1/rh/actas/{acta}/anexos/{anexo}/ver` | `api.v1.rh.actas.anexos.ver`<br>Rh\ActaController@verAnexo | App: `src/api/rh/cicloLaboral.ts:414` (ruta de descarga/visor) |  |
| POST | `v1/rh/actas/{acta}/cerrar` | `api.v1.rh.actas.cerrar`<br>Rh\ActaController@cerrar | «sin consumidor conocido» |  |
| POST | `v1/rh/actas/{acta}/documento` | `api.v1.rh.actas.documento`<br>Rh\ActaController@generarDocumento | «sin consumidor conocido» |  |
| POST | `v1/rh/actas/{acta}/entrega` | `api.v1.rh.actas.entrega`<br>Rh\ActaController@entrega | «sin consumidor conocido» |  |
| POST | `v1/rh/actas/{acta}/negativa-firma` | `api.v1.rh.actas.negativa-firma`<br>Rh\ActaController@negativaFirma | «sin consumidor conocido» |  |
| POST | `v1/rh/actas/{acta}/seguimiento` | `api.v1.rh.actas.seguimiento`<br>Rh\ActaController@seguimiento | «sin consumidor conocido» |  |
| GET | `v1/rh/actas/{acta}/vista-previa` | `api.v1.rh.actas.vista-previa`<br>Rh\ActaController@vistaPrevia | «sin consumidor conocido» |  |

### `api/v1/rh/bajas`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/bajas` | `api.v1.rh.bajas.index`<br>Rh\BajaController@index | App: `src/api/rh/bajas.ts:60` | La app lo documenta como «paginador de Laravel sin envoltura `meta`» (`src/api/rh/bajas.ts:48`). |
| POST | `v1/rh/bajas` | `api.v1.rh.bajas.store`<br>Rh\BajaController@store | «sin consumidor conocido» |  |
| GET | `v1/rh/bajas/catalogo` | `api.v1.rh.bajas.catalogo`<br>Rh\BajaController@catalogo | «sin consumidor conocido» |  |
| GET | `v1/rh/bajas/{baja}` | `api.v1.rh.bajas.show`<br>Rh\BajaController@show | App: `src/api/rh/bajas.ts:65` |  |
| PUT | `v1/rh/bajas/{baja}` | `api.v1.rh.bajas.update`<br>Rh\BajaController@update | «sin consumidor conocido» |  |
| POST | `v1/rh/bajas/{baja}/documentos` | `api.v1.rh.bajas.documentos.store`<br>Rh\BajaController@adjuntar | «sin consumidor conocido» |  |
| POST | `v1/rh/bajas/{baja}/enviar` | `api.v1.rh.bajas.enviar`<br>Rh\BajaController@enviar | «sin consumidor conocido» |  |
| POST | `v1/rh/bajas/{baja}/rechazar` | `api.v1.rh.bajas.rechazar`<br>Rh\BajaController@rechazar | «sin consumidor conocido» |  |

### `api/v1/rh/cambios-foto`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/cambios-foto` | `api.v1.rh.cambios-foto.index`<br>Rh\CambioFotoController@index | App: `src/api/rh/cambiosFoto.ts:12` |  |
| POST | `v1/rh/cambios-foto/{cambio}/aprobar` | `api.v1.rh.cambios-foto.aprobar`<br>Rh\CambioFotoController@aprobar | App: `src/api/rh/cambiosFoto.ts:17` |  |
| GET | `v1/rh/cambios-foto/{cambio}/propuesta` | `api.v1.rh.cambios-foto.propuesta`<br>Rh\CambioFotoController@propuesta | App (indirecto): abre la URL que arma el backend en `app/Services/Colaboradores/FotoColaboradorService.php:317` |  |
| POST | `v1/rh/cambios-foto/{cambio}/rechazar` | `api.v1.rh.cambios-foto.rechazar`<br>Rh\CambioFotoController@rechazar | App: `src/api/rh/cambiosFoto.ts:21` |  |

### `api/v1/rh/candidatos`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/candidatos` | `api.v1.rh.candidatos.index`<br>Rh\CandidatoController@index | App: `src/api/rh/candidatos.ts:36` | paginate(). Params: `per_page` |
| GET | `v1/rh/candidatos/{candidato}` | `api.v1.rh.candidatos.show`<br>Rh\CandidatoController@show | App: `src/api/rh/candidatos.ts:49` |  |
| POST | `v1/rh/candidatos/{candidato}/autorizar` | `api.v1.rh.candidatos.autorizar`<br>Rh\CandidatoController@autorizarRh | App: `src/api/rh/candidatos.ts:115` |  |
| POST | `v1/rh/candidatos/{candidato}/contratar` | `api.v1.rh.candidatos.contratar`<br>Rh\AltaColaboradorController@contratarCandidato | App: `src/api/rh/candidatos.ts:140` |  |
| GET | `v1/rh/candidatos/{candidato}/cv` | `api.v1.rh.candidatos.cv`<br>Rh\CandidatoController@cv | App: `src/api/rh/candidatos.ts:41` (ruta de descarga/visor) |  |
| POST | `v1/rh/candidatos/{candidato}/descartar` | `api.v1.rh.candidatos.descartar`<br>Rh\CandidatoController@descartar | App: `src/api/rh/candidatos.ts:130` |  |
| POST | `v1/rh/candidatos/{candidato}/devolver` | `api.v1.rh.candidatos.devolver`<br>Rh\CandidatoController@devolverRh | App: `src/api/rh/candidatos.ts:125` |  |
| POST | `v1/rh/candidatos/{candidato}/entrevista` | `api.v1.rh.candidatos.entrevista`<br>Rh\CandidatoController@registrarEntrevista | App: `src/api/rh/candidatos.ts:59` |  |
| POST | `v1/rh/candidatos/{candidato}/evidencias` | `api.v1.rh.candidatos.evidencias`<br>Rh\CandidatoController@agregarEvidencias | «sin consumidor conocido» |  |
| GET | `v1/rh/candidatos/{candidato}/evidencias/{evidencia}` | `api.v1.rh.candidatos.evidencias.descargar`<br>Rh\CandidatoController@evidencia | App: `src/api/rh/candidatos.ts:45` (ruta de descarga/visor) |  |
| POST | `v1/rh/candidatos/{candidato}/perfil` | `api.v1.rh.candidatos.perfil`<br>Rh\CandidatoController@evaluarPerfil | App: `src/api/rh/candidatos.ts:54` |  |
| POST | `v1/rh/candidatos/{candidato}/psicometricas/enviar` | `api.v1.rh.candidatos.psicometricas.enviar`<br>Rh\CandidatoController@enviarPsicometricas | App: `src/api/rh/candidatos.ts:64` |  |
| POST | `v1/rh/candidatos/{candidato}/psicometricas/resultados` | `api.v1.rh.candidatos.psicometricas.resultados`<br>Rh\CandidatoController@resultadosPsicometricas | App: `src/api/rh/candidatos.ts:72` |  |
| POST | `v1/rh/candidatos/{candidato}/psicometricas/revision` | `api.v1.rh.candidatos.psicometricas.revision`<br>Rh\CandidatoController@revisarPsicometricas | App: `src/api/rh/candidatos.ts:77` |  |
| POST | `v1/rh/candidatos/{candidato}/rechazar` | `api.v1.rh.candidatos.rechazar`<br>Rh\CandidatoController@rechazarRh | App: `src/api/rh/candidatos.ts:120` |  |
| POST | `v1/rh/candidatos/{candidato}/referencias` | `api.v1.rh.candidatos.referencias`<br>Rh\CandidatoController@registrarReferencia | App: `src/api/rh/candidatos.ts:105` |  |
| POST | `v1/rh/candidatos/{candidato}/referencias/concluir` | `api.v1.rh.candidatos.referencias.concluir`<br>Rh\CandidatoController@concluirReferencias | App: `src/api/rh/candidatos.ts:110` |  |
| POST | `v1/rh/candidatos/{candidato}/socioeconomico` | `api.v1.rh.candidatos.socioeconomico`<br>Rh\CandidatoController@registrarSocioeconomico | App: `src/api/rh/candidatos.ts:94` |  |
| POST | `v1/rh/candidatos/{candidato}/socioeconomico/revision` | `api.v1.rh.candidatos.socioeconomico.revision`<br>Rh\CandidatoController@revisarSocioeconomico | App: `src/api/rh/candidatos.ts:100` |  |

### `api/v1/rh/catalogos`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/catalogos` | `api.v1.rh.catalogos`<br>Rh\CatalogoController | App: `src/api/rh/alta.ts:46` ⚠ cliente sin uso en pantallas |  |

### `api/v1/rh/celebraciones`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/celebraciones/aniversarios` | `api.v1.rh.celebraciones.aniversarios`<br>Rh\CelebracionController@aniversarios | App: `src/api/rh/celebraciones.ts:30` ⚠ cliente sin uso en pantallas | meta custom. Params: `desde`, `hasta`, `dias`, `empresa_id`, `sucursal_id`, `departamento_id`, `q` |
| POST | `v1/rh/celebraciones/evento/{celebracion}/recepcion` | `api.v1.rh.celebraciones.recepcion`<br>Rh\CelebracionController@recepcion | «sin consumidor conocido» |  |
| GET | `v1/rh/celebraciones/{colaborador}/{tipo}` | `api.v1.rh.celebraciones.evento`<br>Rh\CelebracionController@evento | App: `src/api/rh/celebraciones.ts:36` ⚠ cliente sin uso en pantallas |  |
| POST | `v1/rh/celebraciones/{colaborador}/{tipo}/avisar-todos` | `api.v1.rh.celebraciones.avisar-todos`<br>Rh\CelebracionController@avisarATodos | App: `src/api/rh/celebraciones.ts:48` ⚠ cliente sin uso en pantallas |  |
| POST | `v1/rh/celebraciones/{colaborador}/{tipo}/enviar` | `api.v1.rh.celebraciones.enviar`<br>Rh\CelebracionController@enviar | App: `src/api/rh/celebraciones.ts:42` |  |
| POST | `v1/rh/celebraciones/{colaborador}/{tipo}/tarjeta` | `api.v1.rh.celebraciones.tarjeta.regenerar`<br>Rh\CelebracionController@regenerarTarjeta | «sin consumidor conocido» |  |

### `api/v1/rh/ciclo`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/ciclo/pendientes` | `api.v1.rh.ciclo.pendientes`<br>Rh\CicloLaboralRhController@pendientes | «sin consumidor conocido» | meta custom. Params: `per_page` |

### `api/v1/rh/cierres`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/cierres` | `api.v1.rh.cierres.index`<br>Rh\CierreLaboralController@index | App: `src/api/rh/cicloLaboral.ts:173` ⚠ cliente sin uso en pantallas | paginate() en CierreLaboralService::listar; el controlador arma la respuesta. Params: `per_page`, `estado`, `colaborador_id` |
| GET | `v1/rh/cierres/{cierre}` | `api.v1.rh.cierres.show`<br>Rh\CierreLaboralController@show | App: `src/api/rh/cicloLaboral.ts:178` |  |
| POST | `v1/rh/cierres/{cierre}/aviso` | `api.v1.rh.cierres.aviso`<br>Rh\CierreLaboralController@aviso | App: `src/api/rh/cicloLaboral.ts:190` |  |
| POST | `v1/rh/cierres/{cierre}/aviso/generar` | `api.v1.rh.cierres.aviso.generar`<br>Rh\CierreLaboralController@generarAviso | App: `src/api/rh/cicloLaboral.ts:196` |  |
| POST | `v1/rh/cierres/{cierre}/cancelar` | `api.v1.rh.cierres.cancelar`<br>Rh\CierreLaboralController@cancelar | App: `src/api/rh/cicloLaboral.ts:262` |  |
| POST | `v1/rh/cierres/{cierre}/cerrar` | `api.v1.rh.cierres.cerrar`<br>Rh\CierreLaboralController@cerrar | «sin consumidor conocido» |  |
| POST | `v1/rh/cierres/{cierre}/cerrar-expediente` | `api.v1.rh.cierres.cerrar-expediente`<br>Rh\CierreLaboralController@cerrarExpediente | App: `src/api/rh/cicloLaboral.ts:257` |  |
| POST | `v1/rh/cierres/{cierre}/cita` | `api.v1.rh.cierres.cita`<br>Rh\CierreLaboralController@cita | «sin consumidor conocido» |  |
| POST | `v1/rh/cierres/{cierre}/ejecutar-baja` | `api.v1.rh.cierres.ejecutar-baja`<br>Rh\CierreLaboralController@ejecutarBaja | App: `src/api/rh/cicloLaboral.ts:252` |  |
| POST | `v1/rh/cierres/{cierre}/finiquito/calcular` | `api.v1.rh.cierres.finiquito.calcular`<br>Rh\CierreLaboralController@calcularFiniquito | App: `src/api/rh/cicloLaboral.ts:202` |  |
| POST | `v1/rh/cierres/{cierre}/finiquito/conceptos` | `api.v1.rh.cierres.finiquito.conceptos.store`<br>Rh\CierreLaboralController@agregarConcepto | App: `src/api/rh/cicloLaboral.ts:210` |  |
| POST | `v1/rh/cierres/{cierre}/finiquito/conceptos-automaticos` | `api.v1.rh.cierres.finiquito.conceptos.ajustar`<br>Rh\CierreLaboralController@ajustarConcepto | «sin consumidor conocido» |  |
| PATCH | `v1/rh/cierres/{cierre}/finiquito/conceptos/{concepto}` | `api.v1.rh.cierres.finiquito.conceptos.update`<br>Rh\CierreLaboralController@actualizarConcepto | App: `src/api/rh/cicloLaboral.ts:215` |  |
| DELETE | `v1/rh/cierres/{cierre}/finiquito/conceptos/{concepto}` | `api.v1.rh.cierres.finiquito.conceptos.destroy`<br>Rh\CierreLaboralController@eliminarConcepto | App: `src/api/rh/cicloLaboral.ts:220` |  |
| POST | `v1/rh/cierres/{cierre}/finiquito/documento` | `api.v1.rh.cierres.finiquito.documento`<br>Rh\CierreLaboralController@generarFiniquito | App: `src/api/rh/cicloLaboral.ts:230` |  |
| POST | `v1/rh/cierres/{cierre}/finiquito/entrega` | `api.v1.rh.cierres.finiquito.entrega`<br>Rh\CierreLaboralController@entregarFiniquito | «sin consumidor conocido» |  |
| POST | `v1/rh/cierres/{cierre}/finiquito/firmado` | `api.v1.rh.cierres.finiquito.firmado`<br>Rh\CierreLaboralController@finiquitoFirmado | App: `src/api/rh/cicloLaboral.ts:241` |  |
| POST | `v1/rh/cierres/{cierre}/finiquito/pago` | `api.v1.rh.cierres.finiquito.pago`<br>Rh\CierreLaboralController@confirmarPago | App: `src/api/rh/cicloLaboral.ts:247` |  |
| POST | `v1/rh/cierres/{cierre}/finiquito/revisar` | `api.v1.rh.cierres.finiquito.revisar`<br>Rh\CierreLaboralController@revisarFiniquito | App: `src/api/rh/cicloLaboral.ts:225` |  |
| POST | `v1/rh/cierres/{cierre}/pago/programar` | `api.v1.rh.cierres.pago.programar`<br>Rh\CierreLaboralController@programarPago | «sin consumidor conocido» |  |
| POST | `v1/rh/cierres/{cierre}/procedimiento/etapa` | `api.v1.rh.cierres.procedimiento.etapa`<br>Rh\DocumentoProcesoController@etapa | «sin consumidor conocido» |  |
| POST | `v1/rh/cierres/{cierre}/procedimiento/negativa` | `api.v1.rh.cierres.procedimiento.negativa`<br>Rh\DocumentoProcesoController@negativa | App: `src/api/rh/documentosProceso.ts:220` |  |
| POST | `v1/rh/cierres/{cierre}/procedimiento/testigos` | `api.v1.rh.cierres.procedimiento.testigos`<br>Rh\DocumentoProcesoController@testigos | App: `src/api/rh/documentosProceso.ts:225` |  |

### `api/v1/rh/colaboradores`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/colaboradores` | `api.v1.rh.colaboradores.index`<br>Rh\ColaboradorController@index | App: `src/api/rh/colaboradores.ts:19` | paginate(). Params: `q`, `sucursal_id`, `departamento_id`, `estatus`, `per_page` |
| POST | `v1/rh/colaboradores` | `api.v1.rh.colaboradores.store`<br>Rh\AltaColaboradorController@store | App: `src/api/rh/alta.ts:60` ⚠ cliente sin uso en pantallas |  |
| GET | `v1/rh/colaboradores/{colaborador}` | `api.v1.rh.colaboradores.show`<br>Rh\ColaboradorController@show | App: `src/api/rh/colaboradores.ts:24` |  |
| POST | `v1/rh/colaboradores/{colaborador}/actas` | `api.v1.rh.colaboradores.actas.store`<br>Rh\ActaController@store | «sin consumidor conocido» |  |
| POST | `v1/rh/colaboradores/{colaborador}/activar` | `api.v1.rh.colaboradores.activar`<br>Rh\AltaColaboradorController@activar | App: `src/api/rh/cicloLaboral.ts:49` |  |
| GET | `v1/rh/colaboradores/{colaborador}/alta` | `api.v1.rh.colaboradores.alta`<br>Rh\AltaColaboradorController@show | App: `src/api/rh/cicloLaboral.ts:43` |  |
| GET | `v1/rh/colaboradores/{colaborador}/ciclo` | `api.v1.rh.colaboradores.ciclo`<br>Rh\CicloLaboralRhController@ciclo | App: `src/api/rh/onboarding.ts:141`, `src/hooks/queries/useRhCicloLaboral.ts:279` |  |
| POST | `v1/rh/colaboradores/{colaborador}/cierres` | `api.v1.rh.colaboradores.cierres.store`<br>Rh\CierreLaboralController@store | App: `src/api/rh/cicloLaboral.ts:183` ⚠ cliente sin uso en pantallas |  |
| GET | `v1/rh/colaboradores/{colaborador}/contratos` | `api.v1.rh.colaboradores.contratos`<br>Rh\ContratoController@delColaborador | App: `src/api/rh/cicloLaboral.ts:59` |  |
| POST | `v1/rh/colaboradores/{colaborador}/contratos/firmado` | `api.v1.rh.colaboradores.contratos.firmado`<br>Rh\ContratoController@subirFirmado | «sin consumidor conocido» |  |
| POST | `v1/rh/colaboradores/{colaborador}/credenciales` | `api.v1.rh.colaboradores.credenciales`<br>Rh\CredencialesController@store | «sin consumidor conocido» |  |
| GET | `v1/rh/colaboradores/{colaborador}/descriptivo-puesto` | `api.v1.rh.colaboradores.descriptivo-puesto.show`<br>Rh\AcuseDescriptivoPuestoController@show | «sin consumidor conocido» |  |
| POST | `v1/rh/colaboradores/{colaborador}/descriptivo-puesto/enviar` | `api.v1.rh.colaboradores.descriptivo-puesto.enviar`<br>Rh\AcuseDescriptivoPuestoController@enviar | «sin consumidor conocido» |  |
| POST | `v1/rh/colaboradores/{colaborador}/documentos-laborales` | `api.v1.rh.colaboradores.documentos-laborales.store`<br>Rh\DocumentoLaboralController@store | «sin consumidor conocido» |  |
| GET | `v1/rh/colaboradores/{colaborador}/foto` | `api.v1.rh.colaboradores.foto`<br>Rh\ColaboradorController@foto | App (indirecto): abre la URL que arma el backend en `app/Services/Colaboradores/FotoColaboradorService.php:378` |  |
| GET | `v1/rh/colaboradores/{colaborador}/jerarquia` | `api.v1.rh.colaboradores.jerarquia`<br>Rh\AltaColaboradorController@jerarquia | App: `src/api/rh/cicloLaboral.ts:54` |  |
| POST | `v1/rh/colaboradores/{colaborador}/recibos` | `api.v1.rh.colaboradores.recibos.store`<br>Rh\ReciboNominaController@store | App: `src/api/rh/cicloLaboral.ts:330` ⚠ cliente sin uso en pantallas |  |
| GET | `v1/rh/colaboradores/{colaborador}/solicitud-empleo` | `api.v1.rh.solicitud-empleo.show`<br>Rh\SolicitudEmpleoIngresoController@show | «sin consumidor conocido» |  |
| POST | `v1/rh/colaboradores/{colaborador}/solicitud-empleo/{seccion}` | `api.v1.rh.solicitud-empleo.guardar`<br>Rh\SolicitudEmpleoIngresoController@guardar | «sin consumidor conocido» |  |

### `api/v1/rh/contratos`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/contratos/por-vencer` | `api.v1.rh.contratos.por-vencer`<br>Rh\ContratoController@porVencer | App: `src/api/rh/cicloLaboral.ts:67` |  |

### `api/v1/rh/correcciones`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/correcciones/colaboradores/{colaborador}/historial` | `api.v1.rh.correcciones.colaborador.historial`<br>Rh\CorreccionController@historialColaborador | «sin consumidor conocido» |  |
| GET | `v1/rh/correcciones/{dominio}/{id}` | `api.v1.rh.correcciones.mostrar`<br>Rh\CorreccionController@mostrar | «sin consumidor conocido» |  |
| POST | `v1/rh/correcciones/{dominio}/{id}` | `api.v1.rh.correcciones.aplicar`<br>Rh\CorreccionController@aplicar | «sin consumidor conocido» |  |
| GET | `v1/rh/correcciones/{dominio}/{id}/historial` | `api.v1.rh.correcciones.historial`<br>Rh\CorreccionController@historial | «sin consumidor conocido» |  |
| POST | `v1/rh/correcciones/{dominio}/{id}/vista-previa` | `api.v1.rh.correcciones.vista-previa`<br>Rh\CorreccionController@vistaPrevia | «sin consumidor conocido» |  |

### `api/v1/rh/cumpleanos`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/cumpleanos` | `api.v1.rh.cumpleanos.index`<br>Rh\CumpleanosController@index | App: `src/api/rh/cumpleanos.ts:25` | paginación custom (forPage). Params: `periodo`, `mes`, `sucursal_id`, `departamento_id`, `q`, `per_page`, `page` |
| GET | `v1/rh/cumpleanos/{colaborador}/foto` | `api.v1.rh.cumpleanos.foto`<br>Rh\CumpleanosController@foto | App: `src/api/rh/cumpleanos.ts:42` (ruta de descarga/visor) ⚠ cliente sin uso en pantallas |  |
| GET | `v1/rh/cumpleanos/{greeting}` | `api.v1.rh.cumpleanos.show`<br>Rh\CumpleanosController@show | App: `src/api/rh/cumpleanos.ts:31` |  |
| GET | `v1/rh/cumpleanos/{greeting}/imagen` | `api.v1.rh.cumpleanos.imagen`<br>Rh\CumpleanosController@imagen | App: `src/api/rh/cumpleanos.ts:37` (ruta de descarga/visor) ⚠ cliente sin uso en pantallas |  |
| POST | `v1/rh/cumpleanos/{greeting}/muro/abrir` | `api.v1.rh.cumpleanos.muro.abrir`<br>Rh\CumpleanosController@abrirMuro | App: `src/api/birthdayWall.ts:42` |  |
| POST | `v1/rh/cumpleanos/{greeting}/muro/cerrar` | `api.v1.rh.cumpleanos.muro.cerrar`<br>Rh\CumpleanosController@cerrarMuro | App: `src/api/birthdayWall.ts:47` |  |

### `api/v1/rh/dashboard`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/dashboard` | `api.v1.rh.dashboard`<br>Rh\DashboardController | App: `src/api/rh/dashboard.ts:8` |  |

### `api/v1/rh/documentos`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/documentos` | `api.v1.rh.documentos.index`<br>Rh\DocumentoController@index | App: `src/api/rh/documentos.ts:18` ⚠ cliente sin uso en pantallas | paginate(). Params: `estado`, `sucursal_id`, `q`, `per_page` |
| GET | `v1/rh/documentos/{documento}` | `api.v1.rh.documentos.show`<br>Rh\DocumentoController@show | App: `src/api/rh/documentos.ts:23` |  |
| POST | `v1/rh/documentos/{documento}/aprobar` | `api.v1.rh.documentos.aprobar`<br>Rh\DocumentoController@aprobar | App: `src/api/rh/documentos.ts:38` |  |
| POST | `v1/rh/documentos/{documento}/correccion` | `api.v1.rh.documentos.correccion`<br>Rh\DocumentoController@correccion | App: `src/api/rh/documentos.ts:48` |  |
| GET | `v1/rh/documentos/{documento}/descargar` | `api.v1.rh.documentos.descargar`<br>Rh\DocumentoController@descargar | App: `src/api/rh/documentos.ts:34` (ruta de descarga/visor) |  |
| GET | `v1/rh/documentos/{documento}/extraccion` | `api.v1.rh.documentos.extraccion`<br>Rh\DocumentoController@extraccion | App: `src/api/rh/documentExtraction.ts:20` |  |
| POST | `v1/rh/documentos/{documento}/extraccion/aplicar` | `api.v1.rh.documentos.extraccion.aplicar`<br>Rh\DocumentoController@aplicarExtraccion | App: `src/api/rh/documentExtraction.ts:31` |  |
| POST | `v1/rh/documentos/{documento}/extraccion/ignorar` | `api.v1.rh.documentos.extraccion.ignorar`<br>Rh\DocumentoController@ignorarExtraccion | App: `src/api/rh/documentExtraction.ts:36` |  |
| POST | `v1/rh/documentos/{documento}/rechazar` | `api.v1.rh.documentos.rechazar`<br>Rh\DocumentoController@rechazar | App: `src/api/rh/documentos.ts:43` |  |
| GET | `v1/rh/documentos/{documento}/ver` | `api.v1.rh.documentos.ver`<br>Rh\DocumentoController@ver | App: `src/api/rh/documentos.ts:29` (ruta de descarga/visor) |  |

### `api/v1/rh/documentos-edicion`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/documentos-edicion/{tipo}/{id}/campos` | `api.v1.rh.documentos-edicion.campos`<br>Rh\DocumentoEdicionController@campos | «sin consumidor conocido» |  |
| POST | `v1/rh/documentos-edicion/{tipo}/{id}/emitir` | `api.v1.rh.documentos-edicion.emitir`<br>Rh\DocumentoEdicionController@emitir | «sin consumidor conocido» |  |
| GET | `v1/rh/documentos-edicion/{tipo}/{id}/versiones` | `api.v1.rh.documentos-edicion.versiones`<br>Rh\DocumentoEdicionController@versiones | «sin consumidor conocido» |  |
| POST | `v1/rh/documentos-edicion/{tipo}/{id}/vista-previa` | `api.v1.rh.documentos-edicion.vista-previa`<br>Rh\DocumentoEdicionController@vistaPrevia | «sin consumidor conocido» |  |

### `api/v1/rh/documentos-laborales`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/documentos-laborales` | `api.v1.rh.documentos-laborales.index`<br>Rh\DocumentoLaboralController@index | App: `src/api/rh/cicloLaboral.ts:102` | paginate() en DocumentoLaboralConsultaService::listar; el controlador arma la respuesta. Params: `per_page`, `etapa`, `estado`, `colaborador_id`, `clave`, `categoria` |
| GET | `v1/rh/documentos-laborales/pendientes` | `api.v1.rh.documentos-laborales.pendientes`<br>Rh\DocumentoLaboralController@pendientes | App: `src/api/rh/cicloLaboral.ts:107` ⚠ cliente sin uso en pantallas |  |
| GET | `v1/rh/documentos-laborales/{documento}` | `api.v1.rh.documentos-laborales.show`<br>Rh\DocumentoLaboralController@show | App: `src/api/rh/cicloLaboral.ts:112` |  |
| POST | `v1/rh/documentos-laborales/{documento}/archivar` | `api.v1.rh.documentos-laborales.archivar`<br>Rh\DocumentoLaboralController@archivar | App: `src/api/rh/cicloLaboral.ts:159` |  |
| POST | `v1/rh/documentos-laborales/{documento}/cancelar` | `api.v1.rh.documentos-laborales.cancelar`<br>Rh\DocumentoLaboralController@cancelar | App: `src/api/rh/cicloLaboral.ts:164` |  |
| GET | `v1/rh/documentos-laborales/{documento}/descargar` | `api.v1.rh.documentos-laborales.descargar`<br>Rh\DocumentoLaboralController@descargar | App: `src/api/rh/cicloLaboral.ts:117` (ruta de descarga/visor) |  |
| POST | `v1/rh/documentos-laborales/{documento}/envio` | `api.v1.rh.documentos-laborales.envio`<br>Rh\DocumentoLaboralController@envio | App: `src/api/rh/cicloLaboral.ts:141` |  |
| POST | `v1/rh/documentos-laborales/{documento}/escaneo` | `api.v1.rh.documentos-laborales.escaneo`<br>Rh\DocumentoLaboralController@escaneo | App: `src/api/rh/cicloLaboral.ts:154` |  |
| POST | `v1/rh/documentos-laborales/{documento}/firma-fisica` | `api.v1.rh.documentos-laborales.firma-fisica`<br>Rh\DocumentoLaboralController@firmaFisica | App: `src/api/rh/cicloLaboral.ts:131` |  |
| POST | `v1/rh/documentos-laborales/{documento}/imprimir` | `api.v1.rh.documentos-laborales.imprimir`<br>Rh\DocumentoLaboralController@imprimir | App: `src/api/rh/cicloLaboral.ts:126` |  |
| POST | `v1/rh/documentos-laborales/{documento}/recepcion` | `api.v1.rh.documentos-laborales.recepcion`<br>Rh\DocumentoLaboralController@recepcion | App: `src/api/rh/cicloLaboral.ts:146` |  |
| GET | `v1/rh/documentos-laborales/{documento}/ver` | `api.v1.rh.documentos-laborales.ver`<br>Rh\DocumentoLaboralController@ver | App: `src/api/rh/cicloLaboral.ts:122` (ruta de descarga/visor) |  |

### `api/v1/rh/documentos-proceso`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/documentos-proceso/colaborador/{colaborador}` | `api.v1.rh.documentos-proceso.colaborador`<br>Rh\DocumentoProcesoController@colaborador | App: `src/api/rh/documentosProceso.ts:194` |  |
| GET | `v1/rh/documentos-proceso/documento/{documento}/word` | `api.v1.rh.documentos-proceso.word`<br>Rh\DocumentoProcesoController@word | App: `src/api/rh/documentosProceso.ts:190` (ruta de descarga/visor) |  |
| POST | `v1/rh/documentos-proceso/documento/{documento}/{accion}` | `api.v1.rh.documentos-proceso.operar`<br>Rh\DocumentoProcesoController@operar | «sin consumidor conocido» |  |
| GET | `v1/rh/documentos-proceso/previa/{flujo}/{id}` | `api.v1.rh.documentos-proceso.previa.documentos`<br>Rh\DocumentoProcesoController@documentosPrevia | «sin consumidor conocido» |  |
| GET | `v1/rh/documentos-proceso/previa/{flujo}/{id}/pdf` | `api.v1.rh.documentos-proceso.previa.pdf`<br>Rh\DocumentoProcesoController@pdfPrevia | «sin consumidor conocido» |  |
| GET | `v1/rh/documentos-proceso/testigos` | `api.v1.rh.documentos-proceso.testigos`<br>Rh\DocumentoProcesoController@buscarTestigos | «sin consumidor conocido» |  |
| GET | `v1/rh/documentos-proceso/{tipo}/{id}` | `api.v1.rh.documentos-proceso.show`<br>Rh\DocumentoProcesoController@show | App: `src/api/rh/documentosProceso.ts:200` |  |
| POST | `v1/rh/documentos-proceso/{tipo}/{id}/generar` | `api.v1.rh.documentos-proceso.generar`<br>Rh\DocumentoProcesoController@generar | App: `src/api/rh/documentosProceso.ts:206` |  |
| POST | `v1/rh/documentos-proceso/{tipo}/{id}/paquete` | `api.v1.rh.documentos-proceso.paquete`<br>Rh\DocumentoProcesoController@paquete | App: `src/api/rh/documentosProceso.ts:211` |  |
| GET | `v1/rh/documentos-proceso/{tipo}/{id}/vista-previa` | `api.v1.rh.documentos-proceso.vista-previa`<br>Rh\DocumentoProcesoController@vistaPrevia | «sin consumidor conocido» |  |

### `api/v1/rh/expedientes`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/expedientes` | `api.v1.rh.expedientes.index`<br>Rh\ExpedienteController@index | App: `src/api/rh/expedientes.ts:23` ⚠ cliente sin uso en pantallas | Paginación **custom** (no `paginate()`): carga todo, filtra `estado` en memoria y corta con `forPage(page, 20)`. Respuesta `{data:[{id, nombre, correo, numero_empleado, empresa, sucursal, departamento, estatus_usuario, estado_incorporacion}], meta:{current_page, per_page:20, total, last_page}}`. Sin `links`. **`per_page` se ignora** (fijo `POR_PAGINA = 20`). Params: `estado`, `empresa_id`, `sucursal_id`, `departamento_id`, `busqueda`, `page`. La app tiene cliente (`RhExpedientesParams` = esos mismos 6) y hook `useRhExpedientes`, pero **ninguna pantalla llama el hook**; además su tipo `PaginatedResponse<RhExpediente>` describe la forma del *show* (`colaborador`, `documentos`…), no las filas planas del index. |
| GET | `v1/rh/expedientes/{colaborador}` | `api.v1.rh.expedientes.show`<br>Rh\ExpedienteController@show | App: `src/api/rh/expedientes.ts:28` |  |
| POST | `v1/rh/expedientes/{colaborador}/aprobar-incorporacion` | `api.v1.rh.expedientes.aprobar-incorporacion`<br>Rh\ExpedienteController@aprobarIncorporacion | App: `src/api/rh/expedientes.ts:68` |  |
| GET | `v1/rh/expedientes/{colaborador}/descargar-zip` | `api.v1.rh.expedientes.descargar-zip`<br>Rh\ExpedienteController@descargarZip | App: `src/api/rh/expedientes.ts:45` (ruta de descarga/visor) |  |
| POST | `v1/rh/expedientes/{colaborador}/documentos/{documento}/aprobar` | `api.v1.rh.expedientes.documentos.aprobar`<br>Rh\ExpedienteController@aprobarDocumento | App: `src/api/rh/expedientes.ts:54` |  |
| POST | `v1/rh/expedientes/{colaborador}/documentos/{documento}/autorizar-cambio` | `api.v1.rh.expedientes.documentos.autorizar-cambio`<br>Rh\ExpedienteController@autorizarCambioDocumento | App: `src/api/rh/expedientes.ts:63` |  |
| POST | `v1/rh/expedientes/{colaborador}/documentos/{documento}/correccion` | `api.v1.rh.expedientes.documentos.correccion`<br>Rh\ExpedienteController@correccionDocumento | App: `src/api/rh/expedientes.ts:50` |  |
| GET | `v1/rh/expedientes/{colaborador}/documentos/{documento}/descargar` | `api.v1.rh.expedientes.documentos.descargar`<br>Rh\ExpedienteController@descargarDocumento | App: `src/api/rh/expedientes.ts:40` (ruta de descarga/visor) |  |
| POST | `v1/rh/expedientes/{colaborador}/documentos/{documento}/rechazar` | `api.v1.rh.expedientes.documentos.rechazar`<br>Rh\ExpedienteController@rechazarDocumento | App: `src/api/rh/expedientes.ts:59` |  |
| GET | `v1/rh/expedientes/{colaborador}/documentos/{documento}/ver` | `api.v1.rh.expedientes.documentos.ver`<br>Rh\ExpedienteController@verDocumento | App: `src/api/rh/expedientes.ts:35` (ruta de descarga/visor) |  |
| POST | `v1/rh/expedientes/{colaborador}/rechazar-incorporacion` | `api.v1.rh.expedientes.rechazar-incorporacion`<br>Rh\ExpedienteController@rechazarIncorporacion | App: `src/api/rh/expedientes.ts:73` |  |

### `api/v1/rh/formatos`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET|POST|PUT|PATCH|DELETE|OPTIONS | `v1/rh/formatos/{retirado?}` | `api.v1.rh.formatos.retirado`<br>Closure | «sin consumidor conocido» | Closure que responde **410** — mismo caso que `formatos-oficiales`. |

### `api/v1/rh/formatos-oficiales`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET|POST|PUT|PATCH|DELETE|OPTIONS | `v1/rh/formatos-oficiales/{retirado?}` | `api.v1.rh.formatos-oficiales.retirado`<br>Closure | «sin consumidor conocido» | Closure que responde **410** «Este formato se retiró» — existe solo para que APKs viejas reciban un error claro. |

### `api/v1/rh/incorporaciones`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/incorporaciones` | `api.v1.rh.incorporaciones.index`<br>Rh\IncorporacionController@index | App: `src/api/rh/incorporaciones.ts:17` ⚠ cliente sin uso en pantallas | Paginación **custom**: carga todo, filtra `estado` en memoria, `forPage(page, per_page)` con `per_page` por defecto 15 (sin tope máximo). Respuesta `{data:[{id:"incorporacion:{id}", resource_id, estado, colaborador:{…resumen}, creado_en, acciones_permitidas}], meta:{current_page, per_page, total}}` — **sin `last_page` ni `links`**. Params: `q`, `estado`, `page`, `per_page`. La app tiene cliente (`RhIncorporacionesParams` = `estado, q, page, per_page`, normaliza con `normalizeRhPagina` que conserva `meta` tal cual) y hook `useRhIncorporaciones`, pero **ninguna pantalla llama el hook**; la app llega a las incorporaciones por `GET rh/pendientes` y abre el detalle `rh/incorporaciones/{colaborador}`. |
| GET | `v1/rh/incorporaciones/{colaborador}` | `api.v1.rh.incorporaciones.show`<br>Rh\IncorporacionController@show | App: `src/api/rh/incorporaciones.ts:22` |  |
| POST | `v1/rh/incorporaciones/{colaborador}/aprobar` | `api.v1.rh.incorporaciones.aprobar`<br>Rh\IncorporacionController@aprobar | App: `src/api/rh/incorporaciones.ts:28` |  |
| POST | `v1/rh/incorporaciones/{colaborador}/rechazar` | `api.v1.rh.incorporaciones.rechazar`<br>Rh\IncorporacionController@rechazar | App: `src/api/rh/incorporaciones.ts:33` |  |

### `api/v1/rh/indicadores`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/indicadores` | `api.v1.rh.indicadores`<br>Rh\EstructuraController@indicadores | App: `src/api/rh/cicloLaboral.ts:435` ⚠ cliente sin uso en pantallas |  |

### `api/v1/rh/induccion`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/induccion/intentos/{intento}` | `api.v1.rh.induccion.mostrar`<br>Rh\CicloLaboralRhController@mostrarInduccion | «sin consumidor conocido» |  |
| POST | `v1/rh/induccion/intentos/{intento}/calificar` | `api.v1.rh.induccion.calificar`<br>Rh\CicloLaboralRhController@calificarInduccion | «sin consumidor conocido» |  |
| GET | `v1/rh/induccion/intentos/{intento}/pdf` | `api.v1.rh.induccion.pdf.vista`<br>Rh\EvaluacionInduccionPdfController@vistaPrevia | «sin consumidor conocido» |  |
| GET | `v1/rh/induccion/intentos/{intento}/pdf/descargar` | `api.v1.rh.induccion.pdf.descargar`<br>Rh\EvaluacionInduccionPdfController@descargar | «sin consumidor conocido» |  |
| POST | `v1/rh/induccion/intentos/{intento}/pdf/expediente` | `api.v1.rh.induccion.pdf.expediente`<br>Rh\EvaluacionInduccionPdfController@guardarEnExpediente | «sin consumidor conocido» |  |
| POST | `v1/rh/induccion/intentos/{intento}/recalificar` | `api.v1.rh.induccion.recalificar`<br>Rh\CicloLaboralRhController@recalificarInduccion | «sin consumidor conocido» |  |
| GET | `v1/rh/induccion/sesiones` | `api.v1.rh.induccion.sesiones.index`<br>Rh\SesionInduccionController@index | «sin consumidor conocido» | Feature flag `induccion_sesiones_legacy` |
| POST | `v1/rh/induccion/sesiones` | `api.v1.rh.induccion.sesiones.store`<br>Rh\SesionInduccionController@store | «sin consumidor conocido» | Feature flag `induccion_sesiones_legacy` |
| GET | `v1/rh/induccion/sesiones/colaboradores` | `api.v1.rh.induccion.sesiones.colaboradores`<br>Rh\SesionInduccionController@colaboradores | «sin consumidor conocido» | Feature flag `induccion_sesiones_legacy` |
| POST | `v1/rh/induccion/sesiones/{sesion}/cancelar` | `api.v1.rh.induccion.sesiones.cancelar`<br>Rh\SesionInduccionController@cancelar | «sin consumidor conocido» | Feature flag `induccion_sesiones_legacy` |
| POST | `v1/rh/induccion/sesiones/{sesion}/enviar` | `api.v1.rh.induccion.sesiones.enviar`<br>Rh\SesionInduccionController@enviar | «sin consumidor conocido» | Feature flag `induccion_sesiones_legacy` |
| POST | `v1/rh/induccion/{evaluacion}/retroalimentacion` | `api.v1.rh.induccion.retroalimentar`<br>Rh\CicloLaboralRhController@retroalimentarInduccion | «sin consumidor conocido» |  |

### `api/v1/rh/jerarquia-puestos`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/jerarquia-puestos` | `api.v1.rh.jerarquia-puestos.index`<br>Rh\JerarquiaPuestoController@index | App: `src/api/rh/organizacion.ts:15` ⚠ cliente sin uso en pantallas |  |

### `api/v1/rh/nomina`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/nomina/lotes` | `api.v1.rh.nomina.lotes.index`<br>Rh\LoteNominaController@index | «sin consumidor conocido» | meta custom |
| POST | `v1/rh/nomina/lotes` | `api.v1.rh.nomina.lotes.store`<br>Rh\LoteNominaController@store | «sin consumidor conocido» |  |
| GET | `v1/rh/nomina/lotes/{lote}` | `api.v1.rh.nomina.lotes.show`<br>Rh\LoteNominaController@show | «sin consumidor conocido» |  |
| POST | `v1/rh/nomina/lotes/{lote}/cancelar` | `api.v1.rh.nomina.lotes.cancelar`<br>Rh\LoteNominaController@cancelar | «sin consumidor conocido» |  |
| POST | `v1/rh/nomina/lotes/{lote}/emitir` | `api.v1.rh.nomina.lotes.emitir`<br>Rh\LoteNominaController@emitir | «sin consumidor conocido» |  |
| GET | `v1/rh/nomina/lotes/{lote}/imprimir` | `api.v1.rh.nomina.lotes.imprimir`<br>Rh\LoteNominaController@imprimir | «sin consumidor conocido» |  |

### `api/v1/rh/onboarding`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| POST | `v1/rh/onboarding/avances/{avance}/retroalimentacion` | `api.v1.rh.onboarding.retroalimentar`<br>Rh\CicloLaboralRhController@retroalimentar | App: `src/api/rh/onboarding.ts:154` |  |
| POST | `v1/rh/onboarding/{proceso}/activos` | `api.v1.rh.onboarding.activos`<br>Rh\CicloLaboralRhController@entregarActivo | App: `src/api/rh/onboarding.ts:162` |  |
| POST | `v1/rh/onboarding/{proceso}/activos/vista-previa` | `api.v1.rh.onboarding.responsiva.vista-previa`<br>Rh\CicloLaboralRhController@vistaPreviaResponsiva | «sin consumidor conocido» |  |
| POST | `v1/rh/onboarding/{proceso}/completar` | `api.v1.rh.onboarding.completar`<br>Rh\CicloLaboralRhController@completar | App: `src/api/rh/onboarding.ts:167` |  |

### `api/v1/rh/organigrama`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/organigrama` | `api.v1.rh.organigrama`<br>Rh\EstructuraController@organigrama | App: `src/api/rh/cicloLaboral.ts:440` ⚠ cliente sin uso en pantallas |  |

### `api/v1/rh/pendientes`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/pendientes` | `api.v1.rh.pendientes`<br>Rh\PendienteController@index | App: `src/api/rh/pendientes.ts:17` | meta custom. Params: `per_page`, `tipo`, `q`, `sucursal_id`, `departamento_id`, `prioridad`, `page` |

### `api/v1/rh/plantilla`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/plantilla/cobertura` | `api.v1.rh.plantilla.cobertura`<br>Rh\EstructuraController@cobertura | App: `src/api/rh/cicloLaboral.ts:430` ⚠ cliente sin uso en pantallas |  |

### `api/v1/rh/plantillas-documentales`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/plantillas-documentales` | `api.v1.rh.plantillas-documentales.index`<br>Rh\PlantillaDocumentalController@index | App: `src/api/rh/cicloLaboral.ts:453` ⚠ cliente sin uso en pantallas |  |
| POST | `v1/rh/plantillas-documentales` | `api.v1.rh.plantillas-documentales.store`<br>Rh\PlantillaDocumentalController@store | «sin consumidor conocido» |  |
| GET | `v1/rh/plantillas-documentales/variables` | `api.v1.rh.plantillas-documentales.variables`<br>Rh\PlantillaDocumentalController@variables | «sin consumidor conocido» |  |
| PATCH | `v1/rh/plantillas-documentales/{plantilla}` | `api.v1.rh.plantillas-documentales.update`<br>Rh\PlantillaDocumentalController@update | «sin consumidor conocido» |  |

### `api/v1/rh/prestamos`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/prestamos` | `api.v1.rh.prestamos.index`<br>Rh\PrestamoController@index | App: `src/api/rh/cicloLaboral.ts:359` ⚠ cliente sin uso en pantallas | paginate() en PrestamoAutorizacionService::listar; el controlador arma la respuesta. Params: `per_page`, `estado`, `colaborador_id` |
| GET | `v1/rh/prestamos/{prestamo}` | `api.v1.rh.prestamos.show`<br>Rh\PrestamoController@show | App: `src/api/rh/cicloLaboral.ts:364` |  |
| POST | `v1/rh/prestamos/{prestamo}/documentos` | `api.v1.rh.prestamos.documentos`<br>Rh\PrestamoController@generarDocumentos | App: `src/api/rh/cicloLaboral.ts:379` |  |
| POST | `v1/rh/prestamos/{prestamo}/resguardar` | `api.v1.rh.prestamos.resguardar`<br>Rh\PrestamoController@resguardar | App: `src/api/rh/cicloLaboral.ts:384` |  |

### `api/v1/rh/reaperturas`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| POST | `v1/rh/reaperturas/{tipo}/{id}` | `api.v1.rh.reaperturas.store`<br>Rh\ReaperturaController@store | App: `src/api/rh/reaperturas.ts:6` |  |

### `api/v1/rh/recepcion-documental`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/recepcion-documental` | `api.v1.rh.recepcion-documental.index`<br>Rh\RecepcionDocumentalController@index | App: `src/api/rh/recepcionDocumental.ts:140` | paginate() en RecepcionDocumentalService::listarCortes; el controlador arma la respuesta |
| GET | `v1/rh/recepcion-documental/renglones/{renglon}` | `api.v1.rh.recepcion-documental.renglones.show`<br>Rh\RecepcionDocumentalController@renglon | «sin consumidor conocido» |  |
| PATCH | `v1/rh/recepcion-documental/renglones/{renglon}` | `api.v1.rh.recepcion-documental.renglones.marcar`<br>Rh\RecepcionDocumentalController@marcar | App: `src/api/rh/recepcionDocumental.ts:155` |  |
| GET | `v1/rh/recepcion-documental/{corte}` | `api.v1.rh.recepcion-documental.show`<br>Rh\RecepcionDocumentalController@show | App: `src/api/rh/recepcionDocumental.ts:145` |  |
| GET | `v1/rh/recepcion-documental/{corte}/renglones` | `api.v1.rh.recepcion-documental.renglones`<br>Rh\RecepcionDocumentalController@renglones | App: `src/api/rh/recepcionDocumental.ts:150` | paginate() en RecepcionDocumentalService::renglones; el controlador arma la respuesta |

### `api/v1/rh/recibos`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/recibos` | `api.v1.rh.recibos.index`<br>Rh\ReciboNominaController@index | App: `src/api/rh/cicloLaboral.ts:305` ⚠ cliente sin uso en pantallas | paginate() en ReciboNominaService::listar; el controlador arma la respuesta. Params: `per_page`, `periodo_inicio`, `periodo_fin`, `colaborador_id`, `lote`, `estado`, `q` |
| POST | `v1/rh/recibos/importar` | `api.v1.rh.recibos.importar`<br>Rh\ReciboNominaController@importar | App: `src/api/rh/cicloLaboral.ts:342` ⚠ cliente sin uso en pantallas |  |
| GET | `v1/rh/recibos/quincena` | `api.v1.rh.recibos.quincena`<br>Rh\ReciboNominaController@quincena | «sin consumidor conocido» |  |
| POST | `v1/rh/recibos/quincena/emitir` | `api.v1.rh.recibos.quincena.emitir`<br>Rh\ReciboNominaController@emitirQuincena | «sin consumidor conocido» |  |
| POST | `v1/rh/recibos/quincena/masivo` | `api.v1.rh.recibos.quincena.masivo`<br>Rh\ReciboNominaController@masivo | «sin consumidor conocido» |  |
| POST | `v1/rh/recibos/quincena/preparar` | `api.v1.rh.recibos.quincena.preparar`<br>Rh\ReciboNominaController@prepararQuincena | «sin consumidor conocido» |  |
| GET | `v1/rh/recibos/{recibo}` | `api.v1.rh.recibos.show`<br>Rh\ReciboNominaController@show | App: `src/api/rh/cicloLaboral.ts:310` ⚠ cliente sin uso en pantallas |  |
| PUT | `v1/rh/recibos/{recibo}` | `api.v1.rh.recibos.update`<br>Rh\ReciboNominaController@update | «sin consumidor conocido» |  |
| POST | `v1/rh/recibos/{recibo}/emitir` | `api.v1.rh.recibos.emitir`<br>Rh\ReciboNominaController@emitir | App: `src/api/rh/cicloLaboral.ts:325` ⚠ cliente sin uso en pantallas |  |
| GET | `v1/rh/recibos/{recibo}/pdf` | `api.v1.rh.recibos.pdf`<br>Rh\ReciboNominaController@pdf | App: `src/api/rh/cicloLaboral.ts:315` (ruta de descarga/visor) ⚠ cliente sin uso en pantallas |  |
| POST | `v1/rh/recibos/{recibo}/regenerar-pdf` | `api.v1.rh.recibos.regenerar-pdf`<br>Rh\ReciboNominaController@regenerarPdf | App: `src/api/rh/cicloLaboral.ts:319` ⚠ cliente sin uso en pantallas |  |

### `api/v1/rh/reingresos`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/reingresos` | `api.v1.rh.reingresos.index`<br>Rh\ReingresoController@index | App: `src/api/rh/reingresos.ts:14` | paginate() en ReingresoService::listar; el controlador arma la respuesta. Params: `per_page` |
| POST | `v1/rh/reingresos` | `api.v1.rh.reingresos.store`<br>Rh\ReingresoController@store | App: `src/api/rh/reingresos.ts:29` |  |
| GET | `v1/rh/reingresos/buscar` | `api.v1.rh.reingresos.buscar`<br>Rh\ReingresoController@buscar | App: `src/api/rh/reingresos.ts:19` |  |
| GET | `v1/rh/reingresos/historial/{colaborador}` | `api.v1.rh.reingresos.historial`<br>Rh\ReingresoController@historial | App: `src/api/rh/reingresos.ts:24` |  |
| POST | `v1/rh/reingresos/{reingreso}/decidir` | `api.v1.rh.reingresos.decidir`<br>Rh\ReingresoController@decidir | App: `src/api/rh/reingresos.ts:34` |  |

### `api/v1/rh/solicitudes`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/solicitudes` | `api.v1.rh.solicitudes.index`<br>Rh\SolicitudController@index | App: `src/api/aprobaciones.ts:30`, `src/api/rh/solicitudes.ts:20` ⚠ cliente sin uso en pantallas | meta custom. Params: `q`, `per_page`, `estado`, `tipo`, `sucursal_id`, `empresa_id`, `departamento_id` |
| GET | `v1/rh/solicitudes/{solicitud}` | `api.v1.rh.solicitudes.show`<br>Rh\SolicitudController@show | App: `src/api/rh/solicitudes.ts:25` |  |
| POST | `v1/rh/solicitudes/{solicitud}/aprobar` | `api.v1.rh.solicitudes.aprobar`<br>Rh\SolicitudController@aprobar | App: `src/api/rh/solicitudes.ts:37` |  |
| POST | `v1/rh/solicitudes/{solicitud}/correccion` | `api.v1.rh.solicitudes.correccion`<br>Rh\SolicitudController@correccion | App: `src/api/rh/solicitudes.ts:48` |  |
| PATCH | `v1/rh/solicitudes/{solicitud}/estado` | `api.v1.rh.solicitudes.estado`<br>Rh\SolicitudController@actualizarEstado | App: `src/api/rh/solicitudes.ts:64` |  |
| GET | `v1/rh/solicitudes/{solicitud}/permiso-pdf` | `api.v1.rh.solicitudes.permiso-pdf`<br>Rh\SolicitudController@permisoPdf | App (indirecto): abre la URL que arma el backend en `app/Api/V1/Rh/SolicitudController.php:105` |  |
| POST | `v1/rh/solicitudes/{solicitud}/prestamo/autorizar` | `api.v1.rh.solicitudes.prestamo.autorizar`<br>Rh\PrestamoController@autorizar | App: `src/api/rh/cicloLaboral.ts:369` |  |
| POST | `v1/rh/solicitudes/{solicitud}/prestamo/rechazar` | `api.v1.rh.solicitudes.prestamo.rechazar`<br>Rh\PrestamoController@rechazar | App: `src/api/rh/cicloLaboral.ts:374` |  |
| POST | `v1/rh/solicitudes/{solicitud}/rechazar` | `api.v1.rh.solicitudes.rechazar`<br>Rh\SolicitudController@rechazar | App: `src/api/rh/solicitudes.ts:43` |  |
| GET | `v1/rh/solicitudes/{solicitud}/vista-previa/{documento}` | `api.v1.rh.solicitudes.vista-previa`<br>Rh\SolicitudController@vistaPreviaDocumento | App (indirecto): abre la URL que arma el backend en `app/Api/V1/Rh/SolicitudController.php:110` |  |

### `api/v1/rh/tablero`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/tablero` | `api.v1.rh.tablero`<br>Rh\CicloLaboralRhController@tablero | «sin consumidor conocido» |  |

### `api/v1/rh/vacaciones`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/vacaciones` | `api.v1.rh.vacaciones.index`<br>Rh\VacacionController@index | App: `src/api/rh/vacaciones.ts:30` ⚠ cliente sin uso en pantallas | meta custom. Params: `q`, `per_page`, `estado`, `sucursal_id`, `empresa_id` |
| GET | `v1/rh/vacaciones/{vacacion}` | `api.v1.rh.vacaciones.show`<br>Rh\VacacionController@show | App: `src/api/rh/vacaciones.ts:35` |  |
| POST | `v1/rh/vacaciones/{vacacion}/aprobar` | `api.v1.rh.vacaciones.aprobar`<br>Rh\VacacionController@aprobar | App: `src/api/rh/vacaciones.ts:40` |  |
| POST | `v1/rh/vacaciones/{vacacion}/rechazar` | `api.v1.rh.vacaciones.rechazar`<br>Rh\VacacionController@rechazar | App: `src/api/rh/vacaciones.ts:45` |  |

### `api/v1/rh/vacantes`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/rh/vacantes` | `api.v1.rh.vacantes.index`<br>Rh\VacanteController@index | App: `src/api/rh/vacantes.ts:24` ⚠ cliente sin uso en pantallas | paginate() vía Resource::collection → {data, links, meta}. Params: `estado`, `sucursal_id`, `departamento_id`, `busqueda`, `per_page` |
| GET | `v1/rh/vacantes/{vacante}` | `api.v1.rh.vacantes.show`<br>Rh\EstructuraController@vacante | App: `src/api/rh/cicloLaboral.ts:447` ⚠ cliente sin uso en pantallas |  |

### `api/v1/solicitudes`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/solicitudes` | `api.v1.solicitudes.index`<br>SolicitudController@index | App: `src/api/solicitudes.ts:26`, `src/api/solicitudes.ts:32` | meta custom |
| POST | `v1/solicitudes` | `api.v1.solicitudes.store`<br>SolicitudController@store | App: `src/api/solicitudes.ts:48` |  |
| GET | `v1/solicitudes/configuracion` | `api.v1.solicitudes.configuracion`<br>SolicitudController@configuracion | App: `src/api/solicitudes.ts:100` |  |
| GET | `v1/solicitudes/prestamo/elegibilidad` | `api.v1.solicitudes.prestamo.elegibilidad`<br>SolicitudController@elegibilidadPrestamo | App: `src/api/solicitudes.ts:110` |  |
| GET | `v1/solicitudes/{solicitud}` | `api.v1.solicitudes.show`<br>SolicitudController@show | App: `src/api/solicitudes.ts:42` |  |
| POST | `v1/solicitudes/{solicitud}/adjuntos` | `api.v1.solicitudes.adjuntos`<br>SolicitudController@adjuntos | App: `src/api/solicitudes.ts:85` |  |
| POST | `v1/solicitudes/{solicitud}/cancelar` | `api.v1.solicitudes.cancelar`<br>SolicitudController@cancelar | App: `src/api/solicitudes.ts:59` |  |
| GET | `v1/solicitudes/{solicitud}/permiso-pdf` | `api.v1.solicitudes.permiso-pdf`<br>SolicitudController@permisoPdf | App: `src/api/solicitudes.ts:38` (ruta de descarga/visor) |  |
| POST | `v1/solicitudes/{solicitud}/responder` | `api.v1.solicitudes.responder`<br>CorreccionController@responder | App: `src/api/correcciones.ts:30` |  |

### `api/v1/tareas`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/tareas` | `api.v1.tareas.index`<br>TareaController@index | App: `src/api/trabajo.ts:86` | paginate() en TareaService::bandeja; el controlador arma la respuesta. Params: `per_page` |
| POST | `v1/tareas/{tarea}/leer` | `api.v1.tareas.leer`<br>TareaController@leer | App: `src/api/trabajo.ts:92` |  |
| POST | `v1/tareas/{tarea}/resolver` | `api.v1.tareas.resolver`<br>TareaController@resolver | App: `src/api/trabajo.ts:97` |  |

### `api/v1/vacaciones`

| Método | Ruta | Nombre / Controlador@método | Consumidores | Notas de contrato |
|---|---|---|---|---|
| GET | `v1/vacaciones/saldo` | `api.v1.vacaciones.saldo`<br>VacacionesController@saldo | App: `src/api/vacaciones.ts:27` |  |
| GET | `v1/vacaciones/solicitudes` | `api.v1.vacaciones.solicitudes.index`<br>VacacionesController@solicitudes | «sin consumidor conocido» | Legacy (tabla `solicitudes_vacaciones`). Se conserva para APKs publicadas — ver `docs/API_MOVIL.md` § «vacaciones/* … legacy». |
| POST | `v1/vacaciones/solicitudes` | `api.v1.vacaciones.solicitudes.store`<br>VacacionesController@storeSolicitud | «sin consumidor conocido» | Legacy (tabla `solicitudes_vacaciones`). Se conserva para APKs publicadas — ver `docs/API_MOVIL.md`. |

## Resumen

### Conteo por consumidor

| Consumidor | Endpoints |
|---|---|
| Total de endpoints API inventariados | 398 (10 `api/integracion/v1` + 388 `api/v1`) |
| App actual — llamada directa encontrada | 277 |
| ↳ de esos, con al menos una pantalla que la dispara | 235 |
| ↳ de esos, solo con cliente sin uso en pantallas (⚠) | 42 |
| App actual — solo indirecto (URL armada por el backend) | 9 |
| Auditoría | 7 (todos en `api/v1/auditoria/*`) |
| Reportes | 0 (no tiene cliente de People en su código) |
| Sin consumidor conocido | 105 |
| Consumidos por más de un sistema | 0 |

### Endpoints consumidos por más de un sistema

**Ninguno.** Los tres consumidores no se traslapan: Auditoría usa solo `api/v1/auditoria/*` (que la app no llama) y Reportes no llama nada todavía. El riesgo de cambio más alto, entonces, está en:

1. **Contrato de Auditoría** (`api/v1/auditoria/catalogo`, `colaboradores`, `colaboradores/{colaborador}/acta`, `sucursales`, `sucursales/{sucursal}/acta`, `puestos`, `departamentos`): servidor a servidor, `people:sincronizar` cada 2 minutos, y Auditoría **rechaza la página** si `data` no es lista o falta `meta` (`SincronizadorPeople.php:215-218`); avanza con `meta.next_cursor`. Contrato versionado `people-auditoria/1..3` — ver `docs/integraciones/AUDITORIA.md`.
2. **Arranque y sesión de la app** (`login`, `logout`, `me`, `mobile/bootstrap`, `app/config`, `app/theme`, `app/releases/latest`, `reautenticar`, `cambiar-contrasena`, `dispositivos/push-token`): los llaman **todas** las versiones instaladas, no solo la actual; `app/config`, `app/theme`, `app/releases*`, `login` e `incorporacion/invitaciones/*` son públicos (sin Sanctum).
3. **Rutas cuyo nombre arma URLs en respuestas** (`route('api.v1.…')`): fotos (`colaborador.foto`, `colaborador.foto.propuesta`, `colaboradores.foto`, `rh.colaboradores.foto`, `rh.cambios-foto.propuesta`, `rh.cumpleanos.foto`), muro de cumpleaños/celebraciones, `induccion.pdf`, `solicitudes.permiso-pdf`, `rh.solicitudes.permiso-pdf`/`vista-previa`, `solicitudes.show`/`responder`. Cambiar la URI **o el nombre** rompe imágenes/PDF en la app aunque ningún literal de la app cambie.
4. **Legacy para APKs viejas**: `vacaciones/*` (tabla `solicitudes_vacaciones`), `rh/formatos*` y `rh/formatos-oficiales*` (410), `colaborador/incorporacion/resumen` (alias).

### Clientes de la app sin uso en pantallas (⚠)

El endpoint tiene función en `src/api/**` (y a veces hook), pero ninguna pantalla actual la dispara. Siguen siendo contrato (APKs anteriores / pantallas futuras):

- `GET /api/v1/app/releases`
- `GET /api/v1/avisos`
- `PATCH /api/v1/celebraciones/{celebracion}/mensajes/{mensaje}`
- `DELETE /api/v1/celebraciones/{celebracion}/mensajes/{mensaje}`
- `GET /api/v1/colaborador/alta`
- `GET /api/v1/colaborador/documentos-pendientes`
- `GET /api/v1/colaborador/foto/estado`
- `GET /api/v1/colaborador/notificaciones`
- `GET /api/v1/colaborador/solicitudes`
- `POST /api/v1/colaborador/solicitudes`
- `GET /api/v1/colaborador/vacaciones`
- `GET /api/v1/correcciones/resumen`
- `POST /api/v1/notificaciones/{notificacion}/leer`
- `GET /api/v1/rh/catalogos`
- `GET /api/v1/rh/celebraciones/aniversarios`
- `GET /api/v1/rh/celebraciones/{colaborador}/{tipo}`
- `POST /api/v1/rh/celebraciones/{colaborador}/{tipo}/avisar-todos`
- `GET /api/v1/rh/cierres`
- `POST /api/v1/rh/colaboradores`
- `POST /api/v1/rh/colaboradores/{colaborador}/cierres`
- `POST /api/v1/rh/colaboradores/{colaborador}/recibos`
- `GET /api/v1/rh/cumpleanos/{colaborador}/foto`
- `GET /api/v1/rh/cumpleanos/{greeting}/imagen`
- `GET /api/v1/rh/documentos`
- `GET /api/v1/rh/documentos-laborales/pendientes`
- `GET /api/v1/rh/expedientes`
- `GET /api/v1/rh/incorporaciones`
- `GET /api/v1/rh/indicadores`
- `GET /api/v1/rh/jerarquia-puestos`
- `GET /api/v1/rh/organigrama`
- `GET /api/v1/rh/plantilla/cobertura`
- `GET /api/v1/rh/plantillas-documentales`
- `GET /api/v1/rh/prestamos`
- `GET /api/v1/rh/recibos`
- `POST /api/v1/rh/recibos/importar`
- `GET /api/v1/rh/recibos/{recibo}`
- `POST /api/v1/rh/recibos/{recibo}/emitir`
- `GET /api/v1/rh/recibos/{recibo}/pdf`
- `POST /api/v1/rh/recibos/{recibo}/regenerar-pdf`
- `GET /api/v1/rh/vacaciones`
- `GET /api/v1/rh/vacantes`
- `GET /api/v1/rh/vacantes/{vacante}`

### Sin consumidor conocido

No se encontró llamada en la app actual, Auditoría ni Reportes, ni URL armada por el backend que la app abra. **No implica que se pueda borrar** (ver introducción).

- `GET /api/integracion/v1/capacidades`
- `GET /api/integracion/v1/colaboradores`
- `GET /api/integracion/v1/colaboradores/{colaborador}`
- `GET /api/integracion/v1/departamentos`
- `GET /api/integracion/v1/empresas`
- `GET /api/integracion/v1/movimientos/altas`
- `GET /api/integracion/v1/movimientos/bajas`
- `GET /api/integracion/v1/puestos`
- `GET /api/integracion/v1/rotacion`
- `GET /api/integracion/v1/sucursales`
- `GET /api/v1/auditoria/colaboradores/{colaborador}`
- `GET /api/v1/avisos/{aviso}/imagen`
- `POST /api/v1/broadcasting/auth`
- `GET /api/v1/celebraciones/{celebracion}/mensajes/{mensaje}/autor-foto`
- `GET /api/v1/celebraciones/{celebracion}/mensajes/{mensaje}/foto`
- `POST /api/v1/colaborador/carta-compromiso/solicitar`
- `GET /api/v1/colaborador/incorporacion/resumen`
- `POST /api/v1/colaborador/induccion/audio/{avance}/progreso`
- `GET /api/v1/colaborador/induccion/capitulos/{avance}`
- `POST /api/v1/colaborador/induccion/capitulos/{avance}/progreso`
- `GET /api/v1/colaborador/induccion/organigrama/{vista}`
- `POST /api/v1/colaborador/onboarding/avances/{avance}/visto`
- `POST /api/v1/evaluaciones/excepciones/{solicitud}/resolver`
- `POST /api/v1/evaluaciones/{evaluacion}/excepcion`
- `GET /api/v1/incorporacion/invitaciones/{token}/fases`
- `GET /api/v1/rh/actas/testigos`
- `PATCH /api/v1/rh/actas/{acta}`
- `POST /api/v1/rh/actas/{acta}/anexos`
- `POST /api/v1/rh/actas/{acta}/cerrar`
- `POST /api/v1/rh/actas/{acta}/documento`
- `POST /api/v1/rh/actas/{acta}/entrega`
- `POST /api/v1/rh/actas/{acta}/negativa-firma`
- `POST /api/v1/rh/actas/{acta}/seguimiento`
- `GET /api/v1/rh/actas/{acta}/vista-previa`
- `POST /api/v1/rh/bajas`
- `GET /api/v1/rh/bajas/catalogo`
- `PUT /api/v1/rh/bajas/{baja}`
- `POST /api/v1/rh/bajas/{baja}/documentos`
- `POST /api/v1/rh/bajas/{baja}/enviar`
- `POST /api/v1/rh/bajas/{baja}/rechazar`
- `POST /api/v1/rh/candidatos/{candidato}/evidencias`
- `POST /api/v1/rh/celebraciones/evento/{celebracion}/recepcion`
- `POST /api/v1/rh/celebraciones/{colaborador}/{tipo}/tarjeta`
- `GET /api/v1/rh/ciclo/pendientes`
- `POST /api/v1/rh/cierres/{cierre}/cerrar`
- `POST /api/v1/rh/cierres/{cierre}/cita`
- `POST /api/v1/rh/cierres/{cierre}/finiquito/conceptos-automaticos`
- `POST /api/v1/rh/cierres/{cierre}/finiquito/entrega`
- `POST /api/v1/rh/cierres/{cierre}/pago/programar`
- `POST /api/v1/rh/cierres/{cierre}/procedimiento/etapa`
- `POST /api/v1/rh/colaboradores/{colaborador}/actas`
- `POST /api/v1/rh/colaboradores/{colaborador}/contratos/firmado`
- `POST /api/v1/rh/colaboradores/{colaborador}/credenciales`
- `GET /api/v1/rh/colaboradores/{colaborador}/descriptivo-puesto`
- `POST /api/v1/rh/colaboradores/{colaborador}/descriptivo-puesto/enviar`
- `POST /api/v1/rh/colaboradores/{colaborador}/documentos-laborales`
- `GET /api/v1/rh/colaboradores/{colaborador}/solicitud-empleo`
- `POST /api/v1/rh/colaboradores/{colaborador}/solicitud-empleo/{seccion}`
- `GET /api/v1/rh/correcciones/colaboradores/{colaborador}/historial`
- `GET /api/v1/rh/correcciones/{dominio}/{id}`
- `POST /api/v1/rh/correcciones/{dominio}/{id}`
- `GET /api/v1/rh/correcciones/{dominio}/{id}/historial`
- `POST /api/v1/rh/correcciones/{dominio}/{id}/vista-previa`
- `GET /api/v1/rh/documentos-edicion/{tipo}/{id}/campos`
- `POST /api/v1/rh/documentos-edicion/{tipo}/{id}/emitir`
- `GET /api/v1/rh/documentos-edicion/{tipo}/{id}/versiones`
- `POST /api/v1/rh/documentos-edicion/{tipo}/{id}/vista-previa`
- `POST /api/v1/rh/documentos-proceso/documento/{documento}/{accion}`
- `GET /api/v1/rh/documentos-proceso/previa/{flujo}/{id}`
- `GET /api/v1/rh/documentos-proceso/previa/{flujo}/{id}/pdf`
- `GET /api/v1/rh/documentos-proceso/testigos`
- `GET /api/v1/rh/documentos-proceso/{tipo}/{id}/vista-previa`
- `ANY /api/v1/rh/formatos/{retirado?}`
- `ANY /api/v1/rh/formatos-oficiales/{retirado?}`
- `GET /api/v1/rh/induccion/intentos/{intento}`
- `POST /api/v1/rh/induccion/intentos/{intento}/calificar`
- `GET /api/v1/rh/induccion/intentos/{intento}/pdf`
- `GET /api/v1/rh/induccion/intentos/{intento}/pdf/descargar`
- `POST /api/v1/rh/induccion/intentos/{intento}/pdf/expediente`
- `POST /api/v1/rh/induccion/intentos/{intento}/recalificar`
- `GET /api/v1/rh/induccion/sesiones`
- `POST /api/v1/rh/induccion/sesiones`
- `GET /api/v1/rh/induccion/sesiones/colaboradores`
- `POST /api/v1/rh/induccion/sesiones/{sesion}/cancelar`
- `POST /api/v1/rh/induccion/sesiones/{sesion}/enviar`
- `POST /api/v1/rh/induccion/{evaluacion}/retroalimentacion`
- `GET /api/v1/rh/nomina/lotes`
- `POST /api/v1/rh/nomina/lotes`
- `GET /api/v1/rh/nomina/lotes/{lote}`
- `POST /api/v1/rh/nomina/lotes/{lote}/cancelar`
- `POST /api/v1/rh/nomina/lotes/{lote}/emitir`
- `GET /api/v1/rh/nomina/lotes/{lote}/imprimir`
- `POST /api/v1/rh/onboarding/{proceso}/activos/vista-previa`
- `POST /api/v1/rh/plantillas-documentales`
- `GET /api/v1/rh/plantillas-documentales/variables`
- `PATCH /api/v1/rh/plantillas-documentales/{plantilla}`
- `GET /api/v1/rh/recepcion-documental/renglones/{renglon}`
- `GET /api/v1/rh/recibos/quincena`
- `POST /api/v1/rh/recibos/quincena/emitir`
- `POST /api/v1/rh/recibos/quincena/masivo`
- `POST /api/v1/rh/recibos/quincena/preparar`
- `PUT /api/v1/rh/recibos/{recibo}`
- `GET /api/v1/rh/tablero`
- `GET /api/v1/vacaciones/solicitudes`
- `POST /api/v1/vacaciones/solicitudes`

### Notas de mantenimiento

- `api/integracion/v1/*` (10 endpoints) está lista y documentada en `docs/API_INTEGRACION_SISTEMAS.md` para Reportes, pero el repositorio `Reportes` todavía no la llama: su `.env`/config no tiene `PEOPLE_API_*` ni cliente HTTP hacia People (la integración que sí existe va en otro sentido: Auditoría consulta la API propia de Reportes, `CatalogoReportes.php`).
- `rh/induccion/sesiones*` está detrás del feature flag `induccion_sesiones_legacy`.
- `broadcasting/auth` (Closure) no aparece en la app actual (no hay `authEndpoint` ni `broadcasting` en `src/`).
- Para actualizar este inventario: repetir `route:list --json`, la extracción de literales de `src/api/**` y la búsqueda en `auditoria/backend/app/Support/People/*`; anotar aquí la fecha.
