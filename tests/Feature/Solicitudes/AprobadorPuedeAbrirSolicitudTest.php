<?php

use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Solicitudes\AprobacionJerarquicaService;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Un gerente nombrado aprobador de una solicitud de otra sucursal recibía 403 al
| abrirla: el trámite quedaba esperándolo sin salida.
*/

test('solo quien la cadena nombra como aprobador abre la solicitud fuera de su alcance', function () {
    $this->seed(RolesYPermisosSeeder::class);
    $solicitud = SolicitudInterna::factory()->create();
    $aprobador = clUsuario('gerente_sucursal');
    $ajeno = clUsuario('gerente_sucursal');

    $this->mock(AprobacionJerarquicaService::class)
        ->shouldReceive('esAprobadorDe')
        ->andReturnUsing(fn (User $usuario): bool => $usuario->is($aprobador));

    expect($aprobador->can('view', $solicitud))->toBeTrue()
        ->and($ajeno->can('view', $solicitud))->toBeFalse();
});
