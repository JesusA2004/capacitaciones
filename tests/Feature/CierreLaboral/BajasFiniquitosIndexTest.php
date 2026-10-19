<?php

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Bajas y finiquitos (índice agregado, grupo «7 · Cierres laborales»):
| panorama de lectura de los cierres en el alcance + estado del finiquito.
| Las acciones se ejecutan en la ficha; NO se exponen las del flujo legacy.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->withoutVite();
    Storage::fake('nas');
    Notification::fake();

    $estructura = clEstructura();
    $this->centro = $estructura['sucursal'];

    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $this->centro->id]);
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->centro->id]);

    $this->persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->centro->id,
        'jefe_id' => $this->gerente->colaborador_id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => '2024-01-01',
    ]);
    $cuenta = User::factory()->create(['colaborador_id' => $this->persona->id]);
    $cuenta->assignRole('colaborador');

    // Baja aplicada por el jefe directo (flujo vigente): deja un cierre real.
    $this->actingAs($this->gerente)
        ->post(route('rh.colaboradores.cierres.store', $this->persona), [
            'tipo_baja' => 'renuncia',
            'motivo' => 'Renuncia voluntaria.',
            'fecha_efectiva' => now()->addWeek()->toDateString(),
        ])
        ->assertRedirect();
});

test('RH ve el panorama de bajas y finiquitos con el siguiente paso vigente', function () {
    $this->actingAs($this->rh)->get(route('rh.cierres.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Rh/Cierres/Index')
            ->where('puede_ver_finiquito', true)
            ->has('cierres.data', 1)
            ->where('cierres.data.0.colaborador.id', $this->persona->id)
            ->where('cierres.data.0.tipo_baja', 'renuncia')
            // Cierre recién iniciado, sin finiquito: el paso vigente es calcularlo.
            ->where('cierres.data.0.finiquito', null)
            ->where('cierres.data.0.siguiente_accion', 'Calcular finiquito')
            // NO se exponen acciones del flujo legacy en el índice.
            ->missing('cierres.data.0.acciones_permitidas'));
});

test('un gerente de otra sucursal no ve el cierre (respeta el alcance)', function () {
    $otra = clEstructura()['sucursal'];
    $gerenteOtra = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $otra->id]);

    $this->actingAs($gerenteOtra)->get(route('rh.cierres.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Rh/Cierres/Index')->has('cierres.data', 0));
});

test('sin el permiso cierres.ver responde 403', function () {
    $this->actingAs(clUsuario('instructor'))->get(route('rh.cierres.index'))->assertForbidden();
});
