# Matriz comercial

Árbol territorial **MATRIZ → Región → Zona (= Sucursal) → Rutas de cobro** (`App\Models\NodoComercial`, tabla `nodos_comerciales`). Responde *dónde se cobra y qué cartera tiene cada Gestor*; **no** dice quién reporta a quién (eso es el Organigrama) ni cuántas plazas hay (eso es Headcount). Ver `docs/ORGANIGRAMA.md`.

Pantalla: `Administración → Matriz comercial` (`administracion/matriz-comercial`).

## Regiones

Solo **Q1** y **Q3**. Q2 (Aguascalientes) no existe: no se siembra, no se muestra, no se puede elegir. `MatrizComercialSeeder` y `people:sincronizar-organigrama` la eliminan si una base anterior la tiene. Cada Región apunta a su puesto de gerente regional (`puesto_id` → «Gerente Regional Q1» / «Gerente Regional Q3»).

## Clasificación de las entradas de cada zona

La lista que entregó dirección mezcla rutas de cobro con posiciones de la sucursal. `App\Services\MatrizComercial\ClasificadorNodoComercial` clasifica cada entrada por su nombre:

| Clase | Ejemplos | Tipo de nodo | ¿Se asigna a un Gestor? |
|---|---|---|---|
| A. Ruta de cobro | YAUTEPEC, CUERNAVACA CENTRO | `ruta` | Sí |
| B. Posición de gerente | CUERNAVACA GTE, MIACATLAN GERENCIA, SJR - GTE | `gerencia` | **No** — es el Gerente de Sucursal (Organigrama) |
| C. Posición de subgerente | CUERNAVACA SUBGTE, ATLIX-SUBGTE | `subgerencia` | **No** — es el Subgerente |
| D. Volante | VOLANTE CUERNAVACA, VOLANTE 2 ORIZABA | `volante` | **No** — es la plaza de Gestor Volante |
| E. Ruta inactiva | ZACATEPEC (INACTIVA) | `ruta`, `activa = false` | No (no opera) |
| F. Cartera vencida / castigo | HUAMANTLA (VENCIDOS), HUAMANTLA (CASTIGO) | `ruta` (`metadata.estado_operativo`) | Sí |
| G. Operación grupal | GRUPALES SUR, GRUPALES NORTE | `ruta` (`metadata.clase = operacion_grupal`) | Sí |

Reglas de nombre (en orden): SUBGERENCIA/SUBGTE → C; GERENCIA/GTE → B; empieza con VOLANTE → D; (INACTIVA) → E; (VENCIDOS)/(CASTIGO) → F; empieza con GRUPALES → G; el resto → A.

`MatrizComercialService::asignarResponsable()`/`agregarApoyo()` **rechazan** asignar un gestor, apoyo o volante a una posición (B/C/D). En la pantalla, las posiciones aparecen aparte («Posiciones de la sucursal (ver Organigrama)») y el conteo de rutas, cobertura y el KPI del dashboard solo cuentan rutas.

Conteo de la carga actual: 118 rutas de cobro, 13 posiciones de gerente, 12 de subgerente, 14 volantes, 15 rutas inactivas, 2 de cartera especial y 2 grupales.

## Ruta, Gestor y Gestor Volante

- **Gestor**: un solo puesto «Gestor» (nunca «Gestor Ruta Centro»). La ruta es una **asignación** aparte (`user_nodo_comercial`, tipo `gestor`), **una vigente por gestor** y un gestor activo por ruta; asignar uno nuevo cierra al anterior conservando historial.
- **Gestor Volante**: plaza que cuenta en headcount, sin ruta fija. Cubre la ruta de un gestor que no asistió, una vacante temporal o apoya la operación (asignación tipo `volante`/`apoyo` sobre una ruta real).
- Una ruta **no crea headcount**: las plazas de Gestor vienen de la plantilla autorizada. Si una ruta queda sin titular, se ve «sin cubrir» y un volante puede cubrirla.

## Carga y sincronización

- `database/seeders/MatrizComercialSeeder.php`: idempotente, llave (padre, nombre) — reclasificar una entrada conserva su id e historial.
- `php artisan people:sincronizar-organigrama [--simular]`: reclasifica una base existente y reporta los conflictos (p. ej. una entrada GERENCIA que tenía un «gestor» asignado).
- Nunca se asignan personas a rutas desde un seeder de producción.
