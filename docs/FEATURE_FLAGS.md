# Feature flags

El portal usa banderas de configuración para activar/desactivar módulos completos sin borrar código, datos ni rutas. Viven en `config/features.php` y se controlan por variable de entorno — **nunca uses `env()` fuera de ese archivo de config**.

```php
// config/features.php
return [
    'rh_portal' => env('RH_PORTAL_ENABLED', true),
    'capacitacion' => env('CAPACITACION_ENABLED', false),
];
```

```env
RH_PORTAL_ENABLED=true
CAPACITACION_ENABLED=false
```

## `rh_portal`

Activa la identidad y navegación de Portal RH (fase actual, ver `docs/PORTAL_RH.md`). En esta primera ejecución no condiciona código todavía — existe para que las fases siguientes (expedientes, vacaciones, solicitudes) puedan apagarse individualmente si hace falta, sin tocar `capacitacion`.

## `recibos_colaborador`

`RECIBOS_COLABORADOR_ENABLED` (por defecto `false`). Decisión de negocio (2026-10-12): el recibo de nómina se entrega **impreso** — RH lo emite, genera el PDF (queda en el expediente) y lo imprime. Con `false`:

- El colaborador no ve «Mis recibos de nómina» (web) ni «Mis recibos» (app: `mobile/bootstrap` manda `features.recibos = false`, también en APKs ya instaladas).
- `GET /mis-recibos` y `GET /api/v1/colaborador/recibos` responden 404; el detalle/PDF propio responde 403 (`ReciboNominaPolicy`).
- El PDF del recibo (documento laboral de categoría `nomina_interna`) no aparece en los documentos laborales del colaborador ni se puede descargar desde su cuenta (`GeneratedDocumentPolicy`).
- No se le envía aviso/push al emitir.
- RH (`nomina.recibos.ver`, `documentos_laborales.ver`) los sigue viendo y descargando igual.

## `capacitacion`

Controla la visibilidad y el acceso de **todo** el módulo de capacitación: cursos, mi capacitación, biblioteca multimedia, cuestionarios/banco de preguntas, actividades/calificaciones, sesiones en vivo/asistencias, asignaciones, reporte de cumplimiento y el calendario de sesiones.

Con `CAPACITACION_ENABLED=false` (valor por defecto):

- El sidebar no muestra ninguno de esos módulos. Solo queda un acceso "Capacitación" con badge "Próximamente" (`resources/js/components/AppSidebar.vue`).
- Si alguien entra por URL directa a una ruta protegida, el middleware `App\Http\Middleware\EnsureFeatureEnabled` responde:
  - **GET**: renderiza `Capacitacion/Proximamente` en la misma URL (no hay redirect ni error feo).
  - **POST/PUT/DELETE** (acciones de escritura): `403`.
- Nada se borra: modelos, migraciones, controladores, policies, datos y tests de capacitación siguen intactos. Ver `docs/CAPACITACION_PROXIMAMENTE.md`.

### Cómo reactivar capacitación

1. En `.env`, cambia `CAPACITACION_ENABLED=true`.
2. `php artisan config:clear` (o `optimize:clear`).
3. El sidebar vuelve a mostrar Cursos, Asignaciones, Biblioteca multimedia, Banco de preguntas, Calificaciones y Reporte de cumplimiento, y todas las rutas quedan accesibles otra vez — sin ninguna migración ni cambio de código adicional.

### Rutas protegidas por `feature:capacitacion`

| Archivo | Prefijo |
|---|---|
| `routes/cursos.php` | `/cursos` |
| `routes/mi-capacitacion.php` | `/mi-capacitacion` |
| `routes/multimedia.php` | `/multimedia` |
| `routes/cuestionarios.php` | `/bancos-preguntas`, `/cuestionarios/{cuestionario}`, `/calificaciones/cuestionarios` |
| `routes/actividades.php` | `/actividades/{actividad}`, `/calificaciones/actividades` |
| `routes/reuniones.php` | `/sesiones/{sesion}` (el webhook de Zoom queda fuera, Zoom lo llama directo) |
| `routes/reportes.php` | `/reportes/cumplimiento*` |
| `routes/asignaciones.php` | `/asignaciones` |
| `routes/web.php` | `/calendario` |

### Pruebas

`phpunit.xml` fuerza `CAPACITACION_ENABLED=true` en el entorno de pruebas, para que la suite existente siga ejercitando esas rutas exactamente igual que antes, sin importar el valor por defecto en producción.

## Módulos de Desarrollo (etapa 6) aún no liberados

La etapa **6 · Desarrollo** del sidebar (ver `docs/ROLES_Y_NAVEGACION.md`) incluye módulos que todavía no tienen pantalla real. En lugar de mostrar accesos falsos, cada uno queda **detrás de su propia bandera, apagada por defecto** — regla del plan: «no mostrar pantallas falsas, usar feature flag hasta estar realmente funcional».

| Bandera | Env | Default | Oculta |
|---|---|---|---|
| `evaluacion_desempeno` | `EVALUACION_DESEMPENO_ENABLED` | `false` | «Evaluación del desempeño» (evaluación técnica/por puesto, KPIs, ciclos). |
| `nine_box` | `NINE_BOX_ENABLED` | `false` | «Matriz 9 box y planes» (desempeño × potencial, planes de desarrollo/mejora). |
| `certificacion` | `CERTIFICACION_ENABLED` | `false` | «Certificación Mr. Lana» (programa de certificación por puesto). |

Con la bandera apagada, el acceso **no se muestra a nadie** en el menú (el `href` es un marcador `'#'`). Al construir cada módulo real se cambia el `href` por su ruta y se enciende la bandera; mientras tanto la etapa Desarrollo sí existe en el orden del menú con «Capacitación continua» (detrás de `capacitacion`). Estas tres banderas se exponen al frontend en `App\Http\Middleware\HandleInertiaRequests` (`features`).
