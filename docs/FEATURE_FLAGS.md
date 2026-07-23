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
