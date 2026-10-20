<?php

use App\Enums\TipoMovimientoLaboral;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\MovimientoLaboral;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Departamentos\MovimientoDepartamentoService;
use Database\Seeders\RolesYPermisosSeeder;

/**
 * Organización → Departamentos → abrir → mover personas en lote
 * (MovimientoDepartamentoService → DatosExpedienteService, la misma regla
 * que «Datos laborales» del expediente).
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);

    $this->ventas = Departamento::factory()->create(['nombre' => 'Ventas', 'activo' => true]);
    $this->operaciones = Departamento::factory()->create(['nombre' => 'Operaciones', 'activo' => true]);
    $this->sucursal = Sucursal::factory()->create();

    $this->personas = Colaborador::factory()->count(3)->create([
        'departamento_id' => $this->ventas->id,
        'sucursal_principal_id' => $this->sucursal->id,
        'estatus' => 'activo',
    ]);

    $this->rh = User::factory()->create(['departamento_id' => $this->ventas->id]);
    $this->rh->assignRole('rh_admin');

    $this->mover = fn (User $actor, array $ids, ?Departamento $destino = null) => $this->actingAs($actor)
        ->post(route('administracion.departamentos.mover-colaboradores', $this->ventas), [
            'departamento_destino_id' => ($destino ?? $this->operaciones)->id,
            'colaborador_ids' => $ids,
        ]);
});

test('el detalle lista a las personas vigentes del departamento con destinos para RH', function () {
    Colaborador::factory()->create(['departamento_id' => $this->ventas->id, 'estatus' => 'inactivo']);

    $this->actingAs($this->rh)
        ->get(route('administracion.departamentos.show', $this->ventas))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Administracion/Departamentos/Show')
            ->where('puedeMover', true)
            // 3 personas + la propia cuenta de RH (que no se puede mover).
            ->has('colaboradores', 4)
            ->where('destinos.0.nombre', 'Operaciones'));
});

test('mueve solo a las personas seleccionadas y registra su movimiento laboral', function () {
    [$a, $b, $c] = $this->personas->all();

    ($this->mover)($this->rh, [$a->id, $b->id])->assertRedirect()->assertSessionHasNoErrors();

    expect($a->fresh()->departamento_id)->toBe($this->operaciones->id)
        ->and($b->fresh()->departamento_id)->toBe($this->operaciones->id)
        ->and($c->fresh()->departamento_id)->toBe($this->ventas->id);

    $movimientos = MovimientoLaboral::query()->where('tipo_movimiento', TipoMovimientoLaboral::CambioDepartamento->value)->get();

    expect($movimientos)->toHaveCount(2)
        ->and($movimientos->pluck('colaborador_id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all())
        ->and($movimientos->every(fn (MovimientoLaboral $m) => (int) $m->departamento_anterior_id === $this->ventas->id
            && (int) $m->departamento_nuevo_id === $this->operaciones->id
            && (int) $m->registrado_por === $this->rh->id))->toBeTrue();
});

test('es idempotente: repetir el mismo movimiento no duplica movimientos laborales', function () {
    $ids = $this->personas->pluck('id')->all();

    ($this->mover)($this->rh, $ids)->assertRedirect();
    ($this->mover)($this->rh, $ids)->assertRedirect();

    expect(MovimientoLaboral::query()->where('tipo_movimiento', TipoMovimientoLaboral::CambioDepartamento->value)->count())->toBe(3)
        ->and(Colaborador::query()->whereKey($ids)->where('departamento_id', $this->operaciones->id)->count())->toBe(3);
});

test('nadie se mueve a sí mismo: se omite con motivo y los demás sí se mueven', function () {
    $propio = $this->rh->colaborador;
    $otro = $this->personas->first();

    ($this->mover)($this->rh, [$propio->id, $otro->id])->assertRedirect();

    expect($propio->fresh()->departamento_id)->toBe($this->ventas->id)
        ->and($otro->fresh()->departamento_id)->toBe($this->operaciones->id)
        ->and(MovimientoLaboral::query()->where('colaborador_id', $propio->id)->exists())->toBeFalse();
});

test('el resultado reporta movidos y omitidos con su motivo', function () {
    $baja = Colaborador::factory()->create(['departamento_id' => $this->ventas->id, 'estatus' => 'inactivo']);

    $resultado = app(MovimientoDepartamentoService::class)->mover(
        $this->ventas,
        $this->operaciones,
        [$this->personas->first()->id, $this->rh->colaborador_id, $baja->id, 999999],
        $this->rh,
    );

    expect(array_column($resultado['movidos'], 'id'))->toBe([$this->personas->first()->id])
        ->and(array_column($resultado['omitidos'], 'motivo'))->toBe([
            'Nadie cambia su propio departamento.',
            'No está activo (Inactivo).',
            'No existe en el sistema.',
        ]);
});

test('sin permiso de editar expedientes (o sin permisos) responde 403', function () {
    $catalogo = User::factory()->create();
    $catalogo->assignRole('administrador_capacitacion');

    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $ids = [$this->personas->first()->id];

    ($this->mover)($catalogo, $ids)->assertForbidden();
    ($this->mover)($colaborador, $ids)->assertForbidden();

    $this->actingAs($colaborador)->get(route('administracion.departamentos.show', $this->ventas))->assertForbidden();

    expect($this->personas->first()->fresh()->departamento_id)->toBe($this->ventas->id);
});

test('el destino debe ser otro departamento activo', function () {
    $inactivo = Departamento::factory()->create(['activo' => false]);
    $ids = [$this->personas->first()->id];

    ($this->mover)($this->rh, $ids, $this->ventas)->assertSessionHasErrors('departamento_destino_id');
    ($this->mover)($this->rh, $ids, $inactivo)->assertSessionHasErrors('departamento_destino_id');
});

test('renombre: Sucursales y Departamentos siguen respondiendo en sus rutas', function () {
    $this->actingAs($this->rh)->get(route('administracion.sucursales.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Administracion/Sucursales/Index'));
    $this->actingAs($this->rh)->get(route('administracion.sucursales.show', $this->sucursal))->assertOk();
    $this->actingAs($this->rh)->get(route('administracion.departamentos.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Administracion/Departamentos/Index'));

    expect(route('administracion.sucursales.index', absolute: false))->toBe('/administracion/sucursales')
        ->and(route('administracion.departamentos.show', $this->ventas, false))->toBe(sprintf('/administracion/departamentos/%d', $this->ventas->id));
});
