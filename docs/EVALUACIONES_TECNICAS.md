# Evaluaciones técnicas por puesto («Seguimiento N»)

Fase 2 «Seguimiento». Son pruebas técnicas que se aplican a un colaborador en días fijos desde su **fecha de ingreso** (`colaboradores.fecha_ingreso`), distintas de:

- la **evaluación de inducción** (FO-RH-EI, la contesta el colaborador al terminar la Inducción Mr. Lana), y
- la **evaluación de capacitación inicial / periodo de prueba** (FO-RH-EPP-001, la contesta el jefe directo y decide la renovación del contrato).

Cada evaluación se llama por su día: **«Seguimiento 15»**, **«Seguimiento 30»**, **«Seguimiento 60»**… (patrón `Seguimiento {días}`, editable por RH).

## Ejemplo: Gestor (periodo de prueba de 2 meses)

1. **Seguimiento → Calendario de seguimientos** (`/seguimiento/evaluaciones-tecnicas/configuracion`), pestaña «Formularios técnicos» → **Nuevo formulario técnico** (p. ej. «Técnica de cobranza — Gestor»). Se abre el constructor tipo Google Forms: arma las preguntas, marca la respuesta correcta y los puntos, ajusta el diseño y **publícalo**. Solo un formulario con versión publicada se puede generar.
2. Pestaña «Calendario por puesto» → elige **Gestor**. Como el catálogo de puestos tiene `meses_periodo_prueba = 2`, el botón **Usar sugerencia** arma `Seguimiento 15 · Seguimiento 30 · Seguimiento 60` (la de 60 marcada como **técnica final**).
3. En cada una elige el formulario, **quién la contesta** (colaborador como examen o jefe directo), la **calificación mínima** (0-100) y los **días para contestar**. **Guardar calendario**.
4. Opcional: **Copiar a otros puestos** (Gestor Volante, Gestor grupal…).

Desde ese momento, cada mañana (06:45, `evaluaciones-tecnicas:programar`) se genera la evaluación a cada gestor activo cuyo día 15/30/60 ya llegó, y se le avisa a quien la contesta. RH también puede pulsar **Generar las que ya tocan** en el tablero.

## Modelo

| Tabla | Qué guarda |
|---|---|
| `plantillas_evaluacion_tecnica` | Formularios técnicos reutilizables (nombre, activa). Un formulario puede usarse en varias evaluaciones y puestos. |
| `formularios_evaluacion` | Las **versiones** de cada formulario (clave `tecnica`, `familia = tecnica_{id}`): el mismo constructor, versionado, borrador/publicar/descartar, banner y vista previa que inducción y periodo de prueba. |
| `pasos_evaluacion_tecnica` | Calendario por puesto: `dias`, `nombre`, plantilla, `contesta`, `calificacion_minima`, `dias_para_contestar`, `es_final`, `activo`. Único (puesto, días). |
| `evaluaciones_tecnicas` | Cada evaluación generada: colaborador, puesto, día, versión del formulario fijada al generarse, evaluador (jefe directo del organigrama), fechas, respuestas, puntos, calificación 0-100, aprobada. Único (colaborador, puesto, días) → el programador es idempotente. |

`formularios_evaluacion.familia` sustituye al antiguo único `(clave, version)`: inducción y periodo de prueba tienen `familia = clave` (idéntico a antes); cada plantilla técnica tiene la suya.

## Reglas

- **Día N** = `fecha_ingreso + N días`. La fecha límite = día N + `dias_para_contestar`.
- **Ventana de gracia** (`config/evaluaciones_tecnicas.php → dias_gracia_generacion`, 15 por defecto): solo se generan evaluaciones cuyo día pasó hace a lo más esa cantidad de días. Así, al configurar un puesto, a quien entró hace años no le llueven evaluaciones viejas; en su línea de tiempo aparecen como «No se generó».
- **Quién contesta** es configurable por evaluación. Si es el jefe directo, sale del organigrama (`colaboradores.jefe_id`, o su gerente si no tiene jefe) — nunca se captura a mano. Quien tenga `evaluaciones_tecnicas.aplicar` con alcance sobre la persona también puede contestarla en su lugar (nunca la suya propia).
- **Calificación** = puntos obtenidos / puntos posibles × 100. Opción múltiple y V/F: todo o nada; casillas: todas las correctas exactas; escala: proporcional; abiertas: no suman (quedan como evidencia). Aprobada = calificación ≥ mínima del paso.
- Las respuestas correctas **nunca** llegan a quien contesta. El colaborador ve su calificación solo si el formulario tiene «mostrar resultado» activo.
- Si el colaborador deja de estar activo o cambia de puesto, sus pendientes se **cancelan** (no se borran).
- Una evaluación vencida se avisa **una sola vez** más.

## Avisos (capa única de notificaciones)

| Evento | A quién |
|---|---|
| `evaluacion_tecnica_pendiente` | Quien la contesta (colaborador o jefe directo). |
| `evaluacion_tecnica_vencida` | Quien la contesta, una vez. |
| `evaluacion_tecnica_contestada` | Al jefe directo cuando su colaborador contestó su examen. |

Un fallo al avisar nunca deshace la evaluación (`Log::warning`).

## Permisos (módulo `evaluaciones_tecnicas` en Roles y permisos)

- `evaluaciones_tecnicas.ver` + alcance (`ver_todos` / `ver_region` / `ver_sucursal` / `ver_equipo`): tablero y líneas de tiempo.
- `evaluaciones_tecnicas.aplicar`: contestar como evaluador en su alcance.
- `evaluaciones_tecnicas.configurar`: calendario por puesto y formularios técnicos.

Por defecto: super_admin y rh_admin todo; gerente regional / gerente de sucursal / gerente: ver + aplicar en su alcance. Migración aditiva `2026_11_17_800100_otorga_permisos_evaluaciones_tecnicas`.

## Pantallas

- **Seguimiento → Seguimientos técnicos** (`/seguimiento/evaluaciones-tecnicas`): KPIs, aprobación por puesto, avance de Seguimiento 15 → final, próximas a vencer, vencidas y tabla con filtros.
- **Línea de tiempo por colaborador** (`/seguimiento/evaluaciones-tecnicas/colaboradores/{id}`).
- **Resultado** (`/seguimiento/evaluaciones-tecnicas/{id}`) y **Contestar** (`…/{id}/contestar`).
- **Mi espacio → Mis seguimientos técnicos** (`/mis-evaluaciones-tecnicas`): lo que el colaborador contesta desde la web (la app Expo no se tocó) y, si es jefe directo, las de su equipo.

## Para otros módulos

`App\Services\EvaluacionesTecnicas\ResultadosEvaluacionTecnicaService::promedioPorColaborador(array $colaboradorIds, CarbonInterface $desde, CarbonInterface $hasta): array<int, float>` — promedio 0-100 de las evaluaciones contestadas en el rango; quien no tenga, no aparece. Lo consume Nine Box.

## Pendientes de negocio

- Confirmar por puesto quién contesta cada Seguimiento (colaborador vs. jefe directo) y la mínima aprobatoria real; hoy la sugerencia nace con «colaborador» y 80.
- Definir qué pasa al **no aprobar** la técnica final (¿alimenta la no renovación del periodo de prueba?). Hoy solo queda registrado.
- Confirmar la ventana de gracia (15 días) para quien ya estaba a mitad de su calendario al configurarse.
- Las preguntas abiertas no se califican; si RH quiere calificarlas a mano, falta una pantalla de revisión.
