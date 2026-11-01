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
use Illuminate\Support\Facades\Route;

/*
 * Organigrama editable (docs/ORGANIGRAMA.md): cadena única Gerente Regional →
 * Subgerente Regional → Gerente de Sucursal; Q1/Q3 son solo dato territorial.
 * Cubrir temporalmente una plaza y subir/bajar/mover a alguien siempre
 * recalculan el jefe directo desde el organigrama.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    config(['organigrama.puestos_raiz_sucursal' => ['Gerente de Sucursal']]);
    Sucursal::query()->update(['activo' => false]);

    $this->director = Puesto::factory()->create(['nombre' => 'Dirección Comercial', 'nivel_jerarquico' => 2, 'activo' => true]);
    $this->regional = Puesto::factory()->create(['nombre' => 'Gerente Regional', 'nivel_jerarquico' => 3, 'puesto_superior_id' => $this->director->id, 'activo' => true]);
    $this->subregional = Puesto::factory()->create(['nombre' => 'Subgerente Regional', 'nivel_jerarquico' => 4, 'puesto_superior_id' => $this->regional->id, 'activo' => true]);
    $this->gerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'nivel_jerarquico' => 5, 'puesto_superior_id' => $this->subregional->id, 'activo' => true]);
    $this->subgerente = Puesto::factory()->create(['nombre' => 'Subgerente', 'nivel_jerarquico' => 6, 'puesto_superior_id' => $this->gerente->id, 'activo' => true]);

    $this->cuernavaca = Sucursal::factory()->create(['nombre' => 'Cuernavaca', 'activo' => true, 'es_corporativo' => false]);
    $this->cordoba = Sucursal::factory()->create(['nombre' => 'Córdoba', 'activo' => true, 'es_corporativo' => false]);

    $matriz = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Matriz->value, 'nombre' => 'MATRIZ']);
    $this->q1 = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Region->value, 'nombre' => 'Región Q1', 'region' => 'Q1', 'parent_id' => $matriz->id]);
    $this->q3 = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Region->value, 'nombre' => 'Región Q3', 'region' => 'Q3', 'parent_id' => $matriz->id]);
    NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'CUERNAVACA', 'parent_id' => $this->q1->id, 'sucursal_id' => $this->cuernavaca->id]);
    NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'CORDOBA', 'parent_id' => $this->q3->id, 'sucursal_id' => $this->cordoba->id]);

    $persona = fn (string $nombre, Puesto $puesto, Sucursal $sucursal): Colaborador => Colaborador::factory()->create([
        'name' => $nombre, 'apellidos' => null, 'estatus' => 'activo',
        'puesto_id' => $puesto->id, 'sucursal_principal_id' => $sucursal->id, 'jefe_id' => null,
    ]);

    $this->dc = $persona('Directora', $this->director, $this->cuernavaca);
    $this->titularRegional = $persona('Regional Uno', $this->regional, $this->cuernavaca);
    $this->titularSubregional = $persona('Regional Tres', $this->subregional, $this->cordoba);
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

test('el gerente de sucursal cuelga del Subgerente Regional y la clasificación Q1/Q3 ya no existe', function () {
    $nodos = nodosEditable($this->rh);
    $gerente = $nodos['p'.$this->gerenteCuernavaca->id];

    // Un solo lugar en el árbol, sin chip «Por clasificar».
    expect(collect($nodos)->where('persona.id', $this->gerenteCuernavaca->id)->where('tipo', 'persona')->count())->toBe(1)
        ->and($gerente['padre'])->toBe('p'.$this->titularSubregional->id)
        ->and($gerente['clasificacion'] ?? null)->toBeNull()
        ->and($this->gerenteCuernavaca->fresh()->jefe_id)->toBe($this->titularSubregional->id)
        // Su subgerente sigue reportándole a él.
        ->and($this->subCuernavaca->fresh()->jefe_id)->toBe($this->gerenteCuernavaca->id)
        ->and(Route::has('administracion.jerarquia-puestos.personas.clasificar'))->toBeFalse();

    expect(ClasificacionRegion::query()->count())->toBe(0);
});

test('«Cubre temporalmente» se marca sin fechas ni motivo, no altera headcount y sus subordinados le reportan; quitarla es un clic', function () {
    HeadcountTarget::factory()->create(['sucursal_id' => $this->cordoba->id, 'puesto_id' => $this->subregional->id, 'plantilla_autorizada' => 1]);
    HeadcountTarget::factory()->create(['sucursal_id' => $this->cuernavaca->id, 'puesto_id' => $this->gerente->id, 'plantilla_autorizada' => 1]);

    // Se va el titular de Subgerente Regional: la plaza queda vacante.
    $this->titularSubregional->update(['estatus' => 'inactivo']);
    app(JefeDirectoService::class)->sincronizar();
    // Personal de sucursal nunca reporta a Dirección Comercial: sube al
    // siguiente de la cadena, el Gerente Regional.
    expect($this->gerenteCordoba->fresh()->jefe_id)->toBe($this->titularRegional->id);

    $headcount = app(HeadcountService::class);
    $antes = $headcount->plantillaDePar($this->cordoba->id, $this->subregional->id);
    $antesCuernavaca = $headcount->plantillaDePar($this->cuernavaca->id, $this->gerente->id);

    // El Gerente Regional cubre la plaza: solo colaborador + plaza (sin motivo, sin fechas).
    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.coberturas.store'), [
            'colaborador_id' => $this->titularRegional->id,
            'puesto_id' => $this->subregional->id,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $cobertura = CoberturaPuesto::query()->sole();
    expect($cobertura->activa)->toBeTrue()
        ->and($cobertura->motivo->value)->toBe('vacante')
        ->and($cobertura->fecha_fin)->toBeNull()
        ->and($cobertura->registrada_por)->toBe($this->rh->id)
        // Conserva su puesto titular.
        ->and($this->titularRegional->fresh()->puesto_id)->toBe($this->regional->id);

    // Auditable también en su expediente.
    expect(MovimientoLaboral::query()
        ->where('colaborador_id', $this->titularRegional->id)
        ->where('tipo_movimiento', TipoMovimientoLaboral::CoberturaTemporal->value)
        ->where('puesto_nuevo_id', $this->subregional->id)
        ->exists())->toBeTrue();

    // Headcount: puesto de empresa cubierto ya está atendido (no abre vacante,
    // docs/HEADCOUNT_Y_VACANTES.md) sin aumentar el autorizado; nada más cambia.
    expect($antes['faltantes'])->toBe(1)
        ->and($headcount->plantillaDePar($this->cordoba->id, $this->subregional->id))->toBe(['autorizada' => 1, 'ocupada' => 1, 'faltantes' => 0])
        ->and($headcount->plantillaDePar($this->cuernavaca->id, $this->gerente->id))->toBe($antesCuernavaca);

    // Regla: los subordinados de la plaza cubierta reportan a quien la cubre.
    app(JefeDirectoService::class)->sincronizar();
    expect($this->gerenteCordoba->fresh()->jefe_id)->toBe($this->titularRegional->id);

    $nodos = nodosEditable($this->rh);
    $nodoCobertura = collect($nodos)->first(fn (array $n) => $n['tipo'] === 'cobertura');
    expect($nodoCobertura['persona']['id'])->toBe($this->titularRegional->id)
        ->and($nodoCobertura['cobertura']['registrada_por'])->toBe($this->rh->name)
        ->and($nodos['p'.$this->gerenteCordoba->id]['padre'])->toBe($nodoCobertura['clave']);

    // Quitar: un clic.
    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.coberturas.finalizar', $cobertura))
        ->assertRedirect();

    expect($cobertura->refresh()->activa)->toBeFalse()
        ->and($cobertura->finalizada_por)->toBe($this->rh->id);
    app(JefeDirectoService::class)->sincronizar();
    // Sin cobertura la plaza vuelve a faltar y el jefe sigue siendo el Gerente
    // Regional, no Dirección Comercial.
    expect($headcount->plantillaDePar($this->cordoba->id, $this->subregional->id))->toBe($antes);
    expect($this->gerenteCordoba->fresh()->jefe_id)->toBe($this->titularRegional->id)
        ->and($this->gerenteCordoba->fresh()->jefe_id)->not->toBe($this->dc->id);
});

test('subir de puesto registra la promoción, recalcula su jefe y el de quienes le reportaban', function () {
    // Cuernavaca sin gerente: el subgerente sube a gerente de su sucursal.
    $this->gerenteCuernavaca->update(['sucursal_principal_id' => $this->cordoba->id]);
    app(JefeDirectoService::class)->sincronizar();

    $vista = $this->actingAs($this->rh)
        ->postJson(route('administracion.jerarquia-puestos.personas.vista-previa', $this->subCuernavaca), [
            'accion' => 'subir', 'puesto_id' => $this->gerente->id, 'sucursal_id' => $this->cuernavaca->id,
        ])
        ->assertOk()
        ->json();

    // La vista previa no guarda nada.
    expect($vista['descripcion'])->toContain('Vas a mover a Sub Cuernavaca de Subgerente · Cuernavaca a Gerente de Sucursal · Cuernavaca')
        ->and($vista['jefe_nuevo'])->toBe('Regional Tres')
        ->and($this->subCuernavaca->fresh()->puesto_id)->toBe($this->subgerente->id);

    $this->actingAs($this->rh)
        ->post(route('administracion.jerarquia-puestos.personas.mover', $this->subCuernavaca), [
            'accion' => 'subir', 'puesto_id' => $this->gerente->id, 'sucursal_id' => $this->cuernavaca->id,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $sub = $this->subCuernavaca->fresh();
    expect($sub->puesto_id)->toBe($this->gerente->id)
        ->and($sub->jefe_id)->toBe($this->titularSubregional->id);

    // (Su jefe ya era el Subgerente Regional mientras Cuernavaca no tenía gerente:
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
        // Córdoba ya no tiene gerente (bajó a subgerente): sube al Subgerente Regional.
        ->and($sub->jefe_id)->toBe($this->titularSubregional->id)
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

test('sin permiso de editar el organigrama: 403 al cubrir, ver opciones o mover', function () {
    $gerente = clUsuario('gerente_sucursal');
    expect($gerente->can('organigrama.ver'))->toBeTrue();

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
        ->post(route('administracion.jerarquia-puestos.coberturas.store'), ['colaborador_id' => $this->titularRegional->id, 'puesto_id' => $this->subregional->id])
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
