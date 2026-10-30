<?php

namespace Database\Seeders;

use App\Services\Permisos\SincronizadorPermisosService;
use Illuminate\Database\Seeder;

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
     * Permisos "personales" del rol `colaborador` (Mi portal, mi perfil,
     * mis solicitudes, mis notificaciones). Se mantienen fuera del catálogo
     * operativo de `self::PERMISOS` que se le asigna en bloque a
     * `super_admin`. Ojo: "Mi espacio" ya NO depende solo de estos
     * permisos — una cuenta administrativa enlazada a un Colaborador activo
     * también lo tiene (Gate `modo-colaborador`,
     * App\Services\Navigation\NavigationService::puedeUsarModoColaborador()),
     * sin necesitar el rol `colaborador`.
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
        'usuarios.reactivar',
        // Rehabilitar el acceso de una cuenta suspendida (p. ej. por una baja
        // en trámite) sin poder revocar cuentas: RH y regionales.
        'usuarios.restablecer_acceso',
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
        // Avisos de RH: mensaje + imagen a toda la empresa o a un colaborador.
        'avisos.ver',
        'avisos.enviar',
        // Directorio: correo y teléfono corporativos de TODAS las personas
        // (sin él, solo los de su jefe directo; el personal corporativo los
        // ve por su ubicación). Nombre, puesto, área y sucursal los ve todos.
        'directorio.contactos.ver_todos',
        // Edición rápida del Directorio (contacto corporativo, puesto,
        // sucursal y departamento sin abrir cada expediente). Solo
        // super_admin; nunca la propia fila (DirectorioEdicionService).
        'directorio.editar',
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
        // Migración inicial de colaboradores/expedientes históricos y datos médicos (sensibles).
        'expedientes.migrar',
        'expedientes.datos_medicos.ver',
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
        // Reportes RH (Fase 4, catalogo desde ahora)
        'reportes_rh.ver',
        'reportes_rh.exportar',
        'reportes_rh.globales',
        'reportes_rh.sucursal',
        'reportes_rh.mis_reportes',
        'reportes_rh.datos_sensibles',
        'reportes_rh.importar_historico',

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
        // Gasto de campañas de reclutamiento por canal/periodo (ver
        // Rh\CampanaReclutamientoController) — permiso propio, ya no
        // hardcodeado a rh_admin/super_admin.
        'reclutamiento.campanas.ver',
        'reclutamiento.campanas.administrar',

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
        // Plantillas oficiales versionadas: subir, versionar/publicar y
        // archivar son capacidades distintas de mapear campos; los datos
        // salariales en un documento requieren permiso propio.
        'formatos_oficiales.crear',
        'formatos_oficiales.versionar',
        'formatos_oficiales.archivar',
        'formatos_oficiales.datos_salariales',

        // --- Celebraciones: cumpleaños y aniversarios (docs/CELEBRACIONES.md) ---
        'celebraciones.ver',
        'celebraciones.gestionar',
        'celebraciones.enviar',
        'celebraciones.moderar',

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
        // Notificaciones. La campana SIEMPRE muestra las propias (incluso a
        // super_admin): ver_propias es esa bandeja; ver_todas es la consulta
        // administrativa de solo lectura «Notificaciones del sistema».
        // leer_todas conserva su significado histórico: "marcar todas MIS
        // notificaciones como leídas" (nunca fue ver las de otros).
        'notificaciones.leer_todas',
        'notificaciones.ver_propias',
        'notificaciones.ver_todas',
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
        // Bajas y finiquitos → «Causales de rotación» (motivo de cada tipo
        // de baja): alta, edición, activar/desactivar y orden.
        'finiquitos.causales_rotacion',

        // --- Headcount / plantilla autorizada ---
        'headcount.ver',
        'headcount.editar',
        'headcount.importar',

        // --- Administración de salarios (solo RH y Dirección General) ---
        'salarios.ver',
        'salarios.editar',
        'salarios.exportar',
        'salarios.importar',
        'salarios.ver_en_expediente',

        // --- Agenda de entrevistas y estudios socioeconómicos ---
        'agenda.ver',
        'agenda.ver_todos',
        'agenda.ver_region',
        'agenda.ver_sucursal',
        'agenda.agendar',
        'agenda.alta_candidato',
        'agenda.registrar_resultado',
        'agenda.ver_datos_candidato',
        'agenda.exportar',

        // --- Organigrama (alias operativo de puestos.administrar para nav) ---
        'organigrama.ver',
        'organigrama.editar',

        // --- Perfiles de puesto: perfil del candidato + descripción del puesto ---
        'perfiles_puesto.ver',
        'perfiles_puesto.editar',
        // Importar descriptivos oficiales (PDF de la carpeta): solo
        // super_admin (2026-10-20, las importaciones/cargas masivas no son
        // de RH). Editar un perfil sigue siendo perfiles_puesto.editar.
        'perfiles_puesto.importar',

        // --- Modulo de cumpleanos (docs/CUMPLEANOS.md) ---
        'rh.cumpleanos.ver',
        'rh.cumpleanos.calendario',
        'rh.cumpleanos.descargar_imagen',
        'rh.cumpleanos.configurar',
        'rh.cumpleanos.frases.gestionar',
        'rh.cumpleanos.notificaciones.gestionar',
        'rh.cumpleanos.muro.gestionar',

        // --- Descarga de app / APK (docs/APP_RELEASES.md) ---
        'app_releases.ver',
        'app_releases.crear',
        'app_releases.publicar',
        'app_releases.eliminar',
        'app_releases.descargar',

        // --- Ciclo laboral completo (docs/backend-rh-completion.md) ---
        'colaboradores.alta',
        'colaboradores.activar',
        'candidatos.contratar',
        'plantillas_documentales.ver',
        'plantillas_documentales.administrar',
        // Activar una versión de documento maestro SIN QA visual aprobado
        // (motivo obligatorio, auditado). Reservado a super_admin.
        'documentos_maestros.activar_excepcional',
        'documentos_laborales.ver',
        'documentos_laborales.generar',
        'documentos_laborales.operar_fisico',
        'documentos_laborales.cancelar',
        // Nueva revisión de un documento YA firmado (el firmado se conserva).
        'documentos_laborales.revisar_firmado',
        'contratos.ver',
        // Acciones del módulo «Contratos» (config/permisos_modulos.php):
        // contratos.ver deja ver la lista/estado; ver, descargar y generar el
        // contrato son acciones aparte (RH) y el gerente solo sube el firmado.
        'contratos.ver_documento',
        'contratos.descargar',
        'contratos.generar',
        'contratos.subir_firmado',
        // Pasos del gerente en el workflow de candidatos (candidatos.evaluar
        // los cubre todos).
        'candidatos.aprobar_entrevista',
        'candidatos.revisar_psicometricas',
        'candidatos.revisar_socioeconomico',
        'evaluaciones.ver',
        'evaluaciones.capturar',
        'evaluaciones.autorizar',
        // Excepción de Dirección General a la regla canónica de renovación
        // del periodo de prueba (FO-RH-EPP-001) — nunca Regional ni
        // Dirección Comercial, solo quien ocupa Dirección General.
        'excepciones.autorizar',
        'cierres.ver',
        'cierres.gestionar',
        'cierres.ejecutar_baja',
        'finiquitos.confirmar_pago',
        'nomina.recibos.ver',
        'nomina.recibos.crear',
        'nomina.recibos.importar',
        // Importador de REPORTE_TRABAJADORES_VAC.xlsx: salarios y
        // vacaciones históricas (CLAUDE.md §21-36).
        'colaboradores.importar_salarios_vacaciones',
        // Importador del Excel de contactos de emergencia (Google Forms).
        // Las importaciones y cargas masivas (este, expedientes.migrar,
        // headcount.importar, colaboradores.importar_salarios_vacaciones,
        // rotacion.cargar, nomina.recibos.importar, perfiles_puesto.importar)
        // son SOLO de super_admin (2026-10-20); en bases existentes las
        // revoca la migración 2026_10_20_230000_importaciones_solo_super_admin.
        'contactos_emergencia.importar',
        'prestamos.ver',
        'prestamos.autorizar',
        // --- Cierre definitivo del ciclo laboral (docs/CICLO_LABORAL_FINAL_IMPLEMENTADO.md) ---
        // ROL = permisos; la cadena de mando sale del organigrama. Preautorizar
        // exige además ser superior de la persona; autorizar_rh es la
        // autoridad final y nunca se puede saltar.
        'ciclo.preautorizar',
        'ciclo.autorizar_rh',
        // Gerente: entrevista, revisión de psicométricas y socioeconómico.
        'candidatos.evaluar',
        // Decidir una intervención (CLAUDE.md §11): solo Regional (Gestor/
        // Volante) y Dirección Comercial (puesto superior). Nunca se asigna
        // a RH ni al gerente que solicitó la intervención.
        'candidatos.intervencion_decidir',
        'onboarding.ver',
        'onboarding.gestionar',
        'onboarding.entregar_activos',
        // Configurar los formularios de evaluación (inducción y periodo de
        // prueba): editar, ver vista previa y publicar versiones.
        'formularios_evaluacion.administrar',
        'cierres.solicitar',
        'cierres.programar_pago',
        'reingresos.solicitar',
        'reingresos.gestionar',
        'prestamos.resguardar',
        'actas.ver',
        'actas.crear',
        'actas.gestionar',
        'incapacidades.ver',
        'incapacidades.ver_todos',
        'incapacidades.registrar',
        'incapacidades.corregir',
        'incapacidades.descargar',
        'incapacidades.exportar',
        'indicadores.ver',
        // --- Administración → Configuración ---
        // ver: entrar al módulo; cada sección exige además su permiso. Las
        // reglas jurídicas (RH es autorización final) NO son configurables.
        'configuracion.ver',
        'configuracion.apariencia',
        'configuracion.organizacion',
        'configuracion.notificaciones',
        'configuracion.rh',
    ];

    /**
     * Permisos del rol `colaborador` (todo sobre lo PROPIO: su expediente,
     * sus documentos, sus solicitudes). También es el catálogo de
     * `coordinadora` y `coordinadora_regional` — decisión del negocio
     * (2026-10-15): la Coordinadora usa PEOPLE como colaboradora, no como
     * operadora de RH. En bases existentes los permisos operativos viejos
     * de esos dos roles los revoca la migración
     * 2026_10_15_180000_coordinadoras_usan_people_como_colaboradoras.
     *
     * @var list<string>
     */
    public const PERMISOS_ROL_COLABORADOR = [
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
            'salarios.ver', 'salarios.editar', 'salarios.exportar', 'salarios.ver_en_expediente',
            // Alcance amplio: elige de qué sucursales no recibir avisos.
            'notificaciones.silenciar_sucursales',
            'directorio.contactos.ver_todos',
            'perfiles_puesto.ver', 'perfiles_puesto.editar',
            'dashboard.global.ver',
            'usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'usuarios.desactivar', 'usuarios.restablecer_acceso',
            'sucursales.administrar', 'departamentos.administrar', 'puestos.administrar',
            'empresas.ver', 'empresas.crear', 'empresas.editar',
            'expedientes.ver', 'expedientes.ver_todos', 'expedientes.crear', 'expedientes.editar', 'expedientes.revisar',
            // Sin importaciones/cargas masivas (solo super_admin, 2026-10-20).
            'expedientes.datos_medicos.ver',
            'documentos.ver', 'documentos.subir', 'documentos.descargar', 'documentos.revisar', 'documentos.aprobar', 'documentos.rechazar', 'documentos.versiones',
            'altas.ver', 'altas.crear', 'altas.enviar', 'altas.revisar', 'altas.aprobar', 'altas.cancelar',
            'vacaciones.ver', 'vacaciones.solicitar', 'vacaciones.aprobar', 'vacaciones.rechazar', 'vacaciones.ajustar', 'vacaciones.reportes',
            'solicitudes.ver', 'solicitudes.crear', 'solicitudes.revisar', 'solicitudes.aprobar', 'solicitudes.rechazar',
            'reportes_rh.ver', 'reportes_rh.exportar', 'reportes_rh.globales', 'reportes_rh.sucursal', 'reportes_rh.mis_reportes', 'reportes_rh.datos_sensibles',
            'rotacion.ver',
            'auditoria.ver',
            'vacantes.ver', 'vacantes.ver_todos', 'vacantes.crear', 'vacantes.editar', 'vacantes.cerrar', 'vacantes.eliminar',
            'candidatos.ver', 'candidatos.ver_todos', 'candidatos.crear', 'candidatos.editar', 'candidatos.aprobar', 'candidatos.rechazar', 'candidatos.eliminar',
            'agenda.ver', 'agenda.ver_todos', 'agenda.agendar', 'agenda.alta_candidato', 'agenda.registrar_resultado', 'agenda.ver_datos_candidato', 'agenda.exportar',
            'reclutamiento.campanas.ver', 'reclutamiento.campanas.administrar',
            'plantillas.ver', 'plantillas.crear', 'plantillas.editar', 'plantillas.eliminar', 'plantillas.generar',
            'formatos.ver', 'formatos.preview', 'formatos.descargar_pdf', 'formatos.descargar_docx',
            'formatos_oficiales.ver', 'formatos_oficiales.generar', 'formatos_oficiales.descargar', 'formatos_oficiales.configurar',
            'formatos_oficiales.crear', 'formatos_oficiales.versionar', 'formatos_oficiales.archivar', 'formatos_oficiales.datos_salariales',
            'celebraciones.ver', 'celebraciones.gestionar', 'celebraciones.enviar', 'celebraciones.moderar',
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
            'rh.cumpleanos.configurar', 'rh.cumpleanos.frases.gestionar', 'rh.cumpleanos.notificaciones.gestionar', 'rh.cumpleanos.muro.gestionar',
            'app_releases.ver', 'app_releases.crear', 'app_releases.publicar', 'app_releases.eliminar', 'app_releases.descargar',
            'solicitudes.bajas.crear', 'solicitudes.bajas.aprobar',
            'finiquitos.ver', 'finiquitos.calcular', 'finiquitos.revisar', 'finiquitos.subir_firmado', 'finiquitos.causales_rotacion',
            // Módulo de Bajas y Causales: RH valida, rechaza, cambia causal,
            // cierra y reabre (docs/BAJAS_Y_CAUSALES.md).
            'bajas.ver', 'bajas.solicitar', 'bajas.gestionar', 'bajas.tablero.ver',
            'headcount.ver', 'headcount.editar',
            'organigrama.ver', 'organigrama.editar',
            // Ciclo laboral completo (docs/backend-rh-completion.md).
            'colaboradores.alta', 'colaboradores.activar', 'candidatos.contratar',
            'plantillas_documentales.ver', 'plantillas_documentales.administrar',
            'documentos_laborales.ver', 'documentos_laborales.generar', 'documentos_laborales.operar_fisico', 'documentos_laborales.cancelar', 'documentos_laborales.revisar_firmado',
            'contratos.ver', 'evaluaciones.ver', 'evaluaciones.capturar', 'evaluaciones.autorizar',
            // Contratos: ver/descargar/generar heredan de documentos_laborales.generar;
            // subir el firmado se asigna aquí.
            'contratos.subir_firmado',
            'cierres.ver', 'cierres.gestionar', 'cierres.ejecutar_baja', 'finiquitos.confirmar_pago',
            // Generar/emitir recibos sí; importarlos por Excel, no (solo
            // super_admin, igual que salarios y vacaciones — 2026-10-20).
            'nomina.recibos.ver', 'nomina.recibos.crear',
            'prestamos.ver', 'prestamos.autorizar', 'prestamos.resguardar',
            'actas.ver', 'actas.crear', 'actas.gestionar', 'actas.descargar', 'indicadores.ver',
            'incapacidades.ver', 'incapacidades.ver_todos', 'incapacidades.registrar', 'incapacidades.corregir', 'incapacidades.descargar', 'incapacidades.exportar',
            // Responsivas de equipo (docs/responsiva).
            'responsivas.ver', 'responsivas.generar', 'responsivas.cancelar',
            // Cartas compromiso de documentos pendientes (nuevo ingreso).
            'cartas_compromiso.ver', 'cartas_compromiso.generar', 'cartas_compromiso.subir_firmada', 'cartas_compromiso.descargar',
            'ciclo.autorizar_rh', 'candidatos.evaluar', 'onboarding.ver', 'onboarding.gestionar', 'onboarding.entregar_activos', 'cierres.solicitar', 'cierres.programar_pago', 'reingresos.solicitar', 'reingresos.gestionar',
            'formularios_evaluacion.administrar',
            // RH administra jefes directos, ruteo de avisos y parámetros de
            // RH; la apariencia institucional queda en super_admin.
            'configuracion.ver', 'configuracion.organizacion', 'configuracion.notificaciones', 'configuracion.rh',
            'avisos.ver', 'avisos.enviar',
        ],

        // Apoyo operativo de RH: puede capturar/revisar pero no aprobar
        // decisiones finales (documentos, altas, vacaciones, solicitudes).
        'rh_auxiliar' => [
            // Captura y consulta el sueldo del expediente; el módulo de salarios no.
            'salarios.ver_en_expediente',
            // Alcance amplio: elige de qué sucursales no recibir avisos.
            'notificaciones.silenciar_sucursales',
            'directorio.contactos.ver_todos',
            'perfiles_puesto.ver', 'perfiles_puesto.editar',
            'dashboard.global.ver',
            'usuarios.ver',
            'expedientes.ver', 'expedientes.ver_todos',
            'documentos.ver', 'documentos.subir', 'documentos.descargar', 'documentos.revisar',
            'altas.ver', 'altas.crear', 'altas.enviar',
            'vacaciones.ver',
            'solicitudes.ver', 'solicitudes.revisar',
            'reportes_rh.ver', 'rotacion.ver',
            'vacantes.ver', 'vacantes.ver_todos', 'vacantes.crear', 'vacantes.editar',
            'candidatos.ver', 'candidatos.ver_todos', 'candidatos.crear', 'candidatos.editar',
            'agenda.ver', 'agenda.ver_todos', 'agenda.agendar', 'agenda.alta_candidato', 'agenda.registrar_resultado', 'agenda.ver_datos_candidato', 'agenda.exportar',
            'reclutamiento.campanas.ver',
            'plantillas.ver', 'plantillas.generar', 'celebraciones.ver',
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
            'bajas.ver', 'bajas.tablero.ver',
            'headcount.ver', 'organigrama.ver',
            // Ciclo laboral completo (docs/backend-rh-completion.md).
            'colaboradores.alta', 'candidatos.contratar', 'plantillas_documentales.ver',
            'documentos_laborales.ver', 'documentos_laborales.generar', 'documentos_laborales.operar_fisico',
            'contratos.ver', 'evaluaciones.ver', 'cierres.ver',
            // Contratos: ver/descargar/generar heredan de documentos_laborales.generar;
            // subir el firmado se asigna aquí.
            'contratos.subir_firmado',
            'nomina.recibos.ver', 'nomina.recibos.crear',
            'prestamos.ver', 'actas.ver', 'actas.crear', 'actas.descargar', 'indicadores.ver',
            'incapacidades.ver', 'incapacidades.ver_todos', 'incapacidades.registrar', 'incapacidades.descargar', 'incapacidades.exportar',
            'responsivas.ver', 'responsivas.generar',
            'cartas_compromiso.ver', 'cartas_compromiso.generar', 'cartas_compromiso.subir_firmada', 'cartas_compromiso.descargar',
            'onboarding.ver', 'onboarding.gestionar', 'reingresos.solicitar',
        ],

        'gerente_sucursal' => [
            'directorio.contactos.ver_todos',
            'perfiles_puesto.ver',
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
            'agenda.ver', 'agenda.ver_sucursal', 'agenda.agendar', 'agenda.alta_candidato', 'agenda.registrar_resultado', 'agenda.ver_datos_candidato',
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
            // Ciclo laboral completo (docs/backend-rh-completion.md).
            'contratos.ver', 'evaluaciones.ver', 'evaluaciones.capturar', 'prestamos.ver',
            'actas.ver', 'actas.crear', 'documentos_laborales.ver', 'indicadores.ver',
            // Responsivas de equipo para su equipo y su subgerente.
            'responsivas.ver', 'responsivas.generar',
            'ciclo.preautorizar', 'candidatos.evaluar', 'onboarding.ver', 'onboarding.entregar_activos', 'cierres.ver', 'cierres.solicitar', 'contratos.ver', 'evaluaciones.ver', 'evaluaciones.capturar', 'documentos_laborales.ver', 'documentos_laborales.operar_fisico', 'reingresos.solicitar',
            // Solicitud de baja de su personal + tablero de su alcance.
            'bajas.ver', 'bajas.solicitar', 'bajas.tablero.ver',
            // Contratos: el gerente solo SUBE el firmado de su gente (no ve,
            // descarga ni genera el contrato; ContratoFirmadoService).
            'contratos.subir_firmado',
            // Cartas compromiso de su gente de nuevo ingreso: la genera, la
            // imprime para que la firme quien entra y sube el escaneo
            // firmado. «descargar» es SOLO el PDF de la carta, no el resto
            // del expediente (gerente_sucursal = gerente: mismo catálogo).
            'cartas_compromiso.ver', 'cartas_compromiso.generar', 'cartas_compromiso.subir_firmada', 'cartas_compromiso.descargar',
        ],
        'supervisor' => [
            'perfiles_puesto.ver',
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
            // Alcance amplio: elige de qué sucursales no recibir avisos.
            'notificaciones.silenciar_sucursales',
            'directorio.contactos.ver_todos',
            'perfiles_puesto.ver',
            'dashboard.global.ver',
            'usuarios.ver',
            'empresas.ver',
            'reportes_rh.ver', 'reportes_rh.globales', 'reportes_rh.exportar', 'rotacion.ver',
            // Tablero de rotación global y por sucursal, solo lectura.
            'bajas.tablero.ver',
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
            // Única autoridad que decide una intervención de un puesto
            // superior a Gestor/Volante (CLAUDE.md §11-B) — no aprueba
            // candidatos normales, solo esta excepción.
            'candidatos.intervencion_decidir',
        ],

        // Ve y administra varias sucursales de su región (sucursal principal +
        // sucursales adicionales asignadas, mismo mecanismo que
        // gerente_sucursal — ver AlcanceOrganizacionalService::ROLES_ALCANCE_SUCURSAL
        // y sucursal_user). Alcance más amplio que "gerente" (una sola sucursal).
        'gerente_regional' => [
            // Alcance amplio: elige de qué sucursales no recibir avisos.
            'notificaciones.silenciar_sucursales',
            'directorio.contactos.ver_todos',
            'perfiles_puesto.ver',
            'dashboard.sucursal.ver',
            'usuarios.ver', 'usuarios.editar', 'usuarios.restablecer_acceso',
            'reportes.sucursal', 'reportes.exportar',
            'expedientes.ver', 'expedientes.ver_sucursal',
            'documentos.ver',
            'vacaciones.ver', 'vacaciones.solicitar', 'vacaciones.aprobar', 'vacaciones.rechazar',
            'solicitudes.ver', 'solicitudes.revisar', 'solicitudes.aprobar',
            'reportes_rh.ver', 'reportes_rh.sucursal',
            'vacantes.ver', 'vacantes.ver_sucursal', 'vacantes.crear',
            'candidatos.ver', 'candidatos.ver_sucursal', 'candidatos.aprobar', 'candidatos.rechazar',
            'agenda.ver', 'agenda.ver_sucursal', 'agenda.agendar', 'agenda.alta_candidato', 'agenda.registrar_resultado', 'agenda.ver_datos_candidato',
            'mobile.bootstrap.ver', 'app.config.ver',
            'dispositivos.push_token.registrar', 'dispositivos.push_token.revocar',
            'rh.mobile.dashboard.ver', 'rh.pendientes.ver',
            'rh.solicitudes.ver', 'rh.solicitudes.detalle', 'rh.solicitudes.aprobar', 'rh.solicitudes.rechazar', 'rh.solicitudes.correccion',
            'rh.vacaciones.ver', 'rh.vacaciones.detalle', 'rh.vacaciones.aprobar', 'rh.vacaciones.rechazar',
            'notificaciones.leer_todas',
            'solicitudes.configuracion.ver', 'solicitudes.adjuntos.subir',
            'solicitudes.bajas.crear', 'headcount.ver', 'organigrama.ver',
            'ciclo.preautorizar', 'candidatos.evaluar', 'onboarding.ver', 'onboarding.entregar_activos', 'cierres.ver', 'cierres.solicitar', 'contratos.ver', 'evaluaciones.ver', 'evaluaciones.capturar', 'documentos_laborales.ver', 'documentos_laborales.operar_fisico', 'reingresos.solicitar',
            // Solicitud de baja de su personal + tablero de su alcance.
            'bajas.ver', 'bajas.solicitar', 'bajas.tablero.ver',
            // Contratos: el gerente solo SUBE el firmado de su gente (no ve,
            // descarga ni genera el contrato; ContratoFirmadoService).
            'contratos.subir_firmado',
            // Única autoridad que decide una intervención de Gestor/Volante
            // (CLAUDE.md §11-A) — la de SU región, nunca otra.
            'candidatos.intervencion_decidir',
            // Responsivas de equipo para su región (docs/responsiva).
            'responsivas.ver', 'responsivas.generar',
            // Cartas compromiso de su región (matriz comercial); «descargar»
            // es solo el PDF de la carta para imprimirla y firmarla.
            'cartas_compromiso.ver', 'cartas_compromiso.generar', 'cartas_compromiso.subir_firmada', 'cartas_compromiso.descargar',
        ],

        // Administra su propia sucursal (mismo alcance que gerente_sucursal,
        // catálogo de permisos equivalente). "gerente_sucursal" se conserva
        // intacto por compatibilidad con datos/roles ya asignados.
        'gerente' => [
            'directorio.contactos.ver_todos',
            'perfiles_puesto.ver',
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
            'agenda.ver', 'agenda.ver_sucursal', 'agenda.agendar', 'agenda.alta_candidato', 'agenda.registrar_resultado', 'agenda.ver_datos_candidato',
            'mobile.bootstrap.ver', 'app.config.ver',
            'dispositivos.push_token.registrar', 'dispositivos.push_token.revocar',
            'rh.mobile.dashboard.ver', 'rh.pendientes.ver',
            'rh.solicitudes.ver', 'rh.solicitudes.detalle', 'rh.solicitudes.aprobar', 'rh.solicitudes.rechazar', 'rh.solicitudes.correccion',
            'rh.vacaciones.ver', 'rh.vacaciones.detalle', 'rh.vacaciones.aprobar', 'rh.vacaciones.rechazar',
            'notificaciones.leer_todas',
            'solicitudes.configuracion.ver', 'solicitudes.adjuntos.subir',
            'solicitudes.bajas.crear', 'headcount.ver', 'organigrama.ver',
            'ciclo.preautorizar', 'candidatos.evaluar', 'onboarding.ver', 'onboarding.entregar_activos', 'cierres.ver', 'cierres.solicitar', 'contratos.ver', 'evaluaciones.ver', 'evaluaciones.capturar', 'documentos_laborales.ver', 'documentos_laborales.operar_fisico', 'reingresos.solicitar',
            // Solicitud de baja de su personal + tablero de su alcance.
            'bajas.ver', 'bajas.solicitar', 'bajas.tablero.ver',
            // Contratos: el gerente solo SUBE el firmado de su gente (no ve,
            // descarga ni genera el contrato; ContratoFirmadoService).
            'contratos.subir_firmado',
            // Actas de hechos de su gente: levantarla, verla y GENERAR su
            // formato (PDF). El finiquito sigue siendo exclusivo de RH
            // (finiquitos.*): el gerente nunca lo calcula ni lo genera.
            'actas.ver', 'actas.crear', 'actas.descargar',
            // Responsivas de equipo para su equipo y su subgerente.
            'responsivas.ver', 'responsivas.generar',
            // Cartas compromiso de su gente (igual que gerente_sucursal).
            'cartas_compromiso.ver', 'cartas_compromiso.generar', 'cartas_compromiso.subir_firmada', 'cartas_compromiso.descargar',
        ],

        // Coordinadoras (de sucursal y regional) y subgerente: usan PEOPLE
        // como COLABORADORES, no como operadores de RH (decisión del negocio,
        // 2026-10-15) — mismo catálogo que `colaborador`, todo sobre lo
        // propio. Sin alcance en config/permisos_modulos.php ni en
        // AlcanceOrganizacionalService/MobileBootstrapService.
        'coordinadora_regional' => self::PERMISOS_ROL_COLABORADOR,
        'coordinadora' => self::PERMISOS_ROL_COLABORADOR,
        // El subgerente deja de apoyar/cubrir operativamente al gerente: usa
        // PEOPLE como colaborador, igual que las coordinadoras (misma decisión
        // del negocio, 2026-10-15).
        // La agenda es la excepción: ve las citas de su sucursal en la app, pero
        // capturar resultados o agendar requiere que RH le asigne el permiso.
        'subgerente' => [...self::PERMISOS_ROL_COLABORADOR, 'agenda.ver', 'agenda.ver_sucursal'],

        // Ve y aprueba vacaciones/solicitudes de sus subordinados directos
        // (jefe_id), un alcance mas estrecho que gerente_sucursal (que ve
        // toda la sucursal). Ver AlcanceOrganizacionalService.
        'jefe_directo' => [
            'perfiles_puesto.ver',
            'dashboard.sucursal.ver',
            // Alcance «equipo» (sus subordinados directos): expedientes.ver_equipo
            // lo agrega config/permisos_modulos.php → alcance_por_rol. Antes
            // se usaba ver_sucursal como truco; la migración
            // 2026_10_15_091000 lo corrige en bases existentes.
            'expedientes.ver',
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
            // Ciclo laboral completo (docs/backend-rh-completion.md).
            'contratos.ver', 'evaluaciones.ver', 'evaluaciones.capturar', 'prestamos.ver',
            // «Bajas» es solo de gerentes, RH y super admin (2026-10-20): el
            // jefe directo ya no registra ni ve bajas (migración
            // 2026_10_20_250000_bajas_directas_sin_borrador).
            'ciclo.preautorizar', 'onboarding.ver', 'cierres.ver',
        ],

        'colaborador' => self::PERMISOS_ROL_COLABORADOR,
        'auditor' => [
            // Alcance amplio: elige de qué sucursales no recibir avisos.
            'notificaciones.silenciar_sucursales',
            'directorio.contactos.ver_todos',
            'perfiles_puesto.ver',
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
            'reportes_rh.ver', 'reportes_rh.globales', 'reportes_rh.sucursal', 'rotacion.ver',
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
            // Ciclo laboral completo (docs/backend-rh-completion.md), solo
            // lectura. Mínimo privilegio (2026-10-15): SIN recibos de nómina,
            // préstamos ni actas — datos salariales/financieros y
            // disciplinarios que su función documentada (empresas,
            // expedientes, documentos, vacaciones, solicitudes, reportes RH,
            // reclutamiento) no requiere. La migración
            // 2026_10_15_180100_auditor_minimo_privilegio los revoca en bases
            // existentes.
            'contratos.ver', 'evaluaciones.ver', 'cierres.ver',
            // Contratos: conserva la consulta del PDF del contrato (antes con
            // documentos_laborales.ver; ahora es acción propia del módulo).
            'contratos.ver_documento', 'contratos.descargar',
            'documentos_laborales.ver', 'plantillas_documentales.ver', 'indicadores.ver',
            'onboarding.ver',
            // Sin «Bajas» (solo gerentes, RH y super admin); conserva el tablero.
            'bajas.tablero.ver',
        ],

        // Dirección: autorizaciones finales (evaluaciones, bajas) e
        // indicadores globales; no opera la captura diaria de RH. El
        // PRÉSTAMO lo autoriza SOLO RH (prestamos.autorizar): Dirección
        // consulta (2026-10-20, migración 2026_10_20_170200).
        'direccion' => [
            'salarios.ver', 'salarios.exportar', 'salarios.ver_en_expediente',
            // Alcance amplio: elige de qué sucursales no recibir avisos.
            'notificaciones.silenciar_sucursales',
            'directorio.contactos.ver_todos',
            'perfiles_puesto.ver',
            'dashboard.global.ver',
            'reportes_rh.ver', 'reportes_rh.exportar', 'reportes_rh.globales', 'reportes_rh.sucursal', 'rotacion.ver',
            'indicadores.ver', 'headcount.ver', 'organigrama.ver', 'auditoria.ver',
            'expedientes.ver', 'expedientes.ver_todos', 'documentos.ver', 'documentos.descargar',
            'vacantes.ver', 'vacantes.ver_todos', 'candidatos.ver', 'candidatos.ver_todos',
            'agenda.ver', 'agenda.ver_todos', 'agenda.exportar',
            'solicitudes.ver', 'solicitudes.aprobar', 'solicitudes.bajas.aprobar',
            'rh.solicitudes.ver', 'rh.solicitudes.detalle', 'rh.solicitudes.aprobar', 'rh.solicitudes.rechazar',
            'contratos.ver', 'evaluaciones.ver', 'evaluaciones.autorizar', 'excepciones.autorizar',
            // Contratos: conserva la consulta del PDF del contrato (antes con
            // documentos_laborales.ver; ahora es acción propia del módulo).
            'contratos.ver_documento', 'contratos.descargar',
            'prestamos.ver',
            'cierres.ver', 'finiquitos.ver', 'finiquitos.revisar',
            // Dirección General: tablero de rotación global y por sucursal.
            'bajas.tablero.ver',
            'documentos_laborales.ver', 'actas.ver', 'actas.descargar', 'nomina.recibos.ver',
            'mobile.bootstrap.ver', 'app.config.ver',
            'dispositivos.push_token.registrar', 'dispositivos.push_token.revocar',
            'notificaciones.leer_todas',
        ],

        // Jurídico: consulta la documentación jurídica y administra las
        // plantillas (textos legales) del motor documental.
        'juridico' => [
            // Alcance amplio: elige de qué sucursales no recibir avisos.
            'notificaciones.silenciar_sucursales',
            'expedientes.ver', 'expedientes.ver_todos', 'documentos.ver', 'documentos.descargar',
            'plantillas_documentales.ver', 'plantillas_documentales.administrar',
            'documentos_laborales.ver', 'contratos.ver', 'actas.ver', 'actas.descargar', 'cierres.ver', 'finiquitos.ver',
            // Contratos: conserva la consulta del PDF del contrato (antes con
            // documentos_laborales.ver; ahora es acción propia del módulo).
            'contratos.ver_documento', 'contratos.descargar',
            // Revisa y aprueba las bajas que requieren Jurídico (RN-06) y
            // consulta las rescisiones; no cierra.
            'bajas.revisar_juridico',
            'formatos_oficiales.ver', 'formatos_oficiales.descargar',
            'mobile.bootstrap.ver', 'app.config.ver',
            'dispositivos.push_token.registrar', 'dispositivos.push_token.revocar',
            'notificaciones.leer_todas',
        ],

        // Contraloría: recibe los avisos de RE-02 (faltantes en caja o
        // cartera) y RE-03 (falta de probidad, robo o fraude) y consulta esas
        // bajas, solo lectura (docs/BAJAS_Y_CAUSALES.md).
        'contraloria' => [
            // Alcance amplio: elige de qué sucursales no recibir avisos.
            'notificaciones.silenciar_sucursales',
            'bajas.consulta_contraloria',
            'mobile.bootstrap.ver', 'app.config.ver',
            'dispositivos.push_token.registrar', 'dispositivos.push_token.revocar',
            'notificaciones.leer_todas',
        ],

        // Sistemas: administra la plataforma (cuentas, roles, releases de la
        // app, auditoría) y tiene LECTURA GLOBAL para soporte y diagnóstico
        // (alcance «todos» en cada módulo que puede ver). Ver todo ≠ aprobar
        // todo: no recibe ninguna acción de decisión de RH (aprobar
        // vacaciones/candidatos/préstamos, autorizar RH, ejecutar bajas) ni
        // datos médicos. Sin dashboard.global.ver no tenía modo operativo.
        'sistemas' => [
            // Alcance amplio: elige de qué sucursales no recibir avisos.
            'notificaciones.silenciar_sucursales',
            'directorio.contactos.ver_todos',
            'dashboard.global.ver',
            'usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'usuarios.desactivar', 'usuarios.reactivar',
            'roles.administrar', 'auditoria.ver',
            'app_releases.ver', 'app_releases.crear', 'app_releases.publicar', 'app_releases.eliminar', 'app_releases.descargar',
            'mobile.bootstrap.ver', 'app.config.ver',
            'dispositivos.push_token.registrar', 'dispositivos.push_token.revocar',
            'notificaciones.leer_todas',
            'configuracion.ver',
            // Lectura de los módulos PEOPLE/RH (el alcance «todos» se agrega
            // desde config/permisos_modulos.php → alcance_por_rol).
            'expedientes.ver', 'documentos.ver', 'solicitudes.ver', 'vacaciones.ver', 'prestamos.ver', 'actas.ver', 'actas.descargar',
            'documentos_laborales.ver', 'contratos.ver', 'evaluaciones.ver', 'candidatos.ver', 'vacantes.ver',
            // Contratos: conserva la consulta del PDF del contrato (antes con
            // documentos_laborales.ver; ahora es acción propia del módulo).
            'contratos.ver_documento', 'contratos.descargar',
            'nomina.recibos.ver', 'finiquitos.ver', 'cierres.ver', 'onboarding.ver', 'altas.ver',
            'empresas.ver', 'headcount.ver', 'organigrama.ver', 'perfiles_puesto.ver', 'indicadores.ver',
            'reportes_rh.ver', 'reportes_rh.globales', 'rotacion.ver', 'reclutamiento.campanas.ver', 'celebraciones.ver',
            // Sin «Bajas» (solo gerentes, RH y super admin): Sistemas recibe el
            // aviso «Baja registrada» y entra por el expediente / ciclo laboral.
            'bajas.tablero.ver',
            'formatos.ver', 'formatos.preview', 'formatos_oficiales.ver', 'plantillas.ver', 'plantillas_documentales.ver',
            // Consulta (sin acciones) también desde la app.
            'rh.mobile.dashboard.ver', 'rh.pendientes.ver',
            'rh.expedientes.ver', 'rh.expedientes.detalle', 'rh.expedientes.documentos.ver',
            'rh.solicitudes.ver', 'rh.solicitudes.detalle', 'rh.vacaciones.ver', 'rh.vacaciones.detalle',
            'rh.documentos.ver', 'rh.documentos.detalle', 'rh.incorporaciones.ver', 'rh.incorporaciones.detalle',
            'rh.colaboradores.ver', 'rh.colaboradores.detalle', 'rh.cumpleanos.ver', 'rh.cumpleanos.calendario',
            'avisos.ver',
        ],
    ];

    /**
     * Quién consulta TODAS las notificaciones (solo lectura) por defecto.
     * El resto solo las propias. Editable desde Roles y permisos.
     *
     * @var list<string>
     */
    private const ROLES_VEN_TODAS_LAS_NOTIFICACIONES = ['super_admin', 'rh_admin', 'sistemas'];

    /** @var array<string, true>|null */
    private static ?array $operativos = null;

    /**
     * Permisos de alcance y de acceso que define config/permisos_modulos.php
     * (los nuevos se crean con people:sincronizar-permisos).
     *
     * @return list<string>
     */
    public static function permisosDeModulos(): array
    {
        $permisos = [];

        foreach ((array) config('permisos_modulos.modulos', []) as $modulo) {
            if (! is_array($modulo)) {
                continue;
            }

            $permisos[] = (string) ($modulo['acceso'] ?? '');

            foreach ((array) ($modulo['alcances'] ?? []) as $permiso) {
                $permisos[] = (string) $permiso;
            }

            foreach (array_keys((array) ($modulo['acciones'] ?? [])) as $permiso) {
                $permisos[] = (string) $permiso;
            }
        }

        // Permisos nuevos que se separaron de uno general (herencia).
        foreach (array_keys(self::herencia()) as $permiso) {
            $permisos[] = $permiso;
        }

        return array_values(array_unique(array_filter($permisos, fn (string $p): bool => $p !== '')));
    }

    /**
     * config/permisos_modulos.php → herencia: permiso nuevo => permiso(s)
     * general(es) del que se separó. Una lista = lo hereda quien tenía
     * cualquiera de ellos.
     *
     * @return array<string, list<string>>
     */
    public static function herencia(): array
    {
        $herencia = [];

        foreach ((array) config('permisos_modulos.herencia', []) as $nuevo => $existentes) {
            $padres = array_values(array_filter(
                array_map(fn ($p): string => is_string($p) ? $p : '', is_array($existentes) ? $existentes : [$existentes]),
                fn (string $p): bool => $p !== '' && $p !== $nuevo,
            ));

            if (is_string($nuevo) && $nuevo !== '' && $padres !== []) {
                $herencia[$nuevo] = $padres;
            }
        }

        return $herencia;
    }

    /**
     * config/permisos_modulos.php → retirados: permisos generales que ya no
     * habilitan nada por sí solos (la pantalla de roles no los ofrece).
     *
     * @return list<string>
     */
    public static function retirados(): array
    {
        return array_values(array_filter(array_map('strval', array_keys((array) config('permisos_modulos.retirados', [])))));
    }

    /**
     * true si la habilidad es un permiso del catálogo OPERATIVO (no los
     * personales de "Mi espacio"). Lo usa el Gate::before de super_admin
     * (AppServiceProvider) para no depender de que su rol en producción
     * tenga sincronizado cada permiso nuevo.
     */
    /**
     * Catálogo operativo completo (sin los personales de "Mi espacio").
     *
     * @return list<string>
     */
    public static function permisosOperativos(): array
    {
        return array_values(array_unique([...self::PERMISOS, ...self::permisosDeModulos()]));
    }

    public static function esPermisoOperativo(string $habilidad): bool
    {
        self::$operativos ??= array_fill_keys([...self::PERMISOS, ...self::permisosDeModulos()], true);

        return isset(self::$operativos[$habilidad]) && ! in_array($habilidad, self::PERMISOS_PERSONALES, true);
    }

    /**
     * Permisos de alcance que corresponden a un rol según
     * config/permisos_modulos.php → alcance_por_rol, solo en los módulos
     * a los que el rol tiene acceso. Notificaciones queda fuera: ver todas
     * es explícito (ROLES_VEN_TODAS_LAS_NOTIFICACIONES).
     *
     * @param  list<string>  $permisosDelRol
     * @return list<string>
     */
    private static function alcancesPara(string $rol, array $permisosDelRol): array
    {
        $nivel = config("permisos_modulos.alcance_por_rol.{$rol}");

        if (! is_string($nivel)) {
            return [];
        }

        $orden = ['todos', 'region', 'sucursal', 'equipo'];
        $desde = array_search($nivel, $orden, true);
        $resultado = [];

        foreach ((array) config('permisos_modulos.modulos', []) as $clave => $modulo) {
            // Un módulo sin permiso de acceso (p. ej. Directorio: lo usa todo
            // el personal) recibe igual el alcance por defecto del rol.
            if ($clave === 'notificaciones' || ! is_array($modulo) || (($modulo['acceso'] ?? null) !== null && ! in_array($modulo['acceso'], $permisosDelRol, true))) {
                continue;
            }

            // El nivel del rol, o el más cercano por debajo que el módulo
            // tenga (vacantes/candidatos no tienen "equipo").
            foreach (array_slice($orden, $desde === false ? count($orden) : $desde) as $candidato) {
                $permiso = $modulo['alcances'][$candidato] ?? null;

                if (is_string($permiso)) {
                    $resultado[] = $permiso;

                    break;
                }
            }
        }

        return $resultado;
    }

    /**
     * Catálogo completo de permisos (operativos + personales).
     *
     * @return list<string>
     */
    public static function catalogoPermisos(): array
    {
        return array_values(array_unique([...self::PERMISOS, ...self::permisosDeModulos(), ...self::PERMISOS_PERSONALES]));
    }

    /**
     * Permisos personales de «Mi espacio» (portal.*): la pantalla «Roles y
     * permisos» los presenta en su propia etapa.
     *
     * @return list<string>
     */
    public static function permisosPersonales(): array
    {
        return self::PERMISOS_PERSONALES;
    }

    /**
     * Permisos BASE de cada rol sembrado — lo mínimo que el rol debe tener,
     * no un techo: un rol editado en "Roles y permisos" puede tener más.
     *
     * @return array<string, list<string>>
     */
    public static function permisosBasePorRol(): array
    {
        $roles = self::ROLES;
        // super_admin: todo el catálogo operativo, incluidos los permisos
        // de alcance/acción que define config/permisos_modulos.php.
        $roles['super_admin'] = [...self::PERMISOS, ...self::permisosDeModulos()];

        $resultado = [];

        foreach ($roles as $rol => $permisos) {
            // Todo rol que usa la campana conserva "ver mis notificaciones".
            if (in_array('notificaciones.leer_todas', $permisos, true)) {
                $permisos[] = 'notificaciones.ver_propias';
            }

            if (in_array($rol, self::ROLES_VEN_TODAS_LAS_NOTIFICACIONES, true)) {
                $permisos[] = 'notificaciones.ver_propias';
                $permisos[] = 'notificaciones.ver_todas';
            }

            // Un permiso que se separó de uno general lo tiene por defecto
            // quien tenía el general (mismo comportamiento que antes).
            // (Hasta estabilizar: una acción puede heredar de otra heredada.)
            do {
                $antes = count($permisos);

                foreach (self::herencia() as $nuevo => $existentes) {
                    if (array_intersect($existentes, $permisos) !== [] && ! in_array($nuevo, $permisos, true)) {
                        $permisos[] = $nuevo;
                    }
                }
            } while (count($permisos) !== $antes);

            $resultado[$rol] = array_values(array_unique([...$permisos, ...self::alcancesPara($rol, $permisos)]));
        }

        return $resultado;
    }

    /**
     * Idempotente y NO destructivo (seguro en producción con `db:seed
     * --force`): crea permisos/roles faltantes y agrega solo los permisos
     * base que le falten a cada rol; nunca quita permisos que un
     * administrador haya agregado desde la pantalla de roles. Misma lógica
     * que `php artisan people:sincronizar-permisos` (ver docs/DEPLOY.md).
     */
    public function run(): void
    {
        app(SincronizadorPermisosService::class)->sincronizar();
    }
}
