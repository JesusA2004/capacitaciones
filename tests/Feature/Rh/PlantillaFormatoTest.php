<?php

use App\Models\Candidato;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\PhpWord;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
});

/**
 * Genera un .docx real con un placeholder, para probar el flujo completo
 * de subida y generación (no un archivo falso/binario arbitrario).
 */
function crearDocxDePrueba(string $texto): string
{
    $phpWord = new PhpWord;
    $seccion = $phpWord->addSection();
    $seccion->addText($texto);

    $ruta = sys_get_temp_dir().'/'.uniqid('plantilla_prueba', true).'.docx';
    $phpWord->save($ruta, 'Word2007');

    $contenido = file_get_contents($ruta);
    unlink($ruta);

    return $contenido !== false ? $contenido : '';
}

test('rh_admin puede registrar una plantilla docx', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $archivo = UploadedFile::fake()->createWithContent(
        'contrato.docx',
        crearDocxDePrueba('Hola {{nombre_completo}}'),
    );

    $this->actingAs($usuario)
        ->post(route('rh.plantillas.store'), [
            'nombre' => 'Contrato base',
            'tipo' => 'contrato',
            'archivo' => $archivo,
        ])
        ->assertSessionHasNoErrors();

    expect(DocumentTemplate::where('nombre', 'Contrato base')->exists())->toBeTrue();
});

test('un colaborador no puede administrar plantillas', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $this->actingAs($usuario)
        ->get(route('rh.plantillas.index'))
        ->assertForbidden();
});

test('rh_admin puede generar un documento precargado para un colaborador y el placeholder se reemplaza', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $colaborador = User::factory()->create(['name' => 'Juana', 'apellidos' => 'Pérez']);

    $rutaPlantilla = 'plantillas/prueba.docx';
    Storage::disk('nas')->put($rutaPlantilla, crearDocxDePrueba('Hola {{nombre_completo}}, bienvenido.'));

    $plantilla = DocumentTemplate::factory()->create([
        'path' => $rutaPlantilla,
        'tipo' => 'contrato',
    ]);

    $this->actingAs($usuario)
        ->post(route('rh.formatos.store'), [
            'document_template_id' => $plantilla->id,
            'tipo_sujeto' => 'colaborador',
            'sujeto_id' => $colaborador->id,
        ])
        ->assertSessionHasNoErrors();

    $documento = GeneratedDocument::where('document_template_id', $plantilla->id)->firstOrFail();

    expect($documento->user_id)->toBe($colaborador->id)
        ->and(Storage::disk('nas')->exists($documento->path))->toBeTrue();

    // El docx generado es un zip; el texto sustituido vive en word/document.xml.
    $zip = new ZipArchive;
    $rutaLocal = Storage::disk('nas')->path($documento->path);
    $zip->open($rutaLocal);
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    expect($xml)->toContain('Juana')
        ->and($xml)->not->toContain('{{nombre_completo}}');
});

test('rh_auxiliar puede generar formatos pero no administrar plantillas', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_auxiliar');

    $candidato = Candidato::factory()->create();
    $rutaPlantilla = 'plantillas/prueba2.docx';
    Storage::disk('nas')->put($rutaPlantilla, crearDocxDePrueba('Hola {{nombre_completo}}'));
    $plantilla = DocumentTemplate::factory()->create(['path' => $rutaPlantilla]);

    $this->actingAs($usuario)
        ->post(route('rh.formatos.store'), [
            'document_template_id' => $plantilla->id,
            'tipo_sujeto' => 'candidato',
            'sujeto_id' => $candidato->id,
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($usuario)
        ->post(route('rh.plantillas.store'), [
            'nombre' => 'Otra plantilla',
            'tipo' => 'otro',
            'archivo' => UploadedFile::fake()->createWithContent('x.docx', crearDocxDePrueba('x')),
        ])
        ->assertForbidden();
});
