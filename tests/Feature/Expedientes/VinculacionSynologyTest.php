<?php

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\ExpedienteHistorico;
use App\Models\Sucursal;
use App\Services\Expedientes\SimilitudNombres;
use App\Services\Expedientes\VinculacionSynologyService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
 * «Ligar expediente de Synology»: carpeta del NAS que la migración no ligó
 * sola porque el nombre está mal escrito. Storage::fake: nunca toca el NAS real.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');

    $empresa = Empresa::factory()->create(['nombre' => 'Mr. Lana']);
    $this->cuernavaca = Sucursal::factory()->create(['nombre' => 'Cuernavaca', 'empresa_id' => $empresa->id]);
    $this->rh = clUsuario('rh_admin');
    $this->juan = Colaborador::factory()->create(['name' => 'Juan', 'apellidos' => 'Pérez López', 'sucursal_principal_id' => $this->cuernavaca->id, 'expediente_storage_path' => null]);
    $this->servicio = app(VinculacionSynologyService::class);

    $nas = Storage::disk('nas');
    $nas->put('expedientes/Mr. Lana/CUERNAVACA/Juam Peres/Expediente Juam Peres.pdf', '%PDF-1.4 juan');
    $nas->put('expedientes/Mr. Lana/CUERNAVACA/Juam Peres/foto.jpg', 'jpg');
    $nas->put('expedientes/Mr. Lana/CUERNAVACA/Rosa Martinez Diaz/rosa.pdf', '%PDF-1.4 rosa');
    $nas->put('expedientes/Mr. Lana/CUERNAVACA/Pedro Gomez Ruiz/pedro.pdf', '%PDF-1.4 pedro');
    // Carpeta que crea el propio sistema y mini expediente de candidato: nunca se ofrecen.
    $nas->put('expedientes/Mr. Lana/CUERNAVACA/EMP-0009 - Juan Perez/INE - v1.pdf', '%PDF-1.4 sistema');
    $nas->put('candidatos/Juan Perez - 5/cv.pdf', '%PDF-1.4 candidato');

    // Ya ligadas: una como carpeta de expediente, otra por su PDF histórico.
    Colaborador::factory()->create(['sucursal_principal_id' => $this->cuernavaca->id, 'expediente_storage_path' => 'expedientes/Mr. Lana/CUERNAVACA/Rosa Martinez Diaz']);
    $pedro = Colaborador::factory()->create(['sucursal_principal_id' => $this->cuernavaca->id]);
    ExpedienteHistorico::query()->create([
        'colaborador_id' => $pedro->id, 'estado' => ExpedienteHistorico::VINCULADO, 'disk' => 'nas',
        'path' => 'expedientes/Mr. Lana/CUERNAVACA/Pedro Gomez Ruiz/pedro.pdf', 'original_name' => 'pedro.pdf', 'stored_name' => 'pedro.pdf',
        'hash' => str_repeat('a', 64), 'source_path' => 'expedientes/Mr. Lana/CUERNAVACA/Pedro Gomez Ruiz/pedro.pdf',
    ]);
});

test('solo lista carpetas del NAS sin ligar: excluye las ligadas, las del sistema y candidatos/', function () {
    $resultado = $this->servicio->buscar($this->juan, $this->rh);

    expect($resultado['disponible'])->toBeTrue()
        ->and(array_column($resultado['carpetas'], 'carpeta'))->toBe(['Juam Peres'])
        ->and($resultado['carpetas'][0])->not->toHaveKey('ruta');
});

test('la búsqueda tolera acentos, mayúsculas y errores: «juan pérez» encuentra «Juam Peres»', function () {
    expect(SimilitudNombres::fonetica('PEREZ'))->toBe(SimilitudNombres::fonetica('PERES'))
        ->and(SimilitudNombres::fonetica('JUAN'))->toBe(SimilitudNombres::fonetica('JUAM'));

    foreach (['juan perez', 'JUÁN PÉREZ', 'peres', 'jua', 'cuernavaca'] as $consulta) {
        $resultado = $this->servicio->buscar($this->juan, $this->rh, $consulta);

        expect(array_column($resultado['carpetas'], 'carpeta'))->toContain('Juam Peres');
    }

    expect($this->servicio->buscar($this->juan, $this->rh, 'gonzalez')['carpetas'])->toBe([]);

    // Sugerida automáticamente para Juan Pérez López, con su parecido.
    $sugerencias = $this->servicio->buscar($this->juan, $this->rh)['sugerencias'];
    expect($sugerencias[0]['carpeta'])->toBe('Juam Peres')
        ->and($sugerencias[0]['similitud'])->toBeGreaterThanOrEqual(VinculacionSynologyService::UMBRAL_SUGERENCIA);
});

test('el endpoint lista y muestra la vista previa sin exponer rutas del NAS', function () {
    $lista = $this->actingAs($this->rh)->getJson(route('rh.expedientes.synology.carpetas', [$this->juan->id, 'q' => 'juan perez']))
        ->assertOk()
        ->json('data');

    expect($lista['disponible'])->toBeTrue()->and($lista['tiene_expediente'])->toBeFalse();

    $vista = $this->actingAs($this->rh)->getJson(route('rh.expedientes.synology.vista-previa', [$this->juan->id, 'clave' => $lista['carpetas'][0]['clave']]))
        ->assertOk()
        ->json('data');

    expect($vista['total_archivos'])->toBe(2)
        ->and($vista['pdfs_a_registrar'])->toBe(1)
        ->and(collect($vista['archivos'])->firstWhere('nombre', 'Expediente Juam Peres.pdf')['se_registra'])->toBeTrue()
        ->and(json_encode($vista))->not->toContain('expedientes/Mr. Lana');
});

test('ligar registra el PDF en sitio con el camino de la migración y no mueve nada del NAS', function () {
    $clave = $this->servicio->buscar($this->juan, $this->rh, 'juan perez')['carpetas'][0]['clave'];

    $this->actingAs($this->rh)
        ->post(route('rh.expedientes.synology.ligar', $this->juan->id), ['clave' => $clave])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $historico = ExpedienteHistorico::query()->where('colaborador_id', $this->juan->id)->sole();

    expect($historico->path)->toBe('expedientes/Mr. Lana/CUERNAVACA/Juam Peres/Expediente Juam Peres.pdf')
        ->and($historico->estado)->toBe(ExpedienteHistorico::VINCULADO)
        ->and($historico->hash)->toBe(hash('sha256', '%PDF-1.4 juan'))
        ->and($historico->vinculado_por)->toBe($this->rh->id)
        ->and($historico->migracion_id)->toBeNull()
        ->and($this->juan->fresh()->expediente_storage_path)->toBe('expedientes/Mr. Lana/CUERNAVACA/Juam Peres')
        ->and($this->servicio->tieneExpedienteLigado($this->juan->fresh()))->toBeTrue();

    Storage::disk('nas')->assertExists('expedientes/Mr. Lana/CUERNAVACA/Juam Peres/Expediente Juam Peres.pdf');
    Storage::disk('nas')->assertExists('expedientes/Mr. Lana/CUERNAVACA/Juam Peres/foto.jpg');

    // Ya ligada: deja de ofrecerse a cualquiera.
    expect($this->servicio->buscar($this->juan, $this->rh)['carpetas'])->toBe([]);
});

test('no se liga dos veces: ni la misma carpeta a otra persona ni otra carpeta a quien ya tiene', function () {
    $otraPersona = Colaborador::factory()->create(['name' => 'Juana', 'apellidos' => 'Peres', 'sucursal_principal_id' => $this->cuernavaca->id, 'expediente_storage_path' => null]);
    $clave = $this->servicio->buscar($this->juan, $this->rh)['carpetas'][0]['clave'];

    $this->servicio->ligar($this->juan, $this->rh, $clave);

    $this->actingAs($this->rh)
        ->post(route('rh.expedientes.synology.ligar', $otraPersona->id), ['clave' => $clave])
        ->assertSessionHasErrors('synology');

    expect(ExpedienteHistorico::query()->where('colaborador_id', $otraPersona->id)->exists())->toBeFalse();

    Storage::disk('nas')->put('expedientes/Mr. Lana/CUERNAVACA/Juan Perez L/otro.pdf', '%PDF-1.4 otro');
    $otraClave = $this->servicio->buscar($this->juan, $this->rh, 'juan', refrescar: true)['carpetas'][0]['clave'];

    expect(fn () => $this->servicio->ligar($this->juan, $this->rh, $otraClave))
        ->toThrow(ValidationException::class, 'ya tiene un expediente de Synology ligado');
});

test('Synology no disponible: mensaje claro en español, nunca un 500', function () {
    Storage::fake('nas');

    $datos = $this->actingAs($this->rh)->getJson(route('rh.expedientes.synology.carpetas', $this->juan->id))
        ->assertOk()
        ->json('data');

    expect($datos['disponible'])->toBeFalse()
        ->and($datos['error'])->toContain('No se pudo leer Synology');

    $this->actingAs($this->rh)
        ->post(route('rh.expedientes.synology.ligar', $this->juan->id), ['clave' => str_repeat('a', 40)])
        ->assertSessionHasErrors('synology');
});

test('requiere expedientes.editar y alcance: un colaborador no puede ligar', function () {
    $colaborador = clUsuario('colaborador');

    $this->actingAs($colaborador)->getJson(route('rh.expedientes.synology.carpetas', $this->juan->id))->assertForbidden();
    $this->actingAs($colaborador)->post(route('rh.expedientes.synology.ligar', $this->juan->id), ['clave' => str_repeat('a', 40)])->assertForbidden();
});
