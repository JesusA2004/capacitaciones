<?php

use App\Enums\EstadoUsuario;
use App\Jobs\SendExpoPushJob;
use App\Models\BirthdayGreeting;
use App\Models\BirthdayWallMessage;
use App\Models\MobileDevice;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // "Hoy" es America/Mexico_City: reloj fijo a mediodía en México para que
    // las fechas armadas con now() no fallen de noche (en UTC ya es mañana).
    Carbon::setTestNow(Carbon::parse('2026-09-25 18:00:00', 'UTC'));
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Storage::fake(config('cumpleanos.disk'));
    Queue::fake();
});

afterEach(fn () => Carbon::setTestNow());

function bearerMuro(User $usuario): array
{
    // Varias cuentas en la misma prueba: sin esto el guard reutiliza al primer usuario autenticado.
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken];
}

function greetingDeHoy(User $cumpleanero, array $extra = []): BirthdayGreeting
{
    return BirthdayGreeting::factory()->create(array_merge([
        'user_id' => $cumpleanero->id,
        'colaborador_id' => $cumpleanero->colaborador_id,
        'fecha' => now()->toDateString(),
    ], $extra));
}

function rhMuro(): User
{
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    return $rh;
}

function colaboradorMuro(): User
{
    $u = User::factory()->create();
    $u->assignRole('colaborador');

    return $u;
}

test('rh abre el muro y se avisa por push a los compañeros con app (al cumpleañero se le felicita aparte)', function () {
    $rh = rhMuro();
    $cumpleanero = colaboradorMuro();
    $companero = colaboradorMuro();
    MobileDevice::factory()->for($cumpleanero, 'usuario')->create(['push_token' => 'ExponentPushToken[cumple]']);
    MobileDevice::factory()->for($companero, 'usuario')->create(['push_token' => 'ExponentPushToken[companero]']);
    $greeting = greetingDeHoy($cumpleanero);

    $this->withHeaders(bearerMuro($rh))
        ->postJson("/api/v1/rh/cumpleanos/{$greeting->id}/muro/abrir")
        ->assertOk()
        ->assertJsonPath('data.abierto', true);

    expect($greeting->refresh()->muroAbierto())->toBeTrue();
    // Solo el compañero: el homenajeado recibe su propia felicitación con
    // "Enviar al colaborador" (CelebracionService), no el aviso general.
    Queue::assertPushed(SendExpoPushJob::class, 1);

    // Reabrir no vuelve a notificar a toda la empresa.
    $this->withHeaders(bearerMuro($rh))->postJson("/api/v1/rh/cumpleanos/{$greeting->id}/muro/abrir")->assertOk();
    Queue::assertPushed(SendExpoPushJob::class, 1);
});

test('un colaborador no puede abrir ni cerrar un muro', function () {
    $greeting = greetingDeHoy(colaboradorMuro());
    $colaborador = colaboradorMuro();

    $this->withHeaders(bearerMuro($colaborador))->postJson("/api/v1/rh/cumpleanos/{$greeting->id}/muro/abrir")->assertForbidden();
    $this->withHeaders(bearerMuro($colaborador))->postJson("/api/v1/rh/cumpleanos/{$greeting->id}/muro/cerrar")->assertForbidden();
});

test('el listado solo incluye muros abiertos por rh y nunca el anio de nacimiento', function () {
    $abierto = greetingDeHoy(colaboradorMuro(), ['muro_abierto_at' => now()]);
    greetingDeHoy(colaboradorMuro());

    $respuesta = $this->withHeaders(bearerMuro(colaboradorMuro()))
        ->getJson('/api/v1/cumpleanos/muros')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'fecha', 'es_hoy', 'abierto', 'mensajes_count', 'es_mi_muro', 'cumpleanero' => ['id', 'nombre', 'puesto', 'sucursal', 'foto_url']]]]);

    expect(collect($respuesta->json('data'))->pluck('id')->all())->toBe([$abierto->id]);
    expect($respuesta->getContent())->not->toContain('fecha_nacimiento');
});

test('un muro que rh no abrio responde 404', function () {
    $greeting = greetingDeHoy(colaboradorMuro());

    $this->withHeaders(bearerMuro(colaboradorMuro()))->getJson("/api/v1/cumpleanos/muros/{$greeting->id}")->assertNotFound();
});

test('un compañero deja mensaje y foto; la foto se sirve por streaming sin exponer la ruta', function () {
    $greeting = greetingDeHoy(colaboradorMuro(), ['muro_abierto_at' => now()]);
    $autor = colaboradorMuro();

    $respuesta = $this->withHeaders(bearerMuro($autor))
        ->post("/api/v1/cumpleanos/muros/{$greeting->id}/mensajes", [
            'mensaje' => '¡Feliz cumpleaños! 🎉',
            'foto' => UploadedFile::fake()->image('fiesta.jpg', 800, 600),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.mensaje', '¡Feliz cumpleaños! 🎉')
        ->assertJsonPath('data.es_mio', true);

    expect($respuesta->getContent())->not->toContain('cumpleanos/muro/');
    $mensaje = BirthdayWallMessage::firstOrFail();
    Storage::disk(config('cumpleanos.disk'))->assertExists($mensaje->foto_path);

    // Privacidad (docs/CELEBRACIONES.md): el autor ve su foto; otro
    // compañero no ve mensajes ni fotos ajenas.
    $this->withHeaders(bearerMuro($autor))
        ->get("/api/v1/cumpleanos/muros/{$greeting->id}/mensajes/{$mensaje->id}/foto")
        ->assertOk();

    $this->withHeaders(bearerMuro(colaboradorMuro()))
        ->get("/api/v1/cumpleanos/muros/{$greeting->id}/mensajes/{$mensaje->id}/foto")
        ->assertNotFound();

    $this->withHeaders(bearerMuro(colaboradorMuro()))
        ->getJson("/api/v1/cumpleanos/muros/{$greeting->id}/mensajes")
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

test('un mensaje vacio sin foto es 422 y un muro cerrado ya no acepta mensajes', function () {
    $greeting = greetingDeHoy(colaboradorMuro(), ['muro_abierto_at' => now()]);
    $autor = colaboradorMuro();

    $this->withHeaders(bearerMuro($autor))
        ->postJson("/api/v1/cumpleanos/muros/{$greeting->id}/mensajes", ['mensaje' => '   '])
        ->assertUnprocessable();

    $greeting->forceFill(['muro_cerrado_at' => now()])->save();

    $this->withHeaders(bearerMuro($autor))
        ->postJson("/api/v1/cumpleanos/muros/{$greeting->id}/mensajes", ['mensaje' => 'Tarde pero seguro'])
        ->assertUnprocessable();
});

test('solo el autor o rh pueden borrar un mensaje', function () {
    $greeting = greetingDeHoy(colaboradorMuro(), ['muro_abierto_at' => now()]);
    $autor = colaboradorMuro();
    $mensaje = BirthdayWallMessage::create(['birthday_greeting_id' => $greeting->id, 'user_id' => $autor->id, 'mensaje' => 'Hola']);

    $this->withHeaders(bearerMuro(colaboradorMuro()))
        ->deleteJson("/api/v1/cumpleanos/muros/{$greeting->id}/mensajes/{$mensaje->id}")
        ->assertForbidden();

    $this->withHeaders(bearerMuro($autor))
        ->deleteJson("/api/v1/cumpleanos/muros/{$greeting->id}/mensajes/{$mensaje->id}")
        ->assertOk();

    $otro = BirthdayWallMessage::create(['birthday_greeting_id' => $greeting->id, 'user_id' => $autor->id, 'mensaje' => 'Otro']);
    $this->withHeaders(bearerMuro(rhMuro()))
        ->deleteJson("/api/v1/cumpleanos/muros/{$greeting->id}/mensajes/{$otro->id}")
        ->assertOk();

    expect(BirthdayWallMessage::count())->toBe(0);
});

test('un colaborador dado de baja no participa en el muro', function () {
    $greeting = greetingDeHoy(colaboradorMuro(), ['muro_abierto_at' => now()]);
    $baja = colaboradorMuro();
    $baja->colaborador->forceFill(['estatus' => EstadoUsuario::Inactivo->value])->save();

    $this->withHeaders(bearerMuro($baja))->getJson('/api/v1/cumpleanos/muros')->assertForbidden();
});

test('el detalle rh incluye el estado del muro', function () {
    $rh = rhMuro();
    $greeting = greetingDeHoy(colaboradorMuro());

    $this->withHeaders(bearerMuro($rh))
        ->getJson("/api/v1/rh/cumpleanos/{$greeting->id}")
        ->assertOk()
        ->assertJsonPath('data.muro.publicado', false)
        ->assertJsonPath('data.muro.puede_gestionar', true);
});
