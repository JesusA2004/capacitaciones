import type { ArticuloGuia } from '../tipos';

/**
 * Proceso 14 — Administración y trabajo diario. Fuente real:
 *  - App\Http\Controllers\Rh\PendienteController + TareaService (Mis pendientes)
 *  - App\Services\Headcount\PlantillaAutorizadaService (plantilla por sucursal, motivo obligatorio)
 *  - App\Services\Configuracion\ConfiguracionSistemaService (parámetros de RH, meses por puesto)
 *  - App\Services\Autenticacion\CredencialesService (usuario + contraseña temporal)
 *  - database/seeders/RolesYPermisosSeeder.php (roles base)
 *  - config/configuracion_sistema.php (eventos de notificación)
 *  - App\Services\DocumentosMaestros (formatos originales de Jurídico)
 */
export const ARTICULOS_ADMINISTRACION: ArticuloGuia[] = [
    {
        id: 'mis-pendientes',
        titulo: 'Mis pendientes: tu bandeja de trabajo',
        proceso: 'administracion',
        resumen:
            'Todo lo que alguien espera que hagas (entrevistar, autorizar, evaluar, entregar equipo, firmar) en un solo lugar.',
        queEs: 'Es la bandeja de tareas del ciclo laboral. Cada pendiente es una acción concreta; al resolverla en su pantalla desaparece sola.',
        quien: ['rh', 'gerente', 'regional'],
        antes: [
            'Participar en algún paso del ciclo laboral (tu rol lo determina).',
        ],
        pasos: [
            {
                titulo: 'Abre Mis pendientes',
                detalle:
                    'Inicio → Mis pendientes (o la pestaña Pendientes de la app).',
                canal: 'web_app',
            },
            {
                titulo: 'Atiende lo urgente',
                detalle:
                    'Filtra por etapa, sucursal o prioridad; primero lo vencido.',
            },
            {
                titulo: 'Abre el pendiente',
                detalle:
                    'Te lleva directo a la ficha del candidato o al ciclo laboral de la persona, donde está el botón de la acción.',
            },
        ],
        despues: [
            'La campana de notificaciones también te avisa de cada pendiente nuevo.',
        ],
        errores: [
            {
                problema: '«Un pendiente no es mío.»',
                solucion:
                    'Los pendientes se asignan por el organigrama (jefe directo) y por permisos. Si el jefe de alguien está mal, se corrige en el organigrama, no a mano.',
            },
        ],
        relacionados: [
            'etapas-del-candidato',
            'firma-y-original',
            'evaluacion-capacitacion',
        ],
        accesos: [
            {
                etiqueta: 'Ir a Mis pendientes',
                ruta: '/rh/pendientes',
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Qué tengo pendiente?',
            '¿Dónde veo lo que me toca hacer?',
        ],
        recorrido: 'primer-dia-rh',
    },
    {
        id: 'estructura-y-plantilla',
        titulo: 'Estructura, plantilla autorizada y duración por puesto',
        proceso: 'administracion',
        resumen:
            'Empresas, sucursales, departamentos y puestos. Gerencia de RH captura la plantilla autorizada (con motivo) y la duración de capacitación de cada puesto.',
        queEs: 'Es el esqueleto del sistema: de aquí salen las vacantes, el organigrama y los contratos.',
        quien: ['rh'],
        quienDetalle:
            'La plantilla autorizada solo la cambia Gerencia de RH (y super administración).',
        antes: ['Tener permisos de administración de catálogos.'],
        pasos: [
            {
                titulo: 'Plantilla autorizada',
                detalle:
                    'Administración → Sucursales → detalle de la sucursal → lápiz en el puesto (o «Agregar puesto»): nueva cifra y motivo obligatorio. La vacante del par se recalcula al guardar.',
                canal: 'web',
            },
            {
                titulo: 'Duración de capacitación por puesto',
                detalle:
                    'Administración → Configuración → Parámetros de RH: meses de capacitación inicial de cada puesto (por ejemplo, Gestor 2; Gerente y Regional 3) y su grupo (Gestores, Gerencia…).',
            },
            {
                titulo: 'Otros parámetros de RH',
                detalle:
                    'Calificación mínima del onboarding, días de aviso antes del vencimiento (15), calificación mínima de la evaluación, causas de baja solicitables, domicilio del patrón en documentos y vigencia de documentos.',
            },
            {
                titulo: 'Organigrama',
                detalle:
                    'Organización → Organigrama: quién reporta a quién. De aquí sale el jefe directo de cada persona.',
            },
        ],
        llamados: [
            {
                tono: 'importante',
                texto: 'Organigrama, Matriz comercial, Headcount y Vacantes son cosas distintas: el organigrama dice quién reporta a quién; la matriz, qué ruta cubre cada gestor; el headcount, cuántas plazas hay; las vacantes, cuántas faltan.',
            },
        ],
        despues: ['Cada cambio queda en la bitácora con antes → después.'],
        errores: [
            {
                problema: '«No puedo editar la plantilla.»',
                solucion: 'Solo Gerencia de RH (permiso de editar headcount).',
            },
        ],
        registro: [
            'Historial de plantilla por sucursal y bitácora de configuración.',
        ],
        relacionados: ['plantilla-y-vacantes', 'contrato-inicial'],
        accesos: [
            {
                etiqueta: 'Ir a Sucursales',
                ruta: '/administracion/sucursales',
                permisos: ['sucursales.administrar'],
                modo: 'operativo',
            },
            {
                etiqueta: 'Ir a Parámetros de RH',
                ruta: '/administracion/configuracion/parametros-rh',
                permisos: ['configuracion.rh'],
                modo: 'operativo',
            },
            {
                etiqueta: 'Ir al Organigrama',
                ruta: '/administracion/jerarquia-puestos',
                permisos: ['organigrama.ver'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo cambio la plantilla autorizada?',
            '¿Dónde configuro la duración del contrato por puesto?',
            '¿Cómo cambio el jefe de alguien?',
        ],
    },
    {
        id: 'usuarios-y-roles',
        titulo: 'Cuentas, credenciales y roles (Sistemas)',
        proceso: 'administracion',
        resumen:
            'Sistemas recibe las altas y bajas para preparar o retirar accesos. Las cuentas nunca se borran: se bloquean.',
        queEs: 'La cuenta (usuario y contraseña) es distinta de la persona (expediente). Quitar el acceso nunca borra al colaborador.',
        quien: ['sistemas', 'rh'],
        antes: ['Tener permiso de usuarios o de roles.'],
        pasos: [
            {
                titulo: 'Alta → preparar accesos',
                detalle:
                    'Llega «Alta: Nombre (Puesto, Sucursal). Prepara su cuenta, accesos y equipo.» Revisa sus roles en Administración → Usuarios.',
                quien: 'sistemas',
            },
            {
                titulo: '«Generar credenciales»',
                detalle:
                    'Expediente → pestaña Cuenta (o Usuarios → «Nuevo usuario»): crea la cuenta o da una contraseña temporal nueva. Muestra usuario y contraseña una sola vez para entregarlos en privado.',
                quien: 'rh',
            },
            {
                titulo: 'Baja → retirar accesos',
                detalle:
                    'Llega «Baja: Nombre… Retira accesos, cuentas y equipo.» PEOPLE ya bloqueó su acceso a la app y al portal; Sistemas retira lo externo (correo, equipo).',
                quien: 'sistemas',
            },
            {
                titulo: 'Roles y permisos',
                detalle:
                    'Administración → Roles y permisos: clona un rol parecido y ajusta permisos.',
                quien: 'sistemas',
            },
        ],
        llamados: [
            {
                tono: 'info',
                texto: 'Usuario = primer nombre + primer apellido sin acentos (por ejemplo «Juan Perez»); si se repite, «Juan Perez2». El correo es opcional. La contraseña temporal obliga a cambiarla al primer inicio.',
            },
        ],
        despues: [
            'Revocar acceso solo bloquea el inicio de sesión (incapacidad, suspensión); el empleo no cambia.',
        ],
        errores: [
            {
                problema: '«No puedo generar credenciales a alguien de baja.»',
                solucion: 'Es correcto: primero debe reactivarse o reingresar.',
            },
            {
                problema: '«No puedo generarme credenciales a mí mismo.»',
                solucion: 'Por seguridad nadie opera su propia cuenta.',
            },
        ],
        notificaciones: ['Alta y baja de colaborador → RH y Sistemas.'],
        registro: ['Cambios de cuenta y roles en la bitácora.'],
        relacionados: ['alta-activa', 'baja'],
        accesos: [
            {
                etiqueta: 'Ir a Usuarios',
                ruta: '/administracion/usuarios',
                permisos: ['usuarios.ver'],
                modo: 'operativo',
            },
            {
                etiqueta: 'Ir a Roles y permisos',
                ruta: '/administracion/roles',
                permisos: ['roles.administrar'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Qué hace Sistemas en PEOPLE?',
            '¿Cómo genero credenciales?',
            '¿Cómo quito el acceso a alguien?',
        ],
    },
    {
        id: 'configuracion-notificaciones',
        titulo: 'A quién le llega cada notificación',
        proceso: 'administracion',
        resumen:
            'Configuración → Notificaciones decide quién se entera de cada evento. Notificar no es autorizar.',
        queEs: 'Es el ruteo de avisos: para cada momento del sistema eliges a quién avisar.',
        quien: ['rh', 'sistemas'],
        antes: ['Permiso de configuración de notificaciones.'],
        pasos: [
            {
                titulo: 'Abre Notificaciones',
                detalle: 'Administración → Configuración → Notificaciones.',
                canal: 'web',
            },
            {
                titulo: 'Edita un evento',
                detalle:
                    'Por ejemplo «Alta de colaborador»: RH y Sistemas. Puedes elegir quien lo pidió, su jefe directo, el gerente, el regional, Gerencia de RH, RH, quienes pueden hacer cierta acción o personas con nombre.',
            },
            {
                titulo: 'Usa el «?»',
                detalle:
                    'Cada opción explica para qué sirve antes de cambiarla.',
            },
        ],
        llamados: [
            {
                tono: 'importante',
                texto: 'Cambiar quién se ENTERA no cambia quién AUTORIZA. Recursos Humanos siempre da la autorización final.',
            },
        ],
        despues: [
            'Si un evento se queda sin destinatario, PEOPLE avisa al respaldo seguro (RH) y lo registra.',
        ],
        errores: [],
        registro: ['Cada cambio de regla queda en la bitácora.'],
        relacionados: ['usuarios-y-roles'],
        accesos: [
            {
                etiqueta: 'Ir a Notificaciones',
                ruta: '/administracion/configuracion/notificaciones',
                permisos: ['configuracion.notificaciones'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo cambio a quién le llegan las notificaciones?',
            '¿Por qué no me llegó una notificación?',
        ],
    },
    {
        id: 'documentos-maestros',
        titulo: 'Documentos maestros (formatos de Jurídico)',
        proceso: 'administracion',
        resumen:
            'Los formatos originales se cargan una vez; PEOPLE los llena con los datos de cada persona en su proceso.',
        queEs: 'Son los formatos oficiales de MR. LANA (contratos, permiso, préstamo, baja, actas). No se escriben marcadores: PEOPLE pone los datos donde corresponden.',
        quien: ['rh'],
        quienDetalle: 'Gerencia de RH (y Jurídico consulta).',
        antes: ['Tener el original que entregó Jurídico.'],
        pasos: [
            {
                titulo: 'Carga la versión',
                detalle:
                    'Administración → Documentos maestros → el documento → cargar nueva versión del original.',
                canal: 'web',
            },
            {
                titulo: 'Prueba',
                detalle:
                    'Revisa los campos detectados y genera una prueba con un colaborador.',
            },
            {
                titulo: 'Activa',
                detalle:
                    'Los procesos nuevos usan la versión activa; lo ya generado no cambia.',
            },
        ],
        despues: [
            'Si a un puesto le falta su formato, el proceso avisa «Falta el formato oficial» en lugar de usar otro.',
        ],
        errores: [
            {
                problema: '«El contrato dice Falta el formato oficial.»',
                solucion: 'Carga y activa la versión de ese documento.',
            },
        ],
        registro: ['Quién cargó, probó, activó o desactivó cada versión.'],
        relacionados: ['contrato-inicial', 'permisos', 'prestamos'],
        accesos: [
            {
                etiqueta: 'Ir a Documentos maestros',
                ruta: '/rh/documentos-maestros',
                permisos: ['plantillas_documentales.administrar'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo cargo un formato de contrato?',
            '¿Por qué dice falta el formato oficial?',
        ],
    },
    {
        id: 'app-movil',
        titulo: 'La app móvil: descarga y versiones',
        proceso: 'administracion',
        resumen:
            'Mientras no esté en Play Store, la app se descarga desde /app; Sistemas publica cada versión.',
        queEs: 'Es la app MR. LANA PEOPLE para el colaborador (y su modo de gestión para RH y gerentes).',
        quien: ['sistemas', 'colaborador'],
        antes: ['Tener el archivo de la versión (APK).'],
        pasos: [
            {
                titulo: 'Descargar',
                detalle:
                    'Cualquier persona abre la página «Descargar app» (también aparece al escanear un QR sin la app).',
                quien: 'colaborador',
            },
            {
                titulo: 'Publicar una versión',
                detalle:
                    'Administración → Versiones de app → «Subir versión» (queda en borrador) → publicar. «Actualización obligatoria» obliga a todos a actualizar.',
                quien: 'sistemas',
                canal: 'web',
            },
        ],
        despues: ['La app avisa sola cuando hay una versión nueva.'],
        errores: [
            {
                problema: '«Todos tienen que actualizar.»',
                solucion:
                    'Marca una versión como obligatoria solo cuando sea necesario.',
            },
        ],
        relacionados: ['app-colaborador'],
        accesos: [
            { etiqueta: 'Descargar la app', ruta: '/app' },
            {
                etiqueta: 'Ir a Versiones de app',
                ruta: '/administracion/app-versiones',
                permisos: ['app_releases.ver'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Dónde descargo la app?',
            '¿Cómo publico una versión de la app?',
        ],
    },
];
