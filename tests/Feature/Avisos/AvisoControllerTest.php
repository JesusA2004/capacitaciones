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

test('aviso sin archivo: solo título y mensaje', function () {
    $this->actingAs($this->rh)->post('/rh/avisos', ['titulo' => 'Solo texto', 'mensaje' => 'Sin adjunto.', 'alcance' => 'todos'])->assertRedirect();

    $aviso = Aviso::query()->where('titulo', 'Solo texto')->firstOrFail();
    expect($aviso->tieneArchivo())->toBeFalse();
    $this->actingAs($this->rh)->get("/rh/avisos/{$aviso->id}/archivo")->assertNotFound();
});

test('una imagen se sube, se ve inline y solo con permiso; la app anterior la sigue viendo por /imagen', function () {
    $this->actingAs($this->rh)->post('/rh/avisos', [
        'titulo' => 'Con imagen',
        'mensaje' => 'Mira esto.',
        'alcance' => 'todos',
        'archivo' => UploadedFile::fake()->image('foto.jpg'),
    ])->assertRedirect();

    $aviso = Aviso::query()->where('titulo', 'Con imagen')->firstOrFail();
    expect($aviso->archivoEsImagen())->toBeTrue()
        ->and($aviso->archivo_nombre)->toBe('foto.jpg');

    $this->actingAs($this->rh)->get("/rh/avisos/{$aviso->id}/archivo")->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    $sinPermiso = clUsuario('colaborador');
    $this->actingAs($sinPermiso)->get("/rh/avisos/{$aviso->id}/archivo")->assertForbidden();

    Sanctum::actingAs($sinPermiso);
    $item = collect($this->getJson('/api/v1/avisos')->assertOk()->json('data'))->firstWhere('id', $aviso->id);
    expect($item['archivo']['tipo'])->toBe('imagen')
        ->and($item['imagen_path'])->not->toBeNull();
    $this->get("/api/v1/avisos/{$aviso->id}/imagen")->assertOk();
});

test('un PDF se sube como PDF (no como imagen) y se abre inline en web y API', function () {
    $pdf = UploadedFile::fake()->createWithContent('Manual de políticas.pdf', '%PDF-1.4
1 0 obj<<>>endobj
trailer<<>>
%%EOF');

    $this->actingAs($this->rh)->post('/rh/avisos', [
        'titulo' => 'Políticas',
        'mensaje' => 'Lee el manual.',
        'alcance' => 'todos',
        'archivo' => $pdf,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $aviso = Aviso::query()->where('titulo', 'Políticas')->firstOrFail();
    expect($aviso->archivoEsPdf())->toBeTrue()
        ->and($aviso->archivo_nombre)->toBe('Manual de políticas.pdf')
        ->and($aviso->archivo_size)->toBeGreaterThan(0);

    $colaborador = clUsuario('colaborador');
    $this->actingAs($colaborador)->get("/avisos/{$aviso->id}/archivo")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    Sanctum::actingAs($colaborador);
    $item = collect($this->getJson('/api/v1/avisos')->json('data'))->firstWhere('id', $aviso->id);
    expect($item['archivo'])->toMatchArray(['tipo' => 'pdf', 'nombre' => 'Manual de políticas.pdf'])
        // La app anterior no intenta pintar un PDF como imagen.
        ->and($item['imagen_path'])->toBeNull();
    $this->get("/api/v1/avisos/{$aviso->id}/archivo")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get("/api/v1/avisos/{$aviso->id}/imagen")->assertNotFound();
});

test('un tipo de archivo no permitido se rechaza con mensaje claro', function () {
    $this->actingAs($this->rh)->post('/rh/avisos', [
        'titulo' => 'Malo',
        'mensaje' => 'Ejecutable.',
        'alcance' => 'todos',
        'archivo' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
    ])->assertSessionHasErrors(['archivo' => 'El archivo debe ser una imagen (JPG, PNG, WEBP) o un PDF.']);

    expect(Aviso::query()->where('titulo', 'Malo')->exists())->toBeFalse();
});

test('un aviso personal no lo puede abrir otro colaborador', function () {
    $destino = Colaborador::factory()->create();
    $this->actingAs($this->rh)->post('/rh/avisos', [
        'titulo' => 'Personal',
        'mensaje' => 'Solo para ti.',
        'alcance' => 'colaborador',
        'colaborador_objetivo_id' => $destino->id,
        'archivo' => UploadedFile::fake()->image('carta.png'),
    ])->assertRedirect();
    $aviso = Aviso::query()->where('titulo', 'Personal')->firstOrFail();

    $this->actingAs(clUsuario('colaborador'))->get("/avisos/{$aviso->id}/archivo")->assertForbidden();
});

test('si el archivo desapareció del almacenamiento, no es un 404 genérico', function () {
    $this->actingAs($this->rh)->post('/rh/avisos', ['titulo' => 'Perdido', 'mensaje' => 'X', 'alcance' => 'todos', 'archivo' => UploadedFile::fake()->image('a.png')]);
    $aviso = Aviso::query()->where('titulo', 'Perdido')->firstOrFail();
    Storage::disk('nas')->delete((string) $aviso->archivo_path);

    $this->actingAs($this->rh)->get("/rh/avisos/{$aviso->id}/archivo")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('ArchivoNoDisponible'));
    Sanctum::actingAs($this->rh);
    $this->getJson("/api/v1/avisos/{$aviso->id}/archivo")->assertStatus(409)->assertJsonPath('codigo', 'archivo_no_disponible');
});

test('API: el push abre un aviso concreto (GET /avisos/{id}) solo si le corresponde', function () {
    $this->actingAs($this->rh)->post('/rh/avisos', ['titulo' => 'Para todos', 'mensaje' => 'Hola.', 'alcance' => 'todos'])->assertRedirect();
    $aviso = Aviso::query()->where('titulo', 'Para todos')->firstOrFail();
    $destino = Colaborador::factory()->create();
    $this->actingAs($this->rh)->post('/rh/avisos', ['titulo' => 'Privado', 'mensaje' => 'Solo uno.', 'alcance' => 'colaborador', 'colaborador_objetivo_id' => $destino->id])->assertRedirect();
    $privado = Aviso::query()->where('titulo', 'Privado')->firstOrFail();

    $colaborador = clUsuario('colaborador');
    Sanctum::actingAs($colaborador);
    $this->getJson("/api/v1/avisos/{$aviso->id}")
        ->assertOk()
        ->assertJsonPath('data.titulo', 'Para todos')
        ->assertJsonPath('data.leido', false)
        ->assertJsonPath('data.archivo', null);
    $this->getJson("/api/v1/avisos/{$privado->id}")->assertForbidden();
    $this->getJson('/api/v1/avisos/999999')->assertNotFound();
});
