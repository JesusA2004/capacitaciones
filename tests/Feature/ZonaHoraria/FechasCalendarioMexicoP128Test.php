<?php

use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoUsuario;
use App\Enums\EstadoVacante;
use App\Enums\MotivoCobertura;
use App\Enums\TipoSolicitudInterna;
use App\Models\Candidato;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\HeadcountTarget;
use App\Models\Prestamo;
use App\Models\Puesto;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Colaboradores\AltaImssService;
use App\Services\Colaboradores\ColaboradorPerfilService;
use App\Services\Compensacion\CompensacionService;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use App\Services\Nomina\PrestamoService;
use App\Services\Organigrama\CoberturaPuestoService;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use App\Services\Reclutamiento\ContratacionCandidatoService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| P1-28 (zona horaria): los campos DATE legales/de negocio deben guardarse
| con el día CALENDARIO de México, no con el de UTC. La app corre en UTC y de
| 18:00 a 24:00 de México now() ya cuenta el día siguiente. Se simula un
| instante nocturno de México: 2026-03-10 05:00 UTC = 2026-03-09 23:00 México
| (UTC-6 todo el año, sin horario de verano). El día correcto es 2026-03-09.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    // 23:00 del 9 de marzo en México, cuando en UTC ya es el día 10.
    Carbon::setTestNow(Carbon::parse('2026-03-10 05:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('contexto: now() está en el día siguiente (UTC) mientras en México sigue siendo el día anterior', function () {
    expect(now()->toDateString())->toBe('2026-03-10'); // UTC ya cambió de día
});

test('alta IMSS: fecha_alta_imss toma el día de México, no el de UTC', function () {
    Storage::fake(config('expedientes.disk'));

    $rh = clUsuario('rh_admin');
    $persona = Colaborador::factory()->create([
        'estatus' => EstadoUsuario::Activo->value,
        'fecha_alta_imss' => null,
    ]);

    app(AltaImssService::class)->registrar(
        $persona,
        UploadedFile::fake()->create('acuse.pdf', 20, 'application/pdf'),
        $rh,
    );

    expect($persona->refresh()->fecha_alta_imss->toDateString())->toBe('2026-03-09');
});

test('salario: fecha_efectiva del historial toma el día de México cuando no viene capturada', function () {
    $colaborador = Colaborador::factory()->create(['salario_diario' => 300, 'sueldo_mensual' => 9000]);
    $actor = clUsuario('rh_admin');

    $historial = app(CompensacionService::class)->actualizarSalario(
        $colaborador,
        350,
        null,
        $actor,
        ['fuente' => 'manual'],
    );

    expect($historial->fecha_efectiva->toDateString())->toBe('2026-03-09');
});

test('préstamo: la fecha del movimiento toma el día de México, no el de UTC', function () {
    $actor = clUsuario('rh_admin');
    $prestamo = Prestamo::factory()->create([
        'estado' => 'activo',
        'monto_original' => 5000,
        'saldo' => 5000,
    ]);

    $movimiento = app(PrestamoService::class)->registrarMovimiento($prestamo, 100, 'manual', $actor);

    expect($movimiento->fecha->toDateString())->toBe('2026-03-09');
});

test('antigüedad: antiguedad_anios se mide contra el día de México (no suma un año de más por UTC)', function () {
    // Ingreso un 10 de marzo: en México (9 de marzo) aún NO cumple el 6.º año;
    // en UTC (10 de marzo) ya lo habría cumplido. El valor correcto es 5.
    $usuario = clUsuario('colaborador', ['fecha_ingreso' => '2020-03-10']);

    $perfil = app(ColaboradorPerfilService::class)->perfil($usuario);

    expect($perfil['antiguedad_anios'])->toBe(5);
});

/*
| Segundo pase (P1-28 cont.): cobertura de puesto, fecha_cierre de la vacante
| automática y fecha_solicitud del préstamo (derivada de created_at en UTC)
| también deben caer en el día CALENDARIO de México, no en el de UTC.
*/

test('cobertura de puesto: fecha_inicio toma el día de México cuando no viene capturada', function () {
    $colaborador = Colaborador::factory()->create(['estatus' => EstadoUsuario::Activo->value]);
    $puesto = Puesto::factory()->create();
    $sucursal = Sucursal::factory()->create();

    $cobertura = app(CoberturaPuestoService::class)->asignar([
        'colaborador_id' => $colaborador->id,
        'puesto_id' => $puesto->id,
        'sucursal_id' => $sucursal->id,
        'motivo' => MotivoCobertura::Apoyo->value,
    ], clUsuario('rh_admin'));

    expect($cobertura->fecha_inicio->toDateString())->toBe('2026-03-09');
});

test('vacante automática: fecha_cierre toma el día de México al cerrarse', function () {
    $sucursal = Sucursal::factory()->create(['activo' => true]);
    $puesto = Puesto::factory()->create();
    HeadcountTarget::factory()->create(['sucursal_id' => $sucursal->id, 'puesto_id' => $puesto->id, 'plantilla_autorizada' => 1]);

    $vacante = Vacante::factory()->create([
        'sucursal_id' => $sucursal->id, 'puesto_id' => $puesto->id, 'generada_automaticamente' => true,
        'estado' => EstadoVacante::Abierta->value, 'plazas_requeridas' => 1, 'plazas_disponibles' => 1, 'plazas_cubiertas' => 0,
    ]);

    // Ocupada=1, autorizada=1 → faltante 0 → la automática se cierra.
    Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id, 'puesto_id' => $puesto->id, 'estatus' => 'activo']);
    app(VacanteAutoGenerationService::class)->sincronizar($sucursal->id, $puesto->id);

    expect($vacante->fresh()->fecha_cierre->toDateString())->toBe('2026-03-09');
});

test('préstamo: fecha_solicitud derivada de created_at (UTC) toma el día de México', function () {
    $colaborador = Colaborador::factory()->create();
    // created_at = 2026-03-10 05:00 UTC = 2026-03-09 23:00 México.
    $solicitud = SolicitudInterna::factory()->create([
        'tipo' => TipoSolicitudInterna::PrestamoInterno->value,
        'colaborador_id' => $colaborador->id,
        'monto_solicitado' => 5000,
        'plazo_meses' => 5,
    ]);

    $prestamo = app(PrestamoService::class)->crearDesdeSolicitud(
        $solicitud,
        ['monto' => 5000, 'plazo' => 5],
        clUsuario('rh_admin'),
    );

    expect($prestamo->fecha_solicitud->toDateString())->toBe('2026-03-09');
});

/*
| Tercer pase (P1-28 cola): más columnas DATE de negocio que derivaban su día
| de now()/toDateString() en UTC. Deben caer en el día CALENDARIO de México.
*/

test('baja: fecha_apertura y fecha_movimiento de la baja toman el día de México', function () {
    Notification::fake();
    $sucursal = Sucursal::factory()->create(['activo' => true]);
    $puesto = Puesto::factory()->create();
    $colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $puesto->id,
        'estatus' => EstadoUsuario::Activo->value,
    ]);

    ['vacante' => $vacante, 'movimiento' => $movimiento] = app(MovimientoLaboralService::class)->registrarBaja(
        $colaborador,
        clUsuario('rh_admin'),
        'Renuncia voluntaria.',
        crearVacante: true,
    );

    expect($vacante)->not->toBeNull()
        ->and($vacante->fecha_apertura->toDateString())->toBe('2026-03-09')
        ->and($movimiento->fecha_movimiento->toDateString())->toBe('2026-03-09');
});

/**
 * Candidato que recorrió el workflow real hasta la autorización final de RH,
 * sobre una vacante abierta con una sola plaza (así, al contratarlo, la plaza
 * se cubre y la vacante se cierra). Fechas del proceso fijas en el pasado para
 * no depender de la zona horaria del reloj de prueba.
 */
function p128CandidatoAutorizado(array $estructura, User $rh, User $gerente): Candidato
{
    HeadcountTarget::query()->firstOrCreate(
        ['sucursal_id' => $estructura['sucursal']->id, 'puesto_id' => $estructura['puesto']->id],
        HeadcountTarget::factory()->raw(['sucursal_id' => $estructura['sucursal']->id, 'puesto_id' => $estructura['puesto']->id, 'plantilla_autorizada' => 1]),
    );
    $vacante = Vacante::factory()->create([
        'empresa_id' => $estructura['empresa']->id,
        'sucursal_id' => $estructura['sucursal']->id,
        'puesto_id' => $estructura['puesto']->id,
        'estado' => EstadoVacante::Abierta->value,
        'plazas_requeridas' => 1,
        'plazas_disponibles' => 1,
        'plazas_cubiertas' => 0,
        'fecha_apertura' => '2026-02-01',
    ]);

    $wf = app(CandidatoWorkflowService::class);
    $c = $wf->registrar([
        'empresa_id' => $estructura['empresa']->id,
        'sucursal_id' => $estructura['sucursal']->id,
        'departamento_id' => $estructura['departamento']->id,
        'puesto_objetivo_id' => $estructura['puesto']->id,
        'vacante_id' => $vacante->id,
        'nombre' => 'Luis',
        'apellidos' => 'Ramírez',
        'correo' => 'luis.ramirez.p128@mrlana.test',
        'gerente_involucrado_id' => $gerente->id,
    ], $rh);

    $wf->evaluarPerfil($c, $rh, true, null);
    $wf->registrarEntrevista($c, $gerente, ['realizada_en' => '2026-02-10 10:00:00', 'resultado' => 'viable']);
    $wf->registrarResultadosPsicometricas($c, $rh, 'En perfil.');
    $wf->revisarPsicometricas($c, $gerente, true, null);
    $wf->registrarSocioeconomico($c, $gerente, ['fecha_visita' => '2026-02-15', 'direccion' => 'Calle 1']);
    $wf->revisarSocioeconomico($c, $gerente, true, null);
    $wf->registrarReferencia($c, $rh, ['empresa' => 'X', 'contacto' => 'Y', 'resultado' => 'positiva']);
    $wf->concluirReferencias($c, $rh, true, null);
    $wf->autorizarRh($c, $rh, null);

    return $c->refresh();
}

test('contratación: fecha_cierre de la vacante cubierta toma el día de México', function () {
    Notification::fake();
    Storage::fake('nas');
    $estructura = clEstructura();
    $rh = clUsuario('rh_admin');
    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $estructura['sucursal']->id]);

    $candidato = p128CandidatoAutorizado($estructura, $rh, $gerente);

    app(ContratacionCandidatoService::class)->iniciarContratacion($candidato, [
        'sueldo_mensual' => 12000,
        'fecha_ingreso' => '2026-03-01',
        'tipo_contratacion' => 'periodo_prueba',
        'fecha_fin_contrato' => '2026-04-01',
    ], $rh);

    $vacante = Vacante::query()->findOrFail($candidato->refresh()->vacante_id);

    expect($vacante->estado)->toBe(EstadoVacante::Cubierta)
        ->and($vacante->fecha_cierre->toDateString())->toBe('2026-03-09');
});

test('periodo de prueba (no renovación): fecha_efectiva del cierre toma el día de México cuando no hay fecha de fin ni se captura', function () {
    Notification::fake();
    $jefe = clUsuario('jefe_directo');
    $colaborador = Colaborador::factory()->create(['jefe_id' => $jefe->colaborador_id]);
    // Sin fecha_fin: el fallback de fecha_efectiva cae en «hoy» (día México).
    $contrato = ContratoLaboral::factory()->create(['colaborador_id' => $colaborador->id, 'fecha_fin' => null]);
    $evaluacion = EvaluacionPeriodoPrueba::factory()->create([
        'colaborador_id' => $colaborador->id,
        'contrato_laboral_id' => $contrato->id,
        'evaluador_colaborador_id' => $jefe->colaborador_id,
        'estado' => EstadoEvaluacionPrueba::Pendiente,
        'fecha_limite' => '2026-03-20',
    ]);

    $svc = app(EvaluacionPeriodoPruebaService::class);
    // Calificación regular (2) en los 9 criterios: 18 < 25, no alcanza a renovar.
    $svc->capturar($evaluacion, $jefe, [
        'criterios' => collect(config('contratos.evaluacion_periodo_prueba_v2.criterios'))
            ->map(fn (array $c) => ['clave' => $c['clave'], 'calificacion' => 2])->values()->all(),
        'filtros' => collect(config('contratos.evaluacion_periodo_prueba_v2.filtros'))
            ->map(fn (array $f) => ['clave' => $f['clave'], 'respuesta' => false])->values()->all(),
        'observaciones' => 'Desempeño regular; no alcanza el mínimo para renovar.',
    ]);

    $svc->autorizar($evaluacion->refresh(), clUsuario('rh_admin'), ['renovar' => false]);

    $cierre = CierreLaboral::query()->where('colaborador_id', $colaborador->id)->firstOrFail();

    expect($cierre->fecha_efectiva->toDateString())->toBe('2026-03-09');
});
