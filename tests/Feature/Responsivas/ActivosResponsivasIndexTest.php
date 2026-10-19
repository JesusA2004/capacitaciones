<?php

use App\Enums\EstadoResponsiva;
use App\Enums\EstadoUsuario;
use App\Enums\TipoResponsiva;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Responsiva;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Activos y responsivas (índice agregado, grupo «3 · Onboarding»): panorama
| DOCUMENTAL de todas las responsivas dentro del alcance. Reutiliza el mismo
| scoping (módulo «responsivas») y las mismas operaciones per-persona.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->withoutVite();

    $empresa = Empresa::factory()->create();
    $this->centro = Sucursal::factory()->create(['empresa_id' => $empresa->id]);
    $this->orizaba = Sucursal::factory()->create(['empresa_id' => $empresa->id]);

    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $this->centro->id]);
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->centro->id]);

    $this->delCentro = clResponsivaDemo($this->centro, $this->rh);
    $this->deOrizaba = clResponsivaDemo($this->orizaba, $this->rh);
});

function clResponsivaDemo(Sucursal $sucursal, User $responsable): Responsiva
{
    $persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'estatus' => EstadoUsuario::Activo,
    ]);

    return Responsiva::query()->create([
        'folio' => sprintf('RESP-TEST-%d', $persona->id),
        'colaborador_id' => $persona->id,
        'tipo' => TipoResponsiva::EquipoElectronico,
        'lugar' => 'Cuernavaca',
        'fecha_entrega' => now()->toDateString(),
        'entregado_por' => $responsable->id,
        'registrado_por' => $responsable->id,
        'datos' => ['tipo_equipo' => 'celular', 'marca' => 'Samsung', 'estado' => 'nuevo'],
        'snapshot' => ['otorgante' => ['nombre' => 'Responsable de entrega', 'puesto' => null]],
        'estado' => EstadoResponsiva::Generada,
    ]);
}

test('RH ve el panorama de todas las responsivas de su alcance con datos reales', function () {
    $this->actingAs($this->rh)->get(route('rh.activos-responsivas.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Rh/Responsivas/Index')
            ->has('responsivas.data', 2)
            ->has('responsivas.data.0.colaborador.nombre')
            ->where('responsivas.data.0.tipo_etiqueta', 'Equipo electrónico'));
});

test('el gerente solo ve las responsivas de su sucursal (respeta el alcance)', function () {
    $this->actingAs($this->gerente)->get(route('rh.activos-responsivas.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Rh/Responsivas/Index')
            ->has('responsivas.data', 1)
            ->where('responsivas.data.0.id', $this->delCentro->id));
});

test('el filtro por familia acota el panorama', function () {
    clResponsivaDemo($this->centro, $this->rh)->update(['tipo' => TipoResponsiva::Vehiculo]);

    $this->actingAs($this->rh)->get(route('rh.activos-responsivas.index', ['tipo' => 'vehiculo']))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->has('responsivas.data', 1)->where('responsivas.data.0.tipo', 'vehiculo'));
});

test('sin el permiso responsivas.ver responde 403', function () {
    $this->actingAs(clUsuario('instructor'))->get(route('rh.activos-responsivas.index'))->assertForbidden();
});
