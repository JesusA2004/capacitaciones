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
        'Validación de documentos' => [['documentos.revisar'], 'rh.validacion-documentos.index'],
        'Contratos de ingreso' => [['contratos.ver'], 'rh.contratos-ingreso.index'],
        'Contratos' => [['contratos.ver'], 'rh.contratos.index'],
        'Cartas compromiso' => [['cartas_compromiso.ver'], 'rh.cartas-compromiso.index'],
        'Inducción institucional' => [['onboarding.gestionar'], 'induccion.presentacion'],
        'Inducción al puesto' => [['onboarding.gestionar'], 'rh.induccion-puesto.index'],
        'Configuración de onboarding' => [['onboarding.gestionar'], 'rh.onboarding.configuracion'],
        'Calificaciones y refuerzo' => [['onboarding.gestionar'], 'rh.onboarding.calificaciones'],
        'Activos y responsivas' => [['responsivas.ver'], 'rh.activos-responsivas.index'],
        'Evaluaciones de periodo de prueba' => [['evaluaciones.capturar', 'evaluaciones.autorizar', 'evaluaciones.ver_todos'], 'rh.evaluaciones.index'],
        'Colaboradores' => [['expedientes.ver_todos', 'expedientes.ver_region', 'expedientes.ver_sucursal', 'expedientes.ver_equipo'], 'rh.expedientes.index'],
        'Solicitudes' => [['solicitudes.revisar', 'solicitudes.aprobar', 'rh.solicitudes.ver'], 'rh.solicitudes.index'],
        'Incapacidades' => [['incapacidades.ver'], 'rh.incapacidades.index'],
        'Actas administrativas' => [['actas.ver'], 'rh.actas.index'],
        'Recibo simple de pago' => [['nomina.recibos.ver'], 'rh.nomina.lotes.index'],
        'Importar salarios y vacaciones' => [['colaboradores.importar_salarios_vacaciones'], 'rh.importaciones.index'],
        'Contactos de emergencia' => [['contactos_emergencia.importar'], 'rh.contactos-emergencia.index'],
        'Enviar notificación' => [['avisos.ver', 'avisos.enviar'], 'rh.avisos.index'],
        'Reingresos' => [['reingresos.solicitar', 'reingresos.gestionar'], 'rh.reingresos.index'],
        'Organigrama' => [['organigrama.ver'], 'administracion.jerarquia-puestos.index'],
        'Responsables de aprobación' => [['organigrama.ver'], 'administracion.responsables-aprobacion.index'],
        'Puestos y tabulador' => [['puestos.administrar'], 'administracion.puestos.index'],
        'Administración de salarios' => [['salarios.ver'], 'salarios.index'],
        'Sucursales' => [['sucursales.administrar'], 'administracion.sucursales.index'],
        'Departamentos' => [['departamentos.administrar'], 'administracion.departamentos.index'],
        'Empresas' => [['empresas.ver'], 'administracion.empresas.index'],
        'Reportes de RH' => [['reportes_rh.ver'], 'reportes.index'],
        'Mis reportes' => [['reportes_rh.mis_reportes'], 'rh.reportes.mis-reportes.index'],
        'Formularios de evaluación' => [['formularios_evaluacion.administrar'], 'rh.formularios-evaluacion.index'],
        'Documentos maestros' => [['plantillas_documentales.administrar'], 'rh.documentos-maestros.index'],
        'Usuarios y permisos' => [['usuarios.ver', 'roles.administrar'], 'administracion.usuarios.index'],
        'Roles y permisos' => [['roles.administrar', 'notificaciones_rol.editar'], 'administracion.roles.index'],
        'Importar datos' => [['expedientes.migrar', 'headcount.importar', 'colaboradores.importar_salarios_vacaciones', 'rotacion.cargar', 'perfiles_puesto.importar', 'contactos_emergencia.importar', 'nomina.recibos.importar'], 'administracion.importar-datos.index'],
        'Ajustes y notificaciones' => [['configuracion.ver', 'notificaciones.ver_todas'], 'administracion.configuracion.index'],
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
        'Mis solicitudes' => [['portal.solicitudes.ver', 'solicitudes.crear'], 'solicitudes.index'],
        'Mis notificaciones' => [[], 'portal.notificaciones'],
        'Mi inducción' => [[], 'portal.induccion.ver'],
        'Celebraciones' => [[], 'celebraciones.index'],
        'Directorio' => [[], 'directorio.index'],
        'Mi agenda' => [['agenda.ver'], 'agenda.index'],
    ];
}

function sbTiene(User $usuario, array $permisos): bool
{
    return $permisos === [] || collect($permisos)->contains(fn (string $p): bool => $usuario->can($p));
}

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->sucursal = Sucursal::factory()->create();
});

dataset('rolesOperativos', ['gerente_sucursal', 'gerente', 'gerente_regional', 'direccion', 'director_comercial', 'rh_admin', 'rh_auxiliar', 'sistemas', 'juridico', 'auditor', 'contraloria']);
dataset('rolesColaborador', ['colaborador', 'subgerente', 'coordinadora']);

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
        $yaEsSuyo = in_array($ruta, ['directorio.index', 'agenda.index'], true);

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
    $aparte = ['Tablero de RH', 'Bajas', 'Catálogo de bajas', 'Ayuda', 'Mi recibo', 'Mi capacitación', 'Evaluación del desempeño', 'Matriz 9 box y planes', 'Capacitación continua', 'Certificación Mr. Lana', 'Celebraciones'];

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
