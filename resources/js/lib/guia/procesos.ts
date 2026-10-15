import type { ProcesoGuia } from './tipos';

/**
 * Las 14 tarjetas de «Guía organizada por proceso». El orden es el del
 * ciclo real: de la plaza autorizada a la vida laboral y la salida.
 */
export const PROCESOS: ProcesoGuia[] = [
    {
        id: 'reclutamiento',
        numero: 1,
        titulo: 'Reclutamiento',
        icono: 'Megaphone',
        resumen:
            'De la plaza autorizada que falta a un candidato autorizado por RH: vacante real, campaña, filtro RH, entrevista, psicométricos, socioeconómico, referencias y autorización.',
        tiempo: '1 a 3 semanas por candidato',
        roles: ['rh', 'gerente', 'regional', 'direccion_comercial'],
        articulos: [
            'plantilla-y-vacantes',
            'campanas',
            'registrar-candidato',
            'etapas-del-candidato',
            'filtro-rh',
            'entrevista-gerente',
            'psicometricos',
            'socioeconomico',
            'referencias',
            'autorizacion-rh',
            'intervencion',
        ],
    },
    {
        id: 'contratacion',
        numero: 2,
        titulo: 'Contratación',
        icono: 'QrCode',
        resumen:
            'RH genera el QR de contratación: PEOPLE valida que la plaza siga libre, crea a la persona sin duplicarla y le entrega un QR temporal de un solo uso.',
        tiempo: '5 minutos (RH) · el QR vive 24 h',
        roles: ['rh'],
        articulos: ['iniciar-contratacion', 'qr-incorporacion'],
    },
    {
        id: 'alta',
        numero: 3,
        titulo: 'Alta del colaborador',
        icono: 'UserPlus',
        resumen:
            'La persona escanea el QR, crea su cuenta en la app, sube sus documentos y propone sus datos; RH revisa y aprueba. El alta queda activa al cerrar el onboarding.',
        tiempo: '1 a 5 días',
        roles: ['colaborador', 'rh', 'sistemas'],
        articulos: [
            'registro-en-app',
            'documentos-de-alta',
            'candidato-a-colaborador',
            'alta-activa',
        ],
    },
    {
        id: 'expediente',
        numero: 4,
        titulo: 'Expediente',
        icono: 'FolderKanban',
        resumen:
            'La carpeta digital de cada persona: datos, documentos, contratos, solicitudes, avisos e historial. Muestra qué falta por sección y permite avisarle al colaborador.',
        tiempo: 'Consulta inmediata',
        roles: ['rh', 'gerente', 'colaborador'],
        articulos: ['expediente', 'completitud-expediente', 'completar-datos'],
    },
    {
        id: 'contratos',
        numero: 5,
        titulo: 'Contratos',
        icono: 'FileSignature',
        resumen:
            'PEOPLE llena el contrato de capacitación inicial con los datos del expediente, con la duración que define cada puesto, y controla su firma y el original físico.',
        tiempo: '1 a 3 días (firma y envío del original)',
        roles: ['rh', 'gerente'],
        articulos: ['contrato-inicial', 'firma-y-original'],
    },
    {
        id: 'onboarding',
        numero: 6,
        titulo: 'Onboarding',
        icono: 'Sparkles',
        resumen:
            'Bienvenida del colaborador nuevo: lecciones institucionales y del puesto, con evaluación, y entrega del equipo de trabajo con su carta responsiva.',
        tiempo: '1 a 2 semanas',
        roles: ['colaborador', 'rh', 'gerente'],
        articulos: ['onboarding', 'induccion-mr-lana', 'configurar-onboarding'],
    },
    {
        id: 'evaluacion',
        numero: 7,
        titulo: 'Evaluación inicial',
        icono: 'ClipboardCheck',
        resumen:
            '15 días antes de que termine la capacitación inicial, el jefe evalúa; RH autoriza y PEOPLE prepara el contrato por tiempo indeterminado (o la no renovación).',
        tiempo: '15 días antes del vencimiento',
        roles: ['gerente', 'rh'],
        articulos: ['evaluacion-capacitacion', 'contrato-indeterminado'],
    },
    {
        id: 'solicitudes',
        numero: 8,
        titulo: 'Solicitudes',
        icono: 'ClipboardList',
        resumen:
            'Permisos, vacaciones y préstamos: el colaborador pide desde la app o el portal, el gerente y el regional dan visto bueno y RH autoriza.',
        tiempo: '1 a 3 días hábiles',
        roles: ['colaborador', 'gerente', 'regional', 'rh'],
        articulos: [
            'permisos',
            'vacaciones',
            'prestamos',
            'revisar-solicitudes',
        ],
    },
    {
        id: 'nomina',
        numero: 9,
        titulo: 'Nómina',
        icono: 'ReceiptText',
        resumen:
            'Recibos simples (no CFDI) por lote: semanal para sucursales y quincenal para corporativo. PEOPLE prepara, RH revisa uno por uno, emite, imprime y entrega el recibo impreso.',
        tiempo: '30 a 60 minutos por lote',
        roles: ['rh'],
        articulos: ['nomina-lotes'],
    },
    {
        id: 'avisos',
        numero: 10,
        titulo: 'Avisos',
        icono: 'BellRing',
        resumen:
            'Comunicados de RH con título, mensaje e imagen, para toda la empresa o para una persona. Llegan por notificación y push y quedan en su expediente.',
        tiempo: '2 minutos',
        roles: ['rh', 'colaborador'],
        articulos: ['avisos'],
    },
    {
        id: 'vida-laboral',
        numero: 11,
        titulo: 'Vida laboral',
        icono: 'PartyPopper',
        resumen:
            'Lo que pasa mientras la persona trabaja: cumpleaños y aniversarios, cambios de puesto o sucursal registrados en su historial y el uso diario de la app.',
        tiempo: 'Continuo',
        roles: ['colaborador', 'rh', 'gerente'],
        articulos: [
            'app-colaborador',
            'directorio',
            'celebraciones',
            'actas',
            'actividad-laboral',
        ],
    },
    {
        id: 'baja',
        numero: 12,
        titulo: 'Baja y finiquito',
        icono: 'UserMinus',
        resumen:
            'Cierre laboral: el jefe directo (o RH) inicia la baja desde el colaborador, sin solicitud previa; RH autoriza, calcula y autoriza el finiquito, se programa el pago, se firma y se ejecuta la baja.',
        tiempo: '1 a 3 semanas',
        roles: ['gerente', 'regional', 'rh', 'sistemas'],
        articulos: ['baja', 'finiquito'],
    },
    {
        id: 'reingresos',
        numero: 13,
        titulo: 'Reingresos',
        icono: 'RotateCcw',
        resumen:
            'Volver a contratar a alguien sin crear otra persona: misma cuenta y mismo historial, un ciclo laboral nuevo.',
        tiempo: '1 a 5 días',
        roles: ['gerente', 'rh'],
        articulos: ['reingreso'],
    },
    {
        id: 'administracion',
        numero: 14,
        titulo: 'Administración',
        icono: 'Settings2',
        resumen:
            'Estructura (empresas, sucursales, puestos, plantilla autorizada), cuentas y roles, notificaciones, documentos maestros, la app y la bandeja Mis pendientes.',
        tiempo: 'Según el ajuste',
        roles: ['rh', 'sistemas'],
        articulos: [
            'mis-pendientes',
            'estructura-y-plantilla',
            'usuarios-y-roles',
            'configuracion-notificaciones',
            'documentos-maestros',
            'app-movil',
            'directorio',
            'importador-salarios',
            'historial-persona',
        ],
    },
];
