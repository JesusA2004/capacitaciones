<?php

use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\PhpWord;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
});

function crearDocxPreviewPrueba(string $texto): string
{
    $phpWord = new PhpWord;
    $seccion = $phpWord->addSection();
    $seccion->addText($texto);

    $ruta = sys_get_temp_dir().'/'.uniqid('preview_prueba', true).'.docx';
    $phpWord->save($ruta, 'Word2007');

    $contenido = file_get_contents($ruta);
    unlink($ruta);

    return $contenido !== false ? $contenido : '';
}

test('rh_admin puede previsualizar un formato y ve las variables faltantes', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    // domicilio no se llena en el factory de User por defecto -> queda
    // vacio -> debe salir como "faltante" para este colaborador.
    $colaborador = User::factory()->create(['name' => 'Juana', 'apellidos' => 'Pérez', 'domicilio' => null]);

    $ruta = 'plantillas/preview.docx';
    Storage::disk('nas')->put($ruta, crearDocxPreviewPrueba('Hola {{nombre_completo}}, domicilio: {{domicilio}}'));

    $plantilla = DocumentTemplate::factory()->create(['path' => $ruta, 'tipo' => 'contrato']);

    $respuesta = $this->actingAs($usuario)
        ->postJson(route('rh.formatos.preview'), [
            'document_template_id' => $plantilla->id,
            'tipo_sujeto' => 'colaborador',
            'sujeto_id' => $colaborador->id,
        ])
        ->assertOk()
        ->json();

    expect($respuesta['faltantes'])->toContain('domicilio')
        ->and($respuesta['variables'])->toHaveKey('nombre_completo');
});

test('un rol sin formatos.preview no puede previsualizar', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $colaborador = User::factory()->create();
    $plantilla = DocumentTemplate::factory()->create([
        'path' => 'plantillas/no-existe.docx',
        'tipo' => 'contrato',
    ]);

    $this->actingAs($usuario)
        ->postJson(route('rh.formatos.preview'), [
            'document_template_id' => $plantilla->id,
            'tipo_sujeto' => 'colaborador',
            'sujeto_id' => $colaborador->id,
        ])
        ->assertForbidden();
});

test('el catalogo de formatos expone las variables reales de cada plantilla', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $ruta = 'plantillas/catalogo.docx';
    Storage::disk('nas')->put($ruta, crearDocxPreviewPrueba('{{nombre_completo}} — {{rfc}}'));

    DocumentTemplate::factory()->create(['path' => $ruta, 'tipo' => 'contrato', 'nombre' => 'Contrato de prueba']);

    $respuesta = $this->actingAs($usuario)->get(route('rh.formatos.catalogo.index'));

    $respuesta->assertInertia(fn ($page) => $page
        ->has('plantillasDisponibles', 1)
        ->where('plantillasDisponibles.0.variables', ['nombre_completo', 'rfc']));
});

test('descargar el pdf de un documento generado no truena aunque la conversion falle', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $plantilla = DocumentTemplate::factory()->create(['tipo' => 'contrato']);
    $ruta = 'documentos-generados/prueba.docx';
    Storage::disk('nas')->put($ruta, crearDocxPreviewPrueba('Documento de prueba'));

    $documento = GeneratedDocument::factory()->create([
        'document_template_id' => $plantilla->id,
        'path' => $ruta,
        'generated_name' => 'prueba.docx',
    ]);

    $respuesta = $this->actingAs($usuario)->get(route('rh.formatos.descargar-pdf', $documento));

    // O regresa el PDF (conversion exitosa) o un redirect con aviso de
    // error (conversion no soportada) — nunca un 500.
    expect($respuesta->status())->toBeLessThan(500);
});

test('la api movil de formatos no expone documentos de colaboradores fuera del alcance', function () {
    $sucursalPropia = Sucursal::factory()->create();
    $sucursalAjena = Sucursal::factory()->create();

    $gerente = User::factory()->create(['sucursal_principal_id' => $sucursalPropia->id]);
    $gerente->assignRole('gerente_sucursal');

    $colaboradorAjeno = User::factory()->create(['sucursal_principal_id' => $sucursalAjena->id]);

    $plantilla = DocumentTemplate::factory()->create(['tipo' => 'contrato']);
    $ruta = 'documentos-generados/ajeno.docx';
    Storage::disk('nas')->put($ruta, crearDocxPreviewPrueba('Documento ajeno'));

    $documento = GeneratedDocument::factory()->create([
        'document_template_id' => $plantilla->id,
        'user_id' => $colaboradorAjeno->id,
        'path' => $ruta,
        'generated_name' => 'ajeno.docx',
    ]);

    $this->actingAs($gerente, 'sanctum')
        ->getJson(route('api.v1.rh.formatos.descargar', $documento))
        ->assertNotFound();
});
