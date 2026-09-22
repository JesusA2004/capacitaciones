<?php

use App\Enums\EstadoCandidato;
use App\Enums\EstadoVacante;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\User;
use App\Models\Vacante;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $this->estructura = clEstructura();
});

function clCandidatoListo(array $estructura, array $extra = []): Candidato
{
    $vacante = Vacante::factory()->create([
        'empresa_id' => $estructura['empresa']->id,
        'sucursal_id' => $estructura['sucursal']->id,
        'puesto_id' => $estructura['puesto']->id,
        'estado' => EstadoVacante::Abierta->value,
        'plazas_requeridas' => 1,
        'plazas_disponibles' => 1,
        'plazas_cubiertas' => 0,
        'fecha_apertura' => now()->subDays(12)->toDateString(),
    ]);

    return Candidato::factory()->create([
        'empresa_id' => $estructura['empresa']->id,
        'sucursal_id' => $estructura['sucursal']->id,
        'departamento_id' => $estructura['departamento']->id,
        'puesto_objetivo_id' => $estructura['puesto']->id,
        'vacante_id' => $vacante->id,
        'nombre' => 'Luis',
        'apellidos' => 'Ramírez',
        'correo' => 'luis.ramirez@mrlana.test',
        'estado' => EstadoCandidato::ListoParaContratacion->value,
        ...$extra,
    ]);
}

test('contratar un candidato crea el colaborador sin duplicar la persona y conserva la trazabilidad', function () {
    Sanctum::actingAs($this->rh);
    $candidato = clCandidatoListo($this->estructura);

    $respuesta = $this->postJson("/api/v1/rh/candidatos/{$candidato->id}/contratar", [
        'sueldo_mensual' => 12000,
        'fecha_ingreso' => now()->toDateString(),
        'tipo_contratacion' => 'periodo_prueba',
        'fecha_fin_contrato' => now()->addDays(30)->toDateString(),
    ])->assertCreated();

    $colaborador = Colaborador::query()->findOrFail($respuesta->json('colaborador_id'));
    $candidato->refresh();
    $vacante = Vacante::query()->findOrFail($candidato->vacante_id);

    // Datos tomados del candidato, no recapturados.
    expect($colaborador->name)->toBe('Luis')
        ->and($colaborador->sucursal_principal_id)->toBe($this->estructura['sucursal']->id)
        ->and($colaborador->puesto_id)->toBe($this->estructura['puesto']->id)
        ->and($colaborador->candidato_id)->toBe($candidato->id)
        ->and(User::query()->where('email', 'luis.ramirez@mrlana.test')->value('colaborador_id'))->toBe($colaborador->id);

    // Trazabilidad reclutamiento → alta → plaza ocupada.
    expect($candidato->estado)->toBe(EstadoCandidato::Contratado)
        ->and($candidato->colaborador_id)->toBe($colaborador->id)
        ->and($candidato->contratado_en)->not->toBeNull()
        ->and($vacante->colaborador_contratado_id)->toBe($colaborador->id)
        ->and($vacante->candidato_contratado_id)->toBe($candidato->id)
        ->and($vacante->estado)->toBe(EstadoVacante::Cubierta)
        ->and($vacante->fecha_cierre)->not->toBeNull()
        ->and($vacante->diasAbierta())->toBe(12);

    // No se puede convertir dos veces.
    $this->postJson("/api/v1/rh/candidatos/{$candidato->id}/contratar", [
        'sueldo_mensual' => 12000,
        'fecha_ingreso' => now()->toDateString(),
        'tipo_contratacion' => 'indeterminado',
    ])->assertUnprocessable();

    expect(Colaborador::query()->where('name', 'Luis')->count())->toBe(1);
});

test('un candidato en entrevista todavía no puede contratarse', function () {
    Sanctum::actingAs($this->rh);
    $candidato = clCandidatoListo($this->estructura, ['estado' => EstadoCandidato::Entrevista->value]);

    $this->postJson("/api/v1/rh/candidatos/{$candidato->id}/contratar", [
        'sueldo_mensual' => 12000,
        'fecha_ingreso' => now()->toDateString(),
        'tipo_contratacion' => 'indeterminado',
    ])->assertUnprocessable();

    expect($candidato->refresh()->colaborador_id)->toBeNull();
});
