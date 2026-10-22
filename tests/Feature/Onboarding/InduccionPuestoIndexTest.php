<?php

use App\Enums\EstadoOnboarding;
use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\OnboardingProceso;
use App\Models\Sucursal;
use Database\Seeders\RolesYPermisosSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Inducción al puesto (grupo «3 · Onboarding»): por cada colaborador en
| onboarding, el estado del acuse de su descriptivo de puesto. Reutiliza
| OnboardingService (población en alcance) y AcuseDescriptivoPuestoService.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->withoutVite();

    $empresa = Empresa::factory()->create();
    $this->centro = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $this->orizaba = Sucursal::factory()->create(['empresa_id' => $empresa->id]);

    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $this->centro->id]);

    $this->enCentro = clOnboardingDemo($this->centro);
    $this->enOrizaba = clOnboardingDemo($this->orizaba);
});

function clOnboardingDemo(Sucursal $sucursal): OnboardingProceso
{
    $persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'estatus' => EstadoUsuario::Activo,
    ]);

    return OnboardingProceso::query()->create([
        'colaborador_id' => $persona->id,
        'estado' => EstadoOnboarding::InduccionPuesto,
        'iniciado_en' => now(),
    ]);
}

test('RH ve a los colaboradores en onboarding y el estado de su descriptivo de puesto', function () {
    $this->actingAs($this->rh)->get(route('rh.induccion-puesto.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Rh/Onboarding/InduccionPuesto')
            ->has('colaboradores.data', 2)
            ->has('colaboradores.data.0.colaborador.nombre')
            ->where('colaboradores.data.0.onboarding_estado', 'induccion_puesto')
            // Sin evaluación de inducción aprobada: todavía no se puede enviar.
            ->where('colaboradores.data.0.acuse', null)
            ->where('colaboradores.data.0.puede_enviar', false)
            ->whereType('colaboradores.data.0.motivo', 'string'));
});

test('respeta el alcance: RH con alcance de sucursal solo ve a los suyos', function () {
    $rhSucursal = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->centro->id]);
    $rhSucursal->givePermissionTo(['onboarding.gestionar', 'onboarding.induccion_puesto']);

    $this->actingAs($rhSucursal)->get(route('rh.induccion-puesto.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Rh/Onboarding/InduccionPuesto')
            ->has('colaboradores.data', 1)
            ->where('colaboradores.data.0.colaborador.id', $this->enCentro->colaborador_id));
});

test('sin el permiso onboarding.gestionar responde 403', function () {
    $this->actingAs(clUsuario('instructor'))->get(route('rh.induccion-puesto.index'))->assertForbidden();
});
