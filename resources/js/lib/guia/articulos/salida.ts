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
        titulo: 'Baja de un colaborador (cierre laboral)',
        proceso: 'baja',
        resumen:
            'El jefe solicita, el superior preautoriza y RH autoriza. Al solicitarla se suspende el acceso; la baja se ejecuta en la fecha efectiva, ya pagado y firmado el finiquito.',
        queEs: 'Es el cierre de la relación laboral. Nunca borra a la persona: bloquea su acceso y conserva expediente, contratos, evaluaciones y documentos para un posible reingreso.',
        quien: ['gerente', 'regional', 'rh', 'sistemas'],
        quienDetalle:
            'Solicita el jefe o gerente (o RH). Preautoriza el superior según el organigrama (implícita si quien solicita ya es su superior). Autoriza RH. Programa el pago la coordinación regional. El gerente cita, recaba firma y huella. RH confirma el pago y concluye.',
        antes: ['La persona está activa y dentro de tu alcance.'],
        pasos: [
            {
                titulo: '«Solicitar baja»',
                detalle:
                    'Expedientes → la persona → Ciclo laboral → «Solicitar baja»: causa (renuncia, no renovación, bajo desempeño, baja inmediata…), motivo, fecha efectiva y evidencias (PDF o imagen).',
                quien: 'gerente',
            },
            {
                titulo: '«Preautorizar baja»',
                detalle: 'El superior operativo la preautoriza o la rechaza.',
                quien: 'regional',
            },
            {
                titulo: '«Autorizar baja»',
                detalle:
                    'RH autoriza, devuelve o rechaza. Al autorizar se preparan los documentos de la causa.',
                quien: 'rh',
            },
            {
                titulo: '«Registrar renuncia / aviso firmado»',
                detalle: 'Se sube la renuncia o el aviso firmado.',
                quien: 'rh',
            },
            {
                titulo: 'Finiquito',
                detalle:
                    '«Calcular finiquito» y «Autorizar finiquito» (ver el artículo de finiquito).',
                quien: 'rh',
            },
            {
                titulo: '«Programar pago»',
                detalle: 'Fecha, monto y método.',
                quien: 'regional',
            },
            {
                titulo: '«Registrar cita» y «Registrar firma y huella»',
                detalle:
                    'El gerente cita a la persona, recaba la firma y la huella del finiquito y sube el documento firmado.',
                quien: 'gerente',
            },
            {
                titulo: '«Confirmar pago»',
                detalle: 'RH confirma que se pagó.',
                quien: 'rh',
            },
            {
                titulo: '«Concluir cierre laboral»',
                detalle:
                    'Desde la fecha efectiva: la baja se ejecuta y el expediente queda cerrado. PEOPLE recuerda a RH los cierres pagados cuya fecha ya llegó.',
                quien: 'rh',
            },
        ],
        llamados: [
            {
                tono: 'importante',
                texto: 'Al SOLICITAR la baja se suspende de inmediato el acceso de la persona a la app y al portal. Si la baja se rechaza o cancela, el acceso vuelve solo. Su estatus laboral no cambia hasta la autorización de RH y la fecha efectiva.',
            },
        ],
        despues: [
            'Baja ejecutada → aviso a RH y a Sistemas: «Retira accesos, cuentas y equipo».',
            'La vacante de esa sucursal y puesto se abre sola.',
            'La persona queda disponible para un posible reingreso.',
        ],
        notificaciones: [
            '«Baja solicitada» a regionales y RH.',
            '«Preautorización» al superior; «Autorización» a RH; «Pago por programar» a la coordinación; «Cita de finiquito» al jefe.',
            'Baja ejecutada → RH y Sistemas.',
        ],
        documentos: [
            'Documentos de la causa (aviso de término, actas, según la causa)',
            'Finiquito',
        ],
        errores: [
            {
                problema: '«No me deja concluir.»',
                solucion:
                    'La baja exige finiquito firmado y pago confirmado, y no se ejecuta antes de la fecha efectiva.',
            },
            {
                problema: '«¿Cómo hago una baja?»',
                solucion:
                    'Desde el Ciclo laboral de la persona. Expedientes → la persona → Ciclo laboral → «Solicitar baja».',
            },
        ],
        registro: [
            'Cierre laboral con cada paso, aprobaciones, documentos y evidencias; expediente conservado.',
        ],
        relacionados: ['finiquito', 'reingreso', 'usuarios-y-roles'],
        accesos: [
            {
                etiqueta: 'Ir a Expedientes',
                ruta: '/rh/expedientes',
                permisos: ['expedientes.ver_todos', 'expedientes.ver_sucursal'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo hago una baja?',
            '¿Qué pasa con la cuenta de alguien que se va?',
            '¿Quién autoriza una baja?',
        ],
    },
    {
        id: 'finiquito',
        titulo: 'Finiquito',
        proceso: 'baja',
        resumen:
            'PEOPLE calcula sueldo pendiente, aguinaldo, vacaciones, prima vacacional e ISR; RH revisa, ajusta lo autorizado y genera el PDF para firma.',
        queEs: 'Es el cálculo del pago final de la persona que se va.',
        quien: ['rh'],
        antes: ['La baja está autorizada por RH.'],
        pasos: [
            {
                titulo: '«Calcular finiquito»',
                detalle:
                    'Desde el ciclo laboral, con el sueldo mensual y el sueldo pendiente.',
            },
            {
                titulo: 'Revisa los conceptos',
                detalle:
                    'Percepciones: sueldo pendiente, aguinaldo proporcional, vacaciones proporcionales, prima vacacional, indemnización y bonos extra (si aplican). Deducciones: ISR retenido, otras deducciones y adeudos.',
            },
            {
                titulo: 'Ajusta lo autorizado',
                detalle:
                    'En Solicitudes → la solicitud de baja → panel «Finiquito»: ajusta el importe de un concepto automático (con motivo), bonos, descuentos, adeudos o agrega conceptos.',
            },
            {
                titulo: 'Vista previa y PDF',
                detalle:
                    'Revisa la vista previa, márcalo como revisado y genera el PDF.',
            },
            {
                titulo: '«Autorizar finiquito»',
                detalle: 'Queda listo para programar el pago.',
            },
            {
                titulo: 'Firmado',
                detalle:
                    'Al subir el finiquito firmado (con huella) queda bloqueado: ya no admite ajustes.',
            },
        ],
        documentos: ['Finiquito en PDF (y el escaneo firmado)'],
        despues: ['La descarga entrega el escaneo firmado cuando existe.'],
        errores: [
            {
                problema: '«No puedo ajustar el finiquito.»',
                solucion:
                    'Ya está firmado o pagado: queda bloqueado para proteger el documento firmado.',
            },
            {
                problema: '«Un ajuste no se guarda.»',
                solucion: 'Escribe el motivo: todo ajuste queda auditado.',
            },
        ],
        registro: [
            'Cálculo, cada ajuste con motivo y autor, autorización, firma y pago.',
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
