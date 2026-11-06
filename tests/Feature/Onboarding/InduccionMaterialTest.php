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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/*
| Nuevo flujo de inducción: material de explicación (audio XOR video) entre
| las hojas y la evaluación. El VIDEO (anti-adelanto respaldado por el
| servidor) es alternativa a las hojas: basta con la presentación O el video;
| el AUDIO es narración que no bloquea.
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

test('presentación O video: el video abre (web y API) sin terminar la presentación', function () {
    $total = $this->pdf->totalPaginas();
    expect($total)->toBeGreaterThan(1);
    imHlsLargo();
    $this->materiales->activar($this->modulo, imVideo($this, 100), $this->rh);

    // Solo una hoja vista: el video ya no espera a la presentación.
    $this->avance->update(['progreso_contenido' => ['paginas_vistas' => [1]]]);

    $this->actingAs($this->usuario)
        ->postJson(route('portal.induccion.video.iniciar', $this->avance))
        ->assertOk();
    $this->actingAs($this->usuario)
        ->get(URL::temporarySignedRoute('portal.induccion.video.manifiesto-maestro', now()->addMinutes(5), ['avance' => $this->avance]))
        ->assertOk();

    $this->actingAs($this->usuario, 'sanctum')
        ->postJson('/api/v1/colaborador/induccion/video/'.$this->avance->id.'/iniciar')
        ->assertOk();
    // La app instalada lee estas llaves: siempre «sin bloqueo».
    $this->actingAs($this->usuario, 'sanctum')
        ->getJson('/api/v1/colaborador/induccion/material/'.$this->avance->id)
        ->assertOk()
        ->assertJsonPath('data.video_bloqueado', false)
        ->assertJsonPath('data.paginas_faltantes', [])
        ->assertJsonPath('data.video_bloqueado_mensaje', null);
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

test('material video: con todas las hojas basta para terminar (el video no es obligatorio)', function () {
    $this->materiales->activar($this->modulo, imVideo($this, 10), $this->rh);

    // Ni hojas ni video: no puede terminar y le dice que basta con uno.
    expect(fn () => $this->pdf->terminar($this->avance->refresh(), $this->usuario))
        ->toThrow(ValidationException::class, 'Antes de terminar ve el video completo o termina la presentación');

    foreach (range(1, $this->pdf->totalPaginas()) as $p) {
        $this->pdf->registrarPagina($this->avance->refresh(), $this->usuario, $p);
    }

    // Todas las hojas vistas y el video sin ver: ya puede terminar.
    $this->pdf->terminar($this->avance->refresh(), $this->usuario);
    expect($this->avance->refresh()->contenido_visto_en)->not->toBeNull()
        ->and($this->video->videoCompletado($this->avance->refresh()))->toBeFalse();

    $evaluacion = app(EvaluacionInduccionService::class)->habilitarPara($this->proceso->refresh());
    expect($evaluacion)->not->toBeNull()
        ->and(EvaluacionInduccion::query()->where('colaborador_id', $this->colaborador->id)->exists())->toBeTrue();
});

test('material video: sin ver las hojas, el video completo basta para terminar', function () {
    $this->materiales->activar($this->modulo, imVideo($this, 10), $this->rh);

    $this->video->iniciarSesion($this->usuario, $this->avance->refresh());
    foreach ([5, 10] as $pos) {
        $this->travel(5)->seconds(); // el servidor solo acredita el tiempo real transcurrido
        $this->video->registrarHeartbeat($this->usuario, $this->avance->refresh(), $pos);
    }

    expect($this->video->videoCompletado($this->avance->refresh()))->toBeTrue();

    $this->pdf->terminar($this->avance->refresh(), $this->usuario);
    expect($this->avance->refresh()->contenido_visto_en)->not->toBeNull()
        ->and(app(EvaluacionInduccionService::class)->habilitarPara($this->proceso->refresh()))->not->toBeNull();
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
        $this->travel(5)->seconds(); // el servidor solo acredita el tiempo real transcurrido
        $this->video->registrarHeartbeat($this->usuario, $this->avance->refresh(), $pos);
    }

    $segunda = $this->video->iniciarSesion($this->usuario, $this->avance->refresh());

    expect($segunda['posicion_inicial'])->toBe(20)
        ->and($this->video->videoCompletado($this->avance->refresh()))->toBeFalse();
});

test('endpoints HTTP: iniciar/heartbeat del video respetan el anti-seek', function () {
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

/** HLS falso de 20 minutos (200 segmentos de 6 s) en el disco de media. */
function imHlsLargo(): void
{
    $disco = Storage::fake((string) config('media.disk'));
    $disco->put('hls/demo/master.m3u8', implode("\n", [
        '#EXTM3U',
        '#EXT-X-STREAM-INF:BANDWIDTH=800000,RESOLUTION=1280x720',
        '720p.m3u8',
    ]));

    $variante = ['#EXTM3U', '#EXT-X-VERSION:3', '#EXT-X-TARGETDURATION:6', '#EXT-X-PLAYLIST-TYPE:VOD'];
    for ($i = 0; $i < 200; $i++) {
        $variante[] = '#EXTINF:6.0,';
        $variante[] = sprintf('720p_%03d.ts', $i);
    }
    $variante[] = '#EXT-X-ENDLIST';
    $disco->put('hls/demo/720p.m3u8', implode("\n", $variante));
    $disco->put('hls/demo/720p_000.ts', 'seg-0');
    $disco->put('hls/demo/720p_150.ts', 'seg-150');
}

test('precarga: la variante se sirve COMPLETA (VOD + ENDLIST) aunque no se haya visto nada', function () {
    imHlsLargo();
    $this->materiales->activar($this->modulo, imVideo($this, 1200), $this->rh);

    $contenido = $this->actingAs($this->usuario)
        ->get(URL::temporarySignedRoute('portal.induccion.video.variante', now()->addMinutes(5), ['avance' => $this->avance, 'altura' => 720]))
        ->assertOk()
        ->getContent();

    expect(substr_count((string) $contenido, '#EXTINF:'))->toBe(200)
        ->and($contenido)->toContain('#EXT-X-ENDLIST')
        ->and($contenido)->toContain('#EXT-X-PLAYLIST-TYPE:VOD')
        ->and($contenido)->not->toContain('#EXT-X-PLAYLIST-TYPE:EVENT')
        ->and($contenido)->toContain('signature=');
});

test('precarga: un segmento por delante de lo visto se sirve (sin 403 por posición)', function () {
    imHlsLargo();
    $this->materiales->activar($this->modulo, imVideo($this, 1200), $this->rh);

    // Minuto 15, sin haber visto nada: el reproductor puede precargarlo.
    $this->actingAs($this->usuario)
        ->get(URL::temporarySignedRoute('portal.induccion.video.segmento', now()->addMinutes(5), ['avance' => $this->avance, 'altura' => 720, 'archivo' => '720p_150.ts']))
        ->assertOk();

    // Un nombre de archivo ajeno a la variante sigue siendo 404.
    $this->actingAs($this->usuario)
        ->get(URL::temporarySignedRoute('portal.induccion.video.segmento', now()->addMinutes(5), ['avance' => $this->avance, 'altura' => 720, 'archivo' => 'otro.ts']))
        ->assertNotFound();
});

test('heartbeat: la reproducción normal (8 s reales entre reportes) nunca se rechaza', function () {
    $this->materiales->activar($this->modulo, imVideo($this, 1200), $this->rh);
    $this->video->iniciarSesion($this->usuario, $this->avance->refresh());

    // Antes la tolerancia (5 s) era menor que el intervalo (8 s): cada
    // heartbeat de reproducción normal salía «No puedes adelantar…».
    foreach ([8, 16, 24, 32, 40] as $pos) {
        $this->travel(8)->seconds();
        $respuesta = $this->video->registrarHeartbeat($this->usuario, $this->avance->refresh(), $pos);
        expect($respuesta['permitido'])->toBeTrue("rechazó la posición {$pos}");
    }

    expect($this->video->segundoMaximoAlcanzado($this->usuario, $this->avance->refresh()))->toBe(40);
});

test('heartbeat: un buffering solo frena el avance, no lo rechaza', function () {
    $this->materiales->activar($this->modulo, imVideo($this, 1200), $this->rh);
    $this->video->iniciarSesion($this->usuario, $this->avance->refresh());

    // Trabado: pasan 8 s reales y apenas avanzó 1 s; luego retoma.
    $this->travel(8)->seconds();
    expect($this->video->registrarHeartbeat($this->usuario, $this->avance->refresh(), 1)['permitido'])->toBeTrue();
    $this->travel(8)->seconds();
    expect($this->video->registrarHeartbeat($this->usuario, $this->avance->refresh(), 9)['permitido'])->toBeTrue();
});

test('heartbeat: un salto grande no cuenta como visto ni marca el video completo', function () {
    $this->materiales->activar($this->modulo, imVideo($this, 1200), $this->rh);
    $this->video->iniciarSesion($this->usuario, $this->avance->refresh());

    $this->travel(8)->seconds();
    $this->video->registrarHeartbeat($this->usuario, $this->avance->refresh(), 8);

    // A los 8 s reales salta al final del video.
    $this->travel(8)->seconds();
    $respuesta = $this->video->registrarHeartbeat($this->usuario, $this->avance->refresh(), 1199);

    expect($respuesta['permitido'])->toBeFalse()
        ->and($respuesta['completada'])->toBeFalse()
        ->and($respuesta['posicion_permitida'])->toBeLessThanOrEqual(8 + (int) config('media.video.salto_tolerancia_segundos'))
        ->and($this->video->videoCompletado($this->avance->refresh()))->toBeFalse()
        ->and($this->video->segundoMaximoAlcanzado($this->usuario, $this->avance->refresh()))->toBe(8);
});

test('heartbeat: muchos reportes seguidos sin que pase tiempo no acreditan video', function () {
    $this->materiales->activar($this->modulo, imVideo($this, 1200), $this->rh);
    $this->video->iniciarSesion($this->usuario, $this->avance->refresh());

    // Abuso directo a la API: +5 s por petición con el reloj detenido.
    foreach (range(1, 40) as $i) {
        $this->video->registrarHeartbeat($this->usuario, $this->avance->refresh(), $i * 5);
    }

    expect($this->video->segundoMaximoAlcanzado($this->usuario, $this->avance->refresh()))->toBe(0)
        ->and($this->video->porcentajeVisto($this->usuario, $this->avance->refresh()))->toBe(0.0);
});

test('heartbeat: esperar sin reproducir y mandar un solo reporte no marca el video completo', function () {
    $this->materiales->activar($this->modulo, imVideo($this, 1200), $this->rh);
    $this->video->iniciarSesion($this->usuario, $this->avance->refresh());

    $this->travel(20)->minutes();
    $respuesta = $this->video->registrarHeartbeat($this->usuario, $this->avance->refresh(), 1200);

    expect($respuesta['permitido'])->toBeFalse()
        ->and($respuesta['completada'])->toBeFalse()
        ->and($this->video->videoCompletado($this->avance->refresh()))->toBeFalse();
});

test('heartbeat: la tolerancia acepta la posición pero solo acredita el tiempo real, sin huecos', function () {
    $this->materiales->activar($this->modulo, imVideo($this, 1200), $this->rh);
    $this->video->iniciarSesion($this->usuario, $this->avance->refresh());

    // Reporta 5 s adelantado dentro de la tolerancia: se acepta, pero solo cuentan los 8 s reales.
    $this->travel(8)->seconds();
    expect($this->video->registrarHeartbeat($this->usuario, $this->avance->refresh(), 13)['permitido'])->toBeTrue()
        ->and($this->video->segundoMaximoAlcanzado($this->usuario, $this->avance->refresh()))->toBe(8);

    // Al seguir reproduciendo, el tramo continúa desde lo acreditado.
    $this->travel(8)->seconds();
    $this->video->registrarHeartbeat($this->usuario, $this->avance->refresh(), 21);
    $this->travel(8)->seconds();
    $this->video->registrarHeartbeat($this->usuario, $this->avance->refresh(), 29);

    expect($this->video->segundoMaximoAlcanzado($this->usuario, $this->avance->refresh()))->toBe(24)
        ->and(IntervaloVideoInduccion::query()->where('onboarding_avance_id', $this->avance->id)->count())->toBe(1);
});

test('heartbeat HTTP sin terminar la presentación se acepta (el video no espera a las hojas)', function () {
    $this->materiales->activar($this->modulo, imVideo($this, 100), $this->rh);

    $this->actingAs($this->usuario)
        ->postJson(route('portal.induccion.video.iniciar', $this->avance))
        ->assertOk();
    $this->travel(5)->seconds();
    $this->actingAs($this->usuario)
        ->postJson(route('portal.induccion.video.heartbeat', $this->avance), ['posicion_segundos' => 5])
        ->assertOk()
        ->assertJsonPath('permitido', true);

    expect($this->video->videoIniciado($this->avance->refresh()))->toBeTrue();
});
