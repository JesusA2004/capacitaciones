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

## Qué NO hacer

- ❌ Calcular el saldo de vacaciones dentro de un controlador (web o API) y volver a calcularlo distinto en el otro.
- ❌ Un controlador "mezclado" que a veces responde Inertia y a veces JSON según el header de la petición — cada salida tiene su propio controlador (`Rh\SolicitudController` vs `Api\V1\SolicitudController`), ambos livianos, ambos llamando al mismo `SolicitudesService`.
- ❌ Consultas Eloquent complejas directamente en el controlador — si una consulta tiene `whereHas`/joins/agrupaciones, vive en un Service (ver `ReportesRhService` para el ejemplo más grande del proyecto).
