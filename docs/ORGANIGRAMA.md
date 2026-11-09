# Organigrama

Cinco conceptos relacionados que **no se fusionan** — no los confundas al leer código ni al construir UI:

| Concepto | Qué responde | Dónde vive |
|---|---|---|
| **Organigrama** | ¿Quién reporta a quién? | `puestos.puesto_superior_id` + colaboradores (`App\Services\Organigrama\OrganigramaPersonasService`) |
| **Matriz comercial** | Región → Sucursal/Zona → Rutas de cobro | `nodos_comerciales` (ver `docs/MATRIZ_COMERCIAL.md`) |
| **Ruta** | ¿Qué cartera cobra cada Gestor? | Asignación `user_nodo_comercial` (tipo `gestor`), una vigente por gestor |
| **Headcount** | ¿Cuántas plazas están autorizadas y cuántas ocupadas? | `headcount_targets` (ver `docs/HEADCOUNT_Y_VACANTES.md`) |
| **Cobertura temporal** | ¿Quién cubre un puesto vacante sin dejar el suyo? | `coberturas_puesto` |

## Estructura confirmada (dirección, 2026-10-06)

Reemplaza la versión anterior del 2026-09-29: Sistemas, Recursos Humanos y
Contraloría dejaron de depender de Dirección Comercial — ahora son áreas
directas de Dirección General, al mismo nivel que ella.

```
Dirección General
├── Dirección Comercial
│   ├── Asistente de Dirección Comercial          1 plaza
│   ├── Gerente de Mesa de Control
│   │   └── Analista de Mesa de Control
│   ├── Coordinadora Regional                     vive en Corporativo
│   │   └── Coordinadora de Sucursal              1 por sucursal (Corporativo NO tiene)
│   └── Gerente Regional                          1 plaza de empresa
│       ├── Subgerente Regional                   1 plaza de empresa, apoyo del Gerente Regional
│       └── Gerente de Sucursal                   exactamente 1 por sucursal
│           └── Subgerente                        exactamente 1 por sucursal
│               ├── Gestor                        1 plaza + 1 ruta de cobro vigente
│               └── Gestor Volante                plaza de plantilla, SIN ruta fija
├── Responsable de Sistemas
│   └── Monitorista                                hoy 1
├── Gerencia de Recursos Humanos
│   ├── Administración de Personal
│   └── Reclutamiento
└── Gerente de Contraloría
    ├── Auditora      (crecimiento: Gerente de Contraloría)
    ├── Tesorero
    └── Contador
```

Definición única en código: `App\Services\Organigrama\SincronizadorOrganigramaService` (la usa `PuestoJerarquiaSeeder` y el comando de sincronización). No se inventan otros puestos.

**Mesa de Control y Contraloría** son áreas distintas (una sola definición: `SincronizadorOrganigramaService::ESTRUCTURA`): Mesa de Control sigue bajo Dirección Comercial; Contraloría reporta directo a Dirección General, al mismo nivel que Dirección Comercial — nunca cuelga de ella ni de Mesa de Control.

**Fuera de la estructura confirmada, conservados sin cambios** (decisión de dirección): Asistente de Dirección General y Gestor grupal. El comando de sincronización los lista en «Puestos fuera de la estructura confirmada».

### Gerente y Subgerente Regional (regla confirmada por la dueña del negocio, 2026-11-01)

Sustituye la cadena del 2026-10-30, que colgaba la sucursal solo del Subgerente Regional. Orden de autoridad: **Gerente Regional > Subgerente Regional > Gerente de Sucursal > Subgerente de Sucursal > Gestores**.

- **Gerente Regional:** plaza única, jefa directa de los Gerentes de Sucursal y del Subgerente Regional. Actúa sobre toda la línea de abajo y puede cambiar lo que cualquiera de ellos hizo. Se resuelve por puesto en la base, nunca por el nombre de una persona; ningún proceso la renombra ni la reemplaza.
- **Subgerente Regional:** su apoyo, le reporta. Decide sobre Gerentes y Subgerentes de Sucursal y Gestores, pero nunca cambia lo hecho por la Gerente Regional.
- **Quién responde por una sucursal:** ambos, titular o cobertura (`OrganizacionJerarquiaService::regionalVigente`): los dos pueden dar el visto bueno regional y decidir intervenciones. Si los dos faltan, la regional de respaldo; nunca Dirección Comercial. Con la Gerencia Regional vacante, el jefe del Gerente de Sucursal es el Subgerente Regional por esa misma regla de respaldo.
- **Alcance:** las dos plazas alcanzan todas las sucursales operativas aunque la persona esté registrada en una. Requieren el rol `gerente_regional`: si un titular no lo tiene, `people:sincronizar-organigrama` lo reporta como conflicto.

**Quién modifica o reabre qué, y quién aprueba una entrevista:** ver `docs/JERARQUIA_PUESTOS.md`.

**«Regional de Operaciones»:** sin rango en `linea_comercial` y sin plaza confirmada hasta que la dueña del negocio lo aclare (2026-11-01). Los ejemplos de abajo son históricos. No se reasigna a nadie: quien lo ocupe aparece como conflicto en `people:sincronizar-organigrama` y necesita que RH le asigne «Gerente Regional» o «Subgerente Regional» para volver a tener alcance de empresa y mando sobre la línea.

La lámina de Inducción (`config/organigrama.php` → `laminas.comercial`) dibuja la línea en orden de autoridad, no de reporte directo.

Q1 y Q3 siguen en la matriz comercial solo como dato territorial, sin puesto ligado. La clasificación Q1/Q3 se retiró de la UI y de las aprobaciones; `clasificaciones_region` se conserva como historial. `people:sincronizar-organigrama` reutiliza el puesto ya capturado aunque esté escrito distinto («Sub Gerente Regional»), une Q1/Q3 si solo uno tiene titular y, con titulares en varios, reporta el conflicto sin elegir a nadie. La migración `2026_11_04_200000_gerente_de_sucursal_reporta_a_gerente_regional` aplica el cambio de «reporta a» al desplegar, recalcula jefes y deja los conflictos en el log.

### Organigrama editable (General · Por personas)

Una tarjeta por persona o plaza (foto/iniciales, nombre, puesto, sucursal, chip Q1/Q3, «Cubre temporalmente», vacante punteada), en un lienzo con zoom (Ctrl + rueda / botones) y arrastre; en celular, lista jerárquica desplegable. Al tocar una tarjeta se abre el panel con sus acciones (solo `organigrama.editar`: RH / super admin; el backend lo valida con `ColaboradorPolicy::moverEnOrganigrama/clasificarRegion` y `CoberturaPuestoPolicy`):

| Tarjeta | Acciones |
|---|---|
| Persona | Subir de puesto · Bajar de puesto · Mover bajo… · Cambiar de puesto (traspaso) · (Gerente de Sucursal) Q1 / Q3 / Por clasificar |
| Plaza vacante | Cubre temporalmente este puesto |
| Plaza cubierta | Quitar cobertura (un clic) |

**Subir / Bajar / Mover bajo…** (`MovimientoOrganigramaService`) nunca escriben el jefe: cambian la PLAZA (puesto, sucursal y, si aplica, lado Q1/Q3) por la misma puerta que «Datos laborales» (`DatosExpedienteService::actualizarLaborales` → movimiento laboral + `JefeDirectoService::sincronizar`). Antes de aplicar se muestra «Vas a mover a X de Y a Z» con el jefe actual → jefe nuevo, calculado sin guardar (`OrganigramaPersonasService::jefesDerivados($simulacion)`), y avisos de headcount. «Mover bajo…» ofrece las plazas del puesto superior (personas, vacantes o coberturas) en otras sucursales/lados; el Corporativo nunca es destino de un puesto de sucursal. Arrastrar y soltar no se implementó a propósito: el menú por nodo con confirmación es más estable y auditable.

**Cambiar de puesto (traspaso)** (`TraspasoPuestoService`, rutas `administracion.jerarquia-puestos.traspaso.*`, mismo permiso `ColaboradorPolicy::moverEnOrganigrama` por persona): pasa a una o **varias** personas a **cualquier** puesto activo — p. ej. Gestores de Cuernavaca a Auditora (Contraloría). Diálogo `DialogoTraspasoPuesto.vue` con buscadores de lista completa al enfocar (personas por nombre, número, puesto, sucursal o área; puesto por nombre o área). Reglas de destino de `MovimientoOrganigramaService::destino(…, corporativoFijo: true)`: un puesto corporativo queda **siempre** en Corporativo; un puesto de sucursal **nunca** en Corporativo (sin sucursal elegida, cada quien conserva la suya). La vista previa (sin guardar) muestra por persona puesto/sucursal/área de → a, tipo de movimiento (promoción o cambio de puesto, por nivel), jefe actual → jefe nuevo (simulando a todas a la vez) y «Dejará libre la ruta X»; en conjunto, el efecto en headcount con `HeadcountService::plantillaDePar` (solo lectura, con la pareja de plazas compartidas Gestor Volante ↔ Gestor): «Se abrirá 1 vacante de Gestor Volante en Cuernavaca», «Ocupará 1 plaza autorizada de Auditora en Corporativo» o «Quedará fuera de plantilla (no hay plaza autorizada…)». Al aplicar: rutas de cobro cerradas (`MatrizComercialService::cerrarAsignacionesDe`, la ruta queda «sin cubrir» en Matriz comercial; entrar a Gestor nunca asigna ruta), plaza por `aplicarDestino` (la misma puerta de Datos laborales) con motivo «Traspaso de puesto» y el comentario opcional en observaciones, bitácora `traspaso_puesto`. La vacante del origen la abre `ColaboradorPlantillaObserver` y el traspaso la marca con motivo «Reemplazo». **No es baja ni alta**: no crea filas en `bajas`, no registra movimientos alta/baja ni toca la fecha de ingreso, así que no cuenta en rotación, ingresos ni egresos. No existe equivalente en la API móvil (las acciones del organigrama son solo web).

**Acomodo** (`resources/js/lib/organigramaLayout.ts`, probado en `tests/js/organigrama-layout.test.mjs`): cada nivel en su fila, ramas acercadas por contornos (una rama chica queda junto a la grande, el padre centrado sobre su primer y último hijo), hojas de un mismo jefe apiladas en columna y conectores ortogonales en SVG. Sustituye al árbol de flexbox anidado, donde cada rama se alineaba por su cuenta.

### Ocupación = vigentes (2026-11-09)

Toda vista de ocupación (árbol por personas, láminas, Por puestos, perfil de puesto, detalle de sucursal, sincronizador) cuenta a los **vigentes** (`EstadoUsuario::valoresVigentes()`: activo + en incorporación), igual que el headcount. Quien firmó contrato y está en Inducción, Evaluación o Capacitación inicial ya trabaja y ocupa su plaza.

- **Chip de incorporación** (pastel `bg-info-soft`): la fase real del tablero de candidatos (Contratación, Inducción, Evaluación de inducción…; `FaseContratadoService` vía el candidato con `candidatos.colaborador_id`) o «En incorporación» si no viene de un candidato. `App\Services\Organigrama\IncorporacionOrganigramaService`.
- **«Por ingresar: <nombre>»**: plaza VACANTE con un candidato ya **autorizado por RH** (estado `autorizado_rh`, fase 6 «Contratación») ligado a una vacante de ese puesto (y sucursal, si es de sucursal) que todavía no tiene colaborador (`ContratacionCandidatoService` lo crea al iniciar la contratación). «Autorización de RH» pendiente no cuenta: aún puede rechazarse. En headcount la plaza sigue vacante.
- **Láminas:** una caja cuyo patrón coincide con un puesto activo del catálogo y sin nadie vigente dice «Vacante» (como RH → Vacantes); una caja cuyo patrón no coincide con **ningún** puesto (p. ej. «Gerente de Auditoría», «Auditores de Sucursales» si no existen) dice «Puesto no registrado» (`registrado = false`), con la sugerencia de registrarlo en Puestos.

### Puestos sin ubicar y «Ubicar / mover puesto» (2026-11-09)

- **Bandeja «Puestos sin ubicar en el organigrama»** (General, ambas vistas; `PuestosSinUbicarService`): puestos activos a los que no se llega desde Dirección General caminando por puestos activos — sin «reporta a», superior inactivo/eliminado o rama suelta/ciclo (`App\Enums\MotivoPuestoSinUbicar`) — con su departamento y ocupantes.
- **«Ubicar / mover puesto»** (`UbicacionPuestoService`, `UbicacionPuestoController`; desde la bandeja, el panel de «Por puestos» y el panel de una tarjeta del árbol): 1) «¿Debajo de quién va?», buscador con todos los puestos activos, su área y quién los ocupa (excepto el propio puesto y los que le reportan); 2) departamento propuesto del superior, editable; 3) vista previa sin guardar (reporta a y departamento antes → después, personas del puesto y cambios de jefe directo calculados con `jefesDerivados` y el «reporta a» simulado en memoria); 4) aplicar en `DB::transaction`, bitácora `puesto_reubicado` + activity log de `Puesto`, y `JefeDirectoService::sincronizar` al momento. Rechaza ciclos (debajo de sí mismo o de un subordinado). Permiso: `PuestoPolicy::editarJerarquia` (organigrama.editar o puestos.editar). Rutas `administracion.jerarquia-puestos.ubicacion.{opciones,vista-previa,aplicar}`. No cambia personas ni expedientes.

### Vacantes y coberturas en el árbol

- Un puesto sin titular **se muestra como VACANTE**, nunca se oculta (incluye puestos corporativos y regionales sin nadie). Un puesto «de región» sin región ligada ya no se omite (se pinta como plaza corporativa) y ninguna tarjeta queda colgando de sí misma: si el cambio al puesto regional de la región regresa a un puesto ya recorrido se corta, y una red final (`romperCiclos`) deja como raíz cualquier ciclo restante. Antes, un puesto regional que la matriz comercial aún ligaba a una región (datos heredados de Q1/Q3) se resolvía como su propio superior y no se dibujaba.
- Una cobertura no cambia el puesto titular de quien cubre ni suma headcount: si el gerente de Córdoba cubre Cuernavaca, Cuernavaca sigue con su plaza de gerente **vacante** (headcount) y se ve «cubierto temporalmente» (organigrama).
- **«Cubre temporalmente este puesto»** (botón en la plaza vacante): solo se elige a la persona (buscador con la lista completa desde el inicio). **Sin fechas ni motivo**: empieza hoy y queda como «Puesto vacante»; se quita con un clic. Auditable: `registrada_por`/`finalizada_por`, `activity_log` (`cobertura_asignada`/`cobertura_finalizada`) y un movimiento «Cobertura temporal» en el expediente de quien cubre (se cierra al quitarla). También aplica a plazas corporativas (sin sucursal ni región).
- **Regla de jefe directo con cobertura:** mientras la plaza no tenga titular, quienes le reportan a esa plaza tienen como jefe directo a **quien la cubre**. Si quien cubre es uno de esos subordinados (p. ej. el subgerente cubre la gerencia de su sucursal), su propio jefe se resuelve subiendo a la siguiente persona de la cadena. En cuanto la plaza tiene titular, la cobertura deja de usarse para el jefe (el titular manda). Al quitarla, el jefe vuelve a subir por la cadena hasta la primera persona.

## Jefe directo = organigrama (2026-10-02)

El jefe directo **no se captura en ningún lado** (se retiró la pantalla Configuración → Jefes directos y el combo de jefe en datos laborales y en el alta). Sale del organigrama: el superior es quien ocupa el puesto superior en su misma sucursal/región; si ese puesto está vacante se sube por la cadena hasta la primera persona (o quien lo cubre temporalmente). Solo quien encabeza la estructura queda sin jefe.

- Única fuente: `App\Services\Organigrama\JefeDirectoService` (usa `OrganigramaPersonasService::jefesDerivados()`). `colaboradores.jefe_id` se conserva como copia materializada porque aprobaciones, avisos y alcance lo leen directo; solo este servicio lo escribe, y cada cambio queda en la bitácora (`jefe_directo_cambiado`, motivo "Derivado del organigrama").
- Se recalcula solo al terminar la petición cuando cambia el puesto, la sucursal o el estatus de alguien, el "reporta a" de un puesto, la región de la matriz o una cobertura (`AppServiceProvider`). Cambiar datos laborales desde el expediente y dar de alta lo recalculan al momento.
- Red de seguridad diaria y para bases existentes: `php artisan organigrama:sincronizar-jefes` (programado 05:30).
- «¿Por qué su jefe es X?»: `php artisan organigrama:explicar-jefe EMP-0131` (o el id) muestra la cadena nivel por nivel (titular, cobertura, VACANTE o regional de respaldo, y quién tiene ese puesto pero no cuenta por su estatus) y avisa si `jefe_id` está desactualizado. Solo lectura.

### Subgerentes = apoyo (regla de la dueña del negocio, 2026-11-09)

El **Subgerente de Sucursal** y el **Subgerente Regional** son puestos de APOYO (`config/organigrama.php` → `puestos_apoyo`, sin acentos ni mayúsculas): nunca son jefe directo mientras su superior tenga titular o cobertura. Sustituye la derivación anterior Gestor → Subgerente.

| Persona | Jefe directo, en orden |
|---|---|
| Gestor, Gestor Volante, Gestor grupal | Gerente de Sucursal → Subgerente de Sucursal → Gerente Regional → Subgerente Regional → regional de respaldo / «sin jefe asignable» |
| Subgerente de Sucursal | Gerente de Sucursal → Gerente Regional → Subgerente Regional → respaldo |
| Gerente de Sucursal | Gerente Regional → Subgerente Regional → respaldo |
| Subgerente Regional | Gerente Regional |

Cómo se calcula (`OrganigramaPersonasService::cadenaDeJefe`, la usan `jefesDerivados`, `explicarJefe` y por lo tanto `JefeDirectoService` y las aprobaciones que leen el jefe organizacional): se sube por la cadena del árbol; un apoyo con persona se **omite** si su plaza superior está ocupada («Subgerente: apoyo, se omite porque hay Gerente de Sucursal» en `organigrama:explicar-jefe`); en una plaza **vacante**, el apoyo que cuelga de ella la **suple** («apoyo: suple la plaza vacante»). Nunca un apoyo del mismo puesto que quien pregunta (un subgerente no es jefe de otro subgerente). Nunca Dirección Comercial para personal de sucursal (regla de abajo).

**Árbol:** se conserva la posición estructural (el Subgerente sigue entre el Gerente y sus gestores) con un chip «Apoyo» en la tarjeta; el «Reporta a» del panel sigue la regla nueva (`jefeVisible` en `resources/js/lib/organigramaEditable.ts`). El motor de aprobaciones ya pedía primero la gerencia de la sucursal y después el jefe organizacional, así que sigue la misma regla.

**Al desplegar:** la migración `2026_11_09_120000_subgerentes_son_apoyo_recalcula_jefes` corre `organigrama:sincronizar-jefes` (cada cambio en la bitácora). La red diaria de las 05:30 lo repite.

### Personal de sucursal: nunca Dirección Comercial (regla del dueño, 2026-10-21)

Para cualquier puesto **de sucursal** (Gestor, Gestor Volante, Subgerente, Gerente de Sucursal, Coordinadora de Sucursal…) el jefe — y por lo tanto el «Responsable de aprobaciones» — es, en este orden:

1. quien ocupa (o cubre temporalmente) el puesto superior en **su** sucursal, aplicando la regla de **apoyo** (abajo): Gestor → Gerente de Sucursal; con la gerencia vacante, el Subgerente;
2. el Gerente Regional (titular o cobertura; también responde el Subgerente Regional en aprobaciones);
3. la **regional de respaldo** (`App\Services\Organigrama\RegionalRespaldoService`, `config/organigrama.php` → `regional_respaldo`): la persona fijada en `ORGANIGRAMA_REGIONAL_RESPALDO` (id o número de empleado) o, si no hay, el primer titular vigente (por id) de un puesto regional — los ligados a una región de la matriz más «Gerente Regional Q1/Q3», «Gerente regional», «Regional de Operaciones» — y después quien cubra una plaza regional. Con una sola regional en la empresa siempre es ella;
4. si no existe **ningún** regional: `jefe_id` = null, «SIN JEFE ASIGNABLE» en `explicar-jefe` y en `sincronizar-jefes`, y un pendiente «Sin jefe asignable en el organigrama» en la bandeja de RH (`organigrama.editar`, se cierra solo al resolverse).

**Nunca** Dirección Comercial ni Dirección General (`ciclo_laboral.organizacion.puestos_direccion_comercial` y sus superiores). Los regionales sí reportan a Dirección Comercial. El árbol visual no cambia (la plaza regional se sigue viendo VACANTE colgando de Dirección Comercial); cambia el jefe derivado. La misma regla aplica en el motor de aprobaciones (Gerente de Sucursal clasificado en una región sin regional → origen `regional_respaldo` en vez de detenerse) y en `OrganizacionJerarquiaService::regionalesDe()` (avisos y candidatos de una sucursal cuya región de la matriz no tiene regional; una sucursal fuera de la matriz no hereda regional).

Caso real (2026-10-21): Luis Ángel Méndez Díaz (EMP-0131, Gestor, San Luis Potosí) salía con «Jefe: Dirección Comercial» porque SLP no tiene Gerente ni Subgerente cargados y la Región Q1 no tiene Gerente Regional. Los datos reales (`HEADCOUNT GENERAL MR LANA 16-10-2026`, hoja «SUCURSALES») ponen a la «Regional de Operaciones» de Tula al frente de las 11 sucursales, consolidado «Q1, Q3»; la carga inicial solo la registró como titular de Q3. Ahora su jefe es ella por respaldo. Lo formal es asignarle la plaza regional, o que RH cargue al gerente de SLP.
- Los nombres de `puestos_raiz_sucursal` / `puestos_de_region` se comparan sin mayúsculas ni acentos.

## Vistas

`Administración → Organigrama` (ruta interna `administracion/jerarquia-puestos`):

- **Por personas** (principal): una tarjeta por colaborador; vista corporativa (Dirección General → Dirección Comercial → ramas) y luego la estructura comercial por región y sucursal. Árbol con zoom en escritorio, lista jerárquica en móvil.
- **Por puestos**: el catálogo de puestos para editar «reporta a», respaldos y ruta de crecimiento.

Encima del árbol, **secciones como el PDF de Inducción** (capítulo 4): **General** (todo), **Corporativo** (Dirección General y sus áreas directas, un nivel), **Dirección Comercial** y **Sucursal** — cada sección es la rama real de su puesto raíz (`config/organigrama.php` → `vistas_induccion`, `raiz_puesto_id`). La **Matriz comercial** sigue como acceso aparte.

Acceso: `puestos.administrar` u `organigrama.ver`; editar pide `puestos.administrar` u `organigrama.editar`.

**Organigrama institucional de la Inducción** (capítulo 4 — web, «Presentar inducción» y app): `JerarquiaPuestoService::organigramaInstitucional($vista)`, un solo método para las tres rutas. A diferencia del organigrama operativo, los ocupantes **no** se acotan por `AlcanceOrganizacionalService` (misma filosofía que el Directorio: cualquier colaborador autenticado ve quién ocupa cada puesto) y solo expone datos organizacionales mínimos: puesto, área, nombre y sucursal del ocupante — nunca salario, contacto personal, RFC/CURP/NSS, domicilio ni expediente. El organigrama operativo (`agregarOcupantes`) sigue respetando el alcance.

## Sincronizar una base existente (producción)

```bash
php artisan people:sincronizar-organigrama --simular   # reporta, no escribe
php artisan people:sincronizar-organigrama             # aplica
```

Informa: puestos nuevos, puestos renombrados (conservan id, gente e historial — incluso cambios solo de mayúsculas como «Gestor volante» → «Gestor Volante»), relaciones jerárquicas actualizadas, puestos fuera de la estructura, regiones ligadas, Q2, clasificación de la matriz, nodos legacy que cambian de tipo y **conflictos**.

Nunca borra datos en uso ni **asigna personas**: por ejemplo, si alguien ocupa el puesto genérico anterior «Gerente regional», no se adivina si es Q1 o Q3 — se reporta para que RH lo reasigne. Tampoco cierra asignaciones de gestor en entradas que resultaron ser posiciones (GERENCIA/SUBGERENCIA/VOLANTE): las reporta. Conflictos que detecta: 2+ gerentes o subgerentes en una sucursal, Coordinadora de Sucursal en Corporativo, más de una asistente de Dirección Comercial o monitorista, gestores con más de una ruta vigente.

Los seeders de estructura (`PuestoJerarquiaSeeder`, `MatrizComercialSeeder`) son idempotentes y corren en producción; los de demostración (`DemoSeeder`, incluido `OrganigramaDemoSeeder`) solo en local/testing.
