<?php

use App\Models\GeneratedDocument;
use App\Models\ReciboNomina;
use App\Models\User;
use App\Services\Formatos\Motor\ConversorDocxPdf;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;
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

    // La pantalla del módulo anterior se retiró: redirige a Documentos maestros.
    $this->actingAs(adminFormatos())
        ->get(route('rh.formatos.catalogo.index'))
        ->assertRedirect(route('rh.documentos-maestros.index'));

    expect(GeneratedDocument::query()->desdePlantillaEditable()->pluck('id')->all())
        ->toBe([$word->id])
        ->not->toContain($recibo->id);
});

test('A2: un PDF del motor documental con plantilla tampoco es "Word generado"', function () {
    $pdfConPlantilla = GeneratedDocument::factory()->create(['mime' => 'application/pdf', 'path' => 'expedientes/x.pdf']);

    expect($pdfConPlantilla->esDePlantillaEditable())->toBeFalse()
        ->and(GeneratedDocument::query()->desdePlantillaEditable()->count())->toBe(0);
});
