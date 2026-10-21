<?php

use App\Enums\TipoNodoComercial;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Organigrama\JefeDirectoService;
use App\Services\Organigrama\OrganigramaPersonasService;
use App\Services\Solicitudes\AprobacionOrganizacionalService;
use Database\Seeders\RolesYPermisosSeeder;

/*
 * Caso de producción (2026-10-21): un Gestor de San Luis Potosí salía con
 * «Jefe organizacional: Dirección Comercial». La cadena es Gestor →
 * Subgerente → Gerente de Sucursal (de SU sucursal) → Gerente Regional de
 * la región de su sucursal en la matriz → Dirección Comercial; solo sube un
 * nivel cuando la plaza de arriba está VACANTE (ni titular vigente ni
 * cobertura). Dirección Comercial aparece solo si la sucursal y su regional
 * están vacantes (docs/ORGANIGRAMA.md, «Jefe directo = organigrama»).
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    config(['organigrama.puestos_raiz_sucursal' => ['Gerente de Sucursal', 'Coordinadora de Sucursal']]);
    Sucursal::query()->update(['activo' => false]);

    $this->direccion = Puesto::factory()->create(['nombre' => 'Dirección Comercial', 'nivel_jerarquico' => 2, 'activo' => true]);
    $this->regionalQ1 = Puesto::factory()->create(['nombre' => 'Gerente Regional Q1', 'nivel_jerarquico' => 3, 'puesto_superior_id' => $this->direccion->id, 'activo' => true]);
    $this->gerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'nivel_jerarquico' => 4, 'puesto_superior_id' => $this->regionalQ1->id, 'activo' => true]);
    $this->subgerente = Puesto::factory()->create(['nombre' => 'Subgerente', 'nivel_jerarquico' => 5, 'puesto_superior_id' => $this->gerente->id, 'activo' => true]);
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'nivel_jerarquico' => 6, 'puesto_superior_id' => $this->subgerente->id, 'activo' => true]);

    $this->slp = Sucursal::factory()->create(['nombre' => 'San Luis Potosí', 'activo' => true, 'es_corporativo' => false]);
    $this->cuernavaca = Sucursal::factory()->create(['nombre' => 'Cuernavaca', 'activo' => true, 'es_corporativo' => false]);
    $this->corporativo = Sucursal::factory()->create(['nombre' => 'Corporativo', 'activo' => true, 'es_corporativo' => true]);

    $matriz = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Matriz->value, 'nombre' => 'MATRIZ']);
    $this->q1 = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Region->value, 'nombre' => 'Región Q1', 'region' => 'Q1', 'parent_id' => $matriz->id, 'puesto_id' => $this->regionalQ1->id]);
    NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'SAN LUIS POTOSI', 'parent_id' => $this->q1->id, 'sucursal_id' => $this->slp->id]);
    NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'CUERNAVACA', 'parent_id' => $this->q1->id, 'sucursal_id' => $this->cuernavaca->id]);

    $this->alberto = personaSucursal('Alberto', $this->direccion, $this->corporativo);
    // Gerente de OTRA sucursal: nunca debe salir como jefe de alguien de SLP.
    $this->gerenteCuernavaca = personaSucursal('Gerente Cuernavaca', $this->gerente, $this->cuernavaca);
});

function personaSucursal(string $nombre, Puesto $puesto, Sucursal $sucursal, string $estatus = 'activo'): Colaborador
{
    return Colaborador::factory()->create([
        'name' => $nombre,
        'apellidos' => null,
        'puesto_id' => $puesto->id,
        'sucursal_principal_id' => $sucursal->id,
        'estatus' => $estatus,
        'jefe_id' => null,
    ]);
}

test('un gestor de una sucursal con gerente (sin subgerente) reporta a SU gerente, aunque ambos estén en incorporación', function () {
    $regional = personaSucursal('Regional Uno', $this->regionalQ1, $this->cuernavaca);
    $gerenteSlp = personaSucursal('Gerente SLP', $this->gerente, $this->slp, 'en_incorporacion');
    $luis = personaSucursal('Luis Gestor', $this->gestor, $this->slp, 'en_incorporacion');

    app(JefeDirectoService::class)->sincronizar();

    expect($luis->fresh()->jefe_id)->toBe($gerenteSlp->id)
        ->and($gerenteSlp->fresh()->jefe_id)->toBe($regional->id);

    // El expediente y las aprobaciones leen el mismo jefe.
    $resumen = app(AprobacionOrganizacionalService::class)->resumenResponsables($luis->fresh());
    expect($resumen['jefe_organizacional']['id'] ?? null)->toBe($gerenteSlp->id);
});

test('con subgerente en la sucursal, el gestor reporta al subgerente y este al gerente', function () {
    personaSucursal('Regional Uno', $this->regionalQ1, $this->cuernavaca);
    $gerenteSlp = personaSucursal('Gerente SLP', $this->gerente, $this->slp);
    $subSlp = personaSucursal('Sub SLP', $this->subgerente, $this->slp);
    $gestor = personaSucursal('Gestor SLP', $this->gestor, $this->slp);

    app(JefeDirectoService::class)->sincronizar();

    expect($gestor->fresh()->jefe_id)->toBe($subSlp->id)
        ->and($subSlp->fresh()->jefe_id)->toBe($gerenteSlp->id);
});

test('gerencia de la sucursal vacante: sube al regional de su región; si también está vacante, a Dirección Comercial', function () {
    $gestor = personaSucursal('Gestor SLP', $this->gestor, $this->slp, 'en_incorporacion');
    // Un gerente dado de baja no ocupa la plaza.
    personaSucursal('Gerente SLP anterior', $this->gerente, $this->slp, 'inactivo');

    app(JefeDirectoService::class)->sincronizar();

    // Regional Q1 vacante también: es el caso real de producción.
    expect($gestor->fresh()->jefe_id)->toBe($this->alberto->id);

    $regional = personaSucursal('Regional Uno', $this->regionalQ1, $this->cuernavaca);
    app(JefeDirectoService::class)->sincronizar();

    expect($gestor->fresh()->jefe_id)->toBe($regional->id);

    // RH marca que alguien cubre la gerencia de SLP: ahora es su jefe.
    $cubre = personaSucursal('Cubre SLP', $this->gerente, $this->cuernavaca);
    CoberturaPuesto::factory()->create(['colaborador_id' => $cubre->id, 'puesto_id' => $this->gerente->id, 'sucursal_id' => $this->slp->id]);
    app(JefeDirectoService::class)->sincronizar();

    expect($gestor->fresh()->jefe_id)->toBe($cubre->id);
});

test('el gerente de sucursal reporta al regional de su región, nunca a sí mismo ni a otro gerente', function () {
    $regional = personaSucursal('Regional Uno', $this->regionalQ1, $this->cuernavaca);
    $gerenteSlp = personaSucursal('Gerente SLP', $this->gerente, $this->slp);

    app(JefeDirectoService::class)->sincronizar();

    expect($gerenteSlp->fresh()->jefe_id)->toBe($regional->id)
        ->and($this->gerenteCuernavaca->fresh()->jefe_id)->toBe($regional->id)
        ->and($regional->fresh()->jefe_id)->toBe($this->alberto->id);
});

test('el puesto raíz de sucursal se reconoce sin importar mayúsculas ni acentos', function () {
    $this->gerente->update(['nombre' => 'GERENTE DE SUCURSAL']);
    $sub = personaSucursal('Sub SLP', $this->subgerente, $this->slp);
    $gerenteSlp = personaSucursal('Gerente SLP', $this->gerente, $this->slp);

    app(JefeDirectoService::class)->sincronizar();

    // Sin reconocerlo, la rama dejaba de partirse por sucursal y el
    // subgerente podía colgar del gerente de otra sucursal.
    expect($sub->fresh()->jefe_id)->toBe($gerenteSlp->id);
});

test('organigrama:explicar-jefe muestra la cadena con las plazas vacantes y avisa si el jefe guardado no coincide', function () {
    $gestor = Colaborador::factory()->create([
        'name' => 'Luis', 'apellidos' => 'Gestor', 'numero_empleado' => 'EMP-0131',
        'puesto_id' => $this->gestor->id, 'sucursal_principal_id' => $this->slp->id, 'estatus' => 'en_incorporacion',
    ]);
    personaSucursal('Gerente SLP anterior', $this->gerente, $this->slp, 'inactivo');
    app(JefeDirectoService::class)->sincronizar();
    Colaborador::query()->whereKey($gestor->id)->update(['jefe_id' => $this->gerenteCuernavaca->id]);

    $this->artisan('organigrama:explicar-jefe', ['colaborador' => 'EMP-0131'])
        ->expectsOutputToContain('Jefe según el organigrama: Alberto')
        ->expectsOutputToContain('organigrama:sincronizar-jefes')
        ->assertSuccessful();

    $explicacion = app(OrganigramaPersonasService::class)->explicarJefe($gestor->id);

    expect(array_column($explicacion['pasos'], 'tipo'))->toBe(['vacante', 'vacante', 'vacante', 'persona'])
        ->and($explicacion['pasos'][1]['no_vigentes'])->toBe(['Gerente SLP anterior (Inactivo)'])
        ->and($explicacion['jefe_id'])->toBe($this->alberto->id);
});
