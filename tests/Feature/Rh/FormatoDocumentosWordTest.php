<?php

use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\ReciboNomina;
use App\Models\User;
use App\Services\Formatos\Motor\ConversorDocxPdf;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpWord\PhpWord;

/*
 * Regresión del 500 en producción (GET /rh/formatos/2/descargar-pdf →
 * TypeError en ConversorDocxPdf::convertir(null), y /descargar →
 * UnableToRetrieveMetadata): el documento 2 era un recibo de nómina en PDF
 * sin document_template_id que el catálogo Word trataba como DOCX.
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    config(['formatos_oficiales.libreoffice' => null]);
});

function docxDePrueba(string $texto): string
{
    $phpWord = new PhpWord;
    $phpWord->addSection()->addText($texto);
    $ruta = sys_get_temp_dir().'/'.uniqid('word_generado', true).'.docx';
    $phpWord->save($ruta, 'Word2007');
    $contenido = (string) file_get_contents($ruta);
    unlink($ruta);

    return $contenido;
}

function adminFormatos(): User
{
    $usuario = User::factory()->create();
    $usuario->assignRole('super_admin');

    return $usuario;
}

function reciboNominaPdf(): GeneratedDocument
{
    $recibo = ReciboNomina::factory()->create();

    return GeneratedDocument::factory()->create([
        'document_template_id' => null,
        'disk' => 'nas',
        'path' => 'expedientes/recibo-nomina.pdf',
        'generated_name' => 'Recibo de nómina 2026-09.pdf',
        'mime' => 'application/pdf',
        'documentable_type' => $recibo->getMorphClass(),
        'documentable_id' => $recibo->id,
    ]);
}

function wordGenerado(array $atributos = []): GeneratedDocument
{
    return GeneratedDocument::factory()->create(array_merge([
        'disk' => 'nas',
        'path' => 'documentos-generados/'.uniqid().'.docx',
        'generated_name' => 'Contrato - 2026-09-28.docx',
        'mime' => GeneratedDocument::MIME_DOCX,
    ], $atributos));
}

test('A: un recibo de nómina PDF no aparece en el catálogo Generados (Word)', function () {
    $recibo = reciboNominaPdf();
    $word = wordGenerado();

    $this->actingAs(adminFormatos())
        ->get(route('rh.formatos.catalogo.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Rh/Formatos/Index')
            ->has('documentos.data', 1)
            ->where('documentos.data.0.id', $word->id));

    expect(GeneratedDocument::query()->desdePlantillaEditable()->pluck('id')->all())
        ->toBe([$word->id])
        ->not->toContain($recibo->id);
});

test('A2: un PDF del motor documental con plantilla tampoco es "Word generado"', function () {
    $pdfConPlantilla = GeneratedDocument::factory()->create(['mime' => 'application/pdf', 'path' => 'expedientes/x.pdf']);

    expect($pdfConPlantilla->esDePlantillaEditable())->toBeFalse()
        ->and(GeneratedDocument::query()->desdePlantillaEditable()->count())->toBe(0);
});

test('B: descargar un PDF que no es Word responde 404 controlado, nunca 500', function () {
    $recibo = reciboNominaPdf();
    Storage::disk('nas')->put($recibo->path, '%PDF-1.4 recibo');

    $this->actingAs(adminFormatos())
        ->get(route('rh.formatos.descargar', $recibo))
        ->assertNotFound();
});

test('C: descargar-pdf sobre un PDF que no es Word responde 404 y jamás llama al conversor DOCX', function () {
    $recibo = reciboNominaPdf();

    $this->mock(ConversorDocxPdf::class)->shouldNotReceive('convertir');

    $this->actingAs(adminFormatos())
        ->get(route('rh.formatos.descargar-pdf', $recibo))
        ->assertNotFound();
});

test('C2: la API móvil tampoco trata un recibo PDF como Word', function () {
    $recibo = reciboNominaPdf();

    $this->mock(ConversorDocxPdf::class)->shouldNotReceive('convertir');

    $this->actingAs(adminFormatos(), 'sanctum')
        ->getJson(route('api.v1.rh.formatos.descargar-pdf', $recibo))
        ->assertNotFound();
    $this->actingAs(adminFormatos(), 'sanctum')
        ->getJson(route('api.v1.rh.formatos.descargar', $recibo))
        ->assertNotFound();
});

test('C3: eliminar desde el catálogo Word nunca borra un recibo de nómina', function () {
    $recibo = reciboNominaPdf();
    Storage::disk('nas')->put($recibo->path, '%PDF-1.4 recibo');

    $this->actingAs(adminFormatos())
        ->delete(route('rh.formatos.destroy', $recibo))
        ->assertNotFound();

    expect(GeneratedDocument::query()->whereKey($recibo->id)->exists())->toBeTrue();
    Storage::disk('nas')->assertExists($recibo->path);
});

test('D: un Word válido se descarga como DOCX', function () {
    $word = wordGenerado();
    Storage::disk('nas')->put($word->path, docxDePrueba('Contrato de prueba'));

    $respuesta = $this->actingAs(adminFormatos())
        ->get(route('rh.formatos.descargar', $word))
        ->assertOk();

    expect($respuesta->headers->get('Content-Disposition'))->toContain('Contrato - 2026-09-28.docx');
});

test('E: un Word válido se descarga como PDF', function () {
    $word = wordGenerado();
    Storage::disk('nas')->put($word->path, docxDePrueba('Contrato de prueba'));

    $respuesta = $this->actingAs(adminFormatos())
        ->get(route('rh.formatos.descargar-pdf', $word))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($respuesta->headers->get('Content-Disposition'))->toContain('Contrato - 2026-09-28.pdf');
});

test('F: Word registrado sin archivo físico regresa con aviso, sin 500', function () {
    $word = wordGenerado();

    $this->mock(ConversorDocxPdf::class)->shouldNotReceive('convertir');
    $admin = adminFormatos();

    $this->actingAs($admin)
        ->from(route('rh.formatos.catalogo.index'))
        ->get(route('rh.formatos.descargar-pdf', $word))
        ->assertRedirect(route('rh.formatos.catalogo.index'))
        ->assertSessionHas('toast.message', 'El archivo fuente de este documento ya no está disponible.');

    $this->actingAs($admin)
        ->from(route('rh.formatos.catalogo.index'))
        ->get(route('rh.formatos.descargar', $word))
        ->assertRedirect(route('rh.formatos.catalogo.index'))
        ->assertSessionHas('toast.message', 'El archivo fuente de este documento ya no está disponible.');

    $this->actingAs($admin, 'sanctum')
        ->getJson(route('api.v1.rh.formatos.descargar', $word))
        ->assertNotFound();
});

test('G: se lee del disco registrado en el documento, no del disco por defecto', function () {
    Storage::fake('local');
    $word = wordGenerado(['disk' => 'local']);
    Storage::disk('local')->put($word->path, docxDePrueba('En otro disco'));

    $this->actingAs(adminFormatos())
        ->get(route('rh.formatos.descargar', $word))
        ->assertOk();
});

test('G2: un disco que no existe en la configuración se rechaza de forma controlada', function () {
    $word = wordGenerado(['disk' => 'disco-inexistente']);
    Storage::disk('nas')->put($word->path, docxDePrueba('x'));

    $this->actingAs(adminFormatos())
        ->from(route('rh.formatos.catalogo.index'))
        ->get(route('rh.formatos.descargar', $word))
        ->assertRedirect(route('rh.formatos.catalogo.index'))
        ->assertSessionHas('toast.type', 'error');
});

test('el catálogo Word sigue listando los documentos de plantilla con su plantilla', function () {
    $plantilla = DocumentTemplate::factory()->create(['nombre' => 'Contrato base']);
    wordGenerado(['document_template_id' => $plantilla->id]);

    $this->actingAs(adminFormatos())
        ->get(route('rh.formatos.catalogo.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('documentos.data.0.plantilla.nombre', 'Contrato base'));
});
