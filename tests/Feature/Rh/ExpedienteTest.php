<?php

use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoSolicitudInterna;
use App\Models\Colaborador;
use App\Models\SolicitudInterna;
use App\Models\SolicitudVacaciones;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('un colaborador solo puede ver su propio expediente', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $otro = User::factory()->create();

    $this->actingAs($colaborador)->get(route('mi-expediente'))->assertOk();
    $this->actingAs($colaborador)->get(route('rh.expedientes.show', $otro))->assertForbidden();
});

test('un colaborador dado de baja sigue siendo visible en expedientes y super_admin puede reactivarlo desde ahi', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $sucursal = Sucursal::factory()->create();
    $colaborador = User::factory()->create(['sucursal_principal_id' => $sucursal->id]);
    $colaborador->assignRole('colaborador');

    $this->actingAs($admin)
        ->delete(route('administracion.usuarios.destroy', $colaborador))
        ->assertRedirect();

    // rh_admin todavia puede abrir el expediente de una baja (antes daba 404
    // porque la ruta no soportaba soft-deleted).
    $this->actingAs($rh)
        ->get(route('rh.expedientes.show', $colaborador->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('colaborador.deleted_at', fn ($v) => $v !== null)
            ->where('puedeReactivar', false)
        );

    $this->actingAs($admin)
        ->post(route('administracion.usuarios.reactivar', $colaborador->id))
        ->assertSessionHasNoErrors();

    $colaborador = User::findOrFail($colaborador->id);
    expect($colaborador->trashed())->toBeFalse()
        ->and($colaborador->estatus->value)->toBe('activo');

    $this->actingAs($admin)
        ->get(route('rh.expedientes.index'))
        ->assertInertia(fn ($page) => $page
            ->where('colaboradores.data', fn ($lista) => collect($lista)->pluck('id')->contains($colaborador->id))
        );
});

test('el expediente muestra el historial de vacaciones desde solicitudes_internas, no desde la tabla legacy', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $solicitudUnificada = SolicitudInterna::factory()->create([
        'user_id' => $colaborador->id,
        'tipo' => TipoSolicitudInterna::Vacaciones,
        'estado' => EstadoSolicitudInterna::Aprobada,
        'fecha_inicio' => now()->addDays(10)->toDateString(),
        'fecha_fin' => now()->addDays(15)->toDateString(),
        'dias_solicitados' => 5,
    ]);

    // Un registro legacy no debe aparecer: es historial congelado de antes
    // de la unificación (docs/SOLICITUDES_UNIFICADAS.md), no la fuente
    // vigente de nuevas solicitudes.
    SolicitudVacaciones::factory()->create(['user_id' => $colaborador->id]);

    $this->actingAs($colaborador)->get(route('mi-expediente'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('solicitudesVacaciones', 1)
            ->where('solicitudesVacaciones.0.id', $solicitudUnificada->id)
            ->where('solicitudesVacaciones.0.dias_solicitados', 5)
        );
});

test('un rh_admin ve el listado de expedientes de toda la organizacion', function () {
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $sucursalA = Sucursal::factory()->create();
    $sucursalB = Sucursal::factory()->create();
    User::factory()->create(['sucursal_principal_id' => $sucursalA->id]);
    User::factory()->create(['sucursal_principal_id' => $sucursalB->id]);

    $respuesta = $this->actingAs($admin)->get(route('rh.expedientes.index'))->assertOk();

    $respuesta->assertInertia(function ($page) {
        expect($page->toArray()['props']['colaboradores']['total'])->toBeGreaterThanOrEqual(3);
    });
});

test('un gerente de sucursal solo ve expedientes de su propia sucursal', function () {
    $sucursalPropia = Sucursal::factory()->create();
    $sucursalAjena = Sucursal::factory()->create();

    $gerente = User::factory()->create(['colaborador_id' => Colaborador::factory()->create(['sucursal_principal_id' => $sucursalPropia->id])]);
    $gerente->assignRole('gerente_sucursal');

    $colaboradorPropio = Colaborador::factory()->create(['sucursal_principal_id' => $sucursalPropia->id]);
    $colaboradorAjeno = Colaborador::factory()->create(['sucursal_principal_id' => $sucursalAjena->id]);

    $this->actingAs($gerente)->get(route('rh.expedientes.show', $colaboradorPropio))->assertOk();
    $this->actingAs($gerente)->get(route('rh.expedientes.show', $colaboradorAjeno))->assertForbidden();
});

test('un jefe directo ve el expediente de sus subordinados pero no de otros colaboradores', function () {
    $jefe = User::factory()->create();
    $jefe->assignRole('jefe_directo');

    $subordinado = Colaborador::factory()->create(['jefe_id' => $jefe->colaborador_id]);
    $otro = Colaborador::factory()->create();

    $this->actingAs($jefe)->get(route('rh.expedientes.show', $subordinado))->assertOk();
    $this->actingAs($jefe)->get(route('rh.expedientes.show', $otro))->assertForbidden();
});

test('un colaborador puede actualizar sus datos personales desde su expediente', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->put(route('rh.expedientes.datos-personales.update', $colaborador->colaborador_id), [
            'curp' => 'XAXX010101HNEXXXA4',
            'rfc' => 'XAXX010101000',
            'nss' => '12345678901',
        ])
        ->assertSessionHasNoErrors();

    expect($colaborador->fresh()->colaborador->curp)->toBe('XAXX010101HNEXXXA4');
});

test('un colaborador no puede editar los datos personales de otro colaborador', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $otro = User::factory()->create();
    $otro->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->put(route('rh.expedientes.datos-personales.update', $otro), ['curp' => 'XAXX010101HNEXXXA4'])
        ->assertForbidden();
});

test('el listado y el detalle de expedientes nunca exponen la ruta fisica de la foto en el disco NAS', function () {
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $colaborador = Colaborador::factory()->create(['foto_path' => 'expedientes/999/foto/secreto.jpg']);

    $respuestaIndex = $this->actingAs($admin)->get(route('rh.expedientes.index'))->assertOk();
    $respuestaIndex->assertInertia(function ($page) {
        expect(json_encode($page->toArray()['props']['colaboradores']['data']))->not->toContain('expedientes/999/foto');
    });

    $respuestaShow = $this->actingAs($admin)->get(route('rh.expedientes.show', $colaborador))->assertOk();
    $respuestaShow->assertInertia(function ($page) use ($colaborador) {
        expect(json_encode($page->toArray()['props']['colaborador']))
            ->not->toContain('expedientes/999/foto')
            ->and($page->toArray()['props']['colaborador']['foto_url'])
            ->toBe(route('rh.expedientes.foto', $colaborador));
    });
});

test('la foto de un colaborador solo la puede ver quien tiene acceso a su expediente', function () {
    $persona = Colaborador::factory()->create(['foto_path' => 'expedientes/1/foto/foto.jpg']);
    $colaborador = User::factory()->create(['colaborador_id' => $persona->id]);
    $colaborador->assignRole('colaborador');
    Storage::disk('nas')->put($persona->foto_path, 'contenido-de-foto');

    $otro = User::factory()->create();
    $otro->assignRole('colaborador');

    $this->actingAs($otro)->get(route('rh.expedientes.foto', $persona))->assertForbidden();
    $this->actingAs($colaborador)->get(route('rh.expedientes.foto', $persona))->assertOk();
});
