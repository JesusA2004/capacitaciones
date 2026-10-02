<?php

use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoSolicitudInterna;
use App\Models\SolicitudInterna;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;

/*
 * Vacaciones: UN solo flujo (motor unificado de Solicitudes: visto bueno
 * gerente → regional → autorización RH). Ningún camino legacy permite que un
 * gerente las apruebe en definitiva.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
});

function tokenDe(User $u): array
{
    auth()->forgetGuards();

    return ['Authorization' => 'Bearer '.$u->createToken('t')->plainTextToken];
}

test('el endpoint legacy de crear vacaciones las manda al motor unificado', function () {
    $colaborador = clUsuario('colaborador', ['fecha_ingreso' => now()->subYears(3)]);

    $respuesta = $this->withHeaders(tokenDe($colaborador))->postJson('/api/v1/vacaciones/solicitudes', [
        'fecha_inicio' => now()->addDays(10)->toDateString(),
        'fecha_fin' => now()->addDays(11)->toDateString(),
        'dias_solicitados' => 2,
        'comentario' => 'Viaje familiar',
    ])->assertCreated()->assertJsonPath('estado', 'pendiente');

    $solicitud = SolicitudInterna::query()->findOrFail($respuesta->json('solicitud_id'));
    expect($solicitud->tipo)->toBe(TipoSolicitudInterna::Vacaciones)
        ->and($solicitud->estado)->not->toBe(EstadoSolicitudInterna::Aprobada)
        ->and(SolicitudVacaciones::query()->count())->toBe(0);
});

test('un gerente no puede aprobar en definitiva una vacación legacy; RH sí', function () {
    $gerente = clUsuario('gerente_sucursal');
    $rh = clUsuario('rh_admin');
    $vacacion = SolicitudVacaciones::factory()->create(['estado' => 'pendiente']);

    $this->withHeaders(tokenDe($gerente))
        ->postJson("/api/v1/rh/vacaciones/{$vacacion->id}/aprobar")
        ->assertForbidden();

    expect($vacacion->fresh()->estado->value)->toBe('pendiente');

    $this->withHeaders(tokenDe($rh))
        ->postJson("/api/v1/rh/vacaciones/{$vacacion->id}/aprobar")
        ->assertOk()
        ->assertJsonPath('data.estado', 'aprobada');
});
