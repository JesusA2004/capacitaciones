<?php

use App\Services\Pdf\DetectorChromeHeadlessService;

/*
| Puppeteer instala chrome-headless-shell bajo una carpeta de versión que
| cambia con cada actualización (p. ej. "linux-131.0.6778.204"): el
| detector nunca debe adivinar ese nombre, solo escanear la carpeta real y
| tomar la build más reciente.
*/

function dchCrearInstalacion(string $cacheDir, string $producto, string $build, string $ejecutable): string
{
    $carpeta = $cacheDir.DIRECTORY_SEPARATOR.$producto.DIRECTORY_SEPARATOR."linux-{$build}".DIRECTORY_SEPARATOR."{$producto}-linux64";
    mkdir($carpeta, 0755, true);
    $ruta = $carpeta.DIRECTORY_SEPARATOR.$ejecutable;
    file_put_contents($ruta, '#!/bin/sh');
    chmod($ruta, 0755);

    return $ruta;
}

test('detectar(): encuentra chrome-headless-shell en la carpeta de caché configurada y toma la build más reciente', function () {
    $cacheDir = sys_get_temp_dir().'/dch-'.uniqid();
    dchCrearInstalacion($cacheDir, 'chrome-headless-shell', '120.0.0.0', 'chrome-headless-shell');
    $masReciente = dchCrearInstalacion($cacheDir, 'chrome-headless-shell', '131.0.6778.204', 'chrome-headless-shell');

    config(['pdf.browsershot.puppeteer_cache_dir' => $cacheDir]);

    expect(app(DetectorChromeHeadlessService::class)->detectar()['ruta'])->toBe($masReciente);
});

test('detectar(): si no hay chrome-headless-shell, usa chrome como segunda opción', function () {
    $cacheDir = sys_get_temp_dir().'/dch-'.uniqid();
    $chrome = dchCrearInstalacion($cacheDir, 'chrome', '131.0.6778.204', 'chrome');

    config(['pdf.browsershot.puppeteer_cache_dir' => $cacheDir]);

    expect(app(DetectorChromeHeadlessService::class)->detectar()['ruta'])->toBe($chrome);
});

test('detectar(): sin ninguna instalación, devuelve null y lista las carpetas que revisó', function () {
    config(['pdf.browsershot.puppeteer_cache_dir' => sys_get_temp_dir().'/dch-vacio-'.uniqid()]);

    $resultado = app(DetectorChromeHeadlessService::class)->detectar();

    expect($resultado['ruta'])->toBeNull()
        ->and($resultado['carpetas_revisadas'])->not->toBe([]);
});
