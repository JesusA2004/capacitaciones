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
│   ├── Gerente Regional Q1   ─┐ cada una ligada a su región de la matriz
│   └── Gerente Regional Q3   ─┘ (Q2 no existe)
│       └── (sucursales de esa región)
│           Gerente de Sucursal                   exactamente 1 por sucursal
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

### Gerentes regionales

Son **dos puestos**: «Gerente Regional Q1» y «Gerente Regional Q3». Cada nodo Región de la matriz apunta a su puesto (`nodos_comerciales.puesto_id`). Un Gerente de Sucursal reporta al puesto regional **de la región de su sucursal** (en el catálogo, «Gerente Regional Q1» es solo el superior de referencia; en la vista por puestos, la rama de sucursal aparece debajo de cada puesto regional).

Si una misma persona atiende las dos regiones, **no** se le crean dos puestos: su puesto titular es uno (p. ej. Q1) y la otra región se registra como **cobertura temporal** (`CoberturaPuesto` con `region_id`). El organigrama muestra:

```
Gerente Regional Q3
VACANTE · cubierto temporalmente
Fernanda …   Cubierto temporalmente por esta persona · Titular de Gerente Regional Q1
```

### Q1 / Q3: de qué lado está cada Gerente de Sucursal (2026-10-20)

**Q1 y Q3 son las dos REGIONES de la matriz comercial** (`nodos_comerciales` tipo `region`: «Región Q1», «Región Q3», `MatrizComercialSeeder`), cada una ligada a su puesto «Gerente Regional Q1/Q3» (`nodos_comerciales.puesto_id`). No son secciones de las láminas ni cuadrantes geográficos. Q2 no existe.

Antes, el organigrama General (composición de las láminas) colgaba TODAS las sucursales de un solo «Regional Comercial» con los dos regionales juntos, así que cada gerente se veía de Q1 y de Q3 a la vez. Ahora RH **clasifica** a cada Gerente de Sucursal con un clic (Q1 · Q3 · Por clasificar):

- Tabla `clasificaciones_region` (una fila por colaborador: `region_id`, `clasificada_por`, fechas; historial en `activity_log` `organigrama`/`region_clasificada`). Regla: `App\Services\Organigrama\ClasificacionRegionService`.
- Solo se clasifica a quien ocupa un puesto **clasificable**: el que reporta a un puesto regional (hoy, Gerente de Sucursal). Su subgerente y gestores lo siguen.
- **Sin clasificar = «Por clasificar»**, visible (aviso arriba del árbol, chip punteado en la tarjeta, lámina de Dirección Comercial). Nunca se clasifica solo: la región de su sucursal en la matriz se muestra como **sugerencia** (punto dorado) y, mientras tanto, su jefe directo sale provisionalmente de esa región (la regla de siempre).
- Clasificado = cuelga del regional de ESE lado (o de quien lo cubra), aunque la matriz diga otra región para su sucursal. Clasificar recalcula el jefe directo y, si cambió, registra «Cambio de jefe» en su expediente.

### Organigrama editable (General · Por personas)

Una tarjeta por persona o plaza (foto/iniciales, nombre, puesto, sucursal, chip Q1/Q3, «Cubre temporalmente», vacante punteada), en un lienzo con zoom (Ctrl + rueda / botones) y arrastre; en celular, lista jerárquica desplegable. Al tocar una tarjeta se abre el panel con sus acciones (solo `organigrama.editar`: RH / super admin; el backend lo valida con `ColaboradorPolicy::moverEnOrganigrama/clasificarRegion` y `CoberturaPuestoPolicy`):

| Tarjeta | Acciones |
|---|---|
| Persona | Subir de puesto · Bajar de puesto · Mover bajo… · (Gerente de Sucursal) Q1 / Q3 / Por clasificar |
| Plaza vacante | Cubre temporalmente este puesto |
| Plaza cubierta | Quitar cobertura (un clic) |

**Subir / Bajar / Mover bajo…** (`MovimientoOrganigramaService`) nunca escriben el jefe: cambian la PLAZA (puesto, sucursal y, si aplica, lado Q1/Q3) por la misma puerta que «Datos laborales» (`DatosExpedienteService::actualizarLaborales` → movimiento laboral + `JefeDirectoService::sincronizar`). Antes de aplicar se muestra «Vas a mover a X de Y a Z» con el jefe actual → jefe nuevo, calculado sin guardar (`OrganigramaPersonasService::jefesDerivados($simulacion)`), y avisos de headcount. «Mover bajo…» ofrece las plazas del puesto superior (personas, vacantes o coberturas) en otras sucursales/lados; el Corporativo nunca es destino de un puesto de sucursal. Arrastrar y soltar no se implementó a propósito: el menú por nodo con confirmación es más estable y auditable.

**Acomodo** (`resources/js/lib/organigramaLayout.ts`, probado en `tests/js/organigrama-layout.test.mjs`): cada nivel en su fila, ramas acercadas por contornos (una rama chica queda junto a la grande, el padre centrado sobre su primer y último hijo), hojas de un mismo jefe apiladas en columna y conectores ortogonales en SVG. Sustituye al árbol de flexbox anidado, donde cada rama se alineaba por su cuenta.

### Vacantes y coberturas en el árbol

- Un puesto sin titular **se muestra como VACANTE**, nunca se oculta (incluye puestos corporativos y regionales sin nadie).
- Una cobertura no cambia el puesto titular de quien cubre ni suma headcount: si el gerente de Córdoba cubre Cuernavaca, Cuernavaca sigue con su plaza de gerente **vacante** (headcount) y se ve «cubierto temporalmente» (organigrama).
- **«Cubre temporalmente este puesto»** (botón en la plaza vacante): solo se elige a la persona (buscador con la lista completa desde el inicio). **Sin fechas ni motivo**: empieza hoy y queda como «Puesto vacante»; se quita con un clic. Auditable: `registrada_por`/`finalizada_por`, `activity_log` (`cobertura_asignada`/`cobertura_finalizada`) y un movimiento «Cobertura temporal» en el expediente de quien cubre (se cierra al quitarla). También aplica a plazas corporativas (sin sucursal ni región).
- **Regla de jefe directo con cobertura:** mientras la plaza no tenga titular, quienes le reportan a esa plaza tienen como jefe directo a **quien la cubre**. Si quien cubre es uno de esos subordinados (p. ej. el subgerente cubre la gerencia de su sucursal), su propio jefe se resuelve subiendo a la siguiente persona de la cadena. En cuanto la plaza tiene titular, la cobertura deja de usarse para el jefe (el titular manda). Al quitarla, el jefe vuelve a subir por la cadena hasta la primera persona.

## Jefe directo = organigrama (2026-10-02)

El jefe directo **no se captura en ningún lado** (se retiró la pantalla Configuración → Jefes directos y el combo de jefe en datos laborales y en el alta). Sale del organigrama: el superior es quien ocupa el puesto superior en su misma sucursal/región; si ese puesto está vacante se sube por la cadena hasta la primera persona (o quien lo cubre temporalmente). Solo quien encabeza la estructura queda sin jefe.

- Única fuente: `App\Services\Organigrama\JefeDirectoService` (usa `OrganigramaPersonasService::jefesDerivados()`). `colaboradores.jefe_id` se conserva como copia materializada porque aprobaciones, avisos y alcance lo leen directo; solo este servicio lo escribe, y cada cambio queda en la bitácora (`jefe_directo_cambiado`, motivo "Derivado del organigrama").
- Se recalcula solo al terminar la petición cuando cambia el puesto, la sucursal o el estatus de alguien, el "reporta a" de un puesto, la región de la matriz o una cobertura (`AppServiceProvider`). Cambiar datos laborales desde el expediente y dar de alta lo recalculan al momento.
- Red de seguridad diaria y para bases existentes: `php artisan organigrama:sincronizar-jefes` (programado 05:30).
- «¿Por qué su jefe es X?»: `php artisan organigrama:explicar-jefe EMP-0131` (o el id) muestra la cadena nivel por nivel (titular, cobertura, VACANTE o regional de respaldo, y quién tiene ese puesto pero no cuenta por su estatus) y avisa si `jefe_id` está desactualizado. Solo lectura.

### Personal de sucursal: nunca Dirección Comercial (regla del dueño, 2026-10-21)

Para cualquier puesto **de sucursal** (Gestor, Gestor Volante, Subgerente, Gerente de Sucursal, Coordinadora de Sucursal…) el jefe — y por lo tanto el «Responsable de aprobaciones» — es, en este orden:

1. quien ocupa (o cubre temporalmente) el puesto superior en **su** sucursal (Gestor → Subgerente → Gerente de Sucursal; el catálogo dice Gestor → Subgerente y se respeta);
2. el Gerente Regional de **su** región (titular o cobertura);
3. la **regional de respaldo** (`App\Services\Organigrama\RegionalRespaldoService`, `config/organigrama.php` → `regional_respaldo`): la persona fijada en `ORGANIGRAMA_REGIONAL_RESPALDO` (id o número de empleado) o, si no hay, el primer titular vigente (por id) de un puesto regional — los ligados a una región de la matriz más «Gerente Regional Q1/Q3», «Gerente regional», «Regional de Operaciones» — y después quien cubra una plaza regional. Con una sola regional en la empresa siempre es ella;
4. si no existe **ningún** regional: `jefe_id` = null, «SIN JEFE ASIGNABLE» en `explicar-jefe` y en `sincronizar-jefes`, y un pendiente «Sin jefe asignable en el organigrama» en la bandeja de RH (`organigrama.editar`, se cierra solo al resolverse).

**Nunca** Dirección Comercial ni Dirección General (`ciclo_laboral.organizacion.puestos_direccion_comercial` y sus superiores). Los regionales sí reportan a Dirección Comercial. El árbol visual no cambia (la plaza regional se sigue viendo VACANTE colgando de Dirección Comercial); cambia el jefe derivado. La misma regla aplica en el motor de aprobaciones (Gerente de Sucursal clasificado en una región sin regional → origen `regional_respaldo` en vez de detenerse) y en `OrganizacionJerarquiaService::regionalesDe()` (avisos y candidatos de una sucursal cuya región de la matriz no tiene regional; una sucursal fuera de la matriz no hereda regional).

Caso real (2026-10-21): Luis Ángel Méndez Díaz (EMP-0131, Gestor, San Luis Potosí) salía con «Jefe: Dirección Comercial» porque SLP no tiene Gerente ni Subgerente cargados y la Región Q1 no tiene Gerente Regional. Los datos reales (`HEADCOUNT GENERAL MR LANA 16-10-2026`, hoja «SUCURSALES») ponen a **Mónica Ana Karen García Cancino** («Regional de Operaciones», Tula) al frente de las 11 sucursales, consolidado «Q1, Q3»; la carga inicial solo la registró como titular de Q3 (la región de Tula). Ahora su jefe es Mónica por respaldo. Lo formal es que RH registre a Mónica con «Cubre temporalmente» en la plaza Gerente Regional Q1 (Organigrama → tarjeta VACANTE de Q1), o cargue al gerente de SLP.
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
