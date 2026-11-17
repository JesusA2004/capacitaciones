# Fechas laborales del colaborador

Última actualización: 2026-11-18. Fuente única: `App\Services\Colaboradores\FechasLaboralesService`.
Complementa `docs/CICLO_LABORAL_FINAL_IMPLEMENTADO.md`, `docs/EVALUACIONES_TECNICAS.md` y `docs/CORRECCIONES.md`.

## Qué fecha es cuál

| Fecha | Dónde vive | Quién la escribe | Para qué se usa |
|---|---|---|---|
| **Fecha de ingreso** (inicio de labores) | `colaboradores.fecha_ingreso` (ya existía) | Alta / contratación; RH en «Fechas laborales» (mismos bloqueos de «Datos laborales»: no se corrige con contrato firmado, recibos emitidos o finiquito firmado/pagado) | Antigüedad, vacaciones, rotación, plantilla, integraciones. **Su semántica no cambió.** |
| **Inicio de capacitación inicial** | `colaboradores.periodo_prueba_inicio` (ya existía; lo llena el contrato de capacitación inicial) | `ContratoLaboralService::crearContrato` o RH en «Fechas laborales» | **Desde aquí corren los días** del periodo de prueba y de los Seguimientos. Vacía = la fecha de ingreso. |
| **Periodo de prueba específico** | `colaboradores.periodo_prueba_cantidad` + `periodo_prueba_unidad` (`meses` / `dias`) — **nuevas** | RH en «Fechas laborales» | Si está vacío **hereda** `puestos.meses_periodo_prueba`. |
| **Fecha de inducción** | `colaboradores.fecha_induccion` — **nueva** | RH (sobre todo plantilla importada que la tomó antes de PEOPLE) | Si está vacía se muestra la de su evaluación de inducción aprobada en PEOPLE (`evaluaciones_induccion.aprobada_en`). Solo informativa: no mueve cálculos. |
| **Alta IMSS** | `colaboradores.fecha_alta_imss` (ya existía) | Alta IMSS / RH en «Fechas laborales» | Reportes nominales, recepción documental. |
| **Fecha de contrato vigente** | `contratos_laborales.fecha_inicio/fecha_fin` (ya existía) | **Solo por el contrato** (dominio de correcciones `contratacion`, regeneración) | Nunca se edita desde «Fechas laborales»; si quedó desfasada aparece la alerta. |

«Capacitación inicial» y «periodo de prueba» son la **misma etapa** (`TipoContratacion::normalizada`): su fin es una sola fecha.

Columnas nuevas: migración aditiva `2026_11_18_100000_agrega_fechas_laborales_a_colaboradores` (todas nulas, no se rellenan datos existentes).

## Reglas de cálculo (FechasLaboralesService)

- **Periodo efectivo** = específico de la persona ?? del puesto. Sin ninguno: «Sin definir» (nunca uno inventado).
- **Fin del periodo de prueba** = inicio de capacitación inicial (o ingreso) + periodo − 1 día (meses sin desbordar fin de mes; días = N − 1).
- **Se habilita la evaluación de periodo de prueba** = primer día en que faltan `contratos.dias_aviso_vencimiento` (15) días **hábiles** o menos para el fin (misma cuenta que el scheduler).
- **Seguimiento N** = inicio de capacitación inicial (o ingreso) + N días.

Quién los usa (refactor): `ElegibilidadEvaluacionPeriodoPruebaService` (`finPeriodo`, `asignableDesde`), `PeriodosPruebaService` (pestaña «Próximas / por asignar»; ventana por `COALESCE(periodo_prueba_inicio, fecha_ingreso)`), `ProgramacionEvaluacionTecnicaService` y `EvaluacionTecnicaService::lineaDeTiempo` (Seguimientos), `RegularizacionContratosService::plan` (contrato inicial propuesto), `DatosDocumentoService::finSegunPuesto` (fin impreso sin contrato). `ContratoLaboralService::fechaFinPeriodoPrueba` sigue usándose al contratar (persona nueva, aún sin periodo específico).

Las fechas IMPRESAS en el paquete de contrato no cambian de regla (decisión 2026-10-21: fecha del contrato = fecha de ingreso; vigencia según el contrato registrado).

## Editar (con impacto, motivo e historial)

- Expediente → **Datos → Fechas laborales** (`?tab=fechas`, nunca en el propio expediente). Componente `components/Rh/Expediente/FechasLaborales/*`; datos por `GET rh/expedientes/{id}/fechas-laborales`.
- La escritura va por el **sistema universal de correcciones**, dominio `fechas_laborales` (`FechasLaboralesDominio`): vista previa con *lo capturado*, **fechas que se recalculan** (antes → después: fin del periodo, evaluación habilitada, Seguimientos) y **qué más se afecta** (contrato, evaluaciones pendientes, antigüedad); motivo obligatorio; huella anti-conflicto; historial inmutable (`correcciones` + `correccion_campos`, incluidas las fechas calculadas que se movieron). Se consulta en «Cambios de datos → Correcciones auditadas». API: `/api/v1/rh/correcciones/fechas_laborales/{id}` (aditivo) y `GET /api/v1/rh/colaboradores/{id}/fechas-laborales`.
- Al guardar (`FechasLaboralesService::guardar`/`recalcularPendientes`):
  - `colaboradores.periodo_prueba_fin` se actualiza con el nuevo fin (si ya tenía periodo registrado o se capturó uno);
  - Seguimientos técnicos **pendientes** se reprograman (conservan su plazo para contestar); los **contestados no se tocan**;
  - la evaluación de periodo de prueba **pendiente/devuelta** toma el nuevo fin como fecha límite; capturada/autorizada no se toca;
  - **el contrato nunca se modifica.**
- Permiso: `expedientes.editar_fechas` (módulo Expedientes). Migración `2026_11_18_100100` lo otorga a todo rol que hoy tiene `expedientes.editar`. Nadie edita sus propias fechas.

## Alertas (se calculan siempre; desaparecen al corregir la causa)

| Código | Cuándo | Acción |
|---|---|---|
| `contrato_desfasado` | Contrato de capacitación inicial vigente **sin firmar** con inicio/fin distinto a las fechas laborales | «Actualizar contrato» (corrección del contrato) y regenerar el documento |
| `contrato_firmado_desfasado` | Igual, pero **firmado**: la fecha impresa ya no coincide | Modificación contractual / nuevo contrato (flujo existente) |
| `evaluacion_desfasada` | Evaluación pendiente con otra fecha límite | Se ajusta al guardar fechas |
| `sin_contrato` | Sigue en periodo de prueba sin contrato vigente en PEOPLE | Ir a Contratos (la regularización toma estas fechas) |
| `faltan_fechas` | Sin ingreso o sin periodo (ni específico ni del puesto) | Capturar fechas |

## Plantilla importada por Excel (sin candidato)

- Evaluación de periodo de prueba: no se les exige inducción (`exige_induccion = false`); solo necesitan un contrato vigente con fin. Si no lo tienen, la alerta `sin_contrato` lleva a **Contratos** (regularización), que ya registra el contrato con estas fechas.
- Inducción: «Asignar inducción» ya abre una **reinducción** a personal activo; su fecha histórica se captura en `fecha_induccion`.
- Seguimientos técnicos: se programan por fecha base; a quien entró hace mucho no le llueven evaluaciones viejas (ventana de gracia de 15 días). Capturar un inicio de capacitación reciente los programa.
- **Captura masiva**: `rh/fechas-laborales` (`Rh/FechasLaborales/Index`): activos del alcance con fechas faltantes (o todos), filtros buscables por sucursal y puesto, edición en línea y un motivo para el lote (cada persona queda con su propia corrección auditada). No se inventa ninguna fecha: lo vacío se muestra como «Falta».

## Pendientes de negocio

- ¿El inicio de capacitación inicial puede ser anterior al ingreso? Hoy solo se avisa («Revisa que sea correcto»).
- Seguimientos configurados más allá del fin de un periodo específico corto (p. ej. Seguimiento 60 con periodo de 1 mes) se siguen generando: falta decidir si se omiten.
- El scheduler `contratos:revisar-vencimientos` sigue disparando por la fecha fin del **contrato**; si las fechas laborales adelantan el fin, la evaluación se puede asignar a mano desde «Próximas» (o al actualizar el contrato).
- Quién contesta la evaluación de periodo de prueba sigue **sin resolver** (no se cambió).
