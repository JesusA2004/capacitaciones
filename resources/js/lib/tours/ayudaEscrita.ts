/**
 * Guía ESCRITA de cada módulo (página /ayuda → "Leer la guía"): se puede
 * aprender el módulo sin depender del recorrido interactivo. Una entrada por
 * cada `id` de MODULOS_GUIA (tests/Feature/Ayuda/CoberturaGuiaTest.php lo
 * verifica). "Quién tiene permiso" describe los roles BASE sembrados
 * (RolesYPermisosSeeder); un administrador puede ajustarlos en Roles y
 * permisos.
 */
export type AyudaModulo = {
    queEs: string;
    puedes: string[];
    flujo: string[];
    permisos: string;
    errores: string[];
};

export const AYUDA_ESCRITA: Record<string, AyudaModulo> = {
    dashboard: {
        queEs: 'El tablero de Inicio resume en tiempo real la operación de RH de las sucursales que te corresponden.',
        puedes: [
            'Ver indicadores: colaboradores activos, altas, bajas del mes, solicitudes y vacaciones pendientes, expedientes incompletos, vacantes y candidatos.',
            'Consultar gráficas de cobertura, plantilla y reclutamiento.',
            'Filtrar la rotación de personal por sucursal, departamento y periodo, y exportarla.',
            'Ver próximos aniversarios laborales y alertas de RH.',
        ],
        flujo: [
            'Revisa primero los indicadores en rojo o ámbar.',
            'Da clic en el módulo correspondiente (Solicitudes, Expedientes, Vacantes) para atender lo pendiente.',
            'Usa la rotación para revisar altas y bajas del periodo.',
        ],
        permisos:
            'Cualquier cuenta con modo operativo (dashboard global o de sucursal). Lo que ves depende de tu alcance: RH y dirección ven todo; gerentes y coordinadoras, sus sucursales.',
        errores: [
            '«No veo una sucursal»: tu alcance no la incluye; pide a RH que revise tus sucursales asignadas.',
            'Las gráficas tardan un momento en aparecer: se cargan después de los indicadores.',
        ],
    },
    expedientes: {
        queEs: 'La carpeta digital de cada colaborador: datos personales y laborales, documentos, cuenta, vacaciones, recibos, préstamos e historial.',
        puedes: [
            'Buscar y filtrar la plantilla por empresa, sucursal, departamento, puesto y estado.',
            'Abrir un expediente y revisar, aprobar o rechazar documentos.',
            'Editar datos personales y laborales, registrar avisos y generar recibos.',
            'Exportar el listado a Excel o PDF.',
        ],
        flujo: [
            'Busca a la persona por nombre o número de empleado.',
            'Abre su tarjeta y revisa el avance de su expediente.',
            'En «Documentos», revisa lo cargado y aprueba o rechaza con comentario.',
        ],
        permisos:
            'RH (administración y auxiliar), dirección, jurídico y auditoría ven todos los expedientes; gerentes, subgerentes y coordinadoras solo los de su sucursal.',
        errores: [
            'Un expediente «no desaparece» al dar de baja: la baja bloquea el acceso y conserva todo el historial.',
            'El avance solo sube con documentos APROBADOS; cargado o en revisión todavía no cuenta.',
        ],
    },
    solicitudes: {
        queEs: 'La bandeja unificada donde RH y los jefes revisan vacaciones, permisos, préstamos, incapacidades, bajas y demás trámites.',
        puedes: [
            'Ver todas las solicitudes en un tablero por estado.',
            'Mover una solicitud entre columnas (revisar, pedir corrección, aprobar, rechazar).',
            'Abrir el detalle, ver evidencias e historial.',
            'Exportar lo filtrado a Excel o PDF.',
        ],
        flujo: [
            'Filtra por tipo o sucursal.',
            'Abre la tarjeta y revisa motivo y evidencias.',
            'Muévela a «En revisión» y luego a Aprobada, Rechazada o Requiere corrección (estas dos piden comentario).',
        ],
        permisos:
            'RH, gerentes, subgerentes, coordinadoras y jefes directos revisan; aprueban RH, gerencias, jefes directos y dirección. Cada quien ve lo de su alcance.',
        errores: [
            'No puedes rechazar o pedir corrección sin escribir un comentario.',
            'Un préstamo no se aprueba con el botón genérico: se autoriza en su propio flujo.',
            'Si el tablero muestra el aviso de límite, usa filtros: ninguna solicitud se pierde.',
        ],
    },
    organigrama: {
        queEs: 'Quién reporta a quién: la estructura corporativa (Dirección General → Dirección Comercial y sus áreas) y la comercial (Gerencias Regionales Q1/Q3 → sucursales).',
        puedes: [
            'Ver el árbol por personas (una tarjeta por colaborador) o por puestos.',
            'Buscar un puesto o una persona.',
            'Ver puestos VACANTES y quién los cubre temporalmente.',
            'Asignar o terminar una cobertura temporal (si tu rol puede editar).',
        ],
        flujo: [
            'Elige la vista «Por personas».',
            'Busca la sucursal o la persona.',
            'En un puesto VACANTE, usa «Cubrir» para registrar quién lo cubre temporalmente (sin cambiar su puesto titular).',
        ],
        permisos:
            'Consultar: RH, dirección, dirección comercial, gerencias, coordinadoras, jefes directos y auditoría. Editar: quien administra puestos u organigrama.',
        errores: [
            'Cubrir un puesto NO cambia el puesto titular de la persona ni suma plantilla.',
            'La estructura territorial (regiones, rutas) no está aquí sino en Matriz comercial.',
        ],
    },
    vacantes: {
        queEs: 'Las plazas autorizadas que nadie ocupa, una fila por vacante. Se abren y cierran solas según la plantilla autorizada.',
        puedes: [
            'Ver cuántas plazas faltan de cada puesto (gerentes, gestores…) y en qué sucursales.',
            'Ver qué puesto falta, en qué sucursal y cuántas plazas.',
            'Saber desde cuándo está abierta y cuántos candidatos lleva.',
            'Ir directo a los candidatos de esa vacante.',
            'Exportar la lista.',
        ],
        flujo: [
            'Filtra por sucursal o puesto.',
            'Abre los candidatos de la vacante.',
            'Avánzalos en Candidatos; al contratar, la vacante se cierra sola.',
        ],
        permisos:
            'RH, dirección comercial, gerencias, subgerentes, auditoría y dirección.',
        errores: [
            'No hace falta «crear» una vacante tras una baja: aparece sola.',
            'Si cambias a alguien de sucursal o de puesto, la vacante del lugar que deja se abre sola.',
            'El costo no se ve aquí: el costo por colaborador contratado está en Campañas.',
            'Una cobertura temporal no cierra la vacante: la plaza sigue sin titular.',
        ],
    },
    candidatos: {
        queEs: 'El tablero de reclutamiento: cada candidato avanza por fases desde que se recibe hasta que se contrata o se descarta.',
        puedes: [
            'Registrar candidatos con su puesto objetivo, sucursal, fuente y vacante.',
            'Moverlos entre fases y registrar seguimientos.',
            'Ver su CV e iniciar su alta digital.',
        ],
        flujo: [
            'Registra al candidato.',
            'Arrástralo a la siguiente fase conforme avanza (las fases son sucesivas).',
            'Al llegar a «Listo para contratación», inicia su alta o genera su invitación QR.',
        ],
        permisos:
            'RH, dirección comercial, gerencias, subgerentes, auditoría y dirección.',
        errores: [
            'No se puede saltar fases: las columnas no permitidas se atenúan al arrastrar.',
        ],
    },
    campanas: {
        queEs: 'El gasto de reclutamiento por canal, para saber cuánto costó cada colaborador contratado.',
        puedes: [
            'Registrar lo invertido por mes y canal.',
            'Ver por campaña cuántos colaboradores contrató y cuánto costó cada uno.',
            'Ver los totales del periodo: gasto, contratados y costo promedio por colaborador.',
            'Editar o eliminar una campaña.',
        ],
        flujo: [
            'Registra la campaña (mes, año, canal, monto).',
            'Registra a los candidatos con esa fuente.',
            'Revisa los costos del periodo.',
        ],
        permisos: 'RH (administración y auxiliar) y super administración.',
        errores: [
            'Liga al candidato con su campaña al registrarlo; si no, solo se atribuye si su «fuente» se capturó igual al canal.',
        ],
    },
    invitaciones: {
        queEs: 'Códigos QR temporales para que una persona nueva se registre en la app y complete su expediente.',
        puedes: [
            'Generar un QR para un candidato listo para contratación.',
            'Ver su estado: activo, usado, vencido o revocado.',
            'Regenerar o revocar una invitación y descargar el QR.',
        ],
        flujo: [
            'Genera la invitación y elige su vigencia.',
            'Comparte el QR con la persona.',
            'Cuando lo usa, su expediente aparece en Expedientes para revisión.',
        ],
        permisos: 'RH (administración y auxiliar) y super administración.',
        errores: [
            'Nadie puede registrarse en la app sin invitación activa.',
            'Una invitación vencida no se reactiva: genera una nueva.',
        ],
    },
    reportes: {
        queEs: 'Reportes cruzados de RH con gráfica y tabla, listos para exportar.',
        puedes: [
            'Elegir un reporte por tema.',
            'Filtrar por empresa, sucursal, departamento y puesto.',
            'Exportar exactamente lo que ves a Excel o PDF.',
        ],
        flujo: [
            'Elige el reporte.',
            'Aplica filtros.',
            'Exporta si lo necesitas.',
        ],
        permisos:
            'RH, dirección comercial, gerencias, coordinadoras, auditoría y dirección (exportar requiere permiso adicional).',
        errores: [
            'Con muchas categorías la gráfica muestra las principales; el detalle completo está en la exportación.',
        ],
    },
    cumpleanos: {
        queEs: 'Los cumpleaños del equipo: hoy, el calendario del mes y los próximos, con tarjeta de felicitación.',
        puedes: [
            'Ver, descargar o copiar la tarjeta de cada cumpleañero.',
            'Enviar la felicitación al colaborador y avisar a todos.',
            'Configurar el fondo de la tarjeta y las frases.',
        ],
        flujo: [
            'Revisa «Cumpleañeros de hoy».',
            'Abre la tarjeta y envíala o compártela.',
            'Consulta el calendario y los próximos para planear.',
        ],
        permisos: 'RH (administración y auxiliar) y super administración.',
        errores: [
            'Quien no tiene fecha de nacimiento capturada no aparece: complétala en su expediente.',
            'Cada botón indica si ya se envió y cuándo, para no felicitar dos veces.',
        ],
    },
    aniversarios: {
        queEs: 'Los aniversarios laborales: cada año que un colaborador cumple en MR. LANA, calculado con su fecha de ingreso.',
        puedes: [
            'Ver quién cumple hoy, el calendario y los próximos.',
            'Ver, generar, descargar o copiar su tarjeta.',
            'Enviar la felicitación al colaborador y avisar a todos.',
            'Configurar mensaje, fondo, activación y envío automático.',
        ],
        flujo: [
            'Revisa «Aniversarios de hoy».',
            'Abre la tarjeta, revisa y envíala.',
            'En Configuración ajusta el mensaje ({anios}, {nombre}, {sucursal}) y el fondo; la vista previa usa datos de ejemplo.',
        ],
        permisos:
            'Ver: RH (administración y auxiliar) y super administración. Configurar y enviar: administración de RH y super administración.',
        errores: [
            'Sin fecha de ingreso, la persona no aparece.',
            'Un 29 de febrero se celebra el 28 en años no bisiestos.',
        ],
    },
    'documentos-maestros': {
        queEs: 'Los formatos jurídicos originales (contratos, convenios, actas, permiso, préstamo). Se cargan una vez; PEOPLE los llena con los datos del colaborador en cada proceso.',
        puedes: [
            'Ver la versión activa de cada documento y a qué puestos aplica.',
            'Cargar una nueva versión del original que entregó Jurídico.',
            'Probar una versión y activarla o desactivarla.',
            'Ver quién cargó, activó, desactivó o probó cada versión.',
        ],
        flujo: [
            'Carga el original (sin editar ni agregar marcadores).',
            'Revisa el reporte de campos y genera una prueba.',
            'Activa la versión: los procesos nuevos la usan; lo ya generado no cambia.',
        ],
        permisos: 'Gerencia de RH y super administración.',
        errores: [
            'Los blancos que no son datos del colaborador se imprimen tal cual vienen en el original.',
            'Si un puesto no tiene su formato, el proceso avisa «Formato no cargado» en lugar de usar otro.',
        ],
    },
    empresas: {
        queEs: 'Las razones sociales del sistema: toda sucursal, colaborador y expediente pertenece a una empresa.',
        puedes: [
            'Dar de alta y editar empresas.',
            'Ver sucursales y colaboradores por empresa.',
            'Activar o desactivar una empresa.',
        ],
        flujo: [
            '«Nueva empresa».',
            'Captura nombre, razón social y RFC.',
            'Asígnale sus sucursales en Sucursales.',
        ],
        permisos:
            'Super administración, administración de RH, dirección comercial y auditoría (consulta).',
        errores: [
            'Una empresa con sucursales no se puede eliminar: desactívala o reasigna sus sucursales.',
        ],
    },
    usuarios: {
        queEs: 'Las cuentas de acceso: correo, roles y estado. Los datos laborales viven en Expedientes.',
        puedes: [
            'Crear la cuenta de un colaborador ya dado de alta.',
            'Cambiar roles.',
            'Bloquear o restablecer el acceso y gestionar la contraseña.',
        ],
        flujo: [
            '«Nuevo usuario».',
            'Elige al colaborador sin cuenta y su correo.',
            'Asigna sus roles.',
        ],
        permisos:
            'Administración de RH, sistemas, super administración y quienes administran personal de su alcance.',
        errores: [
            'Las cuentas nunca se borran: si alguien sale, se bloquea su acceso.',
            'Solo aparecen colaboradores que todavía no tienen cuenta.',
        ],
    },
    sucursales: {
        queEs: 'Las ubicaciones de la empresa. Lo que cada responsable ve depende de las sucursales que tiene asignadas.',
        puedes: [
            'Dar de alta y editar sucursales.',
            'Ver su plantilla autorizada y vacantes.',
            'Abrir el detalle con headcount por puesto.',
        ],
        flujo: [
            '«Nueva sucursal».',
            'Captura clave, empresa y datos de contacto.',
            'Revisa su headcount en el detalle.',
        ],
        permisos: 'Super administración y administración de RH.',
        errores: [
            'La plantilla autorizada viene del Excel de headcount; aquí solo se consulta.',
        ],
    },
    departamentos: {
        queEs: 'Las áreas de la empresa que agrupan puestos y colaboradores.',
        puedes: [
            'Crear, editar y desactivar departamentos.',
            'Ver cuántos puestos y personas tiene cada uno.',
        ],
        flujo: ['«Nuevo departamento».', 'Asigna sus puestos en Puestos.'],
        permisos: 'Super administración y administración de RH.',
        errores: [
            'Eliminar un departamento lo quita del catálogo; si todavía tiene personas, mejor desactívalo para conservar su historial visible.',
        ],
    },
    puestos: {
        queEs: 'El catálogo de puestos de cada departamento; alimenta Expedientes, Vacantes y el Organigrama.',
        puedes: [
            'Crear y editar puestos.',
            'Ver cuántas personas ocupan cada puesto.',
        ],
        flujo: [
            '«Nuevo puesto» dentro de su departamento.',
            'Define a quién reporta en el Organigrama.',
        ],
        permisos: 'Super administración y administración de RH.',
        errores: [
            'El Gestor tiene UNA ruta asignada en la matriz comercial: no crees puestos por ruta.',
        ],
    },
    roles: {
        queEs: 'Los roles y sus permisos: qué módulos ve cada usuario y qué acciones puede hacer.',
        puedes: [
            'Crear, editar, clonar y eliminar roles.',
            'Marcar los permisos de cada rol.',
        ],
        flujo: [
            'Clona un rol parecido.',
            'Ajusta sus permisos.',
            'Asígnalo en Usuarios.',
        ],
        permisos: 'Super administración y sistemas.',
        errores: [
            'Un cambio en un rol afecta de inmediato a todos los usuarios que lo tienen.',
            'El rol super_admin está protegido.',
        ],
    },
    'app-releases': {
        queEs: 'La publicación del APK de la app móvil mientras no esté en Play Store.',
        puedes: [
            'Subir una versión (queda en borrador).',
            'Publicarla o marcarla como actualización obligatoria.',
            'Ver el historial de versiones.',
        ],
        flujo: [
            '«Subir versión» con el APK.',
            'Revisa los datos.',
            'Publícala.',
        ],
        permisos:
            'Sistemas, RH y super administración (publicar requiere permiso adicional).',
        errores: [
            'Una versión obligatoria obliga a todos a actualizar: úsala solo cuando sea necesario.',
        ],
    },
    pendientes: {
        queEs: 'Tu bandeja de trabajo del ciclo laboral: lo que otras personas esperan que hagas.',
        puedes: [
            'Ver tus pendientes por etapa, sucursal y urgencia.',
            'Abrir cada uno directo donde se resuelve.',
        ],
        flujo: [
            'Atiende primero los vencidos.',
            'Abre el pendiente.',
            'Haz la acción; el pendiente se cierra solo.',
        ],
        permisos:
            'Quien participa en el ciclo laboral (preautoriza, autoriza, evalúa, entrega equipo u opera bajas).',
        errores: [
            'Si un pendiente no es tuyo, revisa en Configuración → Jefes directos quién es el jefe de esa persona.',
        ],
    },
    onboarding: {
        queEs: 'Catálogo de lecciones de bienvenida y del equipo que se entrega al ingresar.',
        puedes: [
            'Crear y editar lecciones con su material y preguntas.',
            'Definir la calificación mínima.',
            'Administrar los tipos de equipo y su responsiva.',
        ],
        flujo: [
            'Crea la lección institucional.',
            'Agrega las del puesto.',
            'Da de alta el equipo que se entrega.',
        ],
        permisos: 'onboarding.gestionar (Recursos Humanos).',
        errores: [
            'Una lección sin preguntas no se puede guardar.',
            'Una lección de puesto necesita el puesto al que aplica.',
        ],
    },
    reingresos: {
        queEs: 'Reincorporación de excolaboradores sin duplicar a la persona.',
        puedes: [
            'Buscar a la persona y ver su historial.',
            'Solicitar su reingreso.',
            'Decidir si es viable (RH).',
        ],
        flujo: [
            'Busca a la persona.',
            'Revisa su causa de salida.',
            'Solicita el reingreso.',
            'RH decide y se piden solo los documentos vencidos o faltantes.',
        ],
        permisos:
            'reingresos.solicitar para proponer; reingresos.gestionar y autorización de RH para decidir.',
        errores: [
            'Una persona activa no puede reingresar.',
            'Si tiene un cierre sin concluir, primero termínalo.',
        ],
    },
    configuracion: {
        queEs: 'Ajustes del sistema que administra el negocio: jefes directos, avisos, parámetros de RH y apariencia.',
        puedes: [
            'Cambiar el jefe directo de una persona.',
            'Elegir a quién llega cada aviso.',
            'Ajustar días de aviso, calificaciones mínimas y duración del contrato por puesto.',
            'Cambiar los colores institucionales.',
        ],
        flujo: [
            'Elige la sección.',
            'Haz el cambio.',
            'Guarda: queda registrado quién cambió qué y cuándo.',
        ],
        permisos:
            'configuracion.ver más el permiso de cada sección (organización, notificaciones, rh, apariencia).',
        errores: [
            'Nadie puede ser su propio jefe ni formar un ciclo de jefes.',
            'Los colores deben ir en formato #RRGGBB.',
        ],
    },
    'mi-expediente': {
        queEs: 'Tu expediente: tus datos, documentos, vacaciones, recibos, préstamos y solicitudes.',
        puedes: [
            'Subir los documentos que te pidan.',
            'Ver si fueron aprobados.',
            'Actualizar tu contacto.',
        ],
        flujo: [
            'Abre "Documentos".',
            'Sube el archivo que falta.',
            'Espera la revisión de Recursos Humanos.',
        ],
        permisos: 'Toda cuenta ligada a un colaborador.',
        errores: [
            'Si un documento fue rechazado, verás el motivo: corrígelo y súbelo de nuevo.',
        ],
    },
    portal: {
        queEs: 'Tu espacio personal: tu perfil, vacaciones, solicitudes y avisos.',
        puedes: [
            'Ver tus datos y tu número de empleado.',
            'Consultar tus días de vacaciones.',
            'Ver tus solicitudes recientes y notificaciones.',
        ],
        flujo: [
            'Revisa tus avisos.',
            'Consulta tu saldo de vacaciones.',
            'Entra a Mis solicitudes para pedir algo.',
        ],
        permisos:
            'Toda cuenta ligada a un colaborador activo (también si además tienes funciones de RH: cambia con «Mi espacio» en el menú).',
        errores: [
            'Si un dato tuyo está mal, pide a RH que lo corrija en tu expediente.',
        ],
    },
    'mis-solicitudes': {
        queEs: 'Donde pides vacaciones, permisos, préstamos y otros trámites, y sigues su estado.',
        puedes: [
            'Crear una solicitud.',
            'Ver el estado e historial de cada una.',
            'Corregirla si RH lo pide.',
        ],
        flujo: [
            'Elige qué necesitas.',
            'Llena el formulario (fechas, motivo, evidencia si aplica).',
            'Envíala y sigue su estado.',
        ],
        permisos: 'Colaboradores y cuentas con permiso de crear solicitudes.',
        errores: [
            'Para vacaciones, no puedes pedir más días de los que tienes disponibles.',
            '«Requiere corrección»: ábrela, ajusta lo indicado y reenvíala.',
        ],
    },
};
