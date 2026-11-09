<?php

use App\Enums\EstadoVacante;
use App\Enums\MotivoVacante;
use App\Enums\TipoAsignacionNodoComercial;
use App\Enums\TipoMovimientoLaboral;
use App\Enums\TipoNodoComercial;
use App\Models\AsignacionNodoComercial;
use App\Models\Baja;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\HeadcountTarget;
use App\Models\MovimientoLaboral;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\Vacante;
use App\Services\Organigrama\JefeDirectoService;
use App\Services\Rotacion\RotacionMotorService;
use App\Support\FechaMexico;
use Database\Seeders\RolesYPermisosSeeder;

/*
 * «Cambiar de puesto (traspaso)» desde el organigrama (docs/ORGANIGRAMA.md,
 * docs/MOVIMIENTOS_LABORALES.md): Gestores de Cuernavaca pasan a Auditora
 * (Contraloría, Corporativo). Vista previa con jefe y headcount, misma
 * puerta que Datos laborales, rutas liberadas y CERO efecto en rotación.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    config(['organigrama.puestos_raiz_sucursal' => ['Gerente de Sucursal']]);
    Sucursal::query()->update(['activo' => false]);

    $comercial = Departamento::factory()->create(['nombre' => 'Comercial']);
    $this->contraloria = Departamento::factory()->create(['nombre' => 'Contraloría']);

    $this->director = Puesto::factory()->create(['nombre' => 'Dirección General', 'nivel_jerarquico' => 1, 'activo' => true]);
    $this->gerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'nivel_jerarquico' => 5, 'puesto_superior_id' => $this->director->id, 'activo' => true, 'departamento_id' => $comercial->id]);
    $this->subgerente = Puesto::factory()->create(['nombre' => 'Subgerente', 'nivel_jerarquico' => 6, 'puesto_superior_id' => $this->gerente->id, 'activo' => true, 'departamento_id' => $comercial->id]);
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'nivel_jerarquico' => 7, 'puesto_superior_id' => $this->subgerente->id, 'activo' => true, 'departamento_id' => $comercial->id]);
    $this->volante = Puesto::factory()->create(['nombre' => 'Gestor Volante', 'nivel_jerarquico' => 7, 'puesto_superior_id' => $this->subgerente->id, 'activo' => true, 'departamento_id' => $comercial->id]);
    $this->gerenteContraloria = Puesto::factory()->create(['nombre' => 'Gerente de Contraloría', 'nivel_jerarquico' => 2, 'puesto_superior_id' => $this->director->id, 'activo' => true, 'departamento_id' => $this->contraloria->id]);
    $this->auditora = Puesto::factory()->create(['nombre' => 'Auditora', 'nivel_jerarquico' => 4, 'puesto_superior_id' => $this->gerenteContraloria->id, 'activo' => true, 'departamento_id' => $this->contraloria->id]);

    $this->corporativo = Sucursal::factory()->create(['nombre' => 'Corporativo', 'activo' => true, 'es_corporativo' => true]);
    $this->cuernavaca = Sucursal::factory()->create(['nombre' => 'Cuernavaca', 'activo' => true, 'es_corporativo' => false]);

    $persona = fn (string $nombre, Puesto $puesto, Sucursal $sucursal): Colaborador => Colaborador::factory()->create([
        'name' => $nombre, 'apellidos' => null, 'estatus' => 'activo',
        'puesto_id' => $puesto->id, 'sucursal_principal_id' => $sucursal->id, 'jefe_id' => null,
        'departamento_id' => $puesto->departamento_id, 'fecha_ingreso' => '2024-01-15',
    ]);

    $this->dg = $persona('Directora General', $this->director, $this->corporativo);
    $this->contralor = $persona('Contralor', $this->gerenteContraloria, $this->corporativo);
    $this->gerenteCuernavaca = $persona('Gerente Cuernavaca', $this->gerente, $this->cuernavaca);
    $this->subCuernavaca = $persona('Sub Cuernavaca', $this->subgerente, $this->cuernavaca);
    $this->volanteCuernavaca = $persona('Volante Cuernavaca', $this->volante, $this->cuernavaca);
    $this->gestorCuernavaca = $persona('Gestor Cuernavaca', $this->gestor, $this->cuernavaca);

    // Cuernavaca: 2 plazas de Gestor (el volante cuenta como Gestor), ambas ocupadas.
    HeadcountTarget::factory()->create(['sucursal_id' => $this->cuernavaca->id, 'puesto_id' => $this->gestor->id, 'plantilla_autorizada' => 2]);

    // Ruta de cobro vigente del gestor.
    $zona = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'CUERNAVACA', 'sucursal_id' => $this->cuernavaca->id]);
    $this->ruta = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Ruta->value, 'nombre' => 'RUTA CV-01', 'parent_id' => $zona->id, 'sucursal_id' => $this->cuernavaca->id, 'responsable_colaborador_id' => $this->gestorCuernavaca->id]);
    AsignacionNodoComercial::query()->create([
        'colaborador_id' => $this->gestorCuernavaca->id, 'nodo_comercial_id' => $this->ruta->id,
        'tipo_asignacion' => TipoAsignacionNodoComercial::Gestor->value, 'activo' => true, 'fecha_inicio' => now()->subYear(),
    ]);

    app(JefeDirectoService::class)->sincronizar();

    $this->rh = clUsuario('rh_admin');
});

test('la vista previa de un traspaso muestra de → a, jefe nuevo y el efecto en headcount sin guardar nada', function () {
    $vista = $this->actingAs($this->rh)
        ->postJson(route('administracion.jerarquia-puestos.traspaso.vista-previa'), [
            'colaborador_ids' => [$this->volanteCuernavaca->id], 'puesto_id' => $this->auditora->id,
        ])
        ->assertOk()
        ->json();

    $persona = $vista['personas'][0];
    $textos = collect($vista['headcount'])->pluck('texto');

    expect($vista['descripcion'])->toBe('Vas a traspasar a Volante Cuernavaca de Gestor Volante · Cuernavaca a Auditora · Corporativo.')
        ->and($persona['jefe_actual'])->toBe(Colaborador::query()->whereKey($this->volanteCuernavaca->fresh()->jefe_id)->first()?->nombreCompleto())
        ->and($persona['jefe_nuevo'])->toBe('Contralor')
        ->and($persona['departamento_de'])->toBe('Comercial')
        ->and($persona['departamento_a'])->toBe('Contraloría')
        ->and($persona['tipo_movimiento'])->toBe(TipoMovimientoLaboral::Promocion->etiqueta())
        ->and($textos)->toContain('Se abrirá 1 vacante de Gestor Volante en Cuernavaca (comparte plazas con Gestor).')
        ->and($textos)->toContain('Quedará fuera de plantilla (no hay plaza autorizada de Auditora en Corporativo).')
        ->and($vista['sin_cambios'])->toBeFalse();

    expect($this->volanteCuernavaca->fresh()->puesto_id)->toBe($this->volante->id)
        ->and(MovimientoLaboral::query()->count())->toBe(0);
});

test('traspasar a una persona pasa por Datos laborales: movimiento «Traspaso de puesto», jefe derivado y vacante de reemplazo', function () {
    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.traspaso.aplicar'), [
            // Aunque llegue una sucursal, un puesto corporativo queda en Corporativo.
            'colaborador_ids' => [$this->volanteCuernavaca->id], 'puesto_id' => $this->auditora->id,
            'sucursal_id' => $this->cuernavaca->id, 'comentario' => 'Refuerzo de auditoría',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $auditora = $this->volanteCuernavaca->fresh();
    expect($auditora->puesto_id)->toBe($this->auditora->id)
        ->and($auditora->sucursal_principal_id)->toBe($this->corporativo->id)
        ->and($auditora->departamento_id)->toBe($this->contraloria->id)
        ->and($auditora->jefe_id)->toBe($this->contralor->id)
        ->and($auditora->fecha_ingreso->toDateString())->toBe('2024-01-15');

    $promocion = MovimientoLaboral::query()->where('colaborador_id', $auditora->id)->where('tipo_movimiento', TipoMovimientoLaboral::Promocion->value)->first();
    expect($promocion)->not->toBeNull()
        ->and($promocion->motivo)->toBe('Traspaso de puesto')
        ->and($promocion->observaciones)->toBe('Refuerzo de auditoría')
        ->and(MovimientoLaboral::query()->where('colaborador_id', $auditora->id)->where('tipo_movimiento', TipoMovimientoLaboral::CambioSucursal->value)->exists())->toBeTrue();

    // La plaza (Gestor / Gestor Volante comparten) quedó libre: vacante automática de reemplazo.
    $vacante = Vacante::query()->where('sucursal_id', $this->cuernavaca->id)->whereIn('puesto_id', [$this->gestor->id, $this->volante->id])->whereIn('estado', EstadoVacante::valoresAbiertos())->first();
    expect($vacante)->not->toBeNull()
        ->and($vacante->motivo->value ?? $vacante->motivo)->toBe(MotivoVacante::Reemplazo->value);

    $this->assertDatabaseHas('activity_log', ['log_name' => 'organigrama', 'description' => 'traspaso_puesto', 'subject_id' => $auditora->id]);
});

test('traspaso de varias personas a la vez: una ocupa la plaza autorizada y la otra queda fuera de plantilla', function () {
    HeadcountTarget::factory()->create(['sucursal_id' => $this->corporativo->id, 'puesto_id' => $this->auditora->id, 'plantilla_autorizada' => 1]);
    $ids = [$this->volanteCuernavaca->id, $this->gestorCuernavaca->id];

    $vista = $this->actingAs($this->rh)
        ->postJson(route('administracion.jerarquia-puestos.traspaso.vista-previa'), ['colaborador_ids' => $ids, 'puesto_id' => $this->auditora->id])
        ->assertOk()
        ->json();

    $textos = collect($vista['headcount'])->pluck('texto');
    expect($vista['descripcion'])->toBe('Vas a traspasar a 2 personas a Auditora · Corporativo.')
        ->and($vista['personas'])->toHaveCount(2)
        ->and($textos)->toContain('Se abrirán 2 vacantes de Gestor Volante / Gestor en Cuernavaca.')
        ->and($textos)->toContain('Ocupará 1 plaza autorizada de Auditora en Corporativo.')
        ->and($textos)->toContain('Quedará fuera de plantilla (la única plaza autorizada de Auditora en Corporativo ya está ocupada).');

    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.traspaso.aplicar'), ['colaborador_ids' => $ids, 'puesto_id' => $this->auditora->id])
        ->assertSessionHasNoErrors();

    foreach ($ids as $id) {
        $c = Colaborador::query()->whereKey($id)->firstOrFail();
        expect($c->puesto_id)->toBe($this->auditora->id)
            ->and($c->jefe_id)->toBe($this->contralor->id);
    }

    $plazas = Vacante::query()->where('sucursal_id', $this->cuernavaca->id)->whereIn('puesto_id', [$this->gestor->id, $this->volante->id])->whereIn('estado', EstadoVacante::valoresAbiertos())->sum('plazas_disponibles');
    expect((int) $plazas)->toBe(2);

    // Volver a mandar a quien ya está en esa plaza se rechaza sin tocar nada.
    $antes = MovimientoLaboral::query()->count();
    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.traspaso.aplicar'), ['colaborador_ids' => $ids, 'puesto_id' => $this->auditora->id])
        ->assertSessionHasErrors('colaborador_ids');
    expect(MovimientoLaboral::query()->count())->toBe($antes);
});

test('un gestor con ruta que pasa a un puesto que no es de gestor deja libre su ruta en la matriz comercial', function () {
    $vista = $this->actingAs($this->rh)
        ->postJson(route('administracion.jerarquia-puestos.traspaso.vista-previa'), ['colaborador_ids' => [$this->gestorCuernavaca->id], 'puesto_id' => $this->auditora->id])
        ->assertOk()
        ->json();

    expect($vista['personas'][0]['rutas'])->toBe(['Dejará libre la ruta RUTA CV-01.']);

    // El Gestor Volante no tiene ruta fija: pasar a volante en la misma sucursal también la libera.
    $mismaPlaza = $this->actingAs($this->rh)
        ->postJson(route('administracion.jerarquia-puestos.traspaso.vista-previa'), ['colaborador_ids' => [$this->gestorCuernavaca->id], 'puesto_id' => $this->volante->id, 'sucursal_id' => $this->cuernavaca->id])
        ->assertOk()
        ->json();
    expect($mismaPlaza['personas'][0]['rutas'])->toBe(['Dejará libre la ruta RUTA CV-01.']);

    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.traspaso.aplicar'), ['colaborador_ids' => [$this->gestorCuernavaca->id], 'puesto_id' => $this->auditora->id])
        ->assertSessionHasNoErrors();

    expect(AsignacionNodoComercial::query()->where('colaborador_id', $this->gestorCuernavaca->id)->where('activo', true)->exists())->toBeFalse()
        ->and($this->ruta->fresh()->responsable_colaborador_id)->toBeNull();
});

test('un traspaso no es baja ni alta: la rotación del mes, las bajas y los ingresos no cambian', function () {
    $motor = app(RotacionMotorService::class);
    $mes = FechaMexico::inicioDeHoyInmutable()->startOfMonth();
    $sucursales = Sucursal::query()->pluck('id')->map(fn ($id): int => (int) $id)->values();
    $rotacionAntes = $motor->bajasDelMes($mes, $sucursales);

    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.traspaso.aplicar'), [
            'colaborador_ids' => [$this->volanteCuernavaca->id, $this->gestorCuernavaca->id], 'puesto_id' => $this->auditora->id,
        ])
        ->assertSessionHasNoErrors();

    $motor->reiniciar();

    expect($motor->bajasDelMes($mes, $sucursales))->toBe($rotacionAntes)
        ->and(Baja::query()->count())->toBe(0)
        ->and(MovimientoLaboral::query()->whereIn('tipo_movimiento', [TipoMovimientoLaboral::Alta->value, TipoMovimientoLaboral::Baja->value, TipoMovimientoLaboral::Reingreso->value])->count())->toBe(0)
        ->and(Colaborador::query()->whereKey([$this->volanteCuernavaca->id, $this->gestorCuernavaca->id])->where('estatus', 'activo')->count())->toBe(2)
        ->and(Colaborador::query()->whereKey($this->gestorCuernavaca->id)->first()?->fecha_ingreso?->toDateString())->toBe('2024-01-15');
});

test('reglas de destino y autorización del traspaso', function () {
    // Un puesto de sucursal nunca va al Corporativo.
    $this->actingAs($this->rh)
        ->postJson(route('administracion.jerarquia-puestos.traspaso.vista-previa'), ['colaborador_ids' => [$this->contralor->id], 'puesto_id' => $this->gestor->id, 'sucursal_id' => $this->corporativo->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('sucursal_id');

    // Las opciones traen todos los puestos activos y las personas vigentes.
    $opciones = $this->actingAs($this->rh)->getJson(route('administracion.jerarquia-puestos.traspaso.opciones'))->assertOk()->json();
    expect(collect($opciones['puestos'])->firstWhere('id', $this->auditora->id)['ambito'])->toBe('corporativo')
        ->and(collect($opciones['colaboradores'])->pluck('id'))->toContain($this->volanteCuernavaca->id);

    // Sin organigrama.mover_personas: 403 en todo y nada cambia.
    $gerente = clUsuario('gerente_sucursal');
    $this->actingAs($gerente)->getJson(route('administracion.jerarquia-puestos.traspaso.opciones'))->assertForbidden();
    $this->actingAs($gerente)
        ->postJson(route('administracion.jerarquia-puestos.traspaso.vista-previa'), ['colaborador_ids' => [$this->volanteCuernavaca->id], 'puesto_id' => $this->auditora->id])
        ->assertForbidden();
    $this->actingAs($gerente)
        ->post(route('administracion.jerarquia-puestos.traspaso.aplicar'), ['colaborador_ids' => [$this->volanteCuernavaca->id], 'puesto_id' => $this->auditora->id])
        ->assertForbidden();

    // RH no se traspasa a sí misma.
    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.traspaso.aplicar'), ['colaborador_ids' => [$this->rh->colaborador_id], 'puesto_id' => $this->auditora->id])
        ->assertForbidden();

    expect($this->volanteCuernavaca->fresh()->puesto_id)->toBe($this->volante->id)
        ->and(MovimientoLaboral::query()->count())->toBe(0);
});
