<?php

use App\Models\CertificacionMrLana;
use App\Models\Colaborador;
use App\Services\Certificados\CertificacionFormatoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use setasign\Fpdi\Fpdi;

/*
 * Certificación Mr. Lana por formato oficial RH-CC-R-001
 * (CertificacionFormatoService, CertificacionMrLanaPolicy): RH descarga el
 * formato con el encabezado lleno, sube el firmado con su promedio, la
 * persona queda certificada y se emite su certificado verificable.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake((string) config('expedientes.disk'));
    config(['pdf.renderer' => 'dompdf', 'features.capacitacion' => true, 'features.certificacion' => true]);

    $estructura = clEstructura();
    $this->sucursal = $estructura['sucursal'];
    $this->rh = clUsuario('rh_admin', ['estatus' => 'activo', 'sucursal_principal_id' => $this->sucursal->id]);
    $this->gestor = Colaborador::factory()->create([
        'name' => 'Saúl Valentín',
        'apellidos' => 'Hernández Zúñiga',
        'estatus' => 'activo',
        'sucursal_principal_id' => $this->sucursal->id,
        'puesto_id' => $estructura['puesto']->id,
        'fecha_ingreso' => '2025-03-15',
    ]);
});

test('descargar el formato lleno y subir el firmado certifica a la persona', function () {
    $respuesta = $this->actingAs($this->rh)
        ->get(route('capacitacion.certificacion.formato', $this->gestor))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $pdf = (string) $respuesta->getContent();
    expect($pdf)->toStartWith('%PDF');

    // Mismas dos hojas del formato original.
    $ruta = tempnam(sys_get_temp_dir(), 'cert');
    file_put_contents($ruta, $pdf);
    expect((new Fpdi)->setSourceFile($ruta))->toBe(2);
    @unlink($ruta);

    $certificacion = CertificacionMrLana::query()->where('colaborador_id', $this->gestor->id)->sole();
    expect($certificacion->formato_descargado_en)->not->toBeNull()
        ->and($certificacion->descargas)->toBe(1)
        ->and($certificacion->certificada())->toBeFalse();

    $this->actingAs($this->rh)
        ->get(route('capacitacion.certificacion'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Capacitacion/Certificacion')
            ->where('puede_gestionar', true)
            ->loadDeferredProps('gestion', fn (AssertableInertia $reload) => $reload
                ->where('gestion.personas', fn ($personas) => collect($personas)->contains(
                    fn ($p) => $p['id'] === $this->gestor->id && $p['estado'] === 'formato_descargado',
                ))));

    // Promedio menor al mínimo del formato (80): no se certifica.
    $this->actingAs($this->rh)->post(route('capacitacion.certificacion.subir-firmado', $this->gestor), [
        'archivo' => UploadedFile::fake()->create('formato-firmado.pdf', 120, 'application/pdf'),
        'promedio' => 72,
        'confirmo_firmas' => '1',
    ])->assertSessionHasErrors('promedio');

    $this->actingAs($this->rh)->post(route('capacitacion.certificacion.subir-firmado', $this->gestor), [
        'archivo' => UploadedFile::fake()->create('formato-firmado.pdf', 120, 'application/pdf'),
        'promedio' => 92.5,
        'confirmo_firmas' => '1',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $certificacion->refresh();
    expect($certificacion->certificada())->toBeTrue()
        ->and($certificacion->folio)->toStartWith('MRLC-')
        ->and((float) $certificacion->promedio)->toBe(92.5)
        ->and($certificacion->emitido_por)->toBe($this->rh->id)
        ->and($certificacion->documentoFirmado?->tipo?->clave)->toBe(CertificacionFormatoService::CLAVE_TIPO)
        ->and($certificacion->certificado_ruta)->not->toBeNull();
    Storage::disk((string) config('expedientes.disk'))->assertExists((string) $certificacion->certificado_ruta);

    $this->actingAs($this->rh)->get(route('capacitacion.certificacion.certificado', $this->gestor))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->rh)->get(route('capacitacion.certificacion.firmado', $this->gestor))->assertOk();

    // Folio verificable públicamente.
    $this->get(route('constancias.verificar', $certificacion->folio))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('valido', true)->where('certificado.curso', 'Certificación Mr. Lana'));

    // Ya certificada: no se vuelve a subir.
    $this->actingAs($this->rh)->post(route('capacitacion.certificacion.subir-firmado', $this->gestor), [
        'archivo' => UploadedFile::fake()->create('otro.pdf', 120, 'application/pdf'),
        'promedio' => 95,
        'confirmo_firmas' => '1',
    ])->assertSessionHasErrors('archivo');
});

test('sin permiso o sobre su propia certificación: 403', function () {
    $colaborador = clUsuario('colaborador', ['estatus' => 'activo', 'sucursal_principal_id' => $this->sucursal->id]);
    $this->actingAs($colaborador)->get(route('capacitacion.certificacion.formato', $this->gestor))->assertForbidden();

    $propio = clUsuarioDe($this->gestor, 'rh_admin');
    $this->actingAs($propio)->get(route('capacitacion.certificacion.formato', $this->gestor))->assertForbidden();
});
