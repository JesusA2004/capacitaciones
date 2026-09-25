<?php

use App\Enums\MotivoCobertura;
use App\Enums\TipoNodoComercial;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    config([
        'organigrama.puestos_raiz_sucursal' => ['Gerente de Sucursal'],
        'organigrama.puestos_de_region' => ['Gerente regional'],
    ]);
    Sucursal::query()->update(['activo' => false]);

    $this->director = Puesto::factory()->create(['nombre' => 'Director comercial', 'nivel_jerarquico' => 2]);
    $this->regional = Puesto::factory()->create(['nombre' => 'Gerente regional', 'nivel_jerarquico' => 3, 'puesto_superior_id' => $this->director->id]);
    $this->gerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'nivel_jerarquico' => 4, 'puesto_superior_id' => $this->regional->id]);
    $this->subgerente = Puesto::factory()->create(['nombre' => 'Subgerente', 'nivel_jerarquico' => 5, 'puesto_superior_id' => $this->gerente->id]);

    $this->cordoba = Sucursal::factory()->create(['nombre' => 'Córdoba']);
    $this->cuernavaca = Sucursal::factory()->create(['nombre' => 'Cuernavaca']);

    $matriz = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Matriz->value, 'nombre' => 'MATRIZ']);
    $this->q1 = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Region->value, 'nombre' => 'Región Q1', 'parent_id' => $matriz->id]);
    $this->q3 = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Region->value, 'nombre' => 'Región Q3', 'parent_id' => $matriz->id]);
    NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'CUERNAVACA', 'parent_id' => $this->q1->id, 'sucursal_id' => $this->cuernavaca->id]);
    NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'CORDOBA', 'parent_id' => $this->q3->id, 'sucursal_id' => $this->cordoba->id]);

    $this->gerenteCordoba = Colaborador::factory()->create([
        'name' => 'Gerente', 'apellidos' => 'Córdoba',
        'puesto_id' => $this->gerente->id, 'sucursal_principal_id' => $this->cordoba->id, 'jefe_id' => null,
    ]);

    $this->rh = User::factory()->create();
    $this->rh->assignRole('rh_admin');
});

/**
 * @return array<string, array<string, mixed>>
 */
function nodosOrganigrama(User $usuario): array
{
    $nodos = [];

    test()->actingAs($usuario)
        ->get(route('administracion.jerarquia-puestos.index'))
        ->assertOk()
        ->assertInertia(function ($page) use (&$nodos) {
            $nodos = collect($page->toArray()['props']['personas'])->keyBy('clave')->all();
        });

    return $nodos;
}

test('asignar una cobertura la registra con quién la asignó sin tocar el puesto titular', function () {
    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.coberturas.store'), [
            'colaborador_id' => $this->gerenteCordoba->id,
            'puesto_id' => $this->gerente->id,
            'sucursal_id' => $this->cuernavaca->id,
            'motivo' => MotivoCobertura::Vacante->value,
            'nota' => 'Mientras se contrata al nuevo gerente.',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $cobertura = CoberturaPuesto::query()->sole();

    expect($cobertura->activa)->toBeTrue()
        ->and($cobertura->registrada_por)->toBe($this->rh->id)
        ->and($cobertura->sucursal_id)->toBe($this->cuernavaca->id)
        ->and($cobertura->fecha_fin)->toBeNull()
        ->and($cobertura->nota)->toBe('Mientras se contrata al nuevo gerente.');

    // Conserva su puesto y sucursal originales.
    $this->gerenteCordoba->refresh();
    expect($this->gerenteCordoba->puesto_id)->toBe($this->gerente->id)
        ->and($this->gerenteCordoba->sucursal_principal_id)->toBe($this->cordoba->id);

    $this->assertDatabaseHas('activity_log', ['log_name' => 'organigrama', 'description' => 'cobertura_asignada', 'subject_id' => $cobertura->id]);
});

test('no permite dos coberturas vigentes del mismo puesto en la misma sucursal', function () {
    $otro = Colaborador::factory()->create(['puesto_id' => $this->subgerente->id, 'sucursal_principal_id' => $this->cordoba->id]);

    CoberturaPuesto::factory()->create([
        'colaborador_id' => $otro->id,
        'puesto_id' => $this->gerente->id,
        'sucursal_id' => $this->cuernavaca->id,
    ]);

    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.coberturas.store'), [
            'colaborador_id' => $this->gerenteCordoba->id,
            'puesto_id' => $this->gerente->id,
            'sucursal_id' => $this->cuernavaca->id,
            'motivo' => MotivoCobertura::Vacante->value,
        ])
        ->assertSessionHasErrors('colaborador_id');

    expect(CoberturaPuesto::query()->where('activa', true)->count())->toBe(1);
});

test('no se puede cubrir el mismo puesto del que ya se es titular en su sucursal', function () {
    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.coberturas.store'), [
            'colaborador_id' => $this->gerenteCordoba->id,
            'puesto_id' => $this->gerente->id,
            'sucursal_id' => $this->cordoba->id,
            'motivo' => MotivoCobertura::Apoyo->value,
        ])
        ->assertSessionHasErrors('colaborador_id');

    expect(CoberturaPuesto::query()->count())->toBe(0);
});

test('un colaborador dado de baja no puede cubrir', function () {
    $this->gerenteCordoba->update(['estatus' => 'inactivo']);

    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.coberturas.store'), [
            'colaborador_id' => $this->gerenteCordoba->id,
            'puesto_id' => $this->gerente->id,
            'sucursal_id' => $this->cuernavaca->id,
            'motivo' => MotivoCobertura::Baja->value,
        ])
        ->assertSessionHasErrors('colaborador_id');
});

test('terminar una cobertura la pasa al historial y libera el puesto para otra', function () {
    $cobertura = CoberturaPuesto::factory()->create([
        'colaborador_id' => $this->gerenteCordoba->id,
        'puesto_id' => $this->gerente->id,
        'sucursal_id' => $this->cuernavaca->id,
    ]);

    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.coberturas.finalizar', $cobertura))
        ->assertRedirect();

    $cobertura->refresh();
    expect($cobertura->activa)->toBeFalse()
        ->and($cobertura->fecha_fin?->toDateString())->toBe(now()->toDateString())
        ->and($cobertura->finalizada_por)->toBe($this->rh->id);

    // El historial se conserva (no se borra) y ya se puede asignar otra.
    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.coberturas.store'), [
            'colaborador_id' => $this->gerenteCordoba->id,
            'puesto_id' => $this->gerente->id,
            'sucursal_id' => $this->cuernavaca->id,
            'motivo' => MotivoCobertura::Incapacidad->value,
        ])
        ->assertSessionHasNoErrors();

    expect(CoberturaPuesto::query()->count())->toBe(2)
        ->and(CoberturaPuesto::query()->where('activa', true)->count())->toBe(1);
});

test('sin permiso de organigrama no se asignan ni terminan coberturas', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)
        ->post(route('administracion.jerarquia-puestos.coberturas.store'), [
            'colaborador_id' => $this->gerenteCordoba->id,
            'puesto_id' => $this->gerente->id,
            'sucursal_id' => $this->cuernavaca->id,
            'motivo' => MotivoCobertura::Vacante->value,
        ])
        ->assertForbidden();

    $cobertura = CoberturaPuesto::factory()->create([
        'colaborador_id' => $this->gerenteCordoba->id,
        'puesto_id' => $this->gerente->id,
        'sucursal_id' => $this->cuernavaca->id,
    ]);

    $this->actingAs($colaborador)
        ->post(route('administracion.jerarquia-puestos.coberturas.finalizar', $cobertura))
        ->assertForbidden();

    expect($cobertura->refresh()->activa)->toBeTrue();
});

test('el organigrama muestra la cobertura en la sucursal cubierta y al titular en la suya', function () {
    CoberturaPuesto::factory()->create([
        'colaborador_id' => $this->gerenteCordoba->id,
        'puesto_id' => $this->gerente->id,
        'sucursal_id' => $this->cuernavaca->id,
        'motivo' => MotivoCobertura::Vacante->value,
    ]);

    $nodos = nodosOrganigrama($this->rh);

    // Sigue en su puesto titular de Córdoba…
    $titular = $nodos['p'.$this->gerenteCordoba->id];
    expect($titular['tipo'])->toBe('persona')
        ->and($titular['sucursal']['nombre'])->toBe('Córdoba');

    // …y aparece como cobertura en Cuernavaca, no como titular.
    $cubriendo = collect($nodos)->first(fn (array $n) => $n['tipo'] === 'cobertura');
    expect($cubriendo)->not->toBeNull()
        ->and($cubriendo['persona']['id'])->toBe($this->gerenteCordoba->id)
        ->and($cubriendo['sucursal']['nombre'])->toBe('Cuernavaca')
        ->and($cubriendo['cobertura']['motivo'])->toBe('vacante');

    // Cuernavaca no queda "sin ocupar" en gerencia porque alguien la cubre.
    $vacanteGerencia = collect($nodos)->first(fn (array $n) => $n['tipo'] === 'vacante'
        && $n['puesto']['id'] === $this->gerente->id
        && ($n['sucursal']['id'] ?? null) === $this->cuernavaca->id);
    expect($vacanteGerencia)->toBeNull();
});

test('una sucursal sin titular ni cobertura muestra el puesto sin ocupar, sin traer a alguien de otra sucursal', function () {
    Colaborador::factory()->create(['puesto_id' => $this->subgerente->id, 'sucursal_principal_id' => $this->cuernavaca->id, 'jefe_id' => null]);

    $nodos = nodosOrganigrama($this->rh);

    $sub = collect($nodos)->first(fn (array $n) => $n['tipo'] === 'persona' && $n['puesto']['id'] === $this->subgerente->id);
    $padre = $nodos[$sub['padre']];

    expect($padre['tipo'])->toBe('vacante')
        ->and($padre['puesto']['id'])->toBe($this->gerente->id)
        ->and($padre['sucursal']['id'])->toBe($this->cuernavaca->id);
});

test('un gerente regional de Q1 puede cubrir temporalmente Q3', function () {
    $regionalQ1 = Colaborador::factory()->create([
        'name' => 'Regional', 'apellidos' => 'Q1',
        'puesto_id' => $this->regional->id, 'sucursal_principal_id' => $this->cuernavaca->id, 'jefe_id' => null,
    ]);

    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.coberturas.store'), [
            'colaborador_id' => $regionalQ1->id,
            'puesto_id' => $this->regional->id,
            'region_id' => $this->q3->id,
            'motivo' => MotivoCobertura::Vacante->value,
        ])
        ->assertSessionHasNoErrors();

    $nodos = nodosOrganigrama($this->rh);

    // Titular de Q1…
    expect($nodos['p'.$regionalQ1->id]['region']['nombre'])->toBe('Región Q1');

    // …y cubriendo Q3: el gerente de Córdoba (Q3) cuelga de esa cobertura.
    $cobertura = collect($nodos)->first(fn (array $n) => $n['tipo'] === 'cobertura');
    expect($cobertura['region']['nombre'])->toBe('Región Q3')
        ->and($cobertura['persona']['id'])->toBe($regionalQ1->id)
        ->and($nodos['p'.$this->gerenteCordoba->id]['padre'])->toBe($cobertura['clave']);

    // Solo existen Q1 y Q3 como regiones.
    expect(collect($nodos)->pluck('region.nombre')->filter()->unique()->sort()->values()->all())
        ->toBe(['Región Q1', 'Región Q3']);
});

test('la región indicada debe existir como región de la matriz', function () {
    $ruta = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Ruta->value]);

    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.coberturas.store'), [
            'colaborador_id' => $this->gerenteCordoba->id,
            'puesto_id' => $this->regional->id,
            'region_id' => $ruta->id,
            'motivo' => MotivoCobertura::Vacante->value,
        ])
        ->assertSessionHasErrors('region_id');
});
