<?php

use App\Enums\TipoLeccion;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\InscripcionCurso;
use App\Models\Leccion;
use App\Models\RecursoMultimedia;
use App\Models\User;
use App\Services\Capacitacion\ProgresoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);

    Storage::fake('nas');

    $this->colaborador = User::factory()->create();
    $this->colaborador->assignRole('colaborador');

    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id, 'orden' => 1]);

    $this->recurso = RecursoMultimedia::factory()->create([
        'nombre_interno' => 'video-test.mp4',
        'duracion_segundos' => 30,
        'ruta_hls_manifiesto' => 'hls/video-test/master.m3u8',
    ]);

    $this->leccion = Leccion::factory()->create([
        'curso_modulo_id' => $modulo->id,
        'tipo' => TipoLeccion::Video,
        'recurso_multimedia_id' => $this->recurso->id,
        'obligatoria' => true,
    ]);

    $this->curso = $curso->fresh(['modulos.lecciones']);

    Storage::disk('nas')->put('hls/video-test/master.m3u8', implode("\n", [
        '#EXTM3U',
        '#EXT-X-STREAM-INF:BANDWIDTH=800000,RESOLUTION=640x360',
        '360p.m3u8',
    ]));

    $variante = ['#EXTM3U', '#EXT-X-VERSION:3', '#EXT-X-TARGETDURATION:6', '#EXT-X-PLAYLIST-TYPE:VOD'];

    for ($i = 0; $i < 5; $i++) {
        $variante[] = '#EXTINF:6.0,';
        $variante[] = sprintf('360p_%03d.ts', $i);
        Storage::disk('nas')->put(sprintf('hls/video-test/360p_%03d.ts', $i), "contenido-falso-{$i}");
    }
    $variante[] = '#EXT-X-ENDLIST';

    Storage::disk('nas')->put('hls/video-test/360p.m3u8', implode("\n", $variante));

    InscripcionCurso::create(['user_id' => $this->colaborador->id, 'curso_id' => $this->curso->id, 'estado' => 'pendiente']);
});

function iniciarReproduccion(User $usuario, Leccion $leccion): array
{
    $respuesta = test()->actingAs($usuario)
        ->postJson(route('mi-capacitacion.lecciones.reproduccion.iniciar', $leccion))
        ->assertOk();

    return $respuesta->json();
}

test('un colaborador sin el curso asignado no puede iniciar la reproduccion', function () {
    $otro = User::factory()->create();
    $otro->assignRole('colaborador');

    $this->actingAs($otro)
        ->postJson(route('mi-capacitacion.lecciones.reproduccion.iniciar', $this->leccion))
        ->assertForbidden();
});

test('iniciar la reproduccion crea una sesion y arranca en el segundo cero', function () {
    $datos = iniciarReproduccion($this->colaborador, $this->leccion);

    expect($datos['posicion_inicial'])->toBe(0);
    expect($datos['duracion_total_segundos'])->toBe(30);
    expect($datos['completada'])->toBeFalse();
    expect($datos['url_manifiesto'])->toContain('manifiesto/master.m3u8');
});

test('el manifiesto maestro reescribe la variante hacia una ruta firmada propia', function () {
    $datos = iniciarReproduccion($this->colaborador, $this->leccion);

    $contenido = $this->actingAs($this->colaborador)
        ->get($datos['url_manifiesto'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.apple.mpegurl')
        ->getContent();

    expect($contenido)->not->toContain('360p.m3u8');
    expect($contenido)->toContain('reproduccion/manifiesto/360.m3u8');
});

test('la variante solo lista los segmentos hasta el limite de avance permitido', function () {
    $urlVariante = URL::temporarySignedRoute(
        'mi-capacitacion.lecciones.reproduccion.variante',
        now()->addMinutes(5),
        ['leccion' => $this->leccion->id, 'altura' => 360],
    );

    $contenido = $this->actingAs($this->colaborador)->get($urlVariante)->assertOk()->getContent();

    expect(substr_count($contenido, '#EXTINF'))->toBe(1);
    expect($contenido)->toContain('#EXT-X-PLAYLIST-TYPE:EVENT');
    expect($contenido)->not->toContain('#EXT-X-ENDLIST');
});

test('un segmento mas alla del limite permitido se rechaza y uno dentro del limite se sirve', function () {
    $urlPermitido = URL::temporarySignedRoute(
        'mi-capacitacion.lecciones.reproduccion.segmento',
        now()->addMinutes(5),
        ['leccion' => $this->leccion->id, 'altura' => 360, 'archivo' => '360p_000.ts'],
    );
    $urlRechazado = URL::temporarySignedRoute(
        'mi-capacitacion.lecciones.reproduccion.segmento',
        now()->addMinutes(5),
        ['leccion' => $this->leccion->id, 'altura' => 360, 'archivo' => '360p_001.ts'],
    );

    $this->actingAs($this->colaborador)->get($urlPermitido)->assertOk();
    $this->actingAs($this->colaborador)->get($urlRechazado)->assertForbidden();
});

test('un heartbeat que salta mucho hacia adelante se rechaza y no mueve el avance', function () {
    $datos = iniciarReproduccion($this->colaborador, $this->leccion);

    $respuesta = $this->actingAs($this->colaborador)
        ->postJson(route('mi-capacitacion.lecciones.reproduccion.heartbeat', $this->leccion), [
            'sesion_id' => $datos['sesion_id'],
            'posicion_segundos' => 25,
        ])
        ->assertOk()
        ->json();

    expect($respuesta['permitido'])->toBeFalse();
    expect($respuesta['posicion_permitida'])->toBe(5);
});

test('avanzar en pasos dentro de la tolerancia completa la leccion automaticamente', function () {
    $datos = iniciarReproduccion($this->colaborador, $this->leccion);
    $sesionId = $datos['sesion_id'];

    $ultima = null;

    foreach ([5, 10, 15, 20, 25, 30] as $posicion) {
        $ultima = $this->actingAs($this->colaborador)
            ->postJson(route('mi-capacitacion.lecciones.reproduccion.heartbeat', $this->leccion), [
                'sesion_id' => $sesionId,
                'posicion_segundos' => $posicion,
            ])
            ->assertOk()
            ->json();

        expect($ultima['permitido'])->toBeTrue();
    }

    expect($ultima['porcentaje_visto'])->toEqual(100);
    expect($ultima['completada'])->toBeTrue();
    expect(app(ProgresoService::class)->leccionCompletada($this->colaborador, $this->leccion))->toBeTrue();
});

test('las lecciones de video no se pueden marcar como completadas manualmente', function () {
    $this->actingAs($this->colaborador)
        ->post(route('mi-capacitacion.lecciones.completar', $this->leccion))
        ->assertSessionHas('toast', fn ($toast) => $toast['type'] === 'error');

    expect(app(ProgresoService::class)->leccionCompletada($this->colaborador, $this->leccion))->toBeFalse();
});
