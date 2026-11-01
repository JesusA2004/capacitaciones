<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/*
| Los helpers de Wayfinder no dependen de la base: en un clon limpio, con la
| base inaccesible, `{rol}` debe admitir el id numérico que usa la pantalla de
| Roles (antes salía `string` y vue-tsc fallaba).
*/
test('people:wayfinder genera los mismos tipos con la base inaccesible', function () {
    $carpeta = storage_path('framework/testing/wayfinder-'.uniqid());

    $conexion = config('database.default');

    config([
        'database.default' => 'mysql',
        'database.connections.mysql.host' => '127.0.0.1',
        'database.connections.mysql.port' => 1,
    ]);

    try {
        expect(Artisan::call('people:wayfinder', ['--path' => $carpeta, '--skip-routes' => true, '--with-form' => true]))->toBe(0);

        $helper = (string) file_get_contents($carpeta.'/actions/App/Http/Controllers/Administracion/RolController.ts');

        expect($helper)->toContain('rol: string | number')
            ->and($helper)->not->toMatch('/\brol: string\b(?! \|)/')
            ->and($helper)->toContain('.form');
    } finally {
        config(['database.default' => $conexion]);
        File::deleteDirectory($carpeta);
    }
});
