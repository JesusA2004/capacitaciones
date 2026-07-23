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
    ];

    /**
     * Mapa inicial rol => permisos. Editable despues desde la pantalla de
     * administracion de roles; esto es solo el punto de partida razonable.
     *
     * @var array<string, array<int, string>>
     */
    private const ROLES = [
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
        ],

        'gerente_sucursal' => [
            'dashboard.sucursal.ver',
            'usuarios.ver', 'usuarios.editar',
            'asignaciones.ver',
            'asistencias.ver',
            'reportes.sucursal', 'reportes.exportar',
            'expedientes.ver', 'expedientes.ver_sucursal',
            'documentos.ver',
            'vacaciones.ver', 'vacaciones.solicitar', 'vacaciones.aprobar',
            'solicitudes.ver', 'solicitudes.revisar', 'solicitudes.aprobar',
            'reportes_rh.ver', 'reportes_rh.sucursal',
        ],
        'supervisor' => [
            'dashboard.sucursal.ver',
            'usuarios.ver',
            'asistencias.ver',
            'reportes.sucursal',
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
            'vacaciones.ver', 'vacaciones.solicitar', 'vacaciones.aprobar',
            'solicitudes.ver', 'solicitudes.revisar', 'solicitudes.aprobar',
        ],

        'colaborador' => [
            'expedientes.ver',
            'documentos.ver', 'documentos.subir', 'documentos.descargar',
            'vacaciones.ver', 'vacaciones.solicitar',
            'solicitudes.ver', 'solicitudes.crear',
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
        ],
    ];

    public function run(): void
    {
        foreach (self::PERMISOS as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        foreach (self::ROLES as $rol => $permisos) {
            $role = Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web']);
            $role->syncPermissions($permisos);
        }
    }
}
