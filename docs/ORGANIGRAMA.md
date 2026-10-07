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

### Vacantes y coberturas en el árbol

- Un puesto sin titular **se muestra como VACANTE**, nunca se oculta (incluye puestos corporativos y regionales sin nadie).
- Una cobertura no cambia el puesto titular de quien cubre ni suma headcount: si el gerente de Córdoba cubre Cuernavaca, Cuernavaca sigue con su plaza de gerente **vacante** (headcount) y se ve «cubierto temporalmente» (organigrama).

## Jefe directo = organigrama (2026-10-02)

El jefe directo **no se captura en ningún lado** (se retiró la pantalla Configuración → Jefes directos y el combo de jefe en datos laborales y en el alta). Sale del organigrama: el superior es quien ocupa el puesto superior en su misma sucursal/región; si ese puesto está vacante se sube por la cadena hasta la primera persona (o quien lo cubre temporalmente). Solo quien encabeza la estructura queda sin jefe.

- Única fuente: `AppServicesOrganigramaJefeDirectoService` (usa `OrganigramaPersonasService::jefesDerivados()`). `colaboradores.jefe_id` se conserva como copia materializada porque aprobaciones, avisos y alcance lo leen directo; solo este servicio lo escribe, y cada cambio queda en la bitácora (`jefe_directo_cambiado`, motivo "Derivado del organigrama").
- Se recalcula solo al terminar la petición cuando cambia el puesto, la sucursal o el estatus de alguien, el "reporta a" de un puesto, la región de la matriz o una cobertura (`AppServiceProvider`). Cambiar datos laborales desde el expediente y dar de alta lo recalculan al momento.
- Red de seguridad diaria y para bases existentes: `php artisan organigrama:sincronizar-jefes` (programado 05:30).

## Vistas

`Administración → Organigrama` (ruta interna `administracion/jerarquia-puestos`):

- **Por personas** (principal): una tarjeta por colaborador; vista corporativa (Dirección General → Dirección Comercial → ramas) y luego la estructura comercial por región y sucursal. Árbol con zoom en escritorio, lista jerárquica en móvil.
- **Por puestos**: el catálogo de puestos para editar «reporta a», respaldos y ruta de crecimiento.

Acceso: `puestos.administrar` u `organigrama.ver`; editar pide `puestos.administrar` u `organigrama.editar`.

## Sincronizar una base existente (producción)

```bash
php artisan people:sincronizar-organigrama --simular   # reporta, no escribe
php artisan people:sincronizar-organigrama             # aplica
```

Informa: puestos nuevos, puestos renombrados (conservan id, gente e historial — incluso cambios solo de mayúsculas como «Gestor volante» → «Gestor Volante»), relaciones jerárquicas actualizadas, puestos fuera de la estructura, regiones ligadas, Q2, clasificación de la matriz, nodos legacy que cambian de tipo y **conflictos**.

Nunca borra datos en uso ni **asigna personas**: por ejemplo, si alguien ocupa el puesto genérico anterior «Gerente regional», no se adivina si es Q1 o Q3 — se reporta para que RH lo reasigne. Tampoco cierra asignaciones de gestor en entradas que resultaron ser posiciones (GERENCIA/SUBGERENCIA/VOLANTE): las reporta. Conflictos que detecta: 2+ gerentes o subgerentes en una sucursal, Coordinadora de Sucursal en Corporativo, más de una asistente de Dirección Comercial o monitorista, gestores con más de una ruta vigente.

Los seeders de estructura (`PuestoJerarquiaSeeder`, `MatrizComercialSeeder`) son idempotentes y corren en producción; los de demostración (`DemoSeeder`, incluido `OrganigramaDemoSeeder`) solo en local/testing.
