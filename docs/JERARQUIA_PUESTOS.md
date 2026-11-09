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

Estructura definida por dirección (septiembre 2026). Un solo árbol con
**Dirección General** como raíz:

```
Dirección General
├── Asistente de Dirección General          (el puesto existe aunque no esté ocupado)
└── Director comercial                      (Dirección Comercial de Mr. Lana)
    ├── Asistente de Dirección Comercial
    ├── Gerente de Sistemas
    │   └── Monitorista
    ├── Gerente de Mesa de Control
    │   └── Analista de Mesa de Control
    ├── Gerente de Recursos Humanos
    │   ├── Administración de Personal
    │   └── Reclutamiento
    ├── Gerente de Contraloría               (antes "Gerente de Contabilidad")
    ├── Gerente Regional                     (División comercial; plaza única)
    │   ├── Subgerente Regional              (plaza única; apoyo del Gerente Regional)
    │   └── Gerente de Sucursal
    │       └── Subgerente
    │           ├── Gestor fijo (ruta)
    │           │   └── Gestor volante
    │           └── Gestor grupal
    └── Gerente administrativo regional      (= coordinadora regional; antes "Coordinadora regional")
        └── Coordinadora                     (de sucursal)
```

Niveles: 1 Dirección General · 2 asistente de DG y Director comercial · 3
asistente comercial y todas las gerencias · 4 Monitorista, Analista de Mesa
de Control, Administración de Personal, Reclutamiento, Gerente de Sucursal y
Coordinadora · 5 Subgerente · 6 gestores fijo/grupal · 7 Gestor volante.

- **Coordinadora** no la listó dirección, pero existe en el Excel real de
  headcount ("COORDINADORA DE SUCURSAL", ver
  `App\Services\Headcount\HeadcountImportService::MAPA_PUESTOS`); se conserva
  bajo el Gerente administrativo regional hasta que dirección confirme.
- **"Gerente"** era un duplicado de "Gerente de Sucursal": se retiró y el
  import de headcount mapea "GERENTE" → "Gerente de Sucursal".
- **Puestos retirados** (`Gerente`, `Generalista de RH`, `Coordinador de
  Capacitación`, `Analista de Sistemas`, `Soporte Técnico`, `Analista de
  Nómina`, `Auxiliar Contable`, `Responsable administrativo/regional`,
  `Supervisor de Operaciones`, `Ejecutivo de Ventas`, `Coordinador de
  Ventas`): se eliminan (borrado suave) solo si nada los usa. Si tienen
  colaboradores, headcount, vacantes o historial de movimientos, quedan
  `activo = false`, fuera del árbol, y el seeder lo reporta en consola para
  reasignarlos — borrar un puesto en uso vaciaría el historial y borraría en
  cascada su headcount. El organigrama oculta los puestos inactivos salvo
  que alguien activo siga en uno.
- `PuestoSeeder` (catálogo genérico anterior) ya solo delega en
  `PuestoJerarquiaSeeder`.

Respaldos sembrados: Gestor volante puede cubrir a Gestor fijo; Subgerente
puede cubrir a Gerente de Sucursal.

Para el organigrama **por personas**, los puestos "de sucursal" son
`Gerente de Sucursal`, `Coordinadora` y todo lo que cuelga de ellos
(`config/organigrama.php`).

## Autoridad en la línea comercial

Regla confirmada por la dueña del negocio (2026-11-01): **todo se puede reabrir o modificar, pero solo por alguien de rango superior, a uno o más niveles arriba de quien lo decidió; el mismo puesto o uno inferior, nunca.** El orden vive en `config/organigrama.php` → `linea_comercial`: Gerente Regional > Subgerente Regional > Gerente de Sucursal > Subgerente de Sucursal > Gestores. El reporte directo no es lo mismo: el Gerente de Sucursal reporta al Gerente Regional, aunque el Subgerente Regional también tiene autoridad sobre él. El **jefe directo** tampoco: el Subgerente de Sucursal y el Subgerente Regional son apoyo (`organigrama.puestos_apoyo`) y solo son jefe directo cuando su superior está vacante — los gestores reportan al Gerente de Sucursal (ver «Subgerentes = apoyo» en `docs/ORGANIGRAMA.md`).

Única fuente: `App\Services\Organigrama\AutoridadJerarquicaService`.

| Método | Responde |
|---|---|
| `puedeModificarLoHechoPor(actor, autor)` | ¿Puede cambiar lo que esa cuenta hizo? |
| `tieneAutoridadSobre(actor, colaborador)` | ¿Manda sobre esa persona? Puesto estrictamente superior y dentro de su alcance (regionales: la empresa; los demás: sus sucursales). |
| `exigirPuedeModificar(actor, autores)` | Rechaza con «Lo registró un puesto superior; no puedes cambiarlo.» y avisa si hay que anotar un autor desconocido. |
| `esRangoSuperior(actor, autor)` | ¿Puede reabrir esa decisión? Solo un rango estrictamente mayor; ver «Reabrir». |

Criterios (los tres últimos, supuestos comunicados a la dueña del negocio):

- El rango es el del puesto en la base, titular o cobertura vigente; nunca un nombre de persona. Es el puesto **actual** del autor: las tablas guardan quién decidió, no qué puesto tenía entonces.
- Mismo puesto: no puede cambiar ni reabrir decisiones ajenas. Corregir un dato sigue siendo posible por RH, con motivo, en el flujo de correcciones.
- **Otros nombres:** «Gerente de operaciones» y «Gerente» a secas son Gerente de Sucursal, solo en una sucursal operativa (`organigrama.alias_gerente_sucursal`); en Corporativo no tienen rango y `people:sincronizar-organigrama` y la migración inicial lo reportan como conflicto. Coordinadora es un puesto administrativo aparte, sin rango.
- **«Regional de Operaciones» no tiene rango** hasta que la dueña del negocio lo aclare: no se reasigna a nadie, `people:sincronizar-organigrama` reporta a sus titulares y la migración inicial lo marca como conflicto. Sigue contando como regional de respaldo.
- RH, Dirección y cualquier puesto o rol fuera de la línea (`roles_fuera_de_linea`) conservan sus permisos, en ambos sentidos.
- Sin autor conocido: se trata como de nivel inferior, sin bloquear, y queda anotado (historial de la solicitud, seguimiento del candidato o `afectados` de la corrección).

**Dónde se aplica** (Service y, si hay Policy, también ahí; web y API por igual): rechazar, devolver o cancelar una solicitud ajena (`SolicitudesService::cambiarEstado`, autores: vistos buenos y `revisado_por`); aprobar o rechazar vacaciones ya revisadas (`VacacionesService`); descartar un candidato (`CandidatoWorkflowService::descartar`, autor: último cambio de estado); corregir un dato (`CorreccionService`, autor: última corrección del campo o bitácora); bajas y cierres nunca hacia arriba (`BajaService::puedeSolicitarPara`, `ColaboradorPolicy::iniciarCierre`).

### Reabrir (`App\Services\Reaperturas\ReaperturaService`)

Una sola regla para web (`POST rh/reaperturas/{tipo}/{id}`) y API (`POST api/v1/rh/reaperturas/{tipo}/{id}`). El botón «Reabrir» solo aparece con lo que devuelve `disponibles()` (`reaperturas` en la ficha del candidato y de la solicitud; web y API).

| Tipo | Quién decidió (autor) | Se reabre mientras | Qué pasa |
|---|---|---|---|
| `visto_bueno_solicitud` | Último visto bueno | La solicitud esté en trámite o rechazada por ese visto bueno | Vuelve a «Recibida», pendiente del nivel |
| `solicitud_rechazada` | `revisado_por` | Esté rechazada | Vuelve a «Recibida» o «Pendiente de autorizar» según sus vistos buenos |
| `vacaciones_rechazadas` | `revisado_por` | Esté rechazada (legacy, solo API) | Vuelve a pendiente |
| `intervencion_candidato` | `aprobador_id` | No esté pendiente; si se aprobó, el candidato siga en «Autorizado por RH» | Vuelve a pendiente; si se aprobó, el candidato regresa al rechazo de RH |
| `entrevista_candidato` | Quien registró la última entrevista | El candidato esté entre psicométricas y referencias, o No viable por esa entrevista | Vuelve a «Entrevista»; lo ya registrado se conserva |

Criterios:

- **Solo un rango estrictamente mayor al del autor**, dentro de lo que el actor ya puede ver. Inferior, mismo puesto, uno mismo, RH o autor desconocido: 403 con un mensaje que no revela datos del trámite. Sobre la línea: Dirección Comercial y, arriba, Dirección General (`organigrama.sobre_la_linea`). Lo decidido fuera de la línea (RH) solo lo reabre Dirección General.
- Motivo obligatorio. Se guarda en `reaperturas` con el autor, quien reabrió y la decisión anterior completa, en el historial del trámite y en la bitácora, en una transacción. El visto bueno se retira de `solicitud_aprobaciones` (una decisión por nivel) y queda en ese registro.
- Quien reabrió también puede volver a decidir la entrevista o el visto bueno, además de su responsable normal.
- **No aplica a bajas:** las registran los regionales; cancelarlas o reabrirlas es solo de RH.

## Aprobación de entrevista (`App\Services\Reclutamiento\AprobadorEntrevistaService`)

Por puesto objetivo (el de la vacante o, sin vacante, el del candidato), validado en backend en web, API y agenda:

| Candidato a | Aprueba | Dirección Comercial |
|---|---|---|
| Gestor, Gestor Volante, Gestor grupal | Gerente de Sucursal; sin gerente vigente, los regionales | No lo ve |
| Subgerente o Gerente de Sucursal | Gerente Regional, Subgerente Regional o Dirección Comercial. Un gerente no aprueba a su reemplazo | Ve, recibe avisos y actúa |
| Gerente o Subgerente Regional (decisión conservadora) | Dirección Comercial | Ve, recibe avisos y actúa |
| Fuera de la línea (Coordinadora, corporativos) | Sin regla: permiso y alcance, incluida RH | No lo ve, salvo intervención dirigida a ella |

Cada aprobador tiene su pendiente y su aviso; al decidir uno se cierran todos. Sin ningún aprobador vigente la atiende RH con autorización final, para que el proceso no se detenga. Un «gerente involucrado» asignado a mano solo cuenta si es gerente de sucursal. **Psicométricas y socioeconómico no cambian** (`responsableDeCandidato`). RH conserva el resto del recorrido.

**Intervención:** Gestores → regionales; Subgerentes, Gerentes y puestos fuera de la línea → Dirección Comercial. El aviso abre la ficha del candidato.

## Módulo `/administracion/jerarquia-puestos`

Organigrama por tipo de puesto (comercial/administrativo/operativo), renderizado como
árbol visual con conectores en escritorio/tablet (`OrganigramaArbol.vue` +
`OrganigramaNodo.vue`, recursivo, con zoom in/out/reset vía `transform: scale()` y
scroll horizontal controlado) y como lista jerárquica expandible en móvil
(`OrganigramaAccordion.vue`, reutiliza `components/ui/collapsible`, breakpoint `md`).
Ambas vistas comparten la tarjeta de puesto (`OrganigramaTarjeta.vue`): nombre,
departamento, nivel, badges de tipo/"Ruta"/"Vacante"/"Sin cobertura"/"Candidatos"/
"Inactivo", y contador de colaboradores.

**Filtros** (`empresa_id`, `sucursal_id`, `departamento_id`, `tipo_puesto`, vía
`useFiltros`): como un puesto no tiene FK propia a empresa/sucursal, filtrar por
empresa/sucursal significa "puestos con al menos un colaborador activo en esa
empresa/sucursal" (`whereHas('usuarios', ...)` en `JerarquiaPuestoController::index()`),
no "puestos que pertenecen a esa empresa".

Al seleccionar un puesto se abre un panel lateral (`Sheet`) con tres pestañas:

- **Detalle**: descripción, puesto superior, ruta de crecimiento, esquema de
  comisiones, respaldos, puestos que puede cubrir, responsabilidades, requisitos,
  colaboradores activos y candidatos relacionados (con enlace a Candidatos).
- **Vacantes**: vacantes abiertas de ese puesto (enlaza a `/rh/vacantes` filtrado) y
  botón **"Crear vacante para este puesto"**, que navega a
  `/rh/vacantes?puesto_id=..&departamento_id=..&crear=1` — `Rh/Vacantes/Index.vue` lee
  `crear=1` en `onMounted()` y abre el diálogo de creación precargado con esos valores
  (prop `prefill` de `VacanteFormDialog.vue`).
- **Historial**: cargado bajo demanda (no en el payload inicial) contra
  `GET administracion/jerarquia-puestos/{puesto}/historial`
  (`JerarquiaPuestoController::historial()`), que devuelve tres cosas:
  1. `cambiosJerarquia` — entradas del activity log de Spatie (`Puesto` usa
     `LogsActivity` sobre `puesto_superior_id`, `puesto_crecimiento_id`, `tipo_puesto`,
     `nivel_jerarquico`, `requiere_ruta`, `esquema_comisiones`, `activo`).
  2. `movimientos` — `MovimientoLaboral` donde este puesto es origen o destino (ver
     `docs/MOVIMIENTOS_LABORALES.md`).
  3. `vacantes` — vacantes que ha generado este puesto.

Desde el panel, el botón "Editar jerarquía" abre `JerarquiaPuestoDialog.vue`, que edita
`puesto_superior_id`, `puesto_crecimiento_id`, nivel, tipo, comisiones, "requiere
ruta", responsabilidades, requisitos, **respaldos** (quién puede cubrir a este puesto)
y **puestos que puede cubrir** (a quién puede cubrir este puesto) — ambas direcciones
de la tabla `puesto_cobertura` editables desde el mismo formulario.

### Validaciones de ciclo

`ActualizarJerarquiaPuestoRequest` rechaza, además de la auto-referencia:

- Un ciclo jerárquico (A → B → C → A) al asignar `puesto_superior_id`.
- Un ciclo en la ruta de crecimiento al asignar `puesto_crecimiento_id`.

Ambas usan `Puesto::creariaCiclo(string $columna, int $candidatoId)`: camina la cadena
indicada por `$columna` desde `$candidatoId` y detecta si vuelve a este puesto. No hay
validación equivalente de "duplicado" entre `respaldos` y `puestos_que_puede_cubrir`
en direcciones opuestas — dos puestos cubriéndose mutuamente es una configuración
operativa válida (p. ej. Gerente y Subgerente cubriéndose entre sí), no un error.

Autorización: reutiliza el permiso existente `puestos.administrar` (mismo permiso que
la administración básica de puestos) — no se creó un permiso nuevo para esta vista.

## API móvil (solo lectura)

```
GET /api/v1/rh/jerarquia-puestos      permiso: puestos.administrar
```

`App\Http\Controllers\Api\V1\Rh\JerarquiaPuestoController::index()` reutiliza el mismo
`JerarquiaPuestoService` que el panel web y regresa la misma estructura de árbol; la app
solo consulta, no puede editar jerarquía, agregar subordinados ni quitar relaciones
desde este endpoint (esas acciones se quedan en el panel web por ahora). Ver
`docs/RH_MOBILE_API.md`.
