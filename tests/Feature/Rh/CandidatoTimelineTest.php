<?php

use App\Models\AltaDigital;
use App\Models\Candidato;
use App\Models\IncorporacionInvitacion;
use App\Models\User;
use App\Services\Candidatos\CandidatoTimelineService;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('un candidato recibido tiene el registro completado y la preselección en curso', function () {
    $candidato = Candidato::factory()->create(['estado' => 'recibidos']);

    $timeline = app(CandidatoTimelineService::class)->construir($candidato);

    expect($timeline[0]['clave'])->toBe('registro')
        ->and($timeline[0]['estado'])->toBe('completado')
        ->and($timeline[1]['estado'])->toBe('actual')
        ->and($timeline[2]['estado'])->toBe('pendiente');
});

test('un candidato rechazado marca las etapas siguientes como descartadas', function () {
    $candidato = Candidato::factory()->create(['estado' => 'no_seleccionado']);

    $timeline = app(CandidatoTimelineService::class)->construir($candidato);

    $entrevista = collect($timeline)->firstWhere('clave', 'entrevista');

    expect($entrevista['estado'])->toBe('descartado');
});

test('un candidato con alta digital aprobada marca la etapa de alta como completada', function () {
    $candidato = Candidato::factory()->create(['estado' => 'listo_para_contratacion']);
    AltaDigital::factory()->create(['candidato_id' => $candidato->id, 'estado' => 'aprobada']);

    $timeline = app(CandidatoTimelineService::class)->construir($candidato->fresh());

    $alta = collect($timeline)->firstWhere('clave', 'alta_digital');
    $seleccionado = collect($timeline)->firstWhere('clave', 'listo_para_contratacion');

    expect($alta['estado'])->toBe('completado')
        ->and($seleccionado['estado'])->toBe('completado');
});

test('una invitacion de incorporacion usada marca la etapa de qr como completada', function () {
    $candidato = Candidato::factory()->create(['estado' => 'listo_para_contratacion']);
    $alta = AltaDigital::factory()->create(['candidato_id' => $candidato->id, 'estado' => 'aprobada']);
    IncorporacionInvitacion::factory()->create([
        'candidato_id' => $candidato->id,
        'estado' => 'usado',
        'used_at' => now(),
        'creado_por_id' => User::factory()->create()->id,
    ]);

    $timeline = app(CandidatoTimelineService::class)->construir($candidato->fresh());

    $qr = collect($timeline)->firstWhere('clave', 'qr_incorporacion');

    expect($qr['estado'])->toBe('completado')
        ->and($alta)->not->toBeNull();
});
