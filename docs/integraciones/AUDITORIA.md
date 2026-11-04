# Integración servidor a servidor con Auditoría MR LANA

People es el maestro del personal. El sistema **Auditoría MR LANA** lo consulta servidor a servidor, sólo lectura, con un token de integración propio. Esta guía describe los dos contratos vigentes.

| Contrato | Para qué | Rutas |
|---|---|---|
| `people-auditoria/1` | Proyección del personal (altas, cambios, bajas) y catálogo de sucursales. **Sin cambios.** | `GET /api/v1/auditoria/catalogo`, `…/colaboradores`, `…/colaboradores/{id}`, `…/sucursales` |
| `people-auditoria/2` | Datos del **Acta de constancia de auditoría** (domicilios, fecha de nacimiento, gerente). Sólo dos recursos. | `GET /api/v1/auditoria/sucursales/{id}/acta`, `GET /api/v1/auditoria/colaboradores/{id}/acta` |

People sirve ambos a la vez. Auditoría usa el v2 si existe y, si People responde 404 sin `codigo` (ruta inexistente: People todavía no desplegado), sigue funcionando sólo con el v1. Por eso **People se despliega primero**, pero el orden no rompe nada.

## Autenticación y límites (ambos contratos)

- Token de `clientes_integracion` (prefijo `ppl_aud_`, guardado sólo como SHA-256), habilidad **`auditoria:leer`** y empresas permitidas explícitas. No es Sanctum: un token de la app móvil o de una cuenta da **401**; un token sin la habilidad da **403** (`sin_permiso`).
- `php artisan integraciones:token-auditoria emitir --empresa=<id>|rotar|revocar|estado`. El v2 **no necesita token nuevo ni habilidad nueva**: el mismo token con `auditoria:leer` lee ambos contratos.
- Límite `throttle:integracion-auditoria` (120/min por cliente, `INTEGRACION_AUDITORIA_LIMITE_POR_MINUTO`), intentos fallidos limitados por IP. Errores en JSON `{message, codigo}`: 401, 403, 404 (`no_encontrado`), 422, 429 (`Retry-After`).
- Sólo GET. TLS verificado del lado de Auditoría.

## Contrato v2 — datos del acta

Cada respuesta: `{"contrato": "people-auditoria/2", "generado_en": "…", "data": {…}}` con `Cache-Control: no-store, private`. Un registro de una empresa no permitida responde 404 igual que uno inexistente.

### `GET /api/v1/auditoria/sucursales/{id}/acta`

| Campo | Origen en People | Nota |
|---|---|---|
| `id`, `empresa_id`, `clave`, `nombre`, `activo`, `updated_at` | `sucursales` | `activo` = activa y sin borrado lógico |
| `domicilio.direccion` | `sucursales.direccion` | Calle y número en un solo texto: Auditoría no lo parte |
| `domicilio.colonia`, `municipio`, `ciudad`, `estado`, `codigo_postal` | `sucursales` | `null` si no está capturado |
| `responsable_estado` | — | `asignado`, `sin_asignar`, `sin_colaborador` (la cuenta responsable no tiene persona) o `fuera_de_alcance` (otra empresa) |
| `responsable` | `sucursales.responsable_id` → `users.colaborador_id` | `{id, numero_empleado, nombre_completo, puesto, empresa_id, sucursal{id,clave,nombre}, activo}` o `null` |

### `GET /api/v1/auditoria/colaboradores/{id}/acta`

| Campo | Origen en People | Nota |
|---|---|---|
| `id`, `numero_empleado`, `nombre_completo`, `puesto`, `empresa_id`, `sucursal{id,clave,nombre}`, `activo`, `updated_at` | `colaboradores` | Misma regla de vigencia que el v1 |
| `fecha_nacimiento` | `colaboradores.fecha_nacimiento` | `Y-m-d`; Auditoría calcula la edad a la fecha del acta |
| `domicilio.estructurado` | — | `true` si hay calle capturada (`DomicilioService`) |
| `domicilio.calle`, `numero_exterior`, `numero_interior`, `colonia`, `municipio`, `estado`, `codigo_postal` | `domicilio_*` | `calle` sólo si es estructurado |
| `domicilio.texto` | `colaboradores.domicilio` | Sólo cuando **no** hay calle capturada (texto legado, tal cual, nunca partido) |
| `identificacion.clave_elector_registrada` | `clave_elector` | Booleano: **el número nunca sale**; no se afirma tipo ni vigencia del documento |
| `gerente_estado`, `gerente` | `colaboradores.gerente_id` | Sólo el gerente **capturado**; la deducción «jefe del jefe» no se entrega. `fuera_de_alcance` si es de otra empresa |

**Nunca salen**: salario/sueldo, contrato, CURP, RFC, NSS, número de clave de elector, teléfonos, correos, contactos de emergencia, datos médicos, contraseñas, tokens, fotos ni archivos del expediente. La lista blanca se aplica dos veces: columnas elegidas una por una en `ContextoActaAuditoriaService` y claves fijas en `SucursalActaAuditoriaResource` / `ColaboradorActaAuditoriaResource` (`ListaBlancaAuditoria`).

### Propósito y privacidad

Los datos sirven únicamente para imprimir el acta de constancia de auditoría (lugar, intervinientes y su edad y domicilio) e identificar al gerente que firma. Auditoría los guarda cifrados, los congela en la versión del acta que se firma y nunca escribe en People. El catálogo v1 anuncia `contratos: ["people-auditoria/1", "people-auditoria/2"]` (clave aditiva; `contrato` sigue siendo `people-auditoria/1`).

## Código

- Rutas: `routes/api.php` (grupo `auditoria`).
- Controlador: `app/Http/Controllers/Api/V1/Integraciones/AuditoriaController.php` (`sucursalActa`, `colaboradorActa`).
- Servicios: `app/Services/Integraciones/Auditoria/ColaboradoresAuditoriaService.php` (v1), `ContextoActaAuditoriaService.php` (v2).
- Pruebas: `tests/Feature/Integraciones/AuditoriaIntegracionApiTest.php` (v1) y `AuditoriaActaIntegracionApiTest.php` (v2: cliente permitido, token Sanctum rechazado, sin habilidad 403, otra empresa invisible, campos prohibidos ausentes, domicilio legado sin partir, responsable sin persona o de baja).

## Diagnóstico del acta (sólo lectura)

`php artisan auditoria:diagnosticar-acta --sucursal=<id|clave> --colaborador=<id|número de empleado> [--mostrar]` (opciones repetibles; `DiagnosticoActaAuditoriaService`). Por cada dato del acta (sucursal: dirección, colonia, municipio, ciudad, estado, C.P., responsable; persona: fecha de nacimiento, domicilio texto, calle, exterior, interior, colonia, municipio, estado, C.P., puesto, gerente, clave de elector registrada) imprime la tabla/columna de origen, si existe en la base (sí/no + longitud) y si la respuesta v2 que recibe Auditoría lo trae (resuelta con el cliente `auditoria` real, sin red). Señala además fuentes que **no** son autoridad del acta (texto legado en `users.domicilio`, alta digital, sugerencias OCR sin revisar) para capturarlas en el expediente. Nunca escribe; sin `--mostrar` no imprime valores, y la clave de elector nunca. La contraparte en Auditoría es `php artisan people:contexto-acta --diagnosticar=<folio>`.

## Despliegue

1. Desplegar People (sin migraciones nuevas; no requiere cambios de `.env`).
2. Verificar con el token existente: `curl -H "Authorization: Bearer <token>" https://<people>/api/v1/auditoria/catalogo` → `contratos` incluye `people-auditoria/2`.
3. Desplegar Auditoría.

## Deprecación futura del v1

El v1 sigue siendo el contrato de la proyección del personal. Si algún día se sustituye, se anunciará en `contratos` del catálogo y se mantendrá servido hasta que Auditoría deje de llamarlo.
