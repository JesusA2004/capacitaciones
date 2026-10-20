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
            'El jefe directo (o RH) inicia la baja desde el colaborador y queda aplicada de inmediato: sin solicitud previa, sin autorización de RH y sin subir archivos. Al iniciarla se suspende el acceso; siguen los documentos de la baja y el finiquito.',
        queEs: 'Es el cierre de la relación laboral. Nunca borra a la persona: bloquea su acceso y conserva expediente, contratos, evaluaciones y documentos para un posible reingreso.',
        quien: ['gerente', 'regional', 'rh', 'sistemas'],
        quienDetalle:
            'Inicia el jefe directo de la persona (su cadena de mando real) o RH; nadie más puede iniciarla y no necesita autorización. Después se generan los documentos, RH calcula el finiquito y, al registrar su entrega, el cierre queda completo.',
        antes: [
            'La persona está activa y eres su jefe directo (o RH).',
            'La baja NO es una solicitud: no está en «Nueva solicitud».',
        ],
        pasos: [
            {
                titulo: '«Iniciar baja»',
                detalle:
                    'Expedientes → la persona de tu equipo → Ciclo laboral → «Iniciar baja»: tipo (renuncia, rescisión o fin de contrato), motivo (causal de rotación del tipo), observaciones opcionales y fecha efectiva. No se sube ningún archivo. Al confirmar, la baja queda aplicada (sin autorización de RH) y el sistema prepara los documentos del tipo: carta de renuncia, o evaluación + aviso de no renovación; la rescisión no genera documento.',
                quien: 'gerente',
            },
            {
                titulo: 'Renuncia / aviso en «Documentos de la baja»',
                detalle:
                    'El sistema genera la renuncia (o el aviso de terminación): «Imprimir» y, ya firmada en papel, «Marcar como firmado físicamente». No se sube ningún archivo. Si la persona se niega a firmar, «El colaborador se negó a firmar/recibir».',
                quien: 'rh',
            },
            {
                titulo: '«Calcular finiquito»',
                detalle:
                    'Muestra al momento el desglose por concepto; «Ver / editar finiquito» para ajustar montos con motivo (ver el artículo de finiquito). No hay que autorizarlo.',
                quien: 'rh',
            },
            {
                titulo: '«Registrar entrega de finiquito»',
                detalle:
                    'Desde la fecha efectiva: fecha de entrega, forma de pago y comentario. El finiquito se congela, la baja se ejecuta y el cierre laboral queda completo.',
                quien: 'rh',
            },
        ],
        llamados: [
            {
                tono: 'importante',
                texto: 'Al INICIAR la baja se suspende de inmediato el acceso de la persona a la app y al portal. Si RH cancela el cierre, el acceso vuelve solo. Su estatus laboral (inactivo, vacante) se ejecuta al concluir el cierre.',
            },
        ],
        despues: [
            'Baja ejecutada → aviso a RH y a Sistemas: «Retira accesos, cuentas y equipo».',
            'La vacante de esa sucursal y puesto se abre sola.',
            'La persona queda disponible para un posible reingreso.',
        ],
        notificaciones: [
            '«Baja aplicada» a regionales y RH.',
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
                    'El cierre se completa al registrar la entrega del finiquito, que solo se permite desde la fecha efectiva.',
            },
            {
                problema: '«¿Cómo hago una baja?»',
                solucion:
                    'Desde el Ciclo laboral de la persona. Expedientes → la persona → Ciclo laboral → «Iniciar baja». No se pide desde Solicitudes.',
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
            'PEOPLE calcula sueldo pendiente, aguinaldo, vacaciones, prima vacacional e ISR; RH revisa, ajusta montos y registra la entrega, que cierra la baja.',
        queEs: 'Es el cálculo del pago final de la persona que se va.',
        quien: ['rh'],
        antes: ['La baja ya está aplicada (se inició desde el ciclo laboral).'],
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
