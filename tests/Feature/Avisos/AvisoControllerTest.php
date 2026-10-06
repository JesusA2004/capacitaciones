<?php

use App\Enums\EstadoUsuario;
use App\Models\Aviso;
use App\Models\Colaborador;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    Queue::fake();
    $this->rh = clUsuario('rh_admin');
});

test('solo quien tiene avisos.enviar puede enviar uno; un colaborador normal no puede', function () {
    $colaborador = clUsuario('colaborador');

    $this->actingAs($colaborador)
        ->post('/rh/avisos', ['titulo' => 'Hola', 'mensaje' => 'Mensaje', 'alcance' => 'todos'])
        ->assertForbidden();

    $this->actingAs($this->rh)
        ->post('/rh/avisos', ['titulo' => 'Hola a todos', 'mensaje' => 'Buen día.', 'alcance' => 'todos'])
        ->assertRedirect();

    expect(Aviso::query()->where('titulo', 'Hola a todos')->exists())->toBeTrue();
});

test('un colaborador solo ve los avisos que le corresponden (API móvil)', function () {
    $colaborador = Colaborador::factory()->create(['estatus' => EstadoUsuario::Activo->value]);
    $cuenta = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $cuenta->assignRole('colaborador');

    $this->actingAs($this->rh)->post('/rh/avisos', [
        'titulo' => 'A todos',
        'mensaje' => 'Mensaje general.',
        'alcance' => 'todos',
    ])->assertRedirect();

    $otro = Colaborador::factory()->create();
    $this->actingAs($this->rh)->post('/rh/avisos', [
        'titulo' => 'Solo para otro',
        'mensaje' => 'Mensaje privado.',
        'alcance' => 'colaborador',
        'colaborador_objetivo_id' => $otro->id,
    ])->assertRedirect();

    Sanctum::actingAs($cuenta);
    $titulos = $this->getJson('/api/v1/avisos')->assertOk()->json('data.*.titulo');

    expect($titulos)->toContain('A todos')->not->toContain('Solo para otro');
});

test('una imagen se puede subir y solo se descarga autenticado', function () {
    $this->actingAs($this->rh)->post('/rh/avisos', [
        'titulo' => 'Con imagen',
        'mensaje' => 'Mira esto.',
        'alcance' => 'todos',
        'imagen' => UploadedFile::fake()->image('foto.jpg'),
    ])->assertRedirect();

    $aviso = Aviso::query()->where('titulo', 'Con imagen')->firstOrFail();
    expect($aviso->imagen_path)->not->toBeNull();

    $this->actingAs($this->rh)->get("/rh/avisos/{$aviso->id}/imagen")->assertOk();

    $sinPermiso = clUsuario('colaborador');
    $this->actingAs($sinPermiso)->get("/rh/avisos/{$aviso->id}/imagen")->assertForbidden();
});
