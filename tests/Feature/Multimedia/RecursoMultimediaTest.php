<?php

use App\Enums\EstadoMultimedia;
use App\Jobs\ProcesarVideoJob;
use App\Models\RecursoMultimedia;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('administrador_capacitacion');

    $this->colaborador = User::factory()->create();
    $this->colaborador->assignRole('colaborador');
});

test('un colaborador sin permiso no puede ver la biblioteca multimedia', function () {
    $this->actingAs($this->colaborador)
        ->get(route('multimedia.index'))
        ->assertForbidden();
});

test('un colaborador sin permiso no puede subir archivos', function () {
    $this->actingAs($this->colaborador)
        ->post(route('multimedia.store'), [
            'tipo' => 'documento',
            'archivo' => UploadedFile::fake()->create('manual.pdf', 200, 'application/pdf'),
        ])
        ->assertForbidden();
});

test('subir un documento lo deja disponible de inmediato sin encolar procesamiento', function () {
    Bus::fake();

    $this->actingAs($this->admin)
        ->post(route('multimedia.store'), [
            'tipo' => 'documento',
            'archivo' => UploadedFile::fake()->create('manual.pdf', 200, 'application/pdf'),
        ])
        ->assertRedirect();

    $recurso = RecursoMultimedia::firstOrFail();

    expect($recurso->estado)->toBe(EstadoMultimedia::Disponible);
    expect($recurso->nombre_original)->toBe('manual.pdf');
    Storage::disk('nas')->assertExists($recurso->ruta_original);
    Bus::assertNotDispatched(ProcesarVideoJob::class);
});

test('subir un video lo deja pendiente y encola el procesamiento', function () {
    Bus::fake();

    $this->actingAs($this->admin)
        ->post(route('multimedia.store'), [
            'tipo' => 'video',
            'archivo' => UploadedFile::fake()->create('induccion.mp4', 5000, 'video/mp4'),
        ])
        ->assertRedirect();

    $recurso = RecursoMultimedia::firstOrFail();

    expect($recurso->estado)->toBe(EstadoMultimedia::Pendiente);
    Storage::disk('nas')->assertExists($recurso->ruta_original);
    Bus::assertDispatched(ProcesarVideoJob::class, fn ($job) => $job->recurso->is($recurso));
});

test('eliminar un recurso borra sus archivos del disco', function () {
    $recurso = RecursoMultimedia::factory()->create([
        'ruta_original' => 'originales/borrar.mp4',
        'ruta_miniatura' => 'miniaturas/borrar.mp4.jpg',
    ]);

    Storage::disk('nas')->put($recurso->ruta_original, 'contenido');
    Storage::disk('nas')->put($recurso->ruta_miniatura, 'contenido');

    $this->actingAs($this->admin)
        ->delete(route('multimedia.destroy', $recurso))
        ->assertRedirect();

    Storage::disk('nas')->assertMissing($recurso->ruta_original);
    Storage::disk('nas')->assertMissing($recurso->ruta_miniatura);
    expect(RecursoMultimedia::find($recurso->id))->toBeNull();
});
