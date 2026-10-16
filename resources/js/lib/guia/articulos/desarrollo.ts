import type { ArticuloGuia } from '../tipos';

/**
 * Procesos 6–7 — Onboarding y Evaluación inicial. Fuente real:
 *  - App\Services\Onboarding\OnboardingService (iniciar, presentarEvaluacion, retroalimentar, entregarActivo, completar)
 *  - resources/js/pages/Rh/Onboarding/Configuracion.vue (campos reales de una lección)
 *  - mr-lana-people-app: (app)/lecciones/*, src/utils/miProceso.ts (estados que ve el colaborador)
 *  - App\Console\Commands (contratos:revisar-vencimientos, 15 días antes)
 *  - App\Services\Contratos\EvaluacionPeriodoPruebaService + ContratoLaboralService::renovar()
 */
export const ARTICULOS_DESARROLLO: ArticuloGuia[] = [
    {
        id: 'onboarding',
        titulo: 'Onboarding: la bienvenida del colaborador',
        proceso: 'onboarding',
        resumen:
            'Lecciones de bienvenida con evaluación (mínimo 8) y entrega del equipo de trabajo. Al cerrarlo, la persona queda activa.',
        queEs: 'Onboarding es la bienvenida estructurada de una persona nueva: conoce la empresa (inducción institucional), aprende su puesto (inducción al puesto) y recibe su equipo (uniforme, credencial, etc.) con su carta responsiva. El colaborador nunca ve la palabra «onboarding»: para él son «lecciones de bienvenida».',
        quien: ['colaborador', 'rh', 'gerente'],
        quienDetalle:
            'El colaborador responde las lecciones; RH da retroalimentación si no aprueba; el gerente (o RH) registra la entrega de equipo; RH cierra el onboarding.',
        antes: [
            'Los contratos de contratación están firmados (el onboarding empieza solo).',
            'RH configuró las lecciones y el equipo en Onboarding.',
        ],
        pasos: [
            {
                titulo: 'Lecciones de bienvenida',
                detalle:
                    'App → «Lecciones de bienvenida» (o Mi portal → «Lo que necesitas hacer»). Cada lección tiene su material (video o documento por liga), texto e instrucciones y una evaluación.',
                quien: 'colaborador',
                canal: 'web_app',
            },
            {
                titulo: 'Avance que ve el colaborador',
                detalle:
                    '«Se habilita después» (todavía bloqueada) → «Lista para responder» → «Aprobada». Si no alcanza la calificación: «Recursos Humanos te dejará un comentario».',
                quien: 'colaborador',
            },
            {
                titulo: 'Si no aprueba (menos de 8)',
                detalle:
                    'RH escribe la retroalimentación y habilita la reevaluación. La persona vuelve a intentarlo.',
                quien: 'rh',
            },
            {
                titulo: 'Entrega de equipo',
                detalle:
                    'En el Ciclo laboral de la persona, «Registrar entrega de activos»: qué se entregó; se genera la carta responsiva.',
                quien: 'gerente',
            },
            {
                titulo: '«Cerrar onboarding»',
                detalle:
                    'Con lecciones aprobadas y equipo entregado, RH lo cierra. En ese momento la persona queda ACTIVA.',
                quien: 'rh',
            },
        ],
        llamados: [
            {
                tono: 'info',
                texto: 'Ejemplo de ruta: 01 Bienvenida a MR. LANA (video) · 02 Políticas de crédito (documento) · 03 Evaluación (cuestionario). Las lecciones de puesto solo aparecen a quien ocupa ese puesto.',
            },
        ],
        despues: [
            'Alta real: avisos a RH y Sistemas y bienvenida al colaborador.',
            'Empieza a correr el periodo de capacitación inicial hasta su evaluación.',
        ],
        notificaciones: [
            '«Onboarding: refuerzo» a RH cuando alguien no aprueba una lección.',
        ],
        documentos: ['Carta responsiva por cada entrega de equipo.'],
        errores: [
            {
                problema: '«No me deja cerrar el onboarding.»',
                solucion:
                    'PEOPLE dice qué falta: alguna lección sin aprobar o equipo sin entregar.',
            },
        ],
        registro: [
            'Cada intento con calificación, la retroalimentación de RH y cada entrega de equipo.',
        ],
        relacionados: [
            'configurar-onboarding',
            'alta-activa',
            'evaluacion-capacitacion',
        ],
        accesos: [
            {
                etiqueta: 'Ir a Onboarding',
                ruta: '/rh/onboarding/configuracion',
                permisos: ['onboarding.gestionar'],
                modo: 'operativo',
            },
            {
                etiqueta: 'Ir a Mi portal',
                ruta: '/mi-portal',
                modo: 'colaborador',
            },
        ],
        preguntas: [
            '¿Qué es el onboarding?',
            '¿Cómo respondo mis lecciones?',
            '¿Qué pasa si repruebo una lección?',
        ],
    },
    {
        id: 'configurar-onboarding',
        titulo: 'Configurar las lecciones y el equipo',
        proceso: 'onboarding',
        resumen:
            'RH define las lecciones (institucionales y por puesto) y el catálogo de equipo que se entrega.',
        queEs: 'Es el catálogo que usa PEOPLE para armar el onboarding de cada persona.',
        quien: ['rh'],
        antes: ['Tener el permiso de gestionar onboarding.'],
        pasos: [
            {
                titulo: 'Abre Onboarding',
                detalle: 'Desarrollo → Onboarding.',
                canal: 'web',
            },
            {
                titulo: 'Crea una lección',
                detalle:
                    'Título, tipo (institucional o del puesto), puesto (si es del puesto), orden, calificación mínima, video o material por liga (URL), contenido o instrucciones y su evaluación (preguntas con opciones).',
            },
            {
                titulo: 'Equipo de trabajo',
                detalle:
                    'Da de alta cada tipo de equipo (nombre y clave): uniforme, credencial, etc.',
            },
        ],
        campos: [
            {
                grupo: 'Lección',
                campos: [
                    'Título',
                    'Tipo',
                    'Puesto (si aplica)',
                    'Orden',
                    'Calificación mínima',
                    'Video / material (URL)',
                    'Contenido / instrucciones',
                    'Evaluación (preguntas y opciones)',
                ],
            },
            { grupo: 'Equipo', campos: ['Nombre', 'Clave'] },
        ],
        despues: [
            'Las personas que entren a onboarding después del cambio ya lo ven.',
        ],
        errores: [
            {
                problema: '«No guarda la lección.»',
                solucion:
                    'Una lección necesita al menos una pregunta; una lección de puesto necesita su puesto.',
            },
        ],
        registro: ['Cambios del catálogo en la bitácora.'],
        relacionados: ['onboarding'],
        accesos: [
            {
                etiqueta: 'Ir a Onboarding',
                ruta: '/rh/onboarding/configuracion',
                permisos: ['onboarding.gestionar'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo creo una lección de onboarding?',
            '¿Puedo subir un video al onboarding?',
        ],
    },
    {
        id: 'evaluacion-capacitacion',
        titulo: 'Evaluación de capacitación inicial',
        proceso: 'evaluacion',
        resumen:
            '15 días antes de que venza la capacitación, PEOPLE abre la evaluación al jefe; él califica y recomienda; RH autoriza.',
        queEs: 'Es la evaluación con la que se decide si la persona sigue (contrato por tiempo indeterminado) o no. Usa los 7 criterios del formato oficial de Jurídico.',
        quien: ['gerente', 'rh'],
        quienDetalle:
            'Evalúa el jefe inmediato (normalmente el gerente de la sucursal); autoriza RH.',
        antes: [
            'La persona tiene un contrato de capacitación inicial vigente.',
        ],
        pasos: [
            {
                titulo: 'PEOPLE la abre sola',
                detalle:
                    'Cada mañana revisa los vencimientos. 15 días antes del fin (por ejemplo, un Gestor con 2 meses se avisa al mes y medio) crea la evaluación y avisa.',
                canal: 'automatico',
            },
            {
                titulo: 'El jefe evalúa',
                detalle:
                    'Desarrollo → Evaluación de capacitación inicial → la persona → «Evaluar periodo de prueba». Califica cada criterio de 0 a 10 con comentario, elige «Recomiendo renovar» o «No renovar» y escribe observaciones.',
                quien: 'gerente',
                canal: 'web_app',
            },
            {
                titulo: 'RH autoriza',
                detalle:
                    '«Autorización final de RH»: confirma renovar o no renovar. Si decide distinto a lo que recomendó el jefe, el comentario es obligatorio. También puede devolverla para corrección.',
                quien: 'rh',
            },
        ],
        campos: [
            {
                grupo: 'Criterios (0 a 10)',
                campos: [
                    'Gestión operativa de la sucursal',
                    'Cumplimiento de KPI’s',
                    'Liderazgo y supervisión',
                    'Reportes y controles administrativos',
                    'Toma de decisiones',
                    'Apego a políticas internas',
                    'Responsabilidad y seguimiento',
                ],
            },
            {
                grupo: 'Decisión',
                campos: ['Recomiendo renovar / No renovar', 'Observaciones'],
            },
        ],
        despues: [
            'Renovar → PEOPLE prepara el contrato por tiempo indeterminado.',
            'No renovar → se abre el cierre laboral por «No renovación del periodo de prueba», ya autorizado, con fecha efectiva al fin del contrato.',
        ],
        notificaciones: [
            '«Evaluación pendiente» al jefe evaluador.',
            '«Contrato por vencer» a la gerencia de la sucursal, al regional, a la Gerencia de RH y a RH con alcance.',
            '«Evaluación capturada» a RH; «Evaluación devuelta» al jefe.',
        ],
        errores: [
            {
                problema: '«No veo la evaluación todavía.»',
                solucion:
                    'Se habilita 15 días antes del vencimiento. El ciclo laboral muestra cuántos días faltan.',
            },
            {
                problema: '«RH no puede autorizar.»',
                solucion:
                    'Solo se autoriza una evaluación capturada por el jefe.',
            },
        ],
        registro: [
            'Evaluación con criterios, calificaciones, recomendación, autorización y comentarios; queda en el expediente.',
        ],
        relacionados: ['contrato-indeterminado', 'baja'],
        accesos: [
            {
                etiqueta: 'Ir a Evaluaciones',
                ruta: '/rh/evaluaciones',
                permisos: ['evaluaciones.capturar', 'evaluaciones.autorizar'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo evalúo la capacitación inicial?',
            '¿Cuándo se abre la evaluación?',
            '¿Qué pasa si no se renueva?',
        ],
    },
    {
        id: 'contrato-indeterminado',
        titulo: 'Contrato por tiempo indeterminado',
        proceso: 'evaluacion',
        resumen:
            'Con la evaluación autorizada para renovar, PEOPLE prepara el contrato indeterminado. Pulsar dos veces no lo duplica.',
        queEs: 'Es el contrato que sigue a una capacitación inicial aprobada. Empieza el día siguiente al fin del contrato anterior.',
        quien: ['rh', 'gerente'],
        antes: ['Evaluación autorizada por RH con decisión «renovar».'],
        pasos: [
            {
                titulo: 'PEOPLE lo prepara',
                detalle:
                    'El contrato de capacitación queda «Renovado» y se crea el nuevo, por tiempo indeterminado, con sus documentos.',
                canal: 'automatico',
            },
            {
                titulo: 'Previsualiza e imprime',
                detalle:
                    'En el Ciclo laboral de la persona, los documentos del contrato: vista previa, imprimir y la ruta de firma del original.',
                quien: 'gerente',
            },
        ],
        llamados: [
            {
                tono: 'info',
                texto: 'Doble clic seguro: la autorización solo procede sobre una evaluación «capturada» y la renovación solo sobre un contrato «vigente». Un segundo clic recibe un aviso y no crea otro contrato.',
            },
        ],
        documentos: ['Contrato por tiempo indeterminado.'],
        despues: ['La persona queda «Activo · periodo de prueba superado».'],
        errores: [
            {
                problema: '«Solo un contrato vigente puede renovarse.»',
                solucion:
                    'Ya se renovó: revisa los documentos del ciclo laboral.',
            },
        ],
        registro: [
            'Contrato anterior marcado como renovado y contrato nuevo, ambos en el expediente.',
        ],
        relacionados: ['evaluacion-capacitacion', 'firma-y-original'],
        accesos: [
            {
                etiqueta: 'Ir a Evaluaciones',
                ruta: '/rh/evaluaciones',
                permisos: ['evaluaciones.capturar', 'evaluaciones.autorizar'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo se genera el contrato indeterminado?',
            '¿Se duplica el contrato si pulso dos veces?',
        ],
    },
    {
        id: 'induccion-mr-lana',
        titulo: 'Inducción Mr. Lana y su evaluación',
        proceso: 'onboarding',
        resumen:
            'RH presenta la inducción a las personas que eligió, al terminar «Envía la evaluación», el colaborador la contesta en la app y RH califica: con 24/30 o más queda listo para empezar a laborar.',
        queEs: 'Es la primera de las dos evaluaciones de ingreso (la segunda es la evaluación de periodo de prueba). La inducción institucional es el documento oficial (Quiénes somos, Misión/visión/principios, Cultura, Organigrama, Reglamento interior, Modelo de negocio y Empresa con factor humano) y su evaluación es el formato oficial FO-RH-EI de 30 puntos.',
        quien: ['rh', 'colaborador'],
        quienDetalle:
            'RH elige a quién le presenta la inducción, la presenta (en sala o por Meet), envía la evaluación y califica los casos prácticos. El colaborador contesta su evaluación en la app (o en el portal web).',
        antes: [
            'La persona está en su proceso de ingreso (contratos firmados). Si RH se la presenta antes, la asistencia queda registrada y su evaluación se habilita sola en cuanto inicie su onboarding.',
        ],
        pasos: [
            {
                titulo: 'Elegir a quién se le presenta',
                detalle:
                    'Desarrollo → «Presentar inducción». ANTES de iniciar, busca y agrega a uno o varios colaboradores (solo los de tu alcance; «Agregar a los pendientes de presentar» los suma de una vez) y pulsa «Iniciar presentación».',
                quien: 'rh',
                canal: 'web',
            },
            {
                titulo: 'Presentar y «Enviar evaluación»',
                detalle:
                    'Presenta el PDF oficial. Al terminar, pulsa «Enviar evaluación»: en ese momento se habilita la evaluación de cada asistente y le llega el aviso en la app «Contesta la evaluación de la Inducción Mr. Lana». Antes de ese botón no se envía nada; repetirlo no duplica nada.',
                quien: 'rh',
                canal: 'web',
            },
            {
                titulo: 'Contestar la evaluación',
                detalle:
                    'El aviso abre directo la evaluación en la app. Secciones A (opción múltiple), B (verdadero/falso), C (relaciona columnas), D (casos prácticos) y E (opinión, no califica). Se envía una sola vez; la clave de respuestas nunca llega al teléfono ni al navegador.',
                quien: 'colaborador',
                canal: 'web_app',
            },
            {
                titulo: 'RH revisa y califica',
                detalle:
                    'Le llega el aviso «Evaluación de inducción por calificar». En la revisión ve cada pregunta con la respuesta del colaborador y si acertó (A/B/C se califican solas), marca la rúbrica oficial de los casos prácticos (D), puede dejar un comentario y ve el total /30. Desde ahí también ve la vista previa del formato FO-RH-EI, lo descarga o lo guarda en el expediente.',
                quien: 'rh',
                canal: 'web',
            },
            {
                titulo: 'Si requiere reforzamiento',
                detalle:
                    'Menos de 24/30: RH deja retroalimentación y habilita la reevaluación (plazo de 5 días hábiles). El colaborador no puede reintentar antes.',
                quien: 'rh',
            },
            {
                titulo: 'Seguimiento',
                detalle:
                    'En «Presentar inducción» RH ve a cada persona en ingreso: Pendiente de presentar → Presentada · por contestar → Por calificar (D) → Aprobada / Reforzamiento, y si ya está Laborando.',
                quien: 'rh',
                canal: 'web',
            },
            {
                titulo: 'Qué ve el colaborador',
                detalle:
                    'El colaborador nunca presenta: en Mi espacio → «Inducción Mr. Lana» solo ve el PDF mientras la sesión de RH en la que lo incluyeron está en curso. Antes de la sesión ve que RH se la presentará; después, su evaluación por contestar y, al terminarla, que ya la completó (sin el contenido).',
                quien: 'colaborador',
                canal: 'web_app',
            },
        ],
        despues: [
            'Aprobada (24/30 o más): se aprueba el módulo de inducción y el formato FO-RH-EI se guarda solo en el expediente (carpeta Otros). Si ya no quedan módulos de puesto ni activos por entregar, el onboarding se cierra y la persona queda activa: en el tablero de candidatos pasa de «Capacitación inicial» a «Laborando». Si quedan pendientes, sigue el onboarding normal.',
            'La segunda evaluación es la de periodo de prueba, que se abre 15 días hábiles antes de que venza su contrato.',
        ],
        notificaciones: [
            'Al colaborador: «Contesta la evaluación de la Inducción Mr. Lana» (al Enviar evaluación), aprobada, reforzamiento y reevaluación habilitada.',
            'A RH: evaluación por calificar y reforzamiento requerido.',
        ],
        errores: [
            {
                problema: '«No me aparece la evaluación.»',
                solucion:
                    'RH todavía no pulsa «Enviar evaluación» de la sesión donde te presentó la inducción, o aún no inicia tu proceso de ingreso.',
            },
            {
                problema: '«No encuentro a la persona en el buscador.»',
                solucion:
                    'Solo aparecen colaboradores no dados de baja dentro de tu alcance.',
            },
        ],
        registro: [
            'Cada sesión: quién presentó, cuándo y a quién, y qué pasó con cada asistente.',
            'Cada intento con sus respuestas, calificación, nivel, comentario y quién calificó; queda en el historial de la persona.',
        ],
        relacionados: ['onboarding', 'configurar-onboarding'],
        accesos: [
            {
                etiqueta: 'Presentar inducción',
                ruta: '/induccion/presentacion',
                permisos: ['onboarding.gestionar'],
                modo: 'operativo',
            },
            {
                etiqueta: 'Ir a Mis pendientes',
                ruta: '/mi-proceso',
                modo: 'colaborador',
            },
        ],
        preguntas: [
            '¿Cómo hago mi inducción?',
            '¿Con cuántos puntos apruebo la evaluación de inducción?',
            '¿Cómo presento la inducción por Meet?',
            '¿Cómo envío la evaluación de inducción a los colaboradores?',
        ],
    },
];
