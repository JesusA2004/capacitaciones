<?php

use App\Models\Colaborador;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Solicitudes\SolicitudesService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

function tokenBandeja(User $usuario): array
{
    return ['Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken];
}

test('la API tampoco aprueba ni rechaza una baja: se gestiona en el Cierre laboral', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $solicitud = SolicitudInterna::factory()->create([
        'tipo' => 'baja_colaborador',
        'estado' => 'en_revision',
        'objetivo_colaborador_id' => Colaborador::factory()->create()->id,
        'fecha_efectiva' => now()->addWeek(),
        'tipo_baja' => 'renuncia',
    ]);

    $this->withHeaders(tokenBandeja($rh))
        ->postJson("/api/v1/rh/solicitudes/{$solicitud->id}/aprobar")
        ->assertStatus(422)
        ->assertJsonPath('errors.estado.0', 'La baja se gestiona en el Cierre laboral del colaborador.');

    $this->withHeaders(tokenBandeja($rh))
        ->postJson("/api/v1/rh/solicitudes/{$solicitud->id}/rechazar", ['motivo' => 'No procede.'])
        ->assertStatus(422)
        ->assertJsonPath('errors.estado.0', 'La baja se gestiona en el Cierre laboral del colaborador.');

    $this->withHeaders(tokenBandeja($rh))
        ->patchJson("/api/v1/rh/solicitudes/{$solicitud->id}/estado", ['estado' => 'aprobada'])
        ->assertStatus(422);

    // La app tampoco la ofrece como accionable.
    $this->withHeaders(tokenBandeja($rh))
        ->getJson("/api/v1/rh/solicitudes/{$solicitud->id}")
        ->assertOk()
        ->assertJsonPath('data.acciones_permitidas', ['ver']);

    expect($solicitud->fresh()->estado->value)->toBe('en_revision');
});

test('el detalle manda puedeAprobar=false a quien solo puede revisar', function () {
    $auxiliar = User::factory()->create();
    $auxiliar->assignRole('rh_auxiliar');

    $solicitud = SolicitudInterna::factory()->create(['estado' => 'enviada']);

    $this->actingAs($auxiliar)
        ->get(route('rh.solicitudes.show', $solicitud))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Rh/Solicitudes/Show')
            ->where('puedeRevisar', true)
            ->where('puedeAprobar', false)
            ->where('puedeRechazar', false)
            ->where('bajaEnCierre', null));
});

test('el detalle de una solicitud propia no ofrece revisar, aprobar ni rechazar', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $solicitud = SolicitudInterna::factory()->create(['user_id' => $rh->id, 'estado' => 'enviada']);

    $this->actingAs($rh)
        ->get(route('rh.solicitudes.show', $solicitud))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->where('puedeRevisar', false)
            ->where('puedeAprobar', false)
            ->where('puedeRechazar', false));
});

test('el tablero manda las acciones permitidas por tarjeta', function () {
    $auxiliar = User::factory()->create();
    $auxiliar->assignRole('rh_auxiliar');

    SolicitudInterna::factory()->create(['estado' => 'enviada']);

    $this->actingAs($auxiliar)
        ->get(route('rh.solicitudes.index', ['todas' => 1]))
        ->assertOk()
        ->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Rh/Solicitudes/Index')
            ->where('solicitudes.0.acciones.revisar', true)
            ->where('solicitudes.0.acciones.aprobar', false)
            ->where('solicitudes.0.acciones.rechazar', false));
});

test('nadie aprueba su propia solicitud por la API (misma regla que la web)', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $propia = SolicitudInterna::factory()->create(['user_id' => $rh->id, 'estado' => 'en_revision']);

    $this->withHeaders(tokenBandeja($rh))
        ->postJson("/api/v1/rh/solicitudes/{$propia->id}/aprobar")
        ->assertForbidden();

    $this->withHeaders(tokenBandeja($rh))
        ->patchJson("/api/v1/rh/solicitudes/{$propia->id}/estado", ['estado' => 'aprobada'])
        ->assertForbidden();

    expect($propia->fresh()->estado->value)->toBe('en_revision');
});

test('el servicio rechaza la autoaprobación aunque la llamada no pase por la policy', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $propia = SolicitudInterna::factory()->create(['user_id' => $rh->id, 'estado' => 'en_revision']);

    expect(fn () => app(SolicitudesService::class)->aprobar($propia, $rh))
        ->toThrow(ValidationException::class, 'No puedes revisar, aprobar ni rechazar tu propia solicitud.');

    expect($propia->fresh()->estado->value)->toBe('en_revision');
});

test('director_comercial conserva la consulta de solicitudes en la app (rh.solicitudes.*)', function () {
    $director = User::factory()->create();
    $director->assignRole('director_comercial');

    $solicitud = SolicitudInterna::factory()->create(['estado' => 'enviada']);

    $this->withHeaders(tokenBandeja($director))
        ->getJson("/api/v1/rh/solicitudes/{$solicitud->id}")
        ->assertOk()
        ->assertJsonPath('data.acciones_permitidas', ['ver']);

    $this->withHeaders(tokenBandeja($director))
        ->postJson("/api/v1/rh/solicitudes/{$solicitud->id}/aprobar")
        ->assertForbidden();
});

test('Mis pendientes (web) responde 403 a quien no tiene ninguno de los permisos del menú', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->get(route('rh.pendientes.index'))
        ->assertForbidden();

    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $this->actingAs($rh)
        ->get(route('rh.pendientes.index'))
        ->assertOk();
});
