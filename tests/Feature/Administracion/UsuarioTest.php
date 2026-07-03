<?php

use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('un administrador puede crear un colaborador y se le envia un enlace para establecer su contraseña', function () {
    Notification::fake();

    $sucursal = Sucursal::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    $this->actingAs($admin)
        ->post(route('administracion.usuarios.store'), [
            'name' => 'Nuevo',
            'apellidos' => 'Colaborador',
            'email' => 'nuevo.colaborador@mrlana.test',
            'sucursal_principal_id' => $sucursal->id,
            'roles' => ['colaborador'],
        ])
        ->assertSessionHasNoErrors();

    $creado = User::where('email', 'nuevo.colaborador@mrlana.test')->first();

    expect($creado)->not->toBeNull();
    expect($creado->hasRole('colaborador'))->toBeTrue();
    expect($creado->sucursal_principal_id)->toBe($sucursal->id);

    Notification::assertSentTo($creado, ResetPassword::class);
});

test('no se puede crear un colaborador sin sucursal principal', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_capacitacion');

    $this->actingAs($admin)
        ->post(route('administracion.usuarios.store'), [
            'name' => 'Sin Sucursal',
            'email' => 'sinsucursal@mrlana.test',
        ])
        ->assertSessionHasErrors('sucursal_principal_id');
});

test('desactivar un colaborador aplica soft delete y cambia su estatus', function () {
    $sucursal = Sucursal::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $colaborador = User::factory()->create(['sucursal_principal_id' => $sucursal->id]);
    $colaborador->assignRole('colaborador');

    $this->actingAs($admin)
        ->delete(route('administracion.usuarios.destroy', $colaborador))
        ->assertRedirect();

    expect(User::find($colaborador->id))->toBeNull();
    expect(User::withTrashed()->find($colaborador->id)->estatus->value)->toBe('inactivo');
});

test('un administrador no puede desactivarse a si mismo', function () {
    $sucursal = Sucursal::factory()->create();
    $admin = User::factory()->create(['sucursal_principal_id' => $sucursal->id]);
    $admin->assignRole('super_admin');

    $this->actingAs($admin)
        ->delete(route('administracion.usuarios.destroy', $admin))
        ->assertForbidden();
});
