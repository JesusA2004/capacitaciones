<?php

use App\Models\AltaDigital;
use App\Models\Candidato;
use App\Models\DocumentTemplate;
use App\Models\Empresa;
use App\Models\GeneratedDocument;
use App\Models\SolicitudInterna;
use App\Models\SolicitudVacaciones;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->rh = User::factory()->create();
    $this->rh->assignRole('rh_admin');
});

test('rh_admin puede exportar vacantes a excel y pdf', function () {
    Vacante::factory()->count(2)->create();

    $this->actingAs($this->rh)->get(route('rh.vacantes.exportarExcel'))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->actingAs($this->rh)->get(route('rh.vacantes.exportarPdf'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('la exportacion de vacantes respeta el filtro de estado activo en pantalla', function () {
    Vacante::factory()->create(['estado' => 'abierta']);
    Vacante::factory()->create(['estado' => 'cubierta']);

    $respuesta = $this->actingAs($this->rh)
        ->get(route('rh.vacantes.exportarExcel', ['estado' => 'abierta']))
        ->assertOk();

    expect($respuesta->headers->get('content-disposition'))->toContain('vacantes-');
});

test('rh_admin puede exportar candidatos a excel y pdf', function () {
    Candidato::factory()->count(2)->create();

    $this->actingAs($this->rh)->get(route('rh.candidatos.exportarExcel'))->assertOk();
    $this->actingAs($this->rh)->get(route('rh.candidatos.exportarPdf'))->assertOk();
});

test('rh_admin puede exportar altas digitales a excel y pdf', function () {
    AltaDigital::factory()->count(2)->create();

    $this->actingAs($this->rh)->get(route('rh.altas.exportarExcel'))->assertOk();
    $this->actingAs($this->rh)->get(route('rh.altas.exportarPdf'))->assertOk();
});

test('rh_admin puede exportar plantillas a excel y pdf', function () {
    DocumentTemplate::factory()->count(2)->create();

    $this->actingAs($this->rh)->get(route('rh.plantillas.exportarExcel'))->assertOk();
    $this->actingAs($this->rh)->get(route('rh.plantillas.exportarPdf'))->assertOk();
});

test('rh_admin puede exportar formatos generados a excel y pdf', function () {
    GeneratedDocument::factory()->count(2)->create();

    $this->actingAs($this->rh)->get(route('rh.formatos.exportarExcel'))->assertOk();
    $this->actingAs($this->rh)->get(route('rh.formatos.exportarPdf'))->assertOk();
});

test('rh_admin puede exportar solicitudes internas a excel y pdf', function () {
    SolicitudInterna::factory()->count(2)->create();

    $this->actingAs($this->rh)->get(route('rh.solicitudes.exportarExcel'))->assertOk();
    $this->actingAs($this->rh)->get(route('rh.solicitudes.exportarPdf'))->assertOk();
});

test('rh_admin puede exportar expedientes a excel y pdf', function () {
    User::factory()->count(2)->create();

    $this->actingAs($this->rh)->get(route('rh.expedientes.exportarExcel'))->assertOk();
    $this->actingAs($this->rh)->get(route('rh.expedientes.exportarPdf'))->assertOk();
});

test('rh_admin puede exportar vacaciones a excel y pdf', function () {
    SolicitudVacaciones::factory()->count(2)->create();

    $this->actingAs($this->rh)->get(route('rh.vacaciones.exportarExcel'))->assertOk();
    $this->actingAs($this->rh)->get(route('rh.vacaciones.exportarPdf'))->assertOk();
});

test('un colaborador sin permiso no puede exportar vacantes', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($colaborador)->get(route('rh.vacantes.exportarExcel'))->assertForbidden();
});

test('altas digitales respeta el alcance por sucursal al exportar', function () {
    $gerente = User::factory()->create();
    $gerente->assignRole('gerente_sucursal');

    $sucursalPropia = Sucursal::factory()->create();
    $sucursalAjena = Sucursal::factory()->create();
    $gerente->update(['sucursal_principal_id' => $sucursalPropia->id]);

    AltaDigital::factory()->create(['sucursal_id' => $sucursalPropia->id, 'empresa_id' => Empresa::factory()]);
    AltaDigital::factory()->create(['sucursal_id' => $sucursalAjena->id, 'empresa_id' => Empresa::factory()]);

    $this->actingAs($gerente)->get(route('rh.altas.exportarExcel'))->assertOk();
});
