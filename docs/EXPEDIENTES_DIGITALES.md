# Expedientes digitales

## No existe un modelo "Expediente"

El expediente de un colaborador **no es una tabla propia**: es una vista calculada sobre `User` + `EmployeeDocument` (ver `docs/SYNOLOGY_STORAGE.md`). `App\Services\Expedientes\ExpedienteService` calcula:

- `documentosVigentes(User $colaborador)`: el documento más reciente (no archivado) de cada tipo.
- `resumenCompletitud(User $colaborador)`: `{ porcentaje, requeridos_total, requeridos_aprobados, pendientes, rechazados }`, comparando contra `document_types` donde `requerido=true` y `activo=true`.

Como no hay modelo dedicado, tampoco hay una `Policy` de Laravel formal para "expediente": la autorización vive en `App\Services\AlcanceOrganizacionalService::puedeVerExpediente()`/`limitarExpedientesPorAlcance()` (ver `docs/ROLES_PERMISOS_RH.md`), invocada explícitamente desde `Rh\ExpedienteController` con `abort_unless(...)`.

## Reglas de visibilidad

| Rol | Alcance |
|---|---|
| `super_admin`, `rh_admin`, `rh_auxiliar`, `auditor`, `administrador_capacitacion` | Todos los expedientes (alcance global) |
| `gerente_sucursal` | Expedientes de colaboradores de sus sucursales autorizadas |
| `jefe_directo` | Expedientes de sus subordinados directos (`jefe_id`) |
| `colaborador` | Solo su propio expediente |

## Rutas

```
GET  /rh/expedientes                                  rh.expedientes.index    (explorador)
GET  /rh/expedientes/{colaborador}                     rh.expedientes.show     (vista RH de un colaborador)
GET  /mi-expediente                                    mi-expediente           (vista propia del colaborador)
PUT  /rh/expedientes/{colaborador}/datos-personales     rh.expedientes.datos-personales.update
```

`show` y `mi-expediente` comparten la misma lógica de carga (`ExpedienteController::renderExpediente()`) pero renderizan **páginas Inertia distintas** (`Rh/Expedientes/Show.vue` y `Rh/Expedientes/MiExpediente.vue`), ambas delgadas y montando el mismo componente `resources/js/components/Rh/ExpedienteDetalle.vue`. Se separaron en dos páginas porque el breadcrumb difiere ("Expedientes" vs. "Mi expediente") y Vue no permite que `defineOptions({ layout: { breadcrumbs } })` referencie props del componente (se evalúa fuera de `setup()`); el resto del proyecto ya resuelve esto usando siempre breadcrumbs estáticos por página, así que se siguió la misma convención en vez de introducir una excepción.

## `/rh/expedientes` — explorador tipo carpetas

`resources/js/pages/Rh/Expedientes/Index.vue`: grid de tarjetas (`ColaboradorCarpetaCard.vue`), no tabla. Cada tarjeta muestra foto, nombre, número de empleado, puesto/departamento/sucursal/empresa, estado laboral, % de expediente completo (barra de progreso) y documentos pendientes. Filtros por empresa, sucursal (dependiente de la empresa elegida), departamento, puesto y estado, más buscador por nombre/número de empleado. Un breadcrumb simple (`Empresas / <empresa> / <sucursal>`) refleja los filtros activos.

## Expediente individual — pestañas

`ExpedienteDetalle.vue` usa el componente `Tabs` (nuevo: `resources/js/components/ui/tabs/`, construido sobre las primitivas `Tabs*` de `reka-ui` porque el proyecto no traía Tabs de shadcn-vue todavía):

| Pestaña | Estado |
|---|---|
| Resumen | Real: contacto, jefe directo, contadores de documentos aprobados/pendientes/rechazados |
| Datos personales | Real: formulario editable (fecha nacimiento, CURP, RFC, NSS, domicilio, correo personal, contacto de emergencia) |
| Datos laborales | Real, solo lectura (se edita desde Administración → Colaboradores) |
| Documentos | Real: ver `docs/SYNOLOGY_STORAGE.md` |
| Contrato | Placeholder "Próximamente" (Fase 2) |
| Avisos y consentimientos | Placeholder "Próximamente" (Fase 2) |
| Vacaciones | Placeholder "Próximamente" (Fase 3) |
| Solicitudes | Placeholder "Próximamente" (Fase 3) |
| Historial RH | Placeholder "Próximamente" |
| Bitácora | Placeholder "Próximamente" |

Los placeholders usan `resources/js/components/Rh/ProximamenteTab.vue`, el mismo patrón honesto usado en `Capacitacion/Proximamente.vue`: no se fabrican datos falsos para módulos que todavía no existen.

## Datos personales nuevos en `users`

Migración `2026_07_22_222904_add_datos_personales_a_users_table.php` (todas nullable, aditiva):
`fecha_nacimiento`, `curp`, `rfc`, `nss`, `domicilio`, `correo_personal`, `contacto_emergencia_nombre`, `contacto_emergencia_telefono`.

Un colaborador puede editar sus propios datos personales (permiso `expedientes.ver`, no requiere `expedientes.editar`); RH edita los de cualquier colaborador dentro de su alcance con `expedientes.editar`. Ver `App\Http\Requests\Rh\ActualizarDatosPersonalesRequest`.

## Pendiente

- Historial RH / Bitácora / Contrato / Avisos: checkpoints siguientes.
- El cálculo de `% completo` recorre los colaboradores visibles uno por uno (2 consultas por colaborador vía `ExpedienteService`); aceptable para el volumen actual, pero si la organización crece mucho conviene precalcular/cachear este dato en vez de calcularlo en cada carga del explorador o del dashboard.
