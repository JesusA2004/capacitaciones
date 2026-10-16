<?php

use App\Models\ExpedienteHistorico;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * Diagnóstico (expedientes:auditar-historicos-storage) y reparación controlada
 * (expedientes:reparar-historicos-storage) de los PDFs históricos de la
 * migración inicial. Todo con Storage::fake: nunca se toca un NAS real ni se
 * divide ninguno de los 748 registros productivos.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Storage::fake('nas_legacy');
    config(['expedientes.disk' => 'nas']);

    $this->super = User::factory()->create();
    $this->super->assignRole('super_admin');

    $this->contenido = 'PDF HISTORICO DE PRUEBA %PDF-1.4';
    $this->hash = hash('sha256', $this->contenido);
});

/**
 * @param  array<string, mixed>  $attrs
 */
function historicoStorage(string $hash, array $attrs = []): ExpedienteHistorico
{
    return ExpedienteHistorico::query()->create(array_merge([
        'colaborador_id' => null,
        'estado' => ExpedienteHistorico::VINCULADO,
        'disk' => 'nas',
        'path' => 'expedientes/Mr. Lana/Cuernavaca/EMP-0001 - Juan/Historico/Expediente historico unificado.pdf',
        'original_name' => 'expediente.pdf',
        'stored_name' => 'Expediente historico unificado.pdf',
        'mime' => 'application/pdf',
        'extension' => 'pdf',
        'size' => 32,
        'hash' => $hash,
        'source_disk' => 'nas_legacy',
        'source_path' => 'RH/Martha/EXPEDIENTES DIGITALES/EMP-0001/expediente.pdf',
    ], $attrs));
}

test('diagnóstico: el canónico existe e íntegro → encontrado, nada por reparar', function () {
    $historico = historicoStorage($this->hash);
    Storage::disk('nas')->put($historico->path, $this->contenido);

    $this->artisan('expedientes:auditar-historicos-storage', ['--todos' => true])
        ->expectsOutputToContain('Encontrados en (disk, path)')
        ->assertSuccessful();

    // La reparación no hace nada (ya está en su sitio).
    $this->artisan('expedientes:reparar-historicos-storage', ['--todos' => true, '--aplicar' => true])
        ->assertSuccessful();

    expect($historico->fresh()->disk)->toBe('nas')
        ->and(Storage::disk('nas')->get($historico->path))->toBe($this->contenido);
});

test('diagnóstico: canónico faltante + origen correcto → reparable', function () {
    $historico = historicoStorage($this->hash);
    Storage::disk('nas_legacy')->put($historico->source_path, $this->contenido);

    $this->artisan('expedientes:auditar-historicos-storage', ['--todos' => true])
        ->expectsOutputToContain('reparable')
        ->assertFailed();
});

test('reparación: --simular (defecto) NO copia nada', function () {
    $historico = historicoStorage($this->hash);
    Storage::disk('nas_legacy')->put($historico->source_path, $this->contenido);

    $this->artisan('expedientes:reparar-historicos-storage', ['--todos' => true])
        ->expectsOutputToContain('SIMULACIÓN')
        ->assertSuccessful();

    expect(Storage::disk('nas')->exists($historico->path))->toBeFalse();
});

test('reparación: --aplicar copia al disco canónico, verifica y actualiza BD', function () {
    $historico = historicoStorage($this->hash, ['disk' => 'nas']);
    Storage::disk('nas_legacy')->put($historico->source_path, $this->contenido);

    expect(Storage::disk('nas')->exists($historico->path))->toBeFalse();

    $this->artisan('expedientes:reparar-historicos-storage', ['--todos' => true, '--aplicar' => true])
        ->assertSuccessful();

    expect(Storage::disk('nas')->exists($historico->path))->toBeTrue()
        ->and(Storage::disk('nas')->get($historico->path))->toBe($this->contenido)
        ->and($historico->fresh()->disk)->toBe('nas')
        // Nunca toca el origen (copia, no mueve).
        ->and(Storage::disk('nas_legacy')->exists($historico->source_path))->toBeTrue();
});

test('reparación: origen también faltante → faltante total, no toca la BD', function () {
    $historico = historicoStorage($this->hash);
    // Ni canónico ni origen existen.

    $this->artisan('expedientes:reparar-historicos-storage', ['--todos' => true, '--aplicar' => true])
        ->expectsOutputToContain('faltante total')
        ->assertSuccessful();

    expect(Storage::disk('nas')->exists($historico->path))->toBeFalse()
        ->and($historico->fresh()->disk)->toBe('nas');
});

test('reparación: hash del origen distinto al registrado → NO repara y lo reporta', function () {
    // El registro dice un hash; el origen tiene OTRO contenido.
    $historico = historicoStorage($this->hash);
    Storage::disk('nas_legacy')->put($historico->source_path, 'CONTENIDO ALTERADO DISTINTO');

    $this->artisan('expedientes:reparar-historicos-storage', ['--todos' => true, '--aplicar' => true])
        ->expectsOutputToContain('no coincide con el registrado')
        ->assertFailed();

    // No se promueve un origen alterado a canónico.
    expect(Storage::disk('nas')->exists($historico->path))->toBeFalse();
});

test('reparación: segunda corrida es idempotente (copia 0)', function () {
    $historico = historicoStorage($this->hash);
    Storage::disk('nas_legacy')->put($historico->source_path, $this->contenido);

    // 1ª corrida: copia.
    $this->artisan('expedientes:reparar-historicos-storage', ['--todos' => true, '--aplicar' => true])->assertSuccessful();
    expect(Storage::disk('nas')->exists($historico->path))->toBeTrue();

    // 2ª corrida: ya está en su sitio, no vuelve a reparar.
    $this->artisan('expedientes:reparar-historicos-storage', ['--todos' => true, '--aplicar' => true])
        ->doesntExpectOutputToContain('se copiaría')
        ->assertSuccessful();

    // El contenido sigue siendo el correcto (no se reescribió con basura).
    expect(Storage::disk('nas')->get($historico->path))->toBe($this->contenido);
});

test('reparación: disco de BD ausente de config + origen válido → re-alinea al canónico (no corta antes)', function () {
    // El (disk,path) de BD apunta a un disco que YA no existe en config
    // (p. ej. un NAS renombrado), pero el origen sí es válido y su SHA coincide.
    // No debe cortar en el guard de disco: la copia va al disco canónico.
    $historico = historicoStorage($this->hash, ['disk' => 'synology']);
    Storage::disk('nas_legacy')->put($historico->source_path, $this->contenido);

    // Dry-run (defecto): NO copia nada.
    $this->artisan('expedientes:reparar-historicos-storage', ['--todos' => true])
        ->expectsOutputToContain('se copiaría')
        ->assertSuccessful();

    expect(Storage::disk('nas')->exists($historico->path))->toBeFalse()
        ->and($historico->fresh()->disk)->toBe('synology');

    // --aplicar: copia al disco canónico y re-apunta disk al canónico.
    $this->artisan('expedientes:reparar-historicos-storage', ['--todos' => true, '--aplicar' => true])
        ->assertSuccessful();

    expect(Storage::disk('nas')->exists($historico->path))->toBeTrue()
        ->and(Storage::disk('nas')->get($historico->path))->toBe($this->contenido)
        ->and($historico->fresh()->disk)->toBe('nas')
        // Nunca toca el origen (copia, no mueve).
        ->and(Storage::disk('nas_legacy')->exists($historico->source_path))->toBeTrue();

    // 2ª corrida: idempotente, ya está en su (disk,path) canónico → copia 0.
    $this->artisan('expedientes:reparar-historicos-storage', ['--todos' => true, '--aplicar' => true])
        ->doesntExpectOutputToContain('se copiaría')
        ->assertSuccessful();

    expect(Storage::disk('nas')->get($historico->path))->toBe($this->contenido)
        ->and($historico->fresh()->disk)->toBe('nas');
});

test('reparación: disco de BD ausente de config y SIN origen configurado → no repara', function () {
    // Sin un origen con disco configurado no hay de dónde copiar: el 2.º guard
    // sigue siendo legítimo y no se repara nada.
    $historico = historicoStorage($this->hash, ['disk' => 'synology', 'source_disk' => 'synology']);

    $this->artisan('expedientes:reparar-historicos-storage', ['--todos' => true, '--aplicar' => true])
        ->expectsOutputToContain('no hay origen con disco configurado')
        ->assertSuccessful();

    expect(Storage::disk('nas')->exists($historico->path))->toBeFalse()
        ->and($historico->fresh()->disk)->toBe('synology');
});

test('diagnóstico: disco inexistente en config se reporta y no truena', function () {
    historicoStorage($this->hash, ['disk' => 'synology', 'source_disk' => 'synology']);

    $this->artisan('expedientes:auditar-historicos-storage', ['--todos' => true])
        ->expectsOutputToContain('NO está en config')
        ->assertFailed();
});
