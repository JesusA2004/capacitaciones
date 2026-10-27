<?php

use App\Enums\EstadoDocumento;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Expedientes\DocumentoParService;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| Las 2 cartas de recomendación son dos documentos del checklist: cada una se
| sube (foto o PDF), se aprueba y se rechaza por separado; si vienen en un
| mismo archivo se sube una vez con `incluye_par` y queda en las dos.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(DocumentTypeSeeder::class);
    Storage::fake('nas');

    $this->carta1 = DocumentType::query()->where('clave', 'carta_recomendacion_1')->firstOrFail();
    $this->carta2 = DocumentType::query()->where('clave', 'carta_recomendacion_2')->firstOrFail();
    $this->usuario = User::factory()->create();
    $this->usuario->assignRole('colaborador');
});

function headersCartas(User $usuario): array
{
    auth()->forgetGuards();

    return ['Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken];
}

function vigenteCarta(DocumentType $tipo): ?EmployeeDocument
{
    return EmployeeDocument::query()->where('document_type_id', $tipo->id)->where('status', '!=', EstadoDocumento::Archivado->value)->orderByDesc('version')->first();
}

test('el catálogo trae dos cartas obligatorias del alta, ya no una sola', function () {
    foreach ([$this->carta1, $this->carta2] as $tipo) {
        expect($tipo->requerido)->toBeTrue()
            ->and($tipo->aplica_alta)->toBeTrue()
            ->and($tipo->activo)->toBeTrue();
    }

    expect($this->carta1->nombre)->toBe('Carta de recomendación 1')
        ->and($this->carta2->nombre)->toBe('Carta de recomendación 2')
        ->and(DocumentType::query()->where('clave', 'cartas_recomendacion')->exists())->toBeFalse();
});

test('la app ofrece subir las dos cartas con un mismo archivo y lo registra en ambas', function () {
    $antes = $this->withHeaders(headersCartas($this->usuario))->getJson('/api/v1/colaborador/incorporacion')->assertOk();
    $item = collect($antes->json('documentos'))->firstWhere('tipo', 'carta_recomendacion_1');

    expect($item['par'])->toBe(['id' => $this->carta2->id, 'nombre' => 'Carta de recomendación 2', 'puede_subir' => true]);

    $despues = $this->withHeaders(headersCartas($this->usuario))
        ->post("/api/v1/colaborador/incorporacion/documentos/{$this->carta1->id}/subir", [
            'archivo' => UploadedFile::fake()->create('cartas.pdf', 200, 'application/pdf'),
            'incluye_par' => '1',
        ])
        ->assertOk();

    $uno = vigenteCarta($this->carta1);
    $dos = vigenteCarta($this->carta2);

    expect($uno->status)->toBe(EstadoDocumento::EnRevision)
        ->and($dos->status)->toBe(EstadoDocumento::EnRevision)
        ->and($dos->original_name)->toBe('cartas.pdf')
        ->and($dos->comments)->toBe('Mismo archivo que «Carta de recomendación 1».')
        // Copia física: nunca dos filas sobre el mismo archivo.
        ->and($dos->path)->not->toBe($uno->path);

    Storage::disk('nas')->assertExists([$uno->path, $dos->path]);

    // Ya en revisión, la carta 2 no vuelve a ofrecerse como par.
    $carta1 = collect($despues->json('documentos'))->firstWhere('tipo', 'carta_recomendacion_1');
    expect($carta1['par']['puede_subir'])->toBeFalse();
});

test('sin marcar el par, cada carta se sube como su propio archivo (dos fotos)', function () {
    $this->withHeaders(headersCartas($this->usuario))
        ->post("/api/v1/colaborador/incorporacion/documentos/{$this->carta1->id}/subir", ['archivo' => UploadedFile::fake()->image('carta-1.jpg')])
        ->assertOk();

    expect(vigenteCarta($this->carta1))->not->toBeNull()
        ->and(vigenteCarta($this->carta2))->toBeNull();

    $this->withHeaders(headersCartas($this->usuario))
        ->post("/api/v1/colaborador/incorporacion/documentos/{$this->carta2->id}/subir", ['archivo' => UploadedFile::fake()->image('carta-2.jpg')])
        ->assertOk();

    expect(vigenteCarta($this->carta2)->original_name)->toBe('carta-2.jpg');
});

test('RH aprueba una carta y rechaza la otra; solo la rechazada se vuelve a subir', function () {
    $this->withHeaders(headersCartas($this->usuario))
        ->post("/api/v1/colaborador/incorporacion/documentos/{$this->carta1->id}/subir", [
            'archivo' => UploadedFile::fake()->create('cartas.pdf', 200, 'application/pdf'),
            'incluye_par' => '1',
        ])
        ->assertOk();

    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $this->actingAs($rh)->post(route('rh.documentos.aprobar', vigenteCarta($this->carta1)))->assertSessionHasNoErrors();
    $this->actingAs($rh)->post(route('rh.documentos.rechazar', vigenteCarta($this->carta2)), ['rejection_reason' => 'La segunda carta no tiene firma.'])->assertSessionHasNoErrors();

    expect(vigenteCarta($this->carta1)->status)->toBe(EstadoDocumento::Aprobado)
        ->and(vigenteCarta($this->carta2)->status)->toBe(EstadoDocumento::Rechazado);

    // La carta 1 aprobada no se puede arrastrar desde la carta 2.
    $this->withHeaders(headersCartas($this->usuario))
        ->post("/api/v1/colaborador/incorporacion/documentos/{$this->carta2->id}/subir", [
            'archivo' => UploadedFile::fake()->create('otra.pdf', 100, 'application/pdf'),
            'incluye_par' => '1',
        ])
        ->assertStatus(422);

    expect(vigenteCarta($this->carta2)->status)->toBe(EstadoDocumento::Rechazado);

    $this->withHeaders(headersCartas($this->usuario))
        ->post("/api/v1/colaborador/incorporacion/documentos/{$this->carta2->id}/subir", ['archivo' => UploadedFile::fake()->create('carta-2.pdf', 100, 'application/pdf')])
        ->assertOk();

    expect(vigenteCarta($this->carta2)->status)->toBe(EstadoDocumento::EnRevision)
        ->and(vigenteCarta($this->carta2)->version)->toBe(2)
        ->and(vigenteCarta($this->carta1)->status)->toBe(EstadoDocumento::Aprobado);
});

test('RH sube desde la web un archivo con las dos cartas', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $this->actingAs($rh)
        ->post(route('rh.expedientes.documentos.store', $this->usuario), [
            'document_type_id' => $this->carta2->id,
            'archivo' => UploadedFile::fake()->create('cartas.pdf', 200, 'application/pdf'),
            'incluye_par' => 1,
        ])
        ->assertSessionHasNoErrors();

    expect(vigenteCarta($this->carta1)?->comments)->toBe('Mismo archivo que «Carta de recomendación 2».')
        ->and(vigenteCarta($this->carta2)?->status)->toBe(EstadoDocumento::EnRevision);
});

test('un tipo sin par no acepta «incluye_par»', function () {
    $ine = DocumentType::query()->where('clave', 'ine')->firstOrFail();

    $this->withHeaders(headersCartas($this->usuario))
        ->post("/api/v1/colaborador/incorporacion/documentos/{$ine->id}/subir", [
            'archivo' => UploadedFile::fake()->create('ine.pdf', 100, 'application/pdf'),
            'incluye_par' => '1',
        ])
        ->assertStatus(422);

    expect(vigenteCarta($ine))->toBeNull();
});

test('los expedientes cargados con «las dos cartas en un archivo» se separan sin perder avance', function () {
    Storage::disk('nas')->put('Expedientes/Persona/Personales/2 cartas de recomendacion - v1.pdf', 'contenido');
    $anterior = EmployeeDocument::factory()->aprobado()->create([
        'user_id' => $this->usuario->id,
        'document_type_id' => $this->carta1->id,
        'disk' => 'nas',
        'path' => 'Expedientes/Persona/Personales/2 cartas de recomendacion - v1.pdf',
        'extension' => 'pdf',
    ]);

    $servicio = app(DocumentoParService::class);
    $resultado = $servicio->separarArchivosCompartidos('carta_recomendacion_1', 'carta_recomendacion_2', 'Copia de la carta 1.');

    $copia = vigenteCarta($this->carta2);

    expect($resultado['copiados'])->toBe(1)
        ->and($resultado['errores'])->toBe([])
        ->and($copia->status)->toBe(EstadoDocumento::Aprobado)
        ->and($copia->colaborador_id)->toBe($anterior->colaborador_id)
        ->and($copia->path)->not->toBe($anterior->path);

    Storage::disk('nas')->assertExists($copia->path);

    // Idempotente.
    expect($servicio->separarArchivosCompartidos('carta_recomendacion_1', 'carta_recomendacion_2', 'Copia de la carta 1.')['copiados'])->toBe(0)
        ->and(EmployeeDocument::query()->where('document_type_id', $this->carta2->id)->count())->toBe(1);
});
