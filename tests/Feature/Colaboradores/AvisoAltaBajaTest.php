<?php

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\User;
use App\Notifications\Mobile\PendienteRhNotification;
use App\Services\Colaboradores\AvisoAltaBajaService;
use App\Services\Solicitudes\BajaColaboradorService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $estructura = clEstructura();
    $this->persona = Colaborador::factory()->create(['sucursal_principal_id' => $estructura['sucursal']->id, 'puesto_id' => $estructura['puesto']->id]);
    $this->rh = clUsuario('rh_admin');
    // Sistemas por ROL…
    $this->sistemasPorRol = User::factory()->create();
    $this->sistemasPorRol->assignRole('sistemas');
    // …y por DEPARTAMENTO (puesto del departamento «Sistemas»), sin rol especial.
    $departamento = Departamento::factory()->create(['nombre' => 'Sistemas']);
    $puestoSistemas = Puesto::factory()->create(['nombre' => 'Responsable de Sistemas', 'departamento_id' => $departamento->id]);
    $this->sistemasPorPuesto = User::factory()->create(['colaborador_id' => Colaborador::factory()->create(['puesto_id' => $puestoSistemas->id, 'departamento_id' => $departamento->id])->id]);
    $this->ajeno = clUsuario('colaborador');
    $this->actor = clUsuario('rh_admin');
});

test('una ALTA avisa a RH y a Sistemas (resueltos por rol y por departamento, nunca por usuario fijo)', function () {
    app(AvisoAltaBajaService::class)->alta($this->persona);

    $tipo = fn (User $u) => Notification::assertSentTo($u, PendienteRhNotification::class, fn (PendienteRhNotification $n) => $n->toDatabase($u)['tipo'] === 'colaborador_alta');
    $tipo($this->rh);
    $tipo($this->sistemasPorRol);
    $tipo($this->sistemasPorPuesto);
    Notification::assertNotSentTo($this->ajeno, PendienteRhNotification::class);
});

test('una BAJA ejecutada avisa a RH y a Sistemas', function () {
    app(BajaColaboradorService::class)->ejecutar($this->persona, $this->actor, 'Renuncia voluntaria');

    foreach ([$this->rh, $this->sistemasPorRol, $this->sistemasPorPuesto] as $usuario) {
        Notification::assertSentTo($usuario, PendienteRhNotification::class, fn (PendienteRhNotification $n) => $n->toDatabase($usuario)['tipo'] === 'colaborador_baja');
    }
    Notification::assertNotSentTo($this->ajeno, PendienteRhNotification::class);
});
