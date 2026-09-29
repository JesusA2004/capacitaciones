import {
    Activity,
    Award,
    Briefcase,
    Building2,
    Cake,
    ClipboardList,
    FileStack,
    FolderKanban,
    GitBranch,
    Landmark,
    LayoutGrid,
    Megaphone,
    QrCode,
    ShieldCheck,
    Smartphone,
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
            'Tablero operativo: indicadores, gráficas de cobertura, plantilla, rotación, aniversarios y alertas.',
        pasos: [
            {
                titulo: 'Tu tablero de Inicio',
                texto: 'Es lo primero que ves al entrar. Resume en tiempo real cómo está la operación de RH dentro de las sucursales que te corresponden: no necesitas abrir cada módulo para saber qué requiere atención.',
            },
            {
                selector: sel('dashboard-kpis'),
                titulo: 'Indicadores clave',
                texto: 'Colaboradores activos, altas en proceso, bajas del mes, solicitudes y vacaciones pendientes, expedientes completos e incompletos, documentos por revisar, vacantes, candidatos, rutas cubiertas y cumpleaños de la semana.',
                consejo:
                    'Los indicadores con ícono rojo o ámbar (bajas, pendientes, incompletos, rutas sin cubrir) son los que suelen pedir atención primero.',
            },
            {
                selector: sel('dashboard-cobertura'),
                titulo: 'Cobertura y reclutamiento',
                texto: 'Vacantes por puesto, candidatos por etapa del proceso, cobertura de rutas y solicitudes por estado. Pasa el cursor sobre cada barra o sector para ver el valor exacto.',
            },
            {
                selector: sel('dashboard-plantilla'),
                titulo: 'Plantilla y cumplimiento documental',
                texto: 'Dónde está la plantilla activa (colaboradores por sucursal) y qué tan al día están los expedientes y sus documentos.',
            },
            {
                selector: sel('dashboard-rotacion'),
                titulo: 'Rotación de personal',
                texto: 'Altas, bajas y plantilla del periodo. Aquí mismo puedes filtrar por sucursal, departamento y periodo sin salir de Inicio.',
                opcional: true,
            },
            {
                selector: sel('dashboard-aniversarios'),
                titulo: 'Próximos aniversarios laborales',
                texto: 'Quién cumple años en la empresa en los próximos 30 días. Los que están a una semana o menos se resaltan en verde.',
            },
            {
                selector: sel('dashboard-alertas'),
                titulo: 'Alertas RH',
                texto: 'Avisos automáticos de lo que requiere acción. Si todo está en orden, verás "Sin alertas por ahora".',
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
                    'Para dar de baja a alguien no se borra el expediente: se registra una solicitud de baja y, al aprobarse, se bloquea su acceso conservando todo.',
                opcional: true,
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
            'Tablero de revisión de vacaciones, permisos, bajas y demás solicitudes de los colaboradores.',
        pasos: [
            {
                titulo: 'Bandeja unificada de solicitudes',
                texto: 'Todo lo que un colaborador pide — vacaciones, permisos, préstamos, incapacidades, bajas y más — llega aquí como una tarjeta. Tu trabajo es moverla por su flujo hasta resolverla.',
            },
            {
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
                texto: 'Columnas por estado: Pendientes/Enviadas → En revisión → Requiere corrección → Aprobadas / Rechazadas → Cerradas. Desliza horizontalmente para ver todas.',
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
                selector: sel('indicadores'),
                titulo: 'Indicadores de cobertura',
                texto: 'Vacantes abiertas, plazas por cubrir, candidatos en proceso, días promedio que llevan abiertas y costo mensual de esas plazas.',
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
        id: 'candidatos',
        nombre: 'Candidatos',
        ruta: '/rh/candidatos',
        patron: /^\/rh\/candidatos$/,
        modo: 'operativo',
        permisos: ['candidatos.ver'],
        grupo: 'Reclutamiento',
        icono: UserRound,
        descripcion:
            'Tablero de reclutamiento por fases, del primer contacto hasta la contratación.',
        pasos: [
            {
                titulo: 'Tablero de reclutamiento',
                texto: 'Cada candidato avanza por fases, desde que se recibe hasta que se contrata (o se descarta). Aquí das seguimiento a todos los prospectos en un solo lugar.',
            },
            {
                selector: sel('candidatos-nuevo'),
                titulo: 'Registrar un candidato',
                texto: 'Captura a un prospecto nuevo: nombre, datos de contacto, puesto objetivo, sucursal, fuente o canal por el que llegó y la vacante relacionada.',
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
                texto: 'Recibidos → Preselección → Entrevista → Psicométricos → Estudio socioeconómico → Pruebas → Validación documental → Oferta → Listo para contratación → Contratado, más las columnas de descarte (No seleccionado, No viable, No respondió, Desistió).',
                consejo:
                    'Arrastra una tarjeta a otra columna para avanzarla. Mientras arrastras, las columnas a las que no se permite pasar se atenúan: las fases son sucesivas.',
            },
            {
                selector: `${sel('candidatos-tablero')} [draggable="true"]`,
                titulo: 'Tarjeta de candidato',
                texto: 'Puesto objetivo, nombre, sucursal, fuente, responsable, días que lleva en la fase actual y su última nota. "Ver CV" abre su currículum.',
                consejo:
                    'Da clic en la tarjeta para abrir su ficha: ahí registras seguimientos y, cuando está listo, inicias su alta digital.',
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
            'Gasto de reclutamiento por canal y su costo por candidato y por contratación.',
        pasos: [
            {
                titulo: 'Campañas de reclutamiento',
                texto: 'Registra cuánto se invierte en cada canal (Meta, Indeed, Computrabajo, LinkedIn, referidos…) para saber cuánto cuesta realmente conseguir un candidato y una contratación.',
            },
            {
                selector: sel('campanas-nueva'),
                titulo: 'Registrar una campaña',
                texto: 'Captura mes, año, canal y monto invertido. Opcionalmente, a qué empresa, sucursal, departamento o puesto corresponde.',
            },
            {
                selector: sel('indicadores'),
                titulo: 'Resultados del periodo',
                texto: 'Gasto total, candidatos generados, costo por candidato, contratados y costo por contratación.',
            },
            {
                selector: sel('campanas-filtros'),
                titulo: 'Filtros',
                texto: 'Mes, año, canal, empresa, sucursal, departamento y puesto.',
            },
            {
                selector: sel('tabla'),
                titulo: 'Campañas registradas',
                texto: 'Cada fila es una campaña con su gasto y resultados. Desde el menú de acciones de cada fila puedes editarla o eliminarla.',
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
                texto: 'Nadie puede registrarse en la app sin una invitación activa. Generas un QR temporal, el colaborador nuevo lo escanea y completa su alta y expediente desde su celular.',
            },
            {
                selector: sel('invitaciones-nueva'),
                titulo: 'Generar una invitación',
                texto: 'Crea el QR a partir de un candidato listo para contratación y elige cuánto tiempo estará vigente.',
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
        id: 'formatos',
        nombre: 'Formatos',
        ruta: '/rh/formatos',
        patron: /^\/rh\/(formatos(\/catalogo)?|formatos-oficiales(\/(generados|variables))?|plantillas)$/,
        modo: 'operativo',
        permisos: ['formatos_oficiales.ver'],
        grupo: 'Personal',
        icono: FileStack,
        descripcion:
            'Documentos oficiales de MR. LANA y plantillas Word por clave, precargados con los datos del colaborador.',
        pasos: [
            {
                titulo: 'Formatos',
                texto: 'Aquí se generan los documentos de MR. LANA ya llenos con los datos del colaborador o candidato. Hay dos motores: las plantillas oficiales (PDF fijos de la empresa) y las plantillas Word por clave (DOCX con marcadores {{...}}).',
            },
            {
                selector: sel('formatos-pestanas'),
                titulo: 'Pestañas',
                texto: 'Plantillas oficiales, Documentos generados y Variables son del motor oficial (PDF). Plantillas Word por clave y Generados (Word) son del motor Word. Solo ves las pestañas que tu rol permite.',
            },
            // ── Plantillas oficiales ──
            {
                selector: sel('formatos-catalogo'),
                titulo: 'Plantillas oficiales',
                texto: 'Cada tarjeta es un formato oficial. "Listo" significa que ya puede generarse; "Falta configurar" significa que aún no se ha indicado dónde va cada dato sobre el PDF.',
                consejo:
                    'Da clic en "Generar", elige al colaborador o candidato, revisa la vista previa y descarga. "Configurar campos" (administradores) ubica los datos sobre el PDF y publica una nueva versión.',
                opcional: true,
            },
            // ── Documentos generados (motor oficial) ──
            {
                ruta: '/rh/formatos-oficiales/generados',
                selector: sel('formatos-generados-tabla'),
                titulo: 'Documentos generados',
                texto: 'Historial de todo lo generado con las plantillas oficiales: quién, para quién, cuándo y con qué versión. Desde aquí vuelves a descargar un documento o subes el ejemplar firmado.',
                opcional: true,
            },
            // ── Variables ──
            {
                ruta: '/rh/formatos-oficiales/variables',
                selector: sel('formatos-variables'),
                titulo: 'Variables',
                texto: 'Catálogo de los datos que las plantillas oficiales saben llenar solas (nombre, CURP, puesto, sucursal, fechas, salario…), con su clave, tipo y un ejemplo. Los datos salariales solo los ve quien tiene permiso.',
                consejo:
                    'Estas claves (p. ej. colaborador.curp) son del motor oficial. Las plantillas Word usan claves cortas como {{curp}}: se ven en "Variables" de cada plantilla Word.',
            },
            // ── Plantillas Word por clave ──
            {
                ruta: '/rh/plantillas',
                permisos: ['plantillas.ver'],
                titulo: 'Prepara el Word con {{marcadores}}',
                texto: 'En Word escribe el documento tal cual y, donde va un dato, pon su clave entre llaves dobles: {{nombre_completo}}, {{curp}}, {{puesto}}, {{fecha_ingreso}}. Guárdalo como .docx.',
                consejo:
                    'Claves conocidas: nombre_completo, curp, rfc, nss, puesto, sucursal, departamento, empresa, fecha_ingreso, sueldo_diario, fecha_actual… Escribe cada marcador de corrido, sin cambiarle el formato a la mitad, para que Word no lo parta.',
            },
            {
                selector: sel('plantillas-word-nueva'),
                permisos: ['plantillas.crear'],
                titulo: 'Sube la plantilla',
                texto: '"Nueva plantilla": nombre, tipo de documento, a qué empresa/sucursal/puesto aplica y el archivo .docx. El sistema lee los marcadores del archivo al subirlo.',
                opcional: true,
            },
            {
                selector: sel('plantillas-word-lista'),
                permisos: ['plantillas.ver'],
                titulo: 'Variables conocidas',
                texto: 'En el menú ⋮ de cada plantilla, "Variables" muestra qué marcadores reconoció el sistema. Los que coinciden con una variable conocida se llenan solos con los datos del expediente.',
                opcional: true,
            },
            {
                permisos: ['plantillas.editar', 'plantillas.crear'],
                titulo: 'Variables manuales',
                texto: 'Un marcador que el sistema no conoce (p. ej. {{monto_prestamo}}) se vuelve variable manual: en "Variables" le das una etiqueta, un tipo (texto, número, fecha, lista) y un valor por defecto, y se captura al generar.',
            },
            {
                permisos: ['plantillas.editar', 'plantillas.crear'],
                titulo: 'Obligatorio u opcional',
                texto: 'En "Variables" marca cada dato como obligatorio u opcional. Si un obligatorio queda vacío, el sistema no deja generar el documento y te dice exactamente cuál falta.',
            },
            {
                permisos: ['plantillas.editar'],
                titulo: 'Nueva versión',
                texto: 'Para corregir una plantilla usa "Editar" y sube el .docx nuevo: el número de versión (v1, v2…) sube. Los documentos que ya se generaron no cambian.',
            },
            // ── Generados (Word) ──
            {
                ruta: '/rh/formatos/catalogo',
                permisos: ['plantillas.crear'],
                selector: sel('formatos-word-plantillas'),
                titulo: 'Genera un documento',
                texto: 'Cada tarjeta es una plantilla Word activa. "Generar" pide al colaborador o candidato y las variables manuales que falten.',
                opcional: true,
            },
            {
                permisos: ['plantillas.crear'],
                titulo: 'Vista previa',
                texto: 'Antes de generar, la vista previa muestra el documento ya lleno y marca los datos faltantes. Si algo falta en el expediente, complétalo ahí primero.',
            },
            {
                permisos: ['plantillas.crear'],
                selector: sel('formatos-word-historial'),
                titulo: 'Descargar Word o PDF',
                texto: 'En el historial, "Word" descarga el .docx generado (editable) y "PDF" lo convierte al momento. Si el archivo ya no está en el almacenamiento, verás un aviso en vez de un error.',
                opcional: true,
            },
            {
                permisos: ['plantillas.crear'],
                titulo: 'Histórico',
                texto: 'El historial de Generados (Word) guarda cada documento con su plantilla, para quién fue, su estado (generado, entregado, firmado) y quién lo generó. Filtra por tipo, estado o fechas y exporta a Excel o PDF.',
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
                selector: sel('portal-accesos'),
                titulo: 'Accesos rápidos',
                texto: 'Atajos a tu perfil, tus vacaciones y tus solicitudes.',
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
                texto: 'Vacaciones, permisos, préstamos, incapacidades y otros trámites internos, todo en un solo lugar.',
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
];
