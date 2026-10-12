import type { PreguntaFrecuente, TerminoGlosario } from './tipos';

/** Glosario para usuarios no técnicos. */
export const GLOSARIO: TerminoGlosario[] = [
    {
        termino: 'Plantilla autorizada',
        definicion:
            'Cuántas plazas de un puesto autorizó Dirección en una sucursal. Ejemplo: Tlaxcala puede tener 5 gestores.',
        articulo: 'plantilla-y-vacantes',
    },
    {
        termino: 'Vacante real',
        definicion:
            'Plaza autorizada que hoy no tiene titular: plantilla autorizada menos personas que ocupan el puesto. PEOPLE la calcula; nadie la captura.',
        articulo: 'plantilla-y-vacantes',
    },
    {
        termino: 'Campaña',
        definicion:
            'Publicación para cubrir una vacante (Meta, Indeed, referidos…) con su gasto y evidencias, para medir cuánto cuesta cada contratación.',
        articulo: 'campanas',
    },
    {
        termino: 'Candidato espontáneo',
        definicion:
            'Persona interesada sin vacante abierta. Puede avanzar en el proceso, pero no se contrata hasta vincularla a una vacante real.',
        articulo: 'registrar-candidato',
    },
    {
        termino: 'Filtro RH',
        definicion:
            'Primera revisión del candidato: Reclutamiento lo contacta (normalmente por WhatsApp) y registra si continúa.',
        articulo: 'filtro-rh',
    },
    {
        termino: 'Preautorización',
        definicion:
            'Primer «sí» de quien está arriba en el organigrama (gerente o superior). Nunca es la decisión final.',
        articulo: 'autorizacion-rh',
    },
    {
        termino: 'Autorización final de RH',
        definicion:
            'La decisión definitiva de Recursos Humanos. Quien preautorizó no puede darla.',
        articulo: 'autorizacion-rh',
    },
    {
        termino: 'Intervención',
        definicion:
            'Excepción que pide el gerente cuando RH rechazó a un candidato. Decide el Regional (gestor o volante) o Dirección Comercial (puestos superiores).',
        articulo: 'intervencion',
    },
    {
        termino: 'Incorporación',
        definicion:
            'Etapa entre el QR y el alta activa: la persona tiene cuenta, sube documentos y firma, pero todavía no opera.',
        articulo: 'registro-en-app',
    },
    {
        termino: 'QR de contratación',
        definicion:
            'Código temporal (máximo 24 horas) y de un solo uso con el que la persona nueva crea su cuenta.',
        articulo: 'qr-incorporacion',
    },
    {
        termino: 'Expediente',
        definicion:
            'La carpeta digital de la persona: datos, documentos, contratos, solicitudes, recibos, avisos e historial. Nunca se borra.',
        articulo: 'expediente',
    },
    {
        termino: 'Onboarding',
        definicion:
            'La bienvenida estructurada: lecciones con evaluación y entrega de equipo. El colaborador lo ve como «lecciones de bienvenida».',
        articulo: 'onboarding',
    },
    {
        termino: 'Capacitación inicial',
        definicion:
            'Primer contrato de la persona (equivale al periodo de prueba). Su duración la define cada puesto: Gestor 2 meses, Gerente 3…',
        articulo: 'contrato-inicial',
    },
    {
        termino: 'Contrato indeterminado',
        definicion:
            'Contrato sin fecha de fin que sigue a una capacitación inicial aprobada.',
        articulo: 'contrato-indeterminado',
    },
    {
        termino: 'Visto bueno',
        definicion:
            'Aprobación intermedia del gerente y del regional en permisos, vacaciones y préstamos, antes de que RH autorice.',
        articulo: 'revisar-solicitudes',
    },
    {
        termino: 'Lote de nómina',
        definicion:
            'Conjunto de recibos de un periodo (semanal o quincenal). Se prepara, se revisa y solo al emitirse se publica.',
        articulo: 'nomina-lotes',
    },
    {
        termino: 'Cierre laboral',
        definicion:
            'El proceso completo de baja: solicitud, autorizaciones, finiquito, pago, firma y baja en la fecha efectiva.',
        articulo: 'baja',
    },
    {
        termino: 'Reingreso',
        definicion:
            'Volver a contratar a alguien que ya trabajó aquí: la misma persona, con su historial, en un ciclo nuevo.',
        articulo: 'reingreso',
    },
    {
        termino: 'Mis pendientes',
        definicion:
            'Tu bandeja de tareas: lo que otras personas esperan que hagas.',
        articulo: 'mis-pendientes',
    },
];

/** Preguntas frecuentes (cada una lleva a su artículo). */
export const PREGUNTAS_FRECUENTES: PreguntaFrecuente[] = [
    {
        pregunta: '¿Por qué no veo una vacante?',
        respuesta:
            'Porque ya no falta: la plantilla autorizada de ese puesto y sucursal está completa, o la sucursal no es de tu alcance. Gerencia de RH revisa la plantilla en Administración → Sucursales.',
        articulo: 'plantilla-y-vacantes',
    },
    {
        pregunta: '¿Por qué no puedo contratar al candidato?',
        respuesta:
            'Debe estar «Autorizado por RH», vinculado a una vacante real con plaza libre y su puesto debe tener configurada la duración de capacitación inicial. El mensaje en pantalla dice cuál falta.',
        articulo: 'iniciar-contratacion',
    },
    {
        pregunta: '¿Por qué no puedo imprimir un permiso?',
        respuesta:
            'El formato de permiso solo se genera cuando RH ya lo autorizó (después del visto bueno del gerente y del regional). También debe estar cargado el formato oficial en Documentos maestros.',
        articulo: 'permisos',
    },
    {
        pregunta: '¿Por qué el contrato no se genera?',
        respuesta:
            'Falta un dato que el formato pide (PEOPLE dice cuál: estado civil, beneficiario…) o falta el formato oficial. Nunca genera un contrato incompleto.',
        articulo: 'contrato-inicial',
    },
    {
        pregunta: '¿Por qué no veo el recibo?',
        respuesta:
            'El lote todavía no se emite. Los recibos preparados son borradores: nadie los ve hasta que RH pulsa «Emitir».',
        articulo: 'nomina-lotes',
    },
    {
        pregunta: '¿Qué hago si falta un dato?',
        respuesta:
            'RH ve qué sección está incompleta en el expediente y pulsa «Avisar al colaborador». El colaborador lo completa en la app («Completa tu información») y RH lo autoriza.',
        articulo: 'completitud-expediente',
    },
    {
        pregunta: '¿Cómo corrijo un expediente?',
        respuesta:
            'RH corrige los datos de otras personas en su expediente. Nadie edita el suyo propio: se usa «Solicitar corrección» y otra persona de RH lo autoriza.',
        articulo: 'completar-datos',
    },
    {
        pregunta: '¿Qué pasa si RH rechaza al candidato?',
        respuesta:
            'Queda «Rechazado por RH». El gerente que lo entrevistó puede aceptarlo o pedir una intervención: decide el Regional (gestor o volante) o Dirección Comercial (puestos superiores).',
        articulo: 'intervencion',
    },
    {
        pregunta: '¿Qué pasa con alguien que regresa?',
        respuesta:
            'No se registra como candidato nuevo: se busca en Reingresos y, si RH lo aprueba, se reactiva la misma persona con todo su historial.',
        articulo: 'reingreso',
    },
    {
        pregunta: '¿Dónde inicio una baja?',
        respuesta:
            'En el Ciclo laboral de la persona (Expedientes → la persona → «Ciclo laboral») con «Solicitar baja». Ahí siguen la preautorización, la autorización de RH y el finiquito.',
        articulo: 'baja',
    },
    {
        pregunta: '¿Por qué una solicitud sigue en «Recibida»?',
        respuesta:
            'Le falta el visto bueno del gerente o del regional. La «Cadena de autorización» del detalle dice cuál.',
        articulo: 'revisar-solicitudes',
    },
    {
        pregunta: '¿Cuál es mi usuario para entrar?',
        respuesta:
            'Tu primer nombre y tu primer apellido, sin acentos (por ejemplo «Juan Perez»). Si se repite, lleva un número al final.',
        articulo: 'app-colaborador',
    },
];
