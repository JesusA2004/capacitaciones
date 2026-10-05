<?php

use App\Models\Colaborador;
use App\Models\User;
use App\Services\Administracion\GeneradorPasswordService;
use App\Services\Autenticacion\NombreUsuarioService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;

/*
 * Login por nombre de usuario (docs/AUTENTICACION.md): «primer nombre +
 * apellido paterno», tolerante a espacios/mayúsculas; contraseña con solo
 * trim(); correo opcional; contraseña temporal obliga a cambiarla.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->nombres = app(NombreUsuarioService::class);
});

function cuentaJesus(string $password = 'Abc#12xy', array $extra = []): User
{
    $colaborador = Colaborador::factory()->create(['name' => 'Jesús Enrique', 'apellidos' => 'Arizmendi Pérez']);

    return User::factory()->create(['colaborador_id' => $colaborador->id, 'name' => 'Jesús Enrique', 'apellidos' => 'Arizmendi Pérez', 'password' => Hash::make($password), ...$extra]);
}

test('username base: primer nombre + apellido paterno completo, sin materno', function () {
    expect($this->nombres->base('JESUS ENRIQUE', 'ARIZMENDI'))->toBe('Jesus Arizmendi')
        ->and($this->nombres->base('JESUS', 'ARIZMENDI'))->toBe('Jesus Arizmendi')
        ->and($this->nombres->base('MARIA FERNANDA', 'LOPEZ'))->toBe('Maria Lopez')
        ->and($this->nombres->base('JUAN CARLOS', 'DE LA CRUZ'))->toBe('Juan De la Cruz')
        ->and($this->nombres->base('  JOSÉ   ', ' MUÑOZ '))->toBe('Jose Munoz')
        // Solo `apellidos` combinado (cuentas fuera del Excel): paterno = primera palabra + partículas.
        ->and($this->nombres->baseDesdeApellidos('Juan Carlos', 'De la Cruz Hernández'))->toBe('Juan De la Cruz');
});

test('colisiones: Jesus Arizmendi, Jesus Arizmendi2, Jesus Arizmendi3 (determinístico)', function () {
    $primero = User::factory()->create(['colaborador_id' => Colaborador::factory()->create(['name' => 'Jesus', 'apellidos' => 'Arizmendi Perez'])->id]);
    $segundo = User::factory()->create(['colaborador_id' => Colaborador::factory()->create(['name' => 'Jesus Enrique', 'apellidos' => 'Arizmendi Lopez'])->id]);
    $tercero = User::factory()->create(['colaborador_id' => Colaborador::factory()->create(['name' => 'JESÚS', 'apellidos' => 'ARIZMENDI'])->id]);

    expect($primero->username)->toBe('Jesus Arizmendi')
        ->and($segundo->username)->toBe('Jesus Arizmendi2')
        ->and($tercero->username)->toBe('Jesus Arizmendi3')
        ->and($this->nombres->disponible('Jesus Arizmendi'))->toBe('Jesus Arizmendi4');
});

test('username es UNIQUE en BD (sin importar mayúsculas en la validación de la app)', function () {
    User::factory()->create(['username' => 'Jesus Arizmendi']);

    expect(fn () => User::factory()->create(['username' => 'Jesus Arizmendi']))->toThrow(UniqueConstraintViolationException::class)
        ->and($this->nombres->ocupado('JESUS ARIZMENDI'))->toBeTrue();
});

test('email es nullable: se crea la cuenta y se inicia sesión sin correo', function () {
    $usuario = cuentaJesus(extra: ['email' => null]);

    expect($usuario->fresh()->email)->toBeNull();

    $this->post(route('login.store'), ['username' => 'Jesus Arizmendi', 'password' => 'Abc#12xy'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($usuario);
});

test('login web con username correcto', function () {
    $usuario = cuentaJesus();

    $this->post(route('login.store'), ['username' => 'Jesus Arizmendi', 'password' => 'Abc#12xy']);

    $this->assertAuthenticatedAs($usuario);
});

test('login web tolera espacios al inicio/fin, espacios internos múltiples, mayúsculas y acentos en el usuario', function (string $escrito) {
    $usuario = cuentaJesus();

    $this->post(route('login.store'), ['username' => $escrito, 'password' => 'Abc#12xy']);

    $this->assertAuthenticatedAs($usuario);
})->with([
    ' Jesus Arizmendi',
    'Jesus Arizmendi ',
    '  Jesus Arizmendi  ',
    'Jesus    Arizmendi',
    'jesus arizmendi',
    'JESUS ARIZMENDI',
    'Jesús Arizmendi',
]);

test('contraseña con espacios al inicio/fin autentica (solo trim, nada más)', function (string $escrita) {
    $usuario = cuentaJesus();

    $this->post(route('login.store'), ['username' => 'Jesus Arizmendi', 'password' => $escrita]);

    $this->assertAuthenticatedAs($usuario);
})->with([' Abc#12xy', 'Abc#12xy ', '  Abc#12xy  ']);

test('la contraseña no se modifica más allá de trim: mayúsculas y espacios internos cuentan', function (string $escrita) {
    cuentaJesus('Abc #12xy');

    $this->post(route('login.store'), ['username' => 'Jesus Arizmendi', 'password' => $escrita]);

    $this->assertGuest();
})->with(['abc #12xy', 'Abc#12xy', 'ABC #12XY']);

test('contraseña con espacio interno real se conserva y autentica', function () {
    $usuario = cuentaJesus('Abc #12xy');

    $this->post(route('login.store'), ['username' => 'Jesus Arizmendi', 'password' => ' Abc #12xy ']);

    $this->assertAuthenticatedAs($usuario);
});

test('contraseña incorrecta no autentica (web y API)', function () {
    cuentaJesus();

    $this->post(route('login.store'), ['username' => 'Jesus Arizmendi', 'password' => 'Abc#12xz'])
        ->assertSessionHasErrors('username');
    $this->assertGuest();

    $this->postJson(route('api.v1.login'), ['username' => 'Jesus Arizmendi', 'password' => 'Abc#12xz'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('username');
});

test('login API con username (espacios, mayúsculas y contraseña con trim) devuelve token', function () {
    $usuario = cuentaJesus();
    $usuario->assignRole('colaborador');

    $this->postJson(route('api.v1.login'), ['username' => '  jesus   ARIZMENDI ', 'password' => ' Abc#12xy ', 'device_name' => 'qa'])
        ->assertOk()
        ->assertJsonPath('usuario.username', 'Jesus Arizmendi')
        ->assertJsonPath('debe_cambiar_contrasena', false)
        ->assertJsonStructure(['token']);
});

test('API: compatibilidad temporal con email', function () {
    cuentaJesus(extra: ['email' => 'jesus@mrlana.test']);

    $this->postJson(route('api.v1.login'), ['email' => 'jesus@mrlana.test', 'password' => 'Abc#12xy'])->assertOk();
});

test('contraseña temporal generada: exactamente 8 caracteres con mayúscula, minúscula, número y especial controlado', function () {
    $generador = app(GeneradorPasswordService::class);
    $vistas = [];

    for ($i = 0; $i < 300; $i++) {
        $p = $generador->generar();
        $vistas[$p] = true;

        expect(strlen($p))->toBe(8)
            ->and($p)->toMatch('/[A-Z]/')
            ->and($p)->toMatch('/[a-z]/')
            ->and($p)->toMatch('/[0-9]/')
            ->and($p)->toMatch('/[!@#$%&*?]/')
            ->and($p)->toMatch('/^[A-Za-z0-9!@#$%&*?]{8}$/');
    }

    // No es una contraseña fija.
    expect(count($vistas))->toBeGreaterThan(290);
});

test('primer login con contraseña temporal obliga a cambiarla antes de navegar (web)', function () {
    $usuario = cuentaJesus(extra: ['debe_cambiar_contrasena' => true]);
    $usuario->forceFill(['debe_cambiar_contrasena' => true])->save();

    $this->post(route('login.store'), ['username' => 'Jesus Arizmendi', 'password' => 'Abc#12xy']);
    $this->assertAuthenticatedAs($usuario);

    $this->get(route('dashboard'))->assertRedirect(route('contrasena-temporal.edit'));
    $this->get(route('contrasena-temporal.edit'))->assertOk();

    // La misma temporal no se acepta como nueva.
    $this->put(route('contrasena-temporal.update'), ['password' => 'Abc#12xy', 'password_confirmation' => 'Abc#12xy'])
        ->assertSessionHasErrors('password');

    $this->put(route('contrasena-temporal.update'), ['password' => 'NuevaClave#2026', 'password_confirmation' => 'NuevaClave#2026'])
        ->assertRedirect(route('dashboard'));

    expect($usuario->fresh()->debe_cambiar_contrasena)->toBeFalse()
        ->and(Hash::check('NuevaClave#2026', $usuario->fresh()->password))->toBeTrue();
    $this->get(route('dashboard'))->assertOk();
});

test('primer login con contraseña temporal: la API solo deja cambiarla', function () {
    $usuario = cuentaJesus();
    $usuario->forceFill(['debe_cambiar_contrasena' => true])->save();
    $usuario->assignRole('colaborador');

    $token = $this->postJson(route('api.v1.login'), ['username' => 'Jesus Arizmendi', 'password' => 'Abc#12xy'])
        ->assertOk()
        ->assertJsonPath('debe_cambiar_contrasena', true)
        ->json('token');

    $this->withToken($token)->getJson(route('api.v1.mobile.bootstrap'))
        ->assertForbidden()
        ->assertJsonPath('codigo', 'cambio_contrasena_requerido');

    $this->withToken($token)->postJson(route('api.v1.cambiar-contrasena'), [
        'password_actual' => 'Abc#12xy',
        'password' => 'NuevaClave#2026',
        'password_confirmation' => 'NuevaClave#2026',
    ])->assertOk();

    expect($usuario->fresh()->debe_cambiar_contrasena)->toBeFalse();
});

test('el username nunca se cambia solo al editar el nombre del colaborador', function () {
    $usuario = cuentaJesus();
    $usuario->update(['name' => 'Otro', 'apellidos' => 'Nombre']);

    expect($usuario->fresh()->username)->toBe('Jesus Arizmendi');
});
