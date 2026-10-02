<?php

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoAvanceOnboarding;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoReingreso;
use App\Models\Candidato;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\OnboardingAvance;
use App\Models\Reingreso;
use App\Models\User;
use Database\Seeders\CicloLaboralDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| El demo del ciclo deja una persona en cada etapa usando solo services: si
| algún escenario no se pudiera alcanzar así, esta prueba lo detecta.
*/

test('el demo del ciclo laboral deja cada etapa alcanzada por services, sin duplicar al repetirse', function () {
    Storage::fake('nas');
    Notification::fake();

    $this->seed(DatabaseSeeder::class);

    $candidato = fn (string $clave) => Candidato::query()->where('correo', "demo.ciclo.candidato.{$clave}@mrlana.test")->first()?->estado;
    $persona = fn (int $n) => User::query()->where('email', "demo.ciclo.persona{$n}@mrlana.test")->first()?->colaborador_id;

    expect($candidato('perfil'))->toBe(EstadoCandidato::Recibidos)
        ->and($candidato('entrevista'))->toBe(EstadoCandidato::EntrevistaPendiente)
        ->and($candidato('psicometricas'))->toBe(EstadoCandidato::PsicometricasPendientes)
        ->and($candidato('socioeconomico'))->toBe(EstadoCandidato::SocioeconomicoPendiente)
        ->and($candidato('referencias'))->toBe(EstadoCandidato::ReferenciasPendientes)
        ->and($candidato('preseleccion'))->toBe(EstadoCandidato::PreseleccionGerente)
        ->and($candidato('esperando-rh'))->toBe(EstadoCandidato::AutorizacionRhPendiente);

    expect(Colaborador::query()->find($persona(1))?->estado_alta)->toBe(EstadoAltaColaborador::PendienteDocumentos)
        ->and(Colaborador::query()->find($persona(2))?->estado_alta)->toBe(EstadoAltaColaborador::EnOnboarding)
        ->and(OnboardingAvance::query()->whereHas('proceso', fn ($q) => $q->where('colaborador_id', $persona(3)))->where('estado', EstadoAvanceOnboarding::RequiereRefuerzo->value)->exists())->toBeTrue()
        ->and(EvaluacionPeriodoPrueba::query()->where('colaborador_id', $persona(4))->value('estado'))->toBe(EstadoEvaluacionPrueba::Pendiente)
        ->and(EvaluacionPeriodoPrueba::query()->where('colaborador_id', $persona(5))->value('estado'))->toBe(EstadoEvaluacionPrueba::Capturada)
        ->and(CierreLaboral::query()->where('colaborador_id', $persona(6))->value('estado'))->toBe(EstadoCierreLaboral::PendienteRh)
        ->and(CierreLaboral::query()->where('colaborador_id', $persona(7))->value('estado'))->toBe(EstadoCierreLaboral::PagoProgramado);

    $reingreso = Reingreso::query()->whereHas('colaborador', fn ($q) => $q->withTrashed()->whereHas('user', fn ($u) => $u->where('email', 'demo.ciclo.persona8@mrlana.test')))->first();
    expect($reingreso?->estado)->toBe(EstadoReingreso::RevisionRh);

    $candidatos = Candidato::query()->count();
    $this->seed(CicloLaboralDemoSeeder::class);
    expect(Candidato::query()->count())->toBe($candidatos);
});
