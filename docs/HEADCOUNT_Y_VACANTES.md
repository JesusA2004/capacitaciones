# Headcount y vacantes

Tres conceptos que se cruzan pero **no son lo mismo** — no confundirlos al leer código ni al construir UI nueva:

1. **Organigrama** (`docs/ORGANIGRAMA.md`): estructura de puestos y personas (quién reporta a quién), y matriz territorial (regiones/zonas/rutas y quién las cubre). Muestra cobertura, no cifras de plantilla.
2. **Headcount**: cuántas plazas están autorizadas vs. cuántas están realmente ocupadas, por sucursal y puesto. Vive aquí.
3. **Vacantes**: las plazas autorizadas que **faltan** por cubrir — se derivan de headcount, nunca se capturan a mano.

## Headcount

`App\Models\HeadcountTarget` (tabla `headcount_targets`) guarda la **plantilla autorizada** por (sucursal, puesto) — editable, viene del Excel real de dirección. La **plantilla actual** nunca se importa ni se captura: siempre se calcula en vivo contando colaboradores activos con esa sucursal/puesto (`App\Services\Headcount\HeadcountService`).

### Carga inicial (seeder) y captura de RH

`PlantillaAutorizadaSeeder` corre dentro de `DatabaseSeeder` (también en producción) y carga el Excel real **solo para los pares (sucursal, puesto) que aún no existen**: nunca pisa lo que RH ya capturó. Sin esto todas las sucursales arrancaban con plantilla 0.

Después, **solo Gerencia de RH** (rol `rh_admin`, además de `super_admin`; permiso `headcount.editar`, `SucursalPolicy::editarPlantilla`) cambia la plantilla en Administración → Sucursales → detalle (lápiz por puesto o «Agregar puesto»), con motivo obligatorio. Cada cambio —captura o importación— queda en `headcount_target_historial` (quién, cuándo, antes → después, motivo, fuente) y se ve en el mismo detalle. La regla vive en `AppServicesHeadcountPlantillaAutorizadaService`; al guardar se resincroniza la vacante automática del par.

### Importar el Excel real

```bash
php artisan headcount:importar
```

Sin argumento, usa la ruta por defecto: `claude/headcount/HEADCOUNT GENERAL MR LANA 28-08-2026..xlsx` (ya versionado en el repo). También acepta una ruta explícita: `php artisan headcount:importar "ruta/al/archivo.xlsx"`. Si el archivo no existe, el comando avisa con un mensaje claro y termina con código de error — nunca rompe el deploy.

`App\Services\Headcount\HeadcountImportService` lee las hojas del Excel en bloques de dos sucursales por fila (columnas A-D y F-I), cada bloque con su propio encabezado `MODALIDAD | PLANTILLA AUTORIZADA | PLANTILLA ACTUAL | VACANTES` — solo se importa la columna de plantilla autorizada, el resto del Excel se ignora a propósito (esas columnas son historia del propio Excel, no la fuente de verdad del sistema). Modalidades del Excel se mapean a `Puesto` reales (`GESTOR DE RUTA`/`GESTORES DE RUTA` → Gestor, `GESTOR VOLANTE` → Gestor Volante, `COORDINADORA DE SUCURSAL` → Coordinadora de Sucursal, `GERENTE` → Gerente de Sucursal, `SUBGERENTE` → Subgerente); `INCAPACITADOS` se excluye a propósito (no es una modalidad operativa). Una sucursal o modalidad sin match en el sistema **no se crea silenciosamente** — se reporta en la salida del comando como pendiente. Un conflicto entre hojas para el mismo par (sucursal, puesto) también se reporta explícitamente (se conserva el primer valor leído). Idempotente: correrlo varias veces no duplica nada (upsert por sucursal+puesto).

### Plazas, ocupados, coberturas (reglas)

Headcount cuenta **posiciones autorizadas**, no personas actuales. Por sucursal, típicamente: Gerente de Sucursal 1, Subgerente 1, Gestor N, Gestor Volante 1 si corresponde, Coordinadora de Sucursal 1.

- **Autorizado** = suma de plazas (`headcount_targets`).
- **Ocupado** = personas **titulares** (colaboradores activos con ese puesto en esa sucursal).
- **Vacante** = autorizado − ocupado (por par sucursal/puesto, nunca negativo).
- **Fuera de plantilla** = persona real sin plaza autorizada asociada (se reporta aparte).
- **Cobertura temporal** (`coberturas_puesto`) **no** aumenta el autorizado ni cuenta como ocupado: si el gerente de Córdoba cubre Cuernavaca, Cuernavaca sigue con su plaza de gerente vacante y Córdoba sigue con 1 ocupado (no son 2 empleados ni 2 puestos).
- **Gestor Volante** sí cuenta dentro de la plantilla (equivale a la plaza de Gestor para plantilla/vacantes, `config/headcount.php`) aunque no tenga ruta fija.
- **Una ruta no crea headcount**: las plazas de Gestor vienen de la plantilla autorizada; la ruta es una asignación de la matriz comercial (ver `docs/MATRIZ_COMERCIAL.md`).

Observaciones del Excel real (28-08-2026), reportadas sin corregir: San Juan del Río no autoriza gerente ni subgerente; Tenango del Valle no autoriza gerente ni trae renglón de Gestor; Atlixco no autoriza coordinadora. Ninguna sucursal autoriza más de 1 gerente o 1 subgerente.

### Cumplimiento / eficiencia

`HeadcountService::totalesGenerales()`/`resumenPorSucursal()`/`resumenPorPuesto()` calculan plantilla autorizada, plantilla ocupada, vacantes derivadas y % de cumplimiento — usados por el detalle de sucursal y por el KPI "Cumplimiento headcount" del dashboard (`docs/REPORTES.md`).

**Se calcula por par (sucursal, puesto) y luego se suma**: ocupada = `min(personas, autorizada)` y vacantes = `max(autorizada − personas, 0)`. Así el cumplimiento **nunca pasa de 100 %** y no existe "excedente": una persona en un puesto sin plantilla autorizada en esa sucursal (p. ej. un puesto del Corporativo mal asignado) no cuenta como cobertura ni tapa la vacante de otro puesto. El detalle de sucursal la reporta aparte («N personas en puestos sin plantilla autorizada») para que RH la reubique o capture la plantilla. Antes se sumaba todo y aparecían cifras como 280 % o 300 %.

### Dónde se ve

- **Administración > Sucursales > detalle**: el headcount de esa sucursal — plantilla autorizada vs. ocupada por puesto (barras), dona de cobertura, personas por departamento, domicilio/teléfono y su **Gerente de Sucursal** (no existe otra figura de "responsable").
- **RH > Vacantes**: la lista de vacantes reales (ver abajo), no la tabla de plantilla por sucursal.

## Corporativo

`CORP01` ("Corporativo", Subida del Club 114, Cuernavaca) es donde vive todo lo general y de servicio a las sucursales: Sistemas, RH, Mesa de Control, Tesorería, Contraloría, Dirección. **Analista de Mesa de Control no existe por sucursal**: todos están en el Corporativo. Domicilios y teléfonos oficiales de las sucursales: `database/data/sucursales_oficiales.php` (fuente: mr-lana.com/sucursales; San Juan del Río y Tenango del Valle no aparecen ahí y quedan sin domicilio).

## Vacantes automáticas

**Regla única** (`HeadcountService::plantillaDePar()`):

```
faltantes = max(plantilla autorizada − ocupados reales, 0)
```

Ocupados reales = colaboradores vigentes (activo / en incorporación) titulares de ese puesto en esa sucursal (el Gestor volante cuenta como Gestor). `App\Services\Vacantes\VacanteAutoGenerationService::sincronizar($sucursalId, $puestoId)` abre, ajusta o cierra la vacante marcada `generada_automaticamente = true` con esa cifra — nunca duplica (a lo más una automática abierta por par sucursal+puesto; si quedaran dos, se cancela la más nueva) ni toca vacantes creadas a mano por RH. Se llama automáticamente:

- **Cualquier** alta, baja, reactivación, cambio de puesto o de sucursal de un colaborador, venga de donde venga (alta digital, baja, movimiento laboral, edición de expediente, **migración inicial**, API): `App\Observers\ColaboradorPlantillaObserver` sincroniza el par de origen y el de destino al confirmar la transacción. Antes solo lo hacían algunos flujos y quedaban vacantes «1 plaza por cubrir» con la plaza ya ocupada (caso real: Coordinadora de Sucursal en Cuernavaca, «1 de 1 autorizadas ocupadas»).
- Al capturar plantilla en el detalle de sucursal.
- Al terminar `headcount:importar` (`sincronizarTodo()`).

Resincronizar todo (producción incluida, idempotente, no toca vacantes manuales, no borra nada; cierra con motivo):

```bash
php artisan people:sincronizar-vacantes --simular   # solo muestra qué haría
php artisan people:sincronizar-vacantes             # aplica (queda en la auditoría)
```

El listado (web y API) además se protege solo: una automática abierta cuya plaza ya no falta no se muestra como vacante real, y sus «plazas por cubrir» siempre salen del cálculo en vivo, nunca de la columna guardada.

Regla operativa: cuando alguien se da de baja, plantilla actual baja y la vacante sube sola; cuando alguien se da de alta en esa (sucursal, puesto), plantilla actual sube y la vacante se cierra sola. Nunca hay que "crear la vacante a mano" para que esto funcione.

### Pantalla RH > Vacantes

Arriba, **totales concretos** de lo que se está viendo (sin tarjetas de KPIs ni costos): cuántas plazas faltan de cada puesto —gerentes, gestores, etc.— y en qué sucursales (`VacantesListadoService::resumen()`); un clic en el puesto o en la sucursal filtra la lista. El costo **no** vive en Vacantes: el costo por colaborador contratado está en Campañas.

Debajo, una fila por **vacante real**: qué puesto falta, en qué sucursal, cuántas plazas, desde cuándo (días abierta), cuántos candidatos lleva y la plantilla de ese par como contexto («4 de 5 ocupadas · 1 vacante en plantilla»). Por defecto solo activas (sin cubiertas ni canceladas). La regla (alcance, filtros, KPIs) vive en `App\Services\Vacantes\VacantesListadoService`, compartido por la web (`Rh\VacanteController`) y la API móvil (`Api\V1\Rh\VacanteController`; ahora también devuelve solo activas si no se pide `estado`).

## Matriz comercial vs. headcount

La matriz comercial (`App\Models\NodoComercial`, ver `docs/ORGANIGRAMA.md`) modela **rutas individuales** dentro de una zona (p. ej. "CUERNAVACA" tiene 12 rutas: Yautepec, Barona, Jiutepec...). El Excel de headcount **no trae ese detalle** — solo trae plantilla autorizada por zona (= `Sucursal`) y puesto. Por eso headcount sigue siendo por sucursal, no por ruta: la "cobertura" de una ruta individual (¿tiene gestor asignado hoy?) es un dato de la matriz comercial, independiente del cálculo de vacantes. Una zona puede tener 2 vacantes de "Gestor" según headcount sin que eso diga automáticamente cuáles de sus rutas están cubiertas — eso se ve en Matriz comercial.

## Campañas: costo por colaborador

RH → Campañas muestra, por campaña, cuántos colaboradores contrató y **cuánto costó cada uno** (monto ÷ contratados atribuidos; misma atribución que `CostoReclutamientoService`: candidatos registrados como venidos de la campaña o, si no hay, los contratados de su vacante). Sin contratados todavía se muestra «Sin contratados aún» — no se inventa un costo. Arriba, una línea con los totales del periodo filtrado: gasto, colaboradores contratados (sin contar dos veces a la misma persona) y costo promedio por colaborador.
