# Sistema universal de correcciones

Corrige un dato con motivo, vista previa de afectados, control de concurrencia e
historial inmutable. Una capa delgada (`app/Services/Correcciones`): las reglas
de cada dato siguen en sus servicios y activitylog sigue funcionando.

## Flujo

1. `GET correcciones/{dominio}/{id}`: dato vigente, modo de cada campo y huella.
2. `POST …/vista-previa`: antes/después y afectados. No escribe.
3. `POST correcciones/{dominio}/{id}`: en una transacción bloquea la fila, autoriza,
   exige motivo (5 a 500 caracteres, **en el servicio**), compara la huella (409 si
   cambió), delega y escribe `correcciones` + `correccion_campos`.
4. `GET …/historial` y `GET correcciones/colaboradores/{id}/historial`.

Fuera de la transacción van bitácora y aviso: si fallan, la corrección se conserva.
El aviso nunca incluye el valor. Espejo móvil en `/api/v1/rh/correcciones`, sin
`salario`, sin `recibo_nomina` y sin el sueldo del contrato (404 o campo ausente).

## Modos por campo (`config/correcciones.php`)

| Dominio | Campo | Modo | Permiso | Regla |
|---|---|---|---|---|
| salario | sueldo mensual | con vigencia | `salarios.editar` + ver sueldo | Ver «Tipos» |
| salario | diario | no corregible | | Derivado (mensual ÷ 30) |
| salario | integrado | no corregible | | Pendiente de validación |
| laboral | puesto, sucursal, departamento | directo | `expedientes.editar` | Vacío no borra; jefe se recalcula |
| laboral | fecha de ingreso | directo; no corregible si hay contrato firmado, recibo emitido o finiquito firmado/pagado | `expedientes.editar` | Pendiente de validación |
| laboral | jefe, tipo de contratación, baja | no corregible | | Derivados o con flujo propio |
| personal | identidad, contacto, domicilio, beneficiario | directo | `expedientes.editar` | Formatos de CURP/RFC/NSS |
| personal | datos médicos | directo, cifrado | `expedientes.datos_medicos.ver` | |
| contratacion | fechas, observaciones, sueldo | directo sin firmar; nueva versión si firmado; no corregible si no vigente | `contratos.generar` (`salarios.editar` para sueldo) | No se genera anexo |
| incapacidad | folio, inicio, días, observaciones | directo; no corregible si cancelada | `incapacidades.corregir` | Reglas de `IncapacidadService` |
| incapacidad | tipo | no corregible | | Cancelar y recapturar |
| recibo_nomina | días, conceptos, observaciones | directo en preparado; solo rectificación en emitido; no corregible si cancelado o ya rectificado | `nomina.recibos.crear` | Ver «Recibos» |
| finiquito | conceptos, comentarios | directo; no corregible si firmado o pagado | `finiquitos.calcular` | `FiniquitoService::corregir()` |
| agenda | fecha y hora | nueva cita (la anterior se conserva) | `agenda.agendar` | `reprogramar()` |
| agenda | notas | directo si está programada | `agenda.agendar` | |

## Tipos de cambio de sueldo (`historial_salarios.tipo`)

- **Primera captura:** solo sin sueldo previo.
- **Aumento:** mayor al vigente, rige hoy. No toca recibos.
- **Corrección:** el vigente fue un error; rige desde que ese valor entró en vigor.
  Ajusta preparados y abre rectificación en emitidos.
- **Ajuste con vigencia:** fecha pasada o de hoy, no anterior a un cambio posterior
  ni al ingreso. Igual efecto en recibos.
- **Importación:** solo importadores.

`CompensacionService::vigenteEn()` lee el ledger por `fecha_efectiva`. Los renglones
antiguos solo tienen diario: su mensual es diario × 30 y sale `exacto = false`.

## Recibos

Un emitido o cancelado no se edita, ni en masa. Rectificar crea un recibo **nuevo**
(`rectifica_recibo_id`, PDF con `revision_de_id`); el original solo gana la marca
`rectificado_por_id`. Un descuento de préstamo no se cambia ni se vuelve a descontar.
Al emitir se fija `datos_emision` (colaborador y empresa); reparar el PDF lo usa.

## Pendiente de validación por RH y Jurídico

Mientras tanto rige la opción conservadora indicada:

1. Contrato firmado ante un cambio de sueldo: conserva lo firmado y reporta
   «requiere modificación contractual». No hay anexo automático.
2. Efecto retroactivo: se abre rectificación por cada recibo emitido del periodo; sin
   tope de periodos, sin diferencia automática en el siguiente recibo y sin aviso al IMSS.
3. Naturaleza del recibo de rectificación (folio, aviso, firma) y si un proceso
   externo como NOI también debe corregirse.
4. Finiquito firmado o pagado con error: no se modifica y no existe flujo.
5. Salario integrado: no se recalcula ni se corrige.
6. Cambio de sueldo a media quincena: se usa el sueldo vigente al cierre del periodo,
   sin prorratear, y se debe revisar a mano.
7. Cálculo de quincena (lote: diario × días; automático: desde mensual): sin cambios.
8. Fecha de ingreso con contratos firmados, recibos o finiquito: bloqueada.
9. Doble autorización y umbrales: no existen.
10. Purga (`PurgaPersonaService`): borra las correcciones de la persona como el resto
    de su historial; ser actor de correcciones ajenas detiene la purga.
11. Puesto y sucursal se corrigen sin vigencia: no distingue error de movimiento real.
12. Lista completa de modos de este documento.

## Fuera de esta entrega

- Cambios de sueldo con fecha futura: se rechazan; no hay programador.
- Documentos generados: siguen en su flujo de revisión (`DocumentoProcesoService`).
- PDF de recibos emitidos antes de `datos_emision`: reparar usa datos vigentes.
- La edición clásica del expediente sigue sin huella (D10): solo la protege este flujo.
