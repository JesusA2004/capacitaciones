# Movimientos laborales

Histórico inmutable de altas, bajas, promociones y cambios de puesto/sucursal/
departamento/jefe/empresa/cobertura de cada colaborador. Tabla `movimientos_laborales`
(migración `2026_09_03_155756_create_movimientos_laborales_table`), modelo
`App\Models\MovimientoLaboral`.

## Por qué existe

Antes de este cambio no había ningún rastro de "quién estuvo en qué puesto y cuándo":
ni al aprobar una alta digital, ni al editar el puesto de un colaborador desde
Administración, ni al cubrir una vacante. La pestaña "Historial RH" del expediente
era un placeholder explícito ("Próximamente"). Este módulo lo resuelve sin tocar el
resto del dominio: es puramente aditivo, un registro de lo que ya pasaba.

## Campos

`user_id` (colaborador afectado), `tipo_movimiento`, pares `*_anterior_id`/`*_nuevo_id`
para empresa/sucursal/departamento/puesto/jefe, `vacante_id`, `candidato_id`,
`alta_digital_id`, `documento_id` (todos nullable — cada movimiento solo llena los que
aplican), `motivo`, `observaciones`, `fecha_movimiento`, `fecha_fin_cobertura`
(solo `cobertura_temporal`), `registrado_por`. Soft deletes.

## Tipos (`App\Enums\TipoMovimientoLaboral`)

`alta`, `baja`, `promocion`, `cambio_puesto`, `cambio_sucursal`,
`cambio_departamento`, `cambio_jefe`, `cambio_empresa`, `cobertura_temporal`,
`reingreso`, `ajuste_manual`.

## Única puerta de entrada: `MovimientoLaboralService`

`App\Services\MovimientosLaborales\MovimientoLaboralService` — ningún controlador
crea un `MovimientoLaboral` directamente (mismo patrón que `ConversionColaboradorService`
o `VacacionesService`). Métodos:

- **`snapshot(User $usuario)`** — captura empresa/sucursal/departamento/puesto/jefe
  actuales de un colaborador (y el nivel jerárquico de su puesto). Se llama **antes**
  de aplicar un cambio, para poder diferenciar "antes" vs. "después".
- **`registrarAlta()`** — un `User` recién creado (alta digital aprobada, o alta
  directa desde Administración). Enlaza `vacante_id`/`alta_digital_id` si vienen de
  ese flujo.
- **`registrarCambioPuesto(User $usuario, array $antes, ...)`** — compara `$antes`
  contra el estado ya guardado y crea **un movimiento por cada dimensión que
  cambió** (puede ser más de uno en la misma edición: p. ej. promoción + cambio de
  sucursal a la vez). Detecta promoción automáticamente: si cambia el puesto y el
  nuevo `nivel_jerarquico` es numéricamente **menor** que el anterior (1 = más alto),
  se registra como `promocion`; si no, como `cambio_puesto`.
- **`registrarBaja(User $usuario, ..., bool $crearVacante)`** — registra la baja y,
  si `$crearVacante`, crea la `Vacante` de reemplazo (`motivo: baja_colaborador`)
  enlazada al movimiento.
- **`registrarCoberturaTemporal()`** — no cambia el puesto definitivo del
  colaborador; usada por "Cubrir vacante → cobertura temporal" (ver
  `docs/VACANTES.md`).

## Dónde se dispara

| Evento | Dónde | Qué registra |
|---|---|---|
| Alta digital aprobada | `ConversionColaboradorService::convertir()` | `alta`, enlazada a `vacante_id`/`alta_digital_id`/`candidato_id` |
| Alta directa desde Administración | `UsuarioController::store()` | `alta` |
| Editar colaborador (puesto/sucursal/depto/jefe/empresa) | `UsuarioController::update()` | uno o más movimientos según qué cambió; si el puesto cambia y se marca "crear vacante de reemplazo" en el formulario, también crea la `Vacante` (motivo `promocion`) |
| Dar de baja a un colaborador | `UsuarioController::destroy()` | `baja`, con motivo y checkbox opcional "crear vacante de reemplazo" |
| Cubrir vacante con colaborador interno | `VacanteController::cubrir()` (modo `colaborador_interno`) | `promocion`/`cambio_puesto`, enlazado a `vacante_id`; la vacante pasa a `Cubierta` |
| Cubrir vacante con cobertura temporal | `VacanteController::cubrir()` (modo `cobertura_temporal`) | `cobertura_temporal`, la vacante sigue abierta |

**No automático a propósito:** editar un colaborador nunca crea una vacante por sí
solo — requiere el checkbox explícito "crear vacante de reemplazo", para no generar
vacantes fantasma en ediciones menores (corregir un teléfono, por ejemplo).

## Dónde se ve

- **Expediente del colaborador** (`Rh/Expedientes/Show.vue` y `MiExpediente.vue`),
  pestaña **"Historial RH"** → `MovimientosLaboralesTimeline.vue`: timeline vertical
  con ícono y color por tipo, fecha, frase legible (`MovimientoLaboral::
  getDescripcionAttribute()`, p. ej. *"Juan Pérez ascendió de Subgerente a
  Gerente."*), motivo/observaciones, quién lo registró, y chips a vacante/documento
  relacionados. `ExpedienteController::renderExpediente()` provee los últimos 30.
- **Panel lateral del árbol de Jerarquía de puestos**, pestaña "Historial" — ver
  `docs/JERARQUIA_PUESTOS.md`.

## Tests

`tests/Feature/MovimientosLaborales/MovimientosLaboralesTest.php`: alta vía alta
digital, cambio de puesto, promoción con vacante de reemplazo, baja con vacante de
reemplazo, cubrir vacante con colaborador interno, cubrir vacante con cobertura
temporal (no cambia el puesto definitivo).
