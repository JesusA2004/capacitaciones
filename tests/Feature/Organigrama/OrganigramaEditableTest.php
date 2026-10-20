<?php

use App\Enums\TipoMovimientoLaboral;
use App\Enums\TipoNodoComercial;
use App\Models\ClasificacionRegion;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\HeadcountTarget;
use App\Models\MovimientoLaboral;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Headcount\HeadcountService;
use App\Services\Organigrama\JefeDirectoService;
use Database\Seeders\RolesYPermisosSeeder;

/*
 * Organigrama editable (docs/ORGANIGRAMA.md): Q1/Q3 = regiones de la matriz
 * comercial, cada una ligada a su «Gerente Regional Q1/Q3». Clasificar a un
 * gerente, cubrir temporalmente una plaza y subir/bajar/mover a alguien
 * siempre recalculan el jefe directo desde el organigrama.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    config(['organigrama.puestos_raiz_sucursal' => ['Gerente de Sucursal']]);
    Sucursal::query()->update(['activo' => false]);

    $this->director = Puesto::factory()->create(['nombre' => 'Dirección Comercial', 'nivel_jerarquico' => 2, 'activo' => true]);
    $this->regionalQ1 = Puesto::factory()->create(['nombre' => 'Gerente Regional Q1', 'nivel_jerarquico' => 3, 'puesto_superior_id' => $this->director->id, 'activo' => true]);
    $this->regionalQ3 = Puesto::factory()->create(['nombre' => 'Gerente Regional Q3', 'nivel_jerarquico' => 3, 'puesto_superior_id' => $this->director->id, 'activo' => true]);
    $this->gerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'nivel_jerarquico' => 4, 'puesto_superior_id' => $this->regionalQ1->id, 'activo' => true]);
    $this->subgerente = Puesto::factory()->create(['nombre' => 'Subgerente', 'nivel_jerarquico' => 5, 'puesto_superior_id' => $this->gerente->id, 'activo' => true]);

    $this->cuernavaca = Sucursal::factory()->create(['nombre' => 'Cuernavaca', 'activo' => true, 'es_corporativo' => false]);
    $this->cordoba = Sucursal::factory()->create(['nombre' => 'Córdoba', 'activo' => true, 'es_corporativo' => false]);

    $matriz = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Matriz->value, 'nombre' => 'MATRIZ']);
    $this->q1 = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Region->value, 'nombre' => 'Región Q1', 'region' => 'Q1', 'parent_id' => $matriz->id, 'puesto_id' => $this->regionalQ1->id]);
    $this->q3 = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Region->value, 'nombre' => 'Región Q3', 'region' => 'Q3', 'parent_id' => $matriz->id, 'puesto_id' => $this->regionalQ3->id]);
    NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'CUERNAVACA', 'parent_id' => $this->q1->id, 'sucursal_id' => $this->cuernavaca->id]);
    NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'CORDOBA', 'parent_id' => $this->q3->id, 'sucursal_id' => $this->cordoba->id]);

    $persona = fn (string $nombre, Puesto $puesto, Sucursal $sucursal): Colaborador => Colaborador::factory()->create([
        'name' => $nombre, 'apellidos' => null, 'estatus' => 'activo',
        'puesto_id' => $puesto->id, 'sucursal_principal_id' => $sucursal->id, 'jefe_id' => null,
    ]);

    $this->dc = $persona('Directora', $this->director, $this->cuernavaca);
    $this->titularQ1 = $persona('Regional Uno', $this->regionalQ1, $this->cuernavaca);
    $this->titularQ3 = $persona('Regional Tres', $this->regionalQ3, $this->cordoba);
    $this->gerenteCuernavaca = $persona('Gerente Cuernavaca', $this->gerente, $this->cuernavaca);
    $this->subCuernavaca = $persona('Sub Cuernavaca', $this->subgerente, $this->cuernavaca);
    $this->gerenteCordoba = $persona('Gerente Cordoba', $this->gerente, $this->cordoba);

    app(JefeDirectoService::class)->sincronizar();

    $this->rh = clUsuario('rh_admin');
});

/**
 * @return array<string, array<string, mixed>>
 */
function nodosEditable(User $usuario): array
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

test('sin clasificar, el gerente se ve «Por clasificar» con la sugerencia de la matriz y su jefe provisional es el regional de su región', function () {
    $nodos = nodosEditable($this->rh);
    $gerente = $nodos['p'.$this->gerenteCuernavaca->id];

    expect($gerente['clasificacion']['estado'])->toBe('por_clasificar')
        ->and($gerente['clasificacion']['region'])->toBeNull()
        ->and($gerente['clasificacion']['sugerida']['id'])->toBe($this->q1->id)
        // Un solo lugar en el árbol (no aparece en Q1 y Q3 a la vez).
        ->and(collect($nodos)->where('persona.id', $this->gerenteCuernavaca->id)->where('tipo', 'persona')->count())->toBe(1)
        ->and($this->gerenteCuernavaca->fresh()->jefe_id)->toBe($this->titularQ1->id);

    // Los puestos que no reportan a un regional no se clasifican.
    expect($nodos['p'.$this->subCuernavaca->id]['clasificacion'])->toBeNull();
});

test('clasificar en Q3 persiste, cuelga del regional Q3 en el árbol y su jefe directo cambia con movimiento y bitácora', function () {
    $this->actingAs($this->rh)
        ->put(route('administracion.jerarquia-puestos.personas.clasificar', $this->gerenteCuernavaca), ['region_id' => $this->q3->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $clasificacion = ClasificacionRegion::query()->sole();
    expect($clasificacion->colaborador_id)->toBe($this->gerenteCuernavaca->id)
        ->and($clasificacion->region_id)->toBe($this->q3->id)
        ->and($clasificacion->clasificada_por)->toBe($this->rh->id);

    // Jefe directo derivado: ahora el regional de Q3 (no se capturó a mano).
    expect($this->gerenteCuernavaca->fresh()->jefe_id)->toBe($this->titularQ3->id)
        // Su subgerente sigue reportándole a él.
        ->and($this->subCuernavaca->fresh()->jefe_id)->toBe($this->gerenteCuernavaca->id);

    expect(MovimientoLaboral::query()
        ->where('colaborador_id', $this->gerenteCuernavaca->id)
        ->where('tipo_movimiento', TipoMovimientoLaboral::CambioJefe->value)
        ->where('jefe_nuevo_colaborador_id', $this->titularQ3->id)
        ->exists())->toBeTrue();
    $this->assertDatabaseHas('activity_log', ['log_name' => 'organigrama', 'description' => 'region_clasificada', 'subject_id' => $this->gerenteCuernavaca->id]);

    $nodos = nodosEditable($this->rh);
    $gerente = $nodos['p'.$this->gerenteCuernavaca->id];
    expect($gerente['clasificacion']['estado'])->toBe('clasificado')
        ->and($gerente['clasificacion']['region']['id'])->toBe($this->q3->id)
        ->and($gerente['padre'])->toBe('p'.$this->titularQ3->id);

    // Regresar a «Por clasificar» vuelve al regional de la matriz.
    $this->actingAs($this->rh)
        ->put(route('administracion.jerarquia-puestos.personas.clasificar', $this->gerenteCuernavaca), ['region_id' => null])
        ->assertSessionHasNoErrors();

    expect(ClasificacionRegion::query()->count())->toBe(0)
        ->and($this->gerenteCuernavaca->fresh()->jefe_id)->toBe($this->titularQ1->id);
});

test('solo se clasifica a quien reporta a un gerente regional', function () {
    $this->actingAs($this->rh)
        ->put(route('administracion.jerarquia-puestos.personas.clasificar', $this->subCuernavaca), ['region_id' => $this->q1->id])
        ->assertSessionHasErrors('colaborador_id');

    expect(ClasificacionRegion::query()->count())->toBe(0);
});

test('«Cubre temporalmente» se marca sin fechas ni motivo, no altera headcount y sus subordinados le reportan; quitarla es un clic', function () {
    HeadcountTarget::factory()->create(['sucursal_id' => $this->cordoba->id, 'puesto_id' => $this->regionalQ3->id, 'plantilla_autorizada' => 1]);
    HeadcountTarget::factory()->create(['sucursal_id' => $this->cuernavaca->id, 'puesto_id' => $this->gerente->id, 'plantilla_autorizada' => 1]);

    // Se va la titular de Q3: la plaza queda vacante.
    $this->titularQ3->update(['estatus' => 'inactivo']);
    app(JefeDirectoService::class)->sincronizar();
    expect($this->gerenteCordoba->fresh()->jefe_id)->toBe($this->dc->id);

    $headcount = app(HeadcountService::class);
    $antes = $headcount->plantillaDePar($this->cordoba->id, $this->regionalQ3->id);
    $antesCuernavaca = $headcount->plantillaDePar($this->cuernavaca->id, $this->gerente->id);

    // Q1 cubre Q3: solo colaborador + plaza (sin motivo, sin fechas).
    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.coberturas.store'), [
            'colaborador_id' => $this->titularQ1->id,
            'puesto_id' => $this->regionalQ3->id,
            'region_id' => $this->q3->id,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $cobertura = CoberturaPuesto::query()->sole();
    expect($cobertura->activa)->toBeTrue()
        ->and($cobertura->motivo->value)->toBe('vacante')
        ->and($cobertura->fecha_fin)->toBeNull()
        ->and($cobertura->registrada_por)->toBe($this->rh->id)
        // Conserva su puesto titular.
        ->and($this->titularQ1->fresh()->puesto_id)->toBe($this->regionalQ1->id);

    // Auditable también en su expediente.
    expect(MovimientoLaboral::query()
        ->where('colaborador_id', $this->titularQ1->id)
        ->where('tipo_movimiento', TipoMovimientoLaboral::CoberturaTemporal->value)
        ->where('puesto_nuevo_id', $this->regionalQ3->id)
        ->exists())->toBeTrue();

    // Headcount: la plaza de Q3 sigue vacante y nada más cambia.
    expect($headcount->plantillaDePar($this->cordoba->id, $this->regionalQ3->id))->toBe($antes)
        ->and($antes['faltantes'])->toBe(1)
        ->and($headcount->plantillaDePar($this->cuernavaca->id, $this->gerente->id))->toBe($antesCuernavaca);

    // Regla: los subordinados de la plaza cubierta reportan a quien la cubre.
    app(JefeDirectoService::class)->sincronizar();
    expect($this->gerenteCordoba->fresh()->jefe_id)->toBe($this->titularQ1->id);

    $nodos = nodosEditable($this->rh);
    $nodoCobertura = collect($nodos)->first(fn (array $n) => $n['tipo'] === 'cobertura');
    expect($nodoCobertura['persona']['id'])->toBe($this->titularQ1->id)
        ->and($nodoCobertura['cobertura']['registrada_por'])->toBe($this->rh->name)
        ->and($nodos['p'.$this->gerenteCordoba->id]['padre'])->toBe($nodoCobertura['clave']);

    // Quitar: un clic.
    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.coberturas.finalizar', $cobertura))
        ->assertRedirect();

    expect($cobertura->refresh()->activa)->toBeFalse()
        ->and($cobertura->finalizada_por)->toBe($this->rh->id);
    app(JefeDirectoService::class)->sincronizar();
    expect($this->gerenteCordoba->fresh()->jefe_id)->toBe($this->dc->id);
});

test('subir de puesto registra la promoción, recalcula su jefe y el de quienes le reportaban', function () {
    // Cuernavaca sin gerente: el subgerente sube a gerente de su sucursal.
    $this->gerenteCuernavaca->update(['sucursal_principal_id' => $this->cordoba->id]);
    app(JefeDirectoService::class)->sincronizar();

    $vista = $this->actingAs($this->rh)
        ->postJson(route('administracion.jerarquia-puestos.personas.vista-previa', $this->subCuernavaca), [
            'accion' => 'subir', 'puesto_id' => $this->gerente->id, 'sucursal_id' => $this->cuernavaca->id, 'region_id' => $this->q1->id,
        ])
        ->assertOk()
        ->json();

    // La vista previa no guarda nada.
    expect($vista['descripcion'])->toContain('Vas a mover a Sub Cuernavaca de Subgerente · Cuernavaca a Gerente de Sucursal · Cuernavaca')
        ->and($vista['jefe_nuevo'])->toBe('Regional Uno')
        ->and($this->subCuernavaca->fresh()->puesto_id)->toBe($this->subgerente->id);

    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.personas.mover', $this->subCuernavaca), [
            'accion' => 'subir', 'puesto_id' => $this->gerente->id, 'sucursal_id' => $this->cuernavaca->id, 'region_id' => $this->q1->id,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $sub = $this->subCuernavaca->fresh();
    expect($sub->puesto_id)->toBe($this->gerente->id)
        ->and($sub->jefe_id)->toBe($this->titularQ1->id)
        ->and(ClasificacionRegion::query()->where('colaborador_id', $sub->id)->value('region_id'))->toBe($this->q1->id);

    // (Su jefe ya era el regional Q1 mientras Cuernavaca no tenía gerente:
    // no hay «cambio de jefe» que registrar, solo la promoción.)
    expect(MovimientoLaboral::query()->where('colaborador_id', $sub->id)->where('tipo_movimiento', TipoMovimientoLaboral::Promocion->value)->exists())->toBeTrue();
    $this->assertDatabaseHas('activity_log', ['log_name' => 'organigrama', 'description' => 'persona_movida', 'subject_id' => $sub->id]);
});

test('bajar y mover bajo otra plaza pasan por el movimiento laboral y el jefe derivado', function () {
    // Bajar: el gerente de Córdoba pasa a subgerente de Córdoba.
    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.personas.mover', $this->gerenteCordoba), [
            'accion' => 'bajar', 'puesto_id' => $this->subgerente->id, 'sucursal_id' => $this->cordoba->id,
        ])
        ->assertSessionHasNoErrors();

    expect($this->gerenteCordoba->fresh()->puesto_id)->toBe($this->subgerente->id)
        ->and(MovimientoLaboral::query()->where('colaborador_id', $this->gerenteCordoba->id)->where('tipo_movimiento', TipoMovimientoLaboral::CambioPuesto->value)->exists())->toBeTrue();

    // Mover bajo…: el subgerente de Cuernavaca queda bajo la gerencia de
    // Córdoba (mismo puesto, otra sucursal).
    $opciones = $this->actingAs($this->rh)
        ->getJson(route('administracion.jerarquia-puestos.personas.opciones', $this->subCuernavaca))
        ->assertOk()
        ->json();

    $destino = collect($opciones['mover_bajo'])->firstWhere('sucursal_id', $this->cordoba->id);
    expect($destino)->not->toBeNull()
        ->and($destino['puesto_id'])->toBe($this->subgerente->id);

    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.personas.mover', $this->subCuernavaca), [
            'accion' => 'mover', 'puesto_id' => $destino['puesto_id'], 'sucursal_id' => $destino['sucursal_id'],
        ])
        ->assertSessionHasNoErrors();

    $sub = $this->subCuernavaca->fresh();
    expect($sub->sucursal_principal_id)->toBe($this->cordoba->id)
        // Córdoba ya no tiene gerente (bajó a subgerente): sube al regional Q3.
        ->and($sub->jefe_id)->toBe($this->titularQ3->id)
        ->and(MovimientoLaboral::query()->where('colaborador_id', $sub->id)->where('tipo_movimiento', TipoMovimientoLaboral::CambioSucursal->value)->exists())->toBeTrue();
});

test('mover a alguien a la misma plaza se rechaza sin tocar nada', function () {
    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.personas.mover', $this->subCuernavaca), [
            'accion' => 'mover', 'puesto_id' => $this->subgerente->id, 'sucursal_id' => $this->cuernavaca->id,
        ])
        ->assertSessionHasErrors('puesto_id');

    expect(MovimientoLaboral::query()->where('colaborador_id', $this->subCuernavaca->id)->count())->toBe(0);
});

test('sin permiso de editar el organigrama: 403 al clasificar, cubrir, ver opciones o mover', function () {
    $gerente = clUsuario('gerente_sucursal');
    expect($gerente->can('organigrama.ver'))->toBeTrue();

    $this->actingAs($gerente)
        ->put(route('administracion.jerarquia-puestos.personas.clasificar', $this->gerenteCuernavaca), ['region_id' => $this->q3->id])
        ->assertForbidden();
    $this->actingAs($gerente)
        ->getJson(route('administracion.jerarquia-puestos.personas.opciones', $this->subCuernavaca))
        ->assertForbidden();
    $this->actingAs($gerente)
        ->postJson(route('administracion.jerarquia-puestos.personas.vista-previa', $this->subCuernavaca), ['accion' => 'subir', 'puesto_id' => $this->gerente->id])
        ->assertForbidden();
    $this->actingAs($gerente)
        ->post(route('administracion.jerarquia-puestos.personas.mover', $this->subCuernavaca), ['accion' => 'subir', 'puesto_id' => $this->gerente->id])
        ->assertForbidden();
    $this->actingAs($gerente)
        ->post(route('administracion.jerarquia-puestos.coberturas.store'), ['colaborador_id' => $this->titularQ1->id, 'puesto_id' => $this->regionalQ3->id, 'region_id' => $this->q3->id])
        ->assertForbidden();

    expect(ClasificacionRegion::query()->count())->toBe(0)
        ->and(CoberturaPuesto::query()->count())->toBe(0)
        ->and($this->subCuernavaca->fresh()->puesto_id)->toBe($this->subgerente->id);

    // Ver sí puede, pero sin acciones.
    $this->actingAs($gerente)
        ->get(route('administracion.jerarquia-puestos.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('coberturas.puedeEditar', false));
});

test('una plaza corporativa vacante también se cubre temporalmente (sin sucursal ni región)', function () {
    $asistente = Puesto::factory()->create(['nombre' => 'Asistente de Dirección Comercial', 'nivel_jerarquico' => 3, 'puesto_superior_id' => $this->director->id, 'activo' => true]);

    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.coberturas.store'), [
            'colaborador_id' => $this->subCuernavaca->id,
            'puesto_id' => $asistente->id,
        ])
        ->assertSessionHasNoErrors();

    $cobertura = CoberturaPuesto::query()->sole();
    expect($cobertura->sucursal_id)->toBeNull()
        ->and($cobertura->region_id)->toBeNull();

    $nodos = nodosEditable($this->rh);
    $nodo = collect($nodos)->first(fn (array $n) => $n['tipo'] === 'cobertura');
    expect($nodo['puesto']['id'])->toBe($asistente->id)
        ->and($nodo['persona']['id'])->toBe($this->subCuernavaca->id)
        ->and($nodo['padre'])->toBe('p'.$this->dc->id);

    // Un puesto de sucursal sí exige sucursal o región.
    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.coberturas.store'), [
            'colaborador_id' => $this->gerenteCordoba->id,
            'puesto_id' => $this->subgerente->id,
        ])
        ->assertSessionHasErrors('sucursal_id');
});
