<?php

use App\Enums\EstadoCandidato;
use App\Models\Candidato;
use App\Models\CandidatoEvidencia;
use App\Models\CandidatoReferencia;
use App\Models\Sucursal;
use App\Services\Reclutamiento\ExpedienteCandidatoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| Mini expediente del candidato antes de la contratación: CV desde la fase
| inicial, resultados psicométricos, VARIOS videos del socioeconómico y
| soporte de referencias, cada uno en su carpeta del NAS; lo consultan RH,
| Dirección Comercial y administrativos con alcance.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->estructura = clEstructura();
    $this->rh = clUsuario('rh_admin');
    $this->candidato = Candidato::factory()->create([
        'sucursal_id' => $this->estructura['sucursal']->id,
        'estado' => EstadoCandidato::SocioeconomicoPendiente,
    ]);
});

function ecVideo(string $nombre, int $kb = 2048): UploadedFile
{
    return UploadedFile::fake()->create($nombre, $kb, 'video/mp4');
}

function ecArchivosEn(string $carpeta): array
{
    return Storage::disk('nas')->files($carpeta);
}

test('el CV se sube desde el registro del candidato y queda en su carpeta cv/', function () {
    $this->actingAs($this->rh)->post(route('rh.candidatos.store'), [
        'espontaneo' => true,
        'nombre' => 'Laura',
        'apellidos' => 'Méndez',
        'fuente' => 'referido',
        'cv' => UploadedFile::fake()->create('cv-laura.pdf', 200, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $candidato = Candidato::query()->where('nombre', 'Laura')->firstOrFail();

    expect($candidato->cv_original_name)->toBe('cv-laura.pdf')
        ->and($candidato->cv_path)->toStartWith("candidatos/{$candidato->id}/cv/")
        ->and(Storage::disk('nas')->exists((string) $candidato->cv_path))->toBeTrue();
});

test('el socioeconómico acepta varios videos grandes y los guarda en socioeconomico/videos', function () {
    $this->actingAs($this->rh)->post(route('rh.candidatos.socioeconomico', $this->candidato), [
        'fecha_visita' => now()->toDateString(),
        'direccion' => 'Calle Reforma 10',
        'resultado' => 'viable',
        'evidencias' => [ecVideo('fachada.mp4', 150 * 1024), ecVideo('interior.mov'), UploadedFile::fake()->image('sala.jpg')],
    ])->assertSessionHasNoErrors();

    $id = $this->candidato->id;

    expect(ecArchivosEn("candidatos/{$id}/socioeconomico/videos"))->toHaveCount(2)
        ->and(ecArchivosEn("candidatos/{$id}/socioeconomico/fotos"))->toHaveCount(1)
        ->and(CandidatoEvidencia::query()->where('candidato_id', $id)->where('tipo', 'video')->count())->toBe(2);
});

test('una fotografía o PDF no puede pesar como un video', function () {
    $this->actingAs($this->rh)->post(route('rh.candidatos.socioeconomico', $this->candidato), [
        'fecha_visita' => now()->toDateString(),
        'direccion' => 'Calle Reforma 10',
        'resultado' => 'viable',
        'evidencias' => [UploadedFile::fake()->create('acta.pdf', 30 * 1024, 'application/pdf')],
    ])->assertSessionHasErrors('evidencias.0');

    expect(CandidatoEvidencia::query()->count())->toBe(0);
});

test('después de registrar el socioeconómico se le pueden agregar más videos sin cambiar la fase', function () {
    $this->actingAs($this->rh)->post(route('rh.candidatos.evidencias.store', $this->candidato), [
        'etapa' => 'socioeconomico',
        'archivos' => [ecVideo('extra.mp4')],
    ])->assertSessionHasErrors('etapa');

    $this->actingAs($this->rh)->post(route('rh.candidatos.socioeconomico', $this->candidato), [
        'fecha_visita' => now()->toDateString(), 'direccion' => 'Calle 1', 'resultado' => 'viable',
    ])->assertSessionHasNoErrors();
    $fase = $this->candidato->refresh()->estado;

    $this->actingAs($this->rh)->post(route('rh.candidatos.evidencias.store', $this->candidato), [
        'etapa' => 'socioeconomico',
        'archivos' => [ecVideo('recorrido-1.mp4'), ecVideo('recorrido-2.mp4')],
    ])->assertSessionHasNoErrors();

    expect(ecArchivosEn("candidatos/{$this->candidato->id}/socioeconomico/videos"))->toHaveCount(2)
        ->and($this->candidato->refresh()->estado)->toBe($fase)
        ->and($this->candidato->seguimientos()->where('nota', 'like', '%2 archivo(s)%')->exists())->toBeTrue();
});

test('psicométricos y soporte de referencias quedan en sus carpetas y en el expediente de la ficha', function () {
    $this->actingAs($this->rh)->post(route('rh.candidatos.evidencias.store', $this->candidato), [
        'etapa' => 'psicometricas',
        'archivos' => [UploadedFile::fake()->create('resultados.pdf', 300, 'application/pdf')],
    ])->assertSessionHasNoErrors();

    $this->candidato->update(['estado' => EstadoCandidato::ReferenciasPendientes]);
    $this->actingAs($this->rh)->post(route('rh.candidatos.referencias.store', $this->candidato), [
        'empresa' => 'Financiera X', 'contacto' => 'Jefa X', 'resultado' => 'positiva',
        'archivos' => [UploadedFile::fake()->image('constancia.png')],
    ])->assertSessionHasNoErrors();

    $id = $this->candidato->id;
    $expediente = $this->actingAs($this->rh)->get(route('rh.candidatos.show', $this->candidato))
        ->assertOk()->viewData('page')['props']['candidato']['expediente'];
    $grupos = collect($expediente['grupos'])->keyBy('clave');

    expect(ecArchivosEn("candidatos/{$id}/psicometricos"))->toHaveCount(1)
        ->and(ecArchivosEn("candidatos/{$id}/referencias"))->toHaveCount(1)
        ->and(CandidatoReferencia::query()->firstOrFail()->evidencias)->toHaveCount(1)
        ->and($grupos['psicometricas']['archivos'][0]['nombre'])->toBe('resultados.pdf')
        ->and($grupos['referencias']['archivos'][0]['nombre'])->toBe('constancia.png')
        ->and($expediente['total'])->toBe(2)
        // Disco y ruta del NAS nunca salen al cliente.
        ->and(json_encode($expediente))->not->toContain('candidatos/');
});

test('Dirección Comercial consulta el expediente y ve los videos; quien no tiene alcance no', function () {
    $evidencia = app(ExpedienteCandidatoService::class);
    $this->actingAs($this->rh)->post(route('rh.candidatos.socioeconomico', $this->candidato), [
        'fecha_visita' => now()->toDateString(), 'direccion' => 'Calle 1', 'resultado' => 'viable',
        'evidencias' => [ecVideo('visita.mp4')],
    ])->assertSessionHasNoErrors();
    $video = CandidatoEvidencia::query()->firstOrFail();

    $direccion = clUsuario('director_comercial');
    $this->actingAs($direccion)->get(route('rh.candidatos.show', $this->candidato))->assertOk();
    $this->actingAs($direccion)->get(route('rh.candidatos.evidencias.descargar', [$this->candidato, $video]))->assertOk();

    $this->actingAs(clUsuario('colaborador'))->get(route('rh.candidatos.evidencias.descargar', [$this->candidato, $video]))->assertForbidden();

    // Gerente de otra sucursal: ni ve ni agrega archivos.
    $ajeno = clUsuario('gerente_sucursal', ['sucursal_principal_id' => Sucursal::factory()->create()->id]);
    $this->actingAs($ajeno)->post(route('rh.candidatos.evidencias.store', $this->candidato), [
        'etapa' => 'socioeconomico', 'archivos' => [ecVideo('x.mp4')],
    ])->assertForbidden();

    expect($evidencia->carpetaCandidato($this->candidato->id))->toBe("candidatos/{$this->candidato->id}");
});

test('la app agrega videos al socioeconómico por la API con el mismo servicio', function () {
    $this->actingAs($this->rh)->post(route('rh.candidatos.socioeconomico', $this->candidato), [
        'fecha_visita' => now()->toDateString(), 'direccion' => 'Calle 1', 'resultado' => 'viable',
    ])->assertSessionHasNoErrors();

    Sanctum::actingAs($this->rh);
    $this->post("/api/v1/rh/candidatos/{$this->candidato->id}/evidencias", [
        'etapa' => 'socioeconomico',
        'archivos' => [ecVideo('app.mp4')],
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.candidato.expediente.grupos.0.archivos.0.nombre', 'app.mp4');
});
