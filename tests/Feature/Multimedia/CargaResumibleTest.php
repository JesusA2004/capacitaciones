<?php

use App\Enums\EstadoCargaMultimedia;
use App\Enums\EstadoMultimedia;
use App\Jobs\ProcesarVideoJob;
use App\Models\CargaMultimedia;
use App\Models\RecursoMultimedia;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

/**
 * Auditoría de cumplimiento sección 7 (docs/AUDITORIA_CUMPLIMIENTO.md):
 * `cargas_multimedia` existía desde la Fase 3 pero era una tabla fantasma
 * (ningún código la usaba). Estas pruebas verifican la carga por bloques
 * reanudable real: sesión de carga, recepción de bloques fuera de orden,
 * pausa/reanudación, cancelación, verificación de hash y ensamblado final.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    config(['media.carga_resumible.tamano_bloque_mb' => 1]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('administrador_capacitacion');

    $this->colaborador = User::factory()->create();
    $this->colaborador->assignRole('colaborador');
});

// UploadedFile::fake()->create() no escribe contenido real (el archivo
// temporal queda en 0 bytes; solo "finge" el tamaño reportado para las
// reglas de validación), así que no sirve para los tests que verifican
// bytes/hash reales tras el ensamblado. bloqueReal() sí escribe contenido
// real de exactamente $bytes, envuelto como UploadedFile en modo de prueba.
function bloqueReal(int $bytes): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'bloque_');
    file_put_contents($ruta, str_repeat('a', $bytes));

    return new UploadedFile($ruta, 'bloque.part', 'application/octet-stream', null, true);
}

function bloqueDeKilobytes(int $kilobytes): UploadedFile
{
    return bloqueReal($kilobytes * 1024);
}

function bloqueDePrueba(int $bytes): UploadedFile
{
    return UploadedFile::fake()->create('bloque.part', (int) ceil($bytes / 1024));
}

test('un colaborador sin permiso no puede iniciar una carga', function () {
    $this->actingAs($this->colaborador)
        ->postJson(route('multimedia.cargas.iniciar'), [
            'nombre_original' => 'induccion.mp4',
            'tipo' => 'video',
            'tamano_total_bytes' => 2_500_000,
        ])
        ->assertForbidden();
});

test('iniciar una carga calcula el total de bloques segun el tamano configurado', function () {
    $respuesta = $this->actingAs($this->admin)
        ->postJson(route('multimedia.cargas.iniciar'), [
            'nombre_original' => 'induccion.mp4',
            'tipo' => 'video',
            'tamano_total_bytes' => 2_500_000, // 1MB de bloque => 3 bloques
        ])
        ->assertOk()
        ->json();

    expect($respuesta['total_bloques'])->toBe(3);
    expect($respuesta['estado'])->toBe('en_progreso');
    expect(CargaMultimedia::count())->toBe(1);
});

test('iniciar dos veces la misma carga la reanuda en vez de duplicarla', function () {
    $primera = $this->actingAs($this->admin)
        ->postJson(route('multimedia.cargas.iniciar'), [
            'nombre_original' => 'induccion.mp4',
            'tipo' => 'video',
            'tamano_total_bytes' => 2_500_000,
        ])->json();

    $segunda = $this->actingAs($this->admin)
        ->postJson(route('multimedia.cargas.iniciar'), [
            'nombre_original' => 'induccion.mp4',
            'tipo' => 'video',
            'tamano_total_bytes' => 2_500_000,
        ])->json();

    expect($segunda['identificador'])->toBe($primera['identificador']);
    expect(CargaMultimedia::count())->toBe(1);
});

test('enviar todos los bloques fuera de orden ensambla el archivo y crea el recurso multimedia', function () {
    Bus::fake();

    $tamanoTotal = (1024 * 2 + 512) * 1024; // dos bloques de 1024KB + uno de 512KB = 3 bloques de 1MB

    $identificador = $this->actingAs($this->admin)
        ->postJson(route('multimedia.cargas.iniciar'), [
            'nombre_original' => 'induccion.mp4',
            'tipo' => 'video',
            'tamano_total_bytes' => $tamanoTotal,
        ])->json('identificador');

    // Bloque 2 primero (fuera de orden), luego 0, luego 1.
    $this->actingAs($this->admin)->postJson(route('multimedia.cargas.bloque', $identificador), [
        'numero_bloque' => 2,
        'bloque' => bloqueDeKilobytes(512),
    ])->assertOk();

    $this->actingAs($this->admin)->postJson(route('multimedia.cargas.bloque', $identificador), [
        'numero_bloque' => 0,
        'bloque' => bloqueDeKilobytes(1024),
    ])->assertOk();

    $respuesta = $this->actingAs($this->admin)->postJson(route('multimedia.cargas.bloque', $identificador), [
        'numero_bloque' => 1,
        'bloque' => bloqueDeKilobytes(1024),
    ])->assertOk()->json();

    expect($respuesta['estado'])->toBe('completada');
    expect($respuesta['porcentaje'])->toBe(100);

    $carga = CargaMultimedia::where('identificador', $identificador)->firstOrFail();
    expect($carga->estado)->toBe(EstadoCargaMultimedia::Completada);
    expect($carga->recurso_multimedia_id)->not->toBeNull();

    $recurso = RecursoMultimedia::findOrFail($carga->recurso_multimedia_id);
    expect($recurso->estado)->toBe(EstadoMultimedia::Pendiente);
    expect($recurso->tamano_bytes)->toBe($tamanoTotal);
    Storage::disk('nas')->assertExists($recurso->ruta_original);
    Bus::assertDispatched(ProcesarVideoJob::class, fn ($job) => $job->recurso->is($recurso));
});

test('reenviar el mismo bloque no duplica los bytes recibidos', function () {
    $identificador = $this->actingAs($this->admin)
        ->postJson(route('multimedia.cargas.iniciar'), [
            'nombre_original' => 'induccion.mp4',
            'tipo' => 'video',
            'tamano_total_bytes' => 2_500_000,
        ])->json('identificador');

    $this->actingAs($this->admin)->postJson(route('multimedia.cargas.bloque', $identificador), [
        'numero_bloque' => 0,
        'bloque' => bloqueDeKilobytes(1024),
    ])->assertOk();

    $respuesta = $this->actingAs($this->admin)->postJson(route('multimedia.cargas.bloque', $identificador), [
        'numero_bloque' => 0,
        'bloque' => bloqueDeKilobytes(1024),
    ])->assertOk()->json();

    expect($respuesta['bytes_recibidos'])->toBe(1024 * 1024);
    expect($respuesta['bloques_recibidos'])->toBe([0]);
});

test('un bloque con numero fuera de rango se rechaza', function () {
    $identificador = $this->actingAs($this->admin)
        ->postJson(route('multimedia.cargas.iniciar'), [
            'nombre_original' => 'induccion.mp4',
            'tipo' => 'video',
            'tamano_total_bytes' => 2_500_000,
        ])->json('identificador');

    $this->actingAs($this->admin)->postJson(route('multimedia.cargas.bloque', $identificador), [
        'numero_bloque' => 99,
        'bloque' => bloqueDePrueba(1_000_000),
    ])->assertStatus(422);
});

test('pausar impide recibir bloques y reanudar los vuelve a permitir', function () {
    $identificador = $this->actingAs($this->admin)
        ->postJson(route('multimedia.cargas.iniciar'), [
            'nombre_original' => 'induccion.mp4',
            'tipo' => 'video',
            'tamano_total_bytes' => 2_500_000,
        ])->json('identificador');

    $this->actingAs($this->admin)->postJson(route('multimedia.cargas.pausar', $identificador))
        ->assertOk()
        ->assertJsonPath('estado', 'pausada');

    $this->actingAs($this->admin)->postJson(route('multimedia.cargas.bloque', $identificador), [
        'numero_bloque' => 0,
        'bloque' => bloqueDePrueba(1_000_000),
    ])->assertStatus(422);

    $this->actingAs($this->admin)->postJson(route('multimedia.cargas.reanudar', $identificador))
        ->assertOk()
        ->assertJsonPath('estado', 'en_progreso');

    $this->actingAs($this->admin)->postJson(route('multimedia.cargas.bloque', $identificador), [
        'numero_bloque' => 0,
        'bloque' => bloqueDePrueba(1_000_000),
    ])->assertOk();
});

test('cancelar una carga borra los bloques temporales y ya no admite bloques', function () {
    $identificador = $this->actingAs($this->admin)
        ->postJson(route('multimedia.cargas.iniciar'), [
            'nombre_original' => 'induccion.mp4',
            'tipo' => 'video',
            'tamano_total_bytes' => 2_500_000,
        ])->json('identificador');

    $this->actingAs($this->admin)->postJson(route('multimedia.cargas.bloque', $identificador), [
        'numero_bloque' => 0,
        'bloque' => bloqueDePrueba(1_000_000),
    ])->assertOk();

    $this->actingAs($this->admin)->deleteJson(route('multimedia.cargas.cancelar', $identificador))
        ->assertOk()
        ->assertJsonPath('estado', 'cancelada');

    Storage::disk('nas')->assertDirectoryEmpty("temporales/cargas/{$identificador}");

    $this->actingAs($this->admin)->postJson(route('multimedia.cargas.bloque', $identificador), [
        'numero_bloque' => 1,
        'bloque' => bloqueDePrueba(1_000_000),
    ])->assertStatus(422);
});

test('un hash esperado incorrecto marca la carga en error y no crea el recurso', function () {
    $tamanoTotal = 1024 * 1024 * 2; // dos bloques exactos de 1024KB, coincide con total_bloques=2

    $identificador = $this->actingAs($this->admin)
        ->postJson(route('multimedia.cargas.iniciar'), [
            'nombre_original' => 'induccion.mp4',
            'tipo' => 'video',
            'tamano_total_bytes' => $tamanoTotal,
            // Hash deliberadamente incorrecto: el contenido real de los
            // bloques (bloqueDeKilobytes) nunca produce este sha256.
            'hash_esperado' => str_repeat('a', 64),
        ])->json('identificador');

    $this->actingAs($this->admin)->postJson(route('multimedia.cargas.bloque', $identificador), ['numero_bloque' => 0, 'bloque' => bloqueDeKilobytes(1024)]);
    $respuesta = $this->actingAs($this->admin)->postJson(route('multimedia.cargas.bloque', $identificador), ['numero_bloque' => 1, 'bloque' => bloqueDeKilobytes(1024)])->json();

    expect($respuesta['estado'])->toBe('error');
    expect($respuesta['error'])->toContain('hash');
    expect(RecursoMultimedia::count())->toBe(0);
});

test('un usuario no puede operar la carga de otro usuario', function () {
    $otroAdmin = User::factory()->create();
    $otroAdmin->assignRole('administrador_capacitacion');

    $identificador = $this->actingAs($this->admin)
        ->postJson(route('multimedia.cargas.iniciar'), [
            'nombre_original' => 'induccion.mp4',
            'tipo' => 'video',
            'tamano_total_bytes' => 2_500_000,
        ])->json('identificador');

    $this->actingAs($otroAdmin)
        ->getJson(route('multimedia.cargas.estado', $identificador))
        ->assertNotFound();
});
