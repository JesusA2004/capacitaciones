<?php

use App\Models\User;
use App\Services\Autenticacion\AutenticacionService;
use Database\Seeders\DatabaseSeeder;

/*
 * README → «Usuarios de desarrollo»: después de `migrate:fresh --seed` se
 * entra con el USUARIO (primer nombre + primer apellido), no con el correo.
 * Si esta prueba falla, actualiza la tabla del README.
 */
test('los usuarios demo del README existen y entran con usuario + Capacitacion2026!', function () {
    $this->seed(DatabaseSeeder::class);

    $esperados = [
        'Ana Martinez' => 'super_admin',
        'Sofia Reyes' => 'rh_admin',
        'Ivan Cabrera' => 'rh_auxiliar',
        'Claudia Estrada' => 'gerente',
        'Miguel Torres' => 'colaborador',
        'Diego Ponce' => 'jefe_directo',
    ];

    expect(User::query()->whereNull('username')->count())->toBe(0);

    foreach ($esperados as $usuario => $rol) {
        $cuenta = app(AutenticacionService::class)->verificar(mb_strtolower($usuario), 'Capacitacion2026!');

        expect($cuenta)->not->toBeNull("No entra «{$usuario}»")
            ->and($cuenta?->username)->toBe($usuario)
            ->and($cuenta?->hasRole($rol))->toBeTrue();
    }
});
