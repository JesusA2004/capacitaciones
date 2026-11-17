# Depuración de código muerto — noviembre 2026

Fuente: auditoría general de MR. LANA PEOPLE (sección 2.1/2.2 y matriz de retiro). Este documento registra, candidato por candidato, la clase asignada y la evidencia. Solo se retiraron los de clase **A**.

## Clases

| Clase | Significado |
| --- | --- |
| **A** | Eliminación segura: sin consumidores estáticos, dinámicos, rutas, comandos, pruebas ni clientes externos. |
| **B** | Requiere sustitución previa (se hizo la sustitución; el retiro queda para después). |
| **C** | Compatibilidad temporal: se conserva un tiempo por enlaces guardados, apps viejas o notificaciones en producción. |
| **D** | Conservación obligatoria: tiene uso vivo o pertenece a una fase vigente. |
| **E** | Pendiente de validación: falta un dato que no está en este repo (producción, negocio). |

## Cómo se verificó

- `grep` de palabra completa en `resources/js`, `app`, `routes`, `config`, `database`, `tests`, `bootstrap` (imports estáticos, strings de clase, `Inertia::render`, `app()`/`resolve`).
- No hay `import.meta.glob` en `resources/js` (no hay carga dinámica de componentes).
- `php artisan route:list` para las rutas; tabla `notifications` (BD local, 652 filas) buscando `data` con `/avisos`, `periodos-prueba`, `/formatos`, `/plantillas` y `formatos-oficiales`: **0 coincidencias en local** (producción no se revisó).
- Después de cada retiro: `vue-tsc --noEmit`, ESLint de los archivos tocados, `vite build`, PHPStan (Larastan nivel 7) y las pruebas existentes relacionadas.

## Clasificación

| Candidato | Clase | Evidencia | Acción |
| --- | --- | --- | --- |
| `components/Dashboard/GraficasOrganizacion.vue` | **A** | Sin importadores desde `3de1aaff` (jul-2026), cuando `Dashboard/Global` y `Sucursal` dejaron de usarlo. La portada de Capacitación de la Fase 3 (`pages/Capacitacion/Index.vue`) ya tiene sus propias gráficas. El nombre solo queda como tipo en `types/reportes.ts`. | Retirado |
| Cadena de GraficasOrganizacion: `DashboardHero`, `CompletionDonutCard`, `ComplianceHeroCard`, `OverdueAlertCard`, `BranchComplianceChart`, `DashboardSection`, `KpiCard`, `MiniSparkline`, `TrendIndicator`, `ProgressRing` | **A** | Solo se importaban entre ellos o desde GraficasOrganizacion. | Retirados |
| `components/Dashboard/MetricCard.vue` | **D** | Lo usa `DataTable/CrudStats.vue` (7 pantallas de administración y RH). La auditoría lo daba por muerto; no lo está. | Conservado (solo se corrigió su comentario) |
| `components/Dashboard/DashboardChartCard.vue`, `EmptyDashboardState.vue` | **D** | Los usan Capacitación, Recepción documental y Sucursales/Show. | Conservados |
| `components/Dashboard/ProximasSesionesCard.vue` (+ tipo `SesionProximaItem`) | **D** | Es de Capacitación (sesiones en vivo). Hoy no se importa, pero la Fase 3 está vigente y su agente trabaja en esa UI. | Conservado |
| `components/rotacion/KpiRotacion.vue` | **A** | 0 importadores, nunca se usó en el tablero de rotación. | Retirado |
| `components/AppLogoIcon.vue` | **D** | Lo importa `pages/Constancias/Verificar.vue` (cambio en curso del agente de Capacitación). La auditoría lo daba por muerto antes de ese cambio. | Conservado |
| `components/Solicitudes/CadenaAutorizacion.vue` | **A** (tras sustitución) | No se renderiza en ningún lado (la cadena la pinta `FlujoAprobacion`); solo se importaba su tipo `NivelVistoBueno` desde `Rh/Solicitudes/Show.vue`. El tipo se movió a `types/solicitudes.ts`. La ruta `rh.solicitudes.visto-bueno` sigue viva (no se tocó). | Retirado |
| `Services/Formatos/FormatoCatalogoService.php` | **A** | 0 referencias (ni contenedor, ni comandos, ni pruebas). Sus controladores (`Rh\FormatoController`, `Api\V1\Rh\FormatoController`) ya no existen; las rutas responden 410. | Retirado |
| `Services/Plantillas/DocumentoWordGeneradoService.php` | **A** | 0 referencias; sus únicos consumidores eran los controladores de formatos ya retirados. | Retirado |
| `Services/Plantillas/PlantillaResolverService.php` | **A** | 0 referencias. | Retirado |
| `Services/Formatos/Analisis/*` | **D** | `PlantillaOficialService` lo usa, y a su vez lo usa el comando `ImportarFormatosOriginalesCommand`; `tests/Feature/Rh/FormatoOficialTest.php` usa `AnalizadorImagenOcr`. | Conservado |
| `Formatos/Motor/RenderizadorFormato`, `NormalizadorBaseFormato`, `Plantillas/VariableMappingService` | **D** | Los inyectan `GeneradorFormatoService`, `PlantillaOficialService` y `FormatoPreviewService` (este último lo usa `ConversorDocxPdf`). La auditoría los daba por muertos; no lo están. | Conservados |
| `types/formatos-oficiales.ts` (13 de 14 tipos) | **A** | Ninguno de los 13 se importa fuera del archivo; solo vive `FormatoOficialItem` (`Rh/Solicitudes/Show.vue`). | Se retiraron los 13; se conserva `FormatoOficialItem` |
| `types/plantillas.ts` (3 de 4 tipos) | **A** | `PlantillaItem`, `FormatoCatalogoItem` y `OpcionesPlantillas` sin importadores; `DocumentoGeneradoItem` lo usa `types/solicitudes.ts`. | Se retiraron los 3 |
| Redirecciones y 410 de `rh.plantillas.*`, `rh.formatos.*`, `rh.formatos-oficiales.*` (`routes/rh.php` ~409-434) | **C** | Protegen marcadores y enlaces viejos con un costo mínimo. En la BD local no hay notificaciones con esas URLs, pero producción y los logs de acceso no se revisaron. La auditoría recomienda conservar el 410 un trimestre más. `descargar`/`previsualizar` siguen vivos (los enlaza el expediente). | Conservadas. Se pueden retirar en feb-2027 si los logs de acceso no tienen hits (y quitarlas de `PerfilarRutasCommand`). |
| `rh.formatos-oficiales.subir-firmado` | **E** | Sin llamadas en el front, y va contra la regla de «no se suben escaneos», pero puede haber generaciones pendientes de subir su firmado en producción. | Conservada; validar en producción |
| `api.v1.rh.formatos*.retirado` (410 en `routes/api.php`) | **D** | Regla: no se retira ninguna ruta API (app, versiones viejas instaladas, Reportes, Auditoría). | Conservadas |
| `avisos.index` (GET `/avisos` → Mis notificaciones) | **B → C** | La única referencia interna era la guía (`lib/guia/articulos/vida-laboral.ts`). Ya se cambió para que apunte a `/mis-notificaciones`. La redirección se conserva por la app vieja y favoritos (documentado en `Colaborador/AvisoController`). Las otras rutas de `routes/avisos.php` (marcar leído, archivo) siguen vivas. | Guía actualizada; ruta conservada |
| `rh.periodos-prueba.index` (redirige a Evaluaciones) | **C** | Sin enlaces en el front ni en `app/`; 0 notificaciones locales con esa URL. Falta revisar `notifications.data->url` en producción. | Conservada |
| Entradas `href: '#'` del menú | **D** | Las reemplazan los agentes de Nine Box y Evaluaciones técnicas. | No se tocaron |

## Commits

| Commit | Qué retira |
| --- | --- |
| `d51f275d` | Cadena de GraficasOrganizacion (11 componentes), `KpiRotacion.vue`, `CadenaAutorizacion.vue` (su tipo pasa a `types/solicitudes.ts`). |
| `8f2e634b` | 16 tipos TS sin uso de `types/formatos-oficiales.ts` y `types/plantillas.ts`. |
| `92b1220d` | Guía de Avisos → `/mis-notificaciones` (sustitución previa; la ruta se conserva). |
| `7f91131d` | Los tres servicios PHP (`FormatoCatalogoService`, `DocumentoWordGeneradoService`, `PlantillaResolverService`). Entraron por accidente en el commit de `docs/API_CONSUMIDORES.md` de otro agente, porque los borrados ya estaban en el índice compartido. No se reescribió la historia. Verificado con PHPStan sobre `Services/Formatos`, `Services/Plantillas`, `Services/DocumentosLaborales`, `FormatoOficialController` e `ImportarFormatosOriginalesCommand` (0 errores), y con `FormatoOficialTest` y `VistaPreviaContratoExpedienteTest` (8/8). |

## Dependencias

Solo se reportan; no se retiró ninguna.

| Paquete | Clase | Evidencia |
| --- | --- | --- |
| npm `puppeteer` | **D** | No se importa en JS, pero lo usa `spatie/browsershot` (PDF) y lo revisa `DiagnosticoPdfCommand`. |
| npm `@unovis/ts`, `apexcharts` | **D** | Dependencias par de `@unovis/vue` y `vue3-apexcharts`, que sí se usan. |
| npm `tw-animate-css` | **D** | `@import` en `resources/css/app.css`. |
| composer `laravel/chisel` | **E** | 0 referencias en `app`, `config`, `routes`, `bootstrap`. Es un toolkit para scripts que quitan código del starter kit; parece innecesario en `require` (producción). Moverlo o quitarlo cambia `composer.lock`: lo debe decidir quien despliega. |
| composer `laravel/pao` (dev) | **D** | Salida de pruebas para agentes; es de desarrollo y no afecta producción. |
| Demás paquetes de composer/npm | **D** | Todos tienen uso en el código (`smalot/pdfparser`, `minishlink/web-push`, `setasign/fpdi-fpdf`, `laravel/reverb`, `laravel-echo`/`pusher-js`, `hls.js`, `pdfjs-dist`, `sweetalert2`, `vue-draggable-plus`, `date-fns`). |

## Pendientes que no son de esta ronda

- `types/reportes.ts`: `GraficasOrganizacion`, `SesionProximaItem` y los tipos de las gráficas del dashboard viejo quedan sin importadores en el front. El backend (`MetricasDashboardService::graficasOrganizacion()`) todavía puede calcularlos; eso es del dashboard (agente de UX) y de Capacitación.
- `docs/PLANTILLAS_FORMATOS.md` y `docs/RH_MOBILE_API.md` todavía mencionan los servicios retirados como parte del motor anterior (contexto histórico).
