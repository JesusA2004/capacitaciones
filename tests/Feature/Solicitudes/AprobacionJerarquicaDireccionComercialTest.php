<?php

use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\SolicitudAprobacion;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Solicitudes\AprobacionJerarquicaService;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Quien YA es de gerencia o superior (Gerente de Mesa de Control,
| Contraloría, Responsable de Sistemas…) no debe pasar directo a RH: falta
| el visto bueno de Dirección Comercial. Un colaborador normal (Gestor,
| Cajera…) sigue igual: gerente de sucursal → regional → RH, sin el nivel
| extra.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);

    $this->direccionComercial = Puesto::factory()->create(['nombre' => 'Dirección Comercial', 'nivel_jerarquico' => 2]);
    $this->director = Colaborador::factory()->create(['puesto_id' => $this->direccionComercial->id]);
    User::factory()->create(['colaborador_id' => $this->director->id]);
});

function ajdcSolicitud(Colaborador $persona): SolicitudInterna
{
    return SolicitudInterna::factory()->create([
        'colaborador_id' => $persona->id,
        'tipo' => 'prestamo',
        'monto_solicitado' => 5000,
        'estado' => 'enviada',
    ]);
}

test('un Gerente de Mesa de Control (gerencia) agrega Dirección Comercial como nivel extra antes de RH', function () {
    $puesto = Puesto::factory()->create(['nombre' => 'Gerente de Mesa de Control', 'nivel_jerarquico' => 3]);
    $gerente = Colaborador::factory()->create(['puesto_id' => $puesto->id]);
    $solicitud = ajdcSolicitud($gerente);

    $niveles = app(AprobacionJerarquicaService::class)->niveles($solicitud);

    expect($niveles)->toHaveKey(SolicitudAprobacion::NIVEL_DIRECCION_COMERCIAL)
        ->and($niveles[SolicitudAprobacion::NIVEL_DIRECCION_COMERCIAL]->pluck('id')->all())
        ->toBe([$this->director->user->id]);
});

test('un Gestor (puesto normal, sin nivel de gerencia) NO agrega el nivel de Dirección Comercial', function () {
    $puesto = Puesto::factory()->create(['nombre' => 'Gestor', 'nivel_jerarquico' => null]);
    $gestor = Colaborador::factory()->create(['puesto_id' => $puesto->id]);
    $solicitud = ajdcSolicitud($gestor);

    $niveles = app(AprobacionJerarquicaService::class)->niveles($solicitud);

    expect($niveles)->not->toHaveKey(SolicitudAprobacion::NIVEL_DIRECCION_COMERCIAL);
});

test('la propia Dirección Comercial no se autoasigna el visto bueno', function () {
    $solicitud = ajdcSolicitud($this->director);

    $niveles = app(AprobacionJerarquicaService::class)->niveles($solicitud);

    expect($niveles)->not->toHaveKey(SolicitudAprobacion::NIVEL_DIRECCION_COMERCIAL);
});
