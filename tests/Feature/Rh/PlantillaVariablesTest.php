<?php

use App\Models\Colaborador;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\PhpWord;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
});

function crearDocxParaVariables(string $texto): string
{
    $phpWord = new PhpWord;
    $seccion = $phpWord->addSection();
    $seccion->addText($texto);

    $ruta = sys_get_temp_dir().'/'.uniqid('plantilla_variables', true).'.docx';
    $phpWord->save($ruta, 'Word2007');

    $contenido = file_get_contents($ruta);
    unlink($ruta);

    return $contenido !== false ? $contenido : '';
}

function crearPlantillaConMarcador(string $marcadorExtra): DocumentTemplate
{
    $ruta = 'plantillas/'.uniqid('variables', true).'.docx';
    Storage::disk('nas')->put($ruta, crearDocxParaVariables("Hola {{nombre_completo}}, folio: {{{$marcadorExtra}}}."));

    return DocumentTemplate::factory()->create(['path' => $ruta]);
}

test('GET variables detecta el marcador conocido y el que no corresponde a ningun dato real', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $plantilla = crearPlantillaConMarcador('folio_interno_obra');

    $respuesta = $this->actingAs($usuario)->getJson(route('rh.plantillas.variables', $plantilla));

    $respuesta->assertOk();
    expect($respuesta->json('detectadas'))->toContain('nombre_completo', 'folio_interno_obra')
        ->and($respuesta->json('sin_mapear'))->toBe(['folio_interno_obra'])
        ->and($respuesta->json('catalogo'))->not->toBeEmpty();
});

test('un colaborador no puede consultar las variables de una plantilla', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');
    $plantilla = crearPlantillaConMarcador('folio_interno_obra');

    $this->actingAs($usuario)->getJson(route('rh.plantillas.variables', $plantilla))->assertForbidden();
});

test('no se puede declarar como variable manual una clave que ya es un dato conocido', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $plantilla = crearPlantillaConMarcador('folio_interno_obra');

    $this->actingAs($usuario)
        ->put(route('rh.plantillas.variables.update', $plantilla), [
            'variables' => [
                ['clave' => 'nombre_completo', 'etiqueta' => 'Nombre', 'tipo' => 'text', 'requerido' => false],
            ],
        ])
        ->assertSessionHasErrors('variables.0.clave');
});

test('no se puede declarar como variable manual una clave que no aparece en el docx', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $plantilla = crearPlantillaConMarcador('folio_interno_obra');

    $this->actingAs($usuario)
        ->put(route('rh.plantillas.variables.update', $plantilla), [
            'variables' => [
                ['clave' => 'clave_inventada', 'etiqueta' => 'Inventada', 'tipo' => 'text', 'requerido' => false],
            ],
        ])
        ->assertSessionHasErrors('variables.0.clave');
});

test('rh_admin puede mapear un marcador desconocido como variable manual requerida', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $plantilla = crearPlantillaConMarcador('folio_interno_obra');

    $this->actingAs($usuario)
        ->put(route('rh.plantillas.variables.update', $plantilla), [
            'variables' => [
                ['clave' => 'folio_interno_obra', 'etiqueta' => 'Folio interno de obra', 'tipo' => 'text', 'requerido' => true],
            ],
        ])
        ->assertSessionHasNoErrors();

    $plantilla->refresh();
    expect($plantilla->variables_manuales)->toHaveCount(1)
        ->and($plantilla->variables_manuales[0]['clave'])->toBe('folio_interno_obra')
        ->and($plantilla->variables_manuales[0]['requerido'])->toBeTrue();
});

test('preview marca puede_generar en false si falta una variable manual requerida, y en true al proporcionarla', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $colaborador = User::factory()->for(Colaborador::factory(), 'colaborador')->create();
    $plantilla = crearPlantillaConMarcador('folio_interno_obra');
    $plantilla->update(['variables_manuales' => [
        ['clave' => 'folio_interno_obra', 'etiqueta' => 'Folio', 'tipo' => 'text', 'requerido' => true, 'descripcion' => null, 'valor_por_defecto' => null, 'opciones' => null],
    ]]);

    $sinDato = $this->actingAs($usuario)->postJson(route('rh.formatos.preview'), [
        'document_template_id' => $plantilla->id,
        'tipo_sujeto' => 'colaborador',
        'sujeto_id' => $colaborador->colaborador_id,
    ])->assertOk();
    expect($sinDato->json('puede_generar'))->toBeFalse()
        ->and($sinDato->json('faltantes_requeridos'))->toBe(['folio_interno_obra']);

    $conDato = $this->actingAs($usuario)->postJson(route('rh.formatos.preview'), [
        'document_template_id' => $plantilla->id,
        'tipo_sujeto' => 'colaborador',
        'sujeto_id' => $colaborador->colaborador_id,
        'extra' => ['folio_interno_obra' => 'OBRA-001'],
    ])->assertOk();
    expect($conDato->json('puede_generar'))->toBeTrue()
        ->and($conDato->json('faltantes_requeridos'))->toBe([]);
});

test('store rechaza generar el documento si falta una variable manual requerida', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $colaborador = User::factory()->for(Colaborador::factory(), 'colaborador')->create();
    $plantilla = crearPlantillaConMarcador('folio_interno_obra');
    $plantilla->update(['variables_manuales' => [
        ['clave' => 'folio_interno_obra', 'etiqueta' => 'Folio', 'tipo' => 'text', 'requerido' => true, 'descripcion' => null, 'valor_por_defecto' => null, 'opciones' => null],
    ]]);

    $this->actingAs($usuario)->post(route('rh.formatos.store'), [
        'document_template_id' => $plantilla->id,
        'tipo_sujeto' => 'colaborador',
        'sujeto_id' => $colaborador->colaborador_id,
    ])->assertSessionHasErrors('extra');

    expect(GeneratedDocument::where('document_template_id', $plantilla->id)->exists())->toBeFalse();

    $this->actingAs($usuario)->post(route('rh.formatos.store'), [
        'document_template_id' => $plantilla->id,
        'tipo_sujeto' => 'colaborador',
        'sujeto_id' => $colaborador->colaborador_id,
        'extra' => ['folio_interno_obra' => 'OBRA-001'],
    ])->assertSessionHasNoErrors();

    expect(GeneratedDocument::where('document_template_id', $plantilla->id)->exists())->toBeTrue();
});

test('extra rechaza una clave que no es ni una variable conocida ni una manual declarada', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $colaborador = User::factory()->for(Colaborador::factory(), 'colaborador')->create();
    $plantilla = crearPlantillaConMarcador('folio_interno_obra');

    $this->actingAs($usuario)->postJson(route('rh.formatos.preview'), [
        'document_template_id' => $plantilla->id,
        'tipo_sujeto' => 'colaborador',
        'sujeto_id' => $colaborador->colaborador_id,
        'extra' => ['clave_arbitraria' => 'valor'],
    ])->assertStatus(422);
});
