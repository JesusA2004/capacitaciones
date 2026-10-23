import {
    Activity,
    Award,
    Briefcase,
    Building2,
    Cake,
    Contact,
    ClipboardList,
    FilePen,
    FilePenLine,
    FileStack,
    FolderKanban,
    GitBranch,
    GraduationCap,
    Inbox,
    Landmark,
    LayoutGrid,
    Megaphone,
    QrCode,
    RotateCcw,
    Settings2,
    ShieldCheck,
    Smartphone,
    Sparkles,
    TrendingDown,
    UserRound,
    Users,
} from '@lucide/vue';
import type { ModuloGuia, PasoTour } from './tipos';

/**
 * Catálogo de recorridos guiados por módulo. Los selectores apuntan a
 * atributos `data-tour="..."` puestos a propósito en cada pantalla (nunca a
 * clases de Tailwind, que cambian con cualquier ajuste visual). Los
 * componentes compartidos traen uno por defecto — `encabezado`
 * (CrudPageHeader), `indicadores` (CrudStats), `busqueda` (CrudToolbar) y
 * `tabla` (DataTable) — y cada pantalla puede sobreescribirlo.
 *
 * Todo lo que depende de datos (primera tarjeta, gráfica, lista) va como
 * `opcional`: si la pantalla está vacía, el paso se omite en vez de
 * resaltar la nada.
 */
const sel = (nombre: string): string => `[data-tour="${nombre}"]`;

/** Pasos estándar de un catálogo de Administración (encabezado + tabla). */
function pasosCatalogo(opciones: {
    queEs: string;
    crear: string;
    indicadores?: string;
    busqueda?: string;
    tabla: string;
    consejo?: string;
}): PasoTour[] {
    const pasos: PasoTour[] = [
        { titulo: 'Para qué sirve', texto: opciones.queEs },
        {
            selector: sel('encabezado'),
            titulo: 'Crear un registro nuevo',
            texto: opciones.crear,
        },
    ];

    if (opciones.indicadores) {
        pasos.push({
            selector: sel('indicadores'),
            titulo: 'Resumen rápido',
            texto: opciones.indicadores,
            opcional: true,
        });
    }

    if (opciones.busqueda) {
        pasos.push({
            selector: sel('busqueda'),
            titulo: 'Buscar',
            texto: opciones.busqueda,
            opcional: true,
        });
    }

    pasos.push({
        selector: sel('tabla'),
        titulo: 'Listado',
        texto: opciones.tabla,
        consejo: opciones.consejo,
        opcional: true,
    });

    return pasos;
}

export const MODULOS_GUIA: ModuloGuia[] = [
    // ───────────────────────── Modo operativo ─────────────────────────
    {
        id: 'dashboard',
        nombre: 'Inicio',
        ruta: '/dashboard',
        patron: /^\/dashboard$/,
        modo: 'operativo',
        permisos: [],
        grupo: 'Panel',
        icono: LayoutGrid,
        descripcion:
            'Tablero de Recursos Humanos: 8 indicadores, embudo de reclutamiento, tiempo de contratación por nivel y rotación mensual.',
        pasos: [
            {
                titulo: 'Tu tablero de Inicio',
                texto: 'Es lo primero que ves al entrar. Resume cómo está RH dentro de las sucursales que te corresponden. Arriba a la derecha eliges el mes y, si ves varias, la sucursal.',
            },
            {
                selector: sel('dashboard-kpis'),
                titulo: 'Indicadores clave',
                texto: 'Plantilla activa contra la autorizada, vacantes abiertas, rotación del mes, costo por contratación, tiempo de contratación, permanencia promedio, contratos por vencer e inversión en campañas.',
            },
            {
                selector: sel('dashboard-embudo'),
                titulo: 'Embudo de reclutamiento',
                texto: 'Cuántas personas llegaron a cada filtro en el periodo. Quien ya fue contratado también cuenta en las etapas que pasó. Pasa el cursor sobre una barra para ver el valor.',
            },
            {
                selector: sel('dashboard-tiempo'),
                titulo: 'Tiempo de contratación por nivel',
                texto: 'Días promedio desde la postulación hasta la contratación, por grupo de puestos (últimos 12 meses).',
            },
            {
                selector: sel('dashboard-rotacion'),
                titulo: 'Rotación mensual',
                texto: 'Bajas del mes entre la plantilla promedio, mes por mes. El mes elegido se resalta.',
            },
        ],
    },
    {
        id: 'pendientes',
        nombre: 'Mis pendientes',
        ruta: '/rh/pendientes',
        patron: /^\/rh\/pendientes$/,
        modo: 'operativo',
        permisos: [],
        grupo: 'Panel',
        icono: Inbox,
        descripcion:
            'Tu bandeja: lo que te toca hacer hoy en reclutamiento, contratación, inducción, evaluaciones y bajas.',
        pasos: [
            {
                titulo: '¿Qué me toca hacer?',
                texto: 'Cada pendiente es una acción concreta que alguien espera de ti (revisar, autorizar, evaluar, entregar equipo…). Al resolverla en su pantalla, desaparece de aquí sola.',
            },
            {
                selector: sel('pendientes-conteos'),
                titulo: 'Resumen',
                texto: 'Cuántos pendientes tienes abiertos, cuáles no has visto y cuáles ya vencieron.',
            },
            {
                selector: sel('pendientes-filtros'),
                titulo: 'Filtrar',
                texto: 'Acota por etapa, sucursal o urgencia para atender primero lo vencido.',
            },
            {
                selector: sel('pendientes-lista'),
                titulo: 'Abrir y resolver',
                texto: 'Da clic en un pendiente para ir directo a la persona o candidato donde se hace la acción.',
                opcional: true,
            },
        ],
    },
    {
        id: 'expedientes',
        nombre: 'Expedientes',
        ruta: '/rh/expedientes',
        patron: /^\/rh\/expedientes$/,
        modo: 'operativo',
        permisos: ['expedientes.ver_todos', 'expedientes.ver_sucursal'],
        grupo: 'Personal',
        icono: FolderKanban,
        descripcion:
            'Pantalla maestra de personas: datos, documentos, cuenta de acceso e historial laboral de cada colaborador.',
        pasos: [
            {
                titulo: 'El archivo digital de tu plantilla',
                texto: 'Cada colaborador tiene una carpeta con sus datos personales y laborales, documentos, cuenta de acceso, vacaciones, recibos, préstamos, solicitudes e historial RH. Nada se borra nunca: una baja bloquea el acceso pero conserva todo el historial.',
            },
            {
                selector: sel('encabezado'),
                titulo: 'Exportar',
                texto: 'Descarga el listado a Excel o PDF. La exportación respeta los filtros que tengas aplicados en ese momento.',
            },
            {
                selector: sel('expedientes-ruta'),
                titulo: 'Dónde estás',
                texto: 'Muestra la empresa y sucursal que estás explorando. Cambia al elegir una empresa o sucursal en los filtros.',
            },
            {
                selector: sel('busqueda'),
                titulo: 'Buscar a alguien',
                texto: 'Escribe el nombre o el número de empleado; la lista se actualiza sola mientras escribes. "Limpiar filtros" regresa todo a como estaba.',
            },
            {
                selector: sel('expedientes-filtros'),
                titulo: 'Filtrar la plantilla',
                texto: 'Acota por empresa, sucursal, departamento, puesto, estado (activos, inactivos, bajas) y rango de fecha de ingreso. Al cambiar de empresa, la sucursal se reinicia para no mezclar datos.',
            },
            {
                selector: sel('expedientes-tarjetas'),
                titulo: 'Carpetas de colaboradores',
                texto: 'Cada tarjeta es un expediente. Da clic en cualquiera para abrirlo — ahora mismo te llevo a uno de ejemplo.',
                opcional: true,
            },
            {
                rutaDesde: `${sel('expedientes-tarjetas')} a[href]`,
                selector: sel('expediente-cabecera'),
                titulo: 'Ficha del colaborador',
                texto: 'Arriba ves quién es: puesto, empresa, sucursal, departamento, si tiene acceso al sistema y el porcentaje de avance de su expediente.',
                opcional: true,
            },
            {
                selector: sel('expediente-pestanas'),
                titulo: 'Todo el expediente, por pestañas',
                texto: 'Resumen · Datos personales · Datos laborales · Cuenta (correo, roles, acceso) · Documentos (subir y revisar) · Onboarding · Avisos · Vacaciones · Recibos de nómina · Préstamos · Solicitudes · Historial RH.',
                consejo:
                    'Para dar de baja a alguien no se borra el expediente: desde «Ciclo laboral» se solicita la baja (con aprobaciones y finiquito) y se conserva todo el historial.',
                opcional: true,
            },
        ],
    },
    {
        id: 'actas',
        nombre: 'Actas administrativas',
        ruta: '/rh/actas',
        patron: /^\/rh\/actas/,
        modo: 'operativo',
        permisos: ['actas.ver'],
        grupo: 'Personal',
        icono: FilePen,
        descripcion:
            'Incidencias laborales: se levanta el acta, se genera el formato oficial, se firma (o se asienta la negativa) y queda en el expediente.',
        pasos: [
            {
                titulo: 'Actas administrativas',
                texto: 'Cuando el jefe inmediato o RH detecta una incidencia, levanta el acta aquí. Cada acta queda en el expediente del colaborador con su seguimiento.',
            },
            {
                selector: sel('actas-levantar'),
                titulo: 'Levantar un acta',
                texto: 'Elige al colaborador, el motivo (va en el encabezado: «ACTA ADMINISTRATIVA POR …»), fecha, hora, los hechos, dos testigos y lo que manifiesta cada persona. Nombre, puesto y contrato los pone el sistema.',
                opcional: true,
            },
            {
                selector: sel('actas-filtros'),
                titulo: 'Filtros',
                texto: 'Filtra por tipo de acta y por estado: borrador, formato generado, firmada o cerrada.',
            },
            {
                selector: sel('actas-lista'),
                titulo: 'Abrir un acta',
                texto: 'En el acta generas el formato oficial con los datos del colaborador, lo imprimes, registras la firma o la negativa, subes evidencias y anotas el seguimiento de RH y Jurídico.',
                consejo:
                    'Si falta algún dato para el formato, el sistema te dice cuál antes de generarlo: nunca sale con una línea en blanco.',
            },
        ],
    },
    {
        id: 'solicitudes',
        nombre: 'Solicitudes',
        ruta: '/rh/solicitudes',
        patron: /^\/rh\/solicitudes$/,
        modo: 'operativo',
        permisos: ['solicitudes.revisar', 'solicitudes.aprobar'],
        grupo: 'Personal',
        icono: ClipboardList,
        descripcion:
            'Solicitudes de los colaboradores por tipo (préstamos, permisos, vacaciones…): visto bueno del gerente y del regional, y autorización de RH.',
        pasos: [
            {
                titulo: 'Solicitudes de los colaboradores',
                texto: 'Todo lo que un colaborador pide llega aquí. Primero da su visto bueno el gerente de la sucursal, luego el regional, y al final Recursos Humanos autoriza.',
            },
            {
                selector: sel('solicitudes-tipos'),
                titulo: 'Elige el tipo',
                texto: 'Cada tarjeta es un tipo de solicitud con cuántas están recibidas, pendientes de autorizar o en corrección. Da clic en una para abrir su tablero — ahora te llevo a uno.',
            },
            {
                rutaDesde: `${sel('solicitudes-tipos')} a[href]`,
                selector: sel('solicitudes-limite'),
                titulo: 'Aviso de límite',
                texto: 'El tablero muestra hasta cierto número de tarjetas para mantenerse ágil. Cuando aparece este aviso, usa los filtros para ver el resto: ninguna solicitud se pierde.',
                opcional: true,
            },
            {
                selector: sel('solicitudes-exportar'),
                titulo: 'Exportar',
                texto: 'Descarga las solicitudes a Excel o PDF con los filtros aplicados.',
            },
            {
                selector: sel('solicitudes-filtros'),
                titulo: 'Filtros',
                texto: 'Busca por folio o motivo, filtra por tipo y sucursal. El botón "Filtros" abre más opciones: empresa, departamento, puesto, responsable RH y fechas de registro.',
            },
            {
                selector: sel('solicitudes-tablero'),
                titulo: 'El tablero',
                texto: 'Columnas por estado: Solicitudes recibidas (esperan el visto bueno del gerente y del regional) → Pendiente de autorizar (ya con ambos vistos buenos) → Requiere corrección → Aprobadas / No aprobadas.',
            },
            {
                selector: '[data-tour="solicitudes-tablero"] [data-kanban-id]',
                titulo: 'Una solicitud',
                texto: 'Muestra al colaborador, folio, tipo, motivo, sucursal y fecha. Las etiquetas avisan si es urgente, si le falta evidencia, si el finiquito está pendiente o si falta una firma.',
                consejo:
                    'Arrastra la tarjeta desde el ícono de puntos para cambiarla de columna. Rechazar o pedir corrección exige un comentario, que queda en el historial. El ícono de documento abre el detalle completo.',
                opcional: true,
            },
        ],
    },
    {
        id: 'organigrama',
        nombre: 'Organigrama',
        ruta: '/administracion/jerarquia-puestos',
        patron: /^\/administracion\/jerarquia-puestos$/,
        modo: 'operativo',
        permisos: ['organigrama.ver'],
        grupo: 'Estructura',
        icono: GitBranch,
        descripcion:
            'Quién reporta a quién: Dirección General, Dirección Comercial y sus áreas, gerencias regionales Q1/Q3 y cada sucursal, con vacantes y coberturas.',
        pasos: [
            {
                titulo: 'Organigrama de puestos',
                texto: 'Muestra la jerarquía de puestos — a quién reporta cada uno — y quién ocupa cada puesto, con su nombre y foto. La estructura territorial (regiones, zonas, rutas) vive aparte, en Matriz comercial.',
            },
            {
                selector: sel('organigrama-buscar'),
                titulo: 'Buscar puesto o persona',
                texto: 'Escribe un puesto o el nombre de alguien: las tarjetas que coinciden se resaltan y el resto se atenúa, sin perder la forma del árbol.',
            },
            {
                selector: sel('encabezado'),
                titulo: 'Matriz comercial',
                texto: 'Este botón te lleva a la estructura territorial. Son dos vistas distintas y complementarias: aquí puestos, allá territorio.',
            },
            {
                selector: sel('organigrama-filtros'),
                titulo: 'Filtros',
                texto: 'Arriba eliges la vista (por personas o por puestos). El botón "Filtros" abre un panel con empresa, sucursal, departamento y tipo de puesto, para que no ocupen espacio sobre el árbol.',
            },
            {
                selector: sel('organigrama-arbol'),
                titulo: 'El árbol',
                texto: '"Por personas" empieza por la estructura corporativa (Dirección General → Dirección Comercial → Asistente, Sistemas, Recursos Humanos, Coordinación Regional y Gerencias Regionales Q1/Q3) y sigue con cada sucursal de su región: Gerente de Sucursal → Subgerente → Gestores (con su ruta) y Gestor Volante. Un puesto sin titular aparece como VACANTE y, si alguien lo cubre temporalmente, se indica quién y cuál es su puesto titular.',
                consejo:
                    'En celular el árbol se muestra como una lista desplegable, con las mismas acciones.',
                opcional: true,
            },
        ],
    },
    {
        id: 'responsables-aprobacion',
        nombre: 'Responsables de aprobación',
        ruta: '/administracion/responsables-aprobacion',
        patron: /^\/administracion\/responsables-aprobacion$/,
        modo: 'operativo',
        permisos: ['organigrama.ver'],
        grupo: 'Estructura',
        icono: ShieldCheck,
        descripcion:
            'Quién da el visto bueno de las solicitudes de cada persona o puesto, cuando no es su jefe del organigrama.',
        pasos: [
            {
                titulo: 'Jefe organizacional ≠ responsable de aprobaciones',
                texto: 'El jefe organizacional sale del organigrama (a quién reporta). El responsable de aprobaciones es quien autoriza sus solicitudes: por omisión sigue la regla del organigrama; aquí RH asigna a otra persona cuando debe ser distinta.',
            },
            {
                selector: sel('responsables-asignados'),
                titulo: 'Asignados por RH',
                texto: 'Cada tarjeta es una regla para una persona o para todo un puesto: quién aprueba, el motivo y cuándo se asignó. Quitarla regresa a la regla del organigrama.',
            },
            {
                selector: sel('responsables-personas'),
                titulo: 'Personas',
                texto: 'Para cada persona ves su jefe organizacional y su responsable de aprobaciones. Las marcadas en dorado tienen aprobaciones detenidas (por ejemplo, sin región Q1/Q3 asignada).',
                consejo:
                    'Activa «Solo responsables distintos o detenidos» para revisar solo los casos especiales.',
            },
        ],
    },
    {
        id: 'vacantes',
        nombre: 'Vacantes',
        ruta: '/rh/vacantes',
        patron: /^\/rh\/vacantes$/,
        modo: 'operativo',
        permisos: ['vacantes.ver'],
        grupo: 'Reclutamiento',
        icono: Briefcase,
        descripcion:
            'Plazas autorizadas que faltan por cubrir, una fila por vacante, con sus candidatos.',
        pasos: [
            {
                titulo: 'Vacantes',
                texto: 'Una vacante es una plaza autorizada (headcount) que nadie ocupa. Se abren y cierran solas: cuando alguien sale, la vacante aparece; cuando entra alguien a esa plaza, se cierra.',
            },
            {
                selector: sel('encabezado'),
                titulo: 'Exportar',
                texto: 'Descarga la cobertura a Excel o PDF con los filtros aplicados.',
            },
            {
                selector: sel('vacantes-resumen'),
                titulo: 'Cuántas y dónde',
                texto: 'Plazas por cubrir de cada puesto (gerentes, gestores, coordinadoras…) y en qué sucursales. Un clic en el puesto o en la sucursal filtra la lista.',
            },
            {
                selector: sel('vacantes-filtros'),
                titulo: 'Filtros',
                texto: 'Busca por sucursal, departamento o puesto, o filtra por empresa, sucursal, departamento y puesto.',
            },
            {
                selector: sel('vacantes-lista'),
                titulo: 'Una fila por vacante',
                texto: 'Cada fila es una vacante real: qué puesto falta, en qué sucursal, cuántas plazas faltan («4 de 5 autorizadas ocupadas»), desde cuándo está abierta y cuántos candidatos lleva. Las vacantes se abren y cierran solas según la plantilla autorizada.',
                consejo:
                    'Para cubrir una vacante, registra y avanza candidatos en el módulo Candidatos.',
            },
        ],
    },
    {
        id: 'perfiles-puesto',
        nombre: 'Descriptivos y perfiles de puesto',
        ruta: '/rh/perfiles-puesto',
        patron: /^\/rh\/perfiles-puesto/,
        modo: 'operativo',
        permisos: ['perfiles_puesto.ver'],
        grupo: 'Reclutamiento',
        icono: ClipboardList,
        descripcion: 'El deber ser de cada puesto y a quién buscamos para él.',
        pasos: [
            {
                titulo: 'Descriptivos y perfiles',
                texto: 'Cada puesto tiene dos partes: el descriptivo (objetivo, funciones, responsabilidades e indicadores) y el perfil del candidato (escolaridad, experiencia, conocimientos, habilidades, competencias, valores y requisitos).',
            },
            {
                titulo: 'Quién lo edita',
                texto: 'Solo RH edita (botón Editar, en su propia página); gerencias y Dirección los consultan. «Reporta a» y «Supervisa» salen solos del organigrama.',
                consejo:
                    'Si un puesto no tiene perfil, se abre un borrador con lo que ya conocemos del puesto: revísalo y guárdalo.',
            },
            {
                titulo: 'En la ficha del candidato',
                texto: 'Quien entrevista ve en la ficha del candidato qué buscamos para el puesto al que se postula.',
            },
        ],
    },
    {
        id: 'candidatos',
        nombre: 'Candidatos',
        ruta: '/rh/candidatos',
        patron: /^\/rh\/candidatos$/,
        modo: 'operativo',
        permisos: ['candidatos.ver'],
        grupo: 'Reclutamiento',
        icono: UserRound,
        descripcion:
            'Tablero de reclutamiento por fases, del Filtro RH hasta la contratación.',
        pasos: [
            {
                titulo: 'Tablero de reclutamiento',
                texto: 'Cada candidato avanza por fases, desde el Filtro RH hasta que se contrata (o se cierra su proceso). Cada avance se hace con su acción en la ficha del candidato.',
            },
            {
                selector: sel('candidatos-nuevo'),
                titulo: 'Registrar un candidato',
                texto: 'Nombre y contacto, la vacante real (de ella salen puesto y sucursal), la campaña de esa vacante por la que llegó y la fuente. Si aún no hay vacante, márcalo como espontáneo.',
            },
            {
                selector: sel('candidatos-kpis'),
                titulo: 'Indicadores del periodo',
                texto: 'Recibidos, en proceso, finalistas y contratados; tasa de conversión, tiempo promedio de contratación y, si registras campañas, gasto, costo por candidato y por contratación.',
            },
            {
                selector: sel('candidatos-filtros'),
                titulo: 'Filtros',
                texto: 'Busca por nombre o correo y filtra por mes, fuente, empresa, sucursal y puesto. En "Filtros" hay más: departamento, responsable RH y fechas.',
            },
            {
                selector: sel('candidatos-tablero'),
                titulo: 'Fases del proceso',
                texto: 'Filtro RH → Entrevista → Psicométricos → Socioeconómico → Contratación (referencias, autorización de RH y QR) → Contratado, más las columnas de cierre Rechazado y Desistió. «No viable», «No respondió» y «No seleccionado» son motivos dentro de Rechazado.',
                consejo:
                    'Arrastrar solo sirve para cerrar el proceso (a Rechazado o Desistió, con motivo). Para avanzar, abre la ficha y usa la acción del paso.',
            },
            {
                selector: `${sel('candidatos-tablero')} [draggable="true"]`,
                titulo: 'Tarjeta de candidato',
                texto: 'Puesto objetivo, nombre, sucursal, fuente, responsable, días que lleva en la fase actual y su última nota. "Ver CV" abre su currículum.',
                consejo:
                    'Da clic en la tarjeta para abrir su ficha: ahí ves qué paso sigue, quién lo tiene y, ya autorizado por RH, «Generar QR de contratación».',
                opcional: true,
            },
        ],
    },
    {
        id: 'campanas',
        nombre: 'Campañas',
        ruta: '/rh/campanas',
        patron: /^\/rh\/campanas$/,
        modo: 'operativo',
        permisos: ['reclutamiento.campanas.ver'],
        grupo: 'Reclutamiento',
        icono: Megaphone,
        descripcion:
            'Campañas sobre vacantes reales: qué se publicó, cuánto costó y qué produjo.',
        pasos: [
            {
                titulo: 'Campañas de reclutamiento',
                texto: 'Cada campaña parte de una vacante real y registra lo que se publicó en un canal (Meta Ads, Indeed, Computrabajo, LinkedIn, referidos…) para saber cuánto cuesta conseguir un candidato y una contratación.',
            },
            {
                selector: sel('campanas-nueva'),
                titulo: 'Registrar una campaña',
                texto: 'Elige la vacante real (empresa, sucursal, departamento y puesto salen de ella) y captura canal, responsable, fechas, presupuesto, gasto, sueldo publicado, copy, liga y la evidencia en PDF o imagen.',
            },
            {
                selector: sel('campanas-resumen'),
                titulo: 'Totales del periodo',
                texto: 'Gasto, colaboradores contratados y costo promedio por colaborador de las campañas filtradas.',
            },
            {
                selector: sel('campanas-filtros'),
                titulo: 'Filtros',
                texto: 'Mes, año, canal, empresa, sucursal, departamento y puesto.',
            },
            {
                selector: sel('tabla'),
                titulo: 'Campañas registradas',
                texto: 'Cada tarjeta es una campaña con su embudo (candidatos → contactados → entrevistas → psicométricos → contratados), costo por candidato, por colaborador, conversión y días para cubrir. Desde sus acciones la editas o eliminas.',
            },
        ],
    },
    {
        id: 'invitaciones',
        nombre: 'Invitaciones QR',
        ruta: '/rh/incorporacion/invitaciones',
        patron: /^\/rh\/incorporacion\/invitaciones$/,
        modo: 'operativo',
        permisos: ['rh.incorporacion.invitaciones.ver'],
        grupo: 'Reclutamiento',
        icono: QrCode,
        descripcion:
            'QR temporal para que un colaborador nuevo se registre en la app e incorpore su expediente.',
        pasos: [
            {
                titulo: 'Incorporación con QR',
                texto: 'Nadie puede registrarse en la app sin una invitación activa. El primer QR se genera desde la ficha del candidato autorizado («Generar QR de contratación»); aquí ves el estado de todos y vuelves a emitir los que vencieron.',
            },
            {
                selector: sel('invitaciones-nueva'),
                titulo: 'Volver a emitir un QR',
                texto: 'Para una persona en contratación que no alcanzó a usar su QR: elígela y define su vigencia (de 1 a 24 horas).',
                opcional: true,
            },
            {
                selector: sel('invitaciones-filtros'),
                titulo: 'Filtros',
                texto: 'Busca por nombre, correo o código y filtra por estado: Activo, Usado, Vencido o Revocado. En "Filtros" puedes acotar por empresa y sucursal.',
            },
            {
                selector: sel('tabla'),
                titulo: 'Invitaciones',
                texto: 'Destinatario, sucursal, estado, fecha de vencimiento y quién la creó. "Ver" abre el detalle con el QR para compartirlo.',
                consejo:
                    'Las invitaciones vencen: si alguien no alcanzó a usarla, genera una nueva.',
            },
        ],
    },
    {
        id: 'reportes',
        nombre: 'Reportes',
        ruta: '/reportes',
        patron: /^\/reportes$/,
        modo: 'operativo',
        permisos: ['reportes_rh.ver'],
        grupo: 'Análisis',
        icono: Activity,
        descripcion:
            'Tablas cruzadas filtrables, con gráficas, exportables a Excel o PDF.',
        pasos: [
            {
                titulo: 'Reportes de RH',
                texto: 'Elige un reporte, filtra y obtén al instante una gráfica y la tabla de resultados, lista para exportar.',
            },
            {
                selector: sel('reportes-tipo'),
                titulo: 'Elige el reporte',
                texto: 'Los reportes están agrupados por tema. Al elegir uno, la gráfica y la tabla se recalculan.',
            },
            {
                selector: sel('reportes-filtros'),
                titulo: 'Filtra',
                texto: 'Empresa, sucursal, departamento y puesto. "Limpiar filtros" vuelve a la vista general.',
            },
            {
                selector: sel('reportes-grafica'),
                titulo: 'Gráfica',
                texto: 'Visualiza el resultado. Si hay muchas categorías se muestran las de mayor valor; el detalle completo está en la exportación.',
                opcional: true,
            },
            {
                selector: sel('reportes-tabla'),
                titulo: 'Tabla de resultados',
                texto: 'El detalle del reporte con el número de resultados encontrados.',
            },
            {
                selector: sel('encabezado'),
                titulo: 'Exportar',
                texto: 'Descarga exactamente lo que ves (mismo reporte y filtros) a Excel o PDF, con la gráfica incluida. Los botones aparecen si tu rol tiene permiso de exportar.',
            },
        ],
    },
    {
        id: 'rotacion',
        nombre: 'Rotación de personal',
        ruta: '/reportes/rotacion',
        patron: /^\/reportes\/rotacion$/,
        modo: 'operativo',
        permisos: ['rotacion.ver'],
        grupo: 'Análisis',
        icono: TrendingDown,
        descripcion:
            'Índice de rotación mensual por sucursal, cargado desde el Excel de RH (bajas × 100 / plantilla del mes).',
        pasos: [
            {
                titulo: 'Rotación de personal',
                texto: 'Aquí ves la rotación oficial que RH reporta cada mes: se carga desde el Excel de rotación, no se calcula en vivo.',
            },
            {
                selector: sel('rotacion-filtros'),
                titulo: 'Filtra',
                texto: 'Elige la sucursal y hasta qué mes ver. «Todas» muestra el consolidado de la empresa.',
            },
            {
                selector: sel('rotacion-indicadores'),
                titulo: 'Indicadores',
                texto: 'Altas, bajas, plantilla al cierre, promedio mensual y la rotación acumulada de los meses con datos.',
            },
            {
                selector: sel('rotacion-grafica'),
                titulo: 'Tendencia mensual',
                texto: 'El índice de rotación mes a mes. Si todavía no hay datos cargados, aquí lo indica.',
                opcional: true,
            },
            {
                selector: sel('rotacion-tabla'),
                titulo: 'Detalle mensual',
                texto: 'Plantilla inicial, altas, bajas (voluntarias e involuntarias si el Excel las trae) e índice de cada mes.',
            },
            {
                selector: sel('rotacion-carga'),
                titulo: 'Cargar el Excel de rotación',
                texto: 'Descarga el formato, llénalo y súbelo: verás una vista previa con los hallazgos (filas o columnas que no cuadran) antes de confirmar. Nada se aplica sin tu confirmación.',
                opcional: true,
            },
        ],
    },
    {
        id: 'cumpleanos',
        nombre: 'Cumpleaños',
        ruta: '/rh/cumpleanos',
        patron: /^\/rh\/cumpleanos$/,
        modo: 'operativo',
        permisos: ['rh.cumpleanos.ver'],
        grupo: 'Personal',
        icono: Cake,
        descripcion:
            'Calendario de cumpleaños del equipo, con tarjeta de felicitación personalizable.',
        // Selectores del componente compartido CelebracionesPanel (idéntico
        // en Cumpleaños y Aniversarios): no dependen de "encabezado" ni
        // "indicadores", que esa pantalla ya no tiene.
        pasos: [
            {
                titulo: 'Cumpleaños del equipo',
                texto: 'Quién cumple hoy, el calendario del mes y los próximos cumpleaños, con una tarjeta de felicitación lista para descargar, copiar o enviar.',
            },
            {
                selector: sel('celebraciones-tabs'),
                titulo: 'Cumpleaños | Aniversarios',
                texto: 'Celebraciones tiene dos pestañas con la misma forma de trabajo. Aniversarios aparece si tu rol puede verlos.',
            },
            {
                selector: sel('celebraciones-filtros'),
                titulo: 'Buscar y filtrar',
                texto: 'Busca a una persona por nombre y filtra por empresa, sucursal, departamento o estatus. El rango de "Próximos" también se ajusta aquí.',
            },
            {
                selector: sel('cumpleanos-sin-fecha'),
                titulo: 'Datos faltantes',
                texto: 'Estos colaboradores activos no tienen fecha de nacimiento, así que no aparecen en el calendario. Complétala en su expediente.',
                opcional: true,
            },
            {
                selector: sel('celebraciones-hoy'),
                titulo: 'Cumpleañeros de hoy',
                texto: 'Siempre visibles arriba, con foto, nombre completo y puesto · sucursal. "Ver tarjeta", "Descargar" y "Copiar" trabajan con la tarjeta; "Enviar al colaborador" le manda su felicitación y "Avisar a todos" notifica al resto del equipo.',
                consejo:
                    'Cada botón muestra si ya se envió y cuándo, para no felicitar dos veces.',
            },
            {
                selector: sel('celebraciones-calendario'),
                titulo: 'Calendario del mes',
                texto: 'Cambia de mes con las flechas y regresa al actual con "Hoy". Los días con cumpleaños están marcados; da clic para ver quién.',
                opcional: true,
            },
            {
                selector: sel('celebraciones-proximos'),
                titulo: 'Próximos cumpleaños',
                texto: 'Los siguientes cumpleaños dentro del rango elegido.',
            },
            {
                selector: sel('celebraciones-periodo'),
                titulo: 'Cumpleaños del mes',
                texto: 'Listado completo del mes que estás viendo en el calendario.',
            },
            {
                selector: sel('celebraciones-configuracion'),
                titulo: 'Configurar la tarjeta',
                texto: 'Fondo de la tarjeta y frases de felicitación, con vista previa. Solo aparece si tu rol puede configurarlo.',
                opcional: true,
            },
        ],
    },
    {
        id: 'aniversarios',
        nombre: 'Aniversarios',
        ruta: '/rh/aniversarios',
        patron: /^\/rh\/aniversarios(\/configuracion)?$/,
        modo: 'operativo',
        permisos: ['celebraciones.ver'],
        grupo: 'Personal',
        icono: Award,
        // Aniversarios es una pestaña de Celebraciones: el sidebar solo
        // enlaza aquí directo si el usuario no ve Cumpleaños.
        selectorMenu: `[data-sidebar="sidebar"] a[href="/rh/aniversarios"], ${sel('celebraciones-tabs')} a[href="/rh/aniversarios"]`,
        descripcion:
            'Aniversarios laborales del equipo (años cumplidos en MR. LANA), con tarjeta y avisos.',
        pasos: [
            {
                titulo: 'Aniversarios laborales',
                texto: 'Un aniversario laboral es cada año que un colaborador cumple trabajando en MR. LANA. Se calcula con su fecha de ingreso: el día y mes de ingreso, cada año, cuenta un año más.',
                consejo:
                    'Quien no tiene fecha de ingreso capturada no aparece: complétala en su expediente.',
            },
            {
                selector: sel('celebraciones-tabs'),
                titulo: 'Cumpleaños | Aniversarios',
                texto: 'Misma pantalla y misma forma de trabajo que Cumpleaños: cambia de pestaña cuando quieras.',
            },
            {
                selector: sel('celebraciones-filtros'),
                titulo: 'Buscar y filtrar',
                texto: 'Busca por nombre y filtra por empresa, sucursal, departamento o estatus.',
            },
            {
                selector: sel('celebraciones-hoy'),
                titulo: 'Aniversarios de hoy',
                texto: 'Quién cumple años en la empresa hoy y cuántos, con foto, nombre completo y puesto · sucursal.',
            },
            {
                selector: sel('celebraciones-hoy'),
                titulo: 'Tarjeta y mensajes',
                texto: '"Ver tarjeta" muestra la tarjeta con el mensaje institucional y los años cumplidos; "Generar" la vuelve a crear, "Descargar" y "Copiar" sirven para compartirla por WhatsApp o correo.',
            },
            {
                selector: sel('celebraciones-hoy'),
                titulo: 'Enviar al colaborador y avisar a todos',
                texto: '"Enviar al colaborador" le manda su felicitación como notificación en el portal y en la app. "Avisar a todos" notifica a todos los colaboradores activos. Cada botón muestra cuándo se hizo, para no repetirlo por error.',
            },
            {
                selector: sel('celebraciones-calendario'),
                titulo: 'Calendario',
                texto: 'Aniversarios del mes, día por día. Cambia de mes con las flechas y regresa con "Hoy".',
                opcional: true,
            },
            {
                selector: sel('celebraciones-proximos'),
                titulo: 'Próximos aniversarios',
                texto: 'Los siguientes aniversarios dentro del rango elegido, con los años que cumplirá cada persona.',
            },
            {
                selector: sel('celebraciones-periodo'),
                titulo: 'Aniversarios del mes',
                texto: 'Listado completo del mes del calendario.',
            },
            {
                selector: sel('celebraciones-configuracion'),
                titulo: 'Configuración',
                texto: 'Activa o desactiva los aniversarios, edita el mensaje institucional (usa {anios} para los años cumplidos), decide si la felicitación se envía sola al colaborador y cambia el fondo de la tarjeta, con vista previa.',
                opcional: true,
            },
        ],
    },

    // ───────────────────────── Administración ─────────────────────────
    {
        id: 'documentos-maestros',
        nombre: 'Documentos maestros',
        ruta: '/rh/documentos-maestros',
        patron: /^\/rh\/documentos-maestros$/,
        modo: 'operativo',
        permisos: ['plantillas_documentales.administrar'],
        grupo: 'Administración',
        icono: FileStack,
        descripcion:
            'Formatos jurídicos originales: se cargan una vez y PEOPLE los llena en cada proceso.',
        pasos: [
            {
                titulo: 'Documentos maestros',
                texto: 'Aquí viven los formatos originales de Jurídico (contratos, convenios, actas, permiso, préstamo…). No se escriben marcadores: PEOPLE pone los datos del colaborador donde corresponden y respeta el diseño original.',
            },
            {
                selector: sel('documentos-maestros-lista'),
                titulo: 'Versiones y estado',
                texto: 'Cada documento muestra su versión activa, a qué puestos aplica, cuántos campos se detectaron y si queda alguno pendiente. «Ver» muestra el detalle con quién cargó, activó o probó cada versión.',
                consejo:
                    'Los documentos de cada persona se generan desde su proceso (alta, baja, permiso, préstamo), no desde aquí.',
            },
        ],
    },
    {
        id: 'empresas',
        nombre: 'Empresas',
        ruta: '/administracion/empresas',
        patron: /^\/administracion\/empresas$/,
        modo: 'operativo',
        permisos: ['empresas.ver'],
        grupo: 'Administración',
        icono: Landmark,
        descripcion:
            'Estructura multiempresa: cada sucursal, colaborador y expediente pertenece a una empresa.',
        pasos: pasosCatalogo({
            queEs: 'El sistema es multiempresa: toda sucursal, colaborador y expediente pertenece a una empresa. Aquí se da de alta y se mantiene cada razón social.',
            crear: '"Nueva empresa" abre el formulario de alta.',
            indicadores:
                'Total de empresas, cuántas están activas y cuántas inactivas.',
            busqueda: 'Busca por nombre, razón social o RFC.',
            tabla: 'Empresa, RFC, número de sucursales y de colaboradores, y estado. Desde las acciones de cada fila puedes editarla o eliminarla.',
        }),
    },
    {
        id: 'usuarios',
        nombre: 'Usuarios',
        ruta: '/administracion/usuarios',
        patron: /^\/administracion\/usuarios$/,
        modo: 'operativo',
        permisos: ['usuarios.ver'],
        grupo: 'Administración',
        icono: Users,
        descripcion:
            'Cuentas de acceso: correo, roles, estado y seguridad de cada usuario.',
        pasos: pasosCatalogo({
            queEs: 'Administra las CUENTAS DE ACCESO (correo, roles, estado, verificación en dos pasos). Los datos laborales no viven aquí sino en Expedientes.',
            crear: '"Nuevo usuario" crea la cuenta de acceso para un colaborador que ya fue dado de alta.',
            indicadores:
                'Total de cuentas y cuántas tienen el acceso bloqueado.',
            busqueda: 'Busca por nombre o correo.',
            tabla: 'Colaborador, correo, roles, estado de acceso, correo verificado y último acceso. Desde las acciones de cada fila puedes editar la cuenta y sus roles o gestionar su contraseña.',
            consejo:
                'Las cuentas nunca se borran: si alguien sale, se bloquea su acceso y se conserva su historial.',
        }),
    },
    {
        id: 'sucursales',
        nombre: 'Sucursales',
        ruta: '/administracion/sucursales',
        patron: /^\/administracion\/sucursales$/,
        modo: 'operativo',
        permisos: ['sucursales.administrar'],
        grupo: 'Administración',
        icono: Building2,
        descripcion:
            'Ubicaciones de la empresa y el alcance de cada responsable.',
        pasos: pasosCatalogo({
            queEs: 'Cada colaborador trabaja en una sucursal, y lo que cada responsable puede ver depende de las sucursales que tiene asignadas.',
            crear: '"Nueva sucursal" registra una ubicación con su clave y ciudad.',
            indicadores:
                'Total de sucursales, cuántas están activas y cuántas inactivas.',
            busqueda: 'Busca por nombre o clave.',
            tabla: 'Sucursal, empresa, clave, ciudad, número de colaboradores y estado. Desde las acciones de cada fila puedes editarla.',
        }),
    },
    {
        id: 'departamentos',
        nombre: 'Departamentos',
        ruta: '/administracion/departamentos',
        patron: /^\/administracion\/departamentos$/,
        modo: 'operativo',
        permisos: ['departamentos.administrar', 'puestos.administrar'],
        grupo: 'Administración',
        icono: Briefcase,
        descripcion: 'Áreas de la empresa que agrupan puestos y colaboradores.',
        pasos: pasosCatalogo({
            queEs: 'Organiza a los colaboradores por área y agrupa los puestos de cada una.',
            crear: '"Nuevo departamento" crea un área nueva.',
            indicadores:
                'Total de departamentos, cuántos están activos y cuántos inactivos.',
            busqueda: 'Busca un departamento por nombre.',
            tabla: 'Departamento, número de puestos y de colaboradores, y estado. Desde las acciones de cada fila puedes editarlo o eliminarlo.',
        }),
    },
    {
        id: 'puestos',
        nombre: 'Puestos',
        ruta: '/administracion/puestos',
        patron: /^\/administracion\/puestos$/,
        modo: 'operativo',
        permisos: ['departamentos.administrar', 'puestos.administrar'],
        grupo: 'Administración',
        icono: Briefcase,
        descripcion: 'Catálogo de puestos de cada departamento.',
        pasos: pasosCatalogo({
            queEs: 'Define los puestos de cada departamento. De aquí se alimentan Expedientes, Vacantes y el Organigrama.',
            crear: '"Nuevo puesto" crea un puesto dentro de un departamento.',
            indicadores:
                'Total de puestos, cuántos están activos y cuántos inactivos.',
            busqueda: 'Busca un puesto por nombre.',
            tabla: 'Puesto, departamento, número de colaboradores y estado. Desde las acciones de cada fila puedes editarlo o eliminarlo.',
            consejo:
                'La jerarquía entre puestos (a quién reporta cada uno) se arma en el Organigrama.',
        }),
    },
    {
        id: 'roles',
        nombre: 'Roles y permisos',
        ruta: '/administracion/roles',
        patron: /^\/administracion\/roles$/,
        modo: 'operativo',
        permisos: ['roles.administrar'],
        grupo: 'Administración',
        icono: ShieldCheck,
        descripcion: 'Qué puede hacer cada rol dentro del sistema.',
        pasos: pasosCatalogo({
            queEs: 'Cada usuario tiene uno o más roles, y cada rol es un conjunto de permisos. Los permisos determinan qué módulos ve y qué acciones puede hacer.',
            crear: '"Nuevo rol" crea un rol y te deja marcar sus permisos.',
            indicadores:
                'Roles existentes, colaboradores con rol y permisos disponibles.',
            busqueda: 'Busca un rol por nombre.',
            tabla: 'Cada rol con sus permisos y cuántos colaboradores lo tienen. Desde sus acciones puedes editarlo, clonarlo (para crear uno parecido) o eliminarlo.',
            consejo:
                'Un cambio en un rol afecta de inmediato a todos los usuarios que lo tienen.',
        }),
    },
    {
        id: 'app-releases',
        nombre: 'Versiones de app',
        ruta: '/administracion/app-versiones',
        patron: /^\/administracion\/app-versiones$/,
        modo: 'operativo',
        permisos: ['app_releases.ver'],
        grupo: 'Administración',
        icono: Smartphone,
        descripcion:
            'Publicación del APK de la app móvil para descarga directa.',
        pasos: [
            {
                titulo: 'Versiones de la app móvil',
                texto: 'Mientras la app no esté en Play Store, aquí se publica el APK que los colaboradores descargan.',
            },
            {
                selector: sel('encabezado'),
                titulo: 'Subir una versión',
                texto: '"Subir versión" carga un APK nuevo. Queda como borrador hasta que lo publiques.',
            },
            {
                selector: sel('tabla'),
                titulo: 'Historial de versiones',
                texto: 'Versión, tamaño, estado, quién la subió y cuándo se publicó. Una versión marcada como "Actualización obligatoria" obliga a actualizar la app.',
                opcional: true,
            },
        ],
    },

    {
        id: 'onboarding',
        nombre: 'Onboarding',
        ruta: '/rh/onboarding/configuracion',
        patron: /^\/rh\/onboarding\/configuracion$/,
        modo: 'operativo',
        permisos: ['onboarding.gestionar'],
        grupo: 'Personal',
        icono: Sparkles,
        descripcion:
            'Lecciones de bienvenida (institucionales y por puesto) y el catálogo de equipo que se entrega al ingresar.',
        pasos: [
            {
                titulo: 'Bienvenida de los nuevos',
                texto: 'Aquí defines las lecciones que responde cada persona nueva y el equipo que recibe. El colaborador solo ve "lecciones de bienvenida", nunca el nombre interno de la etapa.',
            },
            {
                selector: sel('onboarding-modulos'),
                titulo: 'Lecciones',
                texto: 'Material, preguntas y calificación mínima (8 por defecto). Las del puesto solo aparecen a quien ocupa ese puesto.',
            },
            {
                selector: sel('onboarding-activos'),
                titulo: 'Equipo de trabajo',
                texto: 'Uniforme, credencial, casco… Al entregarlo se genera la carta responsiva.',
            },
        ],
    },
    {
        id: 'reingresos',
        nombre: 'Reingresos',
        ruta: '/rh/reingresos',
        patron: /^\/rh\/reingresos$/,
        modo: 'operativo',
        permisos: ['reingresos.solicitar', 'reingresos.gestionar'],
        grupo: 'Personal',
        icono: RotateCcw,
        descripcion:
            'Reincorporar a un excolaborador como la MISMA persona, con su historial.',
        pasos: [
            {
                titulo: 'Volver a contratar a alguien',
                texto: 'Nunca se crea otra persona: se reactiva la misma, con su historial, y solo se le piden los documentos vencidos o faltantes.',
            },
            {
                selector: sel('reingresos-busqueda'),
                titulo: 'Buscar',
                texto: 'Por número de empleado, CURP, RFC, NSS o nombre. Verás su causa de salida antes de solicitar.',
            },
            {
                selector: sel('reingresos-lista'),
                titulo: 'Solicitudes',
                texto: 'Quien solicita propone; Recursos Humanos decide si es viable.',
            },
        ],
    },
    {
        id: 'cartas-compromiso',
        nombre: 'Cartas compromiso',
        ruta: '/rh/cartas-compromiso',
        patron: /^\/rh\/cartas-compromiso(\/.*)?$/,
        modo: 'operativo',
        permisos: ['cartas_compromiso.ver'],
        grupo: 'Personal',
        icono: FilePenLine,
        descripcion:
            'La persona de nuevo ingreso se compromete por escrito a entregar, en un plazo, los documentos que le faltan (máximo 2).',
        pasos: [
            {
                titulo: 'Para qué sirve',
                texto: 'Cuando alguien de nuevo ingreso todavía no tiene todos sus documentos, firma una carta compromiso: dice qué documentos le faltan y la fecha límite para entregarlos. Solo aplica a personas en contratación u onboarding, nunca al personal que ya laboraba.',
            },
            {
                selector: sel('cartas-generar'),
                titulo: 'Generar una carta',
                texto: 'Busca a la persona, marca los documentos que se compromete a entregar (máximo 2) y revisa la vista previa antes de generarla. La pueden generar Recursos Humanos, su gerente de sucursal o su gerente regional.',
                consejo:
                    'Si le faltan más de 2 documentos, no se puede generar: la app le pide subirlos y, en cuanto le falten 2 o menos, la carta se genera sola.',
                opcional: true,
            },
            {
                selector: sel('indicadores'),
                titulo: 'Cómo van las cartas',
                texto: 'Cuántas están vigentes, por vencer, vencidas, cumplidas (ya entregó todo) y firmadas. Una carta vencida limita la app de la persona a subir sus documentos pendientes.',
            },
            {
                selector: sel('cartas-filtros'),
                titulo: 'Filtrar y buscar',
                texto: 'Usa las pestañas para ver solo las pendientes de firma, las vigentes, las vencidas, etc. «Historial (sustituidas)» muestra las cartas que se reemplazaron por una nueva. Busca por nombre, número de empleado, sucursal o folio.',
            },
            {
                selector: sel('cartas-lista'),
                titulo: 'Abrir una carta',
                texto: 'Cada renglón muestra el folio, la persona, los documentos comprometidos, el plazo y si ya está firmada. Da clic en una para abrirla — ahora te llevo a una de ejemplo.',
                opcional: true,
            },
            {
                rutaDesde: `${sel('cartas-lista')} a[href]`,
                selector: sel('carta-acciones'),
                titulo: 'Imprimir y subir la firmada',
                texto: 'Imprime la carta, la persona que entra la firma (con su huella) frente a su gerente o RH, y aquí subes el escaneo firmado. Queda guardada en su expediente.',
                opcional: true,
            },
            {
                selector: sel('carta-documentos'),
                titulo: 'Documentos comprometidos',
                texto: 'Cada documento se marca como «Entregado» en cuanto la persona lo sube a su expediente. Cuando entrega todos, la carta queda cumplida sola.',
                opcional: true,
            },
            {
                selector: sel('carta-linea-tiempo'),
                titulo: 'Línea de tiempo',
                texto: 'Quién la generó y cuándo, cuándo se subió la firma, si se cumplió, venció o fue sustituida por otra.',
                opcional: true,
            },
        ],
    },
    {
        id: 'formularios-evaluacion',
        nombre: 'Formularios de evaluación',
        ruta: '/rh/formularios-evaluacion',
        patron: /^\/rh\/formularios-evaluacion(\/.*)?$/,
        modo: 'operativo',
        permisos: ['formularios_evaluacion.administrar'],
        grupo: 'Administración',
        icono: ClipboardList,
        descripcion:
            'Configura, como en Google Forms, la evaluación de inducción y la de periodo de prueba: preguntas, diseño, vista previa y versiones.',
        pasos: [
            {
                titulo: 'Para qué sirve',
                texto: 'Aquí se arman las dos evaluaciones de ingreso: la de inducción (FO-RH-EI, la contesta el colaborador) y la de capacitación inicial / periodo de prueba (FO-RH-EPP-001, también la contesta el colaborador como autoevaluación; RH decide si se renueva el contrato).',
            },
            {
                selector: sel('formularios-tarjetas'),
                titulo: 'Una tarjeta por evaluación',
                texto: 'Cada tarjeta muestra cuántas preguntas tiene, el total de puntos, con cuánto se aprueba (o se renueva) y qué versión está vigente. Si hay un borrador sin publicar, también lo verás. Da clic para abrir el editor — ahora te llevo a uno.',
            },
            {
                rutaDesde: `${sel('formularios-tarjetas')} a[href]`,
                selector: sel('formulario-estado'),
                titulo: 'Versión y estado',
                texto: 'Indica si estás viendo la versión vigente, un borrador o una versión anterior, y la calificación mínima. Para cambiar algo, pulsa «Editar formulario»: se crea un borrador que se guarda solo mientras escribes.',
                consejo:
                    'Nadie ve tus cambios hasta que pulses «Publicar versión». Las evaluaciones que ya empezaron o ya se contestaron conservan la versión con la que iniciaron.',
                opcional: true,
            },
            {
                selector: sel('formulario-pestanas'),
                titulo: 'Preguntas, diseño, vista previa y versiones',
                texto: '«Preguntas» para el contenido y las reglas de calificación; «Diseño» para el color, el encabezado y el banner; «Vista previa» para verlo tal como lo verá quien lo contesta; «Versiones» para consultar o restaurar una versión anterior.',
                opcional: true,
            },
        ],
    },
    {
        id: 'configuracion',
        nombre: 'Configuración',
        ruta: '/administracion/configuracion/notificaciones',
        patron: /^\/administracion\/configuracion(\/.*)?$/,
        modo: 'operativo',
        permisos: ['configuracion.ver'],
        grupo: 'Administración',
        icono: Settings2,
        descripcion:
            'A quién llega cada aviso, parámetros de RH y colores institucionales. El jefe directo sale del organigrama.',
        pasos: [
            {
                titulo: 'Configuración del sistema',
                texto: 'Lo que el negocio ajusta sin programar. Lo esencial no se apaga: Recursos Humanos siempre da la autorización final.',
            },
            {
                selector: sel('configuracion-secciones'),
                titulo: 'Secciones',
                texto: 'Solo ves las que tu permiso te deja administrar.',
            },
            {
                titulo: 'Notificaciones: a quién le llega cada aviso',
                texto: 'Cada renglón es un momento del sistema (por ejemplo, «llegó una solicitud de vacaciones»). Con «Editar» eliges a quién avisar: quien lo pidió, su jefe directo, el regional, RH o personas con nombre. Esto solo decide quién se entera; quién autoriza no cambia.',
            },
            {
                titulo: 'Avisar según lo que pueden hacer',
                texto: 'Si marcas «Quienes pueden hacer cierta acción», eliges una acción en palabras normales (por ejemplo, «Autorizar préstamos»). Les llega a todas las personas que pueden hacerla y que tienen a esa persona dentro de su sucursal o región.',
            },
            {
                titulo: 'El botón «?»',
                texto: 'Junto a cada opción hay un «?»: tócalo y te explica para qué sirve antes de cambiar algo.',
                consejo:
                    '«Si nadie aplica» es el respaldo: si no se encuentra a nadie (por ejemplo, el puesto del jefe está vacante), el aviso llega a esas personas para que nunca se pierda.',
            },
        ],
    },

    // ───────────────────────── Modo colaborador ─────────────────────────
    {
        id: 'portal',
        nombre: 'Mi portal',
        ruta: '/mi-portal',
        patron: /^\/mi-portal$/,
        modo: 'colaborador',
        permisos: [],
        grupo: 'Mi espacio',
        icono: UserRound,
        descripcion:
            'Tu espacio personal: perfil, vacaciones, solicitudes y notificaciones.',
        pasos: [
            {
                titulo: 'Tu portal personal',
                texto: 'Aquí tienes a la mano lo tuyo: tu perfil, tus días de vacaciones, tus solicitudes y tus avisos.',
            },
            {
                selector: sel('portal-encabezado'),
                titulo: 'Tus datos',
                texto: 'Tu nombre, puesto, sucursal y número de empleado. La campana de la derecha te lleva a tus notificaciones.',
            },
            {
                selector: sel('portal-pendientes'),
                titulo: 'Lo que necesitas hacer',
                texto: 'Cuando Recursos Humanos te pida algo (subir un documento, firmar, responder tus lecciones de bienvenida) aparece aquí. Toca cualquiera para hacerlo.',
                opcional: true,
            },
            {
                selector: sel('portal-accesos'),
                titulo: 'Accesos rápidos',
                texto: 'Atajos a tu perfil, tu expediente, tus vacaciones y tus solicitudes.',
            },
            {
                selector: sel('portal-vacaciones'),
                titulo: 'Tus vacaciones',
                texto: 'Días generados, usados y disponibles en el periodo actual.',
            },
            {
                selector: sel('portal-solicitudes'),
                titulo: 'Solicitudes recientes',
                texto: 'Tus últimas solicitudes con su estado. "Ver todas" abre la lista completa.',
            },
            {
                selector: sel('portal-notificaciones'),
                titulo: 'Notificaciones',
                texto: 'Avisos de RH y cambios en tus solicitudes. Las no leídas llevan un punto de color.',
            },
        ],
    },
    {
        id: 'mi-expediente',
        nombre: 'Mi expediente',
        ruta: '/mi-expediente',
        patron: /^\/mi-expediente$/,
        modo: 'colaborador',
        permisos: [],
        grupo: 'Mi espacio',
        icono: FolderKanban,
        descripcion: 'Tus datos, tus documentos y tu historial en la empresa.',
        pasos: [
            {
                titulo: 'Tu expediente',
                texto: 'Aquí está todo lo tuyo que guarda Recursos Humanos: datos personales y laborales, documentos, vacaciones, préstamos y solicitudes.',
            },
            {
                selector: sel('expediente-cabecera'),
                titulo: 'Tu ficha',
                texto: 'Tu puesto, sucursal y qué tan completo está tu expediente.',
            },
            {
                selector: sel('expediente-pestanas'),
                titulo: 'Secciones',
                texto: 'En "Documentos" subes lo que te pidan y ves si ya fue aprobado. Si un dato tuyo está mal o falta, usa «Solicitar corrección»: Recursos Humanos lo autoriza y se actualiza solo.',
            },
        ],
    },
    {
        id: 'mis-solicitudes',
        nombre: 'Mis solicitudes',
        ruta: '/solicitudes',
        patron: /^\/solicitudes$/,
        modo: 'colaborador',
        permisos: ['portal.solicitudes.ver', 'solicitudes.crear'],
        grupo: 'Mi espacio',
        icono: ClipboardList,
        descripcion:
            'Pide vacaciones, permisos, préstamos y más, y sigue su estado.',
        pasos: [
            {
                titulo: 'Tus solicitudes',
                texto: 'Permisos, vacaciones y préstamos, todo en un solo lugar. Tu gerente y tu regional dan su visto bueno y Recursos Humanos autoriza.',
            },
            {
                selector: sel('mis-solicitudes-nueva'),
                titulo: 'Hacer una solicitud',
                texto: 'Primero eliges qué necesitas y después llenas un formulario corto (fechas, motivo, evidencia si aplica). Para vacaciones verás tu saldo de días antes de enviar.',
            },
            {
                selector: sel('mis-solicitudes-lista'),
                titulo: 'Seguimiento',
                texto: 'Cada tarjeta muestra folio, tipo, motivo y estado. Da clic para ver el detalle y el historial.',
                consejo:
                    'Si RH te pide una corrección, la solicitud cambia a "Requiere corrección": ábrela, ajusta lo indicado y reenvíala.',
                opcional: true,
            },
        ],
    },
    {
        id: 'directorio',
        nombre: 'Directorio',
        ruta: '/directorio',
        patron: /^\/directorio$/,
        modo: 'colaborador',
        permisos: [],
        grupo: 'Mi espacio',
        icono: Contact,
        descripcion:
            'Encuentra a un compañero de cualquier sucursal y su contacto corporativo.',
        pasos: [
            {
                titulo: 'Directorio de la empresa',
                texto: 'Busca por nombre o filtra por puesto, sucursal o departamento. Solo verás datos de contacto CORPORATIVO (correo y teléfono de trabajo), nunca datos personales.',
                consejo:
                    'No todos tienen correo corporativo: si alguien no lo tiene, el directorio no inventa uno.',
            },
        ],
    },
    {
        id: 'directorio-operativo',
        nombre: 'Directorio',
        ruta: '/directorio',
        patron: /^\/directorio$/,
        modo: 'operativo',
        permisos: [],
        grupo: 'Operación diaria',
        icono: Contact,
        descripcion:
            'Contacto corporativo de cualquier colaborador activo de la empresa.',
        pasos: [
            {
                titulo: 'Directorio de la empresa',
                texto: 'Mismo directorio que ven los colaboradores: nombre, puesto, sucursal, departamento y correo/teléfono corporativo. Para datos personales usa Expedientes (con permiso).',
            },
        ],
    },
    {
        id: 'induccion',
        nombre: 'Inducción Mr. Lana',
        ruta: '/mi-proceso/induccion/ver',
        patron: /^\/mi-proceso\/induccion\/ver$/,
        modo: 'colaborador',
        permisos: [],
        grupo: 'Mi espacio',
        icono: GraduationCap,
        descripcion:
            'La inducción institucional que te presenta Recursos Humanos.',
        pasos: [
            {
                titulo: 'Tu inducción',
                texto: 'Recursos Humanos te presenta la inducción en una sesión. Mientras esa sesión está en curso puedes verla aquí, página por página; al terminar, RH te envía tu evaluación para que la contestes. Antes de la sesión y una vez que la terminas, aquí verás en qué paso vas.',
            },
        ],
    },
    {
        id: 'presentar-induccion',
        nombre: 'Presentar inducción',
        ruta: '/induccion/presentacion',
        patron: /^\/induccion\/presentacion$/,
        modo: 'operativo',
        permisos: ['onboarding.gestionar'],
        grupo: 'Desarrollo',
        icono: GraduationCap,
        descripcion:
            'Presenta la inducción Mr. Lana en una sesión (por Meet o en sala).',
        pasos: [
            {
                titulo: 'Modo presentación',
                texto: 'Muestra el PDF oficial de la inducción, el mismo que ve el colaborador. Usa «Presentación» para pantalla completa. No registra avance de nadie ni habilita evaluaciones: el colaborador las presenta desde su cuenta.',
            },
        ],
    },
];
