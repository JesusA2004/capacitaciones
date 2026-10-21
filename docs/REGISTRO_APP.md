# Registro completo en la app antes de entrar

Quien crea su cuenta con el **QR de incorporación** hace su registro completo
(datos y documentos) en la app **antes** de entrar al inicio. Reglas en
`App\Services\Incorporacion\RegistroAppService`.

## A quién aplica (regla de bloqueo)

`RegistroAppService::aplica($usuario)` es verdadero solo si **todo** se cumple:

1. La cuenta tiene colaborador con `estatus = en_incorporacion`.
2. RH todavía no decide su incorporación (`incorporacion_decision` nulo).
3. La cuenta se creó con una invitación QR (`incorporacion_invitaciones.usado_por_id`/`user_id`, `used_at` lleno).
4. Esa invitación no tiene `metadata.registro_app_completado_en`.

Nunca aplica a colaboradores activos, a cuentas creadas por RH/importación
ni a quien ya terminó: ellos siguen con «Completa tu información» y las
correcciones de siempre.

## Qué exige para terminar

- Todos los datos personales contractuales vacíos de
  `DatosFaltantesService::PERSONALES` (género, nacimiento, nacionalidad,
  estado civil, lugar de nacimiento, CURP, RFC, NSS, clave de elector,
  profesión, teléfono, correo personal, domicilio estructurado, contacto de
  emergencia y beneficiario). `config('incorporacion.registro_app.campos_opcionales')`
  puede liberar campos sin tocar código.
- Todos los documentos **requeridos** del expediente con archivo (en
  revisión o aprobados; pendiente/rechazado/requiere corrección/vencido no
  cuentan).
- Datos médicos (condición médica, alergias): opcionales, cifrados en
  `colaborador_datos_medicos` (`fuente = registro_app`).

## Captura inicial ≠ editar el expediente

Durante el registro la persona **llena datos vacíos** (igual que el registro
por QR ya guardaba CURP/RFC/NSS). Lo que RH ya había capturado en la
contratación queda **bloqueado** (422 con mensaje claro): si está mal, se
pide corrección después. Lo que la propia persona capturó en este registro
sí puede corregirlo mientras no termine (`metadata.registro_app_campos`).
Al terminar, cualquier cambio es por solicitud de corrección. Cada guardado
y el cierre quedan en la auditoría (`registro_app_datos_capturados`,
`registro_app_completado`) sin valores sensibles.

## API (todas con `auth:sanctum`, siempre la cuenta en sesión)

| Método | Ruta | Uso |
|---|---|---|
| GET | `/api/v1/colaborador/registro` | Estado: campos con valor/obligatorio/bloqueado, faltantes, documentos, `puede_finalizar`, `pendientes`. |
| POST | `/api/v1/colaborador/registro/datos` | `{ datos: {campo: valor}, datos_medicos?: {condicion_medica, alergias} }` — mismas reglas de formato que la actualización de datos. |
| POST | `/api/v1/colaborador/registro/finalizar` | Cierra el registro (422 con lo que falta si no está completo). |
| POST | `/api/v1/colaborador/incorporacion/documentos/{tipo}/subir` | Documentos (endpoint de siempre). |

`registro_completo` (bool) viaja en `GET /me`, en `usuario` de `POST /login`
y de `POST /incorporacion/invitaciones/{token}/registrar`.

## Bloqueo en backend y compatibilidad con APKs instaladas

Middleware `registro.app` (`App\Http\Middleware\ExigirRegistroApp`) en el
grupo autenticado de la API: si la cuenta tiene el registro pendiente,
responde **403 `{codigo: "registro_incompleto"}`** a todo salvo sesión,
`mobile/bootstrap`, dispositivos, registro, incorporación documental,
datos faltantes, perfil y foto.

Solo se exige a la app que declara entender el registro con la cabecera
**`X-People-Registro`** (la app nueva la manda siempre). Las APKs ya
instaladas no la mandan y siguen con su flujo anterior (checklist de
incorporación) sin romperse; ignoran el campo `registro_completo`.

Pruebas: `tests/Feature/Api/RegistroAppApiTest.php`.
