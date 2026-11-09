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
`cancelada`. El tablero (`/rh/vacantes`, con `vue-draggable-plus` — misma librería que
el tablero de Solicitudes) permite arrastrar una tarjeta a otra columna para cambiar de
estado (`PUT rh/vacantes/{vacante}/estado`); el cambio siempre pasa por
`VacantePolicy::cambiarEstado` en el backend, no solo por la interfaz.

`EstadoVacante::puedeTransicionarA()` es la única fuente de verdad de qué transiciones
son válidas (`VacanteController::actualizarEstado()` la consulta antes de escribir nada).
`cubierta` **nunca** es un destino válido ahí a propósito: soltar una tarjeta en esa
columna abre `CubrirVacanteDialog` (cobertura real: colaborador interno, temporal o
candidato externo) en vez de mandar el PATCH — la única forma real de llegar a
`cubierta` es `POST rh/vacantes/{vacante}/cubrir` (ver abajo). Una vacante
`generada_automaticamente` tampoco admite `DELETE` directo (`VacantePolicy::delete()`):
se cancela con motivo o se sincroniza sola cuando el faltante desaparece, para conservar
su auditoría.

## Relación con candidatos

`Vacante::candidatos()` — `hasMany(Candidato::class)`. Ver `docs/RECLUTAMIENTO.md`.
El contador de candidatos por vacante (`candidatos_count`) se muestra en cada tarjeta
del tablero.

## Cubrir una vacante

Vacantes es **informativo** (derivado de la plantilla, `docs/HEADCOUNT_Y_VACANTES.md`):
`routes/rh.php` solo expone `index`, `exportarExcel` y `exportarPdf`; el antiguo
`POST rh/vacantes/{vacante}/cubrir` (`VacanteController::cubrir()`) ya no existe. Una
vacante se cierra sola cuando su plaza queda ocupada:

- **Contratación**: Alta Digital del candidato enlazada a `vacante_id`; al aprobarse,
  `ConversionColaboradorService` marca la vacante `Cubierta` y registra el movimiento
  `alta`.
- **Movimiento interno**: cambio de puesto/sucursal del colaborador (expediente,
  movimiento laboral, organigrama); `ColaboradorPlantillaObserver` resincroniza el par
  de origen y el de destino.
- **Una persona en dos puestos** (organigrama → `CoberturaPuestoService`, campo
  `ocupa_plaza`): «**Ocupa ambos puestos (sin vacante)**» cuenta la plaza como
  ocupada y cierra la vacante; «**Cubre mientras se contrata (con vacante)**» la deja
  abierta. Al quitar la cobertura se reabre. Es la única forma de registrar una
  cobertura (`POST administracion/jerarquia-puestos/coberturas`).
- **Ajuste de plantilla**: bajar las plazas autorizadas (detalle de sucursal, ficha del
  puesto o «¿Esta sucursal tendrá volantes?») cierra o reduce la vacante con su motivo.

Una vacante nunca se borra ni se duplica: se cancela con motivo y hay a lo más una
automática abierta por par (sucursal, puesto).

## Cómo se muestra

- **Puesto**: Gestor se ve como «Gestor fijo» (con la aclaración «la ruta se define en
  Matriz comercial») y Gestor Volante como «Gestor volante» (`config/headcount.php` →
  `etiquetas_vacante`, `notas_vacante`), en la web, la API, el resumen y Excel/PDF.
  Son plazas y vacantes **separadas**.
- **Ubicación**: sucursal o Corporativo (marcado «Corporativo»).
- **Área / departamento**: el de la vacante o, si no lo trae, el de su puesto
  (Auditora → Contraloría). Se filtra con los chips de área o con el filtro
  «Área / departamento» (`departamento_id`, también en la API).

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
