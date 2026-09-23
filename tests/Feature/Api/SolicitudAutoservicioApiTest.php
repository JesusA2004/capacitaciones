<?php

use App\Models\Colaborador;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Solicitudes\SolicitudesService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
 * Autoservicio del colaborador desde la app: sin solicitud de baja y con el
 * préstamo reducido a monto + motivo. La baja administrativa (RH/Dirección)
 * y el catálogo completo del Portal web no cambian.
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
});

function colaboradorAutoservicio(): User
{
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    return $usuario;
}

test('el catalogo movil del colaborador nunca ofrece baja', function () {
    Sanctum::actingAs(colaboradorAutoservicio());

    $claves = collect($this->getJson('/api/v1/solicitudes/configuracion')->assertOk()->json('tipos'))->pluck('clave');

    expect($claves)->not->toContain('baja_colaborador')
        ->and($claves)->toContain('prestamo')
        ->and($claves)->toContain('vacaciones');
});

test('el prestamo del catalogo movil solo pide monto y motivo', function () {
    Sanctum::actingAs(colaboradorAutoservicio());

    $prestamo = collect($this->getJson('/api/v1/solicitudes/configuracion')->json('tipos'))->firstWhere('clave', 'prestamo');

    expect(collect($prestamo['campos'])->pluck('name')->all())->toBe(['monto_solicitado', 'motivo'])
        ->and($prestamo['permite_adjuntos'])->toBeFalse();
});

test('un colaborador no puede crear una solicitud de baja por la api movil', function () {
    $colaborador = colaboradorAutoservicio();
    Sanctum::actingAs($colaborador);

    $this->postJson('/api/v1/solicitudes', [
        'tipo' => 'baja_colaborador',
        'motivo' => 'Renuncia',
        'colaborador_objetivo_id' => $colaborador->colaborador_id,
        'fecha_efectiva' => now()->addWeek()->toDateString(),
        'tipo_baja' => 'renuncia_voluntaria',
    ])->assertUnprocessable()->assertJsonValidationErrors('tipo');

    $this->postJson('/api/v1/colaborador/solicitudes', ['tipo' => 'baja_colaborador', 'motivo' => 'Renuncia'])
        ->assertUnprocessable();

    expect(SolicitudInterna::count())->toBe(0);
});

test('prestamo con solo monto y motivo se crea y el backend no inventa plazo', function () {
    Sanctum::actingAs(colaboradorAutoservicio());

    $id = $this->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'monto_solicitado' => 8000, 'motivo' => 'Reparación del auto'])
        ->assertCreated()
        ->json('id');

    $solicitud = SolicitudInterna::findOrFail($id);
    expect((float) $solicitud->monto_solicitado)->toBe(8000.0)
        ->and($solicitud->plazo_meses)->toBeNull();
});

test('un plazo que mande una version vieja de la app se descarta', function () {
    Sanctum::actingAs(colaboradorAutoservicio());

    $id = $this->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'monto_solicitado' => 5000, 'motivo' => 'Gastos', 'plazo_meses' => 12])
        ->assertCreated()
        ->json('id');

    expect(SolicitudInterna::findOrFail($id)->plazo_meses)->toBeNull();
});

test('prestamo sin monto o sin motivo es 422', function () {
    Sanctum::actingAs(colaboradorAutoservicio());

    $this->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'motivo' => 'Gastos'])->assertUnprocessable()->assertJsonValidationErrors('monto_solicitado');
    $this->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'monto_solicitado' => 1000])->assertUnprocessable()->assertJsonValidationErrors('motivo');
    $this->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'monto_solicitado' => 0, 'motivo' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('monto_solicitado');
});

test('el catalogo del Portal web conserva la baja administrativa', function () {
    $claves = collect(app(SolicitudesService::class)->tiposConFormulario())->pluck('clave');

    expect($claves)->toContain('baja_colaborador');
});

test('el colaborador ve el avance de su prestamo sin datos internos de RH', function () {
    $jefe = User::factory()->create();
    $jefe->assignRole('jefe_directo');
    $colaborador = Colaborador::factory()->create(['jefe_id' => $jefe->colaborador_id]);
    $cuenta = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $cuenta->assignRole('colaborador');
    Sanctum::actingAs($cuenta);

    $id = $this->postJson('/api/v1/solicitudes', ['tipo' => 'prestamo', 'monto_solicitado' => 8000, 'motivo' => 'Reparación'])->assertCreated()->json('id');

    $prestamo = $this->getJson("/api/v1/solicitudes/{$id}")->assertOk()->json('prestamo') ?? $this->getJson("/api/v1/solicitudes/{$id}")->json('data.prestamo');

    expect($prestamo['monto_solicitado'])->toEqual(8000)
        ->and($prestamo['prestamo_id'])->toBeNull()
        ->and(collect($prestamo['etapas'])->pluck('estado', 'clave')->all())->toBe([
            'solicitud' => 'hecho',
            'visto_bueno' => 'actual',
            'autorizacion' => 'pendiente',
            'firma' => 'pendiente',
        ]);

    Sanctum::actingAs($jefe);
    $this->postJson("/api/v1/equipo/solicitudes/{$id}/visto-bueno", ['aprobado' => true])->assertOk();

    Sanctum::actingAs($cuenta);
    $despues = $this->getJson("/api/v1/solicitudes/{$id}")->json('prestamo') ?? $this->getJson("/api/v1/solicitudes/{$id}")->json('data.prestamo');
    expect(collect($despues['etapas'])->pluck('estado', 'clave')->all())->toMatchArray(['visto_bueno' => 'hecho', 'autorizacion' => 'actual']);
});

test('una solicitud que no es prestamo no trae bloque de seguimiento', function () {
    Sanctum::actingAs(colaboradorAutoservicio());

    $id = $this->postJson('/api/v1/solicitudes', ['tipo' => 'solicitud_general', 'motivo' => 'Duda'])->assertCreated()->json('id');
    $json = $this->getJson("/api/v1/solicitudes/{$id}")->assertOk()->json();

    expect($json['prestamo'] ?? $json['data']['prestamo'] ?? null)->toBeNull();
});
