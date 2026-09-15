<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesYPermisosSeeder extends Seeder
{
    /**
     * Catalogo completo de permisos del sistema (seccion 9 del encargo).
     * Se crean todos desde esta fase, aunque varios se usaran hasta fases
     * posteriores, para no tener que re-sembrar permisos modulo por modulo.
     *
     * @var array<int, string>
     */
    /**
     * Permisos "personales" (experiencia de modo colaborador: Mi portal, mi
     * perfil, mis solicitudes, mis notificaciones — ver
     * App\Services\Navigation\NavigationService). Se mantienen fuera del
     * catálogo operativo de `self::PERMISOS` que se le asigna en bloque a
     * `super_admin`: ser super_admin no debe implicar automáticamente tener
     * "Mi portal", ni por sidebar ni por URL directa (sección 25 del cierre).
     * Si un usuario necesita ambas experiencias, se le asigna también el rol
     * `colaborador` (o estos permisos de forma explícita).
     *
     * @var array<int, string>
     */
    private const PERMISOS_PERSONALES = [
        'portal.ver',
        'portal.perfil.ver',
        'portal.solicitudes.ver',
        'portal.solicitudes.crear',
        'portal.notificaciones.ver',
    ];

    private const PERMISOS = [
        'dashboard.global.ver',
        'dashboard.sucursal.ver',
        'usuarios.ver',
        'usuarios.crear',
        'usuarios.editar',
        'usuarios.desactivar',
        'sucursales.administrar',
        'departamentos.administrar',
        'puestos.administrar',
        'roles.administrar',
        'cursos.ver',
        'cursos.crear',
        'cursos.editar',
        'cursos.publicar',
        'cursos.eliminar',
        'multimedia.administrar',
        'cuestionarios.administrar',
        'respuestas.ver',
        'respuestas.calificar',
        'actividades.administrar',
        'asignaciones.crear',
        'asignaciones.ver',
        'asignaciones.cancelar',
        'sesiones.administrar',
        'asistencias.ver',
        'asistencias.corregir',
        'reportes.globales',
        'reportes.sucursal',
        'reportes.exportar',
        'integraciones.administrar',
        'configuracion.administrar',
        'auditoria.ver',

        // --- Portal RH (ver docs/ROLES_PERMISOS_RH.md) ---
        // Empresas (multiempresa)
        'empresas.ver',
        'empresas.crear',
        'empresas.editar',
        'empresas.eliminar',
        // Expedientes
        'expedientes.ver',
        'expedientes.ver_todos',
        'expedientes.ver_sucursal',
        'expedientes.crear',
        'expedientes.editar',
        'expedientes.revisar',
        'expedientes.eliminar',
        // Documentos
        'documentos.ver',
        'documentos.subir',
        'documentos.descargar',
        'documentos.revisar',
        'documentos.aprobar',
        'documentos.rechazar',
        'documentos.versiones',
        // Altas digitales (Fase 2, catalogo desde ahora)
        'altas.ver',
        'altas.crear',
        'altas.enviar',
        'altas.revisar',
        'altas.aprobar',
        'altas.cancelar',
        // Vacaciones (Fase 3, catalogo desde ahora)
        'vacaciones.ver',
        'vacaciones.solicitar',
        'vacaciones.aprobar',
        'vacaciones.rechazar',
        'vacaciones.ajustar',
        'vacaciones.reportes',
        // Solicitudes RH (Fase 3, catalogo desde ahora)
        'solicitudes.ver',
        'solicitudes.crear',
        'solicitudes.revisar',
        'solicitudes.aprobar',
        'solicitudes.rechazar',
        'solicitudes.cerrar',
        // Reportes RH (Fase 4, catalogo desde ahora)
        'reportes_rh.ver',
        'reportes_rh.exportar',
        'reportes_rh.globales',
        'reportes_rh.sucursal',

        // --- Reclutamiento: vacantes y candidatos (Mr. Lana People Fase 1) ---
        'vacantes.ver',
        'vacantes.ver_todos',
        'vacantes.ver_sucursal',
        'vacantes.crear',
        'vacantes.editar',
        'vacantes.cerrar',
        'vacantes.eliminar',
        'candidatos.ver',
        'candidatos.ver_todos',
        'candidatos.ver_sucursal',
        'candidatos.crear',
        'candidatos.editar',
        'candidatos.aprobar',
        'candidatos.rechazar',
        'candidatos.eliminar',

        // --- Plantillas y formatos precargados (Mr. Lana People Fase 1) ---
        'plantillas.ver',
        'plantillas.crear',
        'plantillas.editar',
        'plantillas.eliminar',
        'plantillas.generar',
        // Catalogo de formatos con vista previa y descarga (docs/FORMATOS.md):
        // permisos aparte de plantillas.* porque generar una plantilla y
        // poder ver/previsualizar/descargar el catalogo son capacidades
        // distintas (un rol podria generar sin tener acceso al catalogo
        // completo, o viceversa).
        'formatos.ver',
        'formatos.preview',
        'formatos.descargar_pdf',
        'formatos.descargar_docx',

        // --- Formatos oficiales fijos de MR. LANA (docs/FORMATOS_OFICIALES.md) ---
        // Modulo aparte de formatos.*/plantillas.*: aqui RH nunca sube ni
        // cambia el documento, solo genera un PDF con overlay sobre el
        // original. "configurar" (donde se pinta cada dato) queda separado
        // de "generar" (RH operativo del dia a dia) a proposito.
        'formatos_oficiales.ver',
        'formatos_oficiales.generar',
        'formatos_oficiales.descargar',
        'formatos_oficiales.configurar',

        // --- Incorporacion documental en la app movil (docs/API_MOVIL.md) ---
        // Colaborador en proceso de alta: solo su propia checklist, nunca el
        // expediente completo.
        'colaborador.incorporacion.ver',
        'colaborador.incorporacion.documentos.subir',
        'colaborador.incorporacion.documentos.solicitar-cambio',
        // RH desde la app movil: expedientes completos, acotados por
        // AlcanceOrganizacionalService.
        'rh.expedientes.ver',
        'rh.expedientes.detalle',
        'rh.expedientes.documentos.ver',
        'rh.expedientes.documentos.aprobar',
        'rh.expedientes.documentos.rechazar',
        'rh.expedientes.documentos.autorizar-cambio',
        'rh.expedientes.incorporacion.aprobar',
        'rh.expedientes.incorporacion.rechazar',
        // Invitaciones de incorporacion por QR temporal.
        'rh.incorporacion.invitaciones.ver',
        'rh.incorporacion.invitaciones.crear',
        'rh.incorporacion.invitaciones.regenerar',
        'rh.incorporacion.invitaciones.revocar',
        'rh.incorporacion.invitaciones.qr.descargar',

        // --- Backend movil v5 (docs/BACKEND_MOBILE_V5.md) ---
        // Mobile / app config: sin gate adicional real en el controlador
        // (basta auth:sanctum o la ruta es publica), se catalogan para
        // futura administracion granular desde Administracion > Roles.
        'mobile.bootstrap.ver',
        'app.config.ver',
        // Dispositivos (push tokens): basta auth:sanctum, un usuario siempre
        // administra sus propios dispositivos.
        'dispositivos.push_token.registrar',
        'dispositivos.push_token.revocar',
        // RH: dashboard y bandeja unificada movil.
        'rh.mobile.dashboard.ver',
        'rh.pendientes.ver',
        // RH: solicitudes internas desde la app.
        'rh.solicitudes.ver',
        'rh.solicitudes.detalle',
        'rh.solicitudes.aprobar',
        'rh.solicitudes.rechazar',
        'rh.solicitudes.correccion',
        // RH: vacaciones desde la app.
        'rh.vacaciones.ver',
        'rh.vacaciones.detalle',
        'rh.vacaciones.aprobar',
        'rh.vacaciones.rechazar',
        // RH: documentos de expediente desde la app (bandeja directa,
        // distinta de rh.expedientes.documentos.* que navega por colaborador).
        'rh.documentos.ver',
        'rh.documentos.detalle',
        'rh.documentos.ver_archivo',
        'rh.documentos.aprobar',
        'rh.documentos.rechazar',
        // Extraccion automatica de datos personales de un documento
        // (docs/DOCUMENT_EXTRACTION.md): sugerencias, nunca aprueba nada sola.
        'rh.documentos.extraccion.ver',
        'rh.documentos.extraccion.aplicar',
        'rh.documentos.extraccion.reprocesar',
        'rh.documentos.extraccion.ignorar',
        // RH: incorporaciones desde la app (bandeja directa, mapea al mismo
        // service que rh.expedientes.incorporacion.*).
        'rh.incorporaciones.ver',
        'rh.incorporaciones.detalle',
        'rh.incorporaciones.aprobar',
        'rh.incorporaciones.rechazar',
        // RH: directorio de colaboradores (datos basicos, no el expediente).
        'rh.colaboradores.ver',
        'rh.colaboradores.detalle',
        // Notificaciones.
        'notificaciones.leer_todas',
        // Solicitudes: configuracion de formulario y adjuntos (propios).
        'solicitudes.configuracion.ver',
        'solicitudes.adjuntos.subir',

        // --- Solicitudes de baja de colaborador (nuevo flujo dentro de Solicitudes) ---
        'solicitudes.bajas.crear',
        'solicitudes.bajas.aprobar',
        // Permiso especial: aprobar una baja sin que su finiquito esté
        // revisado (ver config('finiquitos.exigir_finiquito_revisado_para_aprobar_baja')).
        // Reservado a super_admin, nunca a rh_admin.
        'solicitudes.bajas.omitir_finiquito',

        // --- Finiquito de baja de colaborador (App\Services\Finiquitos\FiniquitoService) ---
        'finiquitos.ver',
        'finiquitos.calcular',
        'finiquitos.revisar',
        'finiquitos.subir_firmado',

        // --- Headcount / plantilla autorizada ---
        'headcount.ver',
        'headcount.editar',
        'headcount.importar',

        // --- Organigrama (alias operativo de puestos.administrar para nav) ---
        'organigrama.ver',
        'organigrama.editar',

        // --- Modulo de cumpleanos (docs/CUMPLEANOS.md) ---
        'rh.cumpleanos.ver',
        'rh.cumpleanos.calendario',
        'rh.cumpleanos.descargar_imagen',
        'rh.cumpleanos.configurar',
        'rh.cumpleanos.frases.gestionar',
        'rh.cumpleanos.notificaciones.gestionar',

        // --- Descarga de app / APK (docs/APP_RELEASES.md) ---
        'app_releases.ver',
        'app_releases.crear',
        'app_releases.publicar',
        'app_releases.eliminar',
        'app_releases.descargar',
    ];

    /**
     * Mapa inicial rol => permisos. Editable despues desde la pantalla de
     * administracion de roles; esto es solo el punto de partida razonable.
     *
     * @var array<string, array<int, string>>
     */
    private const ROLES = [
        // self::PERMISOS ya NO incluye PERMISOS_PERSONALES (ver su
        // docblock): super_admin es un rol operativo, no un colaborador.
        'super_admin' => self::PERMISOS,
        'administrador_capacitacion' => [
            'dashboard.global.ver',
            'usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'usuarios.desactivar',
            'sucursales.administrar', 'departamentos.administrar', 'puestos.administrar',
            'cursos.ver', 'cursos.crear', 'cursos.editar', 'cursos.publicar', 'cursos.eliminar',
            'multimedia.administrar', 'cuestionarios.administrar',
            'respuestas.ver', 'respuestas.calificar',
            'actividades.administrar',
            'asignaciones.crear', 'asignaciones.ver', 'asignaciones.cancelar',
            'sesiones.administrar', 'asistencias.ver', 'asistencias.corregir',
            'reportes.globales', 'reportes.sucursal', 'reportes.exportar',
            'integraciones.administrar', 'auditoria.ver',
        ],
        'instructor' => [
            'dashboard.sucursal.ver',
            'cursos.ver', 'cursos.crear', 'cursos.editar',
            'multimedia.administrar', 'cuestionarios.administrar',
            'respuestas.ver', 'respuestas.calificar',
            'actividades.administrar',
            'sesiones.administrar', 'asistencias.ver',
            'reportes.sucursal',
        ],

        // --- Roles del Portal RH (docs/ROLES_PERMISOS_RH.md) ---

        // Administra todo el modulo RH (expedientes, documentos, altas,
        // vacaciones, solicitudes, reportes) para toda la organizacion, pero
        // no configura empresas.eliminar/roles.administrar/configuracion
        // (reservado a super_admin).
        'rh_admin' => [
            'dashboard.global.ver',
            'usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'usuarios.desactivar',
            'sucursales.administrar', 'departamentos.administrar', 'puestos.administrar',
            'empresas.ver', 'empresas.crear', 'empresas.editar',
            'expedientes.ver', 'expedientes.ver_todos', 'expedientes.crear', 'expedientes.editar', 'expedientes.revisar',
            'documentos.ver', 'documentos.subir', 'documentos.descargar', 'documentos.revisar', 'documentos.aprobar', 'documentos.rechazar', 'documentos.versiones',
            'altas.ver', 'altas.crear', 'altas.enviar', 'altas.revisar', 'altas.aprobar', 'altas.cancelar',
            'vacaciones.ver', 'vacaciones.solicitar', 'vacaciones.aprobar', 'vacaciones.rechazar', 'vacaciones.ajustar', 'vacaciones.reportes',
            'solicitudes.ver', 'solicitudes.crear', 'solicitudes.revisar', 'solicitudes.aprobar', 'solicitudes.rechazar', 'solicitudes.cerrar',
            'reportes_rh.ver', 'reportes_rh.exportar', 'reportes_rh.globales', 'reportes_rh.sucursal',
            'auditoria.ver',
            'vacantes.ver', 'vacantes.ver_todos', 'vacantes.crear', 'vacantes.editar', 'vacantes.cerrar', 'vacantes.eliminar',
            'candidatos.ver', 'candidatos.ver_todos', 'candidatos.crear', 'candidatos.editar', 'candidatos.aprobar', 'candidatos.rechazar', 'candidatos.eliminar',
            'plantillas.ver', 'plantillas.crear', 'plantillas.editar', 'plantillas.eliminar', 'plantillas.generar',
            'formatos.ver', 'formatos.preview', 'formatos.descargar_pdf', 'formatos.descargar_docx',
            'formatos_oficiales.ver', 'formatos_oficiales.generar', 'formatos_oficiales.descargar', 'formatos_oficiales.configurar',
            'rh.expedientes.ver', 'rh.expedientes.detalle', 'rh.expedientes.documentos.ver', 'rh.expedientes.documentos.aprobar',
            'rh.expedientes.documentos.rechazar', 'rh.expedientes.documentos.autorizar-cambio',
            'rh.expedientes.incorporacion.aprobar', 'rh.expedientes.incorporacion.rechazar',
            'rh.incorporacion.invitaciones.ver', 'rh.incorporacion.invitaciones.crear',
            'rh.incorporacion.invitaciones.regenerar', 'rh.incorporacion.invitaciones.revocar',
            'rh.incorporacion.invitaciones.qr.descargar',
            // Backend movil v5: RH mobile completo.
            'mobile.bootstrap.ver', 'app.config.ver',
            'dispositivos.push_token.registrar', 'dispositivos.push_token.revocar',
            'rh.mobile.dashboard.ver', 'rh.pendientes.ver',
            'rh.solicitudes.ver', 'rh.solicitudes.detalle', 'rh.solicitudes.aprobar', 'rh.solicitudes.rechazar', 'rh.solicitudes.correccion',
            'rh.vacaciones.ver', 'rh.vacaciones.detalle', 'rh.vacaciones.aprobar', 'rh.vacaciones.rechazar',
            'rh.documentos.ver', 'rh.documentos.detalle', 'rh.documentos.ver_archivo', 'rh.documentos.aprobar', 'rh.documentos.rechazar',
            'rh.documentos.extraccion.ver', 'rh.documentos.extraccion.aplicar', 'rh.documentos.extraccion.reprocesar', 'rh.documentos.extraccion.ignorar',
            'rh.incorporaciones.ver', 'rh.incorporaciones.detalle', 'rh.incorporaciones.aprobar', 'rh.incorporaciones.rechazar',
            'rh.colaboradores.ver', 'rh.colaboradores.detalle',
            'notificaciones.leer_todas',
            'solicitudes.configuracion.ver', 'solicitudes.adjuntos.subir',
            'rh.cumpleanos.ver', 'rh.cumpleanos.calendario', 'rh.cumpleanos.descargar_imagen',
            'rh.cumpleanos.configurar', 'rh.cumpleanos.frases.gestionar', 'rh.cumpleanos.notificaciones.gestionar',
            'app_releases.ver', 'app_releases.crear', 'app_releases.publicar', 'app_releases.eliminar', 'app_releases.descargar',
            'solicitudes.bajas.crear', 'solicitudes.bajas.aprobar',
            'finiquitos.ver', 'finiquitos.calcular', 'finiquitos.revisar', 'finiquitos.subir_firmado',
            'headcount.ver', 'headcount.editar', 'headcount.importar',
            'organigrama.ver', 'organigrama.editar',
        ],

        // Apoyo operativo de RH: puede capturar/revisar pero no aprobar
        // decisiones finales (documentos, altas, vacaciones, solicitudes).
        'rh_auxiliar' => [
            'dashboard.global.ver',
            'usuarios.ver',
            'expedientes.ver', 'expedientes.ver_todos',
            'documentos.ver', 'documentos.subir', 'documentos.descargar', 'documentos.revisar',
            'altas.ver', 'altas.crear', 'altas.enviar',
            'vacaciones.ver',
            'solicitudes.ver', 'solicitudes.revisar',
            'reportes_rh.ver',
            'vacantes.ver', 'vacantes.ver_todos', 'vacantes.crear', 'vacantes.editar',
            'candidatos.ver', 'candidatos.ver_todos', 'candidatos.crear', 'candidatos.editar',
            'plantillas.ver', 'plantillas.generar',
            'formatos.ver', 'formatos.preview', 'formatos.descargar_pdf', 'formatos.descargar_docx',
            'formatos_oficiales.ver', 'formatos_oficiales.generar', 'formatos_oficiales.descargar',
            'rh.expedientes.ver', 'rh.expedientes.detalle', 'rh.expedientes.documentos.ver',
            'rh.incorporacion.invitaciones.ver', 'rh.incorporacion.invitaciones.crear', 'rh.incorporacion.invitaciones.qr.descargar',
            // Backend movil v5: ve pendientes/documentos/incorporaciones, no aprueba decisiones finales.
            'mobile.bootstrap.ver', 'app.config.ver',
            'dispositivos.push_token.registrar', 'dispositivos.push_token.revocar',
            'rh.mobile.dashboard.ver', 'rh.pendientes.ver',
            'rh.solicitudes.ver', 'rh.solicitudes.detalle',
            'rh.vacaciones.ver', 'rh.vacaciones.detalle',
            'rh.documentos.ver', 'rh.documentos.detalle', 'rh.documentos.ver_archivo',
            'rh.documentos.extraccion.ver', 'rh.documentos.extraccion.reprocesar',
            'rh.incorporaciones.ver', 'rh.incorporaciones.detalle',
            'rh.colaboradores.ver', 'rh.colaboradores.detalle',
            'notificaciones.leer_todas',
            'solicitudes.configuracion.ver', 'solicitudes.adjuntos.subir',
            'rh.cumpleanos.ver', 'rh.cumpleanos.calendario', 'rh.cumpleanos.descargar_imagen',
            'app_releases.ver',
            'finiquitos.ver', 'finiquitos.calcular', 'finiquitos.subir_firmado',
            'headcount.ver', 'organigrama.ver',
        ],

        'gerente_sucursal' => [
            'dashboard.sucursal.ver',
            'usuarios.ver', 'usuarios.editar',
            'asignaciones.ver',
            'asistencias.ver',
            'reportes.sucursal', 'reportes.exportar',
            'expedientes.ver', 'expedientes.ver_sucursal',
            'documentos.ver',
            'formatos.ver', 'formatos.preview', 'formatos.descargar_pdf', 'formatos.descargar_docx',
            'formatos_oficiales.ver', 'formatos_oficiales.generar', 'formatos_oficiales.descargar',
            'vacaciones.ver', 'vacaciones.solicitar', 'vacaciones.aprobar', 'vacaciones.rechazar',
            'solicitudes.ver', 'solicitudes.revisar', 'solicitudes.aprobar',
            'reportes_rh.ver', 'reportes_rh.sucursal',
            'vacantes.ver', 'vacantes.ver_sucursal', 'vacantes.crear',
            'candidatos.ver', 'candidatos.ver_sucursal', 'candidatos.aprobar', 'candidatos.rechazar',
            'altas.ver', 'altas.revisar', 'altas.aprobar',
            // Backend movil v5: aprueba solicitudes/vacaciones de su sucursal desde la app.
            'mobile.bootstrap.ver', 'app.config.ver',
            'dispositivos.push_token.registrar', 'dispositivos.push_token.revocar',
            'rh.mobile.dashboard.ver', 'rh.pendientes.ver',
            'rh.solicitudes.ver', 'rh.solicitudes.detalle', 'rh.solicitudes.aprobar', 'rh.solicitudes.rechazar', 'rh.solicitudes.correccion',
            'rh.vacaciones.ver', 'rh.vacaciones.detalle', 'rh.vacaciones.aprobar', 'rh.vacaciones.rechazar',
            'notificaciones.leer_todas',
            'solicitudes.configuracion.ver', 'solicitudes.adjuntos.subir',
            'solicitudes.bajas.crear', 'headcount.ver', 'organigrama.ver',
        ],
        'supervisor' => [
            'dashboard.sucursal.ver',
            'usuarios.ver',
            'asistencias.ver',
            'reportes.sucursal',
            'mobile.bootstrap.ver', 'app.config.ver',
            'dispositivos.push_token.registrar', 'dispositivos.push_token.revocar',
            'notificaciones.leer_todas', 'solicitudes.configuracion.ver', 'solicitudes.adjuntos.subir',
        ],

        // --- Roles ampliados (jerarquía comercial/regional, Mr. Lana People Fase 1) ---

        // Alcance global, solo lectura de reportes: no administra expedientes,
        // documentos ni aprueba solicitudes/vacaciones (eso es de RH/gerencia
        // de sucursal). Ver AlcanceOrganizacionalService::ROLES_ALCANCE_GLOBAL.
        'director_comercial' => [
            'dashboard.global.ver',
            'usuarios.ver',
            'empresas.ver',
            'reportes_rh.ver', 'reportes_rh.globales', 'reportes_rh.exportar',
            'vacantes.ver', 'vacantes.ver_todos',
            'candidatos.ver', 'candidatos.ver_todos',
            // Backend movil v5: solo lectura, sin autoridad de aprobacion (igual criterio que en web).
            'mobile.bootstrap.ver', 'app.config.ver',
            'dispositivos.push_token.registrar', 'dispositivos.push_token.revocar',
            'rh.mobile.dashboard.ver', 'rh.pendientes.ver',
            'rh.solicitudes.ver', 'rh.solicitudes.detalle',
            'rh.vacaciones.ver', 'rh.vacaciones.detalle',
            'rh.colaboradores.ver', 'rh.colaboradores.detalle',
            'notificaciones.leer_todas',
            'headcount.ver', 'organigrama.ver',
        ],

        // Ve y administra varias sucursales de su región (sucursal principal +
        // sucursales adicionales asignadas, mismo mecanismo que
        // gerente_sucursal — ver AlcanceOrganizacionalService::ROLES_ALCANCE_SUCURSAL
        // y sucursal_user). Alcance más amplio que "gerente" (una sola sucursal).
        'gerente_regional' => [
            'dashboard.sucursal.ver',
            'usuarios.ver', 'usuarios.editar',
            'reportes.sucursal', 'reportes.exportar',
            'expedientes.ver', 'expedientes.ver_sucursal',
            'documentos.ver',
            'vacaciones.ver', 'vacaciones.solicitar', 'vacaciones.aprobar', 'vacaciones.rechazar',
            'solicitudes.ver', 'solicitudes.revisar', 'solicitudes.aprobar',
            'reportes_rh.ver', 'reportes_rh.sucursal',
            'vacantes.ver', 'vacantes.ver_sucursal', 'vacantes.crear',
            'candidatos.ver', 'candidatos.ver_sucursal', 'candidatos.aprobar', 'candidatos.rechazar',
            'mobile.bootstrap.ver', 'app.config.ver',
            'dispositivos.push_token.registrar', 'dispositivos.push_token.revocar',
            'rh.mobile.dashboard.ver', 'rh.pendientes.ver',
            'rh.solicitudes.ver', 'rh.solicitudes.detalle', 'rh.solicitudes.aprobar', 'rh.solicitudes.rechazar', 'rh.solicitudes.correccion',
            'rh.vacaciones.ver', 'rh.vacaciones.detalle', 'rh.vacaciones.aprobar', 'rh.vacaciones.rechazar',
            'notificaciones.leer_todas',
            'solicitudes.configuracion.ver', 'solicitudes.adjuntos.subir',
            'solicitudes.bajas.crear', 'headcount.ver', 'organigrama.ver',
        ],

        // Administra su propia sucursal (mismo alcance que gerente_sucursal,
        // catálogo de permisos equivalente). "gerente_sucursal" se conserva
        // intacto por compatibilidad con datos/roles ya asignados.
        'gerente' => [
            'dashboard.sucursal.ver',
            'usuarios.ver', 'usuarios.editar',
            'reportes.sucursal', 'reportes.exportar',
            'expedientes.ver', 'expedientes.ver_sucursal',
            'documentos.ver',
            'vacaciones.ver', 'vacaciones.solicitar', 'vacaciones.aprobar', 'vacaciones.rechazar',
            'solicitudes.ver', 'solicitudes.revisar', 'solicitudes.aprobar',
            'reportes_rh.ver', 'reportes_rh.sucursal',
            'vacantes.ver', 'vacantes.ver_sucursal', 'vacantes.crear',
            'candidatos.ver', 'candidatos.ver_sucursal', 'candidatos.aprobar', 'candidatos.rechazar',
            'mobile.bootstrap.ver', 'app.config.ver',
            'dispositivos.push_token.registrar', 'dispositivos.push_token.revocar',
            'rh.mobile.dashboard.ver', 'rh.pendientes.ver',
            'rh.solicitudes.ver', 'rh.solicitudes.detalle', 'rh.solicitudes.aprobar', 'rh.solicitudes.rechazar', 'rh.solicitudes.correccion',
            'rh.vacaciones.ver', 'rh.vacaciones.detalle', 'rh.vacaciones.aprobar', 'rh.vacaciones.rechazar',
            'notificaciones.leer_todas',
            'solicitudes.configuracion.ver', 'solicitudes.adjuntos.subir',
            'solicitudes.bajas.crear', 'headcount.ver', 'organigrama.ver',
        ],

        // Puede apoyar/cubrir al gerente de su sucursal según la jerarquía de
        // puestos: mismo alcance y catálogo funcional que "gerente".
        'subgerente' => [
            'dashboard.sucursal.ver',
            'usuarios.ver',
            'reportes.sucursal',
            'expedientes.ver', 'expedientes.ver_sucursal',
            'documentos.ver',
            'vacaciones.ver', 'vacaciones.solicitar', 'vacaciones.aprobar', 'vacaciones.rechazar',
            'solicitudes.ver', 'solicitudes.revisar', 'solicitudes.aprobar',
            'reportes_rh.ver', 'reportes_rh.sucursal',
            'vacantes.ver', 'vacantes.ver_sucursal',
            'candidatos.ver', 'candidatos.ver_sucursal',
            'mobile.bootstrap.ver', 'app.config.ver',
            'dispositivos.push_token.registrar', 'dispositivos.push_token.revocar',
            'rh.mobile.dashboard.ver', 'rh.pendientes.ver',
            'rh.solicitudes.ver', 'rh.solicitudes.detalle', 'rh.solicitudes.aprobar', 'rh.solicitudes.rechazar', 'rh.solicitudes.correccion',
            'rh.vacaciones.ver', 'rh.vacaciones.detalle', 'rh.vacaciones.aprobar', 'rh.vacaciones.rechazar',
            'notificaciones.leer_todas',
            'solicitudes.configuracion.ver', 'solicitudes.adjuntos.subir',
            'solicitudes.bajas.crear', 'headcount.ver', 'organigrama.ver',
        ],

        // Apoyo administrativo regional: ve procesos (expedientes, documentos,
        // solicitudes) de varias sucursales de su región, sin autoridad de
        // reclutamiento ni de aprobar vacaciones/solicitudes (solo revisar).
        'coordinadora_regional' => [
            'dashboard.sucursal.ver',
            'usuarios.ver',
            'expedientes.ver', 'expedientes.ver_sucursal',
            'documentos.ver', 'documentos.subir', 'documentos.revisar',
            'vacaciones.ver',
            'solicitudes.ver', 'solicitudes.revisar',
            'reportes_rh.ver', 'reportes_rh.sucursal',
            // Backend movil v5: ve/revisa desde la app, no aprueba decisiones finales.
            'mobile.bootstrap.ver', 'app.config.ver',
            'dispositivos.push_token.registrar', 'dispositivos.push_token.revocar',
            'rh.mobile.dashboard.ver', 'rh.pendientes.ver',
            'rh.solicitudes.ver', 'rh.solicitudes.detalle',
            'rh.vacaciones.ver', 'rh.vacaciones.detalle',
            'rh.documentos.ver', 'rh.documentos.detalle', 'rh.documentos.ver_archivo',
            'notificaciones.leer_todas',
            'solicitudes.configuracion.ver', 'solicitudes.adjuntos.subir',
            'organigrama.ver',
        ],

        // Mismo catálogo que coordinadora_regional, acotado a su propia
        // sucursal (el alcance real depende de qué sucursales tenga
        // asignadas el usuario, no del nombre del rol).
        'coordinadora' => [
            'dashboard.sucursal.ver',
            'usuarios.ver',
            'expedientes.ver', 'expedientes.ver_sucursal',
            'documentos.ver', 'documentos.subir', 'documentos.revisar',
            'vacaciones.ver',
            'solicitudes.ver', 'solicitudes.revisar',
            'reportes_rh.ver', 'reportes_rh.sucursal',
            'mobile.bootstrap.ver', 'app.config.ver',
            'dispositivos.push_token.registrar', 'dispositivos.push_token.revocar',
            'rh.mobile.dashboard.ver', 'rh.pendientes.ver',
            'rh.solicitudes.ver', 'rh.solicitudes.detalle',
            'rh.vacaciones.ver', 'rh.vacaciones.detalle',
            'rh.documentos.ver', 'rh.documentos.detalle', 'rh.documentos.ver_archivo',
            'notificaciones.leer_todas',
            'solicitudes.configuracion.ver', 'solicitudes.adjuntos.subir',
            'organigrama.ver',
        ],

        // Ve y aprueba vacaciones/solicitudes de sus subordinados directos
        // (jefe_id), un alcance mas estrecho que gerente_sucursal (que ve
        // toda la sucursal). Ver AlcanceOrganizacionalService.
        'jefe_directo' => [
            'dashboard.sucursal.ver',
            // ver_sucursal es el permiso "puedo ver expedientes mas alla del
            // mio" que exige AlcanceOrganizacionalService::puedeVerExpediente();
            // el alcance real para este rol se acota a sus subordinados
            // directos (jefe_id), no a toda la sucursal, en
            // limitarUsuariosPorAlcance()/puedeVerUsuario().
            'expedientes.ver', 'expedientes.ver_sucursal',
            'documentos.ver',
            'vacaciones.ver', 'vacaciones.solicitar', 'vacaciones.aprobar', 'vacaciones.rechazar',
            'solicitudes.ver', 'solicitudes.revisar', 'solicitudes.aprobar',
            'mobile.bootstrap.ver', 'app.config.ver',
            'dispositivos.push_token.registrar', 'dispositivos.push_token.revocar',
            'rh.mobile.dashboard.ver', 'rh.pendientes.ver',
            'rh.solicitudes.ver', 'rh.solicitudes.detalle', 'rh.solicitudes.aprobar', 'rh.solicitudes.rechazar', 'rh.solicitudes.correccion',
            'rh.vacaciones.ver', 'rh.vacaciones.detalle', 'rh.vacaciones.aprobar', 'rh.vacaciones.rechazar',
            'notificaciones.leer_todas',
            'solicitudes.configuracion.ver', 'solicitudes.adjuntos.subir',
            'organigrama.ver',
        ],

        'colaborador' => [
            'expedientes.ver',
            'documentos.ver', 'documentos.subir', 'documentos.descargar',
            'vacaciones.ver', 'vacaciones.solicitar',
            'solicitudes.ver', 'solicitudes.crear',
            'portal.ver', 'portal.perfil.ver', 'portal.solicitudes.ver', 'portal.solicitudes.crear', 'portal.notificaciones.ver',
            'colaborador.incorporacion.ver', 'colaborador.incorporacion.documentos.subir', 'colaborador.incorporacion.documentos.solicitar-cambio',
            // Backend movil v5: cualquier colaborador usa el bootstrap/config/dispositivos/notificaciones/solicitudes de la app.
            'mobile.bootstrap.ver', 'app.config.ver',
            'dispositivos.push_token.registrar', 'dispositivos.push_token.revocar',
            'notificaciones.leer_todas',
            'solicitudes.configuracion.ver', 'solicitudes.adjuntos.subir',
        ],
        'auditor' => [
            'dashboard.global.ver',
            'usuarios.ver',
            'respuestas.ver',
            'asistencias.ver',
            'reportes.globales', 'reportes.sucursal', 'reportes.exportar',
            'auditoria.ver',
            'empresas.ver',
            'expedientes.ver', 'expedientes.ver_todos',
            'documentos.ver',
            'vacaciones.ver',
            'solicitudes.ver',
            'reportes_rh.ver', 'reportes_rh.globales', 'reportes_rh.sucursal',
            'vacantes.ver', 'vacantes.ver_todos',
            'candidatos.ver', 'candidatos.ver_todos',
            // Backend movil v5: lectura global, sin aprobar decisiones.
            'mobile.bootstrap.ver', 'app.config.ver',
            'dispositivos.push_token.registrar', 'dispositivos.push_token.revocar',
            'rh.mobile.dashboard.ver', 'rh.pendientes.ver',
            'rh.solicitudes.ver', 'rh.solicitudes.detalle',
            'rh.vacaciones.ver', 'rh.vacaciones.detalle',
            'rh.documentos.ver', 'rh.documentos.detalle', 'rh.documentos.ver_archivo',
            'rh.incorporaciones.ver', 'rh.incorporaciones.detalle',
            'rh.colaboradores.ver', 'rh.colaboradores.detalle',
            'notificaciones.leer_todas',
            'headcount.ver', 'organigrama.ver',
        ],
    ];

    public function run(): void
    {
        foreach ([...self::PERMISOS, ...self::PERMISOS_PERSONALES] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        foreach (self::ROLES as $rol => $permisos) {
            $role = Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);
            $role->syncPermissions($permisos);
        }
    }
}
