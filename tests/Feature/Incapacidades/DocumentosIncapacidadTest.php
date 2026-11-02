<?php

use App\Enums\EstadoUsuario;
use App\Enums\TipoDocumentoIncapacidad;
use App\Models\Colaborador;
use App\Models\Incapacidad;
use App\Models\IncapacidadDocumento;
use App\Services\Incapacidades\IncapacidadService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Incapacidades · carga, reemplazo y consulta de documentos del IMSS desde el
| registro y el expediente (correcciones de la zona de carga).
*/

beforeEach(function () {
    clRelojMediodiaMexico();
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $estructura = clEstructura();
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $estructura['sucursal']->id]);
    $this->persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'puesto_id' => $estructura['puesto']->id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => '2020-01-01',
    ]);

    $this->registrar = fn (array $datos = []): Incapacidad => app(IncapacidadService::class)->registrar($this->persona, [
        'tipo' => 'enfermedad_general',
        'secuencia' => 'inicial',
        'serie_folio_imss' => 'EG '.fake()->unique()->numerify('#######'),
        'fecha_inicio' => '2026-10-01',
        'dias' => 3,
        ...$datos,
    ], UploadedFile::fake()->create('certificado.pdf', 20, 'application/pdf'), null, $this->rh);
});

it('una subsecuente de riesgo no trae ST-7: ese documento vive en la inicial', function () {
    $inicial = ($this->registrar)(['tipo' => 'riesgo_trabajo', 'subtipo_riesgo' => 'accidente_trabajo', 'fecha_inicio' => '2026-10-01']);

    $this->actingAs($this->rh)->post('/rh/incapacidades', [
        'colaborador_id' => $this->persona->id,
        'tipo' => 'riesgo_trabajo',
        'secuencia' => 'subsecuente',
        'incapacidad_inicial_id' => $inicial->id,
        'serie_folio_imss' => 'RT 8800001',
        'fecha_inicio' => '2026-10-04',
        'dias' => 2,
        'certificado' => UploadedFile::fake()->create('c.pdf', 5, 'application/pdf'),
        'st7' => UploadedFile::fake()->create('st7.pdf', 5, 'application/pdf'),
    ])->assertSessionHasErrors(['st7' => 'El ST-7 se carga en la incapacidad inicial del riesgo: súbelo desde su expediente.']);

    expect(Incapacidad::query()->where('incapacidad_inicial_id', $inicial->id)->exists())->toBeFalse()
        ->and(IncapacidadDocumento::query()->where('tipo', 'st7')->exists())->toBeFalse();
});

it('rechaza formato, tamaño y fecha futura con mensajes claros en español', function () {
    $incapacidad = ($this->registrar)();
    $url = "/rh/incapacidades/{$incapacidad->id}/documentos";

    $this->actingAs($this->rh)->post($url, ['tipo' => 'certificado', 'motivo' => 'Ilegible', 'archivo' => UploadedFile::fake()->create('notas.docx', 5, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')])
        ->assertSessionHasErrors(['archivo' => 'El archivo debe ser PDF o imagen (PDF, JPG, JPEG, PNG, WEBP).']);

    $this->actingAs($this->rh)->post($url, ['tipo' => 'certificado', 'motivo' => 'Ilegible', 'archivo' => UploadedFile::fake()->create('grande.pdf', 16000, 'application/pdf')])
        ->assertSessionHasErrors(['archivo' => 'El archivo supera el tamaño máximo de 15 MB.']);

    $this->actingAs($this->rh)->post($url, ['tipo' => 'certificado', 'motivo' => 'Ilegible'])
        ->assertSessionHasErrors(['archivo' => 'Elige el archivo (PDF o imagen).']);

    $this->actingAs($this->rh)->post('/rh/incapacidades', [
        'colaborador_id' => $this->persona->id,
        'tipo' => 'enfermedad_general',
        'secuencia' => 'inicial',
        'serie_folio_imss' => 'EG 4400001',
        'fecha_inicio' => '2026-10-20',
        'dias' => 2,
        'certificado' => UploadedFile::fake()->create('foto.gif', 5, 'image/gif'),
    ])->assertSessionHasErrors(['certificado' => 'El certificado debe ser PDF o imagen (PDF, JPG, JPEG, PNG, WEBP).']);

    $riesgo = ($this->registrar)(['tipo' => 'riesgo_trabajo', 'subtipo_riesgo' => 'accidente_trabajo', 'fecha_inicio' => '2026-10-10']);
    $this->actingAs($this->rh)->post("/rh/incapacidades/{$riesgo->id}/documentos", ['tipo' => 'st7', 'fecha_documento' => now()->addDays(3)->toDateString(), 'archivo' => UploadedFile::fake()->create('st7.pdf', 5, 'application/pdf')])
        ->assertSessionHasErrors(['fecha_documento' => 'La fecha del documento no puede ser futura.']);

    expect(IncapacidadDocumento::query()->count())->toBe(2);
});

it('sube y reemplaza desde el expediente con aviso claro y conserva la versión anterior', function () {
    $riesgo = ($this->registrar)(['tipo' => 'riesgo_trabajo', 'subtipo_riesgo' => 'accidente_trabajo', 'fecha_inicio' => '2026-10-10']);
    $url = "/rh/incapacidades/{$riesgo->id}/documentos";

    $this->actingAs($this->rh)->post($url, ['tipo' => 'st7', 'fecha_documento' => '2026-10-11', 'archivo' => UploadedFile::fake()->image('st7.jpg')])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'ST-7 cargado.');

    $this->actingAs($this->rh)->post($url, ['tipo' => 'st7', 'archivo' => UploadedFile::fake()->create('st7.pdf', 5, 'application/pdf')])
        ->assertSessionHasErrors(['motivo' => 'Ya hay un documento cargado: indica el motivo para reemplazarlo.']);

    $this->actingAs($this->rh)->post($url, ['tipo' => 'st7', 'motivo' => '  Foto ilegible  ', 'archivo' => UploadedFile::fake()->create('st7.pdf', 5, 'application/pdf')])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'ST-7 reemplazado. La versión anterior se conserva.');

    $st7 = IncapacidadDocumento::query()->where('tipo', 'st7')->orderBy('id')->get();
    expect($st7)->toHaveCount(2)
        ->and($st7[0]->reemplazado_en)->not->toBeNull()
        ->and($st7[1]->reemplazado_en)->toBeNull()
        ->and($st7[1]->motivo_reemplazo)->toBe('Foto ilegible')
        ->and(IncapacidadDocumento::query()->where('tipo', 'st7')->whereNull('reemplazado_en')->count())->toBe(1);

    // El expediente entrega lo que el visor y la zona de carga necesitan.
    $this->actingAs($this->rh)->get("/rh/incapacidades/{$riesgo->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Rh/Incapacidades/Expediente')
        ->where('maxKb', 15360)
        ->where('mimes', ['pdf', 'jpg', 'jpeg', 'png', 'webp'])
        ->where('cadenas.0.documentos.1.mime', 'image/jpeg')
        ->where('cadenas.0.documentos.1.vigente', false)
        ->where('cadenas.0.documentos.2.mime', 'application/pdf')
        ->where('cadenas.0.documentos.2.tamano', 5 * 1024)
    );
});

it('sirve el documento inline aunque el nombre original lleve acentos', function () {
    $incapacidad = app(IncapacidadService::class)->registrar($this->persona, [
        'tipo' => 'enfermedad_general',
        'secuencia' => 'inicial',
        'serie_folio_imss' => 'EG 7100001',
        'fecha_inicio' => '2026-10-01',
        'dias' => 2,
    ], UploadedFile::fake()->create('Certificado José Núñez.pdf', 5, 'application/pdf'), null, $this->rh);
    $documento = $incapacidad->documentos()->firstOrFail();

    $respuesta = $this->actingAs($this->rh)->get("/rh/incapacidades/documentos/{$documento->id}")->assertOk();
    $disposicion = (string) $respuesta->headers->get('content-disposition');

    expect($disposicion)->toStartWith('inline;')
        ->and($disposicion)->toContain('filename="Certificado Jose Nunez.pdf"')
        ->and($disposicion)->toContain("filename*=utf-8''Certificado%20Jos%C3%A9%20N%C3%BA%C3%B1ez.pdf")
        ->and($respuesta->headers->get('content-type'))->toContain('application/pdf');
});

it('una incapacidad cancelada no admite documentos nuevos', function () {
    $incapacidad = ($this->registrar)();
    app(IncapacidadService::class)->cancelar($incapacidad, 'Captura duplicada', $this->rh);

    expect(fn () => app(IncapacidadService::class)->subirDocumento($incapacidad->refresh(), TipoDocumentoIncapacidad::Certificado, UploadedFile::fake()->create('c.pdf', 5, 'application/pdf'), null, 'Otro', $this->rh))
        ->toThrow(ValidationException::class, 'cancelada');
});
