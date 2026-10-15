<?php

use App\Enums\TipoCelebracion;
use App\Models\BirthdayGreeting;
use App\Models\BirthdayWallMessage;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Celebraciones\CelebracionService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| Regla definitiva del muro (cierre 2026-10-15):
|  - antes de que RH lo abra: no está disponible y nadie escribe;
|  - abierto: los compañeros dejan su felicitación;
|  - cerrado: todo lo publicado sigue visible, pero nadie escribe, edita ni
|    borra lo suyo (RH sigue pudiendo moderar).
| Igual en web, API nueva y API legacy.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-09-25 18:00:00', 'UTC'));

    $homenajeado = Colaborador::factory()->create(['fecha_nacimiento' => '1990-09-25']);
    $this->greeting = app(CelebracionService::class)->delDia($homenajeado, TipoCelebracion::Cumpleanos);

    $persona = Colaborador::factory()->create();
    $this->companero = User::factory()->create(['colaborador_id' => $persona->id]);
    $this->companero->assignRole('colaborador');
});

afterEach(fn () => Carbon::setTestNow());

function muroPublicar(BirthdayGreeting $g, User $u, string $texto = '¡Felicidades!')
{
    return test()->actingAs($u)->post(route('celebraciones.mensajes.store', $g), ['mensaje' => $texto]);
}

test('nunca abierto: no se ve ni se publica (web, API nueva y legacy)', function () {
    expect($this->greeting->muroPublicado())->toBeFalse();

    $this->actingAs($this->companero)->get(route('celebraciones.show', $this->greeting))->assertForbidden();
    muroPublicar($this->greeting, $this->companero)->assertForbidden();

    Sanctum::actingAs($this->companero);
    $this->postJson("/api/v1/celebraciones/{$this->greeting->id}/mensajes", ['mensaje' => 'Hola'])->assertForbidden();
    $this->postJson("/api/v1/cumpleanos/muros/{$this->greeting->id}/mensajes", ['mensaje' => 'Hola'])->assertNotFound();

    expect(BirthdayWallMessage::query()->count())->toBe(0);
});

test('abierto: el compañero publica una vez y puede editar', function () {
    $this->greeting->update(['muro_abierto_at' => now()]);

    muroPublicar($this->greeting, $this->companero)->assertSessionHasNoErrors();
    $mensaje = BirthdayWallMessage::query()->firstOrFail();

    $this->actingAs($this->companero)->put(route('celebraciones.mensajes.update', [$this->greeting, $mensaje]), ['mensaje' => 'Muchas felicidades'])
        ->assertSessionHasNoErrors();

    expect($mensaje->refresh()->mensaje)->toBe('Muchas felicidades');
});

test('cerrado después de abierto: se sigue leyendo, pero nadie publica, edita ni borra lo suyo', function () {
    $this->greeting->update(['muro_abierto_at' => now()]);
    muroPublicar($this->greeting, $this->companero)->assertSessionHasNoErrors();
    $mensaje = BirthdayWallMessage::query()->firstOrFail();

    $this->greeting->update(['muro_cerrado_at' => now()]);
    $otro = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $otro->assignRole('colaborador');

    $this->actingAs($this->companero)->get(route('celebraciones.show', $this->greeting))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('celebracion.puede_escribir', false)
            ->where('celebracion.recepcion_cerrada', true)
            ->where('celebracion.mi_mensaje.puede_editar', false)
            ->where('celebracion.mi_mensaje.puede_eliminar', false));

    muroPublicar($this->greeting, $otro)->assertForbidden();
    $this->actingAs($this->companero)->put(route('celebraciones.mensajes.update', [$this->greeting, $mensaje]), ['mensaje' => 'cambio'])
        ->assertSessionHasErrors('mensaje');
    $this->actingAs($this->companero)->delete(route('celebraciones.mensajes.destroy', [$this->greeting, $mensaje]))->assertForbidden();

    Sanctum::actingAs($otro);
    $this->postJson("/api/v1/cumpleanos/muros/{$this->greeting->id}/mensajes", ['mensaje' => 'tarde'])->assertUnprocessable();
    $this->getJson("/api/v1/cumpleanos/muros/{$this->greeting->id}")->assertOk()->assertJsonPath('data.abierto', false)->assertJsonPath('data.cerrado', true);

    expect(BirthdayWallMessage::query()->count())->toBe(1)
        ->and($mensaje->refresh()->mensaje)->toBe('¡Felicidades!');
});
