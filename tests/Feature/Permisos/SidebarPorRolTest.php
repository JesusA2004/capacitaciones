<?php

use App\Enums\GrupoPuestoIndicador;
use App\Enums\OrigenAgendaEvento;
use App\Enums\TipoAgendaEvento;
use App\Models\AgendaEvento;
use App\Models\Candidato;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Navigation\NavigationService;
use App\Services\Reclutamiento\AprobadorEntrevistaService;
use Database\Seeders\RolesYPermisosSeeder;
use Spatie\Permission\Models\Role;

/*
| Recorre cada rol sembrado: toda entrada de su menú responde 200 y toda entrada
| ajena responde 403. El menú vive en useMainNavItems.ts; la última prueba falla
| si aparece una entrada que no esté en esta lista.
*/

/**
 * Título → [permisos (basta uno; vacío = cualquiera), ruta].
 *
 * @return array<string, array{0: list<string>, 1: string}>
 */
function sbOperativo(): array
{
    return [
        'Directorio' => [[], 'directorio.index'],
        'Pendientes' => [['ciclo.preautorizar', 'ciclo.autorizar_rh', 'evaluaciones.capturar', 'evaluaciones.autorizar', 'onboarding.entregar_activos', 'cierres.solicitar', 'cierres.gestionar', 'expedientes.revisar', 'solicitudes.revisar', 'solicitudes.aprobar', 'nomina.recibos.crear', 'candidatos.intervencion_decidir', 'candidatos.aprobar_entrevista'], 'rh.pendientes.index'],
        'Vacantes' => [['vacantes.ver'], 'rh.vacantes.index'],
        'Campañas' => [['reclutamiento.campanas.ver'], 'rh.campanas.index'],
        'Descriptivos y perfiles' => [['perfiles_puesto.ver'], 'rh.perfiles-puesto.index'],
        'Candidatos' => [['candidatos.ver'], 'rh.candidatos.index'],
        'Agenda de entrevistas' => [['agenda.ver'], 'agenda.index'],
        'Invitaciones QR' => [['rh.incorporacion.invitaciones.ver'], 'rh.incorporacion.invitaciones.index'],
        'Personas en contratación' => [['contratos.ver'], 'rh.contratacion.index'],
        'Pendientes de alta en sistemas' => [['sistemas.altas.ver'], 'sistemas.pendientes-alta.index'],
        'Pendientes de baja en sistemas' => [['sistemas.altas.ver'], 'sistemas.pendientes-baja.index'],
        'Validación de documentos' => [['documentos.revisar'], 'rh.validacion-documentos.index'],
        'Contratos de ingreso' => [['contratos.ver'], 'rh.contratos-ingreso.index'],
        'Contratos de la plantilla' => [['contratos.ver'], 'rh.contratos.index'],
        'Cartas compromiso' => [['cartas_compromiso.ver'], 'rh.cartas-compromiso.index'],
        'Inducción institucional' => [['onboarding.gestionar+onboarding.presentar_induccion'], 'induccion.presentacion'],
        'Inducción al puesto' => [['onboarding.gestionar+onboarding.induccion_puesto'], 'rh.induccion-puesto.index'],
        'Configuración de onboarding' => [['onboarding.gestionar+onboarding.configurar'], 'rh.onboarding.configuracion'],
        'Calificaciones y refuerzo' => [['onboarding.gestionar+onboarding.calificar'], 'rh.onboarding.calificaciones'],
        'Activos y responsivas' => [['responsivas.ver'], 'rh.activos-responsivas.index'],
        'Evaluaciones de periodo de prueba' => [['evaluaciones.capturar', 'evaluaciones.autorizar', 'evaluaciones.ver_todos'], 'rh.evaluaciones.index'],
        'Colaboradores' => [['expedientes.ver_todos', 'expedientes.ver_region', 'expedientes.ver_sucursal', 'expedientes.ver_equipo'], 'rh.expedientes.index'],
        'Fechas laborales' => [['expedientes.editar_fechas'], 'rh.fechas-laborales.index'],
        'Solicitudes' => [['solicitudes.revisar', 'solicitudes.aprobar', 'rh.solicitudes.ver'], 'rh.solicitudes.index'],
        'Incapacidades' => [['incapacidades.ver'], 'rh.incapacidades.index'],
        'Actas administrativas' => [['actas.ver'], 'rh.actas.index'],
        'Recepción documental' => [['recepcion_documental.ver'], 'rh.recepcion-documental.index'],
        'Recibos de nómina' => [['nomina.recibos.ver'], 'rh.nomina.lotes.index'],
        'Avisos' => [['avisos.ver', 'avisos.enviar'], 'rh.avisos.index'],
        // Etapa 6 · Capacitación (feature `capacitacion`, encendida en phpunit.xml).
        'Portada de capacitación' => [['cursos.ver', 'asignaciones.ver', 'respuestas.ver', 'reportes.globales', 'reportes.sucursal'], 'capacitacion.index'],
        'Cursos y aulas' => [['cursos.ver'], 'cursos.index'],
        'Asignaciones' => [['asignaciones.ver'], 'asignaciones.index'],
        'Calificar cuestionarios' => [['respuestas.ver'], 'calificaciones.cuestionarios.index'],
        'Calificar actividades' => [['respuestas.ver'], 'calificaciones.actividades.index'],
        'Banco de preguntas' => [['cuestionarios.administrar'], 'bancos-preguntas.index'],
        'Biblioteca multimedia' => [['multimedia.administrar'], 'multimedia.index'],
        // El menú lo ofrece con sesiones.administrar, pero la ruta es el
        // calendario personal de cualquier usuario (CalendarioService: sus
        // fechas límite y sesiones; las creadas solo con sesiones.administrar).
        'Calendario de sesiones' => [[], 'calendario'],
        'Reporte de cumplimiento' => [['reportes.globales', 'reportes.sucursal'], 'reportes.cumplimiento.index'],
        'Reingresos' => [['reingresos.solicitar', 'reingresos.gestionar'], 'rh.reingresos.index'],
        'Organigrama' => [['organigrama.ver'], 'administracion.jerarquia-puestos.index'],
        'Responsables de aprobación' => [['organigrama.ver+responsables_aprobacion.ver', 'organigrama.ver+responsables_aprobacion.administrar'], 'administracion.responsables-aprobacion.index'],
        'Puestos y salarios' => [['puestos.administrar', 'salarios.ver'], 'administracion.puestos.index'],
        'Sucursales' => [['sucursales.administrar'], 'administracion.sucursales.index'],
        'Departamentos' => [['departamentos.administrar'], 'administracion.departamentos.index'],
        'Empresas' => [['empresas.ver'], 'administracion.empresas.index'],
        // Seguimiento (features `seguimiento` y `nine_box`, encendidas por defecto).
        'Nine Box' => [['nine_box.ver'], 'seguimiento.nine-box.index'],
        'Seguimientos técnicos' => [['evaluaciones_tecnicas.ver'], 'evaluaciones-tecnicas.index'],
        'Calendario de seguimientos' => [['evaluaciones_tecnicas.configurar'], 'evaluaciones-tecnicas.configuracion'],
        'Vínculos con Reportes' => [['seguimiento.vincular'], 'seguimiento.vinculos.index'],
        'Rotación de personal' => [['bajas.tablero.ver'], 'reportes.rotacion.tablero.index'],
        'Reportes de RH' => [['reportes_rh.ver'], 'reportes.index'],
        'Mis reportes' => [['reportes_rh.mis_reportes'], 'rh.reportes.mis-reportes.index'],
        'Formularios de evaluación' => [['formularios_evaluacion.administrar'], 'rh.formularios-evaluacion.index'],
        'Documentos maestros' => [['plantillas_documentales.administrar'], 'rh.documentos-maestros.index'],
        'Usuarios y permisos' => [['usuarios.ver'], 'administracion.usuarios.index'],
        'Roles y permisos' => [['roles.administrar', 'notificaciones_rol.editar'], 'administracion.roles.index'],
        'Importar datos' => [['expedientes.migrar', 'headcount.importar', 'reportes_rh.importar_historico', 'colaboradores.importar_salarios_vacaciones', 'rotacion.cargar', 'perfiles_puesto.importar', 'contactos_emergencia.importar', 'nomina.recibos.importar'], 'administracion.importar-datos.index'],
        'Ajustes y notificaciones' => [['configuracion.ver'], 'administracion.configuracion.index'],
        'Notificaciones del sistema' => [['notificaciones.ver_todas'], 'administracion.notificaciones-sistema.index'],
        'Versiones de app' => [['app_releases.ver'], 'administracion.app-releases.index'],
    ];
}

/**
 * @return array<string, array{0: list<string>, 1: string}>
 */
function sbColaborador(): array
{
    return [
        'Mi inicio' => [[], 'portal.index'],
        'Mi expediente' => [['expedientes.ver'], 'mi-expediente'],
        // «Mis documentos laborales» y «Mis contratos» ya no están en el menú:
        // features.documentos_laborales_colaborador (apagado, copia física).
        'Mis solicitudes' => [['portal.solicitudes.ver', 'solicitudes.crear'], 'solicitudes.index'],
        'Mis notificaciones' => [[], 'portal.notificaciones'],
        'Mi inducción' => [[], 'portal.induccion.ver'],
        // Las propias (y las de su equipo si es jefe directo): toda cuenta con colaborador.
        'Mis seguimientos técnicos' => [[], 'portal.evaluaciones-tecnicas'],
        'Celebraciones' => [[], 'celebraciones.index'],
        // El menú lo muestra con `features.directorio` (directorio.ver o
        // Corporativo): el rol colaborador (gestores) no tiene el módulo.
        'Directorio' => [['directorio.ver'], 'directorio.index'],
        'Mi agenda' => [['agenda.ver'], 'agenda.index'],
    ];
}

function sbTiene(User $usuario, array $permisos): bool
{
    // 'a+b' = el permiso general Y la acción (así lo pide el menú).
    return $permisos === [] || collect($permisos)->contains(
        fn (string $p): bool => collect(explode('+', $p))->every(fn (string $parte): bool => $usuario->can($parte)),
    );
}

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->sucursal = Sucursal::factory()->create();
});

dataset('rolesOperativos', ['gerente_sucursal', 'gerente', 'gerente_regional', 'direccion', 'director_comercial', 'rh_admin', 'rh_auxiliar', 'sistemas', 'juridico', 'auditor', 'contraloria', 'jefe_directo', 'supervisor', 'instructor', 'administrador_capacitacion']);
dataset('rolesColaborador', ['colaborador', 'subgerente', 'coordinadora', 'coordinadora_regional']);

test('cada entrada del menú operativo responde 200 y las ajenas 403', function (string $rol) {
    $usuario = clUsuario($rol, ['sucursal_principal_id' => $this->sucursal->id]);
    expect(app(NavigationService::class)->modosDisponibles($usuario))->toContain('operativo');
    $rotas = [];

    foreach (sbOperativo() as $titulo => [$permisos, $ruta]) {
        $estado = $this->actingAs($usuario)->get(route($ruta))->getStatusCode();
        $visible = sbTiene($usuario, $permisos);

        // Una redirección interna (a su pestaña o bandeja) no es un error.
        if (($visible && ! in_array($estado, [200, 302], true)) || (! $visible && $estado !== 403)) {
            $rotas[] = sprintf('%s [%s]: %s, responde %d', $titulo, $ruta, $visible ? 'visible' : 'ajena', $estado);
        }
    }

    expect($rotas)->toBe([]);

    // Su inicio siempre abre algo útil.
    $this->actingAs($usuario)->followingRedirects()->get(route('dashboard'))->assertOk();
})->with('rolesOperativos');

test('un rol personalizado con las acciones apagadas no ve entradas que el backend rechaza', function () {
    // Como RH, pero en «Roles y permisos» le apagaron acciones sueltas que
    // heredaban del permiso general: el menú debe esconder exactamente esas.
    $apagadas = ['onboarding.configurar', 'onboarding.calificar', 'onboarding.presentar_induccion', 'onboarding.induccion_puesto', 'responsables_aprobacion.ver', 'responsables_aprobacion.administrar', 'configuracion.ver', 'usuarios.ver'];
    $rol = Role::create(['name' => 'rh_recortado', 'guard_name' => 'web']);
    $rol->syncPermissions(Role::findByName('rh_admin', 'web')->permissions->pluck('name')->diff($apagadas)->values()->all());
    $usuario = clUsuario('colaborador', ['sucursal_principal_id' => $this->sucursal->id]);
    $usuario->syncRoles(['rh_recortado']);
    $rotas = [];

    foreach (sbOperativo() as $titulo => [$permisos, $ruta]) {
        $estado = $this->actingAs($usuario)->get(route($ruta))->getStatusCode();
        $visible = sbTiene($usuario, $permisos);

        if (($visible && ! in_array($estado, [200, 302], true)) || (! $visible && $estado !== 403)) {
            $rotas[] = sprintf('%s [%s]: %s, responde %d', $titulo, $ruta, $visible ? 'visible' : 'ajena', $estado);
        }
    }

    expect($rotas)->toBe([])
        ->and(sbTiene($usuario, sbOperativo()['Configuración de onboarding'][0]))->toBeFalse()
        ->and(sbTiene($usuario, sbOperativo()['Inducción al puesto'][0]))->toBeFalse();
});

test('cada entrada de Mi espacio responde 200 y la operación da 403', function (string $rol) {
    $usuario = clUsuario($rol, ['sucursal_principal_id' => $this->sucursal->id]);
    expect(app(NavigationService::class)->modosDisponibles($usuario))->toBe(['colaborador']);
    $rotas = [];

    foreach (sbColaborador() as $titulo => [$permisos, $ruta]) {
        $estado = $this->actingAs($usuario)->get(route($ruta))->getStatusCode();

        if (sbTiene($usuario, $permisos) ? ! in_array($estado, [200, 302], true) : $estado !== 403) {
            $rotas[] = sprintf('%s [%s] responde %d', $titulo, $ruta, $estado);
        }
    }

    foreach (sbOperativo() as $titulo => [$permisos, $ruta]) {
        $yaEsSuyo = in_array($ruta, ['directorio.index', 'agenda.index', 'calendario'], true);

        if (! $yaEsSuyo && $this->actingAs($usuario)->get(route($ruta))->getStatusCode() !== 403) {
            $rotas[] = sprintf('%s [%s] debería ser 403', $titulo, $ruta);
        }
    }

    expect($rotas)->toBe([]);
    $this->actingAs($usuario)->followingRedirects()->get(route('dashboard'))->assertOk();
})->with('rolesColaborador');

test('el menú no tiene entradas fuera de este recorrido', function () {
    preg_match_all("/title: '([^']+)'/", (string) file_get_contents(resource_path('js/composables/useMainNavItems.ts')), $titulos);
    preg_match_all("/\\? '([^']+)'\\s*: '([^']+)'/", (string) file_get_contents(resource_path('js/composables/useMainNavItems.ts')), $condicionales);

    // Enrutadas por permiso o detrás de una bandera: no son una sola ruta fija.
    $aparte = ['Tablero de RH', 'Mi bandeja de bajas', 'Bajas', 'Catálogo de causales', 'Ayuda', 'Mi recibo', 'Mis documentos laborales', 'Mis contratos', 'Mi capacitación', 'Certificación Mr. Lana', 'Celebraciones'];

    $sueltos = array_diff(array_unique($titulos[1]), array_keys(sbOperativo()), array_keys(sbColaborador()), $aparte);

    expect(array_values($sueltos))->toBe([]);
});

test('Gerente y Subgerente no ven en agenda ni en candidatos a su posible reemplazo', function (string $rol) {
    $gerente = clUsuario($rol, ['sucursal_principal_id' => $this->sucursal->id]);
    $gestor = Puesto::factory()->create(['nombre' => 'Gestor de prueba', 'grupo_indicador' => GrupoPuestoIndicador::Gestores]);
    $gerencia = Puesto::query()->firstOrCreate(['nombre' => 'Gerente de Sucursal'], Puesto::factory()->raw(['nombre' => 'Gerente de Sucursal']));
    $reemplazo = Candidato::factory()->create(['sucursal_id' => $this->sucursal->id, 'puesto_objetivo_id' => $gerencia->id, 'vacante_id' => null, 'gerente_involucrado_id' => null]);
    $normal = Candidato::factory()->create(['sucursal_id' => $this->sucursal->id, 'puesto_objetivo_id' => $gestor->id, 'vacante_id' => null, 'gerente_involucrado_id' => null]);

    $servicio = app(AprobadorEntrevistaService::class);
    $visibles = $servicio->limitarCandidatos(Candidato::query(), $gerente)->pluck('id')->all();

    expect($visibles)->toContain($normal->id)->not->toContain($reemplazo->id)
        ->and($servicio->esVisiblePara($gerente, $reemplazo))->toBeFalse()
        ->and($servicio->esVisiblePara($gerente, $normal))->toBeTrue();

    $creador = clUsuario('rh_admin');
    $citar = fn (Candidato $c) => AgendaEvento::query()->create([
        'candidato_id' => $c->id, 'sucursal_id' => $this->sucursal->id, 'tipo' => TipoAgendaEvento::Entrevista,
        'fecha_hora' => now()->addDay(), 'origen' => OrigenAgendaEvento::Reclutamiento, 'agendado_por' => $creador->id,
    ]);
    $citaReemplazo = $citar($reemplazo);
    $citaNormal = $citar($normal);

    $agenda = $servicio->limitarAgenda(AgendaEvento::query(), $gerente)->pluck('id')->all();
    expect($agenda)->toContain($citaNormal->id)->not->toContain($citaReemplazo->id)
        ->and($gerente->can('view', $citaReemplazo))->toBeFalse();
})->with(['gerente_sucursal', 'gerente', 'subgerente']);

test('el Regional sí ve la cita de un candidato a Gerente', function () {
    $regional = clUsuario('gerente_regional', ['sucursal_principal_id' => $this->sucursal->id]);
    $gerencia = Puesto::query()->firstOrCreate(['nombre' => 'Gerente de Sucursal'], Puesto::factory()->raw(['nombre' => 'Gerente de Sucursal']));
    $candidato = Candidato::factory()->create(['sucursal_id' => $this->sucursal->id, 'puesto_objetivo_id' => $gerencia->id, 'vacante_id' => null]);

    expect(app(AprobadorEntrevistaService::class)->esVisiblePara($regional, $candidato))->toBeTrue();
});
