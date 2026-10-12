<?php

use App\Models\AjusteHistoricoVacaciones;
use App\Models\Colaborador;
use App\Models\HistorialSalario;
use App\Models\SolicitudInterna;
use App\Services\Auditoria\AuditoriaService;
use App\Services\CicloLaboral\TimelineService;
use Database\Seeders\RolesYPermisosSeeder;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

/*
| Historial laboral único (CLAUDE.md §37-38): candidato → alta → contratos
| → inducción → evaluación inducción → onboarding → evaluación periodo →
| renovación → SALARIOS → SOLICITUDES → VACACIONES → baja → reingreso.
| TimelineService ya cubría casi todo vía activity_log; esta prueba cubre
| las fuentes agregadas (historial_salarios, ajustes_historicos_vacaciones,
| solicitudes_internas) que antes no aparecían.
*/

test('el historial incluye cambios de salario, ajustes de vacaciones y solicitudes internas, ordenados cronológicamente', function () {
    $colaborador = Colaborador::factory()->create();
    $actor = clUsuario('rh_admin');

    HistorialSalario::factory()->create([
        'colaborador_id' => $colaborador->id,
        'salario_diario_anterior' => 300,
        'salario_diario_nuevo' => 350,
        'actor_id' => $actor->id,
        'motivo' => 'Ajuste anual.',
    ]);

    AjusteHistoricoVacaciones::factory()->create([
        'colaborador_id' => $colaborador->id,
        'dias' => -8,
        'motivo' => 'Días tomados antes de PEOPLE.',
        'actor_id' => $actor->id,
    ]);

    SolicitudInterna::factory()->create([
        'colaborador_id' => $colaborador->id,
        'tipo' => 'vacaciones',
        'estado' => 'aprobada',
        'folio' => 'SOL-0001',
    ]);

    $timeline = app(TimelineService::class)->de($colaborador);
    $titulos = array_column($timeline, 'titulo');

    expect($titulos)->toContain('Cambio de salario diario: $300.00 → $350.00')
        ->and($titulos)->toContain('Ajuste histórico de vacaciones: -8.00 días')
        ->and(collect($titulos)->first(fn (string $t) => str_contains($t, 'Vacaciones') && str_contains($t, 'Aprobada')))->not->toBeNull();
});

test('la evaluación de inducción calificada muestra la calificación y el nivel en el título', function () {
    $colaborador = Colaborador::factory()->create();
    $actor = clUsuario('rh_admin');

    app(AuditoriaService::class)->registrar('induccion_casos_calificados', $colaborador, $actor, [
        'colaborador_id' => $colaborador->id,
        'calificacion_total' => 28.0,
        'nivel' => 'sobresaliente',
    ]);

    $timeline = app(TimelineService::class)->de($colaborador);

    expect(array_column($timeline, 'titulo'))->toContain('Evaluación de inducción calificada: 28.0/30 (sobresaliente)');
});
