/**
 * Traducciones a español legible de los permisos de Spatie (formato
 * "modulo.accion", p. ej. "reportes.exportar") para mostrarlos en el
 * formulario de roles en vez del slug técnico crudo.
 *
 * Si aparece un permiso nuevo que no está en el mapa, `etiquetaPermiso()`
 * genera un texto razonable a partir del slug (no rompe ni muestra vacío).
 */

const ETIQUETAS_MODULO: Record<string, string> = {
    actividades: 'Actividades',
    altas: 'Altas de personal',
    app: 'Aplicación móvil',
    app_releases: 'Versiones de la app',
    asignaciones: 'Asignaciones',
    asistencias: 'Asistencias',
    auditoria: 'Auditoría',
    candidatos: 'Candidatos',
    colaborador: 'Portal del colaborador',
    configuracion: 'Configuración',
    cuestionarios: 'Cuestionarios',
    cursos: 'Cursos',
    dashboard: 'Dashboard',
    departamentos: 'Departamentos',
    dispositivos: 'Dispositivos móviles',
    documentos: 'Documentos',
    empresas: 'Empresas',
    expedientes: 'Expedientes',
    integraciones: 'Integraciones',
    mobile: 'App móvil (RH)',
    multimedia: 'Multimedia',
    notificaciones: 'Notificaciones',
    plantillas: 'Plantillas',
    puestos: 'Puestos',
    reportes: 'Reportes',
    reportes_rh: 'Reportes de RH',
    respuestas: 'Respuestas de cuestionarios',
    rh: 'Recursos Humanos',
    roles: 'Roles y permisos',
    sesiones: 'Sesiones de capacitación',
    solicitudes: 'Solicitudes internas',
    sucursales: 'Sucursales',
    usuarios: 'Usuarios',
    vacaciones: 'Vacaciones',
    vacantes: 'Vacantes',
};

const ETIQUETAS_PERMISO: Record<string, string> = {
    'actividades.administrar': 'Administrar actividades',
    'altas.aprobar': 'Aprobar altas de personal',
    'altas.cancelar': 'Cancelar altas de personal',
    'altas.crear': 'Crear altas de personal',
    'altas.enviar': 'Enviar altas de personal',
    'altas.revisar': 'Revisar altas de personal',
    'altas.ver': 'Ver altas de personal',
    'app.config.ver': 'Ver configuración de la app móvil',
    'app_releases.crear': 'Crear versiones de la app',
    'app_releases.descargar': 'Descargar la app',
    'app_releases.eliminar': 'Eliminar versiones de la app',
    'app_releases.publicar': 'Publicar versiones de la app',
    'app_releases.ver': 'Ver versiones de la app',
    'asignaciones.cancelar': 'Cancelar asignaciones',
    'asignaciones.crear': 'Crear asignaciones',
    'asignaciones.ver': 'Ver asignaciones',
    'asistencias.corregir': 'Corregir asistencias',
    'asistencias.ver': 'Ver asistencias',
    'auditoria.ver': 'Ver auditoría',
    'candidatos.aprobar': 'Aprobar candidatos',
    'candidatos.crear': 'Crear candidatos',
    'candidatos.editar': 'Editar candidatos',
    'candidatos.eliminar': 'Eliminar candidatos',
    'candidatos.rechazar': 'Rechazar candidatos',
    'candidatos.ver': 'Ver candidatos',
    'candidatos.ver_sucursal': 'Ver candidatos de mi sucursal',
    'candidatos.ver_todos': 'Ver candidatos de todas las sucursales',
    'colaborador.incorporacion.documentos.subir':
        'Subir documentos de mi incorporación',
    'colaborador.incorporacion.ver': 'Ver mi incorporación',
    'configuracion.administrar': 'Administrar configuración general',
    'cuestionarios.administrar': 'Administrar cuestionarios',
    'cursos.crear': 'Crear cursos',
    'cursos.editar': 'Editar cursos',
    'cursos.eliminar': 'Eliminar cursos',
    'cursos.publicar': 'Publicar cursos',
    'cursos.ver': 'Ver cursos',
    'dashboard.global.ver': 'Ver dashboard global',
    'dashboard.sucursal.ver': 'Ver dashboard de mi sucursal',
    'departamentos.administrar': 'Administrar departamentos',
    'dispositivos.push_token.registrar':
        'Registrar token de notificaciones push',
    'dispositivos.push_token.revocar': 'Revocar token de notificaciones push',
    'documentos.aprobar': 'Aprobar documentos',
    'documentos.descargar': 'Descargar documentos',
    'documentos.rechazar': 'Rechazar documentos',
    'documentos.revisar': 'Revisar documentos',
    'documentos.subir': 'Subir documentos',
    'documentos.ver': 'Ver documentos',
    'documentos.versiones': 'Ver versiones de documentos',
    'empresas.crear': 'Crear empresas',
    'empresas.editar': 'Editar empresas',
    'empresas.eliminar': 'Eliminar empresas',
    'empresas.ver': 'Ver empresas',
    'expedientes.crear': 'Crear expedientes',
    'expedientes.editar': 'Editar expedientes',
    'expedientes.eliminar': 'Eliminar expedientes',
    'expedientes.revisar': 'Revisar expedientes',
    'expedientes.ver': 'Ver expedientes',
    'expedientes.ver_sucursal': 'Ver expedientes de mi sucursal',
    'expedientes.ver_todos': 'Ver expedientes de todas las sucursales',
    'integraciones.administrar': 'Administrar integraciones',
    'mobile.bootstrap.ver': 'Ver datos iniciales de la app móvil',
    'multimedia.administrar': 'Administrar multimedia',
    'notificaciones.leer_todas': 'Marcar todas las notificaciones como leídas',
    'plantillas.crear': 'Crear plantillas',
    'plantillas.editar': 'Editar plantillas',
    'plantillas.eliminar': 'Eliminar plantillas',
    'plantillas.generar': 'Generar documentos desde plantilla',
    'plantillas.ver': 'Ver plantillas',
    'puestos.administrar': 'Administrar puestos',
    'reportes.exportar': 'Exportar reportes',
    'reportes.globales': 'Ver reportes globales',
    'reportes.sucursal': 'Ver reportes de mi sucursal',
    'reportes_rh.exportar': 'Exportar reportes de RH',
    'reportes_rh.globales': 'Ver reportes globales de RH',
    'reportes_rh.sucursal': 'Ver reportes de RH de mi sucursal',
    'reportes_rh.ver': 'Ver reportes de RH',
    'respuestas.calificar': 'Calificar respuestas',
    'respuestas.ver': 'Ver respuestas',
    'rh.colaboradores.detalle': 'Ver detalle de un colaborador',
    'rh.colaboradores.ver': 'Ver colaboradores',
    'rh.cumpleanos.calendario': 'Ver calendario de cumpleaños',
    'rh.cumpleanos.configurar': 'Configurar módulo de cumpleaños',
    'rh.cumpleanos.descargar_imagen': 'Descargar tarjeta de cumpleaños',
    'rh.cumpleanos.frases.gestionar': 'Gestionar frases de cumpleaños',
    'rh.cumpleanos.notificaciones.gestionar':
        'Gestionar notificaciones de cumpleaños',
    'rh.cumpleanos.ver': 'Ver cumpleaños',
    'rh.documentos.aprobar': 'Aprobar documentos',
    'rh.documentos.detalle': 'Ver detalle de un documento',
    'rh.documentos.rechazar': 'Rechazar documentos',
    'rh.documentos.ver': 'Ver documentos',
    'rh.documentos.ver_archivo': 'Ver archivo de un documento',
    'rh.expedientes.detalle': 'Ver detalle de un expediente',
    'rh.expedientes.documentos.aprobar': 'Aprobar documentos de expediente',
    'rh.expedientes.documentos.rechazar': 'Rechazar documentos de expediente',
    'rh.expedientes.documentos.ver': 'Ver documentos de expediente',
    'rh.expedientes.incorporacion.aprobar':
        'Aprobar incorporación de expediente',
    'rh.expedientes.incorporacion.rechazar':
        'Rechazar incorporación de expediente',
    'rh.expedientes.ver': 'Ver expedientes',
    'rh.incorporacion.invitaciones.crear':
        'Crear invitaciones de incorporación',
    'rh.incorporacion.invitaciones.qr.descargar':
        'Descargar código QR de invitación',
    'rh.incorporacion.invitaciones.regenerar':
        'Regenerar invitaciones de incorporación',
    'rh.incorporacion.invitaciones.revocar':
        'Revocar invitaciones de incorporación',
    'rh.incorporacion.invitaciones.ver': 'Ver invitaciones de incorporación',
    'rh.incorporaciones.aprobar': 'Aprobar incorporaciones',
    'rh.incorporaciones.detalle': 'Ver detalle de una incorporación',
    'rh.incorporaciones.rechazar': 'Rechazar incorporaciones',
    'rh.incorporaciones.ver': 'Ver incorporaciones',
    'rh.mobile.dashboard.ver': 'Ver dashboard móvil de RH',
    'rh.pendientes.ver': 'Ver pendientes de RH',
    'rh.solicitudes.aprobar': 'Aprobar solicitudes',
    'rh.solicitudes.correccion': 'Pedir corrección de una solicitud',
    'rh.solicitudes.detalle': 'Ver detalle de una solicitud',
    'rh.solicitudes.rechazar': 'Rechazar solicitudes',
    'rh.solicitudes.ver': 'Ver solicitudes',
    'rh.vacaciones.aprobar': 'Aprobar vacaciones',
    'rh.vacaciones.detalle': 'Ver detalle de una solicitud de vacaciones',
    'rh.vacaciones.rechazar': 'Rechazar vacaciones',
    'rh.vacaciones.ver': 'Ver vacaciones',
    'roles.administrar': 'Administrar roles y permisos',
    'sesiones.administrar': 'Administrar sesiones de capacitación',
    'solicitudes.adjuntos.subir': 'Subir adjuntos a una solicitud',
    'solicitudes.aprobar': 'Aprobar solicitudes',
    'solicitudes.cerrar': 'Cerrar solicitudes',
    'solicitudes.configuracion.ver': 'Ver configuración de solicitudes',
    'solicitudes.crear': 'Crear solicitudes',
    'solicitudes.rechazar': 'Rechazar solicitudes',
    'solicitudes.revisar': 'Revisar solicitudes',
    'solicitudes.ver': 'Ver solicitudes',
    'sucursales.administrar': 'Administrar sucursales',
    'usuarios.crear': 'Crear usuarios',
    'usuarios.desactivar': 'Desactivar usuarios',
    'usuarios.editar': 'Editar usuarios',
    'usuarios.ver': 'Ver usuarios',
    'vacaciones.ajustar': 'Ajustar días de vacaciones',
    'vacaciones.aprobar': 'Aprobar vacaciones',
    'vacaciones.rechazar': 'Rechazar vacaciones',
    'vacaciones.reportes': 'Ver reportes de vacaciones',
    'vacaciones.solicitar': 'Solicitar vacaciones',
    'vacaciones.ver': 'Ver vacaciones',
    'vacantes.cerrar': 'Cerrar vacantes',
    'vacantes.crear': 'Crear vacantes',
    'vacantes.editar': 'Editar vacantes',
    'vacantes.eliminar': 'Eliminar vacantes',
    'vacantes.ver': 'Ver vacantes',
    'vacantes.ver_sucursal': 'Ver vacantes de mi sucursal',
    'vacantes.ver_todos': 'Ver vacantes de todas las sucursales',
};

/**
 * Título del grupo/módulo (usado como encabezado de cada bloque de
 * permisos en el formulario de roles).
 */
export function etiquetaModulo(modulo: string): string {
    if (ETIQUETAS_MODULO[modulo]) {
        return ETIQUETAS_MODULO[modulo];
    }

    return humanizar(modulo);
}

/**
 * Texto legible en español para un permiso ("reportes.exportar" →
 * "Exportar reportes"). Si el permiso no está en el diccionario (p. ej.
 * uno agregado después sin actualizar este archivo) genera un texto
 * razonable en vez de mostrar el slug técnico crudo.
 */
export function etiquetaPermiso(name: string): string {
    if (ETIQUETAS_PERMISO[name]) {
        return ETIQUETAS_PERMISO[name];
    }

    const partes = name.split('.');
    const accion = partes.at(-1) ?? name;

    return humanizar(accion);
}

function humanizar(texto: string): string {
    const palabras = texto.replace(/[._]+/g, ' ').trim().split(/\s+/);

    if (palabras.length === 0 || palabras[0] === '') {
        return texto;
    }

    return palabras
        .map((palabra, indice) =>
            indice === 0
                ? palabra.charAt(0).toUpperCase() + palabra.slice(1)
                : palabra,
        )
        .join(' ');
}
