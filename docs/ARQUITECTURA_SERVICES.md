# Arquitectura: Services compartidos entre Inertia y API

Regla de oro del proyecto (sección 2 del encargo "Mr. Lana People — Fase 1, cierre"): **la lógica de negocio vive en Services, nunca en controladores**, para poder tener dos salidas (web Inertia y API móvil, ver `docs/API_MOVIL.md`) sin duplicar nada.

## Estructura

```
app/
  Services/
    Rh/                  (AlcanceOrganizacionalService, RolPermisoService — transversales)
    Reclutamiento/        CvStorageService
    Expedientes/          ExpedienteService, DocumentoStorageService
    Vacaciones/            VacacionesService
    Solicitudes/           SolicitudesService, SolicitudDocumentoStorageService
    Reportes/               MetricasRhDashboardService, ReportesRhService
    Plantillas/              PlaceholderResolver, PlantillaDocumentoService, PlantillaStorageService
    Colaboradores/            ColaboradorPerfilService, NotificacionesService
    AltaDigital/               AltaDigitalStorageService, ConversionColaboradorService
    Onboarding/                  OnboardingService
  Http/
    Controllers/
      Rh/                 Controladores web Inertia (RH)
      Solicitudes/        Controlador web Inertia (colaborador)
      Api/V1/             Controladores API (misma lógica, respuesta JSON)
    Requests/
      Rh/, Solicitudes/, Vacaciones/  FormRequest — se reutilizan tal cual entre web y API cuando el `authorize()` no depende de un parámetro de ruta exclusivo de la web
    Resources/
      Api/V1/             JSON Resources — solo para la API
```

`App\Services\AlcanceOrganizacionalService` no vive bajo `Rh/` por historia del proyecto (es anterior a esta convención) — es transversal a todos los módulos y se queda en la raíz de `Services/`, documentado aquí para que quede claro que no se movió por descuido.

## Regla por tipo de controlador

- **Controladores Inertia** (`Http/Controllers/Rh`, `Http/Controllers/Solicitudes`, `VacacionesController`, etc.): autorizan (`$this->authorize()` / Policy), validan (`FormRequest`), llaman al Service, devuelven `Inertia::render()` o `redirect()`/`back()`. Nada de cálculos ni consultas complejas.
- **Controladores API** (`Http\Controllers\Api\V1\*`): autorizan (Policy o "siempre el usuario autenticado"), validan (mismo `FormRequest` que la web cuando aplica), llaman **al mismo Service**, devuelven JSON (`response()->json()` o un `JsonResource`).

### Ejemplo real de este proyecto

```php
// VacacionesService — un solo lugar con la lógica
class VacacionesService
{
    public function saldo(User $usuario): array { /* ... */ }
    public function misSolicitudes(User $usuario): Collection { /* ... */ }
    public function solicitar(User $usuario, array $datos): SolicitudVacaciones { /* ... */ }
}

// Web — App\Http\Controllers\VacacionesController
public function store(StoreSolicitudVacacionesRequest $request): RedirectResponse
{
    $this->vacaciones->solicitar($request->user(), $request->validated());
    return back()->with('toast', [...]);
}

// API — App\Http\Controllers\Api\V1\VacacionesController
public function storeSolicitud(StoreSolicitudVacacionesRequest $request): JsonResponse
{
    $solicitud = $this->vacaciones->solicitar($request->user(), $request->validated());
    return response()->json(new SolicitudVacacionesResource($solicitud), 201);
}
```

Ambos controladores llaman exactamente a `VacacionesService::solicitar()` — el cálculo de saldo disponible y la validación de negocio (`ValidationException` si no alcanzan los días) viven en un único lugar.

El mismo patrón aplica a `SolicitudesService` (solicitudes internas, ver `docs/SOLICITUDES_INTERNAS.md`) y a `NotificacionesService` (compartida entre el layout web y la API).

## Patrón de listados con filtros + exportación (Fase 1, cierre)

Los 8 listados operativos de RH (Vacantes, Candidatos, Altas digitales, Plantillas,
Formatos, Solicitudes, Expedientes, Vacaciones) siguen el mismo patrón para que la
exportación Excel/PDF respete *exactamente* los filtros activos en pantalla, sin
duplicar la consulta:

1. Un método privado `queryFiltrada(Request $request): Builder` (o, cuando el listado ya
   tenía un Service dedicado como `SolicitudesService`, un método del propio Service)
   aplica todos los `when()` de filtro + `AlcanceOrganizacionalService`.
2. `index()` pagina/obtiene esa misma query.
3. `exportarExcel()`/`exportarPdf()` recorren la misma query sin paginar, arman
   `{columnas, filas}` y reutilizan `App\Exports\ReporteRhExport` (Excel) y la vista
   genérica `resources/views/pdf/reporte-rh.blade.php` (PDF) — **no se crean clases
   Export ni vistas Blade nuevas por módulo**, ambas piezas ya son genéricas desde que
   se escribieron para `Rh\ReporteRhController`.
4. El frontend arma la URL de exportación con los mismos `filtros` reactivos que ya usa
   `useFiltros()` para el listado (`urlExportar()` en cada `Index.vue`), así que nunca
   hay dos fuentes de verdad de qué filtro está activo.

## Qué NO hacer

- ❌ Calcular el saldo de vacaciones dentro de un controlador (web o API) y volver a calcularlo distinto en el otro.
- ❌ Un controlador "mezclado" que a veces responde Inertia y a veces JSON según el header de la petición — cada salida tiene su propio controlador (`Rh\SolicitudController` vs `Api\V1\SolicitudController`), ambos livianos, ambos llamando al mismo `SolicitudesService`.
- ❌ Consultas Eloquent complejas directamente en el controlador — si una consulta tiene `whereHas`/joins/agrupaciones, vive en un Service (ver `ReportesRhService` para el ejemplo más grande del proyecto).
