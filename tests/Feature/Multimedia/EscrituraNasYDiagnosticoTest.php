<?php

use App\Models\RecursoMultimedia;
use App\Services\Multimedia\MediaStorageService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * P1-19 / P1-20 / P2-12: la escritura al NAS debe confirmarse antes de dar por
 * buena una subida/ensamblado, y el comando people:diagnostico-multimedia debe
 * reportar cada comprobación como OK/FALLA sin depender de un NAS real.
 */
test('MediaStorageService::guardar lanza si el NAS no confirma la escritura (put devuelve false)', function () {
    $disco = Mockery::mock(Filesystem::class);
    $disco->shouldReceive('put')->andReturn(false);
    $disco->shouldReceive('putFileAs')->andReturn(false);
    $disco->shouldReceive('exists')->andReturn(false);

    Storage::shouldReceive('disk')->andReturn($disco);

    $origen = tempnam(sys_get_temp_dir(), 'origen_');
    file_put_contents($origen, 'contenido de prueba');

    try {
        expect(fn () => app(MediaStorageService::class)->guardar($origen, 'originales/x.mp4'))
            ->toThrow(RuntimeException::class);
    } finally {
        @unlink($origen);
    }
});

test('MediaStorageService::guardar no lanza cuando el NAS confirma la escritura', function () {
    Storage::fake('nas');

    $origen = tempnam(sys_get_temp_dir(), 'origen_');
    file_put_contents($origen, 'contenido de prueba');

    try {
        $ruta = app(MediaStorageService::class)->guardar($origen, 'originales/ok.mp4');

        expect($ruta)->toBe('originales/ok.mp4')
            ->and(Storage::disk('nas')->exists('originales/ok.mp4'))->toBeTrue();
    } finally {
        @unlink($origen);
    }
});

test('people:diagnostico-multimedia corre y reporta los checks principales en OK', function () {
    Storage::fake('nas');
    Process::fake(['*' => Process::result('ffmpeg version 6.0 de prueba')]);
    config(['media.x_accel_redirect' => false]);

    $this->artisan('people:diagnostico-multimedia')
        ->expectsOutputToContain('Disco «nas» configurado')
        ->expectsOutputToContain('Escritura al NAS confirmada')
        ->expectsOutputToContain('Lectura del NAS correcta')
        ->expectsOutputToContain('ffmpeg disponible')
        ->expectsOutputToContain('ffprobe disponible')
        ->assertExitCode(0);
});

test('people:diagnostico-multimedia marca FALLA si el recurso de ejemplo apunta a un archivo inexistente en el NAS', function () {
    Storage::fake('nas');
    Process::fake(['*' => Process::result('ffmpeg version 6.0 de prueba')]);

    $recurso = RecursoMultimedia::factory()->create([
        'ruta_original' => 'originales/no-existe.mp4',
        'ruta_hls_manifiesto' => null,
        'ruta_miniatura' => null,
    ]);

    $this->artisan('people:diagnostico-multimedia', ['recurso' => $recurso->id])
        ->expectsOutputToContain('El archivo original NO existe en el NAS')
        ->assertExitCode(1);
});
