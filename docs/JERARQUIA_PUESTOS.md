# Jerarquía de puestos

El sistema no maneja puestos planos: cada puesto puede tener un puesto superior, una
ruta natural de crecimiento y respaldos (puestos que pueden cubrirlo temporalmente).

## Modelo de datos

Campos agregados a `puestos` (migración
`2026_09_01_090000_add_jerarquia_a_puestos_table`):

| Campo | Tipo | Descripción |
|---|---|---|
| `nivel_jerarquico` | `tinyint unsigned` nullable | Menor número = mayor jerarquía dentro de su línea (1 = puesto más alto). |
| `puesto_superior_id` | FK a `puestos`, nullable | Puesto al que reporta directamente. |
| `puesto_crecimiento_id` | FK a `puestos`, nullable | Puesto al que naturalmente se puede crecer desde este. |
| `tipo_puesto` | `string(30)` nullable | `comercial`, `administrativo`, `operativo` u `otro` (enum `App\Enums\TipoPuesto`). |
| `esquema_comisiones` | `string` nullable | Descripción libre del esquema de comisiones, si aplica. |
| `requiere_ruta` | `boolean` | Indica si el puesto necesita una ruta/cartera asignada. |
| `responsabilidades` | `text` nullable | Responsabilidades del puesto. |
| `requisitos` | `text` nullable | Requisitos para ocupar el puesto. |

Tabla `puesto_cobertura` (muchos a muchos): `puesto_id` puede cubrir a
`puesto_a_cubrir_id`. En el modelo `Puesto`:

- `puestosQuePuedeCubrir()` — puestos que ESTE puesto puede cubrir.
- `respaldos()` — inverso: puestos que pueden cubrir a este puesto.

## Jerarquía base sembrada (`PuestoJerarquiaSeeder`)

**Línea comercial:**

```
Gestor volante → Gestor fijo (Gestor de ruta) → Subgerente → Gerente → Gerente regional → Director comercial
```

- **Gestor volante**: cubre rutas cuando falta gestor fijo, apoya rutas lejanas o con
  carga, candidato natural cuando se libera una ruta. Respaldo de Gestor fijo.
- **Gestor fijo**: tiene ruta asignada, responsable de cartera/ruta, requiere ruta.
- **Subgerente**: apoya y cubre al gerente temporalmente, supervisa gestores. Respaldo
  de Gerente.
- **Gerente**: responsable de sucursal, participa en aprobación de candidatos y
  solicitudes.
- **Gerente regional**: supervisa varias sucursales, revisa indicadores.
- **Director comercial**: vista global, reportes generales, decisiones estratégicas.

**Línea administrativa:**

```
Coordinadora → Coordinadora regional → Responsable administrativo/regional
```

- **Coordinadora**: cuadre de caja, control administrativo, procesos internos.
- **Coordinadora regional**: supervisa coordinadoras de varias sucursales.
- **Responsable administrativo/regional**: responsable administrativo a nivel regional.

## Módulo `/administracion/jerarquia-puestos`

Organigrama por tipo de puesto (comercial/administrativo/operativo), renderizado como
árbol visual con conectores en escritorio/tablet (`OrganigramaArbol.vue` +
`OrganigramaNodo.vue`, recursivo, con zoom in/out/reset vía `transform: scale()` y
scroll horizontal controlado) y como lista jerárquica expandible en móvil
(`OrganigramaAccordion.vue`, reutiliza `components/ui/collapsible`, breakpoint `md`).
Ambas vistas comparten la tarjeta de puesto (`OrganigramaTarjeta.vue`): nombre,
departamento, nivel, badges de tipo/"Ruta"/"Vacante"/"Sin cobertura"/"Candidatos"/
"Inactivo", y contador de colaboradores.

**Filtros** (`empresa_id`, `sucursal_id`, `departamento_id`, `tipo_puesto`, vía
`useFiltros`): como un puesto no tiene FK propia a empresa/sucursal, filtrar por
empresa/sucursal significa "puestos con al menos un colaborador activo en esa
empresa/sucursal" (`whereHas('usuarios', ...)` en `JerarquiaPuestoController::index()`),
no "puestos que pertenecen a esa empresa".

Al seleccionar un puesto se abre un panel lateral (`Sheet`) con tres pestañas:

- **Detalle**: descripción, puesto superior, ruta de crecimiento, esquema de
  comisiones, respaldos, puestos que puede cubrir, responsabilidades, requisitos,
  colaboradores activos y candidatos relacionados (con enlace a Candidatos).
- **Vacantes**: vacantes abiertas de ese puesto (enlaza a `/rh/vacantes` filtrado) y
  botón **"Crear vacante para este puesto"**, que navega a
  `/rh/vacantes?puesto_id=..&departamento_id=..&crear=1` — `Rh/Vacantes/Index.vue` lee
  `crear=1` en `onMounted()` y abre el diálogo de creación precargado con esos valores
  (prop `prefill` de `VacanteFormDialog.vue`).
- **Historial**: cargado bajo demanda (no en el payload inicial) contra
  `GET administracion/jerarquia-puestos/{puesto}/historial`
  (`JerarquiaPuestoController::historial()`), que devuelve tres cosas:
  1. `cambiosJerarquia` — entradas del activity log de Spatie (`Puesto` usa
     `LogsActivity` sobre `puesto_superior_id`, `puesto_crecimiento_id`, `tipo_puesto`,
     `nivel_jerarquico`, `requiere_ruta`, `esquema_comisiones`, `activo`).
  2. `movimientos` — `MovimientoLaboral` donde este puesto es origen o destino (ver
     `docs/MOVIMIENTOS_LABORALES.md`).
  3. `vacantes` — vacantes que ha generado este puesto.

Desde el panel, el botón "Editar jerarquía" abre `JerarquiaPuestoDialog.vue`, que edita
`puesto_superior_id`, `puesto_crecimiento_id`, nivel, tipo, comisiones, "requiere
ruta", responsabilidades, requisitos, **respaldos** (quién puede cubrir a este puesto)
y **puestos que puede cubrir** (a quién puede cubrir este puesto) — ambas direcciones
de la tabla `puesto_cobertura` editables desde el mismo formulario.

### Validaciones de ciclo

`ActualizarJerarquiaPuestoRequest` rechaza, además de la auto-referencia:

- Un ciclo jerárquico (A → B → C → A) al asignar `puesto_superior_id`.
- Un ciclo en la ruta de crecimiento al asignar `puesto_crecimiento_id`.

Ambas usan `Puesto::creariaCiclo(string $columna, int $candidatoId)`: camina la cadena
indicada por `$columna` desde `$candidatoId` y detecta si vuelve a este puesto. No hay
validación equivalente de "duplicado" entre `respaldos` y `puestos_que_puede_cubrir`
en direcciones opuestas — dos puestos cubriéndose mutuamente es una configuración
operativa válida (p. ej. Gerente y Subgerente cubriéndose entre sí), no un error.

Autorización: reutiliza el permiso existente `puestos.administrar` (mismo permiso que
la administración básica de puestos) — no se creó un permiso nuevo para esta vista.
