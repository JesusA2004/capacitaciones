# Perfiles de puesto

Siguiendo el mismo criterio de diseño que el expediente digital (ver
`docs/EXPEDIENTES_DIGITALES.md`: "no crear una tabla nueva cuando el dato ya cabe en el
modelo existente"), el **perfil de puesto no es una tabla aparte**: son los campos de
perfil que ya viven en `puestos` desde el módulo de jerarquía
(`docs/JERARQUIA_PUESTOS.md`):

| Campo del perfil | Descripción |
|---|---|
| `tipo_puesto` | Comercial / administrativo / operativo / otro. |
| `esquema_comisiones` | Descripción del esquema de comisiones, si aplica. |
| `requiere_ruta` | Si el puesto necesita ruta/cartera asignada. |
| `responsabilidades` | Texto libre de responsabilidades del puesto. |
| `requisitos` | Texto libre de requisitos para ocupar el puesto. |

## Dónde se consulta

El perfil completo de un puesto se muestra en el panel de detalle de
`/administracion/jerarquia-puestos` (componente `OrganigramaNodo.vue` +
panel lateral), junto con su posición en el organigrama (puesto superior, ruta de
crecimiento, respaldos) y su conteo de colaboradores actuales.

El mismo perfil (responsabilidades/requisitos) es la fuente que **Vacantes**
(`docs/VACANTES.md`) y **Reclutamiento** (`docs/RECLUTAMIENTO.md`) muestran a
gerencia/RH al revisar una vacante o candidato para ese puesto, evitando capturar la
misma información dos veces.

## Por qué no es una tabla aparte

- Un puesto tiene un único perfil vigente a la vez (no hay versionado de perfil en
  Fase 1).
- Mantenerlo como columnas de `puestos` evita joins adicionales en las pantallas de
  vacantes/candidatos, que ya filtran por puesto constantemente.
- Si en una fase futura se requiere historial de cambios de perfil, se puede agregar
  sin romper nada usando `spatie/laravel-activitylog` (ya instalado y usado en otros
  modelos como `Empresa` y `User`).
