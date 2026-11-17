# Seguimiento (Fase 2) — datos duros de Reportes en PEOPLE

PEOPLE muestra **colocación, recuperación, cartera y mora** por **sucursal** y por **gestor**, por **semana** y por **mes**, leyéndolos de la API v1 de Reportes (servidor a servidor). PEOPLE **no calcula** ningún KPI: solo suma/agrupa lo que Reportes ya calculó.

- Bandera: `features.seguimiento` (`SEGUIMIENTO_ENABLED`, encendida por defecto).
- Rutas: `routes/seguimiento.php` (`/seguimiento`, `/seguimiento/gestores`, `/seguimiento/gestores/{empleado}`, `/seguimiento/vinculos`).
- Servicios: `app/Services/Seguimiento/*` (cliente HTTP, catálogo, vínculos, alcance, tableros).
- Menú: grupo **Seguimiento** del modo operativo («Por sucursal», «Por gestor», «Nine Box», «Vínculos con Reportes»). Nunca en modo colaborador.
- **Nine Box** (desempeño de Reportes × potencial): ver `docs/NINE_BOX.md`.

## 1. Periodos (modelo de Reportes, respetado tal cual)

- La **semana** (`type=weekly`) es el periodo base; el **mes** (`type=monthly`) agrupa **4 semanas** en `component_period_ids`.
- Los datos reales (`fact_*`) se cargan con el `period_id` del **mes**; las semanas son metadata. El desglose semanal sale de `GET /periods/{mes}/weekly-activity`: colocación por **fecha de operación** y recuperación por **fecha de pago** (con las mismas exclusiones del reporte, `recoveryExclusionSql()`).
- La pertenencia de una semana a un mes sale **siempre** de `component_period_ids`, nunca del nombre: «Semana 22 - Mayo 2026» pertenece a **Junio 2026**.
- Cartera, vencida y mora son un **corte de mes**: no existen por semana y nunca se inventan.
- Un mes sin semanas componentes (periodos de prueba) no se ofrece en el selector.

## 2. Contrato de API usado (Reportes `/api/v1`, ver `Reportes/docs/api-reporteria.md` §14–§15)

| Uso en PEOPLE | Endpoint | Ability |
|---|---|---|
| Selector de meses | `GET /periods?type=monthly` (`component_period_ids`, `radiography_status`) | `periods.read` |
| Semanas + actividad semanal por branch y gestor | `GET /periods/{mes}/weekly-activity?include=branches,employees` | `financial.read` + `employees.read` |
| KPIs oficiales del mes por sucursal | `GET /periods/{mes}/branches` | `financial.read` |
| KPIs oficiales del mes por gestor (cartera/mora) | `GET /periods/{mes}/employees` | `employees.read` + `financial.read` |
| Tendencia mensual de un gestor | `GET /employees/{id}/periods?period_ids=…&include_effectiveness=0` | `employees.read` + `financial.read` |
| Personas para vincular (employees agrupados, con sucursal y rutas) | `GET /people?per_page=500` (§16; si no existe, se agrupa `GET /employees` aquí) | `employees.read` |
| Sucursales con sus rutas | `GET /sucursales` (§16; si no existe, cada branch es su sucursal) | `branches.read` |
| Una ruta suelta (compatibilidad) | `GET /branches` | `branches.read` |
| Prueba de conexión | `GET /capabilities` (`features.weekly_activity`) | autenticado |

Cliente: `ReportesApiCliente` — token Bearer, `timeout` 60 s (la primera vez Reportes calcula la radiografía; después la guarda 12 h), conexión 5 s, **2 reintentos** solo ante falla de red, 429 o 5xx (nunca 401/403/404/422), caché de 30 min de las respuestas correctas. Cualquier falla se traduce a un mensaje en español (`ReportesNoDisponibleException`) y la pantalla lo muestra con «Reintentar»: nunca truena.

## 3. Cómo se mapean sucursales y personas (tabla `vinculos_reportes`)

**PEOPLE trabaja a nivel PERSONA y SUCURSAL; las rutas son un detalle de Reportes.** Un gestor
cambia de ruta pero siempre pertenece a su sucursal.

Por qué no se vincula por `employee` ni por `branch` (corrección 2026-11-17, producción):
`GET /employees` es un catálogo de **registros**: la misma persona aparecía hasta 12 veces (alta de
NOI con clave, altas de Lendus cobranza sin clave, variantes de captura, cada una con su ruta) y
`GET /branches` mezcla sucursales con rutas. Resultado: 1,485 «colaboradores» casi todos
«homónimos», 98 sugerencias imposibles y 11/122 sucursales. Ahora:

| Tipo | PEOPLE | Reportes | Cómo se guarda |
|---|---|---|---|
| `sucursal` | `sucursal_id` | **sucursal** de Reportes (`/sucursales`) con todas sus rutas | una fila por ruta (`reportes_id` = branch). Una ruta nueva de una sucursal ya vinculada **hereda** la sucursal de PEOPLE (`sucursalPorBranch()`), y la pantalla la sugiere para dejarla guardada |
| `colaborador` | `colaborador_id` | **persona** de Reportes (`/people`: todos sus employee ids) | `reportes_id` = un employee id de la persona; el vínculo vale para **toda** la persona |

- **Directorio** (`DirectorioReportesService`): personas + sucursales en la caché de PEOPLE (vale
  `REPORTES_API_CACHE_DIRECTORIO_MINUTOS`, 360; se guarda 7 días y, si Reportes no responde, se usa
  el último conocido). `php artisan seguimiento:sincronizar-reportes` (programado cada hora, :20)
  lo refresca y precalienta la actividad del mes; la pantalla tiene «Actualizar desde Reportes».
- **Vínculos ya hechos**: no se migran datos. Un vínculo viejo a un employee id se resuelve a su
  persona al leer (`colaboradorPorEmpleado()` lo extiende a todos sus ids); si dos ids de la misma
  persona estaban ligados a colaboradores distintos, la pantalla lo marca («vínculos anteriores a
  dos colaboradores») y vincular de nuevo cierra el sobrante. Nada se borra.
- **Sugerencias** (nunca automáticas): persona con las mismas palabras del nombre (sin acentos ni
  orden) y única en ambos lados; con homónimos en PEOPLE, se desempata por la sucursal (la de su
  ruta más reciente en Reportes ↔ sucursal principal en PEOPLE). Activos antes que bajas. Las
  variantes con typo ya vienen juntas desde Reportes; si no, se eligen a mano. Sucursal: nombre
  idéntico o que empiece con el de PEOPLE. «Confirmar sugerencias» las recalcula en el servidor.
- **No son personas** (`kind: placeholder` de Reportes: VACANTE…, …VENCIDOS, códigos de promotor):
  se listan aparte y no se vinculan.
- Cálculos: `SeguimientoService::gestoresUbicados()` junta las filas de la actividad semanal de la
  misma persona (suma semanas y mes; cartera/vencida oficiales suman sus filas distintas), así «Por
  gestor», el detalle y la **Nine Box** ven una fila por persona con todas sus rutas. «Por
  sucursal» suma todas las rutas de cada sucursal. Verificado en local (Junio 2026): 136 gestores
  sin repetidos, colocación de gestores 14,170,074.60 = filas crudas; por sucursal 14,151,329 =
  oficial.
- Rendimiento de «Vínculos»: ya no llama a `weekly-activity` (lo «con actividad» sale solo si ya está
  en caché) ni recorre 15 páginas de `/employees`; no manda la lista de colaboradores a cada fila
  (buscador bajo demanda, `GET /seguimiento/vinculos/colaboradores/buscar`) y pinta 40 filas por
  tanda. Local: 0.57 s → 0.04 s el servidor; ~1,485 filas × N colaboradores de opciones en el
  navegador → 40 filas sin opciones montadas.

## 4. Permisos y alcance

Módulo `seguimiento` en `config/permisos_modulos.php`:

- `seguimiento.ver` — entrar a los tableros (`VinculoReportesPolicy::ver`).
- Alcances `seguimiento.ver_todo` / `ver_region` / `ver_sucursal` — qué sucursales de PEOPLE ve (`AlcanceSeguimientoService`, sobre `AlcanceModuloService`; Equipo/Propios = ninguna).
- `seguimiento.vincular` — pantalla «Vínculos con Reportes».

Base del seeder: super_admin (todo), rh_admin (ver + vincular, todo), direccion y director_comercial (ver, todo), gerente_regional (ver, región = todas las operativas), gerente_sucursal (ver, su sucursal). En producción: `php artisan people:sincronizar-permisos`.

El detalle de un gestor responde **403** si está fuera del alcance (por su colaborador vinculado o, sin vínculo, por la sucursal de su branch).

## 5. Configuración

`config/seguimiento.php` + `.env`:

```env
SEGUIMIENTO_ENABLED=true
REPORTES_API_URL=https://reportes.mr-lana.com   # SIN /api/v1
REPORTES_API_TOKEN=                             # token del ApiConsumer (no se versiona)
REPORTES_API_TIMEOUT=60
REPORTES_API_CACHE_MINUTOS=30
```

Después: `php artisan config:clear` (o `config:cache` en producción).

## 6. Prueba de conexión real en local (WAMP)

1. En Reportes (`C:\wamp64\www\Reportes`), crear el consumidor y copiar el token (se imprime una sola vez):

   ```bash
   php artisan api:consumers:create "MR. LANA PEOPLE" "mr-lana-people" \
       --ability=branches.read --ability=employees.read --ability=periods.read --ability=financial.read
   ```

2. En el `.env` de PEOPLE: `REPORTES_API_URL=http://localhost/Reportes/public` y `REPORTES_API_TOKEN=<token>`; `php artisan config:clear`.
3. Verificar:

   ```bash
   php artisan tinker --execute="dump(app(App\Services\Seguimiento\ReportesApiCliente::class)->probar()['mensaje']);"
   ```

4. Entrar a **Seguimiento → Vínculos con Reportes**, confirmar las sugerencias de sucursales y abrir **Por sucursal**.

Resultado (2026-11-17, Junio 2026): conexión correcta (`weekly_activity: true`); 13 sucursales, 4 semanas, colocación 14,151,329 (oficial) y 136 gestores con su tendencia semanal (12 semanas) y mensual (abril–junio).

## 7. Revisión de integración (2026-11-17)

- Verificado en local con Reportes real (Junio 2026) y en navegador: super_admin/RH ven todo; gerente de sucursal solo su sucursal (y 403 en el detalle de un gestor de otra); gerente regional todas las operativas; colaborador 403 y sin menú. Reportes caído → aviso con «Reintentar» (prueba `si Reportes no responde…`).
- Corregido: el filtro de sucursal de «Por gestor» solo ofrecía la sucursal ya elegida (las opciones salían de las filas filtradas); «1 sucursales»; etiquetas recortadas en «Colocación por sucursal»; el detalle de un gestor ahora enlaza a su expediente y, si no está vinculado, a «Vínculos con Reportes»; desde la Nine Box se abre su detalle en Seguimiento.

## 8. Pendientes / decisiones abiertas

- Vincular a los gestores reales: los colaboradores de la base local son de demostración, por eso no hubo sugerencias de colaborador. En producción usar «Confirmar sugerencias» y resolver a mano los homónimos.
- EBITDA, nómina y comisiones por sucursal/gestor existen en Reportes pero **no se muestran** (datos sensibles): falta la decisión de negocio sobre quién los ve.
- Efectividad de cobranza V4 (semanal) aún no se consume (`/collections/effectiveness`).
- Metas/objetivos por gestor (OKR de Reportes) para comparar real vs meta.
- La suma semanal de una sucursal (por branch de la fila) puede diferir un poco del KPI oficial del mes (que aplica la atribución de la radiografía): la tabla usa el oficial para el mes y la gráfica usa las semanas.
