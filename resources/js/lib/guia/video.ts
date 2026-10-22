import type { EscenaVideo, MiniVideo } from './tipos';

/**
 * Video tutorial «MR. LANA PEOPLE — Flujo completo de Recursos Humanos».
 * Única fuente del guion: de aquí salen GUION_VIDEO_COMPLETO.md,
 * STORYBOARD.md, CAPTURAS_NECESARIAS.md, TRANSCRIPCION.md, subtitulos.srt
 * (npm run guia:docs) y los capítulos de /ayuda/video. Los tiempos NO se
 * escriben a mano: salen de la narración (palabras por segundo de una voz
 * natural en español de México), así guion, subtítulos y capítulos cuadran.
 */
export const TITULO_VIDEO =
    'MR. LANA PEOPLE — Flujo completo de Recursos Humanos';

/** Ritmo de una locución tutorial clara y pausada (español de México). */
export const PALABRAS_POR_SEGUNDO = 2.2;

/**
 * Segundos de demostración en pantalla por escena (clics, escritura,
 * zoom) además de la voz: un tutorial real no narra sin parar.
 */
const PAUSA = 6;

type EscenaBase = Omit<EscenaVideo, 'numero' | 'inicio' | 'fin'>;

const ESCENAS: EscenaBase[] = [
    {
        capitulo: 'Introducción',
        pantalla: 'Portada',
        ruta: null,
        captura: '00-portada.png',
        accion: 'Portada con el logotipo y el título del video sobre fondo crema.',
        zoom: 'Sin zoom',
        textoPantalla: 'MR. LANA PEOPLE · Flujo completo de Recursos Humanos',
        narracion:
            'Bienvenido a MR. LANA PEOPLE. En este video vas a ver, de principio a fin, cómo se lleva a una persona desde una vacante hasta convertirla en colaborador activo, y todo lo que pasa después en su vida laboral.',
        transicion: 'Fundido a la pantalla de acceso',
    },
    {
        capitulo: 'Introducción',
        pantalla: 'Inicio de sesión',
        ruta: '/login',
        captura: '01-login.png',
        accion: 'Escribir el usuario (nombre y primer apellido) y entrar. La contraseña se escribe fuera de cuadro.',
        zoom: 'Zoom suave al campo Usuario',
        textoPantalla: 'Usuario = primer nombre + primer apellido',
        narracion:
            'Para entrar usas tu usuario, que es tu primer nombre y tu primer apellido, y tu contraseña. Si Recursos Humanos te dio una contraseña temporal, el sistema te pedirá cambiarla la primera vez.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Qué es MR. LANA PEOPLE',
        pantalla: 'Inicio (tablero de RH)',
        ruta: '/dashboard',
        captura: '02-dashboard-rh.png',
        accion: 'Recorrer los indicadores y el menú lateral agrupado por proceso.',
        zoom: 'Zoom a los indicadores, luego al menú',
        textoPantalla: 'Reclutamiento → Desarrollo → Personal → Organización',
        narracion:
            'MR. LANA PEOPLE es el sistema de Recursos Humanos de la empresa. El menú está ordenado por proceso: reclutamiento, desarrollo, personal y organización. Cada persona solo ve lo que su rol le permite, y todo lo que se decide aquí también se puede atender desde la app.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Plantilla y vacantes reales',
        pantalla: 'Organización → Sucursales → detalle',
        ruta: '/administracion/sucursales/:sucursal',
        captura: '03-plantilla-sucursal.png',
        accion: 'Abrir la sucursal del gerente demo y mostrar la plantilla autorizada contra la ocupada del puesto Gestor.',
        zoom: 'Zoom al renglón Gestor: autorizados contra ocupados',
        textoPantalla: 'Plantilla autorizada − ocupados = vacantes reales',
        narracion:
            'Todo comienza con la plantilla autorizada. MR. LANA PEOPLE compara las posiciones autorizadas con los colaboradores que actualmente ocupan cada puesto. Si una sucursal tiene cinco plazas de gestor y cuatro personas, falta una: esa es la vacante real.',
        transicion: 'Desliza a Vacantes',
    },
    {
        capitulo: 'Plantilla y vacantes reales',
        pantalla: 'Reclutamiento → Vacantes',
        ruta: '/rh/vacantes',
        captura: '04-vacantes.png',
        accion: 'Mostrar el resumen por puesto y una fila «Gestor» con plaza disponible.',
        zoom: 'Zoom a la fila de la vacante',
        textoPantalla: '1 plaza disponible',
        narracion:
            'Nadie captura vacantes a mano. Cuando alguien sale, la vacante aparece sola; cuando alguien entra, se cierra sola. Campañas, candidatos y contratación usan exactamente esta misma cifra.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Campañas',
        pantalla: 'Reclutamiento → Campañas → Nueva campaña',
        ruta: '/rh/campanas',
        captura: '05-campana-nueva.png',
        accion: 'Abrir «Nueva campaña», elegir una vacante de Gestor y llenar canal, fechas, gasto, sueldo publicado, copy y liga.',
        zoom: 'Zoom al selector de vacante',
        textoPantalla: 'Empresa, sucursal y puesto salen de la vacante',
        narracion:
            'Para atraer candidatos registras una campaña sobre la vacante real. Al elegir la vacante, el sistema toma la empresa, la sucursal y el puesto; tú solo capturas el canal, las fechas, el presupuesto, el gasto, el sueldo publicado, el texto del anuncio y la liga. El sueldo publicado no cambia el catálogo de puestos.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Campañas',
        pantalla: 'Campañas (tarjeta con embudo)',
        ruta: '/rh/campanas',
        captura: '06-campana-metricas.png',
        accion: 'Mostrar la tarjeta de la campaña con su embudo y costos; abrir un adjunto PDF.',
        zoom: 'Zoom a costo por candidato y costo por contratación',
        textoPantalla:
            '$4,500 · 30 candidatos · 2 contratados · $2,250 por contratación',
        narracion:
            'Cada campaña mide su embudo: candidatos, contactados, entrevistas, psicométricos y contratados. Con el gasto, calcula el costo por candidato y por contratación. También puedes adjuntar la factura o el reporte del proveedor, que quedan privados.',
        transicion: 'Desliza a Candidatos',
    },
    {
        capitulo: 'Crear candidato',
        pantalla: 'Reclutamiento → Candidatos → Nuevo candidato',
        ruta: '/rh/candidatos',
        captura: '07-candidato-nuevo.png',
        accion: 'Capturar a «Juan Pérez Demo» (sin guardar): nombre, teléfono ficticio, vacante, campaña y fuente.',
        zoom: 'Zoom a Vacante y Campaña',
        textoPantalla: 'El candidato se registra sobre la vacante',
        narracion:
            'El candidato se registra sobre la vacante. Capturas su nombre y contacto, eliges la vacante, la campaña por la que llegó y la fuente. Si todavía no hay vacante para esa persona, la marcas como candidato espontáneo, pero no podrá contratarse hasta vincularla a una vacante real.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Crear candidato',
        pantalla: 'Candidatos (tablero)',
        ruta: '/rh/candidatos',
        captura: '08-candidatos-tablero.png',
        accion: 'Mostrar las columnas del tablero y una tarjeta demo («Demo Perfil Ciclo») en Filtro RH.',
        zoom: 'Barrido por las columnas',
        textoPantalla:
            'Filtro RH → Entrevista → Psicométricos → Socioeconómico → Contratación → Contratado',
        narracion:
            'El tablero muestra seis fases y dos columnas de cierre: rechazado y desistió. Arrastrar una tarjeta solo sirve para cerrar el proceso con un motivo. Para avanzar, se abre la ficha del candidato.',
        transicion: 'Zoom a la tarjeta y abrir ficha',
    },
    {
        capitulo: 'Filtro RH',
        pantalla: 'Ficha del candidato',
        ruta: '/rh/candidatos/:candidato',
        captura: '09-candidato-ficha.png',
        accion: 'Mostrar los pasos, el responsable actual y el botón «Revisar perfil»; registrarlo como viable.',
        zoom: 'Zoom a «Acciones disponibles»',
        textoPantalla:
            'PEOPLE registra; el WhatsApp se envía fuera del sistema',
        narracion:
            'En el filtro de Recursos Humanos se hace el primer contacto, normalmente por WhatsApp, fuera del sistema. PEOPLE registra la fecha, el responsable, las notas y el resultado. Si no continúa, el motivo es obligatorio.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Entrevista con el gerente',
        pantalla: 'Ficha del candidato (vista del gerente)',
        ruta: '/rh/candidatos/:candidato',
        captura: '10-entrevista.png',
        accion: 'Como gerente de sucursal, «Registrar entrevista»: fecha, observaciones y resultado viable.',
        zoom: 'Zoom al diálogo de entrevista',
        textoPantalla: 'Decide el gerente de la sucursal',
        narracion:
            'Después, el gerente de la sucursal entrevista al candidato y registra la fecha, sus observaciones y el resultado. El gerente regional no tiene que aprobar a cada candidato.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Psicométricos',
        pantalla: 'Ficha → Registrar resultados',
        ruta: '/rh/candidatos/:candidato',
        captura: '11-psicometricos.png',
        accion: 'Registrar el resumen y subir un PDF ficticio; abrirlo en el visor; el gerente lo revisa en perfil.',
        zoom: 'Zoom al visor del PDF',
        textoPantalla: 'La evidencia se abre sin salir de PEOPLE',
        narracion:
            'Recursos Humanos registra los resultados psicométricos con su PDF o imagen, y el gerente los revisa. La evidencia se abre dentro de PEOPLE, sin descargar nada y sin mostrar dónde se guarda.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Socioeconómico',
        pantalla: 'Ficha → Registrar estudio socioeconómico',
        ruta: '/rh/candidatos/:candidato',
        captura: '12-socioeconomico.png',
        accion: 'Capturar la visita: fecha, dirección ficticia, checklist, riesgos y una foto; reproducir un video de evidencia.',
        zoom: 'Zoom al checklist',
        textoPantalla: 'Fotos, PDF o video',
        narracion:
            'Se carga el estudio socioeconómico: la visita, un checklist, los riesgos y sus evidencias en foto, PDF o video. Cargarlo no lo aprueba: el responsable lo abre y decide si es viable.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Autorización e intervención',
        pantalla: 'Ficha → Referencias y autorización de RH',
        ruta: '/rh/candidatos/:candidato',
        captura: '13-referencias-preautorizacion.png',
        accion: 'Registrar una referencia, concluir, y mostrar «Autorizar contratación» de RH.',
        zoom: 'Zoom a la cadena de aprobaciones',
        textoPantalla: 'Referencias → autoriza RH',
        narracion:
            'Antes de decidir se valida al menos una referencia laboral. Después, solo Recursos Humanos autoriza la contratación: el gerente no la autoriza. En el caso normal no interviene el regional ni Dirección Comercial.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Autorización e intervención',
        pantalla: 'Ficha → Intervención',
        ruta: '/rh/candidatos/:candidato',
        captura: '14-intervencion.png',
        accion: 'Mostrar un candidato rechazado por RH con la sección «Intervención sobre el rechazo de RH».',
        zoom: 'Zoom a «Solicitar intervención»',
        textoPantalla:
            'Gestor o volante → Regional · Puesto superior → Dirección Comercial',
        narracion:
            'Si Recursos Humanos rechaza, el gerente puede aceptar el rechazo o solicitar una intervención. Para gestor o gestor volante decide el regional; para puestos superiores, Dirección Comercial. Todo queda en el historial.',
        transicion: 'Fundido',
    },
    {
        capitulo: 'Contratación y QR',
        pantalla: 'Ficha → Generar QR de contratación',
        ruta: '/rh/candidatos/:candidato',
        captura: '15-generar-qr.png',
        accion: 'Con el candidato autorizado, capturar sueldo, fecha de ingreso y modalidad; confirmar.',
        zoom: 'Zoom al diálogo',
        textoPantalla:
            'Valida que la plaza siga libre antes de crear a la persona',
        narracion:
            'Con la autorización de Recursos Humanos aparece Generar QR de contratación. Capturas el sueldo y la fecha de ingreso. El sistema bloquea la vacante, revisa que la plaza siga libre, crea a la persona sin duplicarla y ocupa la plaza. Así, dos personas nunca ocupan la última plaza.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Contratación y QR',
        pantalla: 'Invitación QR (detalle)',
        ruta: '/rh/incorporacion/invitaciones/:invitacion',
        captura: '16-invitacion-qr.png',
        accion: 'Mostrar el QR, su vencimiento, copiar liga y descargar PNG.',
        zoom: 'Zoom al QR',
        textoPantalla: 'Temporal (máx. 24 h) · un solo uso · revocable',
        narracion:
            'El QR no es solo un formulario: es el inicio de la incorporación. Es temporal, de un solo uso y se puede regenerar o revocar. Se imprime o se envía la liga a la persona.',
        transicion: 'Desliza a celular',
    },
    {
        capitulo: 'Registro del colaborador',
        pantalla: 'App: escanear QR y registro',
        ruta: '/incorporacion/qr/:token',
        captura: '17-app-registro.png',
        accion: 'En el celular, escanear el QR, confirmar nombre, apellidos, teléfono y crear la contraseña.',
        zoom: 'Pantalla de celular centrada',
        textoPantalla: 'Lo laboral ya lo definió RH',
        narracion:
            'La persona escanea el QR con su celular, confirma su nombre, apellidos, correo y teléfono, y crea su contraseña. No captura empresa, sucursal, puesto ni tipo de contrato: eso ya viene del proceso de Recursos Humanos.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Registro del colaborador',
        pantalla: 'App: Mi incorporación',
        ruta: null,
        captura: '18-app-documentos.png',
        accion: 'Mostrar la lista de documentos del alta y subir uno; mostrar «Completa tu información».',
        zoom: 'Pantalla de celular centrada',
        textoPantalla: '11 documentos obligatorios del alta',
        narracion:
            'Después sube sus documentos desde la app y completa los datos que pide su contrato, como domicilio, contacto de emergencia y beneficiario. Esos datos se envían a Recursos Humanos para su autorización.',
        transicion: 'Desliza a web',
    },
    {
        capitulo: 'Expediente',
        pantalla: 'Expedientes → expediente',
        ruta: '/rh/expedientes/:colaborador',
        captura: '19-expediente.png',
        accion: 'Mostrar la cabecera, el botón «Ciclo laboral» y las pestañas del expediente.',
        zoom: 'Zoom a las pestañas',
        textoPantalla:
            'Resumen · Datos · Documentos · Onboarding · Avisos · Vacaciones · Recibos · Préstamos · Solicitudes · Historial',
        narracion:
            'Recursos Humanos ve todo en el expediente: datos personales y laborales, cuenta, documentos, onboarding, avisos, vacaciones, recibos, préstamos, solicitudes e historial. Candidato y colaborador son la misma persona, con todo su antecedente.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Expediente',
        pantalla: 'Expediente → datos faltantes',
        ruta: '/rh/expedientes/:colaborador',
        captura: '20-expediente-faltantes.png',
        accion: 'Mostrar el semáforo por secciones y pulsar «Avisar al colaborador».',
        zoom: 'Zoom al semáforo',
        textoPantalla: 'Identidad ✓ · Fiscal ✓ · Domicilio ⚠ · Emergencia ✗',
        narracion:
            'El expediente no muestra solo un porcentaje: dice qué sección está completa y cuál no. Con Avisar al colaborador, la persona recibe una notificación y un push para completarlo desde la app.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Contrato inicial',
        pantalla: 'Ciclo laboral → documentos de contratación',
        ruta: '/rh/colaboradores/:colaborador/ciclo',
        captura: '21-contrato-inicial.png',
        accion: 'Pulsar «Generar paquete de contratación» y previsualizar el contrato.',
        zoom: 'Zoom a la vista previa del contrato',
        textoPantalla: 'Datos del expediente · duración del puesto',
        narracion:
            'Con el expediente completo, Recursos Humanos genera el paquete de contratación. El sistema llena el contrato de capacitación inicial con los datos del expediente, y la duración la define cada puesto: por ejemplo, dos meses para gestor y tres para gerente. Si falta un dato obligatorio, no se genera un documento incompleto.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Contrato inicial',
        pantalla: 'Documentos del proceso → ruta del original',
        ruta: '/rh/colaboradores/:colaborador/ciclo',
        captura: '22-firma-original.png',
        accion: 'Mostrar imprimir, registrar firma, enviar a corporativo, recepción, subir firmado y archivar.',
        zoom: 'Zoom a la ruta del documento',
        textoPantalla:
            'Imprimir → Firma y huella → Envío → Recepción → Escaneo → Archivo',
        narracion:
            'El gerente imprime, recaba firma y huella, y envía el original a corporativo. Recursos Humanos registra la recepción, sube el documento firmado y lo archiva. Siempre se sabe dónde está cada original.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Onboarding',
        pantalla: 'Desarrollo → Onboarding',
        ruta: '/rh/onboarding/configuracion',
        captura: '23-onboarding-config.png',
        accion: 'Mostrar las lecciones (institucional y por puesto) y el equipo de trabajo.',
        zoom: 'Zoom a una lección',
        textoPantalla: 'Lecciones de bienvenida · calificación mínima 8',
        narracion:
            'Con los contratos firmados empieza el onboarding: la bienvenida del colaborador nuevo. Recursos Humanos define lecciones institucionales y del puesto, con su material, sus instrucciones y una evaluación, y el equipo que se entrega.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Onboarding',
        pantalla: 'Ciclo laboral → onboarding',
        ruta: '/rh/colaboradores/:colaborador/ciclo',
        captura: '24-onboarding-cierre.png',
        accion: 'Mostrar el avance de lecciones, la entrega de activos y «Cerrar onboarding».',
        zoom: 'Zoom al botón Cerrar onboarding',
        textoPantalla: 'Alta real → aviso a RH y a Sistemas',
        narracion:
            'El colaborador responde sus lecciones desde la app. Si no alcanza la calificación, Recursos Humanos le deja retroalimentación y vuelve a intentarlo. Con las lecciones aprobadas y el equipo entregado, Recursos Humanos cierra el onboarding y la persona queda activa. En ese momento se avisa a Recursos Humanos y a Sistemas, para preparar su cuenta, accesos y equipo.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Evaluación',
        pantalla: 'Evaluaciones de periodo de prueba',
        ruta: '/rh/evaluaciones',
        captura: '25-evaluaciones.png',
        accion: 'Mostrar las pestañas Próximas / por asignar, En proceso y Evaluadas; asignar una evaluación, ver respuestas e imprimir el PDF para firma.',
        zoom: 'Zoom a los criterios',
        textoPantalla: '15 días antes del vencimiento',
        narracion:
            'Quince días antes de que termine la capacitación, el sistema abre la evaluación y avisa al gerente. El gerente califica cada criterio, comenta y recomienda si la persona continúa. Recursos Humanos autoriza la decisión final.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Contrato indeterminado',
        pantalla: 'Ciclo laboral → contrato indeterminado',
        ruta: '/rh/colaboradores/:colaborador/ciclo',
        captura: '26-contrato-indeterminado.png',
        accion: 'Mostrar «Renovación autorizada» y el contrato por tiempo indeterminado listo para previsualizar e imprimir.',
        zoom: 'Zoom al documento',
        textoPantalla: '✓ Evaluación aprobada · contrato indeterminado listo',
        narracion:
            'Si la evaluación se aprueba, el sistema prepara el contrato por tiempo indeterminado, listo para previsualizar e imprimir. Pulsar dos veces no lo duplica. Si no se renueva, se abre el cierre laboral por no renovación.',
        transicion: 'Fundido',
    },
    {
        capitulo: 'Solicitudes, nómina y avisos',
        pantalla: 'Personal → Solicitudes',
        ruta: '/rh/solicitudes',
        captura: '27-solicitudes.png',
        accion: 'Mostrar los tipos, abrir el tablero de permisos y una solicitud con su cadena de autorización.',
        zoom: 'Zoom a la cadena de autorización',
        textoPantalla: 'Gerente → Regional → RH autoriza',
        narracion:
            'Desde la app, el colaborador pide permisos, vacaciones y préstamos. El gerente y el regional dan su visto bueno y Recursos Humanos autoriza. En vacaciones el domingo no cuenta y el sábado sí, y la persona ve su saldo antes y después. El formato de permiso solo se imprime cuando Recursos Humanos ya lo autorizó.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Solicitudes, nómina y avisos',
        pantalla: 'Personal → Recibos de nómina',
        ruta: '/rh/nomina/lotes',
        captura: '28-nomina-lotes.png',
        accion: 'Preparar un lote quincenal; abrirlo y recorrer recibos con Anterior y Siguiente.',
        zoom: 'Zoom al resumen del lote',
        textoPantalla: 'Preparar ≠ publicar',
        narracion:
            'Los recibos de nómina son recibos simples, no timbrados. Recursos Humanos prepara el lote semanal o quincenal y todos quedan como borrador: nadie los ve y no se avisa a nadie. Después revisa el resumen y cada recibo con su PDF real.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Solicitudes, nómina y avisos',
        pantalla: 'Lote de nómina → Emitir',
        ruta: '/rh/nomina/lotes/:lote',
        captura: '29-nomina-emitir.png',
        accion: 'Pulsar «Emitir N recibos» e imprimir el PDF.',
        zoom: 'Zoom al botón Emitir',
        textoPantalla: 'Solo al emitir se congela el PDF',
        narracion:
            'Solo al pulsar Emitir se congela el PDF de cada recibo y se guarda en el expediente; Recursos Humanos lo imprime y lo entrega. El colaborador no lo ve en la app ni recibe aviso. Esta separación evita que alguien reciba un recibo con errores.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Solicitudes, nómina y avisos',
        pantalla: 'Personal → Avisos',
        ruta: '/rh/avisos',
        captura: '30-avisos.png',
        accion: 'Redactar un aviso para toda la empresa con imagen; mostrar Celebraciones.',
        zoom: 'Zoom al formulario',
        textoPantalla: 'Toda la empresa o un colaborador',
        narracion:
            'Recursos Humanos envía avisos con título, mensaje e imagen a toda la empresa o a una persona; llegan por push y quedan en su bandeja. Los cumpleaños y aniversarios se felicitan solos a las ocho de la mañana.',
        transicion: 'Fundido',
    },
    {
        capitulo: 'Baja, finiquito y reingreso',
        pantalla: 'Ciclo laboral → Iniciar baja',
        ruta: '/rh/colaboradores/:colaborador/ciclo',
        captura: '31-baja.png',
        accion: 'Abrir «Iniciar baja», elegir el tipo, el motivo y la fecha efectiva.',
        zoom: 'Zoom al diálogo',
        textoPantalla: 'El gerente o RH la registra → documentos',
        narracion:
            'La baja no es una solicitud ni requiere autorización: el gerente o Recursos Humanos la registra en «Bajas» (o desde el ciclo laboral) después de ver la vista previa de la renuncia y del finiquito. Al registrarla, el acceso de la persona se suspende de inmediato, pero nunca se borra nada.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Baja, finiquito y reingreso',
        pantalla: 'Solicitud de baja → Finiquito',
        ruta: '/rh/solicitudes/:solicitud',
        captura: '32-finiquito.png',
        accion: 'Mostrar los conceptos del finiquito, ajustar uno con motivo y ver la vista previa.',
        zoom: 'Zoom al desglose',
        textoPantalla:
            'Sueldo pendiente · aguinaldo · vacaciones · prima · ISR',
        narracion:
            'El finiquito se calcula con sueldo pendiente, aguinaldo proporcional, vacaciones, prima vacacional e ISR. Recursos Humanos revisa, ajusta los conceptos autorizados con su motivo, genera el PDF y, al registrar su entrega, el cierre queda completo. Recursos Humanos y Sistemas reciben el aviso para retirar accesos y equipo.',
        transicion: 'Corte',
    },
    {
        capitulo: 'Baja, finiquito y reingreso',
        pantalla: 'Desarrollo → Reingresos',
        ruta: '/rh/reingresos',
        captura: '33-reingresos.png',
        accion: 'Buscar a una persona dada de baja y mostrar su causa de salida.',
        zoom: 'Zoom al resultado',
        textoPantalla: 'Misma persona · nuevo ciclo laboral',
        narracion:
            'Si alguien regresa, no se crea una persona nueva: se busca en Reingresos, Recursos Humanos decide, y la misma persona inicia un nuevo ciclo laboral conservando todo su historial.',
        transicion: 'Fundido',
    },
    {
        capitulo: 'Cierre',
        pantalla: 'Ayuda → Guía del sistema',
        ruta: '/ayuda',
        captura: '34-guia.png',
        accion: 'Mostrar el centro de Guía: buscador, recorrido completo, procesos y roles.',
        zoom: 'Zoom al buscador',
        textoPantalla: '¿Cómo contrato un candidato? → artículo',
        narracion:
            'Siempre que tengas una duda, abre Ayuda. Puedes buscar con tus propias palabras, por ejemplo cómo contrato un candidato, ver el recorrido completo, la guía por proceso o lo que te toca según tu rol. En cada pantalla importante, el botón Cómo funciona te lleva directo a su explicación.',
        transicion: 'Fundido a portada final',
    },
    {
        capitulo: 'Cierre',
        pantalla: 'Portada final',
        ruta: null,
        captura: '35-cierre.png',
        accion: 'Portada final con el mensaje de cierre.',
        zoom: 'Sin zoom',
        textoPantalla:
            'Qué hacer · dónde hacerlo · quién lo hace · qué pasa después',
        narracion:
            'Con MR. LANA PEOPLE cada persona sabe qué hacer, dónde hacerlo, quién lo hace y qué pasa después. Gracias por ver este recorrido.',
        transicion: 'Fin',
    },
];

function contarPalabras(texto: string): number {
    return texto.trim().split(/\s+/).filter(Boolean).length;
}

/** Duración de una escena (segundos, redondeada a medio segundo). */
export function duracionEscena(narracion: string): number {
    const segundos = contarPalabras(narracion) / PALABRAS_POR_SEGUNDO + PAUSA;

    return Math.ceil(segundos * 2) / 2;
}

/** Escenas numeradas con su inicio y fin calculados. */
export function escenasVideo(): EscenaVideo[] {
    let tiempo = 0;

    return ESCENAS.map((escena, i) => {
        const inicio = tiempo;
        tiempo += duracionEscena(escena.narracion);

        return { ...escena, numero: i + 1, inicio, fin: tiempo };
    });
}

/** Capítulos (primera escena de cada uno) para el índice del video. */
export function capitulosVideo(): { titulo: string; inicio: number }[] {
    const vistos = new Set<string>();

    return escenasVideo()
        .filter((e) =>
            vistos.has(e.capitulo) ? false : (vistos.add(e.capitulo), true),
        )
        .map((e) => ({ titulo: e.capitulo, inicio: e.inicio }));
}

export function formatoTiempo(segundos: number): string {
    const total = Math.floor(segundos);
    const m = Math.floor(total / 60);
    const s = total % 60;

    return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

/** Guiones de los videos cortos (1–3 minutos). */
/** Segundos de demostración por escena en los videos cortos. */
const PAUSA_CORTO = 5;

/** Duración de una escena de video corto (misma regla que el completo). */
export function duracionEscenaCorto(narracion: string): number {
    return (
        Math.ceil(
            (contarPalabras(narracion) / PALABRAS_POR_SEGUNDO + PAUSA_CORTO) *
                2,
        ) / 2
    );
}

type MiniBase = Omit<MiniVideo, 'duracion'>;

const MINIS: MiniBase[] = [
    {
        id: 'contratar-candidato',
        titulo: 'Cómo contratar un candidato',
        articulo: 'iniciar-contratacion',
        escenas: [
            {
                captura: '09-candidato-ficha.png',
                pantalla: 'Introducción',
                accion: 'Ficha de un candidato con sus pasos.',
                narracion:
                    'En este video vas a ver cómo convertir a un candidato autorizado en una persona en contratación, y cómo entregarle su código QR para que se registre en la app.',
            },
            {
                captura: '15-generar-qr.png',
                pantalla: 'Candidatos → ficha',
                accion: 'Mostrar candidato «Autorizado por RH».',
                narracion:
                    'Cuando Recursos Humanos ya autorizó a un candidato, en su ficha aparece el botón Generar QR de contratación. Antes de esa autorización el botón no existe, así que nadie puede saltarse el proceso.',
            },
            {
                captura: '15-generar-qr.png',
                pantalla: 'Diálogo de contratación',
                accion: 'Capturar sueldo, fecha de ingreso y modalidad.',
                narracion:
                    'Captura el sueldo mensual y la fecha de ingreso, y deja la modalidad de capacitación inicial. La sucursal, el departamento y el puesto salen de la vacante; no se vuelven a escribir. Si dejas vacío el fin del periodo, se usa la duración configurada para el puesto.',
            },
            {
                captura: '16-invitacion-qr.png',
                pantalla: 'Confirmación',
                accion: 'Confirmar y mostrar el estado «En contratación».',
                narracion:
                    'Al confirmar, el sistema revisa que la plaza siga libre, crea a la persona sin duplicarla, ocupa la plaza, copia su currículum al expediente y genera su QR.',
            },
            {
                captura: '16-invitacion-qr.png',
                pantalla: 'Invitación QR',
                accion: 'Copiar liga y descargar PNG.',
                narracion:
                    'El QR dura máximo veinticuatro horas y es de un solo uso. Por seguridad solo se muestra unos minutos: cópialo, descárgalo o compártelo en ese momento. Si vence, desde Invitaciones QR generas uno nuevo.',
            },
            {
                captura: '14-intervencion.png',
                pantalla: 'Errores comunes',
                accion: 'Mostrar los avisos de error.',
                narracion:
                    'Si es un candidato espontáneo, primero vincúlalo a una vacante. Si la vacante ya se cubrió, vincúlalo a otra con plaza disponible. Y si el puesto no tiene duración configurada, Gerencia de RH la captura en Parámetros de RH.',
            },
        ],
    },
    {
        id: 'aprobar-permiso',
        titulo: 'Cómo aprobar un permiso',
        articulo: 'revisar-solicitudes',
        escenas: [
            {
                captura: '27-solicitudes.png',
                pantalla: 'Introducción',
                accion: 'Tablero de solicitudes.',
                narracion:
                    'En este video vas a ver el camino completo de un permiso: quién lo pide, quién da el visto bueno y en qué momento Recursos Humanos lo autoriza.',
            },
            {
                captura: '18-app-documentos.png',
                pantalla: 'App del colaborador',
                accion: 'Pedir un permiso desde Mis solicitudes.',
                narracion:
                    'El colaborador pide su permiso desde la app: elige el tipo, la fecha, la hora y si es con goce, sin goce o especial. Puede seguir el avance de su solicitud en todo momento.',
            },
            {
                captura: '27-solicitudes.png',
                pantalla: 'App del gerente → Equipo',
                accion: 'Visto bueno del gerente y del regional.',
                narracion:
                    'Primero el gerente de la sucursal da su visto bueno; después lo da el gerente regional. Cualquiera de los dos puede pedir una corrección con comentario.',
            },
            {
                captura: '27-solicitudes.png',
                pantalla: 'Web → Solicitudes → Permisos',
                accion: 'Abrir la solicitud y revisar la cadena.',
                narracion:
                    'Con ambos vistos buenos, la solicitud pasa sola a Pendiente de autorizar. Recursos Humanos la abre y revisa la cadena completa: quién la pidió, quién la avaló y cuándo.',
            },
            {
                captura: '27-solicitudes.png',
                pantalla: 'Acciones de revisión',
                accion: 'Aprobar, pedir corrección o rechazar.',
                narracion:
                    'Recursos Humanos aprueba, pide corrección o rechaza. Solo después de aprobar, el formato de permiso queda listo para imprimir y firmar; antes no se puede descargar.',
            },
        ],
    },
    {
        id: 'generar-nomina',
        titulo: 'Cómo generar nómina',
        articulo: 'nomina-lotes',
        escenas: [
            {
                captura: '28-nomina-lotes.png',
                pantalla: 'Introducción',
                accion: 'Pantalla de lotes de nómina.',
                narracion:
                    'En este video vas a ver cómo se prepara, se revisa y se emite un lote de recibos de nómina, y por qué nada se publica hasta que tú lo decides.',
            },
            {
                captura: '28-nomina-lotes.png',
                pantalla: 'Recibos de nómina',
                accion: 'Elegir periodicidad y preparar.',
                narracion:
                    'Elige la periodicidad: semanal de lunes a domingo, o quincenal del uno al quince y del dieciséis al último día del mes. Después pulsa Preparar lote.',
            },
            {
                captura: '29-nomina-emitir.png',
                pantalla: 'Lote',
                accion: 'Mostrar el resumen del lote.',
                narracion:
                    'Todos los recibos quedan como borrador. Revisa el resumen: cuántos se esperaban, cuántos están correctos y cuántos tienen error, por ejemplo por falta de sueldo en datos laborales.',
            },
            {
                captura: '29-nomina-emitir.png',
                pantalla: 'Revisión',
                accion: 'Recorrer recibos con Anterior y Siguiente.',
                narracion:
                    'Revisa recibo por recibo con su PDF real, usando Anterior y Siguiente. Si algo no cuadra, edita los conceptos de ese recibo antes de emitir.',
            },
            {
                captura: '29-nomina-emitir.png',
                pantalla: 'Emitir',
                accion: 'Pulsar «Emitir N recibos».',
                narracion:
                    'Solo al emitir se congelan los PDF y se guardan en el expediente para imprimirlos y entregarlos. La emisión automática viene apagada: siempre la confirma una persona.',
            },
        ],
    },
    {
        id: 'completar-expediente',
        titulo: 'Cómo completar un expediente',
        articulo: 'completitud-expediente',
        escenas: [
            {
                captura: '19-expediente.png',
                pantalla: 'Introducción',
                accion: 'Cabecera del expediente.',
                narracion:
                    'En este video vas a ver cómo saber qué le falta a un expediente y cómo pedirle al colaborador que lo complete desde su celular, sin capturar tú sus datos.',
            },
            {
                captura: '19-expediente.png',
                pantalla: 'Expediente',
                accion: 'Mostrar el semáforo por secciones.',
                narracion:
                    'El expediente muestra qué sección está completa y cuál falta: identidad, datos fiscales, laborales, domicilio, contacto, contacto de emergencia, beneficiario y documentos.',
            },
            {
                captura: '20-expediente-faltantes.png',
                pantalla: 'Avisar al colaborador',
                accion: 'Pulsar «Avisar al colaborador».',
                narracion:
                    'Con Avisar al colaborador, la persona recibe una notificación y un aviso en su celular con la lista exacta de lo que falta.',
            },
            {
                captura: '18-app-documentos.png',
                pantalla: 'App → Completa tu información',
                accion: 'Capturar datos y subir documentos.',
                narracion:
                    'Desde la app captura lo que falta, sube sus documentos y lo envía a Recursos Humanos. Mientras tanto, nada cambia en su expediente.',
            },
            {
                captura: '27-solicitudes.png',
                pantalla: 'Web → Solicitud de actualización',
                accion: 'Comparar y autorizar.',
                narracion:
                    'Recursos Humanos compara el dato anterior con el nuevo y autoriza. Al autorizar, el expediente se actualiza solo y el semáforo cambia a verde.',
            },
        ],
    },
    {
        id: 'evaluar-capacitacion',
        titulo: 'Cómo evaluar la capacitación inicial',
        articulo: 'evaluacion-capacitacion',
        escenas: [
            {
                captura: '25-evaluaciones.png',
                pantalla: 'Introducción',
                accion: 'Lista de evaluaciones.',
                narracion:
                    'En este video vas a ver cómo se decide si una persona continúa al terminar su contrato de capacitación inicial, y cómo se genera su contrato por tiempo indeterminado.',
            },
            {
                captura: '25-evaluaciones.png',
                pantalla: 'Evaluaciones de periodo de prueba',
                accion: 'Mostrar la lista con fecha límite.',
                narracion:
                    'Quince días antes del vencimiento, la evaluación aparece sola en la lista y el jefe directo recibe el aviso. Cada renglón muestra su fecha límite.',
            },
            {
                captura: '25-evaluaciones.png',
                pantalla: 'Ciclo laboral → Evaluar',
                accion: 'Calificar los siete criterios.',
                narracion:
                    'El jefe califica los siete criterios de cero a diez, escribe sus comentarios y recomienda renovar o no renovar.',
            },
            {
                captura: '26-contrato-indeterminado.png',
                pantalla: 'Autorización final de RH',
                accion: 'Autorizar renovar.',
                narracion:
                    'Recursos Humanos toma la decisión final. Si decide distinto a la recomendación del jefe, el comentario es obligatorio. Si no se renueva, se abre el cierre laboral.',
            },
            {
                captura: '26-contrato-indeterminado.png',
                pantalla: 'Contrato indeterminado',
                accion: 'Mostrar el documento listo.',
                narracion:
                    'Con la renovación autorizada, el contrato por tiempo indeterminado se genera una sola vez y queda listo para previsualizar, imprimir y firmar.',
            },
        ],
    },
    {
        id: 'hacer-baja',
        titulo: 'Cómo hacer una baja',
        articulo: 'baja',
        escenas: [
            {
                captura: '19-expediente.png',
                pantalla: 'Expediente → Ciclo laboral',
                accion: 'Botón «Ciclo laboral» del expediente.',
                narracion:
                    'Abre el expediente de la persona y entra a su ciclo laboral. Ahí vive la baja completa: documentos, finiquito y cierre.',
            },
            {
                captura: '31-baja.png',
                pantalla: 'Iniciar baja',
                accion: 'Capturar causa, motivo y fecha efectiva.',
                narracion:
                    'Inicia la baja con su causa, motivo y fecha efectiva, y adjunta la evidencia. El acceso de la persona al sistema y a la app se suspende de inmediato.',
            },
            {
                captura: '31-baja.png',
                pantalla: 'Documentos de la baja',
                accion: 'Generar los documentos de la baja.',
                narracion:
                    'No hay preautorización ni una autorización posterior de Recursos Humanos: se generan los documentos de la baja. En cada paso queda registrado quién lo hizo y cuándo.',
            },
            {
                captura: '32-finiquito.png',
                pantalla: 'Finiquito',
                accion: 'Calcular y ajustar conceptos.',
                narracion:
                    'Recursos Humanos calcula el finiquito con su vista previa editable y ajusta conceptos si hace falta.',
            },
            {
                captura: '32-finiquito.png',
                pantalla: 'Registrar entrega',
                accion: 'Registrar la entrega del finiquito.',
                narracion:
                    'Se entrega el finiquito a la persona, que lo firma, y se registra la entrega en el sistema.',
            },
            {
                captura: '31-baja.png',
                pantalla: 'Concluir',
                accion: 'Concluir el cierre.',
                narracion:
                    'Con la entrega registrada se concluye el cierre. Recursos Humanos y Sistemas reciben el aviso para retirar accesos y equipo. Nada se borra: el historial queda para un posible reingreso.',
            },
        ],
    },
];

/** Videos cortos (1 a 3 min) con su duración calculada del guion. */
export const MINI_VIDEOS: MiniVideo[] = MINIS.map((mini) => ({
    ...mini,
    duracion: formatoTiempo(
        mini.escenas.reduce(
            (total, e) => total + duracionEscenaCorto(e.narracion),
            0,
        ),
    ),
}));
