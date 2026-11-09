<?php

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use Database\Seeders\RolesYPermisosSeeder;
use Spatie\Activitylog\Models\Activity;

/*
| «Ubicar / mover puesto» (UbicacionPuestoService): RH elige debajo de quién va
| un puesto y su departamento (se propone el del superior), ve qué cambia y
| confirma. Nunca forma ciclos; el jefe directo se recalcula al momento.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Sucursal::query()->update(['activo' => false]);

    $this->direccion = Departamento::factory()->create(['nombre' => 'Dirección']);
    $this->contraloria = Departamento::factory()->create(['nombre' => 'Contraloría']);
    $this->ventas = Departamento::factory()->create(['nombre' => 'Ventas']);

    $this->dg = Puesto::factory()->create(['nombre' => 'Dirección General', 'puesto_superior_id' => null, 'departamento_id' => $this->direccion->id, 'activo' => true]);
    $this->contralor = Puesto::factory()->create(['nombre' => 'Gerente de Contraloría', 'puesto_superior_id' => $this->dg->id, 'departamento_id' => $this->contraloria->id, 'activo' => true]);
    $this->dc = Puesto::factory()->create(['nombre' => 'Dirección Comercial', 'puesto_superior_id' => $this->dg->id, 'departamento_id' => $this->ventas->id, 'activo' => true]);
    $this->auditoria = Puesto::factory()->create(['nombre' => 'Gerente de Auditoría', 'puesto_superior_id' => null, 'departamento_id' => null, 'activo' => true]);
    $this->auditor = Puesto::factory()->create(['nombre' => 'Auditor de Sucursal', 'puesto_superior_id' => $this->auditoria->id, 'departamento_id' => null, 'activo' => true]);

    $corporativo = Sucursal::factory()->create(['nombre' => 'Corporativo', 'activo' => true, 'es_corporativo' => true]);
    $this->zuriel = Colaborador::factory()->create(['name' => 'Zuriel', 'apellidos' => 'Ávila', 'estatus' => 'activo', 'puesto_id' => $this->dg->id, 'sucursal_principal_id' => $corporativo->id]);
    $this->roberto = Colaborador::factory()->create(['name' => 'Roberto', 'apellidos' => 'Contralor', 'estatus' => 'activo', 'puesto_id' => $this->contralor->id, 'sucursal_principal_id' => $corporativo->id]);
    $this->lucia = Colaborador::factory()->create(['name' => 'Lucía', 'apellidos' => 'Auditora', 'estatus' => 'activo', 'puesto_id' => $this->auditoria->id, 'sucursal_principal_id' => $corporativo->id]);
    $this->pedro = Colaborador::factory()->create(['name' => 'Pedro', 'apellidos' => 'Auditor', 'estatus' => 'activo', 'puesto_id' => $this->auditor->id, 'sucursal_principal_id' => $corporativo->id]);

    $this->rh = clUsuario('rh_admin');
});

test('las opciones listan los superiores posibles con su área y ocupantes, sin el puesto ni sus subordinados', function () {
    $opciones = $this->actingAs($this->rh)
        ->getJson(route('administracion.jerarquia-puestos.ubicacion.opciones', $this->auditoria))
        ->assertOk()
        ->json();

    $valores = collect($opciones['superiores'])->pluck('value')->all();
    $dg = collect($opciones['superiores'])->firstWhere('value', (string) $this->dg->id);

    expect($valores)->toContain((string) $this->contralor->id)
        ->and($valores)->not->toContain((string) $this->auditoria->id)
        ->and($valores)->not->toContain((string) $this->auditor->id)
        ->and($dg['ocupantes'])->toBe(['Zuriel Ávila'])
        ->and($dg['departamento'])->toBe('Dirección')
        ->and($dg['label'])->toContain('Zuriel Ávila');
});

test('la vista previa propone el departamento del superior y muestra los cambios de jefe sin guardar', function () {
    $previa = $this->actingAs($this->rh)
        ->postJson(route('administracion.jerarquia-puestos.ubicacion.vista-previa', $this->auditoria), ['puesto_superior_id' => $this->contralor->id])
        ->assertOk()
        ->json();

    expect($previa['superior_antes'])->toBeNull()
        ->and($previa['superior_despues'])->toBe('Gerente de Contraloría')
        ->and($previa['departamento_despues'])->toBe('Contraloría')
        ->and($previa['personas'])->toBe(['Lucía Auditora'])
        ->and(collect($previa['cambios_jefe'])->firstWhere('colaborador', 'Lucía Auditora')['despues'])->toBe('Roberto Contralor')
        // Nada se guardó.
        ->and($this->auditoria->fresh()->puesto_superior_id)->toBeNull();
});

test('mover un puesto cambia su superior y departamento, queda auditado y recalcula el jefe', function () {
    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.ubicacion.aplicar', $this->auditoria), [
            'puesto_superior_id' => $this->contralor->id,
            'departamento_id' => $this->contraloria->id,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $puesto = $this->auditoria->fresh();

    expect($puesto->puesto_superior_id)->toBe($this->contralor->id)
        ->and($puesto->departamento_id)->toBe($this->contraloria->id)
        ->and($this->lucia->fresh()->jefe_id)->toBe($this->roberto->id)
        // El auditor sigue debajo de su puesto: su jefe no cambia.
        ->and($this->pedro->fresh()->jefe_id)->toBe($this->lucia->id)
        ->and(Activity::query()->where('event', 'puesto_reubicado')->where('subject_type', Puesto::class)->where('subject_id', $puesto->id)->exists())->toBeTrue();
});

test('nunca se forma un ciclo: ni debajo de sí mismo ni de un subordinado', function () {
    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.ubicacion.aplicar', $this->auditoria), ['puesto_superior_id' => $this->auditor->id])
        ->assertSessionHasErrors('puesto_superior_id');

    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.ubicacion.aplicar', $this->auditoria), ['puesto_superior_id' => $this->auditoria->id])
        ->assertSessionHasErrors('puesto_superior_id');

    expect($this->auditoria->fresh()->puesto_superior_id)->toBeNull();
});

test('sin permiso de editar el organigrama no se puede mover un puesto', function () {
    $colaborador = clUsuario('colaborador');

    $this->actingAs($colaborador)
        ->post(route('administracion.jerarquia-puestos.ubicacion.aplicar', $this->auditoria), ['puesto_superior_id' => $this->contralor->id])
        ->assertForbidden();

    $this->actingAs($colaborador)
        ->getJson(route('administracion.jerarquia-puestos.ubicacion.opciones', $this->auditoria))
        ->assertForbidden();

    expect($this->auditoria->fresh()->puesto_superior_id)->toBeNull();
});
