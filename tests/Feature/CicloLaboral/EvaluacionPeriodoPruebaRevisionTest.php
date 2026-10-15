<?php

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoUsuario;
use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\TareaRh;
use App\Notifications\Mobile\PendienteRhNotification;
use Carbon\Carbon;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
| Revisión de la evaluación de periodo de prueba («Evaluación de capacitación
| (inicial)»): baja sin cierre laboral, zona horaria de México, respuestas
| filtro 1/0 y paridad web/API al devolver.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $this->jefeUsuario = clUsuario('jefe_directo');
    $this->colaborador = Colaborador::factory()->create(['jefe_id' => $this->jefeUsuario->colaborador_id]);
    $this->contrato = ContratoLaboral::factory()->create([
        'colaborador_id' => $this->colaborador->id,
        'fecha_inicio' => now()->subDays(20)->toDateString(),
        'fecha_fin' => now()->addDays(10)->toDateString(),
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @return array{criterios: list<array{clave: string, calificacion: int}>, filtros: list<array{clave: string, respuesta: mixed}>}
 */
function eppRevisionCaptura(mixed $respuestaFiltros = false): array
{
    return [
        'criterios' => collect(config('contratos.evaluacion_periodo_prueba_v2.criterios'))
            ->map(fn (array $c) => ['clave' => $c['clave'], 'calificacion' => 4])->values()->all(),
        'filtros' => collect(config('contratos.evaluacion_periodo_prueba_v2.filtros'))
            ->map(fn (array $f) => ['clave' => $f['clave'], 'respuesta' => $respuestaFiltros])->values()->all(),
    ];
}

test('a un colaborador dado de baja sin cierre laboral (contrato aún vigente) no se le abre evaluación; si se reactiva, sí', function () {
    // Así deja BajaColaboradorService al colaborador: el contrato sigue «vigente».
    $this->colaborador->update(['estatus' => EstadoUsuario::Inactivo, 'estado_alta' => EstadoAltaColaborador::Baja, 'fecha_baja' => now()->toDateString()]);
    $this->colaborador->delete();

    $this->artisan('contratos:revisar-vencimientos')->assertSuccessful();

    expect(EvaluacionPeriodoPrueba::query()->count())->toBe(0)
        ->and(TareaRh::query()->where('tipo', TipoTarea::EvaluacionPendiente->value)->count())->toBe(0)
        ->and($this->contrato->refresh()->aviso_vencimiento_en)->toBeNull();
    Notification::assertNotSentTo($this->jefeUsuario, PendienteRhNotification::class);

    // Reactivación: el aviso no quedó "quemado" y se procesa normal.
    $this->colaborador->restore();
    $this->colaborador->update(['estatus' => EstadoUsuario::Activo, 'estado_alta' => EstadoAltaColaborador::Activo, 'fecha_baja' => null]);

    $this->artisan('contratos:revisar-vencimientos')->assertSuccessful();

    expect(EvaluacionPeriodoPrueba::query()->where('contrato_laboral_id', $this->contrato->id)->count())->toBe(1);
});

test('un colaborador en incorporación (aún sin activar) sí recibe la evaluación: su contrato corre igual', function () {
    $this->colaborador->update(['estatus' => EstadoUsuario::EnIncorporacion, 'estado_alta' => EstadoAltaColaborador::EnOnboarding, 'activado_en' => null]);

    $this->artisan('contratos:revisar-vencimientos')->assertSuccessful();

    expect(EvaluacionPeriodoPrueba::query()->where('contrato_laboral_id', $this->contrato->id)->value('evaluador_colaborador_id'))
        ->toBe($this->jefeUsuario->colaborador_id);
});

test('el límite de 15 días hábiles se cuenta desde el día en México, no en UTC', function () {
    // Miércoles 1 de julio, 20:00 en México = jueves 2 de julio 02:00 UTC.
    Carbon::setTestNow(Carbon::parse('2026-07-01 20:00:00', 'America/Mexico_City'));

    // 15 hábiles desde el miércoles 1 = miércoles 22; el jueves 23 aún no toca.
    $this->contrato->update(['fecha_fin' => '2026-07-23']);
    $this->artisan('contratos:revisar-vencimientos')->assertSuccessful();
    expect($this->contrato->refresh()->aviso_vencimiento_en)->toBeNull();

    $this->contrato->update(['fecha_fin' => '2026-07-22']);
    $this->artisan('contratos:revisar-vencimientos')->assertSuccessful();
    expect($this->contrato->refresh()->aviso_vencimiento_en)->not->toBeNull();
});

test('la fecha de evaluación por defecto y el tope «hoy» son del día en México', function () {
    $this->artisan('contratos:revisar-vencimientos');
    $evaluacion = EvaluacionPeriodoPrueba::query()->firstOrFail();

    Carbon::setTestNow(Carbon::parse('2026-07-01 20:00:00', 'America/Mexico_City'));
    Sanctum::actingAs($this->jefeUsuario);

    // "Mañana" en México nunca se acepta, aunque en UTC ya sea hoy.
    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/capturar", [...eppRevisionCaptura(), 'fecha_evaluacion' => '2026-07-02'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fecha_evaluacion');

    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/capturar", eppRevisionCaptura())
        ->assertOk()
        ->assertJsonPath('data.fecha_evaluacion', '2026-07-01');
});

test('las preguntas filtro aceptan 1/0 igual que la validación (antes pasaban y luego «Falta responder»)', function () {
    $this->artisan('contratos:revisar-vencimientos');
    $evaluacion = EvaluacionPeriodoPrueba::query()->firstOrFail();
    Sanctum::actingAs($this->jefeUsuario);

    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/capturar", eppRevisionCaptura(0))
        ->assertOk()
        ->assertJsonPath('data.estado', EstadoEvaluacionPrueba::Capturada->value)
        ->assertJsonPath('data.recomienda_renovar', true)
        ->assertJsonPath('data.filtros.0.respuesta', false);
});

test('devolver valida igual en web y API: motivo obligatorio y hasta 2000 caracteres', function () {
    $this->artisan('contratos:revisar-vencimientos');
    $evaluacion = EvaluacionPeriodoPrueba::query()->firstOrFail();
    Sanctum::actingAs($this->jefeUsuario);
    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/capturar", eppRevisionCaptura())->assertOk();

    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/devolver", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('motivo');

    // 1500 caracteres: la web siempre lo aceptó; la API lo rechazaba (máx. 1000).
    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/devolver", ['motivo' => str_repeat('a', 1500)])
        ->assertOk()
        ->assertJsonPath('data.estado', EstadoEvaluacionPrueba::Devuelta->value);

    $this->postJson("/api/v1/evaluaciones/{$evaluacion->id}/devolver", ['motivo' => str_repeat('a', 2001)])
        ->assertUnprocessable();
});
