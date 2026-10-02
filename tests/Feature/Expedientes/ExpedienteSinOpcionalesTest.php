<?php

use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('el expediente de la app solo lista documentos requeridos: lo opcional entra por Solicitudes', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');
    DocumentType::factory()->count(2)->create(['requerido' => true]);
    DocumentType::factory()->create(['requerido' => false, 'nombre' => 'Incapacidad médica']);

    $respuesta = $this->withHeaders(['Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken])
        ->getJson('/api/v1/colaborador/incorporacion')
        ->assertOk();

    expect($respuesta->json('documentos'))->toHaveCount(2)
        ->and(collect($respuesta->json('documentos'))->every(fn (array $d) => $d['obligatorio'] === true))->toBeTrue();
});

test('el buscador de reingresos muestra bajas recientes sin escribir nada y filtra desde la primera letra', function () {
    $rh = clUsuario('rh_admin');
    Colaborador::factory()->create(['name' => 'Zoe', 'apellidos' => 'Baja', 'estatus' => 'inactivo', 'fecha_baja' => now()->subMonth()]);
    Colaborador::factory()->create(['name' => 'Ana', 'apellidos' => 'Baja', 'estatus' => 'inactivo', 'fecha_baja' => now()->subWeek()]);

    $sinTermino = $this->actingAs($rh)->getJson('/api/v1/rh/reingresos/buscar')->assertOk();
    expect(collect($sinTermino->json('data'))->pluck('nombre')->all())->toContain('Zoe Baja', 'Ana Baja');

    $unaLetra = $this->actingAs($rh)->getJson('/api/v1/rh/reingresos/buscar?q=Z')->assertOk();
    expect(collect($unaLetra->json('data'))->pluck('nombre')->all())->toContain('Zoe Baja')->not->toContain('Ana Baja');
});
