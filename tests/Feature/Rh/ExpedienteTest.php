<?php

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

    $gerente = User::factory()->create(['sucursal_principal_id' => $sucursalPropia->id]);
    $gerente->assignRole('gerente_sucursal');

    $colaboradorPropio = User::factory()->create(['sucursal_principal_id' => $sucursalPropia->id]);
    $colaboradorAjeno = User::factory()->create(['sucursal_principal_id' => $sucursalAjena->id]);

    $this->actingAs($gerente)->get(route('rh.expedientes.show', $colaboradorPropio))->assertOk();
    $this->actingAs($gerente)->get(route('rh.expedientes.show', $colaboradorAjeno))->assertForbidden();
});

test('un jefe directo ve el expediente de sus subordinados pero no de otros colaboradores', function () {
    $jefe = User::factory()->create();
    $jefe->assignRole('jefe_directo');

    $subordinado = User::factory()->create(['jefe_id' => $jefe->id]);
    $otro = User::factory()->create();

    $this->actingAs($jefe)->get(route('rh.expedientes.show', $subordinado))->assertOk();
    $this->actingAs($jefe)->get(route('rh.expedientes.show', $otro))->assertForbidden();
});

test('un colaborador puede actualizar sus datos personales desde su expediente', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->put(route('rh.expedientes.datos-personales.update', $colaborador), [
            'curp' => 'XAXX010101HNEXXXA4',
            'rfc' => 'XAXX010101000',
            'nss' => '12345678901',
        ])
        ->assertSessionHasNoErrors();

    expect($colaborador->fresh()->curp)->toBe('XAXX010101HNEXXXA4');
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

    $colaborador = User::factory()->create(['foto_path' => 'expedientes/999/foto/secreto.jpg']);

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
    $colaborador = User::factory()->create(['foto_path' => 'expedientes/1/foto/foto.jpg']);
    $colaborador->assignRole('colaborador');
    Storage::disk('nas')->put($colaborador->foto_path, 'contenido-de-foto');

    $otro = User::factory()->create();
    $otro->assignRole('colaborador');

    $this->actingAs($otro)->get(route('rh.expedientes.foto', $colaborador))->assertForbidden();
    $this->actingAs($colaborador)->get(route('rh.expedientes.foto', $colaborador))->assertOk();
});
