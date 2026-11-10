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
- **Ocupado** = personas **vigentes** (activo / en incorporación) **titulares** de ese puesto en esa sucursal + coberturas «Ocupa ambos puestos» de esa plaza (ver abajo). Única fuente: `HeadcountService::plantillaActualPorSucursalPuesto()`.
- **Vacante** = autorizado − ocupado (por par sucursal/puesto, nunca negativo).
- **Fuera de plantilla** = persona real sin plaza autorizada asociada (se reporta aparte).
- **Una ruta no crea headcount**: las plazas de Gestor vienen de la plantilla autorizada; la ruta es una asignación de la matriz comercial (ver `docs/MATRIZ_COMERCIAL.md`).

### Una persona en dos puestos (2026-11-09)

`coberturas_puesto.ocupa_plaza` guarda la decisión de RH **en cada caso** (diálogo del organigrama, dos opciones):

| Opción | `ocupa_plaza` | Headcount | Organigrama |
|---|---|---|---|
| **Ocupa ambos puestos (sin vacante)** — p. ej. un Gerente de Sucursal que también es Gerente de Auditoría | `true` | La segunda plaza cuenta como **ocupada**; no hay vacante. | «También ocupa» |
| **Cubre mientras se contrata (con vacante)** — p. ej. el gerente de Córdoba atiende Cuernavaca mientras se contrata | `false` | No aumenta el autorizado ni cuenta como ocupada: la plaza sigue vacante (comportamiento de siempre). | «Cubre temporalmente» |

- Sin elegir (API o llamadas viejas), un puesto **de empresa** ocupa la plaza (`true`) y uno **de sucursal** no (`false`): es lo que pasaba antes de poder elegir. La migración `2026_11_14_100000_agrega_ocupa_plaza_a_coberturas_puesto` marca `true` las coberturas vigentes de puestos de empresa para que nada cambie al desplegar.
- Una cobertura «ocupa ambos» cuenta en la plaza que cubre: la sucursal cubierta o, para un puesto de empresa sin sucursal (corporativo / región), donde vive su plaza (Corporativo).
- Asignarla o quitarla resincroniza la vacante automática del par (`CoberturaPuestoService`); la baja o reactivación de esa persona también (`ColaboradorPlantillaObserver`). Auditoría: `activity_log` (`cobertura_asignada` con `ocupa_plaza` y modalidad; `cobertura_finalizada`) y movimiento «Cobertura temporal» en el expediente.
- Las reglas de jefe directo con cobertura no cambian (`docs/ORGANIGRAMA.md`).
- `poblacion()` reporta estas plazas en `segundas_plazas`: la persona es **una** vigente y ocupa dos plazas.

### Gestor fijo y Gestor volante (2026-11-09)

Antes el Gestor Volante era «la misma plaza que Gestor» (`puestos_equivalentes`). Ahora cada uno tiene **plazas propias** y sus propias vacantes:

- En Vacantes (web, API, resumen, Excel y PDF) el puesto Gestor se llama **«Gestor fijo»** (tiene ruta; la ruta se define en Matriz comercial) y Gestor Volante **«Gestor volante»** (sin ruta fija). `config/headcount.php` → `etiquetas_vacante` / `notas_vacante`. El catálogo de puestos no cambia.
- **Compatibilidad** (`config/headcount.php` → `plazas_compartidas`, `HeadcountService::repartirPlazasCompartidas()`): cada persona ocupa primero una plaza de su puesto; si ya no hay libre, ocupa una plaza **libre** del otro puesto de la pareja en la misma sucursal (un volante sin plaza de volante ocupa una de Gestor, y al revés). Así el total ocupado de Gestor + Volante es exactamente el de antes: nada que estaba ocupado pasa a vacante ni a «fuera de plantilla» por separar las plazas. Lo que todavía sobra se queda en su puesto (excedente o fuera de plantilla).
- Un alta, baja o cambio de un volante resincroniza las vacantes de **ambos** puestos de la pareja (`VacanteAutoGenerationService::sincronizarSinFallar()`), igual que capturar plazas de cualquiera de los dos.
- **«¿Esta sucursal tendrá volantes?»** (Administración → Sucursales: acción en la tarjeta y botón en el detalle; solo `headcount.editar`): Sí/No + cuántos + motivo → fija la plantilla autorizada de Gestor Volante en esa sucursal (0 si no) con `PlantillaAutorizadaService` (misma bitácora) y abre o cierra sus vacantes «Gestor volante» (`VolantesSucursalService`). El detalle de sucursal muestra gestores fijos vs. volantes (autorizadas, ocupadas, vacantes y personas con cada puesto).
- **Dato del negocio (2026-11-09)**: solo Orizaba y Córdoba tendrán 1 volante cada una; las demás, 0. **No hay seeder ni comando para esto** a propósito: `PlantillaAutorizadaSeeder` solo crea pares que faltan (nunca pisa lo capturado) y casi todas las sucursales ya traen un renglón de Gestor Volante del Excel (Cuernavaca 4, varias con 1). RH lo fija con el botón en cada sucursal, con su motivo, y queda en la bitácora. Mientras tanto, el reparto de plazas compartidas evita vacantes falsas: una plaza de volante sin volante solo es vacante si los gestores fijos no la están ocupando.

Observaciones del Excel real (28-08-2026), reportadas sin corregir: San Juan del Río no autoriza gerente ni subgerente; Tenango del Valle no autoriza gerente ni trae renglón de Gestor; Atlixco no autoriza coordinadora. Ninguna sucursal autoriza más de 1 gerente o 1 subgerente.

### Fuente única: `HeadcountService::poblacion()`

Tablero, Directorio, Reportes (`plantilla_conciliacion`) y la conciliación de plazas leen estas definiciones. Se calculan por par (sucursal, puesto) y se devuelven en `total`, `corporativo` y `sucursales`:

| Cifra | Definición |
|---|---|
| Autorizadas | Plazas de `headcount_targets`. |
| Activos | Colaboradores con estatus `activo`. Es «Personas activas» en Tablero y Directorio. |
| En incorporación | Vigentes por otra condición: contratados aún sin activar. Ocupan plaza, pero no aparecen en el Directorio. |
| Vigentes | Activos + en incorporación. |
| Ocupadas | `min(vigentes, autorizadas)` por par. Nunca excede lo autorizado. |
| Vacantes | `max(autorizadas − vigentes, 0)` por par. |
| Excedentes | Vigentes por encima de las plazas de un par que sí tiene plazas. |
| Fuera de plantilla | Vigentes en un par sin ninguna plaza autorizada. |
| Sin ubicación | Vigentes sin sucursal o sin puesto. Toda la empresa los cuenta en `sucursales` para no perderlos. |
| Cobertura | Ocupadas ÷ autorizadas × 100; 0 si no hay plazas autorizadas. |

| Segundas plazas | Plazas ocupadas por coberturas «Ocupa ambos puestos» (la persona ya cuenta como vigente en su propio puesto). |
| Bajas en trámite | Bajas registradas sin cerrar con último día ya llegado: **no cuentan** en ninguna otra cifra (finiquito pendiente o no). |
| Bajas programadas | Bajas registradas con último día futuro: siguen contando como vigentes hasta ese día. |

Invariantes: vigentes + segundas plazas = ocupadas + excedentes + fuera de plantilla + sin ubicación; autorizadas = ocupadas + vacantes; total = corporativo + sucursales. Las vacantes nunca se capturan a mano para cuadrar una cifra ni se duplican (a lo más una automática abierta por par); nunca se borran: se cancelan con motivo.

Por eso «Autorizadas − Activos» solo equivale a Vacantes cuando no hay excedentes, personas fuera de plantilla ni personas en incorporación. Nadie se oculta para cuadrar la cifra.

### Cuadre de plantilla (2026-11-10)

Caso real (Inicio de RH en producción): Autorizada 153, Activa 130, Vacantes 15 → 153 − 15 = 138 ≠ 130. La diferencia de 8 era exactamente «8 en incorporación»: la tarjeta Activa mostraba solo estatus `activo`, mientras Ocupadas/Vacantes/Cobertura (y la lista por sucursal, 110 + 28 = 138 ocupadas) cuentan a los vigentes. Regla decidida:

- **Ocupa plaza** = `HeadcountService::consultaOcupantes()`: vigentes (activo / en incorporación) **sin** una baja registrada (En trámite) cuyo último día ya llegó. Única regla; la usan ocupación, vacantes automáticas, tarjetas, lista por sucursal, detalle de sucursal y el comando.
- **En incorporación ocupa plaza**: la tarjeta **Activa** muestra vigentes («130 laborando + 8 en incorporación»), así Activa + Vacantes = Autorizada cuando no hay fuera de plantilla, excedentes ni segundas plazas.
- **Baja**: es baja desde su último día (`bajas.fecha_baja ≤ hoy`, estatus `en_revision_rh`) aunque el finiquito siga pendiente y el estatus laboral siga `activo` hasta `ejecutarBaja()`. Último día futuro = «baja programada»: sigue contando. Baja «No procede» (cancelada) vuelve a contar sola. `BajaPlantillaObserver` resincroniza la vacante al registrar/cancelar/cerrar/cambiar la fecha; `people:sincronizar-vacantes` (diario) cubre la llegada del último día.
- **Filas por sucursal** = `HeadcountService::poblacionPorSucursal()`: cada persona cuenta en la sucursal de su plaza; con alcance de toda la empresa hay una fila **«Sin sucursal asignada»**. Σ filas = total en cada cifra (`sumarFilas()`), y tarjetas, gráfica, lista (con fila de total al pie) y Organización → Sucursales → detalle leen esas mismas filas.
- **Cuadre visible** en el bloque Plantilla del Inicio: «153 autorizadas = 138 ocupadas + 15 vacantes», la línea personas vs. plazas cuando difieren (fuera de plantilla, excedentes, sin sucursal/puesto, segundas plazas), las bajas en trámite que ya no cuentan, y «¿Quiénes explican la diferencia?» con la lista nominal (`CuadrePlantillaService::diferencias()`, motivos en `App\Enums\MotivoDiferenciaPlantilla`). Si una invariante se rompiera, se muestra «No cuadra: …» en rojo.

Diagnóstico de solo lectura (producción incluida, no escribe nada):

```bash
php artisan headcount:diagnosticar-cuadre                       # toda la empresa
php artisan headcount:diagnosticar-cuadre --sucursal=Cuernavaca # una sucursal (nombre, clave o id)
php artisan headcount:diagnosticar-cuadre --sin-nombres         # solo cifras
```

Imprime por sucursal autorizadas, ocupadas, vacantes, activos, en incorporación, vigentes, fuera de plantilla, excedentes, sin ubicación, segundas plazas, bajas en trámite y programadas, subtotales Corporativo/Sucursales y TOTAL con «¿Cuadra?», verifica Σ filas = total y lista a cada persona que explica una diferencia (nombre, nº de empleado, estatus, puesto, sucursal registrada, motivo). Sale con código ≠ 0 si algo no cuadra.

### Conciliar plazas desde Excel

Configuración → Importar datos → **Plazas autorizadas** (solo `headcount.importar`, super_admin). Lee `Sucursal | Puesto | Plazas autorizadas`, con Corporativo como una ubicación más; el botón «Descargar formato» trae los catálogos válidos y ninguna plaza de ejemplo.

- Vista previa guardada en `importaciones_excel` (`tipo = plazas_autorizadas`) con conciliación antes → después; no escribe plazas.
- Una sucursal o puesto inexistente, un número inválido o un par duplicado bloquean toda la aplicación. Las plazas que el archivo no menciona se conservan y se listan.
- Aplicar exige motivo, rechaza el archivo ya aplicado y aborta si una plaza cambió desde la vista previa. Escribe con `PlantillaAutorizadaService` (bitácora con fuente `excel:plazas` y sincronización de vacantes) y registra la actividad en `headcount`.

### Cumplimiento / eficiencia

`HeadcountService::totalesGenerales()`/`resumenPorSucursal()`/`resumenPorPuesto()` calculan plantilla autorizada, plantilla ocupada, vacantes derivadas y % de cumplimiento — usados por el detalle de sucursal y por el KPI "Cumplimiento headcount" del dashboard (`docs/REPORTES.md`).

**Se calcula por par (sucursal, puesto) y luego se suma**: ocupada = `min(personas, autorizada)` y vacantes = `max(autorizada − personas, 0)`. Así el cumplimiento **nunca pasa de 100 %** y no existe "excedente": una persona en un puesto sin plantilla autorizada en esa sucursal (p. ej. un puesto del Corporativo mal asignado) no cuenta como cobertura ni tapa la vacante de otro puesto. El detalle de sucursal la reporta aparte («N personas en puestos sin plantilla autorizada») para que RH la reubique o capture la plantilla. Antes se sumaba todo y aparecían cifras como 280 % o 300 %.

### Dónde se ve

- **Inicio operativo (Tablero de RH)** → bloque «Plantilla»: plantilla activa (plazas ocupadas por colaboradores vigentes), capacidad autorizada y vacantes reales, todo de `HeadcountService::totalesGenerales()` + `VacantesListadoService` (mismo camino que RH → Vacantes). Nunca cuenta expedientes/carpetas del NAS: una baja (`inactivo`/`suspendido`) no ocupa plaza aunque su expediente siga ahí; «en incorporación» sí ocupa plaza. `DashboardHeadcountTest` verifica que Inicio, tablero, Sucursales y Vacantes den las mismas cifras.
- **Organización > Sucursales > detalle**: el headcount de esa sucursal — plantilla autorizada vs. ocupada por puesto (barras), gestores fijos vs. volantes con el botón «¿Esta sucursal tendrá volantes?», dona de cobertura, personas por departamento, domicilio/teléfono y su **Gerente de Sucursal** (no existe otra figura de "responsable").
- **RH > Vacantes**: la lista de vacantes reales (ver abajo), no la tabla de plantilla por sucursal.

## Corporativo

`CORP01` ("Corporativo", Subida del Club 114, Cuernavaca) es donde vive todo lo general y de servicio a las sucursales: Sistemas, RH, Mesa de Control, Tesorería, Contraloría, Dirección. **Analista de Mesa de Control no existe por sucursal**: todos están en el Corporativo. Domicilios y teléfonos oficiales de las sucursales: `database/data/sucursales_oficiales.php` (fuente: mr-lana.com/sucursales; San Juan del Río y Tenango del Valle no aparecen ahí y quedan sin domicilio).

## Vacantes automáticas

**Puestos de empresa** (corporativos y regionales): se ocupan a nivel empresa. Un puesto activo **sin ningún renglón de plaza**, sin titular ni cobertura «Ocupa ambos puestos», recibe 1 plaza en Corporativo y su vacante automática (`PlazasEmpresaService`; corre al desplegar, con `people:sincronizar-organigrama` y a diario con `people:sincronizar-vacantes`). **Nunca pisa lo que RH autorizó** (ni un 0 ni un 4): solo crea la plaza automática cuando el puesto no tiene ninguna. Un titular registrado en otra sucursal cuenta en la plaza, así que no abre vacante falsa. Una cobertura cuenta como ocupada solo si RH eligió «Ocupa ambos puestos» (ver «Una persona en dos puestos»). `Dirección General` queda fuera (`headcount.puestos_sin_plaza_automatica`). Los puestos de sucursal sin plaza no se inventan: el comando los lista.

**Plazas autorizadas de cualquier puesto** (2026-11-09): Puestos y salarios → ficha del puesto → tarjeta **«Plantilla autorizada»** lista sus plazas por ubicación (Corporativo primero, luego sucursales) con ocupadas y vacantes, y RH (`headcount.editar`) edita o autoriza en otra ubicación con motivo obligatorio (`PUT administracion/puestos/{puesto}/plantilla`, `PlantillaPuestoController` → `PlantillaAutorizadaService`, misma bitácora que el detalle de sucursal; `PlantillaPuestoService` arma la tarjeta). Caso real: «Auditora · Contraloría · 1 persona» nunca tenía vacantes porque solo recibía la plaza automática; con 4 plazas en Corporativo se abren 3 vacantes. El catálogo muestra la columna «Plazas autorizadas» (ocupadas / autorizadas · vacantes).

**Regla única** (`HeadcountService::plantillaDePar()`):

```
faltantes = max(plantilla autorizada − ocupados reales, 0)
```

Ocupados reales = colaboradores vigentes (activo / en incorporación) titulares de ese puesto en esa sucursal, más coberturas «Ocupa ambos puestos» y el reparto de plazas compartidas Gestor volante ↔ Gestor. `App\Services\Vacantes\VacanteAutoGenerationService::sincronizar($sucursalId, $puestoId)` abre, ajusta o cierra la vacante marcada `generada_automaticamente = true` con esa cifra — nunca duplica (a lo más una automática abierta por par sucursal+puesto; si quedaran dos, se cancela la más nueva) ni toca vacantes creadas a mano por RH. Se llama automáticamente:

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

### Eliminar un puesto (2026-11-02)

Caso real: se eliminó un puesto corporativo que no tenía a nadie y RH → Vacantes siguió mostrando «Puesto sin definir · Corporativo · 1 plaza por cubrir · 0 de 1 ocupadas». Causa: `Puesto` usa **SoftDeletes**, así que los `cascadeOnDelete`/`nullOnDelete` de `headcount_targets` y `vacantes` nunca se disparan. La plaza automática de Corporativo (`PlazasEmpresaService`, puesto de empresa sin titular) y su vacante quedaban vivas, y el listado ya no encontraba el nombre del puesto.

Regla (`App\Services\Puestos\EliminacionPuestoService`, usada por `Administracion\PuestoController::destroy`):

- **Se bloquea**, con el motivo en pantalla, si el puesto sigue en la estructura: personas vigentes, puestos que le reportan en el organigrama (no se reconectan solos: RH decide a quién reportan), una cobertura temporal vigente, un nodo activo de la matriz comercial o candidatos en proceso en sus vacantes.
- Si no, en **una transacción**: sus plazas autorizadas pasan a 0 con renglón en `headcount_target_historial` (fuente `puesto_eliminado`), sus vacantes abiertas —automáticas y manuales— se **cancelan** con motivo (nunca se borran), se limpia la ruta de crecimiento que apuntaba a él y el puesto se desactiva y se elimina (lógico). Queda `puesto_eliminado` en la auditoría.
- Defensa en vivo: `HeadcountService` no cuenta plazas de un puesto eliminado, `people:sincronizar-vacantes` cierra su automática («el puesto se eliminó del catálogo») y el listado muestra el nombre real con «(puesto eliminado)» en el historial, nunca «Puesto sin definir».

Reparación de puestos eliminados antes de esta regla (producción, idempotente, no borra nada):

```bash
php artisan people:reparar-puestos-eliminados --simular   # solo muestra qué haría
php artisan people:reparar-puestos-eliminados             # aplica (queda en la auditoría)
```

Libera plazas y cancela vacantes abiertas de cada puesto eliminado, cancela automáticas abiertas sin puesto, y solo **reporta** lo que pide decisión de RH (personas vigentes o puestos que todavía reportan al eliminado).

Crear un puesto o cambiar sus plazas: el organigrama lo lee en vivo; capturar plazas (`PlantillaAutorizadaService`) abre, ajusta o cierra la vacante al momento; un puesto de empresa nuevo sin titular recibe su plaza en Corporativo con la siguiente corrida de `people:sincronizar-vacantes` / `people:sincronizar-organigrama`.

### Pantalla RH > Vacantes

Arriba, **totales concretos** de lo que se está viendo (sin tarjetas de KPIs ni costos): cuántas plazas faltan de cada puesto —gerentes, gestores, etc.— y en qué sucursales (`VacantesListadoService::resumen()`); un clic en el puesto o en la sucursal filtra la lista. El costo **no** vive en Vacantes: el costo por colaborador contratado está en Campañas.

Debajo, una fila por **vacante real**: qué puesto falta, en qué sucursal, cuántas plazas, desde cuándo (días abierta), cuántos candidatos lleva y la plantilla de ese par como contexto («4 de 5 ocupadas · 1 vacante en plantilla»). Por defecto solo activas (sin cubiertas ni canceladas). La regla (alcance, filtros, KPIs) vive en `App\Services\Vacantes\VacantesListadoService`, compartido por la web (`Rh\VacanteController`) y la API móvil (`Api\V1\Rh\VacanteController`; ahora también devuelve solo activas si no se pide `estado`).

Incluye **todas las ubicaciones**: las vacantes del Corporativo (Auditoría, Contraloría, Sistemas…) se listan igual que las de sucursal, marcadas «Corporativo»; el alcance por módulo nunca oculta `sucursal_id` de Corporativo ni nulo a quien tiene alcance «Todos». Cada vacante muestra su **área / departamento** (el de la vacante o, si no lo trae —las automáticas casi nunca—, el de su puesto), hay chips de área para filtrar con un clic y el filtro «Área / departamento» usa la misma regla. El puesto Gestor se ve como «Gestor fijo» (con la aclaración «la ruta se define en Matriz comercial») y Gestor Volante como «Gestor volante», también en la API (`puesto`, `puesto_nota`, `departamento`, `es_corporativo`; acepta `departamento_id`) y en Excel/PDF (columnas «Área / departamento» y «Ubicación»).

## Matriz comercial vs. headcount

La matriz comercial (`App\Models\NodoComercial`, ver `docs/ORGANIGRAMA.md`) modela **rutas individuales** dentro de una zona (p. ej. "CUERNAVACA" tiene 12 rutas: Yautepec, Barona, Jiutepec...). El Excel de headcount **no trae ese detalle** — solo trae plantilla autorizada por zona (= `Sucursal`) y puesto. Por eso headcount sigue siendo por sucursal, no por ruta: la "cobertura" de una ruta individual (¿tiene gestor asignado hoy?) es un dato de la matriz comercial, independiente del cálculo de vacantes. Una zona puede tener 2 vacantes de "Gestor" según headcount sin que eso diga automáticamente cuáles de sus rutas están cubiertas — eso se ve en Matriz comercial.

## Campañas: costo por colaborador

RH → Campañas muestra, por campaña, cuántos colaboradores contrató y **cuánto costó cada uno** (monto ÷ contratados atribuidos; misma atribución que `CostoReclutamientoService`: candidatos registrados como venidos de la campaña o, si no hay, los contratados de su vacante). Sin contratados todavía se muestra «Sin contratados aún» — no se inventa un costo. Arriba, una línea con los totales del periodo filtrado: gasto, colaboradores contratados (sin contar dos veces a la misma persona) y costo promedio por colaborador.
