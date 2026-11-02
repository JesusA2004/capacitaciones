<?php

use App\Enums\EstadoMultimedia;
use App\Enums\TipoRecursoMultimedia;
use App\Jobs\ProcesarVideoJob;
use App\Models\RecursoMultimedia;
use App\Services\Multimedia\FfmpegService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
| `people:videos-pendientes`: diagnóstico de la cola de video y recuperación
| en línea (dispatchSync) de videos atorados. Seguro en producción: sin
| --procesar solo lee; nunca reconvierte uno ya disponible.
*/

beforeEach(function () {
    Storage::fake('nas');
});

test('sin opciones solo reporta: no cambia ningún video', function () {
    $pendiente = RecursoMultimedia::factory()->create(['tipo' => TipoRecursoMultimedia::Video, 'estado' => EstadoMultimedia::Pendiente, 'nombre_original' => 'atorado.mp4']);
    Process::fake();

    $this->artisan('people:videos-pendientes')
        ->expectsOutputToContain('atorado.mp4')
        ->expectsOutputToContain('--procesar')
        ->assertSuccessful();

    Process::assertNothingRan();
    expect($pendiente->fresh()->estado)->toBe(EstadoMultimedia::Pendiente);
});

test('--procesar convierte en línea los pendientes (sin cola) y deja intactos los disponibles y los errores', function () {
    Process::fake([
        '*ffprobe*' => Process::result(output: json_encode([
            'format' => ['duration' => '12.0'],
            'streams' => [['codec_type' => 'video', 'width' => 1280, 'height' => 720], ['codec_type' => 'audio']],
        ])),
        '*' => Process::result(),
    ]);

    $pendiente = RecursoMultimedia::factory()->create([
        'tipo' => TipoRecursoMultimedia::Video,
        'estado' => EstadoMultimedia::Pendiente,
        'nombre_interno' => 'pend.mp4',
        'ruta_original' => 'originales/pend.mp4',
    ]);
    Storage::disk('nas')->put('originales/pend.mp4', 'video');
    $listo = RecursoMultimedia::factory()->create(['tipo' => TipoRecursoMultimedia::Video, 'estado' => EstadoMultimedia::Disponible]);
    $conError = RecursoMultimedia::factory()->create(['tipo' => TipoRecursoMultimedia::Video, 'estado' => EstadoMultimedia::Error, 'error_procesamiento' => 'sin ffmpeg']);

    // La miniatura simulada no escribe archivo; se crea para que el job la copie.
    $miniatura = sys_get_temp_dir().'/pend.mp4.jpg';
    file_put_contents($miniatura, 'jpg');

    $this->artisan('people:videos-pendientes --procesar')
        ->expectsOutputToContain('Listo')
        ->assertSuccessful();

    expect($pendiente->fresh()->estado)->toBe(EstadoMultimedia::Disponible)
        ->and($pendiente->fresh()->ruta_hls_manifiesto)->toBe('hls/pend.mp4/master.m3u8')
        ->and($listo->fresh()->estado)->toBe(EstadoMultimedia::Disponible)
        ->and($conError->fresh()->estado)->toBe(EstadoMultimedia::Error);
    Storage::disk('nas')->assertExists('hls/pend.mp4/master.m3u8');

    // Una sola pasada de FFmpeg para todas las variantes (480p y 720p).
    Process::assertRanTimes(fn ($proceso) => in_array('-var_stream_map', (array) $proceso->command, true), 1);
});

test('--procesar --incluir-errores --recurso reintenta solo ese video con error', function () {
    Process::fake(['*ffprobe*' => Process::result(errorOutput: 'archivo dañado', exitCode: 1)]);

    $conError = RecursoMultimedia::factory()->create(['tipo' => TipoRecursoMultimedia::Video, 'estado' => EstadoMultimedia::Error, 'error_procesamiento' => 'viejo']);
    $otro = RecursoMultimedia::factory()->create(['tipo' => TipoRecursoMultimedia::Video, 'estado' => EstadoMultimedia::Error, 'error_procesamiento' => 'otro']);
    Storage::disk('nas')->put($conError->ruta_original, 'video');

    $this->artisan('people:videos-pendientes', ['--procesar' => true, '--incluir-errores' => true, '--recurso' => $conError->id])
        ->assertFailed();

    expect($conError->fresh()->estado)->toBe(EstadoMultimedia::Error)
        ->and($conError->fresh()->error_procesamiento)->toContain('archivo dañado')
        ->and($otro->fresh()->error_procesamiento)->toBe('otro');
});

test('si el job se agota o falla, failed() deja el video en error con el motivo', function () {
    $recurso = RecursoMultimedia::factory()->create(['tipo' => TipoRecursoMultimedia::Video, 'estado' => EstadoMultimedia::Procesando]);

    (new ProcesarVideoJob($recurso))->failed(new RuntimeException('se agotó el tiempo'));

    expect($recurso->fresh()->estado)->toBe(EstadoMultimedia::Error)
        ->and($recurso->fresh()->error_procesamiento)->toContain('se agotó el tiempo');
});

test('el HLS se genera en una sola pasada, sin escalar hacia arriba y sin audio si el video no lo trae', function () {
    $comando = app(FfmpegService::class)->comandoHls('/tmp/in.mp4', '/tmp/out', [480, 720], 6, false);

    expect($comando)->toContain('-var_stream_map')
        ->and($comando)->toContain('v:0,name:480p v:1,name:720p')
        ->and($comando)->not->toContain('0:a:0')
        ->and($comando)->toContain('veryfast')
        ->and($comando)->toContain('/tmp/out/%v_%03d.ts')
        ->and($comando)->toContain('[0:v]split=2[v0][v1];[v0]scale=-2:480[v0out];[v1]scale=-2:720[v1out]');

    Process::fake();
    $carpeta = sys_get_temp_dir().'/hls_prueba_'.uniqid();
    $generadas = app(FfmpegService::class)->convertirAHls('/tmp/in.mp4', $carpeta, 480, [480, 720], 6);
    expect($generadas)->toBe([480]);
    expect(file_get_contents($carpeta.'/master.m3u8'))->toContain('480p.m3u8')->not->toContain('720p');
    @unlink($carpeta.'/master.m3u8');
    @rmdir($carpeta);
});
