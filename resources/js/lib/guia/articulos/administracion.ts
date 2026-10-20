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
                    'Organización → Sucursales → detalle de la sucursal → lápiz en el puesto (o «Agregar puesto»): nueva cifra y motivo obligatorio. La vacante del par se recalcula al guardar.',
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
            {
                titulo: 'Mover personas entre departamentos',
                detalle:
                    'Organización → Departamentos → abre el departamento: marca a todas o a algunas personas (el buscador filtra la lista mientras escribes), elige el departamento destino, revisa el resumen («Vas a mover 3 personas de Ventas a Operaciones») y confirma. Cada persona queda con su movimiento laboral «Cambio de departamento».',
                canal: 'web',
            },
        ],
        llamados: [
            {
                tono: 'importante',
                texto: 'Organigrama, Matriz comercial, Headcount y Vacantes son cosas distintas: el organigrama dice quién reporta a quién; la matriz, qué ruta cubre cada gestor; el headcount, cuántas plazas hay; las vacantes, cuántas faltan.',
            },
            {
                tono: 'info',
                texto: 'Sucursales y Departamentos son módulos distintos: en Sucursales vive la plantilla autorizada de cada sucursal; en Departamentos, el catálogo de áreas y el cambio de departamento en lote. Cambiar de departamento no cambia puesto, sucursal ni plantilla.',
            },
        ],
        despues: ['Cada cambio queda en la bitácora con antes → después.'],
        errores: [
            {
                problema: '«No puedo editar la plantilla.»',
                solucion: 'Solo Gerencia de RH (permiso de editar headcount).',
            },
            {
                problema: '«No aparece la casilla para moverme a mí.»',
                solucion:
                    'Nadie cambia su propio departamento: pídeselo a otra persona de RH.',
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
            {
                etiqueta: 'Ir a Departamentos',
                ruta: '/administracion/departamentos',
                permisos: ['departamentos.administrar'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo cambio la plantilla autorizada?',
            '¿Dónde configuro la duración del contrato por puesto?',
            '¿Cómo cambio el jefe de alguien?',
            '¿Cómo muevo a varias personas de departamento?',
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
    {
        id: 'directorio',
        titulo: 'Directorio y correo corporativo',
        proceso: 'administracion',
        resumen:
            'Todos ven nombre, puesto, área y sucursal de las personas activas. El correo y teléfono corporativos dependen de tu rol: un colaborador operativo ve los de su jefe directo; el personal corporativo autorizado, RH y Sistemas ven todos. Nunca datos personales.',
        queEs: 'El directorio muestra nombre, puesto, área y sucursal. El contacto corporativo (correo y teléfono) se muestra según el permiso «Ver contactos de todo el directorio»; sin él, solo el de tu jefe directo. El correo corporativo es distinto del correo con el que la persona inicia sesión.',
        quien: [
            'colaborador',
            'gerente',
            'regional',
            'direccion_comercial',
            'sistemas',
            'rh',
        ],
        antes: [
            'RH capturó el correo/teléfono corporativo en el expediente (Datos personales) de quien lo tiene.',
        ],
        pasos: [
            {
                titulo: 'Buscar',
                detalle:
                    'Directorio (web o app): por nombre o número de empleado, o filtra por puesto, sucursal o departamento.',
                canal: 'web_app',
            },
            {
                titulo: 'Contactar',
                detalle:
                    'En la app, toca el correo o el teléfono para escribir o llamar.',
                canal: 'app',
            },
        ],
        despues: [],
        errores: [
            {
                problema: '«Alguien no tiene correo en el directorio.»',
                solucion:
                    'No lo tiene capturado: PEOPLE nunca inventa uno ni usa su correo de acceso o personal. RH lo captura en su expediente.',
            },
        ],
        relacionados: ['expediente'],
        accesos: [
            {
                etiqueta: 'Ir al Directorio',
                ruta: '/directorio',
                modo: 'colaborador',
            },
        ],
        preguntas: [
            '¿Dónde encuentro el correo de un compañero?',
            '¿Por qué alguien no tiene correo corporativo?',
        ],
    },
    {
        id: 'importador-salarios',
        titulo: 'Importar salarios y vacaciones (REPORTE_TRABAJADORES_VAC)',
        proceso: 'administracion',
        resumen:
            'Carga el reporte de RH con vista previa: actualiza salario diario y registra días de vacaciones tomados antes de PEOPLE. Nunca crea personas.',
        queEs: 'El importador lee el Excel oficial, busca a cada persona por nombre EXACTO dentro de su sucursal y propone cambios. Nada se aplica hasta que el super administrador confirma.',
        // Las importaciones y cargas masivas son solo del super
        // administrador (2026-10-20): RH no lo ve; consulta el resultado en
        // el historial de cada persona.
        quien: [],
        antes: ['Tener el archivo REPORTE_TRABAJADORES_VAC.xlsx del periodo.'],
        pasos: [
            {
                titulo: 'Subir y previsualizar',
                detalle:
                    'Configuración → «Importar datos» → Salarios y vacaciones (o Permanencia → «Importar salarios y vacaciones»). Cada fila queda como Aplicable, Requiere revisión, Sin cambios, No encontrado o Error, con su motivo.',
                canal: 'web',
            },
            {
                titulo: 'Aplicar',
                detalle:
                    'Solo las filas «Aplicable». El salario mensual se recalcula como diario × 30 y queda en el historial de salarios.',
                canal: 'web',
            },
        ],
        llamados: [
            {
                tono: 'aviso',
                texto: 'Solo el super administrador importa datos (carga inicial, salarios y vacaciones, contactos de emergencia, rotación, recibos por archivo, descriptivos). RH consulta los resultados, pero no ve estas pantallas.',
            },
            {
                tono: 'importante',
                texto: 'Quien no cumple 1 año de antigüedad NO recibe ajuste de vacaciones anuales aunque el Excel traiga días otorgados/tomados (el salario sí se actualiza). Un nombre que coincide con 2+ personas va a revisión, nunca se adivina.',
            },
        ],
        despues: [
            'El mismo archivo no se puede aplicar dos veces (se reconoce por su huella).',
            'Los cambios aparecen en el historial (timeline) de cada persona.',
        ],
        errores: [
            {
                problema: '«La sucursal no se encontró.»',
                solucion:
                    'El nombre de sucursal debe coincidir con el catálogo. Solo se aceptan los recortes conocidos del reporte (p. ej. ATLACOMULC, SAN LUIS P).',
            },
        ],
        registro: [
            'Historial de salarios y ajustes de vacaciones con quién los aplicó y desde qué importación.',
        ],
        relacionados: ['vacaciones', 'historial-persona'],
        accesos: [
            {
                etiqueta: 'Ir a Importar salarios y vacaciones',
                ruta: '/rh/importaciones',
                permisos: ['colaboradores.importar_salarios_vacaciones'],
                modo: 'operativo',
            },
        ],
        preguntas: [
            '¿Cómo actualizo salarios desde el Excel?',
            '¿Por qué no se aplicaron vacaciones a alguien?',
        ],
    },
    {
        id: 'historial-persona',
        titulo: 'Historial (timeline) de una persona',
        proceso: 'administracion',
        resumen:
            'Una sola línea de tiempo de candidato a excolaborador: contratación, inducción, salarios, vacaciones, solicitudes, periodo de prueba, baja y reingreso.',
        queEs: 'El historial no es un registro aparte: lee lo que ya existe (bitácora, seguimientos, salarios, ajustes de vacaciones y solicitudes) y lo ordena por fecha.',
        quien: ['rh', 'gerente'],
        antes: [],
        pasos: [
            {
                titulo: 'Abrir el ciclo laboral de la persona',
                detalle:
                    'Desde su expediente o Mis pendientes → «Ciclo laboral»; en la app, la ficha de onboarding de la persona.',
                canal: 'web_app',
            },
        ],
        despues: [],
        errores: [],
        relacionados: ['importador-salarios'],
        accesos: [],
        preguntas: ['¿Dónde veo todo lo que le ha pasado a un colaborador?'],
    },
];
