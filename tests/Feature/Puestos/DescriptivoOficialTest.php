<?php

use App\Models\DescriptivoPuesto;
use App\Models\PerfilPuesto;
use App\Models\Puesto;
use App\Services\Puestos\Descriptivos\DescriptivoPuestoService;
use App\Services\Puestos\Descriptivos\LectorDescriptivoPdf;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;

/*
| Descriptivos oficiales (PDF de RH): se leen con la capa de texto (Poppler,
| sin OCR ni servicios externos), se guarda una COPIA EXACTA del original,
| la importación es idempotente por SHA-256, se asocia solo por nombre
| exacto o alias explícito y el perfil del candidato queda como borrador
| con lo que el PDF trae.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');

    if (! app(LectorDescriptivoPdf::class)->disponible()) {
        $this->markTestSkipped('Poppler (pdftotext -bbox-layout) no está disponible en este servidor.');
    }

    $this->carpeta = base_path('docs/descriptivos');
    $this->gerentePdf = $this->carpeta.'/DESCRIPTIVO DE PUESTO - GERENTE MR LANA.1.pdf';
});

test('lee el descriptivo real del Gerente: encabezado, objetivo, funciones, tablas y metadatos', function () {
    $datos = app(LectorDescriptivoPdf::class)->leer((string) file_get_contents($this->gerentePdf));

    expect($datos['requiere_ocr'] ?? false)->toBeFalse()
        ->and($datos['nombre_puesto'])->toBe('Gerente de Sucursal')
        ->and($datos['objetivo'])->not->toBe('')
        ->and(count($datos['funciones']))->toBeGreaterThan(20)
        ->and(count($datos['conocimientos']))->toBeGreaterThan(0)
        ->and(count($datos['competencias']))->toBeGreaterThan(0)
        ->and($datos['paginas'])->toBeGreaterThan(0);
});

test('importar: copia exacta, idempotente por SHA-256, alias explícito y perfil en borrador sin pisar a RH', function () {
    $gerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'activo' => true]);
    $servicio = app(DescriptivoPuestoService::class);
    $pdf = (string) file_get_contents($this->gerentePdf);

    $sugeridos = $servicio->puestosSugeridos('Gerente de Sucursal');
    expect(collect($sugeridos)->pluck('id')->all())->toBe([$gerente->id]);

    $descriptivo = $servicio->importar($pdf, basename($this->gerentePdf), [$gerente->id], clUsuario('rh_admin'));
    $otraVez = $servicio->importar($pdf, basename($this->gerentePdf), [$gerente->id], clUsuario('rh_admin'));

    expect($otraVez->id)->toBe($descriptivo->id)
        ->and(DescriptivoPuesto::query()->count())->toBe(1)
        ->and($descriptivo->version)->toBe(1)
        // El PDF guardado es el MISMO archivo, byte por byte.
        ->and(hash('sha256', (string) Storage::disk('nas')->get($descriptivo->path)))->toBe(hash('sha256', $pdf));

    $perfil = PerfilPuesto::query()->where('puesto_id', $gerente->id)->firstOrFail();
    expect($perfil->origen)->toBe('documento')
        ->and($perfil->descriptivo_puesto_id)->toBe($descriptivo->id);

    // Si RH ya lo ajustó, una nueva importación no lo pisa.
    $perfil->update(['origen' => 'rh', 'objetivo' => 'Ajustado por RH']);
    $servicio->importar($pdf, basename($this->gerentePdf), [$gerente->id], clUsuario('rh_admin'));
    expect($perfil->fresh()?->objetivo)->toBe('Ajustado por RH');
});

test('sin puesto exacto ni alias, RH debe confirmar: nunca se asocia por parecido', function () {
    Puesto::factory()->create(['nombre' => 'Auditora de Contraloría', 'activo' => true]);
    $servicio = app(DescriptivoPuestoService::class);

    expect($servicio->puestosSugeridos('Auditoria de mesa de control'))->toBe([]);

    $ruta = $this->carpeta.'/DESCRIPTIVO DE PUESTO AUDITORIA DE MESA DE CONTROL.1.pdf';
    $analisis = $servicio->analizar((string) file_get_contents($ruta), basename($ruta));

    expect($analisis['estado'])->toBe('confirmar_puesto');
});

test('el PDF original se ve inline o se descarga solo con permiso y por su puesto', function () {
    $gerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'activo' => true]);
    $otro = Puesto::factory()->create(['nombre' => 'Subgerente', 'activo' => true]);
    $descriptivo = app(DescriptivoPuestoService::class)->importar((string) file_get_contents($this->gerentePdf), basename($this->gerentePdf), [$gerente->id], clUsuario('rh_admin'));
    $rh = clUsuario('rh_admin');

    $enLinea = $this->actingAs($rh)->get(route('rh.perfiles-puesto.pdf', [$gerente, $descriptivo]))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect((string) $enLinea->headers->get('Content-Disposition'))->toStartWith('inline');

    $descarga = $this->actingAs($rh)->get(route('rh.perfiles-puesto.pdf', [$gerente, $descriptivo, 'descargar' => 1]))->assertOk();
    expect((string) $descarga->headers->get('Content-Disposition'))->toStartWith('attachment');

    $this->actingAs($rh)->get(route('rh.perfiles-puesto.pdf', [$otro, $descriptivo]))->assertNotFound();
    $this->actingAs(clUsuario('colaborador'))->get(route('rh.perfiles-puesto.pdf', [$gerente, $descriptivo]))->assertForbidden();
});

test('la lectura del puesto muestra el documento oficial y su versión', function () {
    $gerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'activo' => true]);
    app(DescriptivoPuestoService::class)->importar((string) file_get_contents($this->gerentePdf), basename($this->gerentePdf), [$gerente->id], clUsuario('rh_admin'));

    $this->actingAs(clUsuario('rh_admin'))->get(route('rh.perfiles-puesto.show', $gerente))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('descriptivo.version', 1)
            ->where('descriptivo.datos.nombre_puesto', 'Gerente de Sucursal')
            ->where('origen', 'documento'));
});
