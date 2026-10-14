<?php

use App\Models\Candidato;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;

/*
 * Bandeja de CVs: lo que se descarga de WhatsApp Web llega a una carpeta del
 * NAS y desde ahí se asigna al candidato por nombre, sin «Seleccionar
 * archivo». Al asignarse sale de la bandeja.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    config(['reclutamiento.bandeja_cv.disk' => 'nas', 'reclutamiento.bandeja_cv.carpeta' => 'reclutamiento/bandeja-cv']);
    $this->rh = clUsuario('rh_admin');
});

test('la bandeja lista solo CVs completos y permitidos, del más reciente al más antiguo', function () {
    $disco = Storage::disk('nas');
    $disco->put('reclutamiento/bandeja-cv/CV Ana.pdf', '%PDF-1.4 ana');
    touch($disco->path('reclutamiento/bandeja-cv/CV Ana.pdf'), now()->subHour()->getTimestamp());
    $disco->put('reclutamiento/bandeja-cv/foto cv luis.jpg', 'jpg');
    $disco->put('reclutamiento/bandeja-cv/descargando.pdf.crdownload', 'parcial');
    $disco->put('reclutamiento/bandeja-cv/virus.exe', 'x');

    $this->actingAs($this->rh)->getJson(route('rh.candidatos.bandeja-cv.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.archivo', 'foto cv luis.jpg')
        ->assertJsonPath('data.1.archivo', 'CV Ana.pdf');

    $this->actingAs(clUsuario('colaborador'))->getJson(route('rh.candidatos.bandeja-cv.index'))->assertForbidden();
});

test('al registrar un candidato con un CV de la bandeja, el archivo pasa a su expediente y sale de la bandeja', function () {
    Storage::disk('nas')->put('reclutamiento/bandeja-cv/CV Laura.pdf', '%PDF-1.4 laura');

    $this->actingAs($this->rh)->post(route('rh.candidatos.store'), [
        'nombre' => 'Laura', 'espontaneo' => true, 'fuente' => 'referido', 'cv_bandeja' => 'CV Laura.pdf',
    ])->assertSessionHasNoErrors();

    $candidato = Candidato::query()->where('nombre', 'Laura')->firstOrFail();
    expect($candidato->cv_original_name)->toBe('CV Laura.pdf')
        ->and($candidato->cv_mime)->toBe('application/pdf')
        ->and($candidato->cv_path)->toStartWith("candidatos/{$candidato->id}/cv/");
    Storage::disk('nas')->assertExists($candidato->cv_path);
    Storage::disk('nas')->assertMissing('reclutamiento/bandeja-cv/CV Laura.pdf');
});

test('un archivo que ya no está en la bandeja no crea al candidato', function () {
    $this->actingAs($this->rh)->post(route('rh.candidatos.store'), [
        'nombre' => 'Fantasma', 'espontaneo' => true, 'fuente' => 'referido', 'cv_bandeja' => 'no-existe.pdf',
    ])->assertSessionHasErrors('cv_bandeja');

    expect(Candidato::query()->where('nombre', 'Fantasma')->exists())->toBeFalse();
});

test('desde el expediente se toma un CV de la bandeja; nunca se aceptan rutas fuera de ella', function () {
    $candidato = Candidato::factory()->create(['espontaneo' => true]);
    Storage::disk('nas')->put('reclutamiento/bandeja-cv/CV Pedro.png', 'png');
    Storage::disk('nas')->put('otra-carpeta/secreto.pdf', '%PDF secreto');

    $this->actingAs($this->rh)->post(route('rh.candidatos.cv.desde-bandeja', $candidato), ['archivo' => '../../otra-carpeta/secreto.pdf'])
        ->assertSessionHasErrors('cv_bandeja');
    Storage::disk('nas')->assertExists('otra-carpeta/secreto.pdf');

    $this->actingAs($this->rh)->post(route('rh.candidatos.cv.desde-bandeja', $candidato), ['archivo' => 'CV Pedro.png'])
        ->assertSessionHasNoErrors();

    expect($candidato->refresh()->cv_original_name)->toBe('CV Pedro.png')
        ->and($candidato->cv_mime)->toBe('image/png');
    Storage::disk('nas')->assertMissing('reclutamiento/bandeja-cv/CV Pedro.png');
});
