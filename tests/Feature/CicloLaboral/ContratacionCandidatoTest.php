<?php

use App\Enums\EstadoCandidato;
use App\Enums\EstadoVacante;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\HeadcountTarget;
use App\Models\IncorporacionInvitacion;
use App\Models\Puesto;
use App\Models\Sucursal;
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
    // Vacante REAL: solo existe con plantilla autorizada y plaza libre (se
    // respeta la plantilla que la prueba ya haya fijado).
    HeadcountTarget::query()->firstOrCreate(
        ['sucursal_id' => $estructura['sucursal']->id, 'puesto_id' => $estructura['puesto']->id],
        HeadcountTarget::factory()->raw(['sucursal_id' => $estructura['sucursal']->id, 'puesto_id' => $estructura['puesto']->id, 'plantilla_autorizada' => 10]),
    );
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
    $wf->registrarSocioeconomico($c, $t->gerente, ['fecha_visita' => now()->toDateString(), 'direccion' => 'Calle 1']);
    $wf->revisarSocioeconomico($c, $t->gerente, true, null);
    $wf->registrarReferencia($c, $t->rh, ['empresa' => 'X', 'contacto' => 'Y', 'resultado' => 'positiva']);
    $wf->concluirReferencias($c, $t->rh, true, null);

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

    // La app recibe lo necesario para mostrar y compartir el QR.
    $invitacion = IncorporacionInvitacion::query()->where('candidato_id', $candidato->id)->firstOrFail();
    expect($respuesta->json('reutilizada'))->toBeFalse()
        ->and($respuesta->json('invitacion.invitacion_id'))->toBe($invitacion->id)
        ->and($respuesta->json('invitacion.registro_url'))->toStartWith(rtrim((string) config('incorporacion.qr_url_base'), '/').'/')
        ->and($respuesta->json('invitacion.codigo'))->toBe($invitacion->codigo_legible);

    // La liga devuelta ES el token válido de esa invitación (se puede registrar con él).
    $token = basename((string) $respuesta->json('invitacion.registro_url'));
    $this->getJson("/api/v1/incorporacion/invitaciones/{$token}/validar")->assertOk();

    // Nunca se convierte dos veces: un segundo llamado devuelve la MISMA
    // invitación vigente (sin otro colaborador ni otro QR).
    $segunda = $this->postJson("/api/v1/rh/candidatos/{$candidato->id}/contratar", [
        'sueldo_mensual' => 12000,
        'fecha_ingreso' => now()->toDateString(),
    ])->assertOk();

    expect($segunda->json('reutilizada'))->toBeTrue()
        ->and($segunda->json('invitacion.invitacion_id'))->toBe($invitacion->id)
        ->and($segunda->json('colaborador_id'))->toBe($colaborador->id)
        ->and(Colaborador::query()->where('name', 'Luis')->count())->toBe(1)
        ->and(IncorporacionInvitacion::query()->where('candidato_id', $candidato->id)->count())->toBe(1);

    // Solo hay un QR utilizable: el último devuelto.
    $tokenNuevo = basename((string) $segunda->json('invitacion.registro_url'));
    $this->getJson("/api/v1/incorporacion/invitaciones/{$tokenNuevo}/validar")->assertOk();
    $this->getJson("/api/v1/incorporacion/invitaciones/{$token}/validar")->assertStatus(404);
});

test('sin el permiso de contratar, el endpoint no inicia la contratación ni genera QR', function () {
    $candidato = clCandidatoAutorizado($this);
    Sanctum::actingAs($this->gerente);

    $this->postJson("/api/v1/rh/candidatos/{$candidato->id}/contratar", [
        'sueldo_mensual' => 12000,
        'fecha_ingreso' => now()->toDateString(),
    ])->assertForbidden();

    expect($candidato->refresh()->colaborador_id)->toBeNull()
        ->and(IncorporacionInvitacion::query()->count())->toBe(0);
});

test('si la persona ya usó el QR de contratación, el endpoint ya no emite otro', function () {
    Sanctum::actingAs($this->rh);
    $candidato = clCandidatoAutorizado($this);
    $datos = ['sueldo_mensual' => 12000, 'fecha_ingreso' => now()->toDateString()];

    $this->postJson("/api/v1/rh/candidatos/{$candidato->id}/contratar", $datos)->assertCreated();
    IncorporacionInvitacion::query()->where('candidato_id', $candidato->id)->update(['estado' => 'usado', 'usos_count' => 1]);

    $this->postJson("/api/v1/rh/candidatos/{$candidato->id}/contratar", $datos)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('candidato');

    expect(IncorporacionInvitacion::query()->where('candidato_id', $candidato->id)->count())->toBe(1);
});

test('un candidato pendiente de la autorización de RH todavía no puede contratarse', function () {
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

test('carrera por la última plaza: con 1 plaza autorizada, el segundo candidato de la misma vacante no se contrata', function () {
    Sanctum::actingAs($this->rh);
    HeadcountTarget::factory()->create(['sucursal_id' => $this->estructura['sucursal']->id, 'puesto_id' => $this->estructura['puesto']->id, 'plantilla_autorizada' => 1]);
    $primero = clCandidatoAutorizado($this);

    // Segundo candidato TAMBIÉN autorizado por RH, sobre la misma plaza
    // (mismo par sucursal/puesto) mientras todavía estaba libre: solo el
    // candado + recálculo del faltante decide quién entra.
    $segundo = clCandidatoAutorizado($this);
    expect($segundo->estado)->toBe(EstadoCandidato::AutorizadoRh);

    $this->postJson("/api/v1/rh/candidatos/{$primero->id}/contratar", ['sueldo_mensual' => 12000, 'fecha_ingreso' => now()->toDateString()])->assertCreated();

    $this->postJson("/api/v1/rh/candidatos/{$segundo->id}/contratar", ['sueldo_mensual' => 12000, 'fecha_ingreso' => now()->toDateString()])
        ->assertUnprocessable()
        ->assertJsonPath('errors.vacante_id.0', 'La vacante ya fue cubierta. Vincula al candidato a otra vacante disponible.');

    // Sin segundo colaborador, sin segundo QR, sin sobrecobertura.
    expect(Colaborador::query()->where('puesto_id', $this->estructura['puesto']->id)->where('sucursal_principal_id', $this->estructura['sucursal']->id)->count())->toBe(1)
        ->and(IncorporacionInvitacion::query()->where('candidato_id', $segundo->id)->exists())->toBeFalse()
        ->and($segundo->refresh()->colaborador_id)->toBeNull();
});

test('la sucursal y el puesto del contratado salen de la vacante aunque la petición mande otros', function () {
    Sanctum::actingAs($this->rh);
    $candidato = clCandidatoAutorizado($this);
    $otra = Sucursal::factory()->create();

    $respuesta = $this->postJson("/api/v1/rh/candidatos/{$candidato->id}/contratar", [
        'sueldo_mensual' => 12000,
        'fecha_ingreso' => now()->toDateString(),
        'sucursal_principal_id' => $otra->id,
        'puesto_id' => Puesto::factory()->create()->id,
    ])->assertCreated();

    $colaborador = Colaborador::query()->findOrFail($respuesta->json('colaborador_id'));
    expect($colaborador->sucursal_principal_id)->toBe($this->estructura['sucursal']->id)
        ->and($colaborador->puesto_id)->toBe($this->estructura['puesto']->id);
});
