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
        // Tarjetas de la pantalla «Roles y permisos» para permisos que ya
        // existían sin módulo (solo agrupan; sin alcances, no cambian nada).
        'headcount' => [
            'etiqueta' => 'Headcount y plantilla autorizada',
            'grupo' => 'Organización',
            'etapa' => 'organizacion',
            'acceso' => 'headcount.ver',
            'alcances' => [],
            'acciones' => ['headcount.editar' => 'Editar plantilla autorizada'],
        ],
        'indicadores' => [
            'etiqueta' => 'Indicadores',
            'grupo' => 'Reportes',
            'etapa' => 'reportes',
            'acceso' => 'indicadores.ver',
            'alcances' => [],
            'acciones' => [],
        ],
        'formatos' => [
            'etiqueta' => 'Formatos y plantillas',
            'grupo' => 'Contratación',
            'etapa' => 'contratacion',
            'acceso' => null,
            'alcances' => [],
            'acciones' => [
                'formatos.ver' => 'Ver catálogo de formatos',
                'formatos.preview' => 'Vista previa de formatos',
                'formatos.descargar_pdf' => 'Descargar formato en PDF',
                'formatos.descargar_docx' => 'Descargar formato en Word',
                'formatos_oficiales.ver' => 'Ver formatos oficiales',
                'formatos_oficiales.generar' => 'Generar formato oficial',
                'formatos_oficiales.descargar' => 'Descargar formato oficial',
                'formatos_oficiales.configurar' => 'Configurar campos del formato oficial',
                'formatos_oficiales.crear' => 'Subir formato oficial',
                'formatos_oficiales.versionar' => 'Versionar / publicar formato oficial',
                'formatos_oficiales.archivar' => 'Archivar formato oficial',
                'formatos_oficiales.datos_salariales' => 'Incluir datos salariales',
                'plantillas.ver' => 'Ver plantillas',
                'plantillas.crear' => 'Crear plantillas',
                'plantillas.editar' => 'Editar plantillas',
                'plantillas.eliminar' => 'Eliminar plantillas',
                'plantillas.generar' => 'Generar desde plantilla',
            ],
        ],
        'capacitacion' => [
            'etiqueta' => 'Capacitación continua',
            'grupo' => 'Desarrollo',
            'etapa' => 'desarrollo',
            'descripcion' => 'Oculta tras el feature flag «capacitacion»: estos permisos aplican cuando se encienda.',
            'acceso' => 'cursos.ver',
            'alcances' => [],
            'acciones' => [
                'cursos.crear' => 'Crear cursos',
                'cursos.editar' => 'Editar cursos',
                'cursos.publicar' => 'Publicar cursos',
                'cursos.eliminar' => 'Eliminar cursos',
                'multimedia.administrar' => 'Administrar multimedia',
                'cuestionarios.administrar' => 'Administrar cuestionarios',
                'respuestas.ver' => 'Ver respuestas',
                'respuestas.calificar' => 'Calificar respuestas',
                'actividades.administrar' => 'Administrar actividades',
                'asignaciones.ver' => 'Ver asignaciones',
                'asignaciones.crear' => 'Asignar cursos',
                'asignaciones.cancelar' => 'Cancelar asignaciones',
                'sesiones.administrar' => 'Administrar sesiones en vivo',
                'asistencias.ver' => 'Ver asistencias',
                'asistencias.corregir' => 'Corregir asistencias',
                'reportes.globales' => 'Reportes globales de capacitación',
                'reportes.sucursal' => 'Reportes de capacitación de su sucursal',
                'reportes.exportar' => 'Exportar reportes de capacitación',
            ],
        ],
        'roles' => [
            'etiqueta' => 'Roles y permisos',
            'grupo' => 'Configuración',
            'etapa' => 'configuracion',
            'acceso' => 'roles.administrar',
            'alcances' => [],
            'acciones' => [],
        ],
        'auditoria' => [
            'etiqueta' => 'Bitácora de auditoría',
            'grupo' => 'Configuración',
            'etapa' => 'configuracion',
            'acceso' => 'auditoria.ver',
            'alcances' => [],
            'acciones' => [],
        ],
        'integraciones' => [
            'etiqueta' => 'Integraciones y ajustes generales',
            'grupo' => 'Configuración',
            'etapa' => 'configuracion',
            'acceso' => null,
            'alcances' => [],
            'acciones' => [
                'integraciones.administrar' => 'Administrar integraciones',
                'configuracion.administrar' => 'Administrar configuración general (legado)',
            ],
        ],
        'app_movil' => [
            'etiqueta' => 'App móvil',
            'grupo' => 'Mi espacio',
            'etapa' => 'mi_espacio',
            'descripcion' => 'Uso básico de la app: datos iniciales, configuración y avisos push del propio dispositivo.',
            'acceso' => null,
            'alcances' => [],
            'acciones' => [
                'mobile.bootstrap.ver' => 'Abrir la app (datos iniciales)',
                'app.config.ver' => 'Leer configuración de la app',
                'dispositivos.push_token.registrar' => 'Registrar su dispositivo para avisos',
                'dispositivos.push_token.revocar' => 'Quitar su dispositivo',
            ],
        ],
        // --- Organización y Configuración (permisos por acción) ---
        // El acceso es el permiso que ya revisa el menú; cada acción nueva
        // se separó de él (ver 'herencia') y la exigen las Policies.
        'organigrama' => [
            'etiqueta' => 'Organigrama',
            'grupo' => 'Organización',
            'etapa' => 'organizacion',
            'acceso' => 'organigrama.ver',
            'alcances' => [],
            'acciones' => [
                'organigrama.editar' => 'Editar jerarquía de puestos',
                'organigrama.coberturas' => 'Asignar / finalizar coberturas temporales',
                'organigrama.mover_personas' => 'Mover personas y clasificar Q1 / Q3',
                'organigrama.matriz_comercial' => 'Editar matriz comercial (responsables y apoyos)',
                // «Responsables de aprobación» cuelga del Organigrama en el menú.
                'responsables_aprobacion.ver' => 'Ver responsables de aprobación',
                'responsables_aprobacion.administrar' => 'Asignar / quitar responsables de aprobación',
            ],
        ],
        'puestos' => [
            'etiqueta' => 'Puestos y tabulador',
            'grupo' => 'Organización',
            'etapa' => 'organizacion',
            'acceso' => 'puestos.administrar',
            'alcances' => [],
            'acciones' => [
                'puestos.crear' => 'Crear',
                'puestos.editar' => 'Editar',
                'puestos.eliminar' => 'Eliminar',
            ],
        ],
        'salarios' => [
            'etiqueta' => 'Administración de salarios',
            'grupo' => 'Organización',
            'etapa' => 'organizacion',
            'acceso' => 'salarios.ver',
            'alcances' => [],
            'acciones' => [
                'salarios.editar' => 'Capturar tabulador y matriz de mérito',
                'salarios.exportar' => 'Exportar (Excel / PDF)',
                'salarios.importar' => 'Importar tabulador desde Excel',
            ],
        ],
        'sucursales' => [
            'etiqueta' => 'Sucursales',
            'grupo' => 'Organización',
            'etapa' => 'organizacion',
            'acceso' => 'sucursales.administrar',
            'alcances' => [],
            'acciones' => [
                'sucursales.crear' => 'Crear',
                'sucursales.editar' => 'Editar',
                'sucursales.eliminar' => 'Eliminar',
            ],
        ],
        'departamentos' => [
            'etiqueta' => 'Departamentos',
            'grupo' => 'Organización',
            'etapa' => 'organizacion',
            'acceso' => 'departamentos.administrar',
            'alcances' => [],
            'acciones' => [
                'departamentos.crear' => 'Crear',
                'departamentos.editar' => 'Editar',
                'departamentos.eliminar' => 'Eliminar',
                'departamentos.mover_colaboradores' => 'Mover personas entre departamentos',
            ],
        ],
        'empresas' => [
            'etiqueta' => 'Empresas',
            'grupo' => 'Organización',
            'etapa' => 'organizacion',
            'acceso' => 'empresas.ver',
            'alcances' => [],
            'acciones' => [
                'empresas.crear' => 'Crear',
                'empresas.editar' => 'Editar',
                'empresas.eliminar' => 'Eliminar',
            ],
        ],
        'documentos_maestros' => [
            'etiqueta' => 'Documentos maestros',
            'grupo' => 'Configuración',
            'etapa' => 'configuracion',
            'acceso' => 'plantillas_documentales.administrar',
            'alcances' => [],
            'acciones' => [
                'plantillas_documentales.ver' => 'Consultar plantillas (app móvil)',
                'documentos_maestros.cargar' => 'Cargar documento / nueva versión',
                'documentos_maestros.editar' => 'Editar campos en el editor visual',
                'documentos_maestros.probar' => 'Probar con un colaborador',
                'documentos_maestros.validar' => 'Validar diseño y revisar fuentes',
                'documentos_maestros.activar' => 'Activar / desactivar versión',
                'documentos_maestros.activar_excepcional' => 'Activar sin QA visual (excepción)',
                'documentos_maestros.descargar' => 'Descargar original',
                'documentos_maestros.diseno' => 'Diseño de página, fondos y documentos administrativos',
            ],
        ],
        'catalogo_bajas' => [
            'etiqueta' => 'Catálogo de bajas',
            'grupo' => 'Configuración',
            'etapa' => 'configuracion',
            // La pantalla exige además «Ver finiquitos» y «Administrar
            // causales de rotación» (módulo Finiquitos).
            'acceso' => null,
            'alcances' => [],
            'acciones' => [
                'causales_rotacion.crear' => 'Crear causal',
                'causales_rotacion.editar' => 'Editar causal',
                'causales_rotacion.activar' => 'Activar / desactivar',
                'causales_rotacion.ordenar' => 'Cambiar el orden',
            ],
        ],
        'usuarios' => [
            'etiqueta' => 'Usuarios',
            'grupo' => 'Configuración',
            'etapa' => 'configuracion',
            'acceso' => 'usuarios.ver',
            'alcances' => [],
            'acciones' => [
                'usuarios.crear' => 'Crear cuenta',
                'usuarios.editar' => 'Editar cuenta (usuario, correo)',
                'usuarios.asignar_roles' => 'Asignar roles',
                'usuarios.restablecer_password' => 'Generar credenciales / contraseña',
                'usuarios.desactivar' => 'Revocar acceso',
                'usuarios.restablecer_acceso' => 'Restablecer acceso suspendido',
                'usuarios.reactivar' => 'Reactivar colaborador dado de baja',
            ],
        ],
        'importar_datos' => [
            'etiqueta' => 'Importar datos',
            'grupo' => 'Configuración',
            'etapa' => 'configuracion',
            // Solo super_admin por decisión del negocio (2026-10-20). Las
            // importaciones de rotación, recibos y descriptivos viven en su
            // propio módulo.
            'acceso' => null,
            'alcances' => [],
            'acciones' => [
                'expedientes.migrar' => 'Migración inicial de expedientes',
                'headcount.importar' => 'Importar headcount',
                'colaboradores.importar_salarios_vacaciones' => 'Importar salarios y vacaciones',
                'contactos_emergencia.importar' => 'Importar contactos de emergencia',
            ],
        ],
        'configuracion' => [
            'etiqueta' => 'Ajustes y notificaciones',
            'grupo' => 'Configuración',
            'etapa' => 'configuracion',
            'acceso' => 'configuracion.ver',
            'alcances' => [],
            'acciones' => [
                'configuracion.apariencia' => 'Colores institucionales',
                'configuracion.organizacion' => 'Organización',
                'configuracion.notificaciones' => 'Ver ruteo de notificaciones',
                'configuracion.notificaciones.editar' => 'Cambiar ruteo de notificaciones',
                'configuracion.rh' => 'Ver parámetros de RH',
                'configuracion.rh.editar_parametros' => 'Cambiar parámetros generales de RH',
                'configuracion.rh.puestos' => 'Periodo de prueba por puesto',
                'configuracion.rh.tipos_documento' => 'Vigencia de tipos de documento',
                'configuracion.rh.reclutamiento' => 'Motivos de rechazo y preguntas filtro',
            ],
        ],
        'app_releases' => [
            'etiqueta' => 'Versiones de app',
            'grupo' => 'Configuración',
            'etapa' => 'configuracion',
            'acceso' => 'app_releases.ver',
            'alcances' => [],
            'acciones' => [
                'app_releases.crear' => 'Subir versión',
                'app_releases.publicar' => 'Publicar / despublicar',
                'app_releases.eliminar' => 'Eliminar',
                'app_releases.descargar' => 'Descargar APK',
            ],
        ],
        // --- Inicio / Reclutamiento / Reportes (permisos por acción) ---
        'tablero' => [
            'etiqueta' => 'Tablero de RH',
            'grupo' => 'Inicio',
            'etapa' => 'inicio',
            'acceso' => null,
            'alcances' => [],
            'acciones' => ['dashboard.global.ver' => 'Tablero global (modo operativo)', 'dashboard.sucursal.ver' => 'Tablero de su sucursal (modo operativo)'],
        ],
        'directorio' => [
            'etiqueta' => 'Directorio',
            'grupo' => 'Permanencia',
            'etapa' => 'permanencia',
            'descripcion' => 'Todo el personal entra al directorio. El alcance decide A QUIÉNES ve: «Propios» = solo su sucursal (y su jefe directo); Sucursal = sus sucursales asignadas; Región = las de su región (matriz comercial); Todos = toda la empresa. El personal de Corporativo siempre ve a toda la empresa.',
            'acceso' => null,
            'alcances' => ['todos' => 'directorio.ver_todos', 'region' => 'directorio.ver_region', 'sucursal' => 'directorio.ver_sucursal'],
            'acciones' => ['directorio.contactos.ver_todos' => 'Ver correo y teléfono de todos (dentro de su alcance)', 'directorio.editar' => 'Edición rápida de filas'],
        ],
        'campanas' => [
            'etiqueta' => 'Campañas de reclutamiento',
            'grupo' => 'Reclutamiento',
            'etapa' => 'reclutamiento',
            'acceso' => 'reclutamiento.campanas.ver',
            'alcances' => [],
            'acciones' => [
                'reclutamiento.campanas.crear' => 'Crear', 'reclutamiento.campanas.editar' => 'Editar y quitar adjuntos',
                'reclutamiento.campanas.eliminar' => 'Eliminar', 'reclutamiento.campanas.exportar' => 'Reportes PDF / Excel y exportar costos',
                'reclutamiento.campanas.administrar' => 'Puede ser responsable de campañas',
            ],
        ],
        'perfiles_puesto' => [
            'etiqueta' => 'Descriptivos y perfiles de puesto',
            'grupo' => 'Reclutamiento',
            'etapa' => 'reclutamiento',
            'acceso' => 'perfiles_puesto.ver',
            'alcances' => [],
            'acciones' => ['perfiles_puesto.descargar' => 'Ver / descargar el PDF del descriptivo', 'perfiles_puesto.editar' => 'Editar perfil y descripción', 'perfiles_puesto.importar' => 'Importar descriptivos oficiales (PDF)'],
        ],
        'reportes_rh' => [
            'etiqueta' => 'Reportes de RH',
            'grupo' => 'Reportes',
            'etapa' => 'reportes',
            'acceso' => 'reportes_rh.ver',
            'alcances' => [],
            'acciones' => ['reportes_rh.exportar' => 'Exportar (Excel / PDF)', 'reportes_rh.globales' => 'Reportes globales', 'reportes_rh.sucursal' => 'Reportes de su sucursal'],
        ],
        'expedientes' => [
            'etiqueta' => 'Expedientes',
            'grupo' => 'People',
            'acceso' => 'expedientes.ver',
            'alcances' => ['todos' => 'expedientes.ver_todos', 'region' => 'expedientes.ver_region', 'sucursal' => 'expedientes.ver_sucursal', 'equipo' => 'expedientes.ver_equipo'],
            'acciones' => [
                'expedientes.crear' => 'Crear', 'expedientes.editar' => 'Editar', 'expedientes.revisar' => 'Revisar', 'expedientes.eliminar' => 'Eliminar',
                // Documentos del expediente: ver el estado ≠ abrir/descargar el
                // archivo (el gerente consulta datos generales, no descarga).
                'documentos.ver' => 'Ver documentos del expediente (estado)',
                'documentos.descargar' => 'Abrir / descargar archivos del expediente',
                'documentos.subir' => 'Subir documentos al expediente',
            ],
        ],
        // Validación de documentos (Etapa 2): bandeja de documentos por revisar.
        'validacion_documentos' => [
            'etiqueta' => 'Validación de documentos',
            'grupo' => 'People',
            'etapa' => 'contratacion',
            'acceso' => 'documentos.revisar',
            'alcances' => [],
            'acciones' => ['documentos.aprobar' => 'Aprobar', 'documentos.rechazar' => 'Rechazar', 'documentos.versiones' => 'Ver versiones anteriores'],
        ],
        // Altas digitales (absorbidas por «Personas en contratación»).
        'altas' => [
            'etiqueta' => 'Altas digitales',
            'grupo' => 'People',
            'etapa' => 'contratacion',
            'acceso' => 'altas.ver',
            'alcances' => [],
            'acciones' => ['altas.crear' => 'Crear', 'altas.enviar' => 'Enviar', 'altas.revisar' => 'Revisar', 'altas.aprobar' => 'Aprobar', 'altas.cancelar' => 'Cancelar'],
        ],
        'invitaciones_qr' => [
            'etiqueta' => 'Invitaciones QR',
            'grupo' => 'Reclutamiento',
            'etapa' => 'reclutamiento',
            'acceso' => 'rh.incorporacion.invitaciones.ver',
            'alcances' => [],
            'acciones' => [
                'rh.incorporacion.invitaciones.crear' => 'Crear invitación',
                'rh.incorporacion.invitaciones.regenerar' => 'Regenerar código',
                'rh.incorporacion.invitaciones.revocar' => 'Revocar',
                'rh.incorporacion.invitaciones.qr.descargar' => 'Descargar QR',
            ],
        ],
        'solicitudes' => [
            'etiqueta' => 'Solicitudes',
            'grupo' => 'People',
            'acceso' => 'solicitudes.ver',
            'alcances' => ['todos' => 'solicitudes.ver_todos', 'region' => 'solicitudes.ver_region', 'sucursal' => 'solicitudes.ver_sucursal', 'equipo' => 'solicitudes.ver_equipo'],
            'acciones' => [
                'solicitudes.crear' => 'Crear', 'solicitudes.revisar' => 'Marcar en revisión', 'solicitudes.requerir_correccion' => 'Pedir corrección',
                'solicitudes.aprobar' => 'Aprobar', 'solicitudes.rechazar' => 'Rechazar',
                'solicitudes.descargar_formato' => 'Descargar formato de permiso (PDF)', 'solicitudes.exportar' => 'Exportar bandeja (Excel / PDF)',
            ],
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
            'acciones' => [
                'actas.crear' => 'Levantar acta', 'actas.gestionar' => 'Gestionar (RH: cualquier acta, no solo las propias en borrador)',
                'actas.editar' => 'Editar', 'actas.anexos.subir' => 'Subir anexos', 'actas.registrar_firma' => 'Registrar negativa a firmar / entrega de copia',
                'actas.seguimiento' => 'Notas de seguimiento', 'actas.cerrar' => 'Cerrar', 'actas.descargar' => 'Generar / descargar formato (PDF) y anexos',
            ],
        ],
        // Responsivas de equipo (docs/responsiva): el gerente las genera para
        // su equipo y RH para cualquiera; el colaborador no las ve aquí.
        'responsivas' => [
            'etiqueta' => 'Responsivas de equipo',
            'grupo' => 'People',
            'acceso' => 'responsivas.ver',
            'alcances' => ['todos' => 'responsivas.ver_todos', 'region' => 'responsivas.ver_region', 'sucursal' => 'responsivas.ver_sucursal', 'equipo' => 'responsivas.ver_equipo'],
            'acciones' => [
                'responsivas.generar' => 'Generar', 'responsivas.registrar_firma' => 'Marcar firmada físicamente',
                'responsivas.registrar_devolucion' => 'Registrar devolución del equipo', 'responsivas.descargar' => 'Descargar PDF',
                'responsivas.cancelar' => 'Cancelar',
            ],
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
            // El acceso deja ver la lista y el estado; ver/descargar/generar
            // el contrato son acciones aparte. El gerente solo sube el firmado.
            'acciones' => [
                'contratos.ver_documento' => 'Ver contrato (PDF generado)',
                'contratos.descargar' => 'Descargar contrato (PDF / Word)',
                'contratos.generar' => 'Generar contratos (con vista previa)',
                'contratos.subir_firmado' => 'Subir contrato firmado',
            ],
        ],
        // Cartas compromiso de documentos pendientes (nuevo ingreso): RH, el
        // gerente (de sucursal) y la gerente regional las generan para su
        // gente y suben el escaneo firmado por quien entra
        // (GestionCartasCompromisoService / CartaCompromisoPolicy).
        'cartas_compromiso' => [
            'etiqueta' => 'Cartas compromiso',
            'grupo' => 'People',
            'etapa' => 'contratacion',
            'acceso' => 'cartas_compromiso.ver',
            'alcances' => ['todos' => 'cartas_compromiso.ver_todos', 'region' => 'cartas_compromiso.ver_region', 'sucursal' => 'cartas_compromiso.ver_sucursal', 'equipo' => 'cartas_compromiso.ver_equipo'],
            'acciones' => [
                'cartas_compromiso.generar' => 'Generar / asignar (con vista previa)',
                'cartas_compromiso.subir_firmada' => 'Subir carta firmada',
                'cartas_compromiso.descargar' => 'Ver / imprimir la carta (PDF)',
            ],
        ],
        'evaluaciones' => [
            'etiqueta' => 'Evaluaciones (periodo de prueba)',
            'grupo' => 'People',
            'acceso' => 'evaluaciones.ver',
            'alcances' => ['todos' => 'evaluaciones.ver_todos', 'region' => 'evaluaciones.ver_region', 'sucursal' => 'evaluaciones.ver_sucursal', 'equipo' => 'evaluaciones.ver_equipo'],
            'acciones' => [
                'evaluaciones.capturar' => 'Capturar', 'evaluaciones.autorizar' => 'Autorizar',
                'evaluaciones.devolver' => 'Devolver para corrección', 'evaluaciones.asignar' => 'Asignar evaluación',
                'evaluaciones.descargar' => 'Imprimir / descargar PDF',
            ],
        ],
        'candidatos' => [
            'etiqueta' => 'Candidatos',
            'grupo' => 'Reclutamiento',
            'acceso' => 'candidatos.ver',
            'alcances' => ['todos' => 'candidatos.ver_todos', 'region' => 'candidatos.ver_region', 'sucursal' => 'candidatos.ver_sucursal'],
            'descripcion' => 'Un permiso por cada paso del recorrido: «Ver» deja seguir todo el proceso, pero cada botón solo aparece a quien tiene el permiso de ESE paso (p. ej. un gerente que solo registra la entrevista).',
            'acciones' => [
                'candidatos.crear' => 'Registrar candidatos', 'candidatos.editar' => 'Editar datos del candidato',
                'candidatos.revisar_perfil' => 'Paso 1 · Revisar perfil (filtro inicial)',
                'candidatos.aprobar_entrevista' => 'Paso 2 · Registrar / aprobar entrevista',
                'candidatos.registrar_psicometricas' => 'Paso 3 · Enviar link y cargar resultados psicométricos',
                'candidatos.revisar_psicometricas' => 'Paso 4 · Revisar psicométrico (viable / no viable)',
                'candidatos.registrar_socioeconomico' => 'Paso 5 · Cargar estudio socioeconómico',
                'candidatos.revisar_socioeconomico' => 'Paso 6 · Revisar socioeconómico (viable / no viable)',
                'candidatos.validar_referencias' => 'Paso 7 · Registrar y concluir referencias',
                'ciclo.autorizar_rh' => 'Paso 8 · Autorización final de RH (contratación y demás procesos del ciclo)',
                'candidatos.contratar' => 'Paso 9 · Generar QR de contratación',
                'candidatos.intervencion_decidir' => 'Decidir una intervención (Regional / Dirección Comercial)',
                'candidatos.cerrar_proceso' => 'Cerrar el proceso de un candidato (con motivo)',
                'candidatos.solicitar_intervencion' => 'Solicitar intervención sobre un rechazo de RH',
                'candidatos.adjuntar' => 'Agregar archivos al mini expediente del candidato',
                'candidatos.aprobar' => 'Ver candidatos e intervenciones en el resumen de Inicio',
            ],
        ],
        'vacantes' => [
            'etiqueta' => 'Vacantes',
            'grupo' => 'Reclutamiento',
            'acceso' => 'vacantes.ver',
            'alcances' => ['todos' => 'vacantes.ver_todos', 'region' => 'vacantes.ver_region', 'sucursal' => 'vacantes.ver_sucursal'],
            'acciones' => ['vacantes.exportar' => 'Exportar (Excel / PDF)', 'vacantes.crear' => 'Crear', 'vacantes.editar' => 'Editar', 'vacantes.cerrar' => 'Cerrar', 'vacantes.eliminar' => 'Eliminar'],
        ],
        'recibos' => [
            'etiqueta' => 'Recibos de nómina',
            'grupo' => 'Nómina',
            'acceso' => 'nomina.recibos.ver',
            'alcances' => ['todos' => 'nomina.recibos.ver_todos', 'region' => 'nomina.recibos.ver_region', 'sucursal' => 'nomina.recibos.ver_sucursal', 'equipo' => 'nomina.recibos.ver_equipo'],
            'acciones' => [
                'nomina.recibos.crear' => 'Preparar / recalcular / ajustar', 'nomina.recibos.emitir' => 'Emitir',
                'nomina.recibos.cancelar' => 'Cancelar lote', 'nomina.recibos.descargar' => 'Descargar / imprimir PDF',
                'nomina.recibos.importar' => 'Importar',
            ],
        ],
        'finiquitos' => [
            'etiqueta' => 'Finiquitos',
            'grupo' => 'Nómina',
            'acceso' => 'finiquitos.ver',
            'alcances' => ['todos' => 'finiquitos.ver_todos', 'region' => 'finiquitos.ver_region', 'sucursal' => 'finiquitos.ver_sucursal', 'equipo' => 'finiquitos.ver_equipo'],
            'acciones' => ['finiquitos.calcular' => 'Calcular', 'finiquitos.revisar' => 'Revisar', 'finiquitos.descargar' => 'Vista previa / generar / descargar PDF', 'finiquitos.subir_firmado' => 'Subir firmado', 'finiquitos.confirmar_pago' => 'Confirmar pago', 'finiquitos.causales_rotacion' => 'Administrar causales de rotación'],
        ],
        'cierres' => [
            'etiqueta' => 'Bajas (cierres laborales)',
            'grupo' => 'People',
            'acceso' => 'cierres.ver',
            'alcances' => ['todos' => 'cierres.ver_todos', 'region' => 'cierres.ver_region', 'sucursal' => 'cierres.ver_sucursal', 'equipo' => 'cierres.ver_equipo'],
            'acciones' => [
                'cierres.solicitar' => 'Solicitar baja', 'cierres.gestionar' => 'Gestionar', 'cierres.aviso.subir' => 'Subir aviso de término firmado',
                'cierres.aviso.generar' => 'Generar aviso de término (app)', 'cierres.cancelar' => 'Cancelar cierre',
                'cierres.ejecutar_baja' => 'Ejecutar baja', 'cierres.programar_pago' => 'Programar pago',
            ],
        ],
        'incorporaciones' => [
            'etiqueta' => 'Incorporaciones',
            'grupo' => 'People',
            'acceso' => 'onboarding.ver',
            'alcances' => ['todos' => 'onboarding.ver_todos', 'region' => 'onboarding.ver_region', 'sucursal' => 'onboarding.ver_sucursal', 'equipo' => 'onboarding.ver_equipo'],
            'acciones' => [
                'onboarding.gestionar' => 'Gestionar procesos de onboarding',
                'onboarding.presentar_induccion' => 'Presentar inducción institucional',
                'onboarding.induccion_puesto' => 'Inducción al puesto (enviar descriptivo)',
                'onboarding.configurar' => 'Configurar módulos, activos y material',
                'onboarding.calificar' => 'Calificar y retroalimentar la inducción',
                'onboarding.descargar_pdf' => 'Ver / descargar / archivar PDF de la evaluación',
                'onboarding.entregar_activos' => 'Entregar activos',
                'formularios_evaluacion.administrar' => 'Configurar formularios de evaluación (inducción y periodo de prueba)',
            ],
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
                'bajas.gestionar' => 'Operar como RH (cualquier persona, campos de RH)',
                'bajas.documentos.adjuntar' => 'Adjuntar / quitar documentos',
                'bajas.finiquito.vista_previa' => 'Vista previa del finiquito al registrar (PDF)',
                'bajas.cambiar_causal' => 'Cambiar causal',
                'bajas.editar' => 'Corregir datos',
                'bajas.enviar_juridico' => 'Enviar a Jurídico',
                'bajas.cerrar' => 'Cerrar',
                'bajas.reabrir' => 'Reabrir',
                'bajas.cancelar' => 'Marcar «No procede»',
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
            'acciones' => ['rotacion.descargar' => 'Descargar formato y Excel original', 'rotacion.cargar' => 'Cargar Excel'],
        ],
        // Reingresos (ReingresoService): el gerente lo solicita para su
        // personal, RH lo decide. Sin alcance propio (usa el organizacional).
        'reingresos' => [
            'etiqueta' => 'Reingresos',
            'grupo' => 'People',
            'etapa' => 'cierres',
            'acceso' => null,
            'alcances' => [],
            'acciones' => [
                'reingresos.solicitar' => 'Solicitar reingreso',
                'reingresos.gestionar' => 'Operar como RH (cualquier persona, sin alcance)',
                'reingresos.decidir' => 'Decidir (viable / no viable)',
            ],
        ],
        // Avisos de RH (mensaje + imagen/PDF a toda la empresa o a una persona).
        'avisos' => [
            'etiqueta' => 'Enviar notificación (avisos)',
            'grupo' => 'People',
            'etapa' => 'permanencia',
            'acceso' => 'avisos.ver',
            'alcances' => [],
            'acciones' => ['avisos.enviar' => 'Enviar aviso'],
        ],
        // Celebraciones (docs/CELEBRACIONES.md): aniversarios, tarjetas y muro.
        'celebraciones' => [
            'etiqueta' => 'Celebraciones (aniversarios y muro)',
            'grupo' => 'People',
            'etapa' => 'permanencia',
            'acceso' => 'celebraciones.ver',
            'alcances' => [],
            'acciones' => [
                'celebraciones.gestionar' => 'Configurar y regenerar / descargar tarjetas',
                'celebraciones.enviar' => 'Enviar felicitación / avisar a todos',
                'celebraciones.moderar' => 'Moderar mensajes del muro',
            ],
        ],
        'cumpleanos' => [
            'etiqueta' => 'Cumpleaños',
            'grupo' => 'People',
            'etapa' => 'permanencia',
            'acceso' => 'rh.cumpleanos.ver',
            'alcances' => [],
            'acciones' => [
                'rh.cumpleanos.calendario' => 'Calendario',
                'rh.cumpleanos.generar' => 'Generar / regenerar felicitación y elegir frase',
                'rh.cumpleanos.descargar_imagen' => 'Descargar imagen',
                'rh.cumpleanos.notificaciones.gestionar' => 'Enviar felicitación manualmente',
                'rh.cumpleanos.muro.gestionar' => 'Abrir / cerrar el muro',
                'rh.cumpleanos.frases.gestionar' => 'Administrar frases',
                'rh.cumpleanos.configurar' => 'Configurar fondo y apariencia',
            ],
        ],
        'notificaciones' => [
            'etiqueta' => 'Notificaciones',
            'grupo' => 'Sistema',
            'descripcion' => 'A quién le llega cada aviso lo decide el permiso de ACCIÓN y su alcance en el módulo: un gerente con «Solicitudes → Sucursal» solo recibe los permisos y solicitudes de su sucursal; RH con «Todos», los de todas. Quien tiene «Elegir de qué sucursales NO recibir avisos» puede silenciar sucursales en su Configuración.',
            'acceso' => 'notificaciones.ver_propias',
            // Solo dos niveles: las propias (campana) o todas (consulta
            // administrativa de solo lectura). Ver ≠ recibir avisos.
            'alcances' => ['todos' => 'notificaciones.ver_todas'],
            'acciones' => [
                'notificaciones.leer_todas' => 'Marcar todas MIS notificaciones como leídas',
                'notificaciones.silenciar_sucursales' => 'Elegir de qué sucursales NO recibir avisos',
            ],
        ],
    ],

    /*
    | Alcance de los AVISOS por permiso (2026-10-26): a quien se avisa por
    | tener un permiso de acción (p. ej. «Nueva solicitud por revisar» a
    | quien aprueba solicitudes) se le acota con el alcance que su rol tiene
    | en ESTE módulo en «Roles y permisos». Así un gerente con alcance
    | «Sucursal» solo recibe permisos/solicitudes de su sucursal y RH con
    | «Todos», de todas. Los avisos del organigrama (jefe directo,
    | aprobador del visto bueno) no cambian: le llegan a quien debe actuar.
    | Cada persona con alcance amplio puede además silenciar sucursales
    | (Configuración → Avisos por sucursal).
    */
    'alcance_avisos' => [
        'rh.solicitudes.aprobar' => 'solicitudes',
        'solicitudes.aprobar' => 'solicitudes',
        'solicitudes.revisar' => 'solicitudes',
        'rh.vacaciones.aprobar' => 'vacaciones',
        'vacaciones.aprobar' => 'vacaciones',
        'prestamos.autorizar' => 'prestamos',
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

    /*
    |--------------------------------------------------------------------------
    | Pantalla «Roles y permisos»: etapas, herencia y descripciones
    |--------------------------------------------------------------------------
    |
    | - etapas: los mismos bloques del sidebar operativo, en su orden. Cada
    |   módulo cae en una con su clave 'etapa' o, si no la trae, con
    |   etapa_por_modulo; sin ninguna, en «Otros módulos» (nunca se oculta).
    | - 'acceso' de un módulo puede ser null: módulos que solo agrupan
    |   acciones sueltas (p. ej. Importar datos).
    | - herencia: permiso NUEVO => permiso existente del que se separó. Al
    |   crearse el nuevo (people:sincronizar-permisos), se otorga a todo rol
    |   (y usuario con permiso directo) que ya tenía el existente, y el
    |   seeder lo agrega a la base de esos roles: dividir un permiso general
    |   en acciones nunca cambia lo que alguien podía hacer. El valor puede
    |   ser una lista: hereda quien tenía CUALQUIERA de esos permisos.
    | - retirados: permisos generales que ya no habilitan nada por sí solos
    |   (se dividieron en acciones). Se conservan en la base para no romper
    |   clientes viejos, pero la pantalla no los ofrece.
    | - descripciones: texto corto en español que la pantalla muestra junto a
    |   cada permiso (tooltip). Un permiso sin descripción muestra su
    |   etiqueta.
    */
    'etapas' => [
        'inicio' => ['etiqueta' => 'Inicio', 'numero' => null],
        'reclutamiento' => ['etiqueta' => 'Reclutamiento y selección', 'numero' => 1],
        'contratacion' => ['etiqueta' => 'Contratación y expediente', 'numero' => 2],
        'onboarding' => ['etiqueta' => 'Onboarding', 'numero' => 3],
        'periodo_prueba' => ['etiqueta' => 'Cierre del periodo de prueba', 'numero' => 4],
        'permanencia' => ['etiqueta' => 'Permanencia', 'numero' => 5],
        'desarrollo' => ['etiqueta' => 'Desarrollo', 'numero' => 6],
        'cierres' => ['etiqueta' => 'Cierres laborales', 'numero' => 7],
        'organizacion' => ['etiqueta' => 'Organización', 'numero' => null],
        'reportes' => ['etiqueta' => 'Reportes', 'numero' => null],
        'configuracion' => ['etiqueta' => 'Configuración', 'numero' => null],
        'mi_espacio' => ['etiqueta' => 'Mi espacio', 'numero' => null],
    ],

    'etapa_por_modulo' => [
        'candidatos' => 'reclutamiento', 'vacantes' => 'reclutamiento',
        'documentos' => 'contratacion', 'contratos' => 'contratacion',
        'incorporaciones' => 'onboarding', 'responsivas' => 'onboarding',
        'evaluaciones' => 'periodo_prueba',
        'expedientes' => 'permanencia', 'solicitudes' => 'permanencia', 'vacaciones' => 'permanencia',
        'prestamos' => 'permanencia', 'actas' => 'permanencia', 'recibos' => 'permanencia',
        'cierres' => 'cierres', 'bajas' => 'cierres', 'finiquitos' => 'cierres',
        'rotacion' => 'reportes',
        'notificaciones' => 'mi_espacio',
    ],

    'herencia' => [
        // Contratación y expediente (contratos, candidatos). ver_documento y
        // descargar heredan de documentos_laborales.generar (RH), NO de
        // documentos_laborales.ver: el gerente ve los demás documentos
        // laborales de su gente, pero no el contrato. subir_firmado no
        // hereda (documentos.subir también lo tiene el colaborador): se
        // asigna por rol en RolesYPermisosSeeder.
        'contratos.generar' => 'documentos_laborales.generar',
        'contratos.ver_documento' => 'documentos_laborales.generar',
        'contratos.descargar' => 'documentos_laborales.generar',
        // Recorrido del candidato: un permiso por paso (2026-10-26). Los
        // generales candidatos.editar (Reclutamiento) y candidatos.evaluar
        // (gerente) ya no abren pasos por sí solos; quien los tenía recibe
        // los pasos que cubrían. Una lista = hereda de cualquiera de ellos.
        'candidatos.revisar_perfil' => 'candidatos.editar',
        'candidatos.aprobar_entrevista' => 'candidatos.evaluar',
        'candidatos.registrar_psicometricas' => 'candidatos.editar',
        'candidatos.revisar_psicometricas' => 'candidatos.evaluar',
        'candidatos.registrar_socioeconomico' => ['candidatos.editar', 'candidatos.evaluar'],
        'candidatos.revisar_socioeconomico' => 'candidatos.evaluar',
        'candidatos.validar_referencias' => ['candidatos.editar', 'candidatos.evaluar'],
        'candidatos.cerrar_proceso' => ['candidatos.rechazar', 'candidatos.editar', 'candidatos.evaluar'],
        'candidatos.solicitar_intervencion' => 'candidatos.evaluar',
        'candidatos.adjuntar' => ['candidatos.editar', 'candidatos.evaluar'],
        // Organización y Configuración.
        'organigrama.coberturas' => 'organigrama.editar',
        'organigrama.mover_personas' => 'organigrama.editar',
        'organigrama.matriz_comercial' => 'organigrama.editar',
        'responsables_aprobacion.ver' => 'organigrama.ver',
        'responsables_aprobacion.administrar' => 'organigrama.editar',
        'puestos.crear' => 'puestos.administrar',
        'puestos.editar' => 'puestos.administrar',
        'puestos.eliminar' => 'puestos.administrar',
        'sucursales.crear' => 'sucursales.administrar',
        'sucursales.editar' => 'sucursales.administrar',
        'sucursales.eliminar' => 'sucursales.administrar',
        'departamentos.crear' => 'departamentos.administrar',
        'departamentos.editar' => 'departamentos.administrar',
        'departamentos.eliminar' => 'departamentos.administrar',
        'departamentos.mover_colaboradores' => 'departamentos.administrar',
        'documentos_maestros.cargar' => 'plantillas_documentales.administrar',
        'documentos_maestros.editar' => 'plantillas_documentales.administrar',
        'documentos_maestros.probar' => 'plantillas_documentales.administrar',
        'documentos_maestros.validar' => 'plantillas_documentales.administrar',
        'documentos_maestros.activar' => 'plantillas_documentales.administrar',
        'documentos_maestros.descargar' => 'plantillas_documentales.administrar',
        'documentos_maestros.diseno' => 'plantillas_documentales.administrar',
        'causales_rotacion.crear' => 'finiquitos.causales_rotacion',
        'causales_rotacion.editar' => 'finiquitos.causales_rotacion',
        'causales_rotacion.activar' => 'finiquitos.causales_rotacion',
        'causales_rotacion.ordenar' => 'finiquitos.causales_rotacion',
        'usuarios.asignar_roles' => 'usuarios.editar',
        'usuarios.restablecer_password' => 'usuarios.editar',
        'configuracion.notificaciones.editar' => 'configuracion.notificaciones',
        'configuracion.rh.editar_parametros' => 'configuracion.rh',
        'configuracion.rh.puestos' => 'configuracion.rh',
        'configuracion.rh.tipos_documento' => 'configuracion.rh',
        'configuracion.rh.reclutamiento' => 'configuracion.rh',
        // Reclutamiento / Onboarding / Periodo de prueba / Reportes.
        'vacantes.exportar' => 'vacantes.ver',
        'reclutamiento.campanas.crear' => 'reclutamiento.campanas.administrar',
        'reclutamiento.campanas.editar' => 'reclutamiento.campanas.administrar',
        'reclutamiento.campanas.eliminar' => 'reclutamiento.campanas.administrar',
        'reclutamiento.campanas.exportar' => 'reclutamiento.campanas.ver',
        'perfiles_puesto.descargar' => 'perfiles_puesto.ver',
        'rotacion.descargar' => 'rotacion.ver',
        'onboarding.presentar_induccion' => 'onboarding.gestionar',
        'onboarding.induccion_puesto' => 'onboarding.gestionar',
        'onboarding.configurar' => 'onboarding.gestionar',
        'onboarding.calificar' => 'onboarding.gestionar',
        'onboarding.descargar_pdf' => 'onboarding.gestionar',
        'evaluaciones.devolver' => 'evaluaciones.autorizar',
        'evaluaciones.asignar' => 'evaluaciones.autorizar',
        'evaluaciones.descargar' => 'evaluaciones.ver',
        // --- Solicitudes, actas, nómina, celebraciones, bajas, cierres,
        // reingresos y responsivas (permisos por acción, 2026-10-22) ---
        // actas.descargar NO hereda de actas.ver: el formato del acta es un
        // documento legal que genera/descarga RH (y consultan Jurídico,
        // Dirección y Sistemas); el gerente la levanta y registra, no la
        // descarga — se le habilita por rol si el negocio lo pide.
        'solicitudes.requerir_correccion' => 'solicitudes.revisar',
        'solicitudes.descargar_formato' => 'rh.solicitudes.detalle',
        'solicitudes.exportar' => 'rh.solicitudes.ver',
        'actas.editar' => 'actas.gestionar',
        'actas.anexos.subir' => 'actas.gestionar',
        'actas.registrar_firma' => 'actas.gestionar',
        'actas.seguimiento' => 'actas.gestionar',
        'actas.cerrar' => 'actas.gestionar',
        'nomina.recibos.emitir' => 'nomina.recibos.crear',
        'nomina.recibos.cancelar' => 'nomina.recibos.crear',
        'nomina.recibos.descargar' => 'nomina.recibos.ver',
        'rh.cumpleanos.generar' => 'rh.cumpleanos.ver',
        'bajas.documentos.adjuntar' => 'bajas.solicitar',
        // La vista previa del finiquito (PDF con montos) es de RH: no
        // hereda de bajas.solicitar (gerentes), sí de bajas.gestionar.
        'bajas.finiquito.vista_previa' => 'bajas.gestionar',
        'bajas.cambiar_causal' => 'bajas.gestionar',
        'bajas.editar' => 'bajas.gestionar',
        'bajas.enviar_juridico' => 'bajas.gestionar',
        'bajas.cerrar' => 'bajas.gestionar',
        'bajas.reabrir' => 'bajas.gestionar',
        'bajas.cancelar' => 'bajas.gestionar',
        'cierres.aviso.subir' => 'cierres.solicitar',
        'cierres.aviso.generar' => 'cierres.gestionar',
        'cierres.cancelar' => 'cierres.gestionar',
        'finiquitos.descargar' => 'finiquitos.ver',
        'reingresos.decidir' => 'ciclo.autorizar_rh',
        'responsivas.descargar' => 'responsivas.ver',
        'responsivas.registrar_firma' => 'responsivas.generar',
        'responsivas.registrar_devolucion' => 'responsivas.generar',
    ],

    'retirados' => [
        'candidatos.evaluar' => 'Se dividió en los pasos del recorrido del candidato (entrevista, psicométricas, socioeconómico, referencias).',
        'candidatos.rechazar' => 'Reemplazado por «Cerrar el proceso de un candidato».',
    ],

    'descripciones' => [
        'notificaciones.silenciar_sucursales' => 'En Configuración → Avisos por sucursal, la persona elige sucursales de las que no quiere avisos (campana y push). Lo suyo, lo de su equipo directo y sus confirmaciones le siguen llegando; lo pendiente sigue en «Mis pendientes».',
        'directorio.ver_todos' => 'En el directorio ve al personal de toda la empresa.',
        'directorio.ver_region' => 'En el directorio ve al personal de las sucursales de su región (matriz comercial).',
        'directorio.ver_sucursal' => 'En el directorio ve al personal de sus sucursales asignadas. Sin ningún alcance, solo ve su propia sucursal y a su jefe directo.',
        'roles.administrar' => 'Entrar a «Roles y permisos»: crear, editar, clonar y eliminar roles y decidir qué puede hacer cada uno. Muy sensible.',
        'auditoria.ver' => 'Consultar la bitácora de quién hizo qué.',
        'headcount.ver' => 'Ver la plantilla autorizada y las vacantes por sucursal y puesto.',
        'headcount.editar' => 'Cambiar cuántas plazas autorizadas tiene cada sucursal por puesto.',
        'indicadores.ver' => 'Ver los indicadores de RH (rotación, plantilla, tiempos).',
        'cursos.ver' => 'Entrar a Capacitación continua (cuando el módulo esté encendido).',
        // Contratación y expediente.
        'contratos.ver' => 'Entra a Contratos, Personas en contratación y Contratos de ingreso: lista y estado, sin abrir el contrato.',
        'contratos.ver_documento' => 'Abre el PDF del contrato generado de otra persona en el visor.',
        'contratos.descargar' => 'Descarga el contrato de otra persona (PDF adjunto o Word editable).',
        'contratos.generar' => 'Genera los contratos que le corresponden (individual o «Generar todos») y ve su vista previa.',
        'contratos.subir_firmado' => 'Sube el contrato ya firmado de su gente (queda en revisión de RH). El de un Gerente de Sucursal solo lo sube su Gerente Regional.',
        'cartas_compromiso.ver' => 'Entra a Cartas compromiso: lista, estado (vigente, por vencer, vencida, cumplida) y firma de las cartas de su gente.',
        'cartas_compromiso.generar' => 'Genera la carta compromiso de una persona de nuevo ingreso (máximo de documentos y plazo de la configuración), con vista previa antes de generarla.',
        'cartas_compromiso.subir_firmada' => 'Sube el escaneo de la carta firmada (con huella) por quien entra; queda en su expediente.',
        'cartas_compromiso.descargar' => 'Abre e imprime el PDF de la carta compromiso (solo la carta, no el resto del expediente) para que la firme quien entra.',
        'documentos.ver' => 'Ve qué documentos tiene el expediente y su estado.',
        'documentos.descargar' => 'Abre o descarga los archivos del expediente (incluye el acuse IMSS y el expediente completo).',
        'documentos.subir' => 'Sube cualquier documento al expediente (RH; el colaborador, solo al suyo).',
        'documentos.revisar' => 'Entra a Validación de documentos y pide correcciones.',
        'documentos.aprobar' => 'Aprueba documentos del expediente (y autoriza cambios pedidos por el colaborador).',
        'documentos.rechazar' => 'Rechaza documentos del expediente con motivo.',
        'documentos.versiones' => 'Consulta las versiones anteriores de un documento.',
        'candidatos.revisar_perfil' => 'Primer filtro: decide si el perfil del candidato recibido sigue a entrevista.',
        'candidatos.aprobar_entrevista' => 'Registra el resultado de la entrevista de candidatos de su alcance. Con solo este paso, la persona ve el recorrido completo pero no puede avanzar los demás pasos. También la usa RH en una contratación directa.',
        'candidatos.registrar_psicometricas' => 'Registra el link de las pruebas psicométricas y carga los resultados.',
        'candidatos.registrar_socioeconomico' => 'Carga la visita socioeconómica (fecha, dirección, tiempo en la vivienda, evidencias). Cargar no lo aprueba.',
        'candidatos.validar_referencias' => 'Registra referencias laborales y concluye su validación.',
        'candidatos.cerrar_proceso' => 'Cierra el proceso del candidato en cualquier paso, con motivo obligatorio.',
        'candidatos.solicitar_intervencion' => 'Pide que el Regional o Dirección Comercial revise un rechazo de RH.',
        'candidatos.intervencion_decidir' => 'Decide (autoriza o mantiene el rechazo) una intervención solicitada.',
        'candidatos.adjuntar' => 'Agrega CV, psicométricos, videos del socioeconómico o soporte de referencias al mini expediente.',
        'candidatos.editar' => 'Corrige los datos del candidato (nombre, contacto, vacante). No avanza pasos del recorrido.',
        'ciclo.autorizar_rh' => 'Autoridad final de RH: autoriza la contratación del candidato (y los demás procesos del ciclo laboral que la piden).',
        'candidatos.revisar_psicometricas' => 'Decide si el psicométrico cargado es viable.',
        'candidatos.revisar_socioeconomico' => 'Decide si el estudio socioeconómico cargado es viable.',
        'rh.incorporacion.invitaciones.ver' => 'Entra a Invitaciones QR.',
        'rh.incorporacion.invitaciones.crear' => 'Crea la invitación QR de un candidato autorizado.',
        'rh.incorporacion.invitaciones.qr.descargar' => 'Descarga la imagen del QR para enviarla.',
        // Organización y Configuración.
        'organigrama.ver' => 'Entra al Organigrama: árbol de puestos, personas y vacantes.',
        'organigrama.editar' => 'Cambia a quién reporta cada puesto, su tipo, respaldos y puestos que puede cubrir.',
        'organigrama.coberturas' => 'Asigna que alguien cubra temporalmente otro puesto y finaliza la cobertura.',
        'organigrama.mover_personas' => 'Sube, baja o mueve a una persona en el árbol y clasifica gerentes en Q1 / Q3.',
        'organigrama.matriz_comercial' => 'Asigna responsables y apoyos a regiones y zonas de la matriz comercial.',
        'responsables_aprobacion.ver' => 'Consulta quién aprueba explícitamente por sucursal o puesto.',
        'responsables_aprobacion.administrar' => 'Asigna o quita responsables de aprobación.',
        'puestos.administrar' => 'Entra al catálogo de puestos y lo consulta.',
        'puestos.crear' => 'Da de alta un puesto nuevo.',
        'puestos.editar' => 'Cambia nombre, departamento, estado y duración del periodo de prueba.',
        'puestos.eliminar' => 'Elimina un puesto del catálogo.',
        'salarios.ver' => 'Consulta sueldos, tabulador y posición salarial. Dato sensible: solo RH y Dirección General.',
        'salarios.editar' => 'Captura mínimo, medio y máximo por puesto y edita la matriz de mérito. No cambia sueldos.',
        'salarios.exportar' => 'Descarga el tabulador y las posiciones salariales en Excel o PDF.',
        'salarios.importar' => 'Carga el tabulador desde Excel con vista previa. No cambia sueldos.',
        'sucursales.administrar' => 'Entra al catálogo de sucursales y consulta su detalle.',
        'sucursales.crear' => 'Da de alta una sucursal nueva.',
        'sucursales.editar' => 'Cambia los datos de una sucursal (nombre, clave, domicilio).',
        'sucursales.eliminar' => 'Elimina una sucursal.',
        'departamentos.administrar' => 'Entra al catálogo de departamentos y consulta quién pertenece a cada uno.',
        'departamentos.crear' => 'Da de alta un departamento.',
        'departamentos.editar' => 'Cambia el nombre o estado de un departamento.',
        'departamentos.eliminar' => 'Elimina un departamento.',
        'departamentos.mover_colaboradores' => 'Mueve personas en lote a otro departamento (requiere además Editar expedientes).',
        'empresas.ver' => 'Consulta las razones sociales (empresas).',
        'empresas.crear' => 'Da de alta una empresa.',
        'empresas.editar' => 'Cambia los datos de una empresa (logo, RFC, representante legal).',
        'empresas.eliminar' => 'Elimina una empresa.',
        'plantillas_documentales.administrar' => 'Entra a Documentos maestros y consulta versiones y vistas previas.',
        'plantillas_documentales.ver' => 'Consulta el catálogo de plantillas documentales desde la app.',
        'documentos_maestros.cargar' => 'Sube un documento maestro nuevo o una versión nueva, o copia una versión.',
        'documentos_maestros.editar' => 'Marca y guarda los campos que se llenan en el editor visual.',
        'documentos_maestros.probar' => 'Genera una vista previa con los datos de un colaborador (no se guarda).',
        'documentos_maestros.validar' => 'Corre la validación visual y la revisión de fuentes.',
        'documentos_maestros.activar' => 'Activa o desactiva la versión que usa el sistema para generar.',
        'documentos_maestros.activar_excepcional' => 'Activa una versión sin QA visual aprobado (con motivo, auditado).',
        'documentos_maestros.descargar' => 'Descarga el archivo original del documento maestro.',
        'documentos_maestros.diseno' => 'Administra fondos, diseño de página y documentos administrativos (recibo, finiquito…).',
        'causales_rotacion.crear' => 'Agrega una causal (motivo) a un tipo de baja.',
        'causales_rotacion.editar' => 'Cambia el nombre y las reglas de una causal.',
        'causales_rotacion.activar' => 'Activa o desactiva una causal (nunca se borran).',
        'causales_rotacion.ordenar' => 'Cambia el orden en que aparecen las causales.',
        'usuarios.ver' => 'Entra al listado de cuentas de acceso.',
        'usuarios.crear' => 'Crea la cuenta de acceso de un colaborador.',
        'usuarios.editar' => 'Cambia usuario, correo y zona horaria de una cuenta.',
        'usuarios.asignar_roles' => 'Cambia los roles de una cuenta.',
        'usuarios.restablecer_password' => 'Genera credenciales o una contraseña nueva para otra persona.',
        'usuarios.desactivar' => 'Revoca el acceso de una cuenta (no da de baja al colaborador).',
        'usuarios.restablecer_acceso' => 'Rehabilita una cuenta suspendida.',
        'usuarios.reactivar' => 'Reactiva a un colaborador dado de baja desde su expediente.',
        'expedientes.migrar' => 'Migración y carga inicial de colaboradores y expedientes históricos.',
        'headcount.importar' => 'Importa la plantilla autorizada (headcount) desde Excel.',
        'colaboradores.importar_salarios_vacaciones' => 'Importa salarios y vacaciones históricas desde Excel.',
        'contactos_emergencia.importar' => 'Importa los contactos de emergencia desde el Excel de Google Forms.',
        'configuracion.ver' => 'Entra a Ajustes y notificaciones (cada sección pide su permiso).',
        'configuracion.apariencia' => 'Cambia los colores institucionales.',
        'configuracion.organizacion' => 'Sección de organización de los ajustes.',
        'configuracion.notificaciones' => 'Consulta a quién le llega cada aviso del sistema.',
        'configuracion.notificaciones.editar' => 'Cambia o restaura a quién le llega cada aviso.',
        'configuracion.rh' => 'Consulta los parámetros de RH.',
        'configuracion.rh.editar_parametros' => 'Cambia o restaura los parámetros generales de RH.',
        'configuracion.rh.puestos' => 'Cambia la duración del periodo de prueba por puesto.',
        'configuracion.rh.tipos_documento' => 'Cambia la vigencia de cada tipo de documento.',
        'configuracion.rh.reclutamiento' => 'Administra motivos de rechazo y preguntas filtro de candidatos.',
        'app_releases.ver' => 'Consulta las versiones de la app móvil.',
        'app_releases.crear' => 'Sube un APK nuevo.',
        'app_releases.publicar' => 'Publica o despublica una versión para descarga.',
        'app_releases.eliminar' => 'Elimina una versión.',
        'app_releases.descargar' => 'Descarga el APK.',
        // Inicio
        'dashboard.global.ver' => 'Entra al modo operativo con el tablero de toda la empresa.',
        'dashboard.sucursal.ver' => 'Entra al modo operativo con el tablero de sus sucursales.',
        // Directorio
        'directorio.contactos.ver_todos' => 'Sin esto solo ve el contacto de su jefe directo; nombre, puesto y sucursal los ve todo el personal.',
        'directorio.editar' => 'Corrige contacto corporativo, puesto, sucursal y departamento desde el directorio (nunca su propia fila).',
        // Vacantes
        'vacantes.ver' => 'Entra a Vacantes (derivadas de la plantilla autorizada).',
        'vacantes.exportar' => 'Descarga el listado de vacantes en Excel o PDF.',
        'vacantes.crear' => 'Reservado: hoy las vacantes salen solas de la plantilla, sin alta manual.',
        'vacantes.editar' => 'Reservado: hoy las vacantes salen solas de la plantilla, sin edición manual.',
        'vacantes.cerrar' => 'Reservado: hoy las vacantes se cubren solas al contratar.',
        'vacantes.eliminar' => 'Reservado: solo vacantes manuales (hoy no existen).',
        // Campañas
        'reclutamiento.campanas.ver' => 'Entra a Campañas y al reporte en pantalla.',
        'reclutamiento.campanas.crear' => 'Registra una campaña nueva con su gasto.',
        'reclutamiento.campanas.editar' => 'Modifica una campaña y quita sus adjuntos.',
        'reclutamiento.campanas.eliminar' => 'Borra una campaña.',
        'reclutamiento.campanas.exportar' => 'Descarga reportes PDF/Excel y el Excel de costos.',
        'reclutamiento.campanas.administrar' => 'Aparece en la lista de responsables al crear o editar una campaña.',
        // Perfiles de puesto
        'perfiles_puesto.ver' => 'Consulta los descriptivos y perfiles de puesto.',
        'perfiles_puesto.descargar' => 'Abre o descarga el PDF oficial del descriptivo.',
        'perfiles_puesto.editar' => 'Edita el perfil del candidato ideal y la descripción del puesto.',
        'perfiles_puesto.importar' => 'Importa los PDF oficiales de la carpeta (carga masiva, solo super administración).',
        // Reportes de RH
        'reportes_rh.ver' => 'Entra a Reportes de RH.',
        'reportes_rh.exportar' => 'Descarga los reportes en Excel o PDF.',
        'reportes_rh.globales' => 'Reportes de toda la empresa.',
        'reportes_rh.sucursal' => 'Reportes de sus sucursales.',
        // Rotación
        'rotacion.ver' => 'Consulta el histórico de rotación cargado desde Excel.',
        'rotacion.descargar' => 'Descarga el formato de carga y el Excel original de cada carga.',
        'rotacion.cargar' => 'Sube, aplica o descarta un Excel de rotación (carga masiva).',
        // Onboarding
        'onboarding.ver' => 'Consulta los procesos de onboarding.',
        'onboarding.gestionar' => 'Opera el onboarding (completar, retroalimentar avances); requisito de las acciones de abajo.',
        'onboarding.presentar_induccion' => 'Abre «Inducción institucional» para presentarla.',
        'onboarding.induccion_puesto' => 'Ve «Inducción al puesto» y envía el descriptivo de puesto.',
        'onboarding.configurar' => 'Configura módulos, tipos de activo y el material (audio/video) de la inducción.',
        'onboarding.calificar' => 'Ve «Calificaciones y refuerzo», califica la evaluación de inducción y da retroalimentación.',
        'onboarding.descargar_pdf' => 'Ve, descarga y guarda en el expediente el PDF FO-RH-EI (incluye la clave de respuestas).',
        'onboarding.entregar_activos' => 'Entrega activos y genera la responsiva.',
        // Evaluaciones
        'evaluaciones.ver' => 'Consulta evaluaciones de periodo de prueba.',
        'evaluaciones.capturar' => 'Captura la evaluación como evaluador.',
        'evaluaciones.autorizar' => 'Autoriza la evaluación (RH) y pide excepciones a Dirección General.',
        'evaluaciones.devolver' => 'Regresa una evaluación capturada para corrección.',
        'evaluaciones.asignar' => 'Abre la evaluación antes de que el sistema la asigne sola.',
        'evaluaciones.descargar' => 'Ve o descarga el PDF para firma de una evaluación contestada.',
        // --- Solicitudes, actas, nómina, celebraciones, bajas, cierres,
        // reingresos, responsivas y avisos ---
        'solicitudes.revisar' => 'Marca una solicitud de su alcance «En revisión».',
        'solicitudes.requerir_correccion' => 'Regresa la solicitud al colaborador pidiéndole corregir o completar información.',
        'solicitudes.aprobar' => 'Autoriza la solicitud (después de los vistos buenos que pida el organigrama).',
        'solicitudes.rechazar' => 'Rechaza la solicitud con un motivo.',
        'solicitudes.descargar_formato' => 'Descarga el formato oficial de permiso de una solicitud ajena ya autorizada (el dueño siempre descarga el suyo).',
        'solicitudes.exportar' => 'Exporta la bandeja filtrada a Excel o PDF.',
        'actas.crear' => 'Levanta actas de su alcance y completa las propias mientras siguen en borrador.',
        'actas.gestionar' => 'Opera cualquier acta de su alcance aunque no la haya levantado o ya no esté en borrador (RH).',
        'actas.editar' => 'Corrige motivo, hechos, testigos y manifestaciones.',
        'actas.anexos.subir' => 'Agrega evidencias al acta.',
        'actas.registrar_firma' => 'Asienta la negativa a firmar o la constancia de entrega de la copia.',
        'actas.seguimiento' => 'Agrega notas de seguimiento.',
        'actas.cerrar' => 'Cierra el acta.',
        'actas.descargar' => 'Vista previa / generación del formato oficial del acta y descarga de anexos.',
        'nomina.recibos.crear' => 'Prepara lotes, recalcula y ajusta conceptos de recibos (sin emitir).',
        'nomina.recibos.emitir' => 'Emite lotes o recibos ya revisados.',
        'nomina.recibos.cancelar' => 'Cancela un lote de nómina con motivo.',
        'nomina.recibos.descargar' => 'Descarga el PDF de recibos y la impresión del lote.',
        'rh.cumpleanos.generar' => 'Genera o regenera la felicitación y elige su frase.',
        'avisos.ver' => 'Consulta el historial de avisos enviados y sus archivos.',
        'avisos.enviar' => 'Envía avisos a toda la empresa o a una persona.',
        'bajas.gestionar' => 'Opera como RH: registra la baja de cualquier persona y captura los campos de RH. Cada acción de RH tiene además su propio permiso.',
        'bajas.documentos.adjuntar' => 'Adjunta o quita documentos de una baja abierta de su alcance.',
        'bajas.finiquito.vista_previa' => 'Ve el PDF del finiquito propuesto antes de registrar la baja.',
        'bajas.cambiar_causal' => 'Cambia la causal de la baja con motivo.',
        'bajas.editar' => 'Corrige los datos capturados de la baja.',
        'bajas.enviar_juridico' => 'Envía a Jurídico las bajas que lo requieren.',
        'bajas.cerrar' => 'Cierra el expediente de la baja.',
        'bajas.reabrir' => 'Reabre una baja cerrada con motivo.',
        'bajas.cancelar' => 'Marca la baja como «No procede» con motivo.',
        'cierres.aviso.subir' => 'Sube el aviso de término firmado del cierre laboral (solo causas sin documento del sistema).',
        'cierres.aviso.generar' => 'Genera el aviso de término para imprimir y firmar (la rescisión no genera aviso).',
        'cierres.cancelar' => 'Cancela un cierre laboral con motivo.',
        'finiquitos.descargar' => 'Vista previa, generación y descarga del PDF del finiquito.',
        'reingresos.decidir' => 'Decide si un reingreso es viable (regresa a contratación) o no.',
        'responsivas.descargar' => 'Descarga el PDF de la responsiva y la versión firmada.',
        'responsivas.registrar_firma' => 'Marca la responsiva como firmada físicamente.',
        'responsivas.registrar_devolucion' => 'Registra la devolución del equipo.',
    ],

];
