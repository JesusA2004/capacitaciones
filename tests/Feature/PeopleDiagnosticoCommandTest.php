<?php

use Illuminate\Support\Facades\Schema;

test('people:diagnostico corre sin errores en un entorno recién sembrado', function () {
    $this->seed();

    $this->artisan('people:diagnostico')->assertExitCode(1);

    // Exit 1 es esperado (headcount/formatos oficiales no vienen en ningún
    // seeder — se importan aparte), pero nunca debe tronar con una
    // excepción sin capturar.
});

test('people:diagnostico no truena si faltan headcount_targets, official_formats o nodos_comerciales', function () {
    $this->seed();

    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists('headcount_targets');
    Schema::dropIfExists('official_formats');
    Schema::dropIfExists('nodos_comerciales');
    Schema::enableForeignKeyConstraints();

    $this->artisan('people:diagnostico')
        ->expectsOutputToContain('Falta la tabla «headcount_targets»')
        ->expectsOutputToContain('Falta la tabla «official_formats»')
        ->expectsOutputToContain('Falta la tabla «nodos_comerciales»')
        ->assertExitCode(1);
});

test('people:diagnostico no truena si faltan permissions o roles', function () {
    $this->seed();

    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists('role_has_permissions');
    Schema::dropIfExists('model_has_permissions');
    Schema::dropIfExists('model_has_roles');
    Schema::dropIfExists('permissions');
    Schema::dropIfExists('roles');
    Schema::enableForeignKeyConstraints();

    $this->artisan('people:diagnostico')
        ->expectsOutputToContain('Falta la tabla «permissions»')
        ->expectsOutputToContain('Falta la tabla «roles»')
        ->assertExitCode(1);
});
