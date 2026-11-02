<?php

use App\Enums\TipoMovimientoLaboral;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\MovimientoLaboral;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Puestos\MovimientoPuestoService;
use Database\Seeders\RolesYPermisosSeeder;

/**
 * Organización → Puestos y salarios → ficha del puesto → trasladar personas
 * en lote a otro puesto (MovimientoPuestoService → DatosExpedienteService,
 * la misma regla que «Datos laborales» del expediente).
 */
beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);

    $this->comercial = Departamento::factory()->create(['nombre' => 'Comercial', 'activo' => true]);
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'activo' => true, 'departamento_id' => $this->comercial->id]);
    $this->volante = Puesto::factory()->create(['nombre' => 'Gestor Volante', 'activo' => true, 'departamento_id' => $this->comercial->id]);
    $this->sucursal = Sucursal::factory()->create();

    $this->personas = Colaborador::factory()->count(3)->create([
        'puesto_id' => $this->gestor->id,
        'departamento_id' => null,
        'sucursal_principal_id' => $this->sucursal->id,
        'estatus' => 'activo',
    ]);

    $this->rh = User::factory()->create();
    $this->rh->assignRole('rh_admin');

    $this->trasladar = fn (User $actor, array $ids, ?Puesto $destino = null) => $this->actingAs($actor)
        ->post(route('administracion.puestos.mover-colaboradores', $this->gestor), [
            'puesto_destino_id' => ($destino ?? $this->volante)->id,
            'colaborador_ids' => $ids,
        ]);
});

test('la ficha lista a todas las personas vigentes del puesto y los destinos', function () {
    Colaborador::factory()->create(['puesto_id' => $this->gestor->id, 'estatus' => 'inactivo']);

    $this->actingAs($this->rh)
        ->get(route('administracion.puestos.show', $this->gestor))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Administracion/Puestos/Show')
            ->where('puede.mover', true)
            ->has('personas', 3)
            ->where('destinos.0.nombre', 'Gestor Volante'));
});

test('traslada solo a las seleccionadas, las pasa al departamento del destino y registra su movimiento', function () {
    [$a, $b, $c] = $this->personas->all();

    ($this->trasladar)($this->rh, [$a->id, $b->id])->assertRedirect()->assertSessionHasNoErrors();

    expect($a->fresh()->puesto_id)->toBe($this->volante->id)
        ->and($a->fresh()->departamento_id)->toBe($this->comercial->id)
        ->and($b->fresh()->puesto_id)->toBe($this->volante->id)
        ->and($c->fresh()->puesto_id)->toBe($this->gestor->id);

    $movimientos = MovimientoLaboral::query()
        ->whereIn('tipo_movimiento', [TipoMovimientoLaboral::CambioPuesto->value, TipoMovimientoLaboral::Promocion->value])
        ->get();

    expect($movimientos)->toHaveCount(2)
        ->and($movimientos->every(fn (MovimientoLaboral $m) => (int) $m->puesto_anterior_id === $this->gestor->id
            && (int) $m->puesto_nuevo_id === $this->volante->id
            && (int) $m->registrado_por === $this->rh->id))->toBeTrue();
});

test('el traslado no toca el sueldo', function () {
    $persona = $this->personas->first();
    $persona->update(['sueldo_mensual' => 12345.67]);

    ($this->trasladar)($this->rh, [$persona->id])->assertRedirect();

    expect((float) $persona->fresh()->sueldo_mensual)->toBe(12345.67);
});

test('el resultado reporta trasladados y omitidos con su motivo, sin duplicar', function () {
    $baja = Colaborador::factory()->create(['puesto_id' => $this->gestor->id, 'estatus' => 'inactivo']);
    $servicio = app(MovimientoPuestoService::class);
    $primera = $this->personas->first();

    $servicio->mover($this->gestor, $this->volante, [$primera->id], $this->rh);
    $resultado = $servicio->mover($this->gestor, $this->volante, [$primera->id, $baja->id, 999999], $this->rh);

    expect($resultado['movidos'])->toBe([])
        ->and(array_column($resultado['omitidos'], 'motivo'))->toBe([
            'Ya es Gestor Volante.',
            'No está activo (Inactivo).',
            'No existe en el sistema.',
        ]);
});

test('sin editar expedientes, sin permisos o con destino inválido no se traslada', function () {
    $catalogo = User::factory()->create();
    $catalogo->assignRole('administrador_capacitacion');
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');
    $ids = [$this->personas->first()->id];

    ($this->trasladar)($catalogo, $ids)->assertForbidden();
    ($this->trasladar)($colaborador, $ids)->assertForbidden();
    $this->actingAs($colaborador)->get(route('administracion.puestos.show', $this->gestor))->assertForbidden();

    ($this->trasladar)($this->rh, $ids, $this->gestor)->assertSessionHasErrors('puesto_destino_id');
    ($this->trasladar)($this->rh, $ids, Puesto::factory()->create(['activo' => false]))->assertSessionHasErrors('puesto_destino_id');

    expect($this->personas->first()->fresh()->puesto_id)->toBe($this->gestor->id);
});

test('crear un puesto lleva a su ficha', function () {
    $this->actingAs($this->rh)
        ->post(route('administracion.puestos.store'), ['nombre' => 'Auxiliar de Cobranza', 'activo' => true])
        ->assertRedirect(route('administracion.puestos.show', Puesto::query()->where('nombre', 'Auxiliar de Cobranza')->firstOrFail()));
});
