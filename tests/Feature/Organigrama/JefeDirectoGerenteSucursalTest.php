<?php

use App\Enums\TipoNodoComercial;
use App\Enums\TipoTarea;
use App\Models\ClasificacionRegion;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\TareaRh;
use App\Models\User;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use App\Services\Organigrama\JefeDirectoService;
use App\Services\Organigrama\OrganigramaPersonasService;
use App\Services\Solicitudes\AprobacionOrganizacionalService;
use Database\Seeders\RolesYPermisosSeeder;

/*
 * Caso de producción (2026-10-21): un Gestor de San Luis Potosí salía con
 * «Jefe organizacional: Dirección Comercial». La cadena es Gestor →
 * Subgerente → Gerente de Sucursal (de SU sucursal) → Gerente Regional de
 * la región de su sucursal en la matriz; solo sube un nivel cuando la plaza
 * de arriba está VACANTE (ni titular vigente ni cobertura). Regla del dueño:
 * el personal de sucursal NUNCA reporta a Dirección Comercial — tras el
 * regional de su región sigue la regional de respaldo y, si no hay ningún
 * regional, «sin jefe asignable» (docs/ORGANIGRAMA.md).
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

test('gerencia de la sucursal vacante: sube al regional de su región; sin ningún regional queda «sin jefe asignable», nunca Dirección Comercial', function () {
    $gestor = personaSucursal('Gestor SLP', $this->gestor, $this->slp, 'en_incorporacion');
    // Un gerente dado de baja no ocupa la plaza.
    personaSucursal('Gerente SLP anterior', $this->gerente, $this->slp, 'inactivo');

    $resultado = app(JefeDirectoService::class)->sincronizar();

    // Ni gerencia, ni regional Q1, ni ningún otro regional en la empresa.
    expect($gestor->fresh()->jefe_id)->toBeNull()
        ->and($resultado['sin_jefe_asignable'])->toContain($gestor->id)
        ->and($resultado['sin_jefe_asignable'])->toContain($this->gerenteCuernavaca->id);

    $pendiente = TareaRh::query()->where('tipo', TipoTarea::OrganigramaSinJefe->value)->where('colaborador_id', $gestor->id)->whereNull('resuelta_en')->first();
    expect($pendiente)->not->toBeNull()
        ->and($pendiente->asignado_permiso)->toBe('organigrama.editar');

    $regional = personaSucursal('Regional Uno', $this->regionalQ1, $this->cuernavaca);
    app(JefeDirectoService::class)->sincronizar();

    expect($gestor->fresh()->jefe_id)->toBe($regional->id)
        ->and($pendiente->fresh()->resuelta_en)->not->toBeNull();

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

/*
 * Caso real de producción (SLP, 2026-10-21): San Luis Potosí sin Gerente
 * ni Subgerente, Región Q1 sin Gerente Regional, y la única regional de la
 * empresa (Mónica, «Regional de Operaciones» en Tula) es titular de
 * Gerente Regional Q3. Regla del dueño: el gestor reporta (y se aprueba)
 * con la regional, NUNCA con Dirección Comercial.
 */
function escenarioSlp(object $t): array
{
    $regionalQ3 = Puesto::factory()->create(['nombre' => 'Gerente Regional Q3', 'nivel_jerarquico' => 3, 'puesto_superior_id' => $t->direccion->id, 'activo' => true]);
    $tula = Sucursal::factory()->create(['nombre' => 'Tula', 'activo' => true, 'es_corporativo' => false]);
    $q3 = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Region->value, 'nombre' => 'Región Q3', 'region' => 'Q3', 'parent_id' => $t->q1->parent_id, 'puesto_id' => $regionalQ3->id]);
    NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'TULA', 'parent_id' => $q3->id, 'sucursal_id' => $tula->id]);

    $monica = personaSucursal('Mónica', $regionalQ3, $tula);
    User::factory()->create(['colaborador_id' => $monica->id]);
    User::factory()->create(['colaborador_id' => $t->alberto->id]);

    $luis = Colaborador::factory()->create([
        'name' => 'Luis', 'apellidos' => 'Gestor', 'numero_empleado' => 'EMP-0131',
        'puesto_id' => $t->gestor->id, 'sucursal_principal_id' => $t->slp->id, 'estatus' => 'en_incorporacion', 'jefe_id' => null,
    ]);

    return [$monica, $luis, $regionalQ3, $tula];
}

test('SLP sin gerente y Q1 sin regional: el gestor reporta y se aprueba con la regional (Q3), nunca con Dirección Comercial', function () {
    [$monica, $luis] = escenarioSlp($this);
    personaSucursal('Gerente SLP anterior', $this->gerente, $this->slp, 'inactivo');

    $resultado = app(JefeDirectoService::class)->sincronizar();

    expect($luis->fresh()->jefe_id)->toBe($monica->id)
        // El gerente de Cuernavaca (Q1 sin regional) también sube a ella.
        ->and($this->gerenteCuernavaca->fresh()->jefe_id)->toBe($monica->id)
        // La regional sí reporta a Dirección Comercial.
        ->and($monica->fresh()->jefe_id)->toBe($this->alberto->id)
        ->and($resultado['sin_jefe_asignable'])->toBe([]);

    $resumen = app(AprobacionOrganizacionalService::class)->resumenResponsables($luis->fresh());
    expect($resumen['jefe_organizacional']['id'] ?? null)->toBe($monica->id)
        ->and($resumen['responsable_aprobaciones']['id'] ?? null)->toBe($monica->id);

    // Gerente de Sucursal clasificado en Q1 (vacante, sin cobertura): su
    // responsable de aprobaciones es la regional de respaldo, no se detiene
    // ni sube a Dirección Comercial.
    ClasificacionRegion::query()->create(['colaborador_id' => $this->gerenteCuernavaca->id, 'region_id' => $this->q1->id]);
    $responsable = app(AprobacionOrganizacionalService::class)->responsableDeAprobacion($this->gerenteCuernavaca->fresh());
    expect($responsable['origen'])->toBe(AprobacionOrganizacionalService::ORIGEN_REGIONAL_RESPALDO)
        ->and($responsable['bloqueado'])->toBeFalse()
        ->and($responsable['colaboradores']->pluck('id')->all())->toBe([$monica->id]);

    // Regional de la sucursal (notificaciones, candidatos): también ella.
    expect(app(OrganizacionJerarquiaService::class)->regionalesDe($this->slp->id)->pluck('colaborador_id')->all())->toBe([$monica->id]);
});

test('el regional de respaldo se puede fijar por configuración (id o número de empleado)', function () {
    [$monica, $luis] = escenarioSlp($this);
    // Otra regional con un puesto regional que no es el de Q1 (por nombre en
    // config organigrama.regional_respaldo.puestos).
    $operaciones = Puesto::factory()->create(['nombre' => 'Regional de Operaciones', 'nivel_jerarquico' => 3, 'puesto_superior_id' => $this->direccion->id, 'activo' => true]);
    $otra = Colaborador::factory()->create(['name' => 'Otra regional', 'numero_empleado' => 'EMP-9001', 'puesto_id' => $operaciones->id, 'sucursal_principal_id' => $this->corporativo->id, 'estatus' => 'activo']);

    app(JefeDirectoService::class)->sincronizar();
    // Sin fijar: la primera regional vigente por id (Mónica se creó antes).
    expect($luis->fresh()->jefe_id)->toBe($monica->id);

    config(['organigrama.regional_respaldo.colaborador' => 'EMP-9001']);
    app(JefeDirectoService::class)->sincronizar();

    expect($luis->fresh()->jefe_id)->toBe($otra->id);
});

test('organigrama:explicar-jefe muestra la cadena con las plazas vacantes, la regional de respaldo y avisa si el jefe guardado no coincide', function () {
    [$monica, $luis] = escenarioSlp($this);
    personaSucursal('Gerente SLP anterior', $this->gerente, $this->slp, 'inactivo');
    app(JefeDirectoService::class)->sincronizar();
    Colaborador::query()->whereKey($luis->id)->update(['jefe_id' => $this->alberto->id]);

    $this->artisan('organigrama:explicar-jefe', ['colaborador' => 'EMP-0131'])
        ->expectsOutputToContain('Jefe según el organigrama: Mónica')
        ->expectsOutputToContain('organigrama:sincronizar-jefes')
        ->assertSuccessful();

    $explicacion = app(OrganigramaPersonasService::class)->explicarJefe($luis->id);

    expect(array_column($explicacion['pasos'], 'tipo'))->toBe(['vacante', 'vacante', 'vacante', 'persona', 'respaldo'])
        ->and($explicacion['pasos'][1]['no_vigentes'])->toBe(['Gerente SLP anterior (Inactivo)'])
        ->and($explicacion['pasos'][3]['nota'])->toContain('nunca reporta a Dirección Comercial')
        ->and($explicacion['jefe_id'])->toBe($monica->id)
        ->and($explicacion['respaldo'])->toBeTrue()
        ->and($explicacion['sin_jefe_asignable'])->toBeFalse();
});

test('sin ningún regional, explicar-jefe avisa «sin jefe asignable»', function () {
    Colaborador::factory()->create([
        'name' => 'Luis', 'apellidos' => 'Gestor', 'numero_empleado' => 'EMP-0131',
        'puesto_id' => $this->gestor->id, 'sucursal_principal_id' => $this->slp->id, 'estatus' => 'en_incorporacion',
    ]);

    $this->artisan('organigrama:explicar-jefe', ['colaborador' => 'EMP-0131'])
        ->expectsOutputToContain('SIN JEFE ASIGNABLE')
        ->assertSuccessful();
});
