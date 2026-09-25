<?php

use App\Enums\TipoCelebracion;
use App\Models\BirthdayGreeting;
use App\Models\BirthdayWallMessage;
use App\Models\Colaborador;
use App\Models\MobileDevice;
use App\Models\Sucursal;
use App\Models\User;
use App\Notifications\Mobile\CelebracionNotification;
use App\Services\Celebraciones\AniversariosService;
use App\Services\Celebraciones\CelebracionService;
use App\Services\Celebraciones\FechasCelebracion;
use App\Services\Celebraciones\TarjetaAniversarioService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    // 12:00 en México (18:00 UTC): "hoy" es 25/09/2026 en ambas zonas.
    Carbon::setTestNow(Carbon::parse('2026-09-25 18:00:00', 'UTC'));

    $this->sucursal = Sucursal::factory()->create(['nombre' => 'Huamantla']);
    $this->rh = User::factory()->create();
    $this->rh->assignRole('rh_admin');
});

afterEach(fn () => Carbon::setTestNow());

function colaboradorConCuenta(array $atributos = []): User
{
    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => test()->sucursal->id, ...$atributos]);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole('colaborador');

    return $usuario;
}

function aniversarioDeHoy(Colaborador $colaborador): BirthdayGreeting
{
    return app(CelebracionService::class)->delDia($colaborador, TipoCelebracion::AniversarioLaboral);
}

// --- Detección ---------------------------------------------------------------

test('detecta el aniversario de hoy con años exactos y no el de mañana', function () {
    $roberto = Colaborador::factory()->create(['name' => 'Roberto', 'fecha_ingreso' => '2019-09-25', 'sucursal_principal_id' => $this->sucursal->id]);
    $manana = Colaborador::factory()->create(['fecha_ingreso' => '2019-09-26']);

    $hoy = app(AniversariosService::class)->deHoy();

    expect($hoy)->toHaveCount(1)
        ->and($hoy[0]['colaborador']->id)->toBe($roberto->id)
        ->and($hoy[0]['anios'])->toBe(7)
        ->and(app(AniversariosService::class)->proximos(3)->pluck('colaborador.id')->all())->toContain($manana->id);
});

test('texto singular y plural de años', function () {
    expect(FechasCelebracion::textoAnios(1))->toBe('1 año')
        ->and(FechasCelebracion::textoAnios(6))->toBe('6 años');
});

test('no aparecen inactivos, sin fecha de ingreso ni quien ingresó hoy (0 años)', function () {
    Colaborador::factory()->create(['fecha_ingreso' => '2020-09-25', 'estatus' => 'inactivo']);
    Colaborador::factory()->create(['fecha_ingreso' => null]);
    Colaborador::factory()->create(['fecha_ingreso' => '2026-09-25']);

    expect(app(AniversariosService::class)->deHoy())->toHaveCount(0);
});

test('quien ingresó un 29 de febrero celebra el 28 en años no bisiestos', function () {
    Carbon::setTestNow(Carbon::parse('2027-02-28 18:00:00', 'UTC'));
    $bisiesto = Colaborador::factory()->create(['fecha_ingreso' => '2024-02-29']);

    $hoy = app(AniversariosService::class)->deHoy();

    expect($hoy->pluck('colaborador.id')->all())->toBe([$bisiesto->id])
        ->and($hoy[0]['anios'])->toBe(3);
});

test('"hoy" se calcula en hora de México, no en UTC', function () {
    // 25/09 23:30 en México = 26/09 05:30 UTC.
    Carbon::setTestNow(Carbon::parse('2026-09-26 05:30:00', 'UTC'));

    expect(FechasCelebracion::hoy()->toDateString())->toBe('2026-09-25');
});

// --- Evento e idempotencia ---------------------------------------------------------

test('el scheduler no duplica eventos aunque corra dos veces', function () {
    Colaborador::factory()->create(['fecha_ingreso' => '2020-09-25', 'sucursal_principal_id' => $this->sucursal->id]);

    $this->artisan('celebraciones:preparar')->assertExitCode(0);
    $this->artisan('celebraciones:preparar')->assertExitCode(0);

    $eventos = BirthdayGreeting::query()->where('tipo', 'aniversario_laboral')->get();
    expect($eventos)->toHaveCount(1)
        ->and($eventos[0]->anios)->toBe(6)
        ->and($eventos[0]->card_path)->not->toBeNull();
    Storage::disk('nas')->assertExists($eventos[0]->card_path);
});

test('la tarjeta sale con nombre, años y sucursal dinámicos', function () {
    $colaborador = Colaborador::factory()->create(['name' => 'Roberto', 'apellidos' => 'Galicia Velazquez', 'fecha_ingreso' => '2020-09-25', 'sucursal_principal_id' => $this->sucursal->id]);
    $evento = aniversarioDeHoy($colaborador);
    app(TarjetaAniversarioService::class)->generar($evento);

    $evento->refresh();
    $png = Storage::disk('nas')->get($evento->card_path);
    [$ancho, $alto] = getimagesizefromstring($png);

    expect($ancho)->toBe(1080)->and($alto)->toBe(1350)
        ->and($evento->nombre_mostrado)->toBe('Roberto Galicia Velazquez')
        ->and($evento->frase)->toContain('6 años')
        ->and(app(TarjetaAniversarioService::class)->mensaje(1, 'x', 'y'))->toContain('este primer año');
});

test('RH envía la felicitación al homenajeado: notificación y push a su pantalla', function () {
    Notification::fake();
    $homenajeado = colaboradorConCuenta(['fecha_ingreso' => '2020-09-25']);

    $this->actingAs($this->rh)
        ->post(route('rh.celebraciones.enviar', [$homenajeado->colaborador_id, 'aniversario_laboral']))
        ->assertSessionHasNoErrors();

    $evento = BirthdayGreeting::query()->where('tipo', 'aniversario_laboral')->sole();
    expect($evento->enviada_at)->not->toBeNull()->and($evento->enviada_por_id)->toBe($this->rh->id);

    Notification::assertSentTo($homenajeado, CelebracionNotification::class, function (CelebracionNotification $n) use ($homenajeado, $evento) {
        $datos = $n->toDatabase($homenajeado);

        return $datos['type'] === 'aniversario_laboral'
            && $datos['resource_id'] === $evento->id
            && $datos['related_type'] === 'Celebracion'
            && str_contains($datos['titulo'], '6 años')
            && $datos['url'] === route('celebraciones.show', $evento->id, false);
    });
});

test('avisar a todos es idempotente: el segundo intento no vuelve a notificar', function () {
    Notification::fake();
    $homenajeado = colaboradorConCuenta(['fecha_ingreso' => '2020-09-25']);
    $companero = colaboradorConCuenta();
    MobileDevice::factory()->create(['user_id' => $companero->id]);

    $this->actingAs($this->rh)->post(route('rh.celebraciones.avisar-todos', [$homenajeado->colaborador_id, 'aniversario_laboral']))->assertSessionHasNoErrors();
    $this->actingAs($this->rh)->post(route('rh.celebraciones.avisar-todos', [$homenajeado->colaborador_id, 'aniversario_laboral']))
        ->assertSessionHas('toast', fn (array $t) => str_contains($t['message'], 'Aviso general enviado el'));

    Notification::assertSentToTimes($companero, CelebracionNotification::class, 1);
    Notification::assertNotSentTo($homenajeado, CelebracionNotification::class);

    $evento = BirthdayGreeting::query()->sole();
    expect($evento->avisada_todos_at)->not->toBeNull()
        ->and($evento->muroAbierto())->toBeTrue();
});

test('cumpleaños también se puede avisar a todos y conserva su registro', function () {
    Notification::fake();
    $homenajeado = colaboradorConCuenta(['fecha_nacimiento' => '1990-09-25']);
    colaboradorConCuenta();

    $this->actingAs($this->rh)->post(route('rh.celebraciones.avisar-todos', [$homenajeado->colaborador_id, 'cumpleanos']))->assertSessionHasNoErrors();

    expect(BirthdayGreeting::query()->where('tipo', 'cumpleanos')->sole()->avisada_todos_at)->not->toBeNull();
});

test('sin permiso no se envía ni se avisa', function () {
    $homenajeado = colaboradorConCuenta(['fecha_ingreso' => '2020-09-25']);
    $companero = colaboradorConCuenta();

    $this->actingAs($companero)->post(route('rh.celebraciones.avisar-todos', [$homenajeado->colaborador_id, 'aniversario_laboral']))->assertForbidden();
    expect(BirthdayGreeting::query()->whereNotNull('avisada_todos_at')->count())->toBe(0);
});

// --- Privacidad de mensajes ------------------------------------------------------------

test('cada compañero solo ve su propio mensaje; homenajeado y RH ven todos', function () {
    $homenajeado = colaboradorConCuenta(['fecha_ingreso' => '2020-09-25']);
    $ana = colaboradorConCuenta();
    $pedro = colaboradorConCuenta();
    $evento = aniversarioDeHoy($homenajeado->colaborador);
    $evento->update(['muro_abierto_at' => now()]);

    $this->actingAs($ana, 'sanctum')->postJson(route('api.v1.celebraciones.mensajes.store', $evento), ['mensaje' => 'Muchas felicidades'])->assertCreated();
    $this->actingAs($pedro, 'sanctum')->postJson(route('api.v1.celebraciones.mensajes.store', $evento), ['mensaje' => 'Gracias por todo'])->assertCreated();

    $deAna = $this->actingAs($ana, 'sanctum')->getJson(route('api.v1.celebraciones.mensajes.index', $evento))->assertOk()->json();
    expect($deAna['data'])->toHaveCount(1)
        ->and($deAna['data'][0]['mensaje'])->toBe('Muchas felicidades')
        ->and($deAna['meta']['solo_propio'])->toBeTrue()
        ->and($deAna['meta']['total'])->toBe(1);

    $dePedro = $this->actingAs($pedro, 'sanctum')->getJson(route('api.v1.celebraciones.mensajes.index', $evento))->json('data');
    expect(collect($dePedro)->pluck('mensaje')->all())->toBe(['Gracias por todo']);

    $delHomenajeado = $this->actingAs($homenajeado, 'sanctum')->getJson(route('api.v1.celebraciones.mensajes.index', $evento))->json('data');
    expect($delHomenajeado)->toHaveCount(2);

    $deRh = $this->actingAs($this->rh, 'sanctum')->getJson(route('api.v1.celebraciones.mensajes.index', $evento))->json('data');
    expect($deRh)->toHaveCount(2);

    // Ana no puede ver la foto/autor del mensaje de Pedro por URL directa.
    $mensajePedro = BirthdayWallMessage::query()->where('user_id', $pedro->id)->sole();
    $this->actingAs($ana, 'sanctum')->getJson(route('api.v1.celebraciones.autor-foto', [$evento, $mensajePedro]))->assertNotFound();

    // Y la pantalla web tampoco le manda mensajes ajenos.
    $this->actingAs($ana)->get(route('celebraciones.show', $evento))
        ->assertInertia(fn ($page) => $page->has('mensajes', 1)->where('mensajes.0.mensaje', 'Muchas felicidades'));
});

test('una felicitación por persona, editable por su autor y no por otros', function () {
    $homenajeado = colaboradorConCuenta(['fecha_ingreso' => '2020-09-25']);
    $ana = colaboradorConCuenta();
    $pedro = colaboradorConCuenta();
    $evento = aniversarioDeHoy($homenajeado->colaborador);
    $evento->update(['muro_abierto_at' => now()]);

    $id = $this->actingAs($ana, 'sanctum')->postJson(route('api.v1.celebraciones.mensajes.store', $evento), ['mensaje' => 'Hola'])->json('data.id');
    $this->actingAs($ana, 'sanctum')->postJson(route('api.v1.celebraciones.mensajes.store', $evento), ['mensaje' => 'Otra'])->assertUnprocessable();

    $this->actingAs($ana, 'sanctum')->patchJson(route('api.v1.celebraciones.mensajes.update', [$evento, $id]), ['mensaje' => 'Hola, felicidades'])->assertOk();
    $this->actingAs($pedro, 'sanctum')->patchJson(route('api.v1.celebraciones.mensajes.update', [$evento, $id]), ['mensaje' => 'hackeado'])->assertForbidden();
    $this->actingAs($pedro, 'sanctum')->deleteJson(route('api.v1.celebraciones.mensajes.destroy', [$evento, $id]))->assertForbidden();

    expect(BirthdayWallMessage::query()->findOrFail($id)->mensaje)->toBe('Hola, felicidades');

    // El homenajeado no puede escribirse a sí mismo.
    $this->actingAs($homenajeado, 'sanctum')->postJson(route('api.v1.celebraciones.mensajes.store', $evento), ['mensaje' => 'Yo'])->assertForbidden();
});

test('RH puede moderar (eliminar) un mensaje inapropiado; un colaborador no', function () {
    $homenajeado = colaboradorConCuenta(['fecha_ingreso' => '2020-09-25']);
    $ana = colaboradorConCuenta();
    $evento = aniversarioDeHoy($homenajeado->colaborador);
    $evento->update(['muro_abierto_at' => now()]);
    $id = $this->actingAs($ana, 'sanctum')->postJson(route('api.v1.celebraciones.mensajes.store', $evento), ['mensaje' => 'Algo feo'])->json('data.id');

    $this->actingAs($homenajeado, 'sanctum')->deleteJson(route('api.v1.celebraciones.mensajes.destroy', [$evento, $id]))->assertForbidden();
    $this->actingAs($this->rh, 'sanctum')->deleteJson(route('api.v1.celebraciones.mensajes.destroy', [$evento, $id]))->assertOk();

    expect(BirthdayWallMessage::query()->count())->toBe(0);
});

test('el homenajeado recibe un solo aviso aunque lleguen varios mensajes seguidos', function () {
    Notification::fake();
    $homenajeado = colaboradorConCuenta(['fecha_ingreso' => '2020-09-25']);
    $evento = aniversarioDeHoy($homenajeado->colaborador);
    $evento->update(['muro_abierto_at' => now()]);

    foreach (range(1, 3) as $i) {
        $autor = colaboradorConCuenta();
        $this->actingAs($autor, 'sanctum')->postJson(route('api.v1.celebraciones.mensajes.store', $evento), ['mensaje' => "Mensaje {$i}"])->assertCreated();
    }

    Notification::assertSentToTimes($homenajeado, CelebracionNotification::class, 1);
});

test('las celebraciones activas y el evento están listos para la app', function () {
    $homenajeado = colaboradorConCuenta(['fecha_ingreso' => '2020-09-25', 'name' => 'Roberto']);
    $companero = colaboradorConCuenta();
    $evento = aniversarioDeHoy($homenajeado->colaborador);

    // Sin avisar a todos: solo el homenajeado la ve.
    expect($this->actingAs($companero, 'sanctum')->getJson(route('api.v1.celebraciones.activas'))->json('data'))->toHaveCount(0);
    $this->actingAs($companero, 'sanctum')->getJson(route('api.v1.celebraciones.show', $evento))->assertForbidden();

    $evento->update(['muro_abierto_at' => now()]);

    $data = $this->actingAs($companero, 'sanctum')->getJson(route('api.v1.celebraciones.show', $evento))->assertOk()->json('data');
    expect($data['titulo'])->toBe('Roberto cumple 6 años con MR. LANA')
        ->and($data['puede_escribir'])->toBeTrue()
        ->and($data['puede_ver_todos'])->toBeFalse()
        ->and($data['mensajes_count'])->toBeNull()
        ->and($data['tarjeta_url'])->toContain('/api/v1/celebraciones/')
        ->and(json_encode($data))->not->toContain('celebraciones/aniversarios/');

    $this->actingAs($companero, 'sanctum')->get(route('api.v1.celebraciones.tarjeta', $evento))->assertOk()->assertHeader('Content-Type', 'image/png');
});

test('abrir la notificación lleva a la pantalla de la celebración', function () {
    $homenajeado = colaboradorConCuenta(['fecha_ingreso' => '2020-09-25']);
    $evento = aniversarioDeHoy($homenajeado->colaborador);
    $homenajeado->notify(new CelebracionNotification($evento, 'aniversario_laboral', 'Título', 'Mensaje'));
    $notificacion = $homenajeado->notifications()->sole();

    $respuesta = $this->actingAs($homenajeado, 'sanctum')->postJson(route('api.v1.notificaciones.abrir', $notificacion->id))->assertOk()->json('data');

    expect($respuesta['url'])->toBe(route('celebraciones.show', $evento->id, false))
        ->and($respuesta['no_leidas'])->toBe(0);
});
