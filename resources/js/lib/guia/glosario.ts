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
        termino: 'Autorización de RH',
        definicion:
            'La decisión de contratar a un candidato. Solo la da Recursos Humanos, después de que el responsable revisó entrevista, psicométricos y socioeconómico. El gerente no autoriza contrataciones.',
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
            'La carpeta digital de la persona: datos, documentos, contratos, solicitudes, avisos e historial. Nunca se borra.',
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
            'Conjunto de recibos de un periodo (semanal para sucursales, quincenal para corporativo). Se prepara, se revisa, se emite y RH lo imprime.',
        articulo: 'nomina-lotes',
    },
    {
        termino: 'Cierre laboral',
        definicion:
            'El proceso completo de baja: el gerente (o RH) la registra y queda aplicada (sin solicitud ni autorización), documentos, finiquito, registro de su entrega y cierre.',
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
    {
        termino: 'Fechas laborales',
        definicion:
            'Ingreso, inicio de capacitación inicial, inducción y alta IMSS de una persona, más su periodo de prueba (hereda del puesto o específico). De ahí salen el fin del periodo de prueba, su evaluación y sus Seguimientos técnicos.',
        articulo: 'fechas-laborales',
    },
    {
        termino: 'Periodo de prueba específico',
        definicion:
            'Duración del periodo de prueba capturada para una sola persona; reemplaza la del puesto. Vacío = hereda del puesto.',
        articulo: 'fechas-laborales',
    },
    {
        termino: 'Seguimiento técnico',
        definicion:
            'Evaluación técnica del puesto que se aplica en días fijos desde el ingreso («Seguimiento 15», «30», «60»…). La contesta el colaborador o su jefe directo.',
        articulo: 'seguimientos-tecnicos',
    },
    {
        termino: 'Técnica final',
        definicion:
            'El último Seguimiento técnico del calendario de un puesto; solo puede haber una.',
        articulo: 'calendario-seguimientos',
    },
    {
        termino: 'Nine Box',
        definicion:
            'Matriz de 3 × 3 que cruza desempeño (datos de Reportes de todas las rutas de la persona) con potencial (Seguimientos técnicos + calibración del jefe).',
        articulo: 'nine-box',
    },
    {
        termino: 'Calibración',
        definicion:
            'Nivel de potencial (Bajo, Medio, Alto) que el jefe directo o RH asigna en la Nine Box con su porqué. Nunca se borra.',
        articulo: 'nine-box',
    },
    {
        termino: 'Vínculo con Reportes',
        definicion:
            'Une a una persona o sucursal de Reportes con su colaborador o sucursal de PEOPLE. Nunca se vincula por ruta.',
        articulo: 'vinculos-reportes',
    },
    {
        termino: 'Asignación (capacitación)',
        definicion:
            'Indica quién debe tomar un curso y para cuándo: por persona, sucursal, departamento, puesto, rol o todos.',
        articulo: 'capacitacion-asignar',
    },
    {
        termino: 'Aula',
        definicion:
            'La vista de un curso con su tablón, trabajo asignado, calificaciones y participantes.',
        articulo: 'capacitacion-cursos',
    },
    {
        termino: 'Constancia',
        definicion:
            'Documento con folio verificable que se emite al completar un curso que la genera. Suma para el nivel Mr. Lana (Bronce, Plata, Oro).',
        articulo: 'mi-capacitacion',
    },
    {
        termino: 'Certificación Mr. Lana',
        definicion:
            'Acreditación del gestor con el formato oficial RH-CC-R-001 firmado y su promedio; emite el certificado «Cuentas con tu Certificación Mr. Lana».',
        articulo: 'certificacion-mr-lana',
    },
    {
        termino: 'Corte de recepción documental',
        definicion:
            'La lista mensual de originales firmados que RH debe recibir, calculada a partir de los eventos reales del mes.',
        articulo: 'recepcion-documental',
    },
    {
        termino: 'Pendiente de alta / baja en sistemas',
        definicion:
            'Aviso a Sistemas para dar o retirar los usuarios de una persona en las demás plataformas; queda en su bandeja hasta marcarlo como atendido.',
        articulo: 'pendientes-sistemas',
    },
];

/** Preguntas frecuentes (cada una lleva a su artículo). */
export const PREGUNTAS_FRECUENTES: PreguntaFrecuente[] = [
    {
        pregunta: '¿Por qué no veo una vacante?',
        respuesta:
            'Porque ya no falta: la plantilla autorizada de ese puesto y sucursal está completa, o la sucursal no es de tu alcance. Gerencia de RH revisa la plantilla en Organización → Sucursales.',
        articulo: 'plantilla-y-vacantes',
        roles: ['rh', 'gerente', 'regional', 'direccion_comercial'],
    },
    {
        pregunta: '¿Por qué no puedo contratar al candidato?',
        respuesta:
            'Debe estar «Autorizado por RH», vinculado a una vacante real con plaza libre y su puesto debe tener configurada la duración de capacitación inicial. El mensaje en pantalla dice cuál falta.',
        articulo: 'iniciar-contratacion',
        roles: ['rh'],
    },
    {
        pregunta: '¿Por qué no puedo imprimir un permiso?',
        respuesta:
            'El formato de permiso solo se genera cuando RH ya lo autorizó (después del visto bueno del gerente y del regional). También debe estar cargado el formato oficial en Documentos maestros.',
        articulo: 'permisos',
        roles: ['rh', 'gerente'],
    },
    {
        pregunta: '¿Por qué el contrato no se genera?',
        respuesta:
            'Falta un dato que el formato pide (PEOPLE dice cuál: estado civil, beneficiario…) o falta el formato oficial. Nunca genera un contrato incompleto.',
        articulo: 'contrato-inicial',
        roles: ['rh'],
    },
    {
        pregunta: '¿Por qué un recibo no aparece para imprimir?',
        respuesta:
            'El lote todavía no se emite: los recibos preparados son borradores. Al «Emitir» se congela el PDF y RH lo imprime para entregarlo.',
        articulo: 'nomina-lotes',
        roles: ['rh'],
    },
    {
        pregunta: '¿Qué hago si falta un dato?',
        respuesta:
            'RH ve qué sección está incompleta en el expediente y pulsa «Avisar al colaborador». El colaborador lo completa en la app («Completa tu información») y RH lo autoriza.',
        articulo: 'completitud-expediente',
        roles: ['rh', 'colaborador'],
    },
    {
        pregunta: '¿Cómo corrijo un expediente?',
        respuesta:
            'RH corrige los datos de otras personas en su expediente. Nadie edita el suyo propio: si el dato está vacío se usa «Completar información»; si ya tiene valor, «Solicitar cambio». RH lo revisa antes de que sea oficial.',
        articulo: 'completar-datos',
        roles: ['rh', 'colaborador'],
    },
    {
        pregunta: '¿Qué pasa si RH rechaza al candidato?',
        respuesta:
            'Queda «Rechazado por RH». El gerente que lo entrevistó puede aceptarlo o pedir una intervención: decide el Regional (gestor o volante) o Dirección Comercial (puestos superiores).',
        articulo: 'intervencion',
        roles: ['rh', 'gerente', 'regional', 'direccion_comercial'],
    },
    {
        pregunta: '¿Qué pasa con alguien que regresa?',
        respuesta:
            'No se registra como candidato nuevo: se busca en Reingresos y, si RH lo aprueba, se reactiva la misma persona con todo su historial.',
        articulo: 'reingreso',
        roles: ['rh', 'gerente'],
    },
    {
        pregunta: '¿Dónde registro una baja?',
        respuesta:
            'En Cierres laborales → «Bajas» → «Registrar baja» (o en el Ciclo laboral de la persona con «Iniciar baja»): solo el gerente de su personal o RH. No es una solicitud ni requiere autorización: queda aplicada al confirmar, después de ver la vista previa de la renuncia y del finiquito. Siguen los documentos, Jurídico si aplica y el cierre.',
        articulo: 'baja',
        roles: ['rh', 'gerente', 'sistemas'],
    },
    {
        pregunta: '¿Por qué una solicitud sigue en «Recibida»?',
        respuesta:
            'Le falta el visto bueno del gerente o del regional. La «Cadena de autorización» del detalle dice cuál.',
        articulo: 'revisar-solicitudes',
        roles: ['rh', 'gerente', 'regional', 'colaborador'],
    },
    {
        pregunta: '¿Cuál es mi usuario para entrar?',
        respuesta:
            'Tu primer nombre y tu primer apellido, sin acentos (por ejemplo «Juan Perez»). Si se repite, lleva un número al final.',
        articulo: 'app-colaborador',
    },
    {
        pregunta:
            '¿Por qué alguien sale «Sin vincular a PEOPLE» en la Nine Box?',
        respuesta:
            'Su persona de Reportes todavía no está unida a su colaborador. En Seguimiento → «Vínculos con Reportes», pestaña «Personas», confirma la sugerencia o elige al colaborador.',
        articulo: 'vinculos-reportes',
        roles: ['rh', 'gerente', 'regional', 'direccion_comercial'],
    },
    {
        pregunta: '¿Por qué no se generó un Seguimiento técnico?',
        respuesta:
            'Su puesto no tiene calendario, el formulario no tiene versión publicada, falta su fecha de inicio en Fechas laborales o el día ya pasó hace más de 15 días.',
        articulo: 'seguimientos-tecnicos',
        roles: ['rh', 'gerente', 'colaborador'],
    },
    {
        pregunta: '¿Por qué dice «Debes actualizar el contrato»?',
        respuesta:
            'Cambiaron las fechas laborales y el contrato ya no coincide. PEOPLE nunca cambia el contrato solo: pulsa «Actualizar contrato» (o genera uno nuevo si ya estaba firmado).',
        articulo: 'fechas-laborales',
        roles: ['rh'],
    },
    {
        pregunta: '¿Cómo certifico a un gestor en la Certificación Mr. Lana?',
        respuesta:
            'Capacitación → «Certificación Mr. Lana»: «Descargar formato» (sale prellenado), se firma, y «Subir formato firmado» con su promedio. Queda certificado y se emite su certificado.',
        articulo: 'certificacion-mr-lana',
        roles: ['rh'],
    },
    {
        pregunta: '¿Dónde veo mis cursos?',
        respuesta:
            'En Mi espacio → «Mi capacitación»: «Mis aulas», «Pendientes» y «Mis certificados».',
        articulo: 'mi-capacitacion',
        roles: ['colaborador'],
    },
];
