<?php

use App\Enums\AccesoInduccionColaborador;
use App\Enums\EstadoAvanceOnboarding;
use App\Enums\EstadoEvaluacionInduccion;
use App\Enums\EstadoMultimedia;
use App\Enums\EstadoOnboarding;
use App\Enums\EstadoUsuario;
use App\Enums\TipoModuloOnboarding;
use App\Enums\TipoRecursoMultimedia;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionInduccion;
use App\Models\OnboardingModulo;
use App\Models\RecursoMultimedia;
use App\Models\User;
use App\Services\Colaboradores\GuiaSiguientePasoService;
use App\Services\Onboarding\AccesoInduccionColaboradorService;
use App\Services\Onboarding\EvaluacionInduccionService;
use App\Services\Onboarding\InduccionPdfService;
use App\Services\Onboarding\MaterialInduccionService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Onboarding\ProgresoInduccionService;
use App\Services\Onboarding\ReproduccionVideoInduccionService;
use App\Services\Reclutamiento\FaseContratadoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Regla de la inducción: (presentación vista O video visto) + evaluación
| aprobada = inducción completa y el onboarding avanza. Nunca se exigen los
| dos materiales. Quien ya la terminó (o la aprobó) con solo la presentación
| antes de cargarse el video no se detiene, y lo mismo al revés.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    // Al aprobar se adjunta el PDF FO-RH-EI al expediente (disco nas).
    Storage::fake('nas');
    $estructura = clEstructura();
    $this->rh = clUsuario('rh_admin');

    $this->modulo = OnboardingModulo::query()->create([
        'clave' => 'induccion_mr_lana_2026',
        'titulo' => 'Inducción Mr. Lana',
        'tipo' => TipoModuloOnboarding::Institucional,
        'orden' => 1,
        'evaluacion_formal_clave' => 'induccion_mr_lana_2026',
        'obligatorio' => true,
        'activo' => true,
    ]);

    $this->colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'puesto_id' => $estructura['puesto']->id,
        'estatus' => EstadoUsuario::EnIncorporacion,
        'fecha_ingreso' => now()->toDateString(),
    ]);
    $this->usuario = User::factory()->create(['colaborador_id' => $this->colaborador->id]);
    $this->usuario->assignRole('colaborador');

    $this->onboarding = app(OnboardingService::class);
    $this->evaluaciones = app(EvaluacionInduccionService::class);
    $this->pdf = app(InduccionPdfService::class);
    $this->video = app(ReproduccionVideoInduccionService::class);

    $this->proceso = $this->onboarding->iniciar($this->colaborador, ContratoLaboral::factory()->create(['colaborador_id' => $this->colaborador->id]), $this->rh);
    $this->avance = $this->proceso->avances()->firstOrFail();
});

/** Carga (activa) un video de inducción listo para reproducirse. */
function povActivarVideo(object $t, int $duracion = 10): void
{
    $recurso = RecursoMultimedia::factory()->create([
        'tipo' => TipoRecursoMultimedia::Video,
        'estado' => EstadoMultimedia::Disponible,
        'duracion_segundos' => $duracion,
        'ruta_hls_manifiesto' => 'hls/demo/master.m3u8',
        'subido_por' => $t->rh->id,
    ]);

    app(MaterialInduccionService::class)->activar($t->modulo, $recurso, $t->rh);
}

function povVerHojas(object $t): void
{
    foreach (range(1, $t->pdf->totalPaginas()) as $pagina) {
        $t->pdf->registrarPagina($t->avance->refresh(), $t->usuario, $pagina);
    }
}

/** Ve el video de 10 s completo, al ritmo real (el servidor no acredita adelantos). */
function povVerVideo(object $t): void
{
    $t->video->iniciarSesion($t->usuario, $t->avance->refresh());

    foreach ([5, 10] as $posicion) {
        $t->travel(5)->seconds();
        $t->video->registrarHeartbeat($t->usuario, $t->avance->refresh(), $posicion);
    }
}

/** Contesta la evaluación (A/B/C correctas) y RH califica D completa: 30/30. */
function povAprobar(object $t, EvaluacionInduccion $evaluacion): void
{
    $respuestas = ['a' => [], 'b' => [], 'c' => [], 'd' => []];
    foreach (config('induccion_evaluacion.seccion_a') as $i => $p) {
        $respuestas['a'][$i] = $p['correcta'];
    }
    foreach (config('induccion_evaluacion.seccion_b') as $i => $p) {
        $respuestas['b'][$i] = $p['correcto'];
    }
    foreach (config('induccion_evaluacion.seccion_c.conceptos') as $i => $concepto) {
        $respuestas['c'][$i] = $concepto['letra_correcta'];
    }
    $puntos = [];
    foreach (config('induccion_evaluacion.seccion_d') as $caso) {
        $respuestas['d'][$caso['clave']] = 'Respuesta para '.$caso['clave'].'.';
        foreach ($caso['rubrica'] as $criterio) {
            $puntos[$caso['clave']][$criterio['clave']] = true;
        }
    }

    $intento = $t->evaluaciones->presentar($evaluacion, $t->usuario, $respuestas, null);
    $t->actingAs($t->rh);
    $t->evaluaciones->calificarCasosPracticos($intento, $t->rh, $puntos);
}

/** Aprobada, el avance institucional aprobado y el onboarding pasó a la siguiente fase. */
function povEsperarCompletaYAvanza(object $t): void
{
    expect(EvaluacionInduccion::query()->where('colaborador_id', $t->colaborador->id)->sole()->estado)->toBe(EstadoEvaluacionInduccion::Aprobada)
        ->and($t->avance->refresh()->estado)->toBe(EstadoAvanceOnboarding::Aprobado)
        ->and($t->proceso->refresh()->estado)->not->toBe(EstadoOnboarding::InduccionInstitucional)
        ->and(app(AccesoInduccionColaboradorService::class)->estado($t->colaborador->refresh()))->toBe(AccesoInduccionColaborador::Completada)
        ->and(app(FaseContratadoService::class)->paraColaborador($t->colaborador->id))->toBe(FaseContratadoService::CAPACITACION_INICIAL);

    // «Mi inducción» al 100 % y ningún paso pide ver el video.
    $resumen = app(ProgresoInduccionService::class)->resumen($t->colaborador->refresh());
    $estados = collect($resumen['pasos'])->pluck('estado', 'clave');
    expect($resumen['porcentaje'])->toBe(100)
        ->and($estados->filter(fn (string $e): bool => in_array($e, ['actual', 'pendiente', 'detenido'], true))->all())->toBe([]);

    $guia = app(GuiaSiguientePasoService::class)->guia($t->usuario, $t->colaborador->refresh());
    expect(collect($guia['pasos'])->firstWhere('clave', 'induccion_evaluacion')['estado'])->toBe(GuiaSiguientePasoService::HECHO)
        ->and(collect($guia['pasos'])->firstWhere('clave', 'induccion_video'))->toBeNull();
}

test('solo presentación + evaluación aprobada: inducción completa aunque haya video', function () {
    povActivarVideo($this);
    povVerHojas($this);

    $this->pdf->terminar($this->avance->refresh(), $this->usuario);
    $evaluacion = $this->evaluaciones->habilitarPara($this->proceso->refresh());

    expect($evaluacion)->not->toBeNull()
        ->and($this->video->videoCompletado($this->avance->refresh()))->toBeFalse();

    povAprobar($this, $evaluacion);
    povEsperarCompletaYAvanza($this);
});

test('solo video + evaluación aprobada: inducción completa sin ver las hojas', function () {
    povActivarVideo($this);
    povVerVideo($this);

    expect($this->video->videoCompletado($this->avance->refresh()))->toBeTrue()
        ->and($this->pdf->presentacionCompleta($this->avance->refresh()))->toBeFalse();

    // Mientras no termina: el video cuenta y la presentación queda opcional.
    $pasos = collect(app(ProgresoInduccionService::class)->resumen($this->colaborador)['pasos'])->keyBy('clave');
    expect($pasos['material']['estado'])->toBe('completado')
        ->and($pasos['presentacion']['estado'])->toBe('omitido')
        ->and($pasos['evaluacion']['estado'])->toBe('actual');

    $this->pdf->terminar($this->avance->refresh(), $this->usuario);
    $evaluacion = $this->evaluaciones->habilitarPara($this->proceso->refresh());
    expect($evaluacion)->not->toBeNull();

    povAprobar($this, $evaluacion);
    povEsperarCompletaYAvanza($this);
});

test('ninguno de los dos: no termina ni se habilita la evaluación', function () {
    povActivarVideo($this);
    // Solo algunas hojas y nada del video.
    $this->pdf->registrarPagina($this->avance->refresh(), $this->usuario, 1);

    expect(fn () => $this->pdf->terminar($this->avance->refresh(), $this->usuario))
        ->toThrow(ValidationException::class, 'Antes de terminar ve el video completo o termina la presentación');

    expect($this->evaluaciones->habilitarPara($this->proceso->refresh()))->toBeNull()
        ->and($this->avance->refresh()->contenido_visto_en)->toBeNull()
        ->and(app(AccesoInduccionColaboradorService::class)->estado($this->colaborador))->toBe(AccesoInduccionColaborador::Disponible);

    $guia = app(GuiaSiguientePasoService::class)->guia($this->usuario, $this->colaborador);
    expect(collect($guia['pasos'])->firstWhere('clave', 'induccion_evaluacion')['estado'])->toBe(GuiaSiguientePasoService::ESPERA)
        ->and(collect($guia['pasos'])->firstWhere('clave', 'induccion_evaluacion')['descripcion'])->toBe('Se desbloquea al terminar el documento o el video.');
});

test('histórico: aprobó con solo la presentación y DESPUÉS se cargó el video — sigue completa y avanza', function () {
    povVerHojas($this);
    $this->pdf->terminar($this->avance->refresh(), $this->usuario);
    povAprobar($this, $this->evaluaciones->habilitarPara($this->proceso->refresh()));

    // RH sube el video cuando esta persona ya había aprobado.
    povActivarVideo($this);

    povEsperarCompletaYAvanza($this);
    // Abrir «Mis pendientes» de nuevo no le reabre nada.
    $this->actingAs($this->usuario, 'sanctum')
        ->getJson('/api/v1/colaborador/mi-proceso')
        ->assertOk()
        ->assertJsonPath('data.evaluacionInduccion.estado', EstadoEvaluacionInduccion::Aprobada->value)
        ->assertJsonPath('data.accesoInduccion.estado', AccesoInduccionColaborador::Completada->value);
});

test('histórico: contestó con solo la presentación y después se cargó el video — RH la aprueba y avanza', function () {
    povVerHojas($this);
    $this->pdf->terminar($this->avance->refresh(), $this->usuario);
    $evaluacion = $this->evaluaciones->habilitarPara($this->proceso->refresh());

    povActivarVideo($this);

    // En revisión: el video quedó opcional, ningún paso le pide verlo.
    $pasos = collect(app(ProgresoInduccionService::class)->resumen($this->colaborador)['pasos'])->keyBy('clave');
    expect($pasos['presentacion']['estado'])->toBe('completado')
        ->and($pasos['material']['estado'])->toBe('omitido')
        ->and($pasos['material']['detalle'])->toBe('Opcional: ya viste la presentación');

    povAprobar($this, $evaluacion);
    povEsperarCompletaYAvanza($this);
});

test('histórico: vio todas las hojas sin presionar «Terminé» y después se cargó el video — al abrir Mis pendientes se habilita su evaluación', function () {
    povVerHojas($this);
    povActivarVideo($this);

    // La app instalada todavía pide el video para ofrecer «Terminé»: el
    // servidor ya no lo exige y le habilita la evaluación al consultar.
    $this->actingAs($this->usuario, 'sanctum')
        ->getJson('/api/v1/colaborador/mi-proceso')
        ->assertOk()
        ->assertJsonPath('data.induccionContenido.completado', true)
        ->assertJsonPath('data.evaluacionInduccion.puede_presentar', true)
        ->assertJsonPath('data.accesoInduccion.estado', AccesoInduccionColaborador::EvaluacionPendiente->value);

    expect($this->avance->refresh()->contenido_visto_en)->not->toBeNull();

    povAprobar($this, EvaluacionInduccion::query()->where('colaborador_id', $this->colaborador->id)->sole());
    povEsperarCompletaYAvanza($this);
});
