# Flujo uniforme de documentos generados

Un PDF nunca se edita: se corrigen datos y se emite otra versión desde la plantilla.
Sin reescribir motores. `app/Services/Documentos/Versiones`.

## Orden de `VersionadoDocumentoService::emitir`

1. Lista blanca (`FuenteDocumento::campos`): una clave ajena se rechaza.
2. Bloqueo del módulo y firmado: sin rectificación, 422; con ella, permiso y motivo.
3. Huella (`huella_datos`, sin fecha de emisión, referencias ni sellos): si coincide con
   la vigente, la devuelve sin escribir.
4. Motivo: 5 a 500 caracteres (`config/correcciones.php`). Autoridad jerárquica.
5. Render **fuera** de transacción. Si falla, no se tocó nada.
6. Guarda en NAS.
7. En transacción con `lockForUpdate`: crea la versión, escritura del módulo, historial en
   `correcciones` y, **al final**, cancela la anterior (si no está firmada).
8. Si falla, compensa: retira la fila nueva (por si la tabla es MyISAM y no hubo
   rollback) y su archivo. Nunca se cancela antes de tener la nueva lista.

Sin `campos` (`null`) se conservan las correcciones de la vigente cuya fuente no cambió.
Si la fuente cambió, la corrección no se reaplica: el editor la muestra como obsoleta.

## Matriz por módulo

| Documento | Editar antes de firmar | Regenerar | Después de firmar |
|---|---|---|---|
| Contratación, renovación, permisos y préstamos (maestros) | Datos variables; no empresa, identidad, sueldo ni sucursal | Con motivo | Rectificación ligada, con `documentos_laborales.revisar_firmado` |
| Plantillas heredadas | Sin campos | Con motivo | Rectificación ligada |
| Carta de renuncia | Igual que maestros; también desde la baja | Con motivo | Rectificación ligada |
| Convenio, aviso de rescisión, constancia | Lugar y fecha, texto, fundamento, adicional | Con motivo | Rectificación ligada; el firmado queda ligado a su versión y no se quita |
| Finiquito / liquidación | Conceptos y tipo en su panel, con motivo | Con motivo; firmar exige PDF al día | **Bloqueado: pendiente de Jurídico** |
| Recibo de nómina | Preparado, en su pantalla | Al emitir; reparar solo con los mismos montos | Rectificación con recibo nuevo (`ReciboNominaService`) |
| Acta | Borrador; generada sin firmar con motivo; cierre congelado | Con motivo | Solo seguimiento en el acta; rectificación del formato con permiso |
| Responsiva | Snapshot no se edita | Con motivo; nunca borra | Rectificación ligada; `pdf_path` sigue en lo firmado |
| Comprobantes, permiso | No | Generar el faltante por POST | Se conserva |
| Evaluación de inducción | Por recalificación | Primero la nueva, después cancela | Se conserva |
| Reportes y exportes | Definición y filtros | Cada ejecución | No aplica: no son documentos firmables |

## Superficie

Web `rh/documentos-edicion/{tipo}/{id}` y API `api/v1/rh/documentos-edicion/{tipo}/{id}`
(`campos`, `vista-previa`, `emitir`, `versiones`), mismo controlador y servicio; cada
fuente autoriza con la Policy y el alcance de su módulo. `tipo`: los de
`documentos-proceso`, `salida`, `finiquito`, `responsiva`, `recibo`.
UI: `EditorDatosDocumento.vue` y `HistorialVersiones.vue`.

Un GET nunca emite: finiquito sin PDF al día responde vista previa con marca de agua
(`X-Documento-Estado: vista-previa`); el permiso sin formato responde 409.

## Pendiente de validación por RH y Jurídico

1. Finiquito firmado o pagado con error: bloqueado, sin flujo.
2. Qué datos de cada contrato se corrigen por documento (hoy, lo conservador de la matriz).
3. Naturaleza de la rectificación de una responsiva o de un convenio firmados.
4. Evaluación de periodo de prueba: si se firma, debe archivarse; hoy es PDF al vuelo.
5. Variantes del mismo dato que no son solo mayúsculas (`domicilio_completo` y
   `domicilio`) pueden requerir corregirse por separado.
6. Versiones previas a esta entrega no tienen huella: la primera regeneración crea versión.
