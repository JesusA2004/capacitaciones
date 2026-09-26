<?php

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\User;

test('un usuario inactivo no puede iniciar sesión por la web', function () {
    // El estatus vive en Colaborador (la persona), no en la cuenta: es lo
    // que revisa FortifyServiceProvider::authenticateUsing().
    $usuario = User::factory()->create(['colaborador_id' => Colaborador::factory()->create(['estatus' => EstadoUsuario::Inactivo])->id]);

    $respuesta = $this->post(route('login.store'), [
        'email' => $usuario->email,
        'password' => 'password',
    ]);

    $respuesta->assertSessionHasErrors();
    $this->assertGuest();
});

test('un usuario activo sí puede iniciar sesión por la web', function () {
    $usuario = User::factory()->create(['estatus' => EstadoUsuario::Activo]);

    $respuesta = $this->post(route('login.store'), [
        'email' => $usuario->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($usuario);
});
