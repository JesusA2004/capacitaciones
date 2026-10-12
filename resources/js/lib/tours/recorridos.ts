import type { ContextoGuia, PasoTour, Tour } from './tipos';

/**
 * Recorridos guiados POR PROCESO (Guía maestra → «Recorridos guiados»): a
 * diferencia de los recorridos por módulo (modulos.ts), cruzan varias
 * pantallas en el orden en que se hace el trabajo. Usan el mismo motor
 * (useTourGuiado): navegan solos, no bloquean al usuario y se pueden
 * saltar, regresar o terminar en cualquier momento.
 *
 * Todo selector es un `data-tour` real de la pantalla (lo verifica
 * tests/Feature/Ayuda/GuiaMaestraTest.php). Los pasos que dependen de datos
 * (primer candidato, primer lote) son opcionales: si la lista está vacía, se
 * omiten en lugar de resaltar la nada.
 */
export type RecorridoProceso = {
    id: string;
    titulo: string;
    descripcion: string;
    /** Artículo de la guía que explica este proceso. */
    articulo: string;
    modo: 'operativo' | 'colaborador';
    /** Basta uno para ofrecer el recorrido. */
    permisos: string[];
    pasos: PasoTour[];
};

const sel = (nombre: string): string => `[data-tour="${nombre}"]`;

export const RECORRIDOS_PROCESO: RecorridoProceso[] = [
    {
        id: 'primer-dia-rh',
        titulo: 'Primer día en RH',
        descripcion:
            'Las pantallas que vas a usar todos los días, en el orden en que se trabaja.',
        articulo: 'mis-pendientes',
        modo: 'operativo',
        permisos: [
            'candidatos.ver',
            'expedientes.ver_todos',
            'solicitudes.revisar',
        ],
        pasos: [
            {
                titulo: 'Bienvenido a Recursos Humanos',
                texto: 'Te llevo por las pantallas del día a día: tablero, pendientes, vacantes, candidatos, expedientes, solicitudes y nómina. Yo navego; tú solo presiona «Siguiente».',
                consejo:
                    'Puedes saltar o salir cuando quieras con Esc. Nada se modifica durante el recorrido.',
            },
            {
                ruta: '/dashboard',
                selector: sel('dashboard-kpis'),
                titulo: 'Tu tablero',
                texto: 'Plantilla activa contra autorizada, vacantes abiertas, rotación, costo y tiempo de contratación y contratos por vencer.',
            },
            {
                ruta: '/rh/pendientes',
                selector: sel('pendientes-conteos'),
                titulo: 'Mis pendientes',
                texto: 'Lo que otras personas esperan de ti. Cada pendiente te lleva directo a donde se resuelve y desaparece solo al hacerlo.',
            },
            {
                ruta: '/rh/vacantes',
                selector: sel('vacantes-resumen'),
                titulo: 'Vacantes reales',
                texto: 'Plantilla autorizada menos ocupados. Nadie las captura: se abren y cierran solas.',
                permisos: ['vacantes.ver'],
            },
            {
                ruta: '/rh/candidatos',
                selector: sel('candidatos-tablero'),
                titulo: 'Candidatos',
                texto: 'Filtro RH → Entrevista → Psicométricos → Socioeconómico → Contratación → Contratado. Cada avance se hace desde la ficha del candidato.',
                permisos: ['candidatos.ver'],
            },
            {
                ruta: '/rh/expedientes',
                selector: sel('expedientes-filtros'),
                titulo: 'Expedientes',
                texto: 'La carpeta digital de cada persona. Busca por nombre o número de empleado.',
                permisos: ['expedientes.ver_todos', 'expedientes.ver_sucursal'],
            },
            {
                ruta: '/rh/solicitudes',
                selector: sel('solicitudes-tipos'),
                titulo: 'Solicitudes',
                texto: 'Permisos, vacaciones y préstamos por tipo: gerente y regional dan visto bueno; RH autoriza.',
                permisos: ['solicitudes.revisar', 'solicitudes.aprobar'],
            },
            {
                ruta: '/rh/nomina/lotes',
                selector: sel('nomina-pasos'),
                titulo: 'Recibos de nómina',
                texto: 'Preparar → Revisar → Emitir. Nada se publica hasta que tú emites.',
                permisos: ['nomina.recibos.ver'],
            },
            {
                ruta: '/ayuda',
                selector: sel('guia-buscar'),
                titulo: 'Cuando tengas una duda',
                texto: 'Escribe aquí tu pregunta («¿cómo contrato un candidato?») y te llevo al artículo.',
            },
        ],
    },
    {
        id: 'crear-campana',
        titulo: 'Crear vacante y campaña',
        descripcion:
            'De la vacante real a la campaña que la cubre, con su costo por contratación.',
        articulo: 'campanas',
        modo: 'operativo',
        permisos: ['reclutamiento.campanas.ver'],
        pasos: [
            {
                titulo: 'Las vacantes no se crean: se calculan',
                texto: 'Primero ves dónde faltan plazas; después publicas una campaña sobre esa vacante real.',
            },
            {
                ruta: '/rh/vacantes',
                selector: sel('vacantes-resumen'),
                titulo: 'Dónde faltan plazas',
                texto: 'Cuántas plazas faltan de cada puesto y en qué sucursales. Un clic filtra la lista.',
                permisos: ['vacantes.ver'],
            },
            {
                selector: sel('vacantes-lista'),
                titulo: 'Una fila por vacante',
                texto: 'Puesto, sucursal, plazas que faltan, días abierta y candidatos. Ejemplo: «Gestor — Tlaxcala · 4 de 5 ocupadas».',
                permisos: ['vacantes.ver'],
            },
            {
                ruta: '/rh/campanas',
                selector: sel('campanas-nueva'),
                titulo: 'Nueva campaña',
                texto: 'Elige la vacante real: empresa, sucursal, departamento y puesto salen de ella. Captura canal, fechas, presupuesto, gasto, sueldo publicado, copy, liga y evidencias (PDF o imagen).',
                opcional: true,
            },
            {
                selector: sel('campanas-resumen'),
                titulo: 'Costo por colaborador',
                texto: 'Gasto, contratados y costo promedio por colaborador del periodo.',
            },
            {
                selector: sel('tabla'),
                titulo: 'Embudo de cada campaña',
                texto: 'Candidatos → contactados → entrevistas → psicométricos → contratados, con costo por candidato, por contratación, conversión y días para cubrir.',
                consejo:
                    'Liga cada candidato a su campaña al registrarlo para que el embudo se llene.',
                opcional: true,
            },
        ],
    },
    {
        id: 'contratar-candidato',
        titulo: 'Contratar un candidato',
        descripcion:
            'Del registro a la autorización de RH y el QR de contratación.',
        articulo: 'iniciar-contratacion',
        modo: 'operativo',
        permisos: ['candidatos.ver'],
        pasos: [
            {
                titulo: 'Cómo se contrata en PEOPLE',
                texto: 'Registras al candidato sobre una vacante, avanza paso a paso con su acción en la ficha y, ya autorizado por RH, generas su QR de contratación.',
            },
            {
                ruta: '/rh/candidatos',
                selector: sel('candidatos-nuevo'),
                titulo: 'Registrar al candidato',
                texto: 'Nombre, contacto, la vacante real (de ella salen sucursal y puesto), la campaña y la fuente.',
                opcional: true,
            },
            {
                selector: sel('candidatos-tablero'),
                titulo: 'El tablero',
                texto: 'Arrastrar solo sirve para cerrar (Rechazado o Desistió). Para avanzar, abre la ficha. Te llevo a una.',
            },
            {
                rutaDesde: `${sel('candidatos-tablero')} [data-tour-href]`,
                selector: sel('candidato-pasos'),
                titulo: 'Los pasos del candidato',
                texto: 'Perfil → entrevista → psicométricas → socioeconómico → referencias → autorización → contratación. Siempre sabes en cuál va.',
                opcional: true,
            },
            {
                selector: sel('candidato-estado'),
                titulo: '¿A quién le toca?',
                texto: 'El estado actual, el responsable de la siguiente acción y lo que falta.',
                opcional: true,
            },
            {
                selector: sel('candidato-acciones'),
                titulo: 'Tus acciones',
                texto: 'Solo aparecen los botones que a ti te tocan: «Revisar perfil», «Registrar entrevista», «Autorizar contratación», «Generar QR de contratación»…',
                consejo:
                    'Al generar el QR, PEOPLE revisa que la plaza siga libre antes de crear a la persona: nadie ocupa dos veces la última plaza.',
                opcional: true,
            },
            {
                ruta: '/rh/incorporacion/invitaciones',
                selector: sel('invitaciones-filtros'),
                titulo: 'Invitaciones QR',
                texto: 'El estado de cada QR (activo, usado, vencido, revocado). Si alguien no alcanzó a usar el suyo (vive máximo 24 h), aquí lo vuelves a emitir.',
                permisos: ['rh.incorporacion.invitaciones.ver'],
            },
        ],
    },
    {
        id: 'revisar-expediente',
        titulo: 'Revisar un expediente',
        descripcion: 'Qué le falta a una persona y cómo avisarle.',
        articulo: 'completitud-expediente',
        modo: 'operativo',
        permisos: ['expedientes.ver_todos', 'expedientes.ver_sucursal'],
        pasos: [
            {
                ruta: '/rh/expedientes',
                selector: sel('expedientes-tarjetas'),
                titulo: 'Elige a la persona',
                texto: 'Cada tarjeta es un expediente. Te llevo al primero.',
                opcional: true,
            },
            {
                rutaDesde: `${sel('expedientes-tarjetas')} a[href]`,
                selector: sel('expediente-cabecera'),
                titulo: 'Ficha',
                texto: 'Puesto, sucursal, acceso al sistema y avance del expediente. Desde aquí abres su Ciclo laboral.',
                opcional: true,
            },
            {
                selector: sel('expediente-faltantes'),
                titulo: 'Qué falta, por sección',
                texto: 'Identidad, Fiscal, Laboral, Domicilio, Contacto, Emergencia, Beneficiario y Documentos. «Avisar al colaborador» le manda notificación y push.',
                opcional: true,
            },
            {
                selector: sel('expediente-pestanas'),
                titulo: 'Todo, por pestañas',
                texto: 'Datos, cuenta, documentos (aprobar, rechazar, pedir corrección), onboarding, avisos, vacaciones, recibos, préstamos, solicitudes e historial.',
                opcional: true,
            },
        ],
    },
    {
        id: 'emitir-nomina',
        titulo: 'Emitir nómina',
        descripcion:
            'Preparar un lote, revisarlo recibo por recibo y emitirlo.',
        articulo: 'nomina-lotes',
        modo: 'operativo',
        permisos: ['nomina.recibos.ver'],
        pasos: [
            {
                ruta: '/rh/nomina/lotes',
                selector: sel('nomina-pasos'),
                titulo: 'Tres pasos',
                texto: 'Preparar no publica. Revisar te deja ver el PDF real de cada recibo. Solo «Emitir» publica y avisa.',
            },
            {
                selector: sel('encabezado'),
                titulo: 'Nuevo lote',
                texto: 'Elige semanal (lunes a domingo) o quincenal (1–15 y 16–último día del mes) y prepara desde los sueldos o importando el archivo.',
                opcional: true,
            },
            {
                selector: sel('nomina-lotes'),
                titulo: 'Lotes',
                texto: 'Cada lote con su estado: en revisión, emitido o cancelado. Te llevo a uno.',
                opcional: true,
            },
            {
                rutaDesde: `${sel('nomina-lotes')} a[href]`,
                selector: sel('nomina-resumen'),
                titulo: 'Resumen del lote',
                texto: 'Esperados, preparados, errores, advertencias, percepciones, deducciones y neto.',
                opcional: true,
            },
            {
                selector: sel('nomina-revision'),
                titulo: 'Revisión persona por persona',
                texto: 'La lista a la izquierda y el PDF real a la derecha, con «Anterior» y «Siguiente».',
                opcional: true,
            },
            {
                selector: sel('nomina-acciones'),
                titulo: 'Emitir',
                texto: '«Emitir N recibos» publica en web y app, congela cada PDF en el expediente y avisa a cada colaborador. Un lote emitido no se emite dos veces.',
                opcional: true,
            },
        ],
    },
    {
        id: 'autorizar-permiso',
        titulo: 'Autorizar un permiso',
        descripcion:
            'Visto bueno del gerente y del regional, autorización de RH.',
        articulo: 'revisar-solicitudes',
        modo: 'operativo',
        permisos: ['solicitudes.revisar', 'solicitudes.aprobar'],
        pasos: [
            {
                ruta: '/rh/solicitudes',
                selector: sel('solicitudes-tipos'),
                titulo: 'Por tipo',
                texto: 'Cada tarjeta muestra recibidas, por autorizar y en corrección. Te llevo a un tablero.',
            },
            {
                rutaDesde: `${sel('solicitudes-tipos')} a[href]`,
                selector: sel('solicitudes-tablero'),
                titulo: 'El tablero',
                texto: 'Recibidas (esperan vistos buenos) → Pendiente de autorizar → Requiere corrección → Aprobadas / No aprobadas.',
            },
            {
                rutaDesde: `${sel('solicitudes-tablero')} a[href]`,
                selector: sel('solicitud-cadena'),
                titulo: 'Cadena de autorización',
                texto: 'Quién ya dio su visto bueno (gerente, regional) y quién falta. Sin ambos, RH no puede autorizar.',
                opcional: true,
            },
            {
                selector: sel('solicitud-acciones'),
                titulo: 'Acciones de revisión',
                texto: 'Aprobar, pedir corrección o rechazar (con comentario). Ya autorizado, el formato de permiso queda listo para imprimir.',
                opcional: true,
            },
        ],
    },
];

function tienePermisoDelPaso(paso: PasoTour, contexto: ContextoGuia): boolean {
    return (
        !paso.permisos ||
        paso.permisos.length === 0 ||
        paso.permisos.some((permiso) => contexto.tienePermiso(permiso))
    );
}

export function recorridosDisponibles(
    contexto: ContextoGuia,
): RecorridoProceso[] {
    return RECORRIDOS_PROCESO.filter(
        (r) =>
            r.modo === contexto.modo &&
            (r.permisos.length === 0 ||
                r.permisos.some((p) => contexto.tienePermiso(p))),
    );
}

export function tourDeRecorrido(
    recorrido: RecorridoProceso,
    contexto: ContextoGuia,
): Tour {
    return {
        id: `proceso-${recorrido.id}`,
        titulo: recorrido.titulo,
        pasos: recorrido.pasos
            .filter((paso) => tienePermisoDelPaso(paso, contexto))
            .map((paso) => ({
                ...paso,
                seccion: paso.seccion ?? recorrido.titulo,
            })),
    };
}
