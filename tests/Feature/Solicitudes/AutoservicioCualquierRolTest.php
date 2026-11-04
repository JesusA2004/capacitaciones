<?php

use App\Models\SolicitudInterna;
use App\Models\User;
use App\Policies\SolicitudInternaPolicy;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

/*
 * Autoservicio propio (SolicitudInternaPolicy::create): toda persona con
 * colaborador vinculado y acceso a «Mi espacio» pide sus vacaciones,
 * permisos y préstamos sin importar su rol operativo. La baja de
 * colaborador sigue con su permiso propio (solicitudes.bajas.crear).
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->travelTo('2026-10-05 09:00:00');
});

/** Dos días hábiles de vacaciones (lunes y martes) a futuro. */
function diasVacacionesAutoservicio(): array
{
    return ['2026-10-19', '2026-10-20'];
}

dataset('roles operativos con colaborador', [
    'gerente', 'gerente_sucursal', 'gerente_regional', 'jefe_directo', 'director_comercial', 'rh_auxiliar', 'auditor',
]);

test('un rol operativo con colaborador vinculado pide sus propias vacaciones en la web', function (string $rol) {
    $usuario = clUsuario($rol, ['fecha_ingreso' => '2023-06-01']);

    $this->actingAs($usuario)
        ->post(route('solicitudes.store'), ['tipo' => 'vacaciones', 'motivo' => 'Descanso', 'dias' => diasVacacionesAutoservicio()])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $solicitud = SolicitudInterna::query()->where('user_id', $usuario->id)->first();

    expect($solicitud)->not->toBeNull()
        ->and($solicitud->tipo->value)->toBe('vacaciones')
        ->and($solicitud->colaborador_id)->toBe($usuario->colaborador_id);
})->with('roles operativos con colaborador');

test('gerente, regional y director piden sus propias vacaciones por la api movil (201)', function (string $rol) {
    $usuario = clUsuario($rol, ['fecha_ingreso' => '2023-06-01']);
    Sanctum::actingAs($usuario);

    $this->postJson('/api/v1/solicitudes', ['tipo' => 'vacaciones', 'motivo' => 'Descanso', 'dias' => diasVacacionesAutoservicio()])
        ->assertCreated();

    expect(SolicitudInterna::query()->where('user_id', $usuario->id)->count())->toBe(1);
})->with(['gerente', 'gerente_regional', 'director_comercial']);

test('/solicitudes manda puedeCrear=true a un gerente con colaborador', function () {
    $this->actingAs(clUsuario('gerente'))
        ->get(route('solicitudes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('puedeCrear', true)
            ->where('motivoNoPuedeCrear', null));
});

test('una cuenta sin colaborador vinculado recibe 403 con el motivo en español por la api', function () {
    $usuario = User::factory()->create(['colaborador_id' => null]);
    $usuario->assignRole('colaborador');
    Sanctum::actingAs($usuario);

    $this->postJson('/api/v1/solicitudes', ['tipo' => 'permiso', 'permiso_tipo' => 'faltar', 'permiso_goce' => 'con_goce', 'fecha_inicio' => '2026-10-14', 'duracion_dias' => 1])
        ->assertForbidden()
        ->assertJsonPath('message', SolicitudInternaPolicy::MENSAJE_SIN_COLABORADOR);

    expect(SolicitudInterna::query()->count())->toBe(0);
});

test('la policy niega con motivo a la cuenta sin colaborador aunque tenga solicitudes.crear', function () {
    // En la web esa cuenta ni siquiera conserva la sesión (EnsureCuentaActiva):
    // la regla se prueba directo en la policy, que también alimenta puedeCrear.
    $usuario = User::factory()->create(['colaborador_id' => null]);
    $usuario->assignRole('colaborador');

    $respuesta = Gate::forUser($usuario)->inspect('create', SolicitudInterna::class);

    expect($respuesta->allowed())->toBeFalse()
        ->and($respuesta->message())->toBe(SolicitudInternaPolicy::MENSAJE_SIN_COLABORADOR);
});
