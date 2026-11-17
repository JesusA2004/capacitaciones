# API de integración entre sistemas (`/api/integracion/v1`)

API **servidor a servidor y de solo lectura** para que otros sistemas de MR. LANA (empezando por **Reportes**) consulten los datos duros de People: catálogos, plantilla, altas, bajas y rotación. Es independiente de:

- la API móvil (`/api/v1`, Sanctum, cuentas de colaborador): un token de la app da 401 aquí y uno de integración da 401 allá;
- los contratos de Auditoría (`/api/v1/auditoria/*`, `people-auditoria/1..3`), que no cambian (ver `docs/integraciones/AUDITORIA.md`).

Contrato: `people-integracion/1` (viene en cada respuesta). Los cambios a este contrato son aditivos: claves nuevas sí, renombrar o quitar claves no.

## 1. Autenticación

Cada sistema es un **cliente de integración** (tabla `clientes_integracion`, modelo `App\Models\ClienteIntegracion`). No es una cuenta de `users`. Tiene:

| Campo | Para qué |
|---|---|
| `clave` | Identificador del sistema (`reportes`, `auditoria`…), minúsculas/números/guion |
| `nombre`, `descripcion` | Para soporte |
| `habilidades` | Lista de habilidades concedidas (ver §2) |
| `todas_las_empresas` / `empresas_permitidas` | Qué empresas puede leer. Se decide de forma explícita al emitir; una sucursal o colaborador de otra empresa «no existe» (404) |
| `limite_por_minuto` | Límite propio; `null` = el general de config (§5) |
| `token_hash` | Solo el SHA-256 del token; el token en claro nunca se guarda |

```
Authorization: Bearer ppl_int_<48 caracteres>
```

Los tokens de Auditoría conservan su prefijo histórico `ppl_aud_`.

### Emitir, rotar y revocar (solo desde el servidor)

No hay pantalla web a propósito: solo quien tiene acceso al servidor (super administrador) emite tokens.

```bash
# Ver habilidades disponibles y el perfil «reportes»
php artisan integraciones:clientes habilidades

# Emitir el token de Reportes (habilidades del perfil, empresas 1 y 2, 900/min)
php artisan integraciones:clientes emitir reportes --nombre="Reportes MR LANA" \
    --perfil=reportes --empresa=1 --empresa=2 --limite=900

# Rotar (el anterior deja de servir al instante; conserva habilidades/empresas si no se indican)
php artisan integraciones:clientes rotar reportes

# Revocar (inmediato; la bitácora se conserva)
php artisan integraciones:clientes revocar reportes

# Estado de un cliente y lista de todos
php artisan integraciones:clientes estado reportes
php artisan integraciones:clientes listar
```

El token se imprime **una sola vez**. Va solo en el `.env` del servidor del otro sistema (en Reportes: `PEOPLE_API_TOKEN`), nunca en navegador ni app. `php artisan integraciones:token-auditoria` sigue funcionando para Auditoría.

## 2. Habilidades

Catálogo cerrado `App\Enums\HabilidadIntegracion`:

| Habilidad | Permite | Sensible |
|---|---|---|
| `catalogos:leer` | `/empresas`, `/sucursales`, `/puestos`, `/departamentos` | no |
| `colaboradores:leer` | `/colaboradores`, `/colaboradores/{id}` (sin datos sensibles) | no |
| `colaboradores:identificacion` | Además de la anterior, `incluir=identificacion` (CURP, RFC, NSS) | **sí** |
| `colaboradores:salario` | Además de la anterior, `incluir=salario` (salario diario, integrado, sueldo mensual) | **sí** |
| `movimientos:leer` | `/movimientos/altas`, `/movimientos/bajas` | no |
| `rotacion:leer` | `/rotacion` | no |
| `auditoria:leer` | Contratos de Auditoría (`/api/v1/auditoria/*`) | — |

El perfil `--perfil=reportes` concede `catalogos:leer`, `colaboradores:leer`, `movimientos:leer` y `rotacion:leer`. Las habilidades sensibles nunca entran por perfil: se nombran una por una (`--habilidad=colaboradores:salario`) y además hay que pedirlas en cada consulta (`incluir=`); si se piden sin la habilidad, la respuesta es 403 (nunca se omiten en silencio).

`GET /capacidades` solo exige un token válido y dice qué puede hacer ese token.

## 3. Endpoints

Base: `https://people.mr-lana.com/api/integracion/v1`. Todas las fechas laborales son `AAAA-MM-DD` (hora de México); las marcas de cambio son ISO-8601.

| Método | Ruta | Habilidad | Filtros |
|---|---|---|---|
| GET | `/capacidades` | token válido | — |
| GET | `/empresas` | `catalogos:leer` | — |
| GET | `/sucursales` | `catalogos:leer` | `empresa_id`, `actualizado_desde` |
| GET | `/puestos` | `catalogos:leer` | `actualizado_desde` |
| GET | `/departamentos` | `catalogos:leer` | `actualizado_desde` |
| GET | `/colaboradores` | `colaboradores:leer` | `empresa_id`, `sucursal_id`, `estatus=activos\|bajas\|todos`, `numero_empleado`, `actualizado_desde`, `por_pagina` (≤1000, default 200), `cursor`, `incluir` |
| GET | `/colaboradores/{id}` | `colaboradores:leer` | `incluir` |
| GET | `/movimientos/altas` | `movimientos:leer` | `desde`, `hasta` (obligatorios), `empresa_id`, `sucursal_id` |
| GET | `/movimientos/bajas` | `movimientos:leer` | `desde`, `hasta` (obligatorios), `empresa_id`, `sucursal_id` |
| GET | `/rotacion` | `rotacion:leer` | `desde`, `hasta` (obligatorios), `agrupacion`, `desglose=sucursal\|total`, `empresa_id`, `sucursal_id` |

Rango máximo de `desde`–`hasta`: 731 días; `/rotacion` devuelve como máximo 120 periodos (config).

### Formato de respuesta

```json
{
  "contrato": "people-integracion/1",
  "generado_en": "2026-11-17T09:00:00-06:00",
  "data": [ ... ],
  "meta": { ... }
}
```

Errores, siempre JSON `{message, codigo, errors?}`:

| Estado | `codigo` | Cuándo |
|---|---|---|
| 401 | `no_autenticado` | Sin token, token inválido o revocado |
| 403 | `sin_permiso` (+ `habilidad_requerida`) | Falta la habilidad |
| 404 | `no_encontrado`, `empresa_no_encontrada`, `sucursal_no_encontrada` | No existe o es de una empresa no permitida |
| 422 | `parametros_invalidos` (+ `errors` por campo) | Filtros mal formados, cursor de otra consulta, agrupación que no cuadra |
| 429 | `limite_excedido` (+ `Retry-After`) | Límite por minuto o demasiados tokens fallidos desde la IP |

### Identificadores para emparejar con Reportes

- **Sucursal**: `id` (estable), `clave` (p. ej. `COR01`) y `nombre_normalizado` (mayúsculas, sin acentos ni signos, igual que `branches.normalized_name` de Reportes). El emparejamiento se guarda del lado de Reportes y se revisa a mano; nunca se adivina por nombre en silencio.
- **Colaborador**: `id` de People (para `employee_external_identities.external_id` con `system_code = 'mr_lana_people'`), `numero_empleado` y `clave_noi` (clave del sistema de nómina, si existe).

### Colaboradores: campos

`id, numero_empleado, clave_noi, nombres, apellidos, nombre_completo, estatus{valor, etiqueta}, activo, fecha_ingreso, fecha_baja, tipo_contratacion{valor, etiqueta}, empresa, sucursal{id, clave, nombre}, puesto, departamento, actualizado_en`.

Nunca salen domicilio, teléfonos, correos, contactos de emergencia, beneficiarios, fotos ni expedientes. `identificacion` y `salario` solo con su habilidad y `incluir=` (y entonces la respuesta lleva `Cache-Control: no-store`).

`activo` = sin borrado lógico y estatus vigente (activo o en incorporación), la misma regla de plantilla. Un dado de baja sigue apareciendo con `activo=false`, `estatus=inactivo` y su `fecha_baja`: People nunca borra colaboradores.

### Paginación por cursor y sincronización incremental

`/colaboradores` se ordena por (`actualizado_en`, `id`). `actualizado_en` es la marca **efectiva**: el cambio más reciente del colaborador, de su sucursal, su empresa, su puesto o su departamento (un renombre de sucursal también «actualiza» a su gente).

1. Primera carga: `GET /colaboradores?por_pagina=500` y seguir `meta.siguiente_cursor` mientras `meta.hay_mas` sea `true`.
2. Guardar `meta.server_time` de la PRIMERA página.
3. Siguientes cargas: `GET /colaboradores?actualizado_desde=<server_time guardado>` (con su cursor). Los catálogos aceptan el mismo `actualizado_desde`.

El cursor va firmado y ligado a los filtros: usarlo con otros filtros da 422.

### Altas y bajas

- **Alta**: colaborador (también los ya dados de baja) cuya `fecha_ingreso` cae en el rango, en una sucursal permitida.
- **Baja**: evento de rotación del motor único de People (`App\Services\Rotacion\RotacionMotorService`): bajas del sistema que cuentan como rotación (en revisión o cerradas, por su último día laborado) y bajas históricas válidas, sin duplicados. Cada una trae `forma_salida{valor, etiqueta}` (`App\Enums\FormaSalida`: RV renuncia voluntaria, NR no renovación, RE rescisión, OT otras terminaciones), `clasificacion{valor, etiqueta}` (`App\Enums\ClasificacionSalida`: voluntaria / involuntaria / sin clasificar), la `causal` homologada y la antigüedad. El motivo en texto libre no sale. Las bajas de unidades externas (sin sucursal en People) no salen.

### Rotación

`GET /rotacion?desde=&hasta=&agrupacion=&desglose=`

| `agrupacion` | Periodos | Regla |
|---|---|---|
| `rango` (default) | Uno solo: `[desde, hasta]` | Pasa aquí el `start_date`/`end_date` de cualquier periodo de Reportes |
| `semanal` | Semanas lunes–domingo | `desde` debe ser lunes |
| `bloques_4_semanas` | Bloques de 28 días desde `desde` | `desde` debe ser lunes (meses operativos de Reportes) |
| `mes_calendario` | Meses naturales | `desde` debe ser día 1; cifras OFICIALES del Tablero de Rotación |

Cada periodo:

```json
{
  "clave": "S-2026-10-12",
  "etiqueta": "Semana del 12/10/2026 al 18/10/2026",
  "inicio": "2026-10-12", "fin": "2026-10-18", "corte": "2026-10-18",
  "completo": true, "corte_parcial": false,
  "totales": {
    "plantilla_inicial": 120, "altas": 3, "bajas": 2,
    "bajas_voluntarias": 1, "bajas_involuntarias": 1, "bajas_sin_clasificar": 0,
    "plantilla_final": 121, "plantilla_promedio": 120.5, "rotacion_pct": 1.66
  },
  "sucursales": [ { "sucursal": {"id": 3, "clave": "COR01", "nombre": "Córdoba"}, "plantilla_inicial": 12, "...": "..." } ]
}
```

Definiciones (no hay reglas nuevas; todo reutiliza People):

- `plantilla_inicial` / `plantilla_final`: activos en la fecha de inicio / de corte (`PlantillaMensualService::activosEn`: ingresó en o antes y no tiene baja o su último día es en o después), por sucursal principal.
- `altas`: fecha de ingreso dentro del periodo. `bajas`: eventos del motor único con último día laborado dentro del periodo.
- `rotacion_pct = bajas ÷ plantilla_promedio × 100`, `plantilla_promedio = (inicial + final) ÷ 2` (`FormulaRotacion`). Sin plantilla → `null`, nunca 0.
- Con `mes_calendario`, `bajas` y `plantilla_promedio` son las del Tablero de Rotación (incluyen los totales mensuales del Excel histórico y la foto mensual de plantilla), así que el índice coincide con el que ve RH en People. `meta.fuente_plantilla` dice cuál se usó (`oficial_mensual` o `reconstruida_por_fechas`).
- `inicial + altas − bajas` no tiene por qué dar `final` exacto: hay bajas que no cuentan como rotación, cambios de sucursal y bajas históricas. Se informan las cuatro cifras, no se fuerza el cuadre.
- Un periodo que termina después de hoy se corta a hoy (`corte`, `corte_parcial=true`); los que empiezan después de hoy no salen.

### Cómo debe consumir Reportes la rotación

1. Una vez: emparejar `branches` de Reportes con `/sucursales` (por `clave` o `nombre_normalizado`, revisado a mano) y guardar el `id` de People.
2. Por cada periodo semanal de Reportes: `GET /rotacion?agrupacion=rango&desde=<start_date>&hasta=<end_date>&desglose=sucursal` (o un solo llamado `agrupacion=semanal` para varias semanas seguidas).
3. Por cada mes operativo de 4 semanas: `agrupacion=rango` con su `start_date`/`end_date`, o `agrupacion=bloques_4_semanas` desde su primer lunes.
4. Tomar `bajas`, `plantilla_inicial`, `plantilla_final` y `rotacion_pct` por sucursal en lugar de «los que dejaron de recibir pago». Si una sucursal de Reportes no tiene pareja en People, reportarlo como dato no disponible, nunca como 0.
5. Para el detalle (quién se fue y por qué) usar `/movimientos/bajas` con el mismo rango.

## 4. Caché y ETag

Cada respuesta no sensible lleva `ETag` y `Cache-Control: private, no-cache`. Si se manda `If-None-Match` con el último ETag y nada cambió, la respuesta es **304** sin cuerpo (ahorra transferencia en sincronizaciones frecuentes). Un mismo cálculo de rotación se reutiliza 60 s en el servidor (`INTEGRACION_ROTACION_CACHE_SEGUNDOS`).

## 5. Límites de solicitudes

Valores en config versionada:

| Límite | Valor | Dónde |
|---|---|---|
| API de integración, por cliente | **600/min** (`INTEGRACION_LIMITE_POR_MINUTO`) o el `--limite` propio del cliente | `config/integraciones.php` → `sistemas` |
| Tope de un límite propio | 6000/min (`INTEGRACION_LIMITE_MAXIMO_POR_MINUTO`) | `config/integraciones.php` |
| Auditoría (`/api/v1/auditoria`), por cliente | 300/min (antes 120) o su límite propio | `config/integraciones.php` → `auditoria` |
| Tokens fallidos por IP | 20/min | `AutenticarClienteIntegracion` |
| API móvil, por cuenta | **240/min** (antes 120, `API_MOVIL_LIMITE_POR_MINUTO`) | `config/limites_api.php` |
| Cargas pesadas (móvil) | 30/min | `config/limites_api.php` |
| Login móvil | 10/min por usuario+IP — **no configurable** a propósito | `AppServiceProvider` |

Cada cliente tiene su propio cubo (dos sistemas detrás del mismo NAT no se estorban). Toda respuesta trae `X-RateLimit-Limit` y `X-RateLimit-Remaining`; al pasarse, 429 con `Retry-After`.

## 6. Bitácora de consultas

Cada consulta de un cliente autenticado (también los 403 y 429) queda en `consultas_integracion`: cliente, método, nombre de ruta, URL, filtros (solo los conocidos; el cursor truncado), estado HTTP, registros devueltos, duración en ms, IP y fecha. **Nunca** guarda el token ni el cuerpo de la respuesta. Un fallo al escribirla no rompe la consulta (queda en el log). Retención: 365 días (`INTEGRACION_BITACORA_RETENCION_DIAS`, mínimo 30), depurada por `php artisan model:prune` (`ConsultaIntegracion` es `MassPrunable`; programar `model:prune` en el scheduler si aún no corre). `integraciones:clientes estado <clave>` muestra las consultas de las últimas 24 h. Además, emisión, rotación y revocación de tokens quedan en `activity_log` (log `rh`).

## 7. Ejemplos con curl

```bash
TOKEN="ppl_int_..."
BASE="https://people.mr-lana.com/api/integracion/v1"

curl -s -H "Authorization: Bearer $TOKEN" "$BASE/capacidades"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/sucursales?empresa_id=1"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/colaboradores?estatus=activos&por_pagina=500"
curl -s -H "Authorization: Bearer $TOKEN" --get "$BASE/colaboradores" \
     --data-urlencode "actualizado_desde=2026-11-16T08:00:00-06:00"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE/movimientos/bajas?desde=2026-10-01&hasta=2026-10-31"
curl -s -H "Authorization: Bearer $TOKEN" \
     "$BASE/rotacion?agrupacion=semanal&desde=2026-10-05&hasta=2026-11-01&desglose=sucursal"
curl -s -H "Authorization: Bearer $TOKEN" \
     "$BASE/rotacion?agrupacion=mes_calendario&desde=2026-01-01&hasta=2026-10-31&desglose=total"
# Revalidar con ETag
curl -s -i -H "Authorization: Bearer $TOKEN" -H 'If-None-Match: "<etag>"' "$BASE/sucursales"
```

## 8. Código

- Rutas: `routes/api.php` (grupo `integracion/v1`, nombres `api.integracion.v1.*`).
- Controladores (delgados): `app/Http/Controllers/Api/Integracion/V1/*`; recursos con lista blanca: `app/Http/Resources/Api/Integracion/V1/*`.
- Servicios: `app/Services/Integraciones/Sistemas/*` (catálogos, plantilla, movimientos, rotación, periodos), `TokenIntegracionService`, `BitacoraConsultasIntegracionService`.
- Middleware: `AutenticarClienteIntegracion` (token + habilidad), `RegistrarConsultaIntegracion` (bitácora); limiter `integracion` en `AppServiceProvider`.
- Pruebas: `tests/Feature/Integraciones/IntegracionSistemasApiTest.php`.
