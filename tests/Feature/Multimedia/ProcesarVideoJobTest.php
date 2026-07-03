<?php

use App\Enums\EstadoMultimedia;
use App\Jobs\ProcesarVideoJob;
use App\Models\RecursoMultimedia;
use App\Services\Multimedia\FfmpegService;
use App\Services\Multimedia\MediaStorageService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * FFmpeg/FFprobe no estan instalados en este entorno, asi que estas pruebas
 * cubren el control de flujo del job (idempotencia y manejo de errores) con
 * Process::fake(), en vez de una codificacion real de video. La conversion
 * real a HLS se valida manualmente en un entorno con los binarios instalados.
 */
beforeEach(function () {
    Storage::fake('nas');
});

test('el job no reprocesa un recurso que ya no esta pendiente', function () {
    Process::fake();

    $recurso = RecursoMultimedia::factory()->create(['estado' => EstadoMultimedia::Disponible]);

    (new ProcesarVideoJob($recurso))->handle(app(MediaStorageService::class), app(FfmpegService::class));

    Process::assertNothingRan();
    expect($recurso->fresh()->estado)->toBe(EstadoMultimedia::Disponible);
});

test('si ffprobe falla el recurso queda en estado error con el mensaje capturado', function () {
    Process::fake([
        '*ffprobe*' => Process::result(errorOutput: 'no se pudo leer el archivo', exitCode: 1),
    ]);

    $nombreInterno = 'video-error.mp4';
    $recurso = RecursoMultimedia::factory()->create([
        'estado' => EstadoMultimedia::Pendiente,
        'nombre_interno' => $nombreInterno,
        'ruta_original' => "originales/{$nombreInterno}",
    ]);

    Storage::disk('nas')->put($recurso->ruta_original, 'contenido-falso-de-video');

    (new ProcesarVideoJob($recurso))->handle(app(MediaStorageService::class), app(FfmpegService::class));

    $recurso->refresh();

    expect($recurso->estado)->toBe(EstadoMultimedia::Error);
    expect($recurso->error_procesamiento)->toContain('ffprobe falló');
});
