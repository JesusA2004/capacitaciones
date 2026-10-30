# Agenda de entrevistas · contrato de la API móvil

Base: `/api/v1`, autenticación Sanctum. Web y API llaman a `AgendaEventoService` y `AgendaConsultaService`, así que las reglas son las mismas. Errores: `403` sin permiso o fuera de alcance, `422` con `errors` por campo.

## Permisos

| Permiso | Qué abre | Por defecto |
|---|---|---|
| `agenda.ver` + alcance `agenda.ver_todos` / `ver_region` / `ver_sucursal` | Consultar citas | RH y Dirección: todos. Gerente, regional y subgerente: su sucursal |
| `agenda.agendar` | Agendar, reprogramar, cancelar | RH, gerentes |
| `agenda.alta_candidato` | Alta rápida al agendar | RH, gerentes |
| `agenda.registrar_resultado` | Resultado e inasistencia; además exige el permiso del paso en Candidatos | RH, gerentes |
| `agenda.ver_datos_candidato` | Teléfono, domicilio y CV | RH, gerentes |

El subgerente solo consulta por defecto. Sin `agenda.ver_datos_candidato`, los campos `candidato.telefono`, `candidato.tiene_cv` y `domicilio` llegan `null`. Muestra los botones según `puede`, pero el backend siempre vuelve a validar.

## Endpoints

| Método y ruta | Cuerpo | Respuesta |
|---|---|---|
| `GET /agenda?desde&hasta&sucursal_id&tipo&estado` | Por defecto, de hoy en adelante, sin reprogramadas ni canceladas | `data.dias[] = {fecha, eventos[]}`, `meta.motivos_no_viable[]`, `meta.puede_agendar`, `meta.puede_alta_candidato` |
| `GET /agenda/opciones` | | `data.sucursales[] = {id, nombre}` del alcance; `data.candidatos[] = {id, nombre, puesto, sucursal_id}`, vacío sin `agenda.agendar` |
| `GET /agenda/{id}` | | `data` = evento con `historial` |
| `POST /agenda` | Ver abajo | `201`, o `200` con `repetido: true` si la clave ya existía. `data`, `empalmes[]` |
| `POST /agenda/{id}/reprogramar` | `fecha`, `hora`, `motivo?` | `data` = la cita NUEVA, `empalmes[]` |
| `POST /agenda/{id}/cancelar` | `motivo` | `data` |
| `POST /agenda/{id}/no-asistio` | `comentario?` | `data` |
| `POST /agenda/{id}/resultado-entrevista` | `resultado` (`viable`/`no_viable`); con `no_viable`, `motivo` y `comentario` | `data` |
| `POST /agenda/{id}/resultado-socioeconomico` | Multipart: `resultado` (`favorable`/`no_favorable`), `evidencias[]`, `comentario?` (obligatorio si no favorable), `direccion?`, `fecha_visita?` | `data` |
| `GET /agenda/{id}/cv` | | Archivo; `404` si no hay CV |

### `POST /agenda`

`sucursal_id`, `tipo` (`entrevista`/`socioeconomico`), `fecha` (`Y-m-d`), `hora` (`H:i`), `candidato_id` **o** `candidato_nuevo {nombre, apellidos?, telefono?, correo?, puesto_objetivo_id?, vacante_id?}`, `notas?`, `domicilio?`, `responsable_user_id?`, `cv?` (multipart), `clave_idempotencia?`.

Genera `clave_idempotencia` (UUID) al abrir el formulario y reutilízala en cada reintento: el mismo envío devuelve la misma cita. La hora es de reloj de México. `origen` lo decide el servidor según la cuenta.

Archivos del candidato, con la Policy de la ficha (`candidatos.ver` + alcance): `GET /rh/candidatos/{id}/cv` y `GET /rh/candidatos/{id}/evidencias/{evidencia}`.

## Evento

`id, tipo, tipo_etiqueta, fecha_hora, fecha, hora, estado, estado_etiqueta, origen, origen_etiqueta, agendado_por, sucursal{id,nombre}, candidato{id,nombre,puesto,estado,estado_etiqueta,telefono,tiene_cv}, domicilio, notas, resultado, resultado_etiqueta, motivo_no_viable, motivo_no_viable_etiqueta, comentario_resultado, resultado_en, motivo_cancelacion, reprogramado_de_id, reprogramado_a_id, puede{gestionar,registrar_resultado,ver_datos_candidato,reprogramar,cancelar,capturar}, historial[]|null`.

`estado`: `programado`, `realizado`, `no_asistio`, `reprogramado`, `cancelado`.

## Reglas para la pantalla

- El resultado solo se acepta a partir del día de la cita. Repetir el mismo resultado responde `200` sin duplicar; otro distinto responde `422`.
- Reprogramar devuelve otra cita: navega a `data.id`. Una inasistencia también se puede reprogramar.
- Un candidato no tiene dos citas vivas del mismo tipo.
- Los empalmes son advertencias: la cita sí se crea.
- `candidato_nuevo` puede responder `422` en `candidato_nuevo.nombre` si la persona ya está registrada: pide elegirla de la lista.
- Si quien captura un socioeconómico no tiene el paso de revisión, la cita queda `realizado` pero el candidato sigue en su paso hasta que otro lo revise.

## Notificaciones

Llegan por la capa de avisos con `data.type = 'agenda_evento'`, `related_type = 'AgendaEvento'`, `resource_id` = id de la cita y `evento_tipo`. Eventos: agendado, reprogramado, cancelado y recordatorio (un día antes y el mismo día). Un push entregado no significa leído; usa la bandeja de notificaciones para el estado de lectura.
