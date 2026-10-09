<?php

use App\Enums\EstadoVacante;
use App\Models\Colaborador;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\Vacante;
use App\Services\Reclutamiento\CampanaReclutamientoService;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use App\Services\Vacantes\VacantesListadoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

/*
 * Vacante real = plazas autorizadas − ocupadas vigentes > 0, en TODO PEOPLE.
 * Una fila «abierta» (manual o automática) sin faltante real no existe como
 * vacante: no se lista, no se ofrece en campañas/candidatos ni en la API.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->rh = clUsuario('rh_admin');
    $this->sucursal = Sucursal::factory()->create(['nombre' => 'Tlaxcala', 'activo' => true]);
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor']);
    HeadcountTarget::factory()->create(['sucursal_id' => $this->sucursal->id, 'puesto_id' => $this->gestor->id, 'plantilla_autorizada' => 1]);
    // Vacante MANUAL vieja que sigue «abierta» con 1 plaza capturada.
    $this->vacante = Vacante::factory()->create([
        'sucursal_id' => $this->sucursal->id, 'puesto_id' => $this->gestor->id, 'generada_automaticamente' => false,
        'estado' => EstadoVacante::Abierta->value, 'plazas_requeridas' => 1, 'plazas_disponibles' => 1, 'plazas_cubiertas' => 0,
    ]);
});

function vcOcuparPlaza(Sucursal $sucursal, Puesto $puesto): Colaborador
{
    return Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id, 'puesto_id' => $puesto->id, 'estatus' => 'activo']);
}

test('con la plaza libre la vacante manual existe; ocupada, deja de existir aunque la fila diga «abierta»', function () {
    $listado = app(VacantesListadoService::class);

    expect($listado->tieneCupo($this->vacante))->toBeTrue()
        ->and($listado->consulta($this->rh)->pluck('id')->all())->toContain($this->vacante->id);

    vcOcuparPlaza($this->sucursal, $this->gestor);

    expect($listado->tieneCupo($this->vacante->refresh()))->toBeFalse()
        ->and($listado->consulta($this->rh)->pluck('id')->all())->not->toContain($this->vacante->id)
        ->and($listado->plazasPorSucursal(collect([$this->sucursal->id]))->get($this->sucursal->id))->toBeNull();
});

test('una manual nunca ofrece más plazas que el faltante real', function () {
    $this->vacante->update(['plazas_requeridas' => 5, 'plazas_disponibles' => 5]);
    $filas = app(VacantesListadoService::class)->filas(Vacante::query()->whereKey($this->vacante->id)->get());

    expect($filas[0]['plazas_disponibles'])->toBe(1);
});

test('la API de vacantes (app RH) no lista una vacante sin faltante real', function () {
    vcOcuparPlaza($this->sucursal, $this->gestor);
    Sanctum::actingAs($this->rh);

    $ids = collect($this->getJson('/api/v1/rh/vacantes')->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->not->toContain($this->vacante->id);
});

test('una campaña no puede crearse sobre una vacante sin plaza real', function () {
    vcOcuparPlaza($this->sucursal, $this->gestor);

    expect(fn () => app(CampanaReclutamientoService::class)->guardar([
        'vacante_id' => $this->vacante->id, 'nombre' => 'Meta Ads octubre', 'mes' => 10, 'anio' => 2026,
    ], null, $this->rh->id))->toThrow(ValidationException::class);
});

test('un candidato no se liga a una vacante cubierta y hereda sucursal/puesto de la vacante (ignora lo manipulado)', function () {
    $otraSucursal = Sucursal::factory()->create();
    $candidato = app(CandidatoWorkflowService::class)->registrar([
        'nombre' => 'Laura', 'apellidos' => 'Pérez', 'telefono' => '5550000000',
        'vacante_id' => $this->vacante->id, 'sucursal_id' => $otraSucursal->id, 'puesto_objetivo_id' => Puesto::factory()->create()->id,
    ], $this->rh);

    expect($candidato->sucursal_id)->toBe($this->sucursal->id)
        ->and($candidato->puesto_objetivo_id)->toBe($this->gestor->id);

    vcOcuparPlaza($this->sucursal, $this->gestor);

    expect(fn () => app(CandidatoWorkflowService::class)->registrar([
        'nombre' => 'Otro', 'apellidos' => 'Candidato', 'telefono' => '5550000001', 'vacante_id' => $this->vacante->id,
    ], $this->rh))->toThrow(ValidationException::class);
});

test('una vacante manual sin plantilla autorizada no tiene plazas disponibles (no se puede usar en campañas ni candidatos)', function () {
    $sinPlantilla = Puesto::factory()->create(['nombre' => 'Puesto sin plantilla']);
    $vacante = Vacante::factory()->create([
        'sucursal_id' => $this->sucursal->id, 'puesto_id' => $sinPlantilla->id, 'generada_automaticamente' => false,
        'estado' => EstadoVacante::Abierta->value, 'plazas_requeridas' => 3, 'plazas_disponibles' => 3, 'plazas_cubiertas' => 0,
    ]);
    $listado = app(VacantesListadoService::class);

    expect($listado->tieneCupo($vacante))->toBeFalse()
        ->and($listado->plazasReales(new Collection([$vacante]))[$vacante->id])->toBe(0);

    // Detalle de la API RH: el cálculo en vivo, nunca la columna guardada (3).
    Sanctum::actingAs($this->rh);
    $this->getJson(route('api.v1.rh.vacantes.show', $vacante))->assertOk()->assertJsonPath('data.plazas_disponibles', 0);
});
