# Siguiente paso

Panel que dice dónde está un proceso, qué sigue y qué falta. `app/Services/Flujos`; sin
etapas nuevas ni saltos de aprobaciones: los resolvers leen a los servicios dueños de
cada regla (`CicloLaboralService`, `CadenaContratacionService`, `FaseContratadoService`,
`BajaService`, `BajaDocumentoSalidaService`, `CierreLaboralService`, `SalarioDominio`).

## Contrato

`SiguientePaso`: `flujo`, `modo` (`lineal` o `hub`), `estado`, `tipo` (`accion`, `espera`,
`terminado`), `accion`, `mensaje`, `responsable`, `progreso`, `pasos`, `bloqueos`,
`relacionadas` (máximo dos). Web: prop `siguientePaso` por página, nunca compartida.
API: `siguiente_paso` en candidato, ciclo y baja; `ciclo.siguiente_accion` se conserva.
`accion.solo_web` indica que la app debe decir «se completa en la web».

`ConstructorEnlaces` es el único que arma hrefs y aplica el mismo permiso que el destino:
si no pasa, devuelve null y no hay enlace. `tests/Feature/Flujos` pide cada href.

## Mapa

| Flujo | Condición real | Botón |
|---|---|---|
| A | Entrevista pendiente sin cita / con cita | Agendar entrevista / Registrar resultado |
| A | Psicométricas, socioeconómico, referencias | Continuar evaluación |
| A | Autorización de RH / autorizado | Autorizar contratación / Iniciar contratación |
| A | Alta: documentos / revisión / contrato / firma | Completar expediente / Revisar documentos / Generar contrato / Registrar firma de contratos |
| A | Fase: inducción / evaluación de inducción | Iniciar inducción / Calificar inducción |
| A | Pendiente de activación | Activar colaborador |
| B | Expediente: documentos / solicitud / rectificación | Revisar documentos / Atender solicitud / Ver impacto en nómina |
| B | Expediente sin pendientes | Terminado; enlaces Registrar incidencia y Ver impacto en nómina |
| B | Nómina de una persona: borrador / emitido | Revisar conceptos / Descargar PDF |
| B | Directorio, por fila | Abrir expediente |
| C | Sigue laborando | Enlace Iniciar baja (nunca botón principal) |
| C | Datos incompletos | Completar datos de la baja |
| C | Documento: falta / cambió / sin firma | Generar documento de salida / Corregir documento / Registrar documento firmado |
| C | Finiquito: falta / PDF viejo / borrador / revisado | Calcular / Recalcular / Revisar / Registrar entrega de finiquito |
| C | Todo listo; baja cerrada | Cerrar baja; Concluir cierre laboral |
| C | Siempre que tenga permiso | Enlace Ver en rotación (no es un paso: cuenta desde el registro) |
| D | Sin sueldo / rectificación / contrato o finiquito con otro sueldo | Capturar sueldo / Revisar nómina / Ver documentos afectados |
| D | Sin efectos | Ver historial salarial; enlaces Corregir sueldo y Exportar reporte |

D no existe para quien no puede ver el sueldo. El tabulador es la referencia del puesto y
el sueldo individual es el del expediente: ninguno actualiza al otro.

## Contexto en la URL

`?accion=` abre un diálogo solo si la acción está permitida. `?candidato=` precarga la
agenda solo si es agendable y visible. `?colaborador=` acota listas dentro del alcance.
`?desde=expediente&desde_id=12` da «Volver a…»: clave en lista blanca (`expediente`,
`ciclo`, `candidato`, `baja`, `solicitud`) resuelta en el backend, sin URL recibida.

## Pendiente de decisión

1. **Dos cierres.** «Cerrar baja» y «Concluir cierre laboral» conviven y se enlazan; no se fusionan.
2. **Entrega sin PDF.** `registrarEntrega` rechaza un PDF viejo; sin PDF generado sigue permitida.
3. **Orden del flujo C** por tipo de baja: se deriva de lo existente; falta validarlo con RH.
4. **Activación web.** `pendiente_activacion` usa `AltaColaboradorService::activar`, como la API.
