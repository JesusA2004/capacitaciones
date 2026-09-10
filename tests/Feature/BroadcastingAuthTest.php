<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * BROADCAST_CONNECTION=log en pruebas (ver phpunit.xml) no hace ninguna
 * verificacion real de autorizacion (Illuminate\Broadcasting\Broadcasters\LogBroadcaster::auth()
 * es un no-op): aqui se fuerza temporalmente el driver "pusher" — mismo
 * codigo base que "reverb" (App\Http\Controllers usa Broadcast::auth(),
 * agnostico del driver) — para ejercitar de verdad routes/channels.php sin
 * tocar la red (PusherBroadcaster::auth() firma localmente, no llama a
 * ninguna API externa).
 *
 * Broadcast::channel() registra el patron en la instancia del driver POR
 * DEFECTO en el momento en que se llama (routes/channels.php ya corrio
 * contra el driver "log" al arrancar la app de pruebas): cambiar
 * `broadcasting.default` despues no migra ese registro, asi que hay que
 * re-declarar el mismo canal aqui para que quede en la instancia "pusher"
 * recien resuelta.
 */
beforeEach(function () {
    config([
        'broadcasting.default' => 'pusher',
        'broadcasting.connections.pusher.key' => 'test-key',
        'broadcasting.connections.pusher.secret' => 'test-secret',
        'broadcasting.connections.pusher.app_id' => 'test-app',
    ]);

    Broadcast::channel('App.Models.User.{id}', fn ($user, $id) => (int) $user->id === (int) $id);
});

test('un usuario autenticado puede autorizar su propio canal privado', function () {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->postJson('/broadcasting/auth', [
            'channel_name' => "private-App.Models.User.{$usuario->id}",
            'socket_id' => '1234.1234',
        ])
        ->assertOk();
});

test('un usuario no puede autorizar el canal privado de otro', function () {
    $usuario = User::factory()->create();
    $otro = User::factory()->create();

    $this->actingAs($usuario)
        ->postJson('/broadcasting/auth', [
            'channel_name' => "private-App.Models.User.{$otro->id}",
            'socket_id' => '1234.1234',
        ])
        ->assertForbidden();
});

test('un usuario sin sesion no puede autorizar ningun canal', function () {
    $usuario = User::factory()->create();

    $this->postJson('/broadcasting/auth', [
        'channel_name' => "private-App.Models.User.{$usuario->id}",
        'socket_id' => '1234.1234',
    ])->assertForbidden();
});

test('la ruta movil de broadcasting auth funciona con bearer token', function () {
    $usuario = User::factory()->create();

    $this->withHeaders(['Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken])
        ->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => "private-App.Models.User.{$usuario->id}",
            'socket_id' => '1234.1234',
        ])
        ->assertOk();
});
