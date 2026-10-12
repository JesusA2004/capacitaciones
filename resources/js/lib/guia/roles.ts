import type { RolGuia } from './tipos';

/**
 * «¿Qué hago según mi rol?». Basado en los permisos BASE de
 * database/seeders/RolesYPermisosSeeder.php (un administrador puede
 * ajustarlos en Roles y permisos). Cada punto del checklist abre su artículo.
 */
export const ROLES_GUIA: RolGuia[] = [
    {
        id: 'rh',
        nombre: 'Reclutamiento / RH',
        rolesSistema: ['rh_admin', 'rh_auxiliar'],
        icono: 'Users',
        resumen:
            'Lleva el proceso de punta a punta: vacantes, campañas, candidatos, autorizaciones, contratación, expedientes, contratos, nómina y bajas. Gerencia de RH (rh_admin) además autoriza y configura; el auxiliar opera sin decisiones finales.',
        dondeTrabaja: 'Web (principal) y app en modo Gestión RH.',
        noLeToca: [
            'Decidir intervenciones sobre sus propios rechazos (eso es del Regional o de Dirección Comercial).',
            'Editar su propio expediente.',
        ],
        checklist: [
            {
                texto: 'Revisar vacantes reales',
                articulo: 'plantilla-y-vacantes',
            },
            {
                texto: 'Crear campañas sobre vacantes reales',
                articulo: 'campanas',
            },
            { texto: 'Registrar candidatos', articulo: 'registrar-candidato' },
            { texto: 'Filtro RH y psicométricos', articulo: 'psicometricos' },
            {
                texto: 'Autorización final de candidatos',
                articulo: 'autorizacion-rh',
            },
            {
                texto: 'Generar el QR de contratación',
                articulo: 'iniciar-contratacion',
            },
            {
                texto: 'Revisar documentos y datos del alta',
                articulo: 'documentos-de-alta',
            },
            {
                texto: 'Expedientes y datos faltantes',
                articulo: 'completitud-expediente',
            },
            { texto: 'Generar contratos', articulo: 'contrato-inicial' },
            { texto: 'Cerrar onboarding (alta real)', articulo: 'onboarding' },
            {
                texto: 'Autorizar evaluaciones',
                articulo: 'evaluacion-capacitacion',
            },
            { texto: 'Autorizar solicitudes', articulo: 'revisar-solicitudes' },
            { texto: 'Preparar y emitir nómina', articulo: 'nomina-lotes' },
            { texto: 'Enviar avisos', articulo: 'avisos' },
            { texto: 'Bajas y finiquitos', articulo: 'baja' },
            { texto: 'Reingresos', articulo: 'reingreso' },
        ],
    },
    {
        id: 'gerente',
        nombre: 'Gerente de sucursal',
        rolesSistema: [
            'gerente_sucursal',
            'gerente',
            'subgerente',
            'jefe_directo',
        ],
        icono: 'Building2',
        resumen:
            'Decide sobre los candidatos de su sucursal, da visto bueno a las solicitudes de su equipo, imprime y recaba firmas, entrega equipo y evalúa la capacitación inicial.',
        dondeTrabaja: 'App (Gestión RH: Pendientes, Equipo) y web.',
        noLeToca: [
            'Autorizar en definitiva (siempre es RH).',
            'Imprimir un permiso antes de que RH lo autorice.',
            'Capturar campañas o la plantilla autorizada.',
        ],
        checklist: [
            {
                texto: 'Entrevistas de candidatos',
                articulo: 'entrevista-gerente',
            },
            { texto: 'Revisar psicométricos', articulo: 'psicometricos' },
            { texto: 'Estudio socioeconómico', articulo: 'socioeconomico' },
            { texto: 'Preautorizar contratación', articulo: 'autorizacion-rh' },
            {
                texto: 'Solicitar intervención (si RH rechaza)',
                articulo: 'intervencion',
            },
            {
                texto: 'Imprimir, firmar y enviar contratos',
                articulo: 'firma-y-original',
            },
            { texto: 'Entregar equipo (onboarding)', articulo: 'onboarding' },
            {
                texto: 'Evaluación de capacitación inicial',
                articulo: 'evaluacion-capacitacion',
            },
            {
                texto: 'Visto bueno de permisos y vacaciones',
                articulo: 'revisar-solicitudes',
            },
            { texto: 'Solicitar bajas de su equipo', articulo: 'baja' },
            { texto: 'Mis pendientes', articulo: 'mis-pendientes' },
        ],
    },
    {
        id: 'regional',
        nombre: 'Gerente Regional',
        rolesSistema: ['gerente_regional', 'coordinadora_regional'],
        icono: 'MapPinned',
        resumen:
            'Monitorea las sucursales de su región, da el segundo visto bueno de solicitudes, preautoriza bajas y decide intervenciones de Gestor o Gestor Volante cuando el gerente las pide.',
        dondeTrabaja: 'App y web, con alcance de región.',
        noLeToca: [
            'Aprobar cada candidato: en el caso normal NO interviene en reclutamiento.',
            'Decidir intervenciones de puestos superiores (eso es de Dirección Comercial).',
        ],
        checklist: [
            {
                texto: 'Visto bueno de solicitudes de su región',
                articulo: 'revisar-solicitudes',
            },
            {
                texto: 'Intervenciones de Gestor / Volante (solo si se piden)',
                articulo: 'intervencion',
            },
            { texto: 'Preautorizar bajas', articulo: 'baja' },
            {
                texto: 'Programar el pago del finiquito (coordinación regional)',
                articulo: 'finiquito',
            },
            {
                texto: 'Monitorear vacantes de la región',
                articulo: 'plantilla-y-vacantes',
            },
        ],
    },
    {
        id: 'direccion_comercial',
        nombre: 'Dirección Comercial',
        rolesSistema: ['director_comercial'],
        icono: 'Landmark',
        resumen:
            'Consulta global de indicadores, vacantes y candidatos. Decide únicamente las intervenciones de puestos superiores a Gestor/Volante cuando un gerente las solicita.',
        dondeTrabaja: 'Web y app (consulta).',
        noLeToca: [
            'Aprobar candidatos normales, solicitudes o vacaciones.',
            'Intervenciones de Gestor o Gestor Volante (son del Regional).',
        ],
        checklist: [
            {
                texto: 'Intervenciones de puestos superiores',
                articulo: 'intervencion',
            },
            { texto: 'Consultar vacantes', articulo: 'plantilla-y-vacantes' },
            {
                texto: 'Consultar el embudo de candidatos',
                articulo: 'etapas-del-candidato',
            },
        ],
    },
    {
        id: 'sistemas',
        nombre: 'Sistemas',
        rolesSistema: ['sistemas'],
        icono: 'Monitor',
        resumen:
            'Recibe cada alta y baja: prepara cuenta, accesos y equipo, y los retira al salir. Administra roles, cuentas y versiones de la app. No ve expedientes ni nómina.',
        dondeTrabaja: 'Web (Administración).',
        noLeToca: ['Expedientes, documentos personales y datos de nómina.'],
        checklist: [
            {
                texto: 'Alta: preparar cuenta, accesos y equipo',
                articulo: 'alta-activa',
            },
            { texto: 'Baja: retirar accesos y equipo', articulo: 'baja' },
            {
                texto: 'Cuentas, credenciales y roles',
                articulo: 'usuarios-y-roles',
            },
            {
                texto: 'A quién llega cada notificación',
                articulo: 'configuracion-notificaciones',
            },
            { texto: 'Publicar versiones de la app', articulo: 'app-movil' },
        ],
    },
    {
        id: 'colaborador',
        nombre: 'Colaborador',
        rolesSistema: ['colaborador'],
        icono: 'UserRound',
        resumen:
            'Usa la app (o «Mi espacio» en la web) para completar su expediente, responder sus lecciones, pedir permisos, vacaciones y préstamos, y ver avisos y recibos.',
        dondeTrabaja: 'App MR. LANA PEOPLE (y portal web).',
        noLeToca: ['Editar su expediente directo (propone y RH autoriza).'],
        checklist: [
            { texto: 'Registrarme con el QR', articulo: 'registro-en-app' },
            {
                texto: 'Iniciar sesión y usar la app',
                articulo: 'app-colaborador',
            },
            { texto: 'Subir mis documentos', articulo: 'documentos-de-alta' },
            { texto: 'Completar mis datos', articulo: 'completar-datos' },
            { texto: 'Lecciones de bienvenida', articulo: 'onboarding' },
            { texto: 'Pedir un permiso', articulo: 'permisos' },
            { texto: 'Pedir vacaciones', articulo: 'vacaciones' },
            { texto: 'Pedir un préstamo', articulo: 'prestamos' },
            { texto: 'Ver mis recibos', articulo: 'mis-recibos' },
            { texto: 'Avisos', articulo: 'avisos' },
            { texto: 'Cumpleaños y aniversarios', articulo: 'celebraciones' },
        ],
    },
];
