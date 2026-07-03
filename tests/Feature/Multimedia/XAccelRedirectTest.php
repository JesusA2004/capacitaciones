<?php

use App\Services\Multimedia\MediaStorageService;
use Illuminate\Support\Facades\Storage;

/**
 * Auditoría de cumplimiento sección 9 (docs/AUDITORIA_CUMPLIMIENTO.md):
 * X-Accel-Redirect estaba documentado como "no implementado". Estas pruebas
 * verifican que MediaStorageService::respuesta() sabe emitir el header
 * cuando está activo, y que sigue sirviendo por streaming directo cuando no
 * (el comportamiento por defecto en este entorno de desarrollo sin Nginx).
 */
beforeEach(function () {
    Storage::fake('nas');
    Storage::disk('nas')->put('originales/video.mp4', 'contenido de prueba');
});

test('sin MEDIA_X_ACCEL_REDIRECT activo, la respuesta transmite el archivo directamente', function () {
    config(['media.x_accel_redirect' => false]);

    $respuesta = app(MediaStorageService::class)->respuesta('originales/video.mp4');

    expect($respuesta->headers->has('X-Accel-Redirect'))->toBeFalse();
});

test('con MEDIA_X_ACCEL_REDIRECT activo, la respuesta delega el archivo a Nginx sin transmitirlo', function () {
    config([
        'media.x_accel_redirect' => true,
        'media.x_accel_internal_prefix' => '/protegido-nas',
    ]);

    $respuesta = app(MediaStorageService::class)->respuesta('originales/video.mp4', [
        'Content-Type' => 'video/mp4',
        'Content-Disposition' => 'attachment; filename="video.mp4"',
    ]);

    expect($respuesta->headers->get('X-Accel-Redirect'))->toBe('/protegido-nas/originales/video.mp4');
    expect($respuesta->headers->get('Content-Type'))->toBe('video/mp4');
    expect($respuesta->headers->get('Content-Disposition'))->toContain('video.mp4');
    expect($respuesta->headers->has('Content-Length'))->toBeFalse();
});

test('el navegador nunca recibe la ruta fisica real del NAS, solo la ruta interna con prefijo configurado', function () {
    config([
        'media.x_accel_redirect' => true,
        'media.x_accel_internal_prefix' => '/protegido-nas',
    ]);

    $respuesta = app(MediaStorageService::class)->respuesta('hls/algun-uuid/720p_5.ts');

    $rutaExpuesta = $respuesta->headers->get('X-Accel-Redirect');

    expect($rutaExpuesta)->toStartWith('/protegido-nas/');
    expect($rutaExpuesta)->not->toContain(storage_path());
});
