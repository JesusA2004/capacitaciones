<?php

use App\Enums\EstadoDocumento;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| Las cartas de recomendación son dos documentos del checklist (la 1
| obligatoria, la 2 opcional): cada una se sube (foto o PDF), se aprueba y se
| rechaza por separado; si vienen en un mismo archivo se sube una vez con
| `incluye_par` y queda en las dos.
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

test('la carta 1 es obligatoria del alta y la carta 2 opcional del checklist', function () {
    expect($this->carta1->requerido)->toBeTrue()
        ->and($this->carta1->aplica_alta)->toBeTrue()
        ->and($this->carta1->nombre)->toBe('Carta de recomendación 1')
        ->and($this->carta2->requerido)->toBeFalse()
        ->and($this->carta2->checklist_opcional)->toBeTrue()
        ->and($this->carta2->activo)->toBeTrue()
        ->and($this->carta2->nombre)->toBe('Carta de recomendación 2')
        ->and(DocumentType::query()->where('clave', 'cartas_recomendacion')->exists())->toBeFalse();
});

test('la app lista la carta 2 como opcional y con solo la carta 1 aprobada el expediente queda completo', function () {
    $requeridos = DocumentType::query()->where('requerido', true)->where('activo', true)->get();

    foreach ($requeridos as $tipo) {
        EmployeeDocument::factory()->aprobado()->create(['user_id' => $this->usuario->id, 'document_type_id' => $tipo->id]);
    }

    $respuesta = $this->withHeaders(headersCartas($this->usuario))->getJson('/api/v1/colaborador/incorporacion')->assertOk();
    $carta2 = collect($respuesta->json('documentos'))->firstWhere('tipo', 'carta_recomendacion_2');

    expect($carta2['obligatorio'])->toBeFalse()
        ->and($carta2['puede_subir'])->toBeTrue()
        ->and($respuesta->json('progreso.total'))->toBe($requeridos->count())
        ->and($respuesta->json('progreso.porcentaje'))->toBe(100)
        ->and($respuesta->json('estado'))->toBe('completo');
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
