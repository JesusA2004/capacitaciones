# Vacantes

Módulo `/rh/vacantes`. Tabla `vacantes` — ver migración
`2026_09_01_100000_create_vacantes_table`.

## Campos

`empresa_id`, `sucursal_id`, `departamento_id`, `puesto_id` (todos nullable, FK
`nullOnDelete`), `gerente_solicitante_id`, `responsable_rh_id` (FK a `users`),
`motivo` (enum `App\Enums\MotivoVacante`), `estado` (enum `App\Enums\EstadoVacante`),
`fecha_apertura`, `fecha_estimada_cobertura`, `observaciones`, `creado_por`.
Soft deletes.

## Motivos

`nueva_posicion`, `baja_colaborador`, `promocion`, `reemplazo`, `crecimiento`,
`cobertura_temporal`.

## Estados

`abierta` → `en_reclutamiento` → `con_candidatos` → `en_revision` → `cubierta` |
`cancelada`. El tablero (`/rh/vacantes`) permite arrastrar una tarjeta a otra columna
para cambiar de estado (`PUT rh/vacantes/{vacante}/estado`); el cambio siempre pasa por
`VacantePolicy::cambiarEstado` en el backend, no solo por la interfaz.

## Relación con candidatos

`Vacante::candidatos()` — `hasMany(Candidato::class)`. Ver `docs/RECLUTAMIENTO.md`.
El contador de candidatos por vacante (`candidatos_count`) se muestra en cada tarjeta
del tablero.

## Alcance y permisos

Permisos: `vacantes.ver`, `vacantes.ver_todos`, `vacantes.ver_sucursal`,
`vacantes.crear`, `vacantes.editar`, `vacantes.cerrar`, `vacantes.eliminar`.

- RH (`rh_admin`, `rh_auxiliar`) ve todas las vacantes (`vacantes.ver_todos`).
- `gerente_sucursal` solo ve/crea vacantes de sus sucursales (`vacantes.ver_sucursal`),
  vía `AlcanceOrganizacionalService::limitarPorSucursal()` — método genérico agregado
  para reutilizar el mismo criterio de alcance en vacantes, candidatos y futuros
  módulos con columna `sucursal_id` (no solo en usuarios/expedientes).
- `auditor` consulta sin poder crear/editar/cerrar.

Las vacantes sin sucursal asignada (`sucursal_id = null`) son visibles para cualquiera
con acceso al módulo, ya que no pertenecen a una sucursal específica.

## Fuera de alcance en Fase 1

Sugerencia automática de candidato interno con base en jerarquía de puestos, y
sugerencia de gestor volante para cubrir gestor fijo (mencionadas como posibles
funciones futuras): no implementadas en esta fase. El vínculo estructural
(`puesto_cobertura`, ver `docs/JERARQUIA_PUESTOS.md`) ya existe para soportarlas
cuando se prioricen.
