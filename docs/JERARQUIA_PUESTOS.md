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

Estructura definida por dirección (septiembre 2026). Un solo árbol con
**Dirección General** como raíz:

```
Dirección General
├── Asistente de Dirección General          (el puesto existe aunque no esté ocupado)
└── Director comercial                      (Dirección Comercial de Mr. Lana)
    ├── Asistente de Dirección Comercial
    ├── Gerente de Sistemas
    │   └── Monitorista
    ├── Gerente de Mesa de Control
    │   └── Analista de Mesa de Control
    ├── Gerente de Recursos Humanos
    │   ├── Administración de Personal
    │   └── Reclutamiento
    ├── Gerente de Contraloría               (antes "Gerente de Contabilidad")
    ├── Gerente regional                     (División comercial)
    │   └── Gerente de Sucursal
    │       └── Subgerente
    │           ├── Gestor fijo (ruta)
    │           │   └── Gestor volante
    │           └── Gestor grupal
    └── Gerente administrativo regional      (= coordinadora regional; antes "Coordinadora regional")
        └── Coordinadora                     (de sucursal)
```

Niveles: 1 Dirección General · 2 asistente de DG y Director comercial · 3
asistente comercial y todas las gerencias · 4 Monitorista, Analista de Mesa
de Control, Administración de Personal, Reclutamiento, Gerente de Sucursal y
Coordinadora · 5 Subgerente · 6 gestores fijo/grupal · 7 Gestor volante.

- **Coordinadora** no la listó dirección, pero existe en el Excel real de
  headcount ("COORDINADORA DE SUCURSAL", ver
  `App\Services\Headcount\HeadcountImportService::MAPA_PUESTOS`); se conserva
  bajo el Gerente administrativo regional hasta que dirección confirme.
- **"Gerente"** era un duplicado de "Gerente de Sucursal": se retiró y el
  import de headcount mapea "GERENTE" → "Gerente de Sucursal".
- **Puestos retirados** (`Gerente`, `Generalista de RH`, `Coordinador de
  Capacitación`, `Analista de Sistemas`, `Soporte Técnico`, `Analista de
  Nómina`, `Auxiliar Contable`, `Responsable administrativo/regional`,
  `Supervisor de Operaciones`, `Ejecutivo de Ventas`, `Coordinador de
  Ventas`): se eliminan (borrado suave) solo si nada los usa. Si tienen
  colaboradores, headcount, vacantes o historial de movimientos, quedan
  `activo = false`, fuera del árbol, y el seeder lo reporta en consola para
  reasignarlos — borrar un puesto en uso vaciaría el historial y borraría en
  cascada su headcount. El organigrama oculta los puestos inactivos salvo
  que alguien activo siga en uno.
- `PuestoSeeder` (catálogo genérico anterior) ya solo delega en
  `PuestoJerarquiaSeeder`.

Respaldos sembrados: Gestor volante puede cubrir a Gestor fijo; Subgerente
puede cubrir a Gerente de Sucursal.

Para el organigrama **por personas**, los puestos "de sucursal" son
`Gerente de Sucursal`, `Coordinadora` y todo lo que cuelga de ellos
(`config/organigrama.php`).

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

## API móvil (solo lectura)

```
GET /api/v1/rh/jerarquia-puestos      permiso: puestos.administrar
```

`App\Http\Controllers\Api\V1\Rh\JerarquiaPuestoController::index()` reutiliza el mismo
`JerarquiaPuestoService` que el panel web y regresa la misma estructura de árbol; la app
solo consulta, no puede editar jerarquía, agregar subordinados ni quitar relaciones
desde este endpoint (esas acciones se quedan en el panel web por ahora). Ver
`docs/RH_MOBILE_API.md`.
