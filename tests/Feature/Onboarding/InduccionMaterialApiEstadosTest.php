<?php

use App\Enums\EstadoMultimedia;
use App\Enums\EstadoUsuario;
use App\Enums\TipoModuloOnboarding;
use App\Enums\TipoRecursoMultimedia;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\OnboardingAvance;
use App\Models\OnboardingModulo;
use App\Models\RecursoMultimedia;
use App\Models\User;
use App\Services\Onboarding\AccesoInduccionColaboradorService;
use App\Services\Onboarding\InduccionPdfService;
use App\Services\Onboarding\MaterialInduccionService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Onboarding\ProgresoInduccionService;
use App\Services\Onboarding\ReproduccionVideoInduccionService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| Bug de producción (2026-11-03): con el video de inducción recién subido por
| RH, la app mostraba «Algo salió mal» en «Inducción Mr. Lana». Los endpoints
| de API que consume la app deben responder 200 con forma estable en CUALQUIER
| estado del video (cargando, pendiente, procesando, error, disponible, sin
| HLS), y el PDF debe seguir viéndose aunque el video falle.
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

    $proceso = app(OnboardingService::class)->iniciar($this->colaborador, ContratoLaboral::factory()->create(['colaborador_id' => $this->colaborador->id]), $this->rh);
    $this->avance = $proceso->avances()->firstOrFail();
});

/** Orden obligatorio: el video abre al terminar la presentación (todas las hojas vistas). */
function iaePresentacionCompleta(OnboardingAvance $avance): void
{
    $total = app(InduccionPdfService::class)->totalPaginas();
    $avance->update(['progreso_contenido' => [...(array) ($avance->progreso_contenido ?? []), 'paginas_vistas' => $total > 0 ? range(1, $total) : []]]);
}

dataset('estados del video', [
    'cargando' => [EstadoMultimedia::Cargando, null, null],
    'pendiente (solo original)' => [EstadoMultimedia::Pendiente, null, null],
    'procesando' => [EstadoMultimedia::Procesando, null, null],
    'error' => [EstadoMultimedia::Error, null, null],
    'disponible sin HLS ni duración' => [EstadoMultimedia::Disponible, null, null],
    'disponible con HLS' => [EstadoMultimedia::Disponible, 'hls/demo/master.m3u8', 120],
]);

test('la API de inducción responde 200 con forma estable en cualquier estado del video', function (EstadoMultimedia $estado, ?string $manifiesto, ?int $duracion) {
    $recurso = RecursoMultimedia::factory()->create([
        'tipo' => TipoRecursoMultimedia::Video,
        'estado' => $estado,
        'duracion_segundos' => $duracion,
        'ruta_hls_manifiesto' => $manifiesto,
        'error_procesamiento' => $estado === EstadoMultimedia::Error ? 'FFmpeg no instalado' : null,
        'subido_por' => $this->rh->id,
    ]);
    app(MaterialInduccionService::class)->activar($this->modulo, $recurso, $this->rh);

    Sanctum::actingAs($this->usuario);

    $this->getJson('/api/v1/colaborador/mi-proceso')->assertOk()
        ->assertJsonStructure(['data' => ['induccionContenido', 'evaluacionInduccion', 'accesoInduccion', 'miInduccion']]);

    // El PDF (visor de la app) se ve aunque el video no esté listo.
    $this->getJson('/api/v1/induccion/presentacion')->assertOk()
        ->assertJsonStructure(['data' => ['pdf']]);

    $this->getJson('/api/v1/colaborador/induccion/capitulos/'.$this->avance->id)->assertOk()
        ->assertJsonStructure(['data' => ['capitulos', 'resumen', 'pdf']]);

    $this->getJson('/api/v1/colaborador/induccion/material/'.$this->avance->id)->assertOk()
        ->assertJsonPath('data.tipo', 'video')
        ->assertJsonPath('data.video_disponible', $estado === EstadoMultimedia::Disponible && $manifiesto !== null);

    // 423: orden obligatorio (presentación sin terminar → el video aún no abre).
    $iniciar = $this->postJson('/api/v1/colaborador/induccion/video/'.$this->avance->id.'/iniciar');
    expect($iniciar->status())->toBeIn([200, 422, 423]);
})->with('estados del video');

test('reinducción SOLO con video: la app abre la inducción sin error', function (EstadoMultimedia $estado, ?string $manifiesto, ?int $duracion) {
    $recurso = RecursoMultimedia::factory()->create([
        'tipo' => TipoRecursoMultimedia::Video,
        'estado' => $estado,
        'duracion_segundos' => $duracion,
        'ruta_hls_manifiesto' => $manifiesto,
        'subido_por' => $this->rh->id,
    ]);
    app(MaterialInduccionService::class)->activar($this->modulo, $recurso, $this->rh);

    $activo = Colaborador::factory()->create(['estatus' => EstadoUsuario::Activo]);
    $persona = User::factory()->create(['colaborador_id' => $activo->id]);
    $persona->assignRole('colaborador');

    $this->actingAs($this->rh)->post(route('rh.onboarding.induccion.asignaciones.store'), [
        'modo' => 'personas', 'colaborador_ids' => [$activo->id],
        'incluye_presentacion' => false, 'incluye_material' => true,
        'fecha_limite' => now()->addDay()->toDateString(),
    ])->assertSessionHasNoErrors();

    Sanctum::actingAs($persona);
    $mi = $this->getJson('/api/v1/colaborador/mi-proceso');
    $mi->assertOk();
    $avanceId = $mi->json('data.induccionContenido.avance_id');
    expect($avanceId)->not->toBeNull();

    $this->getJson('/api/v1/induccion/presentacion')->assertOk();
    $this->getJson('/api/v1/induccion/pdf')->assertOk();
    $this->getJson('/api/v1/colaborador/induccion/capitulos/'.$avanceId)->assertOk();
    $this->getJson('/api/v1/colaborador/induccion/material/'.$avanceId)->assertOk();
})->with('estados del video');

test('material: mientras el video no está listo la API dice «preparando» y no deja iniciar', function () {
    $recurso = RecursoMultimedia::factory()->create([
        'tipo' => TipoRecursoMultimedia::Video,
        'estado' => EstadoMultimedia::Disponible,
        'ruta_hls_manifiesto' => null,
        'subido_por' => $this->rh->id,
    ]);
    app(MaterialInduccionService::class)->activar($this->modulo, $recurso, $this->rh);

    Sanctum::actingAs($this->usuario);
    $this->getJson('/api/v1/colaborador/induccion/material/'.$this->avance->id)->assertOk()
        ->assertJsonPath('data.video_disponible', false)
        ->assertJsonPath('data.video_estado', 'preparando');
    $this->postJson('/api/v1/colaborador/induccion/video/'.$this->avance->id.'/iniciar')->assertStatus(422);

    $recurso->update(['ruta_hls_manifiesto' => 'hls/demo/master.m3u8', 'duracion_segundos' => 100]);
    iaePresentacionCompleta($this->avance);
    $this->getJson('/api/v1/colaborador/induccion/material/'.$this->avance->id)->assertOk()
        ->assertJsonPath('data.video_disponible', true)
        ->assertJsonPath('data.video_estado', 'listo');
    $this->postJson('/api/v1/colaborador/induccion/video/'.$this->avance->id.'/iniciar')->assertOk();
});

test('reinducción solo video: el avance de RH sube con el video a medias (no «sin empezar» 0 %)', function () {
    $recurso = RecursoMultimedia::factory()->create([
        'tipo' => TipoRecursoMultimedia::Video,
        'estado' => EstadoMultimedia::Disponible,
        'duracion_segundos' => 100,
        'ruta_hls_manifiesto' => 'hls/demo/master.m3u8',
        'subido_por' => $this->rh->id,
    ]);
    app(MaterialInduccionService::class)->activar($this->modulo, $recurso, $this->rh);

    $activo = Colaborador::factory()->create(['estatus' => EstadoUsuario::Activo]);
    $persona = User::factory()->create(['colaborador_id' => $activo->id]);
    $persona->assignRole('colaborador');

    $this->actingAs($this->rh)->post(route('rh.onboarding.induccion.asignaciones.store'), [
        'modo' => 'personas', 'colaborador_ids' => [$activo->id],
        'incluye_presentacion' => false, 'incluye_material' => true,
        'fecha_limite' => now()->addDay()->toDateString(),
    ])->assertSessionHasNoErrors();

    $progreso = app(ProgresoInduccionService::class);
    $inicial = $progreso->resumen($activo->refresh());
    expect($inicial['es_reinduccion'])->toBeTrue()
        ->and($inicial['estado_rh'])->toBe('asignada')
        ->and($inicial['porcentaje'])->toBe(0);

    // Ve la mitad del video (heartbeats incrementales, como el reproductor).
    $avance = app(AccesoInduccionColaboradorService::class)->avanceInduccion($activo);
    $video = app(ReproduccionVideoInduccionService::class);
    $video->iniciarSesion($persona, $avance);
    foreach ([5, 10, 15, 20, 25, 30, 35, 40, 45, 50] as $pos) {
        $this->travel(5)->seconds(); // el servidor solo acredita el tiempo real transcurrido
        $video->registrarHeartbeat($persona, $avance->refresh(), $pos);
    }

    $viendo = $progreso->resumen($activo->refresh());
    expect($viendo['estado_rh'])->toBe('viendo')
        ->and($viendo['material']['porcentaje'])->toBe(50)
        // Pasos contables: video (50 %), evaluación y resultado → 17 %.
        ->and($viendo['porcentaje'])->toBe(17)
        ->and($viendo['estado_rh_etiqueta'])->toBe('Viendo · 17%');

    // Completo: el paso del video queda hecho (1 de 3).
    foreach ([55, 60, 65, 70, 75, 80, 85, 90, 95, 100] as $pos) {
        $this->travel(5)->seconds(); // el servidor solo acredita el tiempo real transcurrido
        $video->registrarHeartbeat($persona, $avance->refresh(), $pos);
    }
    $completo = $progreso->resumen($activo->refresh());
    expect($completo['estado_rh'])->toBe('material_visto')
        ->and($completo['porcentaje'])->toBe(33);
});

test('la app reproduce el video con su token: manifiesto y variante firmados aceptan Bearer de Sanctum', function () {
    Storage::fake((string) config('media.disk'));
    Storage::disk((string) config('media.disk'))->put('hls/demo/master.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=800000,RESOLUTION=854x480\n480p.m3u8\n");
    Storage::disk((string) config('media.disk'))->put('hls/demo/480p.m3u8', "#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXTINF:6.0,\nseg_000.ts\n#EXT-X-ENDLIST\n");

    $recurso = RecursoMultimedia::factory()->create([
        'tipo' => TipoRecursoMultimedia::Video,
        'estado' => EstadoMultimedia::Disponible,
        'duracion_segundos' => 120,
        'ruta_hls_manifiesto' => 'hls/demo/master.m3u8',
        'subido_por' => $this->rh->id,
    ]);
    app(MaterialInduccionService::class)->activar($this->modulo, $recurso, $this->rh);
    iaePresentacionCompleta($this->avance);

    // Token real (no sesión): así llega la app móvil.
    $token = $this->usuario->createToken('app')->plainTextToken;
    $cabeceras = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

    $url = $this->withHeaders($cabeceras)->postJson('/api/v1/colaborador/induccion/video/'.$this->avance->id.'/iniciar')
        ->assertOk()->json('data.url_manifiesto');
    expect($url)->toContain('signature=');

    $maestro = $this->withHeaders($cabeceras)->get($url);
    $maestro->assertOk();
    preg_match('/^https?:\S+$/m', $maestro->getContent(), $variante);
    expect($variante)->not->toBeEmpty();

    $this->withHeaders($cabeceras)->get($variante[0])->assertOk();

    // El portal web (sesión, sin token) sigue reproduciendo.
    $this->app['auth']->forgetGuards();
    $this->actingAs($this->usuario, 'web')->withHeaders(['Authorization' => ''])->get($url)->assertOk();

    // Sin token ni sesión, la URL firmada sola no basta.
    auth()->guard('web')->logout();
    $this->app['auth']->forgetGuards();
    $this->withHeaders(['Authorization' => '', 'Accept' => 'application/json'])->get($url)->assertUnauthorized();
});
