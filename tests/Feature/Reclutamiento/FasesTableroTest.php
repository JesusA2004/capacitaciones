<?php

use App\Enums\EstadoCandidato;
use App\Models\Candidato;
use App\Models\Colaborador;

/*
| Columnas del tablero de candidatos: Referencias es una fase propia después
| de Socioeconómico, y "Contratado" se divide en Capacitación inicial (aún en
| onboarding) y Laborando (ya inició operación: Colaborador::activado_en).
*/

test('las fases del tablero van en orden: socioeconómico → referencias → contratación → capacitación inicial → laborando', function () {
    expect(array_keys(EstadoCandidato::fases()))->toBe([
        'filtro_rh', 'entrevista', 'psicometricos', 'socioeconomico', 'referencias',
        'contratacion', 'capacitacion_inicial', 'laborando', 'rechazado', 'desistido',
    ])->and(EstadoCandidato::ReferenciasPendientes->faseCanonica())->toBe('referencias')
        ->and(EstadoCandidato::Contratado->etiqueta())->toBe('Capacitación inicial');
});

test('un candidato contratado está en capacitación inicial hasta que su colaborador inicia operación; entonces está laborando', function () {
    $colaborador = Colaborador::factory()->create(['activado_en' => null]);
    $candidato = Candidato::factory()->create(['estado' => EstadoCandidato::Contratado, 'colaborador_id' => $colaborador->id]);

    expect($candidato->fresh()->fase)->toBe('capacitacion_inicial')
        ->and($candidato->fresh()->etiquetaEstado())->toBe('Capacitación inicial');

    $colaborador->update(['activado_en' => now()]);

    expect($candidato->fresh()->fase)->toBe('laborando')
        ->and($candidato->fresh()->load('colaborador:id,activado_en')->etiquetaEstado())->toBe('Laborando');
});
