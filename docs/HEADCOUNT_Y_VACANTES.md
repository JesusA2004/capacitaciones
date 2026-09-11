# Headcount y vacantes

Tres conceptos que se cruzan pero **no son lo mismo** — no confundirlos al leer código ni al construir UI nueva:

1. **Organigrama** (`docs/ORGANIGRAMA.md`): estructura de puestos y personas (quién reporta a quién), y matriz territorial (regiones/zonas/rutas y quién las cubre). Muestra cobertura, no cifras de plantilla.
2. **Headcount**: cuántas plazas están autorizadas vs. cuántas están realmente ocupadas, por sucursal y puesto. Vive aquí.
3. **Vacantes**: las plazas autorizadas que **faltan** por cubrir — se derivan de headcount, nunca se capturan a mano.

## Headcount

`App\Models\HeadcountTarget` (tabla `headcount_targets`) guarda la **plantilla autorizada** por (sucursal, puesto) — editable, viene del Excel real de dirección. La **plantilla actual** nunca se importa ni se captura: siempre se calcula en vivo contando colaboradores activos con esa sucursal/puesto (`App\Services\Headcount\HeadcountService`).

### Importar el Excel real

```bash
php artisan headcount:importar
```

Sin argumento, usa la ruta por defecto: `claude/headcount/HEADCOUNT GENERAL MR LANA 28-08-2026..xlsx` (ya versionado en el repo). También acepta una ruta explícita: `php artisan headcount:importar "ruta/al/archivo.xlsx"`. Si el archivo no existe, el comando avisa con un mensaje claro y termina con código de error — nunca rompe el deploy.

`App\Services\Headcount\HeadcountImportService` lee las hojas del Excel en bloques de dos sucursales por fila (columnas A-D y F-I), cada bloque con su propio encabezado `MODALIDAD | PLANTILLA AUTORIZADA | PLANTILLA ACTUAL | VACANTES` — solo se importa la columna de plantilla autorizada, el resto del Excel se ignora a propósito (esas columnas son historia del propio Excel, no la fuente de verdad del sistema). Modalidades del Excel se mapean a `Puesto` reales (`GESTOR DE RUTA`/`GESTORES DE RUTA` → Gestor fijo, `GESTOR VOLANTE` → Gestor volante, `COORDINADORA DE SUCURSAL` → Coordinadora, etc.); `INCAPACITADOS` se excluye a propósito (no es una modalidad operativa). Una sucursal o modalidad sin match en el sistema **no se crea silenciosamente** — se reporta en la salida del comando como pendiente. Un conflicto entre hojas para el mismo par (sucursal, puesto) también se reporta explícitamente (se conserva el primer valor leído). Idempotente: correrlo varias veces no duplica nada (upsert por sucursal+puesto).

### Cumplimiento / eficiencia

`HeadcountService::totalesGenerales()`/`resumenPorSucursal()`/`resumenPorPuesto()` calculan plantilla autorizada, plantilla actual, vacantes derivadas (`max(autorizada - actual, 0)`) y % de cumplimiento — usados tanto por la pantalla de Vacantes como por el KPI "Cumplimiento headcount" del dashboard (`docs/REPORTES.md`).

## Vacantes automáticas

`App\Services\Vacantes\VacanteAutoGenerationService::sincronizar($sucursalId, $puestoId)` abre o cierra una vacante marcada `generada_automaticamente = true` según si la plantilla autorizada sigue por encima de la actual — nunca duplica (a lo más una vacante automática abierta por par sucursal+puesto) ni toca vacantes creadas a mano por RH. Se llama automáticamente:

- Al aprobar una baja de colaborador (ver `docs/SOLICITUDES_UNIFICADAS.md`).
- Al terminar `headcount:importar` (`sincronizarTodo()`, recorre todos los pares con target).

Regla operativa: cuando alguien se da de baja, plantilla actual baja y la vacante sube sola; cuando alguien se da de alta en esa (sucursal, puesto), plantilla actual sube y la vacante se cierra sola. Nunca hay que "crear la vacante a mano" para que esto funcione.

## Matriz comercial vs. headcount

La matriz comercial (`App\Models\NodoComercial`, ver `docs/ORGANIGRAMA.md`) modela **rutas individuales** dentro de una zona (p. ej. "CUERNAVACA" tiene 12 rutas: Yautepec, Barona, Jiutepec...). El Excel de headcount **no trae ese detalle** — solo trae plantilla autorizada por zona (= `Sucursal`) y puesto. Por eso headcount sigue siendo por sucursal, no por ruta: la "cobertura" de una ruta individual (¿tiene gestor asignado hoy?) es un dato de la matriz comercial, independiente del cálculo de vacantes. Una zona puede tener 2 vacantes de "Gestor fijo" según headcount sin que eso diga automáticamente cuáles de sus rutas están cubiertas — eso se ve en Matriz comercial.
