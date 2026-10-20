<?php

use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoSolicitudInterna;
use App\Models\SolicitudInterna;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('rh autorizado entra al dashboard movil', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->getJson('/api/v1/rh/dashboard')
        ->assertOk()
        ->assertJsonStructure(['resumen' => ['pendientes_total', 'solicitudes', 'vacaciones', 'documentos', 'incorporaciones'], 'urgentes', 'recientes']);
});

test('un colaborador sin permiso de rh recibe 403 en el dashboard', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->withHeaders(['Authorization' => 'Bearer '.$colaborador->createToken('test')->plainTextToken])
        ->getJson('/api/v1/rh/dashboard')
        ->assertForbidden();
});

test('el dashboard cuenta correctamente las solicitudes y vacaciones pendientes', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    // Las vacaciones son un `tipo` del motor unificado (solicitudes_internas),
    // no la tabla legacy `solicitudes_vacaciones`: la baldosa cuenta las
    // abiertas de ese tipo y «solicitudes» el resto (nunca se suman dos veces).
    SolicitudInterna::factory()->count(2)->create(['estado' => EstadoSolicitudInterna::Enviada->value]);
    SolicitudInterna::factory()->count(2)->create(['tipo' => TipoSolicitudInterna::Vacaciones->value, 'estado' => EstadoSolicitudInterna::Enviada->value]);
    SolicitudInterna::factory()->create(['tipo' => TipoSolicitudInterna::Vacaciones->value, 'estado' => EstadoSolicitudInterna::EnRevision->value]);
    // Cerradas y legacy: no son pendientes.
    SolicitudInterna::factory()->create(['tipo' => TipoSolicitudInterna::Vacaciones->value, 'estado' => EstadoSolicitudInterna::Aprobada->value]);
    SolicitudVacaciones::factory()->count(3)->create(['estado' => 'pendiente']);

    $respuesta = $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->getJson('/api/v1/rh/dashboard')
        ->assertOk();

    expect($respuesta->json('resumen.solicitudes'))->toBe(2)
        ->and($respuesta->json('resumen.vacaciones'))->toBe(3);
});
