<?php

use App\Models\Colaborador;
use App\Models\User;
use App\Services\Notificaciones\DestinoNotificacionService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/*
| El aviso «Expediente incompleto» habla de UNA persona: quien lo recibe sin
| ser esa persona (RH, su jefe) abre el expediente de ella; solo su dueño va
| a «Mi expediente». El destino se resuelve al abrir el aviso, así que también
| corrige los avisos ya guardados.
*/

test('expediente incompleto: RH abre el expediente de la persona y el dueño abre Mi expediente', function () {
    $this->seed(RolesYPermisosSeeder::class);

    $rh = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $rh->assignRole('rh_admin');

    $persona = Colaborador::factory()->create();
    $cuenta = User::factory()->create(['colaborador_id' => $persona->id]);
    $cuenta->assignRole('colaborador');

    // Mismo payload que guarda PendienteRhNotification para este aviso.
    $aviso = new DatabaseNotification([
        'id' => (string) Str::uuid(),
        'type' => 'x',
        'data' => [
            'type' => 'expediente_incompleto',
            'related_type' => 'Colaborador',
            'resource_id' => $persona->id,
            'colaborador_id' => $persona->id,
            'url' => null,
        ],
    ]);

    $destinos = app(DestinoNotificacionService::class);

    expect($destinos->resolver($aviso, $rh)['url'])
        ->toBe(route('rh.expedientes.show', ['colaborador' => $persona->id, 'tab' => 'documentos'], false))
        ->and($destinos->resolver($aviso, $cuenta)['url'])
        ->toBe(route('mi-expediente', ['tab' => 'documentos'], false));

    // La pantalla a la que se manda a RH sí le abre (autorización del backend).
    $this->actingAs($rh)->get($destinos->resolver($aviso, $rh)['url'])->assertOk();
});
