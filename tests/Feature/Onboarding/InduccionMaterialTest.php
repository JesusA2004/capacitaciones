<?php

use App\Enums\EstadoMultimedia;
use App\Enums\EstadoUsuario;
use App\Enums\TipoMaterialInduccion;
use App\Enums\TipoModuloOnboarding;
use App\Enums\TipoRecursoMultimedia;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionInduccion;
use App\Models\InduccionMaterialExplicacion;
use App\Models\IntervaloVideoInduccion;
use App\Models\OnboardingModulo;
use App\Models\RecursoMultimedia;
use App\Models\User;
use App\Services\Onboarding\EvaluacionInduccionService;
use App\Services\Onboarding\InduccionPdfService;
use App\Services\Onboarding\MaterialInduccionService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Onboarding\ReproduccionVideoInduccionService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
| Nuevo flujo de inducción: material de explicación (audio XOR video) entre
| las hojas y la evaluación. El VIDEO bloquea la evaluación con anti-adelanto
| respaldado por el servidor; el AUDIO es narración que no bloquea.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
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
    $this->materiales = app(MaterialInduccionService::class);
    $this->video = app(ReproduccionVideoInduccionService::class);
    $this->pdf = app(InduccionPdfService::class);

    $this->proceso = $this->onboarding->iniciar($this->colaborador, ContratoLaboral::factory()->create(['colaborador_id' => $this->colaborador->id]), $this->rh);
    $this->avance = $this->proceso->avances()->firstOrFail();
});

/** Recurso de video listo para reproducir. */
function imVideo(object $t, int $duracion = 10): RecursoMultimedia
{
    return RecursoMultimedia::factory()->create([
        'tipo' => TipoRecursoMultimedia::Video,
        'estado' => EstadoMultimedia::Disponible,
        'duracion_segundos' => $duracion,
        'ruta_hls_manifiesto' => 'hls/demo/master.m3u8',
        'subido_por' => $t->rh->id,
    ]);
}

function imAudio(object $t): RecursoMultimedia
{
    return RecursoMultimedia::factory()->create([
        'tipo' => TipoRecursoMultimedia::Audio,
        'estado' => EstadoMultimedia::Disponible,
        'duracion_segundos' => 60,
        'subido_por' => $t->rh->id,
    ]);
}

test('subir material: uno activo a la vez (XOR audio/video) y valida el tipo del recurso', function () {
    $this->materiales->activar($this->modulo, imVideo($this), $this->rh);
    expect(InduccionMaterialExplicacion::query()->where('activo', true)->count())->toBe(1);

    // Activar un audio retira el video anterior (queda uno solo activo).
    $audio = imAudio($this);
    $material = $this->materiales->activar($this->modulo, $audio, $this->rh);

    expect($material->tipo)->toBe(TipoMaterialInduccion::Audio)
        ->and(InduccionMaterialExplicacion::query()->where('onboarding_modulo_id', $this->modulo->id)->where('activo', true)->count())->toBe(1)
        // El anterior se conserva como historial (activo = null, no borrado).
        ->and(InduccionMaterialExplicacion::query()->where('onboarding_modulo_id', $this->modulo->id)->count())->toBe(2);

    // Un recurso que no es audio ni video no puede ser material.
    $documento = RecursoMultimedia::factory()->create(['tipo' => TipoRecursoMultimedia::Documento, 'estado' => EstadoMultimedia::Disponible, 'subido_por' => $this->rh->id]);
    expect(fn () => $this->materiales->activar($this->modulo, $documento, $this->rh))->toThrow(ValidationException::class);

    // Sin permiso onboarding.gestionar no se puede activar material.
    expect(fn () => $this->materiales->activar($this->modulo, imVideo($this), $this->usuario))->toThrow(AuthorizationException::class);
});

test('material video: terminar (y habilitar evaluación) solo tras ver el video completo', function () {
    $this->materiales->activar($this->modulo, imVideo($this, 10), $this->rh);

    foreach (range(1, $this->pdf->totalPaginas()) as $p) {
        $this->pdf->registrarPagina($this->avance->refresh(), $this->usuario, $p);
    }

    // Todas las hojas vistas, pero el video aún no: terminar falla.
    expect(fn () => $this->pdf->terminar($this->avance->refresh(), $this->usuario))->toThrow(ValidationException::class);
    expect($this->avance->refresh()->contenido_visto_en)->toBeNull()
        ->and($this->video->videoCompletado($this->avance->refresh()))->toBeFalse();

    // Ver el video completo (incremental, respetando el límite de avance).
    $this->video->iniciarSesion($this->usuario, $this->avance->refresh());
    foreach ([5, 10] as $pos) {
        $this->video->registrarHeartbeat($this->usuario, $this->avance->refresh(), $pos);
    }

    expect($this->video->videoCompletado($this->avance->refresh()))->toBeTrue();

    $this->pdf->terminar($this->avance->refresh(), $this->usuario);
    expect($this->avance->refresh()->contenido_visto_en)->not->toBeNull();

    $evaluacion = app(EvaluacionInduccionService::class)->habilitarPara($this->proceso->refresh());
    expect($evaluacion)->not->toBeNull()
        ->and(EvaluacionInduccion::query()->where('colaborador_id', $this->colaborador->id)->exists())->toBeTrue();
});

test('material audio: basta con ver las hojas para terminar (no bloquea)', function () {
    $this->materiales->activar($this->modulo, imAudio($this), $this->rh);

    foreach (range(1, $this->pdf->totalPaginas()) as $p) {
        $this->pdf->registrarPagina($this->avance->refresh(), $this->usuario, $p);
    }

    $this->pdf->terminar($this->avance->refresh(), $this->usuario);
    expect($this->avance->refresh()->contenido_visto_en)->not->toBeNull();
});

test('anti-seek: un heartbeat por delante del límite no se permite ni extiende lo visto', function () {
    $this->materiales->activar($this->modulo, imVideo($this, 100), $this->rh);
    $this->video->iniciarSesion($this->usuario, $this->avance->refresh());

    // Límite inicial = 0 + tolerancia(5). Saltar a 50 debe rechazarse.
    $respuesta = $this->video->registrarHeartbeat($this->usuario, $this->avance->refresh(), 50);

    expect($respuesta['permitido'])->toBeFalse()
        ->and($respuesta['posicion_permitida'])->toBe(5)
        ->and(IntervaloVideoInduccion::query()->where('onboarding_avance_id', $this->avance->id)->count())->toBe(0)
        ->and($this->video->segundoMaximoAlcanzado($this->usuario, $this->avance->refresh()))->toBe(0);
});

test('refresh: la 2a reproducción retoma en el máximo alcanzado (no reinicia ni salta)', function () {
    $this->materiales->activar($this->modulo, imVideo($this, 100), $this->rh);
    $this->video->iniciarSesion($this->usuario, $this->avance->refresh());

    foreach ([5, 10, 15, 20] as $pos) {
        $this->video->registrarHeartbeat($this->usuario, $this->avance->refresh(), $pos);
    }

    $segunda = $this->video->iniciarSesion($this->usuario, $this->avance->refresh());

    expect($segunda['posicion_inicial'])->toBe(20)
        ->and($this->video->videoCompletado($this->avance->refresh()))->toBeFalse();
});

test('endpoints HTTP: iniciar/heartbeat del video respetan el gate y el anti-seek', function () {
    $this->materiales->activar($this->modulo, imVideo($this, 100), $this->rh);

    $iniciar = $this->actingAs($this->usuario)
        ->postJson(route('portal.induccion.video.iniciar', $this->avance))
        ->assertOk()
        ->assertJsonPath('posicion_inicial', 0);
    expect($iniciar->json('url_manifiesto'))->toContain('signature=');

    $this->actingAs($this->usuario)
        ->postJson(route('portal.induccion.video.heartbeat', $this->avance), ['posicion_segundos' => 500])
        ->assertOk()
        ->assertJsonPath('permitido', false);

    // Otro colaborador no puede tocar el avance ajeno.
    $otro = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $otro->assignRole('colaborador');
    $this->actingAs($otro)->postJson(route('portal.induccion.video.iniciar', $this->avance))->assertForbidden();
});
