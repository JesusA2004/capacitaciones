<?php

use App\Enums\EstadoCargaMultimedia;
use App\Enums\EstadoMultimedia;
use App\Enums\TipoModuloOnboarding;
use App\Enums\TipoRecursoMultimedia;
use App\Jobs\ProcesarVideoJob;
use App\Models\CargaMultimedia;
use App\Models\InduccionMaterialExplicacion;
use App\Models\OnboardingModulo;
use App\Models\RecursoMultimedia;
use App\Models\User;
use App\Services\Onboarding\MaterialInduccionService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/*
| Material de explicación de la inducción (RH): video por bloques reanudable
| SIN feature:capacitacion, vista previa del original mientras el HLS no está
| listo y estado real del procesamiento (con aviso si la cola no corre).
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    Bus::fake([ProcesarVideoJob::class]);
    Storage::fake(config('media.disk'));
    config(['media.carga_resumible.tamano_bloque_mb' => 1]);

    $this->rh = clUsuario('rh_admin');
    $this->rh->givePermissionTo(['onboarding.gestionar', 'onboarding.configurar']);

    $this->modulo = OnboardingModulo::query()->create([
        'clave' => 'induccion_mr_lana_2026',
        'titulo' => 'Inducción Mr. Lana',
        'tipo' => TipoModuloOnboarding::Institucional,
        'orden' => 1,
        'evaluacion_formal_clave' => 'induccion_mr_lana_2026',
        'obligatorio' => true,
        'activo' => true,
    ]);
});

function cmiBloque(int $bytes): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'bloque_');
    file_put_contents($ruta, str_repeat('v', $bytes));

    return new UploadedFile($ruta, 'bloque.part', 'application/octet-stream', null, true);
}

test('RH sube el video por bloques y al completarse queda activado como material (en cola para HLS)', function () {
    $mega = 1024 * 1024;
    $total = $mega + 1000;

    $carga = $this->actingAs($this->rh)
        ->postJson(route('rh.onboarding.induccion.material.cargas.iniciar'), [
            'nombre_original' => 'induccion.mp4',
            'tamano_total_bytes' => $total,
        ])
        ->assertOk()
        ->assertJsonPath('total_bloques', 2)
        ->assertJsonPath('estado', 'en_progreso')
        ->json();

    // Bloques fuera de orden: el último primero.
    $this->actingAs($this->rh)
        ->post(route('rh.onboarding.induccion.material.cargas.bloque', $carga['identificador']), ['numero_bloque' => 1, 'bloque' => cmiBloque(1000)], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('estado', 'en_progreso');

    expect(InduccionMaterialExplicacion::query()->where('activo', true)->exists())->toBeFalse();

    $final = $this->actingAs($this->rh)
        ->post(route('rh.onboarding.induccion.material.cargas.bloque', $carga['identificador']), ['numero_bloque' => 0, 'bloque' => cmiBloque($mega)], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('estado', EstadoCargaMultimedia::Completada->value)
        ->json();

    $recurso = RecursoMultimedia::query()->findOrFail($final['recurso_multimedia_id']);
    expect($recurso->tipo)->toBe(TipoRecursoMultimedia::Video)
        ->and($recurso->estado)->toBe(EstadoMultimedia::Pendiente)
        ->and($recurso->tamano_bytes)->toBe($total)
        ->and($recurso->mime_type)->not->toBeNull();
    Storage::disk(config('media.disk'))->assertExists($recurso->ruta_original);
    Bus::assertDispatched(ProcesarVideoJob::class, fn (ProcesarVideoJob $job) => $job->recurso->is($recurso)
        && $job->connection === config('media.cola.conexion')
        && $job->queue === config('media.cola.nombre'));

    $activo = app(MaterialInduccionService::class)->materialActivo($this->modulo);
    expect($activo?->recurso_multimedia_id)->toBe($recurso->id)
        ->and($activo?->tipo->value)->toBe('video');
});

test('reenviar un bloque de una carga ya completada no vuelve a activar el material', function () {
    $carga = CargaMultimedia::factory()->create([
        'user_id' => $this->rh->id,
        'estado' => EstadoCargaMultimedia::Completada,
        'total_bloques' => 1,
        'bloques_recibidos' => [0],
    ]);

    $this->actingAs($this->rh)
        ->post(route('rh.onboarding.induccion.material.cargas.bloque', $carga->identificador), ['numero_bloque' => 0, 'bloque' => cmiBloque(10)], ['Accept' => 'application/json'])
        ->assertStatus(422);

    expect(InduccionMaterialExplicacion::query()->count())->toBe(0);
});

test('la carga por bloques valida extensión y tamaño, y exige el módulo de inducción', function () {
    $this->actingAs($this->rh)
        ->postJson(route('rh.onboarding.induccion.material.cargas.iniciar'), ['nombre_original' => 'virus.exe', 'tamano_total_bytes' => 10])
        ->assertJsonValidationErrors('nombre_original');

    $this->actingAs($this->rh)
        ->postJson(route('rh.onboarding.induccion.material.cargas.iniciar'), [
            'nombre_original' => 'enorme.mp4',
            'tamano_total_bytes' => (config('media.max_upload_mb') + 1) * 1024 * 1024,
        ])
        ->assertJsonValidationErrors('tamano_total_bytes');

    $this->modulo->update(['activo' => false]);
    $this->actingAs($this->rh)
        ->postJson(route('rh.onboarding.induccion.material.cargas.iniciar'), ['nombre_original' => 'induccion.mp4', 'tamano_total_bytes' => 10])
        ->assertJsonValidationErrors('modulo');
});

test('sin onboarding.configurar no se puede cargar por bloques ni operar cargas ajenas', function () {
    $colaborador = clUsuario('colaborador');

    $this->actingAs($colaborador)
        ->postJson(route('rh.onboarding.induccion.material.cargas.iniciar'), ['nombre_original' => 'induccion.mp4', 'tamano_total_bytes' => 10])
        ->assertForbidden();

    $ajena = CargaMultimedia::factory()->create(['user_id' => $colaborador->id]);
    $this->actingAs($colaborador)
        ->getJson(route('rh.onboarding.induccion.material.cargas.estado', $ajena->identificador))
        ->assertForbidden();

    // RH tampoco ve la carga de otro usuario (scopeada al usuario).
    $this->actingAs($this->rh)
        ->getJson(route('rh.onboarding.induccion.material.cargas.estado', $ajena->identificador))
        ->assertNotFound();
});

test('mientras el HLS no está listo RH ve el original firmado (con Range) y el colaborador no', function () {
    Storage::disk(config('media.disk'))->put('originales/demo.mp4', str_repeat('x', 2000));
    $video = RecursoMultimedia::factory()->create([
        'tipo' => TipoRecursoMultimedia::Video,
        'estado' => EstadoMultimedia::Pendiente,
        'ruta_original' => 'originales/demo.mp4',
        'ruta_hls_manifiesto' => null,
        'mime_type' => 'video/mp4',
        'subido_por' => $this->rh->id,
    ]);
    app(MaterialInduccionService::class)->activar($this->modulo, $video, $this->rh);

    $datos = $this->actingAs($this->rh)
        ->getJson(route('rh.onboarding.induccion.material.vista-previa.datos'))
        ->assertOk()
        ->assertJsonPath('data.disponible', false)
        ->assertJsonPath('data.video_url', null)
        ->assertJsonPath('data.estado', 'pendiente')
        ->json('data');

    expect($datos['original_url'])->toBeString();

    $respuesta = $this->actingAs($this->rh)->get($datos['original_url'], ['Range' => 'bytes=0-99']);
    $respuesta->assertStatus(206);
    expect($respuesta->headers->get('Content-Type'))->toBe('video/mp4')
        ->and($respuesta->headers->get('Content-Range'))->toBe('bytes 0-99/2000');

    // Sin firma: 403.
    $this->actingAs($this->rh)->get(route('rh.onboarding.induccion.material.vista-previa.original'))->assertForbidden();

    // Firmada pero sin permiso de RH: 403.
    $colaborador = clUsuario('colaborador');
    $this->actingAs($colaborador)->get($datos['original_url'])->assertForbidden();

    // Una vez listo el HLS, la vista previa vuelve a ser el HLS (sin original).
    $video->update(['estado' => EstadoMultimedia::Disponible, 'ruta_hls_manifiesto' => 'hls/demo/master.m3u8']);
    $this->actingAs($this->rh)
        ->getJson(route('rh.onboarding.induccion.material.vista-previa.datos'))
        ->assertJsonPath('data.disponible', true)
        ->assertJsonPath('data.original_url', null);
});

test('estado del material: real, con aviso de cola detenida y reproceso sin volver a subir', function () {
    config(['media.cola.alerta_minutos' => 10]);
    $video = RecursoMultimedia::factory()->create([
        'tipo' => TipoRecursoMultimedia::Video,
        'estado' => EstadoMultimedia::Pendiente,
        'subido_por' => $this->rh->id,
    ]);
    app(MaterialInduccionService::class)->activar($this->modulo, $video, $this->rh);

    $this->actingAs($this->rh)
        ->getJson(route('rh.onboarding.induccion.material.estado'))
        ->assertOk()
        ->assertJsonPath('data.recurso_estado', 'pendiente')
        ->assertJsonPath('data.listo', false)
        ->assertJsonPath('data.cola_detenida', false)
        ->assertJsonPath('data.puede_reprocesar', false);

    // 15 minutos después sigue sin tomarse: la cola parece detenida.
    RecursoMultimedia::query()->whereKey($video->id)->update(['updated_at' => now()->subMinutes(15)]);
    $this->actingAs($this->rh)
        ->getJson(route('rh.onboarding.induccion.material.estado'))
        ->assertJsonPath('data.cola_detenida', true)
        ->assertJsonPath('data.puede_reprocesar', true);

    // Falló: se muestra el motivo y RH puede reintentar sin volver a subir.
    $video->update(['estado' => EstadoMultimedia::Error, 'error_procesamiento' => 'ffprobe falló: archivo dañado']);
    $this->actingAs($this->rh)
        ->getJson(route('rh.onboarding.induccion.material.estado'))
        ->assertJsonPath('data.recurso_estado', 'error')
        ->assertJsonPath('data.error', 'ffprobe falló: archivo dañado');

    $this->actingAs($this->rh)
        ->post(route('rh.onboarding.induccion.material.reprocesar'))
        ->assertRedirect();

    expect($video->fresh()->estado)->toBe(EstadoMultimedia::Pendiente)
        ->and($video->fresh()->error_procesamiento)->toBeNull();
    Bus::assertDispatched(ProcesarVideoJob::class);

    // Sin permiso de RH, ni estado ni reproceso.
    $colaborador = clUsuario('colaborador');
    $this->actingAs($colaborador)->getJson(route('rh.onboarding.induccion.material.estado'))->assertForbidden();
    $this->actingAs($colaborador)->post(route('rh.onboarding.induccion.material.reprocesar'))->assertForbidden();
});

test('el audio sigue subiéndose en un solo envío y queda disponible de inmediato', function () {
    $this->actingAs($this->rh)
        ->post(route('rh.onboarding.induccion.material.store'), [
            'tipo' => 'audio',
            'archivo' => UploadedFile::fake()->create('narracion.mp3', 200, 'audio/mpeg'),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $material = app(MaterialInduccionService::class)->materialActivo($this->modulo);
    expect($material?->tipo->value)->toBe('audio')
        ->and($material?->recurso?->estado)->toBe(EstadoMultimedia::Disponible);
    Bus::assertNotDispatched(ProcesarVideoJob::class);

    // Audio mayor al límite de un solo request: mensaje de validación, no un 413 mudo.
    config(['media.audio_max_mb' => 1]);
    $this->actingAs($this->rh)
        ->post(route('rh.onboarding.induccion.material.store'), [
            'tipo' => 'audio',
            'archivo' => UploadedFile::fake()->create('larga.mp3', 3000, 'audio/mpeg'),
        ])
        ->assertSessionHasErrors('archivo');
});

test('un usuario sin permiso de RH no ve el original aunque tenga una URL firmada', function () {
    $sinPermiso = User::factory()->create();
    $url = URL::temporarySignedRoute('rh.onboarding.induccion.material.vista-previa.original', now()->addMinutes(5));

    $this->actingAs($sinPermiso)->get($url)->assertForbidden();
});
