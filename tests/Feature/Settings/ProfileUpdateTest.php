<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('el correo del perfil se puede actualizar', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('el nombre propio no se puede editar desde el perfil', function () {
    $user = User::factory()->create(['name' => 'Nombre Original']);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Nombre Cambiado',
            'email' => $user->email,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->name)->toBe('Nombre Original');
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('nadie puede eliminar su propia cuenta desde el perfil', function () {
    $user = User::factory()->create();

    expect(Route::has('profile.destroy'))->toBeFalse();

    $this->actingAs($user)
        ->delete('/settings/profile', [
            'password' => 'password',
        ])
        ->assertStatus(405);

    $this->assertAuthenticatedAs($user);
    expect(User::query()->where('id', $user->id)->exists())->toBeTrue();
    expect($user->fresh()->deleted_at)->toBeNull();
});
