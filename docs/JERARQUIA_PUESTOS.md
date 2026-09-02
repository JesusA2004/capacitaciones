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

Solo lectura del organigrama por tipo de puesto (comercial/administrativo/operativo),
renderizado como árbol visual (`OrganigramaNodo.vue`, recursivo). Al seleccionar un
puesto se abre un panel lateral con: descripción, puesto superior, ruta de crecimiento,
esquema de comisiones, respaldos, puestos que puede cubrir, responsabilidades,
requisitos y número de colaboradores actuales. Desde ahí se abre el diálogo de edición
de jerarquía (`JerarquiaPuestoDialog.vue`).

El panel también muestra vacantes abiertas y candidatos asociados a ese puesto
(`Puesto::vacantes()`, `Puesto::candidatos()`), con enlaces directos a los tableros de
Reclutamiento (`docs/RECLUTAMIENTO.md`) y Vacantes (`docs/VACANTES.md`) ya filtrados
por ese puesto.

Autorización: reutiliza el permiso existente `puestos.administrar` (mismo permiso que
la administración básica de puestos) — no se creó un permiso nuevo para esta vista.
