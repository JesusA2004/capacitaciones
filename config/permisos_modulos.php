<?php

/*
|--------------------------------------------------------------------------
| Permisos por módulo: acceso + alcance de datos + acciones
|--------------------------------------------------------------------------
|
| Modelo que presenta «Roles y permisos» en vez de cientos de casillas:
|
|   Módulo       Acceso [ON]   Alcance [Propios ▼]   Acciones [Crear] [Aprobar]…
|
| - acceso: el permiso que deja entrar/ver el módulo.
| - alcances: el permiso que amplía QUÉ registros ve, de mayor a menor
|   (todos > region > sucursal > equipo). Sin ninguno = Propios. Se
|   REUTILIZAN los que ya existían (expedientes.ver_todos,
|   vacantes.ver_sucursal…); los nuevos siguen el mismo patrón.
| - acciones: permisos de acción del módulo. Ver todo ≠ aprobar todo:
|   el alcance nunca otorga una acción.
|
| App\Services\Permisos\AlcanceModuloService lo aplica en el backend; los
| permisos de alcance se agregan al catálogo (RolesYPermisosSeeder) y se
| crean en producción con `php artisan people:sincronizar-permisos`.
|
| Catálogos globales (puestos, empresas, configuración) no tienen alcance.
| En módulos que se acotan por sucursal (vacantes, candidatos) Propios y
| Equipo equivalen a "su sucursal".
*/

return [

    'modulos' => [
        'expedientes' => [
            'etiqueta' => 'Expedientes',
            'grupo' => 'People',
            'acceso' => 'expedientes.ver',
            'alcances' => ['todos' => 'expedientes.ver_todos', 'region' => 'expedientes.ver_region', 'sucursal' => 'expedientes.ver_sucursal', 'equipo' => 'expedientes.ver_equipo'],
            'acciones' => ['expedientes.crear' => 'Crear', 'expedientes.editar' => 'Editar', 'expedientes.revisar' => 'Revisar', 'expedientes.eliminar' => 'Eliminar'],
        ],
        'solicitudes' => [
            'etiqueta' => 'Solicitudes',
            'grupo' => 'People',
            'acceso' => 'solicitudes.ver',
            'alcances' => ['todos' => 'solicitudes.ver_todos', 'region' => 'solicitudes.ver_region', 'sucursal' => 'solicitudes.ver_sucursal', 'equipo' => 'solicitudes.ver_equipo'],
            'acciones' => ['solicitudes.crear' => 'Crear', 'solicitudes.revisar' => 'Revisar', 'solicitudes.aprobar' => 'Aprobar', 'solicitudes.rechazar' => 'Rechazar'],
        ],
        'vacaciones' => [
            'etiqueta' => 'Vacaciones',
            'grupo' => 'People',
            'acceso' => 'vacaciones.ver',
            'alcances' => ['todos' => 'vacaciones.ver_todos', 'region' => 'vacaciones.ver_region', 'sucursal' => 'vacaciones.ver_sucursal', 'equipo' => 'vacaciones.ver_equipo'],
            'acciones' => ['vacaciones.solicitar' => 'Solicitar', 'vacaciones.aprobar' => 'Aprobar', 'vacaciones.rechazar' => 'Rechazar', 'vacaciones.ajustar' => 'Ajustar saldos'],
        ],
        'prestamos' => [
            'etiqueta' => 'Préstamos',
            'grupo' => 'People',
            'acceso' => 'prestamos.ver',
            'alcances' => ['todos' => 'prestamos.ver_todos', 'region' => 'prestamos.ver_region', 'sucursal' => 'prestamos.ver_sucursal', 'equipo' => 'prestamos.ver_equipo'],
            'acciones' => ['prestamos.autorizar' => 'Autorizar', 'prestamos.resguardar' => 'Resguardar pagaré'],
        ],
        'actas' => [
            'etiqueta' => 'Actas administrativas',
            'grupo' => 'People',
            'acceso' => 'actas.ver',
            'alcances' => ['todos' => 'actas.ver_todos', 'region' => 'actas.ver_region', 'sucursal' => 'actas.ver_sucursal', 'equipo' => 'actas.ver_equipo'],
            'acciones' => ['actas.crear' => 'Crear', 'actas.gestionar' => 'Gestionar'],
        ],
        // Responsivas de equipo (docs/responsiva): el gerente las genera para
        // su equipo y RH para cualquiera; el colaborador no las ve aquí.
        'responsivas' => [
            'etiqueta' => 'Responsivas de equipo',
            'grupo' => 'People',
            'acceso' => 'responsivas.ver',
            'alcances' => ['todos' => 'responsivas.ver_todos', 'region' => 'responsivas.ver_region', 'sucursal' => 'responsivas.ver_sucursal', 'equipo' => 'responsivas.ver_equipo'],
            'acciones' => ['responsivas.generar' => 'Generar / marcar firmada físicamente', 'responsivas.cancelar' => 'Cancelar'],
        ],
        'documentos' => [
            'etiqueta' => 'Contratos y documentos',
            'grupo' => 'People',
            'acceso' => 'documentos_laborales.ver',
            'alcances' => ['todos' => 'documentos_laborales.ver_todos', 'region' => 'documentos_laborales.ver_region', 'sucursal' => 'documentos_laborales.ver_sucursal', 'equipo' => 'documentos_laborales.ver_equipo'],
            'acciones' => ['documentos_laborales.generar' => 'Generar', 'documentos_laborales.operar_fisico' => 'Imprimir / firmar / archivar', 'documentos_laborales.cancelar' => 'Cancelar'],
        ],
        'contratos' => [
            'etiqueta' => 'Contratos',
            'grupo' => 'People',
            'acceso' => 'contratos.ver',
            'alcances' => ['todos' => 'contratos.ver_todos', 'region' => 'contratos.ver_region', 'sucursal' => 'contratos.ver_sucursal', 'equipo' => 'contratos.ver_equipo'],
            'acciones' => [],
        ],
        'evaluaciones' => [
            'etiqueta' => 'Evaluaciones (periodo de prueba)',
            'grupo' => 'People',
            'acceso' => 'evaluaciones.ver',
            'alcances' => ['todos' => 'evaluaciones.ver_todos', 'region' => 'evaluaciones.ver_region', 'sucursal' => 'evaluaciones.ver_sucursal', 'equipo' => 'evaluaciones.ver_equipo'],
            'acciones' => ['evaluaciones.capturar' => 'Capturar', 'evaluaciones.autorizar' => 'Autorizar'],
        ],
        'candidatos' => [
            'etiqueta' => 'Candidatos',
            'grupo' => 'Reclutamiento',
            'acceso' => 'candidatos.ver',
            'alcances' => ['todos' => 'candidatos.ver_todos', 'region' => 'candidatos.ver_region', 'sucursal' => 'candidatos.ver_sucursal'],
            'acciones' => ['candidatos.crear' => 'Crear', 'candidatos.editar' => 'Editar', 'candidatos.evaluar' => 'Evaluar', 'candidatos.aprobar' => 'Aprobar', 'candidatos.rechazar' => 'Rechazar', 'candidatos.contratar' => 'Contratar'],
        ],
        'vacantes' => [
            'etiqueta' => 'Vacantes',
            'grupo' => 'Reclutamiento',
            'acceso' => 'vacantes.ver',
            'alcances' => ['todos' => 'vacantes.ver_todos', 'region' => 'vacantes.ver_region', 'sucursal' => 'vacantes.ver_sucursal'],
            'acciones' => ['vacantes.crear' => 'Crear', 'vacantes.editar' => 'Editar', 'vacantes.cerrar' => 'Cerrar', 'vacantes.eliminar' => 'Eliminar'],
        ],
        'recibos' => [
            'etiqueta' => 'Recibos de nómina',
            'grupo' => 'Nómina',
            'acceso' => 'nomina.recibos.ver',
            'alcances' => ['todos' => 'nomina.recibos.ver_todos', 'region' => 'nomina.recibos.ver_region', 'sucursal' => 'nomina.recibos.ver_sucursal', 'equipo' => 'nomina.recibos.ver_equipo'],
            'acciones' => ['nomina.recibos.crear' => 'Crear / emitir', 'nomina.recibos.importar' => 'Importar'],
        ],
        'finiquitos' => [
            'etiqueta' => 'Finiquitos',
            'grupo' => 'Nómina',
            'acceso' => 'finiquitos.ver',
            'alcances' => ['todos' => 'finiquitos.ver_todos', 'region' => 'finiquitos.ver_region', 'sucursal' => 'finiquitos.ver_sucursal', 'equipo' => 'finiquitos.ver_equipo'],
            'acciones' => ['finiquitos.calcular' => 'Calcular', 'finiquitos.revisar' => 'Revisar', 'finiquitos.subir_firmado' => 'Subir firmado', 'finiquitos.confirmar_pago' => 'Confirmar pago', 'finiquitos.causales_rotacion' => 'Administrar causales de rotación'],
        ],
        'cierres' => [
            'etiqueta' => 'Bajas (cierres laborales)',
            'grupo' => 'People',
            'acceso' => 'cierres.ver',
            'alcances' => ['todos' => 'cierres.ver_todos', 'region' => 'cierres.ver_region', 'sucursal' => 'cierres.ver_sucursal', 'equipo' => 'cierres.ver_equipo'],
            'acciones' => ['cierres.solicitar' => 'Solicitar baja', 'cierres.gestionar' => 'Gestionar', 'cierres.ejecutar_baja' => 'Ejecutar baja', 'cierres.programar_pago' => 'Programar pago'],
        ],
        'incorporaciones' => [
            'etiqueta' => 'Incorporaciones',
            'grupo' => 'People',
            'acceso' => 'onboarding.ver',
            'alcances' => ['todos' => 'onboarding.ver_todos', 'region' => 'onboarding.ver_region', 'sucursal' => 'onboarding.ver_sucursal', 'equipo' => 'onboarding.ver_equipo'],
            'acciones' => ['onboarding.gestionar' => 'Gestionar', 'onboarding.entregar_activos' => 'Entregar activos'],
        ],
        // Módulo de Bajas y Causales (docs/BAJAS_Y_CAUSALES.md): la bandeja y
        // el expediente de la baja se acotan por la persona (colaborador_id).
        'bajas' => [
            'etiqueta' => 'Bajas (registro y expediente)',
            'grupo' => 'People',
            'acceso' => 'bajas.ver',
            'alcances' => ['todos' => 'bajas.ver_todos', 'region' => 'bajas.ver_region', 'sucursal' => 'bajas.ver_sucursal', 'equipo' => 'bajas.ver_equipo'],
            'acciones' => [
                'bajas.solicitar' => 'Registrar baja (se aplica de inmediato)',
                'bajas.gestionar' => 'Cambiar causal, corregir datos, cerrar, reabrir y «No procede» (RH)',
                'bajas.revisar_juridico' => 'Revisión de Jurídico',
                'bajas.consulta_contraloria' => 'Consulta de Contraloría (RE-02 / RE-03)',
                'bajas.tablero.ver' => 'Tablero de rotación',
            ],
        ],
        // Rotación de personal cargada desde el Excel de RH (Reportes →
        // Rotación de personal). Se acota por sucursal.
        'rotacion' => [
            'etiqueta' => 'Rotación de personal',
            'grupo' => 'People',
            'acceso' => 'rotacion.ver',
            'alcances' => ['todos' => 'rotacion.ver_todos', 'region' => 'rotacion.ver_region', 'sucursal' => 'rotacion.ver_sucursal'],
            'acciones' => ['rotacion.cargar' => 'Cargar Excel'],
        ],
        'notificaciones' => [
            'etiqueta' => 'Notificaciones',
            'grupo' => 'Sistema',
            'acceso' => 'notificaciones.ver_propias',
            // Solo dos niveles: las propias (campana) o todas (consulta
            // administrativa de solo lectura). Ver ≠ recibir avisos.
            'alcances' => ['todos' => 'notificaciones.ver_todas'],
            'acciones' => ['notificaciones.leer_todas' => 'Marcar todas MIS notificaciones como leídas'],
        ],
    ],

    /*
    | Alcance por defecto de cada rol sembrado sobre los módulos a los que
    | tiene acceso (RolesYPermisosSeeder lo convierte en permisos de alcance).
    | Equivale al alcance que ya tenían por rol en AlcanceOrganizacionalService.
    */
    'alcance_por_rol' => [
        'super_admin' => 'todos', 'administrador_capacitacion' => 'todos', 'auditor' => 'todos',
        'rh_admin' => 'todos', 'rh_auxiliar' => 'todos', 'director_comercial' => 'todos',
        'direccion' => 'todos', 'juridico' => 'todos', 'sistemas' => 'todos', 'contraloria' => 'todos',
        'gerente_regional' => 'region',
        'gerente_sucursal' => 'sucursal', 'gerente' => 'sucursal',
        'supervisor' => 'sucursal',
        'jefe_directo' => 'equipo',
        // coordinadora / coordinadora_regional / subgerente: sin alcance
        // (Propios) — usan PEOPLE como colaboradores (decisión del negocio
        // 2026-10-15).
    ],

];
