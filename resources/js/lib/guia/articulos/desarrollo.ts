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
        titulo: 'Evaluaciones de periodo de prueba',
        proceso: 'evaluacion',
        resumen:
            'Un solo módulo con tres pestañas: Próximas / por asignar, En proceso y Evaluadas. El jefe directo contesta el FO-RH-EPP-001; RH decide e imprime el PDF para firma.',
        queEs: 'Es la evaluación de término de periodo de prueba (FO-RH-EPP-001) con la que se decide si la persona sigue (contrato por tiempo indeterminado) o no. La contesta su jefe directo del organigrama, nunca el colaborador.',
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
                    'Cada mañana revisa los vencimientos. 15 días hábiles antes del fin crea la evaluación y avisa al jefe directo, siempre que la persona ya haya aprobado su inducción (esté en capacitación inicial). Desde ese mismo momento RH también puede abrirla en la pestaña «Próximas / por asignar» con «Asignar evaluación»; quien aún no cumple aparece ahí con el motivo («Aún en inducción», «Se asigna a partir del …») y sin botón.',
                canal: 'automatico',
            },
            {
                titulo: 'El jefe evalúa',
                detalle:
                    'Evaluaciones de periodo de prueba → pestaña «En proceso» → «Contestar evaluación». Califica los 9 criterios del FO-RH-EPP-001 (4 Muy bueno a 1 Deficiente), responde las 5 preguntas filtro y escribe tus comentarios; PEOPLE calcula si se renueva.',
                quien: 'gerente',
                canal: 'web_app',
            },
            {
                titulo: 'RH decide',
                detalle:
                    'Pestaña «En proceso» → chip «Por decidir RH» → «Revisar y decidir»: confirma renovar o no renovar. Si decide distinto a lo que recomendó el jefe, el comentario es obligatorio. También puede devolverla para corrección.',
                quien: 'rh',
            },
            {
                titulo: 'Ver respuestas e imprimir para firma',
                detalle:
                    'Pestaña «Evaluadas» → «Ver respuestas» (calificación de cada criterio, preguntas filtro, total y resultado) → «Imprimir PDF para firma»: primero se ve la vista previa; firman el jefe inmediato, la Gerencia de RH y el colaborador.',
                quien: 'rh',
            },
        ],
        campos: [
            {
                grupo: 'Paso 1 · Desempeño (4 Muy bueno · 3 Bueno · 2 Regular · 1 Deficiente)',
                campos: [
                    'Conoce y hace bien su trabajo',
                    'Cumple sus metas',
                    'Trabaja sin errores',
                    'Asistencia y puntualidad',
                    'Cumplimiento a políticas y procesos operativos',
                    'Uniforme, imagen y reglas internas',
                    'Trato al cliente',
                    'Trabajo en equipo y actitud',
                    'Aprende e iniciativa',
                ],
            },
            {
                grupo: 'Paso 2 · Preguntas filtro (Sí / No)',
                campos: ['Un solo «Sí» = no se renueva'],
            },
            {
                grupo: 'Paso 3 · Resultado',
                campos: [
                    'Se renueva solo sin ningún 1, sin ningún «Sí» y con el total mínimo',
                    'Comentarios del jefe inmediato',
                ],
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
                    'Se habilita sola 15 días hábiles antes del vencimiento, o antes si RH la asigna desde «Próximas / por asignar». Si dice «Aún no se puede asignar», genera primero su contrato de capacitación inicial.',
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
            '¿Cómo evalúo el periodo de prueba?',
            '¿Cómo asigno una evaluación antes de tiempo?',
            '¿Cómo imprimo la evaluación para firma?',
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
                    'En «Presentar inducción» RH ve a cada persona en ingreso: Pendiente de presentar → Presentada · por contestar → Por calificar (D) → Aprobada / Reforzamiento, y si ya tiene Contrato indeterminado.',
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
            'Aprobada (24/30 o más): se aprueba el módulo de inducción y el formato FO-RH-EI se guarda solo en el expediente (carpeta Otros). Si ya no quedan módulos de puesto ni activos por entregar, el onboarding se cierra y la persona queda activa: en el tablero de candidatos pasa de «Capacitación inicial» a «Contrato indeterminado». Si quedan pendientes, sigue el onboarding normal.',
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

    // ─────────────── Proceso 12 — Capacitación y Certificación (etapa 6) ───────────────
    // Fuente real: routes/capacitacion.php, cursos.php, asignaciones.php,
    // cuestionarios.php, actividades.php, multimedia.php, reuniones.php,
    // mi-capacitacion.php; pages/Capacitacion, Cursos, Asignaciones,
    // Cuestionarios, Actividades, Reportes/Cumplimiento, MiCapacitacion;
    // App\Services\Certificados\CertificacionFormatoService; docs/CAPACITACION.md.
    {
        id: 'capacitacion-cursos',
        titulo: 'Crear un curso y su aula',
        proceso: 'capacitacion',
        resumen:
            'De «Nuevo curso» a «Publicar»: módulos, lecciones (video, documento, cuestionario, actividad, sesión en vivo…) y constancia al terminar.',
        queEs: 'Capacitación (etapa 6 del menú) funciona como aulas tipo Classroom: cada curso tiene un constructor (su contenido) y un aula (tablón, trabajo asignado, calificaciones y participantes). La «Portada de capacitación» resume indicadores, aulas y próximas sesiones.',
        quien: ['rh'],
        quienDetalle:
            'Quien tiene cursos.crear (RH / administrador de capacitación). El material se sube en «Biblioteca multimedia» (multimedia.administrar) y las preguntas en «Banco de preguntas» (cuestionarios.administrar).',
        antes: [
            'Los videos y documentos ya están en Capacitación → «Biblioteca multimedia» («Cargar archivo»).',
            'Si el curso lleva cuestionarios, sus preguntas están en «Banco de preguntas» («Nuevo banco» → «Agregar pregunta»).',
        ],
        pasos: [
            {
                titulo: 'Crea el curso',
                detalle:
                    'Capacitación → «Cursos y aulas» → «Nuevo curso». Escribe Título, Descripción y Objetivo y pulsa «Crear curso»: se abre el constructor.',
            },
            {
                titulo: 'Agrega módulos',
                detalle:
                    '«Agregar el primer módulo» (o «Agregar módulo»). Ordénalos con las flechas Subir / Bajar.',
            },
            {
                titulo: 'Agrega lecciones',
                detalle:
                    'En cada módulo, «Agregar lección»: elige el Tipo (Video, Documento, Guía, Texto, Enlace, Cuestionario, Actividad, Sesión en vivo, Confirmación de lectura), el título, el recurso de la biblioteca o el enlace, la duración y si es obligatoria.',
            },
            {
                titulo: 'Configura cuestionarios, actividades y sesiones',
                detalle:
                    'Al guardar una lección de esos tipos aparece «Configurar cuestionario» (calificación mínima, intentos, tiempo, preguntas del banco), «Configurar actividad» (tipo de entrega y fecha límite) o «Configurar sesión en vivo» (fecha, duración, proveedor Meet/Zoom/enlace y reglas de asistencia).',
            },
            {
                titulo: 'Ajusta los detalles',
                detalle:
                    '«Más acciones» → «Editar detalles»: responsable, intentos máximos, fechas de disponibilidad, cursos requeridos, lecciones en orden y «Genera constancia al completarse».',
            },
            {
                titulo: 'Publica',
                detalle:
                    'Pulsa «Publicar». Para retirarlo sin borrarlo usa «Más acciones» → «Archivar curso».',
            },
        ],
        despues: [
            'El curso ya se puede asignar («Asignar»). Su aula («Abrir aula») muestra el avance de cada participante.',
        ],
        errores: [
            {
                problema: '«No encuentro mi video para la lección.»',
                solucion:
                    'Súbelo primero en «Biblioteca multimedia»; los videos se convierten para reproducirse y tardan unos minutos.',
            },
            {
                problema: '«Primero guarda la configuración» (cuestionario).',
                solucion:
                    'Guarda la configuración del cuestionario antes de agregarle preguntas.',
            },
        ],
        registro: [
            'Estados del curso: Borrador, Publicado, Archivado. Cada módulo y lección con su orden.',
        ],
        relacionados: [
            'capacitacion-asignar',
            'capacitacion-calificar',
            'certificacion-mr-lana',
        ],
        accesos: [
            {
                etiqueta: 'Ir a Portada de capacitación',
                ruta: '/capacitacion',
                permisos: ['cursos.ver', 'asignaciones.ver', 'respuestas.ver'],
                modo: 'operativo',
            },
            {
                etiqueta: 'Ir a Cursos y aulas',
                ruta: '/cursos',
                permisos: ['cursos.ver'],
                modo: 'operativo',
            },
            {
                etiqueta: 'Ir a Biblioteca multimedia',
                ruta: '/multimedia',
                permisos: ['multimedia.administrar'],
                modo: 'operativo',
            },
            {
                etiqueta: 'Ir a Banco de preguntas',
                ruta: '/bancos-preguntas',
                permisos: ['cuestionarios.administrar'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo creo un curso?',
            '¿Cómo agrego un video a un curso?',
            '¿Cómo hago un cuestionario de capacitación?',
            '¿Cómo programo una sesión en vivo?',
            '¿Cómo publico un curso?',
        ],
        recorrido: 'capacitacion-curso',
    },
    {
        id: 'capacitacion-asignar',
        titulo: 'Asignar un curso',
        proceso: 'capacitacion',
        resumen:
            'Inscribe a colaboradores en un curso por persona, sucursal, departamento, puesto, rol o a todos, con fecha límite opcional.',
        queEs: 'Una asignación dice quién debe tomar un curso y para cuándo. PEOPLE calcula a quién le llega, inscribe a cada persona y le avisa.',
        quien: ['rh'],
        antes: [
            'El curso existe (de preferencia publicado).',
            'Tener el permiso asignaciones.crear.',
        ],
        pasos: [
            {
                titulo: 'Abre la asignación',
                detalle:
                    'Capacitación → «Asignaciones» → «Nueva asignación» (o «Asignar curso» desde Cursos y aulas o el aula).',
            },
            {
                titulo: 'Elige el curso y el nombre',
                detalle: 'Sección «Curso»: Curso y «Nombre de la asignación».',
            },
            {
                titulo: 'Plazo (opcional)',
                detalle:
                    '«Fecha de inicio» y «Fecha límite» (la límite no puede ser antes del inicio). Marca «Asignación obligatoria» si aplica.',
            },
            {
                titulo: 'Destinatarios',
                detalle:
                    'Con «Agregar destinatario» combina Usuario específico, Sucursal, Departamento, Puesto, Rol o Todos los colaboradores.',
            },
            {
                titulo: 'Revisa a quién le llega',
                detalle:
                    '«Ver vista previa» muestra el total, posibles duplicados y una muestra de 25 personas.',
            },
            {
                titulo: 'Crea',
                detalle:
                    '«Crear asignación»: «Se está aplicando a los colaboradores correspondientes.»',
            },
        ],
        despues: [
            'Cada persona queda inscrita y ve el curso en Mi espacio → «Mi capacitación».',
            'Si hace falta, «Cancelar» la asignación desde la lista («¿Cancelar la asignación?» → «Sí, cancelar»).',
        ],
        notificaciones: [
            '«Nueva capacitación asignada» a cada persona inscrita.',
            '«Tienes una capacitación por vencer» cada mañana cuando se acerca la fecha límite.',
        ],
        errores: [
            {
                problema: '«Debes seleccionar un destino específico.»',
                solucion:
                    'En un destinatario de tipo Usuario, Sucursal, Departamento, Puesto o Rol, elige cuál.',
            },
        ],
        registro: [
            'Estados por persona: Pendiente, En progreso, Completada, Vencida, Cancelada.',
        ],
        relacionados: [
            'capacitacion-cursos',
            'mi-capacitacion',
            'capacitacion-cumplimiento',
        ],
        accesos: [
            {
                etiqueta: 'Ir a Asignaciones',
                ruta: '/asignaciones',
                permisos: ['asignaciones.ver'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo asigno un curso a una sucursal?',
            '¿Cómo asigno capacitación a un puesto?',
            '¿Cómo cancelo una asignación de curso?',
        ],
        recorrido: 'capacitacion-curso',
    },
    {
        id: 'mi-capacitacion',
        titulo: 'Mi capacitación: tomar mis cursos',
        proceso: 'capacitacion',
        resumen:
            'El colaborador ve sus aulas, lo que le falta y sus constancias; completa lecciones, contesta cuestionarios y entrega actividades.',
        queEs: 'En Mi espacio → «Mi capacitación» están tus cursos asignados («Mis aulas»), lo que te falta («Pendientes») y tus constancias y nivel de la Certificación Mr. Lana («Mis certificados»). En Mi inicio la tarjeta «Mi capacitación» te dice cuántas lecciones te faltan y cuál sigue.',
        quien: ['colaborador'],
        antes: ['RH te asignó al menos un curso.'],
        pasos: [
            {
                titulo: 'Abre tu curso',
                detalle:
                    'Mi espacio → «Mi capacitación» → «Mis aulas» → elige el curso (o «Sigue con…» en tu inicio).',
                canal: 'web',
            },
            {
                titulo: 'Avanza lección por lección',
                detalle:
                    'Documentos, textos, enlaces y confirmaciones: «Marcar como completada». Los videos se completan solos al verlos.',
            },
            {
                titulo: 'Cuestionarios',
                detalle:
                    '«Iniciar cuestionario» → responde → «Enviar cuestionario». Si no apruebas y te quedan intentos, «Intentar de nuevo».',
            },
            {
                titulo: 'Actividades',
                detalle:
                    'Escribe tu respuesta, sube tu archivo o pega el enlace y pulsa «Enviar entrega» antes de la fecha límite. Quien imparte el curso la califica.',
            },
            {
                titulo: 'Sesiones en vivo',
                detalle:
                    'El enlace aparece cerca de la fecha. Tu asistencia la registra el instructor (o la conexión con Zoom).',
            },
            {
                titulo: 'Tu constancia',
                detalle:
                    'Al terminar un curso que genera constancia, aparece en «Mis certificados» con folio, «Descargar» y «Verificación».',
            },
        ],
        despues: [
            'Tus constancias suman para tu nivel de la Certificación Mr. Lana (Bronce, Plata, Oro).',
        ],
        notificaciones: [
            '«Resultado de tu cuestionario» y «Resultado de tu actividad» (aprobada, o rechazada con «Puedes volver a entregarla»).',
            '«Tu sesión en vivo está por comenzar».',
        ],
        errores: [
            {
                problema: '«Esta lección está bloqueada.»',
                solucion:
                    'El curso pide completar las lecciones en orden: termina la anterior.',
            },
            {
                problema: '«Ya no te quedan intentos disponibles.»',
                solucion: 'Pide a RH que revise tu caso.',
            },
        ],
        relacionados: ['capacitacion-asignar', 'certificacion-mr-lana'],
        accesos: [
            {
                etiqueta: 'Ir a Mi capacitación',
                ruta: '/mi-capacitacion',
                modo: 'colaborador',
            },
        ],
        preguntas: [
            '¿Dónde veo mis cursos?',
            '¿Cómo descargo mi constancia?',
            '¿Cómo entrego una actividad?',
            '¿Por qué no puedo abrir una lección?',
        ],
    },
    {
        id: 'capacitacion-calificar',
        titulo: 'Calificar cuestionarios, actividades y asistencia',
        proceso: 'capacitacion',
        resumen:
            'Bandejas de lo que espera calificación: respuestas abiertas de cuestionarios y entregas de actividades; asistencia de sesiones en vivo.',
        queEs: 'Las preguntas cerradas se califican solas. Lo abierto (respuestas cortas/largas, archivos) y las actividades las califica una persona desde «Calificar cuestionarios» y «Calificar actividades», del más antiguo al más reciente. Solo ves a las personas dentro de tu alcance.',
        quien: ['rh'],
        antes: ['Tener el permiso respuestas.ver.'],
        pasos: [
            {
                titulo: 'Abre la bandeja',
                detalle:
                    'Capacitación → «Calificar cuestionarios» (pestañas «Cuestionarios» / «Actividades»).',
            },
            {
                titulo: 'Califica un intento',
                detalle:
                    '«Calificar» → en cada respuesta abierta captura los puntos o marca «Correcta» / «Incorrecta». Al terminar: «Todas las respuestas abiertas ya están revisadas.»',
            },
            {
                titulo: 'Califica una entrega',
                detalle:
                    '«Calificar» → revisa la entrega («Descargar» o «Abrir enlace entregado»), captura «Calificación (%)» y «Retroalimentación» y pulsa «Aprobar» o «Rechazar».',
            },
            {
                titulo: 'Corrige asistencia (sesiones en vivo)',
                detalle:
                    'En la sesión, «Asistencias»: captura los minutos corregidos y el motivo (obligatorio).',
            },
        ],
        despues: [
            'La persona recibe su resultado; una actividad aprobada completa su lección.',
        ],
        notificaciones: [
            '«Tienes calificaciones pendientes» cada mañana a quien califica.',
        ],
        errores: [
            {
                problema: '«Debes indicar el motivo de la corrección.»',
                solucion: 'Escribe por qué corriges la asistencia.',
            },
        ],
        relacionados: ['capacitacion-cursos', 'mi-capacitacion'],
        accesos: [
            {
                etiqueta: 'Ir a Calificar cuestionarios',
                ruta: '/calificaciones/cuestionarios',
                permisos: ['respuestas.ver'],
                modo: 'operativo',
            },
            {
                etiqueta: 'Ir a Calificar actividades',
                ruta: '/calificaciones/actividades',
                permisos: ['respuestas.ver'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo califico un cuestionario?',
            '¿Cómo califico una actividad de capacitación?',
            '¿Cómo corrijo la asistencia a una sesión?',
        ],
    },
    {
        id: 'capacitacion-cumplimiento',
        titulo: 'Reporte de cumplimiento de capacitación',
        proceso: 'capacitacion',
        resumen:
            'Avance de capacitación por colaborador: asignadas, completadas, vencidas y % de cumplimiento, con exportación a Excel o PDF.',
        queEs: 'Muestra, para cada colaborador de tu alcance, cuántos cursos tiene asignados, completados y vencidos, y su porcentaje de cumplimiento, más una gráfica por rangos.',
        quien: ['rh', 'gerente'],
        antes: ['Tener reportes.sucursal o reportes.globales.'],
        pasos: [
            {
                titulo: 'Abre el reporte',
                detalle: 'Capacitación → «Reporte de cumplimiento».',
            },
            {
                titulo: 'Filtra',
                detalle:
                    'Sucursal, Departamento y Curso; «Limpiar filtros» para volver a todo.',
            },
            {
                titulo: 'Lee la gráfica y la tabla',
                detalle:
                    '«Distribución por rango de cumplimiento» (0–25 %, 25–50 %, 50–75 %, 75–100 %) y la tabla por colaborador.',
            },
            {
                titulo: 'Exporta',
                detalle: '«Excel» o «PDF» (con permiso reportes.exportar).',
            },
        ],
        despues: ['Con las vencidas, RH puede recordar o reasignar.'],
        errores: [
            {
                problema:
                    '«No hay colaboradores para mostrar con estos filtros.»',
                solucion: 'Quita filtros o revisa tu alcance.',
            },
        ],
        relacionados: ['capacitacion-asignar'],
        accesos: [
            {
                etiqueta: 'Ir a Reporte de cumplimiento',
                ruta: '/reportes/cumplimiento',
                permisos: ['reportes.globales', 'reportes.sucursal'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Quién no ha terminado su capacitación?',
            '¿Cómo exporto el cumplimiento de capacitación?',
        ],
    },
    {
        id: 'certificacion-mr-lana',
        titulo: 'Certificación Mr. Lana (formato RH-CC-R-001)',
        proceso: 'capacitacion',
        resumen:
            'Descargar el formato oficial con nombre, sucursal y fecha de ingreso ya llenos, firmarlo, subirlo firmado con su promedio y emitir el certificado «Cuentas con tu Certificación Mr. Lana».',
        queEs: 'La Certificación Mr. Lana del gestor se acredita con el formato oficial RH-CC-R-001 (certificación del gestor de crédito), que se llena y firma a pluma. PEOPLE lo prellena, guarda el escaneo firmado en el expediente y emite el certificado con folio verificable. Además, la pantalla muestra los niveles Mr. Lana Bronce, Plata y Oro según las constancias de cursos.',
        quien: ['rh', 'colaborador'],
        quienDetalle:
            'RH (o quien tenga certificacion.gestionar dentro de su alcance) descarga, sube y certifica; el colaborador ve su certificación en «Mi capacitación».',
        antes: [
            'La persona es colaborador activo y está dentro de tu alcance.',
            'Sus datos de expediente (nombre, sucursal, fecha de ingreso) están completos: salen impresos en el encabezado.',
        ],
        pasos: [
            {
                titulo: 'Abre Certificación',
                detalle:
                    'Capacitación → «Certificación Mr. Lana» y busca a la persona (estado «Sin formato»).',
            },
            {
                titulo: 'Descarga el formato',
                detalle:
                    '«Descargar formato»: es el PDF oficial RH-CC-R-001 con el encabezado (nombre, sucursal, fecha de ingreso) ya lleno. La persona pasa a «Formato descargado» y queda registrado quién y cuándo.',
            },
            {
                titulo: 'Llénalo y fírmalo',
                detalle:
                    'En papel: temas, fechas, evaluación, promedio y firmas.',
                canal: 'automatico',
            },
            {
                titulo: 'Sube el formato firmado',
                detalle:
                    '«Subir formato firmado»: adjunta el escaneo, captura el «Promedio del formato» (mínimo aprobatorio configurado, 80 por defecto) y confirma que tiene las firmas del gestor y del gerente de sucursal y «Certificado: SÍ».',
            },
            {
                titulo: 'Queda certificado',
                detalle:
                    'Al guardar, la persona queda «Certificado» con folio y se emite el certificado «Cuentas con tu Certificación Mr. Lana». El formato firmado y el certificado se guardan en su expediente; en su renglón aparecen «Ver certificado» y «Descargar».',
                canal: 'automatico',
            },
        ],
        llamados: [
            {
                tono: 'info',
                texto: 'Si el certificado no se puede imprimir en ese momento, la certificación no se deshace: el certificado se genera al descargarlo.',
            },
        ],
        despues: [
            'El colaborador ve su certificación en Mi espacio → «Mi capacitación» → «Mis certificados».',
        ],
        documentos: [
            'Formato RH-CC-R-001 prellenado (descarga) y firmado (expediente).',
            'Certificado «Cuentas con tu Certificación Mr. Lana» con folio verificable.',
        ],
        errores: [
            {
                problema: '«No aparece la persona.»',
                solucion:
                    'Está fuera de tu alcance o no es colaborador activo.',
            },
            {
                problema: '«El promedio no alcanza.»',
                solucion:
                    'Con un promedio menor al mínimo no se certifica: revisa el formato con el área de capacitación.',
            },
        ],
        registro: [
            'Quién descargó el formato y cuándo; quién subió el firmado, el promedio y el folio del certificado (bitácora).',
        ],
        relacionados: ['capacitacion-cursos', 'mi-capacitacion', 'expediente'],
        accesos: [
            {
                etiqueta: 'Ir a Certificación Mr. Lana',
                ruta: '/capacitacion/certificacion',
                permisos: [
                    'cursos.ver',
                    'asignaciones.ver',
                    'respuestas.ver',
                    'certificacion.gestionar',
                ],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo certifico a un gestor?',
            '¿Dónde descargo el formato de certificación RH-CC-R-001?',
            '¿Cómo subo el formato de certificación firmado?',
            '¿Cómo emito el certificado Mr. Lana?',
        ],
    },

    // ─────────────── Proceso 13 — Seguimiento y Nine Box (Fase 2) ───────────────
    // Fuente real: routes/seguimiento.php, routes/evaluaciones-tecnicas.php,
    // pages/Seguimiento/**, App\Services\EvaluacionesTecnicas\*,
    // docs/EVALUACIONES_TECNICAS.md, docs/NINE_BOX.md, docs/SEGUIMIENTO.md.
    {
        id: 'seguimientos-tecnicos',
        titulo: 'Seguimientos técnicos: «Seguimiento 15», «30», «60»…',
        proceso: 'seguimiento',
        resumen:
            'Evaluaciones técnicas por puesto que PEOPLE genera solas cada mañana al llegar el día desde el ingreso; las contesta el colaborador o su jefe directo y se califican al instante.',
        queEs: 'Un Seguimiento técnico es una evaluación de conocimientos del puesto que se aplica en días fijos desde que la persona entró (por ejemplo día 15, 30 y 60). Cada puesto tiene su propio calendario y sus propios formularios. No dependen de Reportes: son la parte de «potencial» que después usa la Nine Box.',
        quien: ['rh', 'gerente', 'regional', 'colaborador'],
        quienDetalle:
            'RH arma el calendario y los formularios; PEOPLE las genera solas; las contesta el colaborador (en Mi espacio) o su jefe directo, según el paso; RH y quien tiene alcance consultan los resultados.',
        antes: [
            'El puesto de la persona tiene calendario en «Calendario de seguimientos» y su formulario técnico está publicado.',
            'La persona está activa y tiene fecha de inicio (inicio de capacitación inicial o, si no hay, fecha de ingreso) en «Fechas laborales».',
            'El organigrama dice quién es su jefe directo (nunca se captura a mano).',
        ],
        pasos: [
            {
                titulo: 'PEOPLE genera la evaluación',
                detalle:
                    'Cada mañana (6:45) revisa a los colaboradores activos: al llegar el día N de su puesto crea la evaluación con la versión publicada del formulario, su fecha límite (día programado + «Días para contestar») y avisa a quien la contesta. Si un día ya pasó, solo se genera hasta 15 días tarde.',
                canal: 'automatico',
            },
            {
                titulo: 'Si te toca contestarla como colaborador',
                detalle:
                    'Mi espacio → «Mis seguimientos técnicos» → «Por contestar» → «Contestar ahora». Responde las preguntas, escribe comentarios si quieres y pulsa «Enviar evaluación» → «Enviar».',
                quien: 'colaborador',
                canal: 'web',
            },
            {
                titulo: 'Si te toca como jefe directo',
                detalle:
                    'Seguimiento → «Seguimientos técnicos» (o «Mis seguimientos técnicos») → bloque «Te toca evaluar a tu equipo». Abre la de la persona, contesta, agrega observaciones para RH y pulsa «Enviar evaluación».',
                quien: 'gerente',
                canal: 'web',
            },
            {
                titulo: 'Calificación al instante',
                detalle:
                    'Al enviar se califica contra la mínima del paso (por defecto 80): queda «Aprobada» o «No aprobada». Ya no se puede cambiar.',
                canal: 'automatico',
            },
            {
                titulo: 'RH da seguimiento',
                detalle:
                    'En «Seguimientos técnicos» revisa indicadores, «Próximas a vencer» y «Vencidas», filtra por estado, puesto, sucursal o quién contesta, y abre cualquier renglón para ver la respuesta por pregunta o el nombre para ver la línea de tiempo de la persona. «Generar las que ya tocan» corre la generación al momento.',
                quien: 'rh',
                canal: 'web',
            },
        ],
        llamados: [
            {
                tono: 'info',
                texto: 'Una evaluación vencida todavía se puede contestar. Si la persona deja de estar activa o cambia de puesto, sus evaluaciones pendientes se cancelan (no se borran).',
            },
            {
                tono: 'aviso',
                texto: 'Nadie contesta por sí mismo una evaluación que le toca al jefe directo, ni siquiera un super administrador.',
            },
        ],
        despues: [
            'El promedio de sus Seguimientos alimenta el potencial de la persona en la Nine Box.',
            'Su jefe directo recibe el resultado cuando el colaborador contesta.',
        ],
        notificaciones: [
            'Al colaborador: «Tu {evaluación} está lista» con su fecha límite.',
            'Al jefe directo: «{evaluación} por contestar».',
            'A quien la contesta: «{evaluación} vencida» (prioridad alta) cuando pasa la fecha límite.',
            'Al jefe directo: «{persona} contestó su {evaluación}» con el resultado.',
        ],
        errores: [
            {
                problema: '«Esta evaluación ya se contestó o se canceló.»',
                solucion:
                    'Ya no admite respuestas; ábrela desde la lista para ver su resultado.',
            },
            {
                problema:
                    '«N no se generaron porque su formulario no tiene versión publicada.»',
                solucion:
                    'Abre «Calendario de seguimientos» → «Formularios técnicos», publica el formulario y vuelve a pulsar «Generar las que ya tocan».',
            },
            {
                problema: '«No me aparece mi seguimiento.»',
                solucion:
                    'Todavía no llega el día, tu puesto no tiene calendario o falta tu fecha de inicio en Fechas laborales. Pregunta a RH.',
            },
        ],
        registro: [
            'Respuestas por pregunta, puntos, calificación, aprobada o no, comentarios, quién y cuándo la contestó (bitácora «evaluación técnica contestada»).',
        ],
        relacionados: [
            'calendario-seguimientos',
            'nine-box',
            'fechas-laborales',
        ],
        accesos: [
            {
                etiqueta: 'Ir a Seguimientos técnicos',
                ruta: '/seguimiento/evaluaciones-tecnicas',
                permisos: ['evaluaciones_tecnicas.ver'],
                modo: 'operativo',
            },
            {
                etiqueta: 'Ir a Mis seguimientos técnicos',
                ruta: '/mis-evaluaciones-tecnicas',
                modo: 'colaborador',
            },
        ],
        preguntas: [
            '¿Qué es el Seguimiento 15?',
            '¿Cómo contesto mi seguimiento técnico?',
            '¿Cómo evalúo a mi equipo en los seguimientos?',
            '¿Por qué no se generó una evaluación técnica?',
            '¿Dónde veo los seguimientos vencidos?',
        ],
        recorrido: 'seguimiento-nine-box',
    },
    {
        id: 'calendario-seguimientos',
        titulo: 'Calendario de seguimientos y formularios técnicos',
        proceso: 'seguimiento',
        resumen:
            'Por puesto: qué día desde el ingreso se aplica cada Seguimiento, con qué formulario, quién lo contesta, con cuánto aprueba y en cuántos días.',
        queEs: 'Es la configuración de los Seguimientos técnicos. Tiene dos pestañas: «Calendario por puesto» (los días y reglas de cada puesto) y «Formularios técnicos» (las preguntas, que se editan con el mismo editor tipo Google Forms de las evaluaciones de ingreso).',
        quien: ['rh'],
        antes: [
            'Tener el permiso evaluaciones_tecnicas.configurar.',
            'El puesto tiene duración de contrato de capacitación en el catálogo de puestos (para la sugerencia de días).',
        ],
        pasos: [
            {
                titulo: 'Crea el formulario técnico',
                detalle:
                    'Seguimiento → «Calendario de seguimientos» → «Nuevo formulario técnico». Escribe el nombre (por ejemplo «Técnica de cobranza — Gestor») y pulsa «Crear y abrir el editor».',
            },
            {
                titulo: 'Arma sus preguntas y publícalo',
                detalle:
                    'En el editor usa «Preguntas», «Diseño» y «Vista previa»; el borrador se guarda solo. Pulsa «Publicar versión»: solo un formulario publicado se puede usar en el calendario.',
            },
            {
                titulo: 'Elige el puesto',
                detalle:
                    'Pestaña «Calendario por puesto»: busca el puesto en la lista (puedes marcar «Solo con calendario»).',
            },
            {
                titulo: 'Agrega sus evaluaciones',
                detalle:
                    '«Usar sugerencia» propone los días según su contrato de capacitación, o usa «Agregar evaluación» (máximo 12). En cada una: «Día desde el ingreso» (1–730), «Nombre» («Seguimiento N»), «Formulario técnico», «La contesta» (Colaborador o Jefe directo), «Aprueba con (0-100)», «Días para contestar» (1–60) y, si aplica, «Marcar como técnica final».',
            },
            {
                titulo: 'Guarda',
                detalle:
                    'Pulsa «Guardar calendario». Aparece «Guardaste el calendario de {puesto}.»',
            },
            {
                titulo: 'Cópialo a otros puestos (opcional)',
                detalle:
                    '«Copiar a otros puestos» → marca los puestos destino → «Copiar a N». Reemplaza su calendario.',
            },
        ],
        despues: [
            'Desde la mañana siguiente PEOPLE genera las evaluaciones que ya tocan (hasta 15 días tarde). Quitar una evaluación del calendario no borra las ya generadas.',
            'Las evaluaciones que ya se abrieron conservan la versión del formulario con la que nacieron.',
        ],
        errores: [
            {
                problema:
                    '«Hay dos evaluaciones el día D: cada día solo puede tener una.»',
                solucion: 'Cambia el día de una de ellas.',
            },
            {
                problema: '«Evaluación N: elige qué formulario técnico usa.»',
                solucion:
                    'Elige un formulario; si no hay, créalo primero en «Formularios técnicos».',
            },
            {
                problema: '«Solo una evaluación puede ser la técnica final.»',
                solucion: 'Desmarca «Técnica final» en las demás.',
            },
        ],
        registro: [
            'Cada formulario guarda sus versiones publicadas; cada evaluación generada recuerda su versión.',
        ],
        relacionados: ['seguimientos-tecnicos', 'nine-box'],
        accesos: [
            {
                etiqueta: 'Ir a Calendario de seguimientos',
                ruta: '/seguimiento/evaluaciones-tecnicas/configuracion',
                permisos: ['evaluaciones_tecnicas.configurar'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo configuro los seguimientos de un puesto?',
            '¿Cómo creo un formulario técnico?',
            '¿Cómo cambio las preguntas del Seguimiento 30?',
            '¿Cómo copio el calendario a otro puesto?',
        ],
        recorrido: 'seguimiento-nine-box',
    },
    {
        id: 'vinculos-reportes',
        titulo: 'Vínculos con Reportes (por persona y por sucursal)',
        proceso: 'seguimiento',
        resumen:
            'Une a cada persona y cada sucursal de Reportes con su colaborador y su sucursal de PEOPLE, para que la Nine Box tome sus datos. Nunca se vincula por ruta.',
        queEs: 'Reportes conoce a la gente por sus rutas; PEOPLE, por colaborador. Aquí se dice «esta persona de Reportes es este colaborador» y «esta sucursal de Reportes es esta sucursal». Una persona con varias rutas se vincula una sola vez y la Nine Box suma todas sus rutas.',
        quien: ['rh'],
        antes: [
            'La conexión con Reportes está configurada (si no, Sistemas captura REPORTES_API_URL y REPORTES_API_TOKEN).',
            'Tener el permiso seguimiento.vincular.',
        ],
        pasos: [
            {
                titulo: 'Abre la pantalla',
                detalle: 'Seguimiento → «Vínculos con Reportes».',
            },
            {
                titulo: 'Actualiza los datos (si hubo altas)',
                detalle:
                    '«Actualizar desde Reportes» vuelve a traer el directorio de personas y sucursales.',
            },
            {
                titulo: 'Elige qué vincular',
                detalle:
                    '«Personas» o «Sucursales». En «Mostrar» deja «Sin vincular» (o «Con sugerencia») y busca por nombre, clave, sucursal o ruta.',
            },
            {
                titulo: 'Confirma o elige',
                detalle:
                    'Si aparece «¿Es {colaborador}?», revisa el motivo y pulsa «Confirmar». Si no, elige al colaborador en «Colaborador de PEOPLE…» (o la sucursal en «Sucursal de PEOPLE…»). Se guarda al momento.',
            },
            {
                titulo: 'Todas las sugerencias de una vez (opcional)',
                detalle:
                    '«Confirmar N sugerencias» acepta todas las de la pestaña actual.',
            },
            {
                titulo: 'Si te equivocaste',
                detalle:
                    'El ícono de desvincular desactiva el vínculo; el historial se conserva. Después elige al correcto.',
            },
        ],
        llamados: [
            {
                tono: 'importante',
                texto: 'Nunca se vincula por ruta: una ruta cambia de gestor, la persona no. Los registros de Reportes que no son personas (vacantes, cartera vencida, códigos) no se vinculan.',
            },
        ],
        despues: [
            'La persona deja de aparecer «Sin vincular a PEOPLE» en la Nine Box y su desempeño entra a la matriz.',
        ],
        errores: [
            {
                problema:
                    '«Esa persona ya no existe en Reportes (o está inactiva).»',
                solucion:
                    'Pulsa «Actualizar desde Reportes» y vuelve a buscarla.',
            },
            {
                problema: '«homónimos: elige a mano»',
                solucion:
                    'Hay dos personas con el mismo nombre: revisa sucursal y rutas antes de elegir al colaborador.',
            },
        ],
        registro: [
            'Cada vínculo guarda cómo se hizo («Elegido a mano» o «Sugerencia confirmada»), quién lo hizo y, si se desactivó, quién, cuándo y por qué. Nada se borra.',
        ],
        relacionados: ['nine-box', 'seguimientos-tecnicos'],
        accesos: [
            {
                etiqueta: 'Ir a Vínculos con Reportes',
                ruta: '/seguimiento/vinculos',
                permisos: ['seguimiento.vincular'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo vinculo a un gestor con Reportes?',
            '¿Por qué alguien sale sin vincular a PEOPLE?',
            '¿Cómo vinculo una sucursal de Reportes?',
            '¿Se vincula por ruta?',
        ],
        recorrido: 'seguimiento-nine-box',
    },
    {
        id: 'nine-box',
        titulo: 'Nine Box: desempeño × potencial',
        proceso: 'seguimiento',
        resumen:
            'Matriz de 3 × 3 por mes o trimestre: el desempeño sale de Reportes (todas las rutas de la persona) y el potencial de sus Seguimientos técnicos más la calibración de su jefe.',
        queEs: 'La Nine Box ubica a cada persona en uno de 9 cuadrantes (Estrella, Alto potencial, Enigma, Profesional clave, Núcleo, Dilema, Confiable, Efectivo, Bajo desempeño), cada uno con su acción sugerida. Los datos de Reportes solo se usan para esto; no hay tableros de datos duros aparte.',
        quien: ['rh', 'gerente', 'regional', 'direccion_comercial'],
        quienDetalle:
            'Consulta quien tiene nine_box.ver dentro de su alcance; calibra el jefe directo o RH (nine_box.calibrar); «Configurar» es de RH (nine_box.configurar).',
        antes: [
            'Las personas y sucursales están vinculadas en «Vínculos con Reportes».',
            'Hay Seguimientos técnicos contestados (para el potencial).',
        ],
        pasos: [
            {
                titulo: 'Abre la Nine Box',
                detalle:
                    'Seguimiento → «Nine Box» (la entrada /seguimiento también lleva aquí).',
            },
            {
                titulo: 'Elige el periodo',
                detalle:
                    '«Mes de Reportes» o «Trimestre» y el mes o trimestre. Si Reportes no tiene datos verás «Reportes aún no tiene datos de este periodo».',
            },
            {
                titulo: 'Lee el resumen y filtra',
                detalle:
                    'Indicadores: «En la matriz», «Estrellas», «Calibrados», «Por calibrar», «Sin vincular a PEOPLE». Busca gestor, sucursal o ruta, filtra por sucursal y cambia entre «Matriz» y «Tabla». Clic en un cuadrante para filtrarlo.',
            },
            {
                titulo: 'Abre a una persona',
                detalle:
                    'Clic en su nombre: verás su desempeño del periodo, tendencia semanal, potencial (promedio de Seguimientos y calibración vigente), sus evaluaciones técnicas y el historial de calibraciones, más «Detalle en Seguimiento» y «Expediente».',
            },
            {
                titulo: 'Calibra su potencial',
                detalle:
                    'En «Calibrar potencial» elige Bajo, Medio o Alto, explica por qué (mínimo 10 caracteres) y pulsa «Guardar calibración». Aparece «Potencial de {nombre} calibrado.»',
                quien: 'gerente',
            },
            {
                titulo: 'Exporta (opcional)',
                detalle: '«Exportar a Excel» descarga la matriz del periodo.',
            },
        ],
        llamados: [
            {
                tono: 'importante',
                texto: 'Nadie calibra su propio potencial: lo hace su jefe directo o RH. Cada calibración queda en el historial y nunca se borra; sigue vigente en los periodos siguientes hasta que se guarde otra.',
            },
        ],
        despues: [
            'La persona cambia de cuadrante en ese periodo y su acción sugerida se actualiza.',
            'RH puede ajustar metas, pesos y cortes en «Configurar» (con motivo; cada cambio crea una versión nueva).',
        ],
        errores: [
            {
                problema:
                    '«La conexión con Reportes todavía no está configurada.»',
                solucion:
                    'Pide a Sistemas que capture REPORTES_API_URL y REPORTES_API_TOKEN.',
            },
            {
                problema: '«Reportes no responde en este momento.»',
                solucion:
                    'Intenta de nuevo en unos minutos; si sigue igual, avísalo a Sistemas.',
            },
            {
                problema: '«No puedes calibrar el potencial de esta persona.»',
                solucion:
                    'Está fuera de tu alcance o no está vinculada a PEOPLE.',
            },
        ],
        registro: [
            'Cada calibración: persona, periodo, nivel, comentario y quién la capturó (bitácora «nine box calibrada»).',
            'Cada versión de la configuración con su motivo.',
        ],
        relacionados: [
            'vinculos-reportes',
            'seguimientos-tecnicos',
            'calendario-seguimientos',
        ],
        accesos: [
            {
                etiqueta: 'Ir a Nine Box',
                ruta: '/seguimiento/nine-box',
                permisos: ['nine_box.ver'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Qué es la Nine Box?',
            '¿Cómo calibro el potencial de un gestor?',
            '¿De dónde sale el desempeño de la Nine Box?',
            '¿Cómo exporto la Nine Box a Excel?',
        ],
        recorrido: 'seguimiento-nine-box',
    },
];
