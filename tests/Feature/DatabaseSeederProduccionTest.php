<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('DatabaseSeeder en producción nunca crea cuentas de demostración @mrlana.test', function () {
    app()->detectEnvironment(fn () => 'production');

    try {
        app(DatabaseSeeder::class)->run();

        expect(User::query()->where('email', 'like', '%@mrlana.test')->exists())->toBeFalse();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

test('DatabaseSeeder sí crea cuentas de demostración en local/testing', function () {
    app(DatabaseSeeder::class)->run();

    expect(User::query()->where('email', 'like', '%@mrlana.test')->exists())->toBeTrue();
});

test('SEED_DEMO_DATA=true fuerza los datos de demostración aunque el entorno no sea local/testing', function () {
    config(['features.seed_demo_data' => true]);
    app()->detectEnvironment(fn () => 'production');

    try {
        app(DatabaseSeeder::class)->run();

        expect(User::query()->where('email', 'like', '%@mrlana.test')->exists())->toBeTrue();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
        config(['features.seed_demo_data' => false]);
    }
});
