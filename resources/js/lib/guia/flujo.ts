import type { BloqueFlujo } from './tipos';

/**
 * Diagrama general «de la necesidad de personal a colaborador activo».
 * Sigue el flujo REAL del código, que agrega dos pasos al esquema original:
 * Referencias y Preautorización del gerente (EstadoCandidato), y deja el
 * alta activa al cerrar el onboarding (AltaColaboradorService::activar()).
 */
export const FLUJO: BloqueFlujo[] = [
    {
        id: 'plantilla',
        titulo: 'Plantilla',
        etapa: 'Reclutamiento',
        quien: ['rh'],
        canal: 'web',
        resumen:
            'Gerencia de RH mantiene cuántas plazas autorizó Dirección por sucursal y puesto.',
        articulo: 'estructura-y-plantilla',
    },
    {
        id: 'vacante',
        titulo: 'Vacante',
        etapa: 'Reclutamiento',
        quien: ['rh'],
        canal: 'automatico',
        resumen:
            'PEOPLE calcula plantilla autorizada − ocupados. Nadie la captura a mano.',
        articulo: 'plantilla-y-vacantes',
    },
    {
        id: 'campana',
        titulo: 'Campaña',
        etapa: 'Reclutamiento',
        quien: ['rh'],
        canal: 'web',
        resumen:
            'Publicación sobre una vacante real, con gasto, sueldo publicado y evidencias.',
        articulo: 'campanas',
    },
    {
        id: 'candidato',
        titulo: 'Candidato',
        etapa: 'Reclutamiento',
        quien: ['rh'],
        canal: 'web',
        resumen:
            'Se registra sobre la vacante (o como espontáneo) y entra al tablero.',
        articulo: 'registrar-candidato',
    },
    {
        id: 'filtro',
        titulo: 'Filtro RH',
        etapa: 'Reclutamiento',
        quien: ['rh'],
        canal: 'web_app',
        resumen:
            'Primer contacto (WhatsApp, fuera de PEOPLE) y resultado: continúa o no.',
        articulo: 'filtro-rh',
    },
    {
        id: 'entrevista',
        titulo: 'Entrevista',
        etapa: 'Reclutamiento',
        quien: ['gerente'],
        canal: 'web_app',
        resumen:
            'El gerente de la sucursal entrevista y registra el resultado.',
        articulo: 'entrevista-gerente',
    },
    {
        id: 'psicometricos',
        titulo: 'Psicométricos',
        etapa: 'Reclutamiento',
        quien: ['rh', 'gerente'],
        canal: 'web_app',
        resumen:
            'RH registra resultados (PDF o imagen); el gerente los revisa.',
        articulo: 'psicometricos',
    },
    {
        id: 'socioeconomico',
        titulo: 'Socioeconómico',
        etapa: 'Reclutamiento',
        quien: ['gerente'],
        canal: 'web_app',
        resumen:
            'Visita domiciliaria con checklist, riesgos y evidencias (foto, PDF, video).',
        articulo: 'socioeconomico',
    },
    {
        id: 'referencias',
        titulo: 'Referencias',
        etapa: 'Reclutamiento',
        quien: ['rh', 'gerente'],
        canal: 'web_app',
        resumen: 'Al menos una referencia laboral validada.',
        articulo: 'referencias',
    },
    {
        id: 'autorizacion',
        titulo: 'Autorización RH',
        etapa: 'Reclutamiento',
        quien: ['gerente', 'rh'],
        canal: 'web_app',
        resumen:
            'El gerente preautoriza y RH da la autorización final. Si RH rechaza, el gerente puede pedir intervención.',
        articulo: 'autorizacion-rh',
    },
    {
        id: 'contratacion',
        titulo: 'Contratación',
        etapa: 'Contratación',
        quien: ['rh'],
        canal: 'web',
        resumen:
            'Valida que la plaza siga libre, crea a la persona sin duplicarla y ocupa la plaza.',
        articulo: 'iniciar-contratacion',
    },
    {
        id: 'qr',
        titulo: 'QR',
        etapa: 'Contratación',
        quien: ['rh', 'colaborador'],
        canal: 'web_app',
        resumen:
            'Código temporal (máx. 24 h) y de un solo uso para registrarse.',
        articulo: 'qr-incorporacion',
    },
    {
        id: 'registro',
        titulo: 'Registro',
        etapa: 'Incorporación',
        quien: ['colaborador'],
        canal: 'app',
        resumen:
            'La persona crea su cuenta en la app, sube documentos y propone sus datos.',
        articulo: 'registro-en-app',
    },
    {
        id: 'expediente',
        titulo: 'Expediente',
        etapa: 'Incorporación',
        quien: ['rh'],
        canal: 'web_app',
        resumen:
            'RH aprueba los 11 documentos del alta y los datos; el expediente queda completo.',
        articulo: 'documentos-de-alta',
    },
    {
        id: 'contrato-inicial',
        titulo: 'Contrato inicial',
        etapa: 'Incorporación',
        quien: ['rh', 'gerente'],
        canal: 'web',
        resumen:
            'Paquete de contratación con la duración del puesto; se imprime, firma y archiva.',
        articulo: 'contrato-inicial',
    },
    {
        id: 'onboarding',
        titulo: 'Onboarding',
        etapa: 'Incorporación',
        quien: ['colaborador', 'rh', 'gerente'],
        canal: 'web_app',
        resumen:
            'Lecciones de bienvenida (mínimo 8) y entrega de equipo. Al cerrarlo, la persona queda ACTIVA.',
        articulo: 'onboarding',
    },
    {
        id: 'evaluacion',
        titulo: 'Evaluación',
        etapa: 'Periodo de prueba',
        quien: ['gerente', 'rh'],
        canal: 'web_app',
        resumen: '15 días antes del fin, el jefe evalúa y RH autoriza.',
        articulo: 'evaluacion-capacitacion',
    },
    {
        id: 'indeterminado',
        titulo: 'Contrato indeterminado',
        etapa: 'Periodo de prueba',
        quien: ['rh', 'gerente'],
        canal: 'automatico',
        resumen:
            'Con la evaluación aprobada, PEOPLE prepara el contrato por tiempo indeterminado.',
        articulo: 'contrato-indeterminado',
    },
];
