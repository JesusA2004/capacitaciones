# Actas administrativas (PRO-ADP-08)

Documentar y resguardar actas administrativas (y actas de hechos, cartas
responsivas y actas de auditoría) que sostienen la defensa jurídica.

## Flujo

1. **Levantar el acta** (jefe inmediato o RH, permiso `actas.crear`):
   *Personal › Actas administrativas › Levantar acta*. Se captura el
   **motivo** (texto libre, obligatorio en el acta administrativa), fecha,
   hora, ciudad (por defecto la de la sucursal), **hechos**, testigos y las
   **manifestaciones** de cada persona.
2. **Formato oficial**: en el detalle del acta, *Formato del acta* usa
   «Documentos del proceso» (tipo `acta`, `DocumentoProcesoService::seccionActa`).
   El formato se llena con los datos del colaborador (nombre, puesto,
   contrato y sus fechas, jefe inmediato del organigrama, empresa y
   representante legal) y con lo capturado en el acta
   (`ActaService::variablesDocumento`). Si falta algo, se pide por su
   nombre antes de generarlo; nunca sale con una línea en blanco.
3. **Firma o negativa**: flujo físico del documento (imprimir → firma →
   envío → corporativo → escaneo → archivo). Si el colaborador no firma,
   *El colaborador se negó a firmar* lo asienta en el acta y su seguimiento.
4. **Resguardo y seguimiento**: el PDF queda en la carpeta *Actas* del
   expediente; anexos (evidencias), notas de seguimiento de RH/Jurídico y
   cierre del acta.

La web (`Rh\ActaController`) y la app (`Api\V1\Rh\ActaController`) usan el
mismo `ActaService`; generar el formato desde la API sigue el mismo camino
(`ActaService::generarDocumento` → `DocumentoProcesoService::generar`).

## Formato

`docs/formatos_fuente/ACTA ADMINISTRATIVA (GENERAL).docx` (familia
`acta_administrativa.general`) se derivó del acta oficial de Jurídico
«ACTA ADMINISTRATIVA-POR NEGATIVA DE FIRMA Y RECEPCIÓN DE DOCUMENTOS.docx»:
mismo diseño, encabezado, personas que intervienen, datos del colaborador,
testigos y firmas. Solo se retiraron los hechos y la manifestación propios
de la negativa; el encabezado lleva «POR {motivo}» y los hechos y las
manifestaciones son los capturados. **Pendiente: validación del texto por
Jurídico.**

- *Tipo de contrato* y sus fechas: del contrato más reciente; sin contrato
  capturado, de la modalidad del colaborador (`tipo_contratacion`, se puede
  capturar en el modal y queda en su ficha). Indeterminado = «Tiempo
  indeterminado»; capacitación inicial sin contrato = ingreso + duración de
  capacitación del puesto.
- El acta de hechos, la carta responsiva y el acta de auditoría no tienen
  formato oficial de Jurídico todavía: se generan con la plantilla que RH
  tenga cargada para su clave o, si no hay ninguna, el sistema avisa que
  falta el formato.

En un servidor nuevo: `php artisan people:importar-formatos-juridicos`
(idempotente) registra el formato.
