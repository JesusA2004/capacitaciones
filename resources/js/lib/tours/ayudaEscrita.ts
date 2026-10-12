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
        queEs: 'Las solicitudes de los colaboradores (permisos, vacaciones, préstamos y actualizaciones de datos), por tipo y por estado.',
        puedes: [
            'Ver por tipo cuántas están recibidas, por autorizar y en corrección.',
            'Abrir una solicitud, su evidencia y su cadena de autorización.',
            'Dar visto bueno (gerente y regional) o autorizar, rechazar o pedir corrección (RH).',
            'Exportar lo filtrado a Excel o PDF.',
        ],
        flujo: [
            'El gerente da su visto bueno; después el regional.',
            'Con ambos, la solicitud pasa sola a «Pendiente de autorizar».',
            'RH aprueba, rechaza o pide corrección (con comentario).',
        ],
        permisos:
            'Visto bueno: gerencia de la sucursal (o jefe directo) y regional. Autorización final: RH. Quien da un visto bueno no puede autorizar.',
        errores: [
            'Sin los dos vistos buenos, nadie (ni RH) puede autorizar.',
            'Rechazar o pedir corrección exige comentario.',
            'Un permiso solo se imprime después de la autorización de RH.',
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
        queEs: 'El tablero de reclutamiento: cada candidato avanza por fases, del Filtro RH hasta la contratación, con su acción en la ficha.',
        puedes: [
            'Registrar candidatos sobre una vacante real (o como espontáneos), con su campaña y su fuente.',
            'Ver en qué fase va cada uno y quién tiene la siguiente acción.',
            'Registrar entrevista, psicométricas, socioeconómico y referencias desde la ficha.',
            'Preautorizar (gerente), autorizar (RH) y generar el QR de contratación.',
            'Cerrar el proceso de un candidato (Rechazado o Desistió) con motivo.',
        ],
        flujo: [
            'Registra al candidato eligiendo la vacante real.',
            'Abre su ficha y usa la acción del paso (Revisar perfil, Registrar entrevista…).',
            'Con la autorización final de RH, «Generar QR de contratación».',
        ],
        permisos:
            'Registrar y avanzar: RH. Entrevistar, revisar psicométricas, socioeconómico y preautorizar: gerencia de la sucursal. Autorizar: RH. Consulta: dirección comercial, regional y auditoría.',
        errores: [
            'Arrastrar una tarjeta solo cierra el proceso (Rechazado o Desistió); para avanzar se usa la acción de la ficha.',
            'Un candidato espontáneo no se puede contratar hasta vincularlo a una vacante real.',
            '«Esta vacante ya fue cubierta»: alguien ocupó la última plaza; elige otra vacante.',
        ],
    },
    campanas: {
        queEs: 'Cada campaña parte de una vacante real y registra qué se publicó, cuánto costó y qué produjo.',
        puedes: [
            'Crear una campaña sobre una vacante real (empresa, sucursal y puesto salen de ella).',
            'Registrar canal, fechas, presupuesto, gasto, sueldo publicado, copy, liga y evidencias (PDF o imagen).',
            'Ver su embudo: candidatos, contactados, entrevistas, psicométricos y contratados.',
            'Ver costo por candidato, por colaborador contratado, conversión y días para cubrir.',
        ],
        flujo: [
            'Elige la vacante real y captura la campaña.',
            'Al registrar cada candidato, elige esa campaña.',
            'Revisa su embudo y sus costos.',
        ],
        permisos:
            'Ver: RH (administración y auxiliar). Crear y editar: quien administra campañas (Gerencia de RH).',
        errores: [
            'Si la lista de vacantes está vacía, no hay plazas faltantes.',
            'El sueldo publicado no cambia el sueldo del puesto ni el de la contratación.',
            'Si el embudo sale en ceros, liga a los candidatos con su campaña en «Campaña».',
        ],
    },
    invitaciones: {
        queEs: 'El estado de todos los QR de incorporación y el lugar para volver a emitir uno que venció.',
        puedes: [
            'Ver cada QR: activo, usado, vencido o revocado.',
            'Copiar la liga, descargar el PNG, regenerar o revocar un QR.',
            'Emitir un QR nuevo para una persona en contratación que no usó el suyo.',
        ],
        flujo: [
            'El primer QR se genera en la ficha del candidato autorizado («Generar QR de contratación»).',
            'Si vence (máximo 24 h), aquí lo vuelves a emitir.',
            'La persona lo escanea, crea su cuenta y sigue en la app.',
        ],
        permisos: 'RH (administración y auxiliar) y super administración.',
        errores: [
            'Nadie puede registrarse en la app sin un QR activo.',
            'Un QR usado o vencido no se reactiva: se emite uno nuevo.',
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
        queEs: 'Las ubicaciones de la empresa, con su plantilla autorizada por puesto.',
        puedes: [
            'Dar de alta y editar sucursales.',
            'Ver en el detalle su headcount: autorizado contra ocupado por puesto.',
            'Cambiar la plantilla autorizada de un puesto (Gerencia de RH), con motivo.',
        ],
        flujo: [
            '«Nueva sucursal»: clave, empresa y datos de contacto.',
            'Abre el detalle para revisar su headcount.',
            'Con el lápiz del puesto ajusta la plantilla autorizada y escribe el motivo.',
        ],
        permisos:
            'Administrar: super administración y administración de RH. Cambiar plantilla: Gerencia de RH.',
        errores: [
            'Cada cambio de plantilla queda en el historial con antes → después y motivo.',
            'Al cambiar la plantilla, la vacante del puesto se recalcula sola.',
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
            'Si un pendiente no te corresponde, revisa el organigrama: el jefe directo de cada persona sale de ahí.',
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
        queEs: 'Ajustes del sistema que administra el negocio: avisos, parámetros de RH y apariencia.',
        puedes: [
            'Elegir a quién llega cada aviso (Notificaciones).',
            'Ajustar días de aviso, calificaciones mínimas y la duración de capacitación inicial de cada puesto (Parámetros de RH).',
            'Cambiar los colores institucionales (Apariencia).',
        ],
        flujo: [
            'Elige la sección.',
            'Haz el cambio.',
            'Guarda: queda registrado quién cambió qué y cuándo.',
        ],
        permisos:
            'configuracion.ver más el permiso de cada sección (notificaciones, rh, apariencia).',
        errores: [
            'El jefe directo no se captura aquí: sale del organigrama.',
            'Cambiar quién se entera de un aviso no cambia quién autoriza.',
            'Los colores deben ir en formato #RRGGBB.',
        ],
    },
    'mi-expediente': {
        queEs: 'Tu expediente: tus datos, documentos, vacaciones, recibos, préstamos y solicitudes.',
        puedes: [
            'Subir los documentos que te pidan.',
            'Ver si fueron aprobados.',
            'Pedir que se corrija o complete un dato tuyo («Solicitar corrección»).',
        ],
        flujo: [
            'Abre "Documentos".',
            'Sube el archivo que falta.',
            'Espera la revisión de Recursos Humanos.',
        ],
        permisos: 'Toda cuenta ligada a un colaborador.',
        errores: [
            'Si un documento fue rechazado, verás el motivo: corrígelo y súbelo de nuevo.',
            'Nadie edita su propio expediente: los cambios los autoriza RH.',
        ],
    },
    portal: {
        queEs: 'Tu espacio personal: tu perfil, lo que necesitas hacer, vacaciones, solicitudes y avisos.',
        puedes: [
            'Ver tus datos y tu número de empleado.',
            'Ver lo que te toca hacer (documentos, firmas, lecciones de bienvenida).',
            'Consultar tus días de vacaciones.',
            'Ver tus solicitudes recientes y notificaciones.',
        ],
        flujo: [
            'Revisa «Lo que necesitas hacer».',
            'Consulta tu saldo de vacaciones.',
            'Entra a Mis solicitudes para pedir algo.',
        ],
        permisos:
            'Toda cuenta ligada a un colaborador activo (también si además tienes funciones de RH: cambia con «Mi espacio» en el menú).',
        errores: [
            'Si un dato tuyo está mal, usa «Solicitar corrección» en Mi expediente: RH lo autoriza.',
        ],
    },
    'mis-solicitudes': {
        queEs: 'Donde pides permisos, vacaciones y préstamos, y sigues su estado.',
        puedes: [
            'Crear una solicitud de permiso, vacaciones o préstamo.',
            'Ver el estado, la cadena de autorización y el historial de cada una.',
            'Corregirla si RH lo pide.',
        ],
        flujo: [
            'Elige qué necesitas.',
            'Llena el formulario (fechas, motivo, evidencia si aplica).',
            'Tu gerente y tu regional dan visto bueno; RH autoriza.',
        ],
        permisos: 'Colaboradores y cuentas con permiso de crear solicitudes.',
        errores: [
            'En vacaciones el domingo no cuenta y no puedes pedir más días de los que tienes.',
            '«Requiere corrección»: ábrela, ajusta lo indicado y reenvíala.',
        ],
    },
    directorio: {
        queEs: 'El directorio de la empresa: nombre, puesto, sucursal, departamento y contacto corporativo de cada colaborador activo.',
        puedes: [
            'Buscar a un compañero por nombre o número de empleado.',
            'Filtrar por puesto, sucursal o departamento.',
            'Tocar su correo o teléfono corporativo para escribirle o llamarle.',
        ],
        flujo: [
            'Escribe el nombre o elige un filtro.',
            'Abre el correo o teléfono corporativo de la persona.',
        ],
        permisos:
            'Cualquier colaborador con sesión (es global a propósito: solo muestra contacto corporativo).',
        errores: [
            'Si alguien no tiene correo corporativo, aparece sin correo: nunca se inventa uno ni se usa su correo personal o de acceso.',
        ],
    },
    'directorio-operativo': {
        queEs: 'El mismo directorio de contacto corporativo, desde el modo operativo.',
        puedes: [
            'Localizar a cualquier colaborador activo y su contacto corporativo.',
        ],
        flujo: [
            'Busca o filtra.',
            'Para datos personales o laborales usa Expedientes (requiere permiso).',
        ],
        permisos: 'Cualquier cuenta con sesión.',
        errores: [
            'El directorio nunca muestra salario, RFC, CURP, NSS, domicilio ni datos personales.',
        ],
    },
    induccion: {
        queEs: 'La inducción institucional Mr. Lana: 7 capítulos con el organigrama vigente.',
        puedes: [
            'Recorrer los 7 capítulos cuando quieras.',
            'Ver los organigramas corporativo, comercial y de sucursal actualizados.',
        ],
        flujo: [
            'Si estás en tu ingreso, entra desde «Mis pendientes»: ahí tu avance se guarda.',
            'Al terminar los 7 capítulos se habilita tu evaluación de inducción.',
        ],
        permisos: 'Todos los colaboradores.',
        errores: [
            'Desde este acceso (repaso) no se registra avance: para tu evaluación entra desde «Mis pendientes».',
        ],
    },
    'presentar-induccion': {
        queEs: 'La inducción Mr. Lana en modo presentación, para impartirla en una sesión (Meet o sala).',
        puedes: ['Mostrar los 7 capítulos con el organigrama vigente.'],
        flujo: [
            'Abre «Presentar inducción» y comparte pantalla.',
            'Cada colaborador presenta su evaluación desde su propia cuenta.',
        ],
        permisos: 'Cuentas con permiso de gestionar onboarding (RH).',
        errores: [
            'Presentarla NO registra avance de ningún colaborador ni habilita su evaluación.',
        ],
    },
};

/**
 * Artículos de la Guía maestra (resources/js/lib/guia) que explican cada
 * pantalla a fondo. La ayuda de arriba es el resumen de la PANTALLA; el
 * artículo es el PROCESO completo. Así no hay dos explicaciones distintas:
 * la página de Ayuda y el botón «¿Cómo funciona?» enlazan aquí.
 */
export const ARTICULOS_DE_MODULO: Record<string, string[]> = {
    dashboard: ['mis-pendientes'],
    pendientes: ['mis-pendientes'],
    expedientes: ['expediente', 'completitud-expediente', 'documentos-de-alta'],
    solicitudes: ['revisar-solicitudes', 'permisos', 'vacaciones', 'prestamos'],
    organigrama: ['estructura-y-plantilla'],
    vacantes: ['plantilla-y-vacantes'],
    candidatos: [
        'registrar-candidato',
        'etapas-del-candidato',
        'autorizacion-rh',
        'iniciar-contratacion',
    ],
    campanas: ['campanas'],
    invitaciones: ['qr-incorporacion'],
    reportes: [],
    cumpleanos: ['celebraciones'],
    aniversarios: ['celebraciones'],
    'documentos-maestros': ['documentos-maestros', 'contrato-inicial'],
    empresas: ['estructura-y-plantilla'],
    usuarios: ['usuarios-y-roles'],
    sucursales: ['estructura-y-plantilla', 'plantilla-y-vacantes'],
    departamentos: ['estructura-y-plantilla'],
    puestos: ['estructura-y-plantilla'],
    roles: ['usuarios-y-roles'],
    'app-releases': ['app-movil'],
    onboarding: ['onboarding', 'configurar-onboarding'],
    reingresos: ['reingreso'],
    configuracion: ['configuracion-notificaciones', 'estructura-y-plantilla'],
    portal: ['app-colaborador'],
    'mi-expediente': ['completar-datos', 'documentos-de-alta'],
    'mis-solicitudes': ['permisos', 'vacaciones', 'prestamos'],
};
