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
| Catálogos para vincular | `GET /branches`, `GET /employees` | `branches.read`, `employees.read` |
| Prueba de conexión | `GET /capabilities` (`features.weekly_activity`) | autenticado |

Cliente: `ReportesApiCliente` — token Bearer, `timeout` 60 s (la primera vez Reportes calcula la radiografía; después la guarda 12 h), conexión 5 s, **2 reintentos** solo ante falla de red, 429 o 5xx (nunca 401/403/404/422), caché de 30 min de las respuestas correctas. Cualquier falla se traduce a un mensaje en español (`ReportesNoDisponibleException`) y la pantalla lo muestra con «Reintentar»: nunca truena.

## 3. Cómo se mapean sucursales y gestores (tabla `vinculos_reportes`)

No hay llave confiable compartida:

- La clave de empleado de Reportes (`employee_code`, clave NOI) **no es única** (la clave «7» la tienen dos personas) y no es el `numero_empleado` de PEOPLE.
- Las sucursales de Reportes son `branches` (oficinas y rutas); su atribución institucional (`sucursal`) hoy viene `null` en local.

Por eso existe una tabla de mapeo **auditable** (`App\Models\VinculoReportes`, `VinculoReportesService`):

| Tipo | PEOPLE | Reportes | Cardinalidad |
|---|---|---|---|
| `sucursal` | `sucursal_id` | `branch.id` | una sucursal de PEOPLE agrupa varios branches; un branch solo pertenece a una sucursal a la vez |
| `colaborador` | `colaborador_id` | `employee.id` | uno a uno |

- Cada fila guarda la foto del nombre/clave en Reportes, el método (`MetodoVinculoReportes`: elegido a mano / sugerencia confirmada) y quién vinculó.
- **Nunca se borra**: cambiar o quitar un vínculo lo desactiva (`desactivado_en`, `desactivado_por`, `motivo_desactivacion`) y, si aplica, crea uno nuevo.
- **Sugerencias** (nunca automáticas): sucursal con nombre idéntico (sin acentos) o branch que empieza con el nombre de la sucursal («TULA CENTRO» → Tula); colaborador con las mismas palabras del nombre y **único en ambos lados** (con homónimos decide una persona). «Confirmar sugerencias» las recalcula en el servidor.
- El nombre y la clave se toman de Reportes al vincular, nunca del formulario.
- Un gestor **sin vincular** se ubica por el branch que Reportes le asignó en ese mes (`assigned_branch_id` → sucursal vinculada).
- En los datos actuales, los 13 branches con datos (88–100) son sucursales completas con el mismo nombre que en PEOPLE; en local se confirmaron las 13 sugerencias (+1 ruta).

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
