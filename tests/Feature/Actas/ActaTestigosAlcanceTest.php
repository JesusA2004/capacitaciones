<?php

use App\Enums\EstadoActa;
use App\Enums\EstadoUsuario;
use App\Enums\TipoActa;
use App\Models\ActaAdministrativa;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Actas\ActaPdfService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| Actas (cierre 2026-10-15): solo acta administrativa y acta de hechos se
| crean (las históricas se leen); exactamente dos testigos activos,
| distintos entre sí y del colaborador; el gerente ve primero a los de su
| sucursal y solo dentro de su alcance; RH busca en toda la organización,
| también por sucursal («Orizaba»). El PDF es un formato propio con vista
| previa que no guarda nada.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $this->centro = Sucursal::factory()->create(['nombre' => 'Toluca Centro']);
    $this->orizaba = Sucursal::factory()->create(['nombre' => 'Orizaba']);
    $this->puestoCajero = Puesto::factory()->create(['nombre' => 'Cajero']);

    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->centro->id]);
    $this->rh = clUsuario('rh_admin');

    $this->sujeto = Colaborador::factory()->create(['sucursal_principal_id' => $this->centro->id, 'estatus' => EstadoUsuario::Activo]);
    $this->testigoA = Colaborador::factory()->create(['name' => 'Ana', 'apellidos' => 'Centro Uno', 'sucursal_principal_id' => $this->centro->id, 'puesto_id' => $this->puestoCajero->id, 'estatus' => EstadoUsuario::Activo, 'numero_empleado' => 'EMP-7001']);
    $this->testigoB = Colaborador::factory()->create(['name' => 'Beto', 'apellidos' => 'Centro Dos', 'sucursal_principal_id' => $this->centro->id, 'estatus' => EstadoUsuario::Activo]);
    $this->inactivo = Colaborador::factory()->create(['name' => 'Carla', 'apellidos' => 'Baja', 'sucursal_principal_id' => $this->centro->id, 'estatus' => EstadoUsuario::Inactivo]);
    $this->deOrizaba = Colaborador::factory()->create(['name' => 'Diego', 'apellidos' => 'Veracruz', 'sucursal_principal_id' => $this->orizaba->id, 'estatus' => EstadoUsuario::Activo]);
});

function atIds(array $lista): array
{
    return collect($lista)->pluck('id')->all();
}

test('al abrir el selector el gerente ve solo activos de SU sucursal, sin el colaborador del acta', function () {
    $ids = atIds($this->actingAs($this->gerente)->getJson(route('rh.actas.testigos', ['excluir' => $this->sujeto->id]))->assertOk()->json('data'));

    expect($ids)->toContain($this->testigoA->id)->toContain($this->testigoB->id)
        ->not->toContain($this->inactivo->id)
        ->not->toContain($this->deOrizaba->id)
        ->not->toContain($this->sujeto->id);
});

test('se busca por sucursal, puesto y número de empleado; el gerente no sale de su alcance', function () {
    $rhOrizaba = atIds($this->actingAs($this->rh)->getJson(route('rh.actas.testigos', ['q' => 'orizaba']))->assertOk()->json('data'));
    expect($rhOrizaba)->toBe([$this->deOrizaba->id]);

    expect(atIds($this->actingAs($this->rh)->getJson(route('rh.actas.testigos', ['q' => 'cajero']))->json('data')))->toBe([$this->testigoA->id])
        ->and(atIds($this->actingAs($this->rh)->getJson(route('rh.actas.testigos', ['q' => 'EMP-7001']))->json('data')))->toBe([$this->testigoA->id])
        ->and(atIds($this->actingAs($this->gerente)->getJson(route('rh.actas.testigos', ['q' => 'orizaba']))->json('data')))->toBe([]);
});

test('solo se levanta el acta administrativa circunstanciada; las históricas se siguen viendo', function () {
    foreach (['carta_responsiva', 'auditoria', 'hechos'] as $tipo) {
        $this->actingAs($this->gerente)->post(route('rh.actas.store', $this->sujeto), [
            'tipo' => $tipo, 'fecha' => now()->toDateString(), 'hechos' => 'x',
        ])->assertSessionHasErrors('tipo');
    }

    $historica = ActaAdministrativa::query()->create([
        'colaborador_id' => $this->sujeto->id, 'tipo' => TipoActa::CartaResponsiva, 'estado' => EstadoActa::Cerrada,
        'fecha' => now()->subYear()->toDateString(), 'hechos' => 'Histórica', 'creado_por' => $this->rh->id,
    ]);

    $this->actingAs($this->rh)->get(route('rh.actas.show', $historica))->assertOk();
});

test('exactamente dos testigos distintos y distintos del colaborador', function () {
    $base = ['tipo' => 'administrativa', 'motivo' => 'Faltante en caja', 'fecha' => now()->toDateString(), 'hechos' => 'Faltante en caja.'];

    $this->actingAs($this->gerente)->post(route('rh.actas.store', $this->sujeto), [...$base, 'testigos' => [['colaborador_id' => $this->testigoA->id]]])
        ->assertSessionHasErrors('testigos');
    $this->actingAs($this->gerente)->post(route('rh.actas.store', $this->sujeto), [...$base, 'testigos' => [['colaborador_id' => $this->testigoA->id], ['colaborador_id' => $this->testigoA->id]]])
        ->assertSessionHasErrors();
    $this->actingAs($this->gerente)->post(route('rh.actas.store', $this->sujeto), [...$base, 'testigos' => [['colaborador_id' => $this->sujeto->id], ['colaborador_id' => $this->testigoB->id]]])
        ->assertSessionHasErrors();

    $this->actingAs($this->gerente)->post(route('rh.actas.store', $this->sujeto), [...$base, 'testigos' => [['colaborador_id' => $this->testigoA->id, 'puesto' => 'Inventado'], ['colaborador_id' => $this->testigoB->id]]])
        ->assertSessionHasNoErrors();

    $acta = ActaAdministrativa::query()->latest('id')->firstOrFail();
    // El cargo sale del puesto real, nunca del texto libre.
    expect($acta->testigos[0]['puesto'])->toBe('Cajero');
});

test('vista previa = PDF real sin guardar nada; el formato no imprime metadata técnica', function () {
    $acta = ActaAdministrativa::query()->create([
        'colaborador_id' => $this->sujeto->id, 'tipo' => TipoActa::Administrativa, 'estado' => EstadoActa::Borrador,
        'motivo' => 'Faltante de efectivo', 'fecha' => now()->toDateString(), 'hora' => '10:30', 'lugar' => 'Caja',
        'hechos' => 'Se detectó un faltante.', 'responsable_id' => $this->gerente->id, 'creado_por' => $this->gerente->id,
        'testigos' => [['colaborador_id' => $this->testigoA->id, 'nombre' => $this->testigoA->nombreCompleto(), 'puesto' => 'Cajero'], ['colaborador_id' => $this->testigoB->id, 'nombre' => $this->testigoB->nombreCompleto(), 'puesto' => '']],
        'declaraciones' => [['persona' => $this->sujeto->nombreCompleto(), 'declaracion' => 'Desconozco el faltante.']],
    ]);
    $acta->update(['folio' => 'ACT-TEST-1']);

    // El formato del acta es de RH por defecto (actas.descargar); habilitado
    // por rol en «Roles y permisos», el gerente lo previsualiza y genera.
    $this->actingAs($this->gerente)->get(route('rh.actas.vista-previa', $acta))->assertForbidden();
    Role::findByName('gerente_sucursal', 'web')->givePermissionTo('actas.descargar');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($this->gerente)->get(route('rh.actas.vista-previa', $acta))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    expect(GeneratedDocument::query()->count())->toBe(0);

    $html = app(ActaPdfService::class)->html($acta->refresh());
    expect($html)->toContain('ACTA ADMINISTRATIVA CIRCUNSTANCIADA')->toContain('Personas presentes')->toContain('Manifestación del colaborador')
        ->toContain('Desconozco el faltante.')->toContain('Testigo 2')
        ->not->toContain('Autorizado')->not->toContain('VISTA-PREVIA');

    // Generación final por el flujo documental: guarda el PDF y lo liga al acta.
    $this->actingAs($this->gerente)->postJson(route('rh.documentos-proceso.generar', ['tipo' => 'acta', 'id' => $acta->id]), ['clave' => 'acta_administrativa'])
        ->assertSuccessful();

    expect($acta->refresh()->generated_document_id)->not->toBeNull()
        ->and($acta->estado)->toBe(EstadoActa::Generada);
});

test('sin dos testigos la vista previa explica qué falta', function () {
    $acta = ActaAdministrativa::query()->create([
        'colaborador_id' => $this->sujeto->id, 'tipo' => TipoActa::Hechos, 'estado' => EstadoActa::Borrador,
        'fecha' => now()->toDateString(), 'hechos' => 'Algo pasó.', 'creado_por' => $this->gerente->id, 'testigos' => [],
    ]);

    Role::findByName('gerente_sucursal', 'web')->givePermissionTo('actas.descargar');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($this->gerente)->get(route('rh.actas.vista-previa', $acta))->assertStatus(409);
});
