import type { ArticuloGuia } from '../tipos';

/**
 * Procesos 12–13 — Baja/finiquito y Reingresos. Fuente real:
 *  - App\Services\CierreLaboral\CierreLaboralService (7 pasos, acciones y permisos)
 *  - App\Services\Solicitudes\BajaColaboradorService (bloqueo real + vacante)
 *  - App\Services\Colaboradores\AvisoAltaBajaService (RH + Sistemas)
 *  - App\Services\Finiquitos\FiniquitoService (conceptos 001–006, 101–103)
 *  - App\Services\CicloLaboral\ReingresoService (misma persona, nuevo ciclo)
 */
export const ARTICULOS_SALIDA: ArticuloGuia[] = [
    {
        id: 'baja',
        titulo: 'Baja de un colaborador (forma de salida y causal)',
        proceso: 'baja',
        resumen:
            'La baja NO es una solicitud: el gerente (de su personal) o RH la registra en «Bajas» y queda aplicada en ese momento. Antes de confirmar ves la renuncia o el formato que se generará y el finiquito propuesto. Después se completan los documentos, Jurídico revisa si la causal lo pide y RH la cierra.',
        queEs: 'Es el expediente de la baja con folio BAJ-AAAA-NNNN: forma de salida y causal de lista cerrada (sin «Otro»), documentos, bitácora de cambios y el cierre laboral (finiquito). Con esos datos se mide la rotación. Nunca borra a la persona: bloquea su acceso y conserva todo para un posible reingreso.',
        quien: ['gerente', 'regional', 'rh', 'sistemas'],
        quienDetalle:
            'Registra el gerente (solo su personal) o RH. RH corrige la causal con motivo, completa datos, cierra, reabre y marca «No procede». Jurídico revisa las causales que lo requieren y no cierra. Contraloría consulta las de faltantes (RE-02) y robo o fraude (RE-03). Nadie más entra a «Bajas»; Dirección solo ve el tablero de rotación y Sistemas recibe el aviso para retirar accesos y equipo.',
        antes: [
            'La persona está activa y es de tu sucursal (o eres RH).',
            'Solo puede haber una baja abierta por persona.',
        ],
        pasos: [
            {
                titulo: '«Registrar baja»',
                detalle:
                    'Cierres laborales → Bajas → «Registrar baja»: 1 ¿quién se va? (sucursal, puesto e ingreso salen solos), 2 ¿cómo se va? (tres tarjetas), 3 ¿por qué se va? (causal con su ayuda, comentario —obligatorio en RV-05 y en rescisión, mínimo 20 caracteres—, último día laborado y ¿recontratable?; en rescisión además fecha en que se conoció la falta y entrega del aviso).',
                quien: 'gerente',
            },
            {
                titulo: 'Vista previa y confirmar',
                detalle:
                    '«Vista previa» abre la renuncia (renuncia voluntaria) o el formato de la causal y el finiquito propuesto, con flechas para pasar de uno a otro. Nada se guarda hasta «Sí, registrar baja». Al confirmar: accesos suspendidos, documentos generados y finiquito propuesto. En rescisión no se genera documento: el aviso de rescisión se adjunta.',
                quien: 'gerente',
            },
            {
                titulo: 'Documentos y Jurídico',
                detalle:
                    'En el expediente de la baja o en Bajas y finiquitos → «Documentos»: imprime y firma lo generado, adjunta lo que falta. Si la causal lo requiere, la baja pasa sola a Jurídico, que la aprueba o la devuelve a RH con observaciones.',
                quien: 'rh',
            },
            {
                titulo: 'Finiquito y «Cerrar baja»',
                detalle:
                    'RH revisa / ajusta el finiquito y registra su entrega (Bajas y finiquitos). Con los documentos obligatorios completos, «Cerrar baja»: la persona queda inactiva y su acceso bloqueado de forma definitiva. Una baja cerrada solo se reabre con motivo; «No procede» la revierte y devuelve el acceso.',
                quien: 'rh',
            },
        ],
        llamados: [
            {
                tono: 'importante',
                texto: 'Al REGISTRAR la baja se suspende de inmediato el acceso de la persona a la app y al portal. Si RH decide que no procede, la revierte y el acceso vuelve.',
            },
            {
                tono: 'importante',
                texto: 'En rescisión, «¿Recontratable?» es «No» y solo RH lo cambia. El aviso de rescisión se adjunta (el sistema no lo genera).',
            },
        ],
        despues: [
            'Baja cerrada → cuenta en la rotación de su mes (salvo que RH la marque como movimiento interno).',
            'La vacante de esa sucursal y puesto se abre sola.',
            'Si alguien con baja «No recontratable» vuelve como candidato, Reclutamiento ve una alerta.',
        ],
        notificaciones: [
            '«Baja registrada» a RH, a la regional y a Sistemas: quién la dio, a quién, forma · causal y último día.',
            'Causal con Jurídico: «Baja por revisar (Jurídico)».',
            'RV-04 (clima laboral): correo a RH para entrevista de salida y NOM-035.',
            'RE-02 y RE-03: aviso a Contraloría (RE-03 también a Jurídico).',
            'Antigüedad menor a 90 días: rotación temprana a RH.',
        ],
        documentos: [
            'Renuncia: carta de renuncia firmada, entrevista de salida, finiquito firmado',
            'No renovación: convenio o aviso de término, finiquito o liquidación firmado',
            'Rescisión: acta administrativa con testigos, aviso de rescisión entregado, finiquito firmado',
            'Adicionales según la causal (evidencia de desempeño, autorización de Dirección General, arqueo, evidencia de la falta)',
        ],
        errores: [
            {
                problema: '«No me deja registrar.»',
                solucion:
                    'Faltan persona, forma, causal, último día laborado o ¿recontratable? (y en rescisión los datos del aviso). La pantalla marca cada campo.',
            },
            {
                problema: '«RH no puede cerrar.»',
                solucion:
                    'Falta un documento obligatorio, la revisión de Jurídico o el finiquito revisado. El expediente dice exactamente qué falta.',
            },
        ],
        registro: [
            'Bitácora de la baja: quién, cuándo, valor anterior y nuevo de cada cambio (causal, recontratable, fechas, documentos, estatus).',
        ],
        relacionados: ['finiquito', 'reingreso', 'usuarios-y-roles'],
        accesos: [
            {
                etiqueta: 'Ir a Bajas',
                ruta: '/rh/bajas',
                permisos: [
                    'bajas.ver',
                    'bajas.revisar_juridico',
                    'bajas.consulta_contraloria',
                ],
                modo: 'operativo',
            },
            {
                etiqueta: 'Documentos de las bajas',
                ruta: '/rh/cierres',
                permisos: ['cierres.ver'],
                modo: 'operativo',
            },
            {
                etiqueta: 'Tablero de rotación',
                ruta: '/reportes/rotacion/tablero',
                permisos: ['bajas.tablero.ver'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo hago una baja?',
            '¿Qué pasa con la cuenta de alguien que se va?',
            '¿La baja necesita autorización?',
            '¿Qué causal elijo?',
        ],
    },
    {
        id: 'finiquito',
        titulo: 'Finiquito',
        proceso: 'baja',
        resumen:
            'PEOPLE calcula sueldo pendiente, aguinaldo, vacaciones, prima vacacional e ISR; RH revisa, ajusta montos y registra la entrega, que cierra la baja.',
        queEs: 'Es el cálculo del pago final de la persona que se va.',
        quien: ['rh'],
        antes: [
            'La baja ya está registrada (Bajas o ciclo laboral): el finiquito propuesto se calcula al registrarla.',
        ],
        pasos: [
            {
                titulo: '«Calcular finiquito»',
                detalle:
                    'Desde el ciclo laboral. El sueldo se toma del salario diario registrado (× 30) y puedes cambiarlo. Al terminar se abre el desglose.',
            },
            {
                titulo: 'Revisa los conceptos',
                detalle:
                    'Percepciones: sueldo pendiente, aguinaldo proporcional, vacaciones proporcionales, prima vacacional, indemnización y bonos extra (si aplican). Deducciones: ISR retenido, otras deducciones y adeudos.',
            },
            {
                titulo: 'Ajusta montos',
                detalle:
                    '«Ver / editar finiquito» (en el estado del cierre o en la tarjeta «Finiquito» de Documentos de la baja): el lápiz de cada concepto cambia su importe con motivo; «Agregar renglón» suma una percepción o deducción. El total lo recalcula PEOPLE y queda quién y cuándo editó.',
            },
            {
                titulo: 'Vista previa PDF',
                detalle:
                    'Revisa el formato del finiquito con los montos actuales.',
            },
            {
                titulo: '«Registrar entrega de finiquito»',
                detalle:
                    'Fecha, forma de pago y comentario. El finiquito queda congelado (solo lectura) y el cierre laboral completo.',
            },
        ],
        documentos: ['Finiquito en PDF'],
        despues: [
            'Después de la entrega el finiquito se puede ver e imprimir, pero ya no editar.',
        ],
        errores: [
            {
                problema: '«No puedo ajustar el finiquito.»',
                solucion:
                    'Ya se registró su entrega: queda congelado tal como se entregó.',
            },
            {
                problema: '«No me deja registrar la entrega.»',
                solucion:
                    'La baja todavía no llega a su fecha efectiva, o la fecha de entrega es futura.',
            },
            {
                problema: '«Un ajuste no se guarda.»',
                solucion: 'Escribe el motivo: todo ajuste queda auditado.',
            },
        ],
        registro: [
            'Cálculo, cada ajuste con motivo y autor, y la entrega (fecha, forma de pago, quién).',
        ],
        relacionados: ['baja'],
        accesos: [
            {
                etiqueta: 'Ir a Solicitudes',
                ruta: '/rh/solicitudes',
                permisos: ['solicitudes.revisar', 'solicitudes.aprobar'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo calculo un finiquito?',
            '¿Qué conceptos lleva el finiquito?',
            '¿Cómo ajusto el finiquito?',
        ],
    },
    {
        id: 'reingreso',
        titulo: 'Reingreso de un excolaborador',
        proceso: 'reingresos',
        resumen:
            'Nunca se crea otra persona: se busca a la anterior, RH decide y se reactiva con su mismo historial en un ciclo laboral nuevo.',
        queEs: 'Es volver a contratar a alguien que ya trabajó en MR. LANA. Se conserva su cuenta, su expediente y toda su vida laboral anterior.',
        quien: ['gerente', 'rh'],
        antes: [
            'La persona tiene la baja concluida (no puede tener un cierre sin terminar ni estar activa).',
        ],
        pasos: [
            {
                titulo: 'Busca a la persona',
                detalle:
                    'Desarrollo → Reingresos: por número de empleado, CURP, RFC, NSS o nombre. Ves su causa de salida antes de solicitar.',
                canal: 'web_app',
            },
            {
                titulo: 'Solicita el reingreso',
                detalle: 'Quien solicita propone (gerente o RH).',
                quien: 'gerente',
            },
            {
                titulo: 'RH decide',
                detalle:
                    '«Decidir reingreso»: viable o no viable (con motivo).',
                quien: 'rh',
            },
            {
                titulo: 'Nuevo ciclo',
                detalle:
                    'Si es viable: se reactiva la MISMA persona, se piden solo documentos vencidos o faltantes, nuevo contrato, firmas y onboarding aplicable, hasta quedar activa.',
            },
        ],
        despues: [
            'La vida laboral anterior (cierres, contratos, evaluaciones, documentos) se conserva intacta.',
        ],
        errores: [
            {
                problema: '«Una persona activa no puede reingresar.»',
                solucion: 'Revisa que la baja esté concluida.',
            },
            {
                problema: '«Ya existe una cuenta con ese correo.»',
                solucion:
                    'Es un reingreso: búscala en Reingresos en lugar de registrarla como candidato nuevo.',
            },
        ],
        registro: [
            'Movimiento «Reingreso» en su actividad laboral y la decisión de RH.',
        ],
        relacionados: ['baja', 'candidato-a-colaborador'],
        accesos: [
            {
                etiqueta: 'Ir a Reingresos',
                ruta: '/rh/reingresos',
                permisos: ['reingresos.solicitar', 'reingresos.gestionar'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Qué pasa con alguien que regresa?',
            '¿Cómo hago un reingreso?',
            '¿Se crea otra persona si regresa?',
        ],
    },
];
