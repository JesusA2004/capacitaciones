<?php

use App\Enums\EstadoCandidato;
use App\Enums\EstadoVacante;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\IncorporacionInvitacion;
use App\Models\Vacante;
use App\Services\Reclutamiento\CandidatoWorkflowService;
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
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
});

/**
 * Candidato que recorrió TODO el reclutamiento con el workflow real hasta la
 * autorización final de RH (nunca se coloca el estado a mano).
 */
function clCandidatoAutorizado(object $t, bool $autorizar = true): Candidato
{
    $estructura = $t->estructura;
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

    $wf = app(CandidatoWorkflowService::class);
    $c = $wf->registrar([
        'empresa_id' => $estructura['empresa']->id,
        'sucursal_id' => $estructura['sucursal']->id,
        'departamento_id' => $estructura['departamento']->id,
        'puesto_objetivo_id' => $estructura['puesto']->id,
        'vacante_id' => $vacante->id,
        'nombre' => 'Luis',
        'apellidos' => 'Ramírez',
        'correo' => 'luis.ramirez@mrlana.test',
        'gerente_involucrado_id' => $t->gerente->id,
    ], $t->rh);

    $wf->evaluarPerfil($c, $t->rh, true, null);
    $wf->registrarEntrevista($c, $t->gerente, ['realizada_en' => now()->subDay()->toDateTimeString(), 'resultado' => 'viable']);
    $wf->registrarResultadosPsicometricas($c, $t->rh, 'En perfil.');
    $wf->revisarPsicometricas($c, $t->gerente, true, null);
    $wf->registrarSocioeconomico($c, $t->gerente, ['fecha_visita' => now()->toDateString(), 'direccion' => 'Calle 1', 'resultado' => 'viable']);
    $wf->registrarReferencia($c, $t->rh, ['empresa' => 'X', 'contacto' => 'Y', 'resultado' => 'positiva']);
    $wf->concluirReferencias($c, $t->rh, true, null);
    $wf->preautorizar($c, $t->gerente, null);

    if ($autorizar) {
        $wf->autorizarRh($c, $t->rh, null);
    }

    return $c->refresh();
}

test('contratar un candidato autorizado por RH crea el colaborador sin duplicar la persona, ocupa la plaza y genera su QR', function () {
    Sanctum::actingAs($this->rh);
    $candidato = clCandidatoAutorizado($this);

    $respuesta = $this->postJson("/api/v1/rh/candidatos/{$candidato->id}/contratar", [
        'sueldo_mensual' => 12000,
        'fecha_ingreso' => now()->toDateString(),
        'tipo_contratacion' => 'periodo_prueba',
        'fecha_fin_contrato' => now()->addDays(30)->toDateString(),
    ])->assertCreated();

    $colaborador = Colaborador::query()->findOrFail($respuesta->json('colaborador_id'));
    $candidato->refresh();
    $vacante = Vacante::query()->findOrFail($candidato->vacante_id);

    // Datos tomados del candidato, no recapturados; la cuenta la crea el
    // propio candidato al registrarse con el QR.
    expect($colaborador->name)->toBe('Luis')
        ->and($colaborador->sucursal_principal_id)->toBe($this->estructura['sucursal']->id)
        ->and($colaborador->puesto_id)->toBe($this->estructura['puesto']->id)
        ->and($colaborador->candidato_id)->toBe($candidato->id)
        ->and($colaborador->user)->toBeNull();

    // Trazabilidad reclutamiento → contratación → plaza ocupada.
    expect($candidato->estado)->toBe(EstadoCandidato::EnContratacion)
        ->and($candidato->colaborador_id)->toBe($colaborador->id)
        ->and($vacante->colaborador_contratado_id)->toBe($colaborador->id)
        ->and($vacante->candidato_contratado_id)->toBe($candidato->id)
        ->and($vacante->estado)->toBe(EstadoVacante::Cubierta)
        ->and($vacante->diasAbierta())->toBe(12)
        ->and(IncorporacionInvitacion::query()->where('candidato_id', $candidato->id)->where('colaborador_id', $colaborador->id)->count())->toBe(1);

    // No se puede convertir dos veces.
    $this->postJson("/api/v1/rh/candidatos/{$candidato->id}/contratar", [
        'sueldo_mensual' => 12000,
        'fecha_ingreso' => now()->toDateString(),
    ])->assertUnprocessable();

    expect(Colaborador::query()->where('name', 'Luis')->count())->toBe(1);
});

test('un candidato solo preautorizado por el gerente todavía no puede contratarse', function () {
    Sanctum::actingAs($this->rh);
    $candidato = clCandidatoAutorizado($this, autorizar: false);

    expect($candidato->estado)->toBe(EstadoCandidato::AutorizacionRhPendiente);

    $this->postJson("/api/v1/rh/candidatos/{$candidato->id}/contratar", [
        'sueldo_mensual' => 12000,
        'fecha_ingreso' => now()->toDateString(),
    ])->assertUnprocessable();

    expect($candidato->refresh()->colaborador_id)->toBeNull()
        ->and(IncorporacionInvitacion::query()->count())->toBe(0);
});
