<?php

use App\Enums\EstadoCandidato;
use App\Models\Candidato;
use App\Models\CandidatoEvidencia;
use App\Models\CandidatoReferencia;
use App\Models\Sucursal;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Reclutamiento\CvStorageService;
use App\Services\Reclutamiento\ExpedienteCandidatoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
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
        ->and($candidato->cv_path)->toStartWith("candidatos/Laura Mendez - {$candidato->id}/cv/")
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
    $carpeta = $this->candidato->refresh()->carpeta_nas;

    expect(ecArchivosEn("{$carpeta}/socioeconomico/videos"))->toHaveCount(2)
        ->and(ecArchivosEn("{$carpeta}/socioeconomico/fotos"))->toHaveCount(1)
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

    expect(ecArchivosEn("{$this->candidato->refresh()->carpeta_nas}/socioeconomico/videos"))->toHaveCount(2)
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

    $carpeta = $this->candidato->refresh()->carpeta_nas;

    expect(ecArchivosEn("{$carpeta}/psicometricos"))->toHaveCount(1)
        ->and(ecArchivosEn("{$carpeta}/referencias"))->toHaveCount(1)
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

    expect($evidencia->carpetaCandidato($this->candidato))->toBe($this->candidato->fresh()?->carpeta_nas);
});

test('la carpeta del candidato lleva su nombre, aparte de expedientes, y no cambia si se corrige el nombre', function () {
    $candidato = Candidato::factory()->create(['nombre' => 'José', 'apellidos' => 'Núñez Pérez']);
    $servicio = app(ExpedienteCandidatoService::class);

    $servicio->guardarCv($candidato, UploadedFile::fake()->create('cv.pdf', 50, 'application/pdf'), $this->rh);
    $carpeta = "candidatos/Jose Nunez Perez - {$candidato->id}";

    expect($candidato->fresh()?->cv_path)->toStartWith("{$carpeta}/cv/")
        ->and(ecArchivosEn("{$carpeta}/cv"))->toHaveCount(1);

    $candidato->update(['apellidos' => 'Núñez']);
    expect($servicio->carpetaCandidato($candidato->fresh() ?? $candidato))->toBe($carpeta);
});

test('candidatos:carpetas-con-nombre mueve la carpeta antigua por número a la carpeta con nombre', function () {
    $candidato = Candidato::factory()->create(['nombre' => 'Ana', 'apellidos' => 'Ruiz', 'cv_path' => null]);
    $antigua = "candidatos/{$candidato->id}";
    Storage::disk('nas')->put("{$antigua}/cv/a.pdf", 'cv');
    Storage::disk('nas')->put("{$antigua}/socioeconomico/videos/v.mp4", 'video');
    $candidato->forceFill(['cv_path' => "{$antigua}/cv/a.pdf"])->save();
    $video = CandidatoEvidencia::query()->create([
        'candidato_id' => $candidato->id, 'evidenciable_type' => 'candidato_socioeconomico', 'evidenciable_id' => 1,
        'tipo' => 'video', 'disk' => 'nas', 'path' => "{$antigua}/socioeconomico/videos/v.mp4",
        'original_name' => 'v.mp4', 'mime' => 'video/mp4', 'size' => 5,
    ]);

    $this->artisan('candidatos:carpetas-con-nombre', ['--simular' => true])->assertSuccessful();
    Storage::disk('nas')->assertExists("{$antigua}/cv/a.pdf");

    $this->artisan('candidatos:carpetas-con-nombre')->assertSuccessful();
    $carpeta = "candidatos/Ana Ruiz - {$candidato->id}";

    Storage::disk('nas')->assertExists("{$carpeta}/cv/a.pdf");
    Storage::disk('nas')->assertExists("{$carpeta}/socioeconomico/videos/v.mp4");
    Storage::disk('nas')->assertMissing("{$antigua}/cv/a.pdf");
    expect($candidato->fresh()?->cv_path)->toBe("{$carpeta}/cv/a.pdf")
        ->and($video->fresh()?->path)->toBe("{$carpeta}/socioeconomico/videos/v.mp4");
});

test('la 2ª corrida real de moverACarpetaConNombre mueve 0 archivos (idempotente)', function () {
    $candidato = Candidato::factory()->create(['nombre' => 'Clara', 'apellidos' => 'Ortiz', 'cv_path' => null]);
    $antigua = "candidatos/{$candidato->id}";
    Storage::disk('nas')->put("{$antigua}/cv/a.pdf", 'cv');
    Storage::disk('nas')->put("{$antigua}/socioeconomico/videos/v.mp4", 'video');
    $candidato->forceFill(['cv_path' => "{$antigua}/cv/a.pdf"])->save();
    $video = CandidatoEvidencia::query()->create([
        'candidato_id' => $candidato->id, 'evidenciable_type' => 'candidato_socioeconomico', 'evidenciable_id' => 1,
        'tipo' => 'video', 'disk' => 'nas', 'path' => "{$antigua}/socioeconomico/videos/v.mp4",
        'original_name' => 'v.mp4', 'mime' => 'video/mp4', 'size' => 5,
    ]);

    $servicio = app(ExpedienteCandidatoService::class);

    $primera = $servicio->moverACarpetaConNombre($candidato->fresh() ?? $candidato);
    expect($primera['movidos'])->toBe(2)
        ->and($primera['faltantes'])->toBe([])
        ->and($primera['fallidos'])->toBe([]);

    // Segunda corrida: ya están en la carpeta con nombre, nada que mover.
    $segunda = $servicio->moverACarpetaConNombre($candidato->fresh() ?? $candidato);
    expect($segunda['movidos'])->toBe(0)
        ->and($segunda['faltantes'])->toBe([])
        ->and($segunda['fallidos'])->toBe([]);

    $carpeta = "candidatos/Clara Ortiz - {$candidato->id}";
    Storage::disk('nas')->assertExists("{$carpeta}/cv/a.pdf");
    Storage::disk('nas')->assertMissing("{$antigua}/cv/a.pdf");
    expect($candidato->fresh()?->cv_path)->toBe("{$carpeta}/cv/a.pdf")
        ->and($video->fresh()?->path)->toBe("{$carpeta}/socioeconomico/videos/v.mp4");
});

test('si el move del NAS falla, la BD no se actualiza y el archivo se reporta como fallido', function () {
    $candidato = Candidato::factory()->create(['nombre' => 'Beto', 'apellidos' => 'Lara', 'cv_path' => null]);
    $origen = "candidatos/{$candidato->id}/cv/a.pdf";
    $candidato->forceFill(['cv_path' => $origen])->save();

    // Disco que confirma que el origen existe pero rechaza el move (devuelve
    // false): el destino nunca llega a existir.
    $disco = Mockery::mock(Filesystem::class);
    $disco->shouldReceive('exists')->andReturnUsing(fn (string $ruta): bool => $ruta === $origen);
    $disco->shouldReceive('move')->andReturn(false);

    $almacenamiento = Mockery::mock(CvStorageService::class);
    $almacenamiento->shouldReceive('disco')->andReturn($disco);

    $servicio = new ExpedienteCandidatoService($almacenamiento, app(AuditoriaService::class), app(DocumentoStorageService::class));

    $resultado = $servicio->moverACarpetaConNombre($candidato->fresh() ?? $candidato);

    expect($resultado['movidos'])->toBe(0)
        ->and($resultado['faltantes'])->toBe([])
        ->and($resultado['fallidos'])->toBe([$origen]);

    // La ruta guardada sigue apuntando al archivo original: no se perdió.
    expect($candidato->fresh()?->cv_path)->toBe($origen);
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
