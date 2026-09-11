# Organigrama

"Organigrama" en el menú y en los títulos de pantalla cubre **dos árboles distintos** — no confundirlos:

## A) Estructura de puestos (`Administración → Organigrama`)

Ruta interna `administracion/jerarquia-puestos` (el nombre de ruta/URL se conserva por compatibilidad; la UI, breadcrumbs y `<title>` dicen "Organigrama", nunca "Jerarquía de puestos"). Árbol de `App\Models\Puesto` con relación `puesto_superior_id`: quién reporta a quién, ruta de crecimiento, respaldos, puestos que puede cubrir. Filtros por empresa/sucursal/departamento/tipo de puesto, vista de árbol con zoom en escritorio y lista expandible en móvil (`OrganigramaArbol.vue`/`OrganigramaAccordion.vue`).

Acceso: `App\Policies\PuestoPolicy::viewAny()`/`view()` aceptan **`puestos.administrar` o `organigrama.ver`** — un gerente que solo tiene `organigrama.ver` puede consultar el árbol aunque no pueda editarlo (antes solo `puestos.administrar` daba acceso, dejando fuera a gerentes/coordinadoras que ya tenían `organigrama.ver` sembrado pero ningún gate lo usaba). Editar sigue pidiendo `puestos.administrar` u `organigrama.editar`.

## B) Matriz comercial (`Administración → Matriz comercial`)

Ruta `administracion/matriz-comercial` (mismo gate de permisos que A). Árbol territorial **MATRIZ → Región → Zona → Ruta** (`App\Models\NodoComercial`, tabla `nodos_comerciales`), completamente distinto del árbol de puestos:

- **MATRIZ**: raíz única.
- **Región**: Q1, Q2 (todavía sin rutas cargadas — se muestra como "pendiente de configurar", nunca como error), Q3.
- **Zona**: cada una corresponde 1:1 a una `Sucursal` real cuando existe match (`sucursal_id`) — mismo nombre que usa headcount (ver `docs/HEADCOUNT_Y_VACANTES.md`).
- **Ruta**: la unidad de cobertura real — puede tener un `responsable_user_id` (el gestor asignado) o quedar "sin cubrir".

Cargada por `database/seeders/MatrizComercialSeeder.php` (idempotente, `updateOrCreate` por parent+tipo+nombre) con la estructura real que entregó dirección. Reglas de esa carga:

- Un nombre con `(INACTIVA)` → `activa = false`, se limpia del nombre mostrado.
- `(VENCIDOS)`/`(CASTIGO)` → **no** desactivan el nodo, se guardan en `metadata.estado_operativo` y se conservan dentro del nombre (a propósito: existen pares como "HUAMANTLA (VENCIDOS)" y "HUAMANTLA (CASTIGO)" como rutas *distintas* de la misma zona — quitarles el sufijo las haría colisionar en el mismo nombre y una sobrescribiría a la otra).
- Una zona sin sucursal real que le corresponda (como "AGUASCALIENTES (INACTIVA)", que cuelga directo de MATRIZ sin región propia) se guarda igual, con `sucursal_id = null` — nunca se inventa una sucursal para que "cuadre".

### Cobertura

`App\Services\MatrizComercial\MatrizComercialService::cobertura()` clasifica cada ruta:

- `inactiva` — la ruta no opera.
- `cubierta` — tiene `responsable_user_id` y ese colaborador está `activo`.
- `sin_cubrir` — está activa pero sin gestor asignado (o el asignado ya no está activo).

Este estado es **independiente** de vacantes/headcount: una ruta puede estar "sin cubrir" en la matriz aunque headcount no muestre vacante alguna en esa zona (porque headcount es agregado por zona, no por ruta), y viceversa. Asignar/quitar gestor (`PUT administracion/matriz-comercial/{nodo}/responsable`) no toca headcount ni genera/cierra vacantes — son sistemas de registro distintos que comparten el mismo dato de "colaboradores activos" como fuente, no una tabla en común.

## Organigrama corporativo (holding)

Dirección Comercial, Sistemas, Recursos Humanos y Contraloría dan servicio a varias empresas/productos de la holding (no son estructura exclusiva de Mr. Lana). Se modelan como `Puesto` normales dentro del árbol A — no como un árbol aparte — para no duplicar el mecanismo de reporte/jerarquía ya existente. Pendiente: cargar con datos reales de quién ocupa cada posición del holding (responsable de Dirección Comercial, Sistemas, etc.) más allá de los puestos ya sembrados.
