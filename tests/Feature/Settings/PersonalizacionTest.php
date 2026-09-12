<?php

use App\Models\User;

test('la pagina de personalizacion se muestra', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('personalizacion.edit'))
        ->assertOk();
});

test('un usuario puede guardar su personalizacion', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('personalizacion'), [
            'tema_color' => 'azul',
            'avatar_color' => '#3B82F6',
            'animaciones' => false,
        ])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->preferencias_ui)->toBe([
        'tema_color' => 'azul',
        'avatar_color' => '#3B82F6',
        'animaciones' => false,
    ]);
});

test('un tema de color fuera del catalogo cerrado se rechaza', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('personalizacion'), [
            'tema_color' => 'dorado',
            'avatar_color' => '#3B82F6',
            'animaciones' => true,
        ])
        ->assertSessionHasErrors('tema_color');
});
