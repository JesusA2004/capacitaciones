<?php

use App\Models\Colaborador;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Puesto;
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

test('rh_admin puede marcar una variable automática detectada como requerida, sin declarar etiqueta/tipo', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $plantilla = crearPlantillaConMarcador('folio_interno_obra');

    $this->actingAs($usuario)
        ->put(route('rh.plantillas.variables.update', $plantilla), [
            'variables' => [
                ['clave' => 'nombre_completo', 'requerido' => true],
            ],
        ])
        ->assertSessionHasNoErrors();

    $plantilla->refresh();
    expect($plantilla->variables_manuales)->toHaveCount(1)
        ->and($plantilla->variables_manuales[0]['clave'])->toBe('nombre_completo')
        ->and($plantilla->variables_manuales[0]['requerido'])->toBeTrue();
});

test('no se puede marcar como requerida una variable automática que no aparece en el docx de esta plantilla', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $plantilla = crearPlantillaConMarcador('folio_interno_obra');

    $this->actingAs($usuario)
        ->put(route('rh.plantillas.variables.update', $plantilla), [
            // curp es un dato conocido real, pero esta plantilla no lo usa.
            'variables' => [
                ['clave' => 'curp', 'requerido' => true],
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

test('una variable automática opcional (sin declarar) que resuelve vacía no bloquea puede_generar', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $colaborador = User::factory()->for(Colaborador::factory()->state(['curp' => null]), 'colaborador')->create();
    $ruta = 'plantillas/'.uniqid('curp-opcional', true).'.docx';
    Storage::disk('nas')->put($ruta, crearDocxParaVariables('CURP: {{curp}}'));
    $plantilla = DocumentTemplate::factory()->create(['path' => $ruta]);

    $respuesta = $this->actingAs($usuario)->postJson(route('rh.formatos.preview'), [
        'document_template_id' => $plantilla->id,
        'tipo_sujeto' => 'colaborador',
        'sujeto_id' => $colaborador->colaborador_id,
    ])->assertOk();

    expect($respuesta->json('puede_generar'))->toBeTrue()
        ->and($respuesta->json('faltantes_requeridos'))->toBe([]);
});

test('una variable automática marcada requerida bloquea puede_generar si el colaborador no tiene el dato', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $colaborador = User::factory()->for(Colaborador::factory()->state(['curp' => null]), 'colaborador')->create();
    $ruta = 'plantillas/'.uniqid('curp-requerido', true).'.docx';
    Storage::disk('nas')->put($ruta, crearDocxParaVariables('CURP: {{curp}}'));
    $plantilla = DocumentTemplate::factory()->create(['path' => $ruta]);
    $plantilla->update(['variables_manuales' => [
        ['clave' => 'curp', 'etiqueta' => null, 'descripcion' => null, 'tipo' => null, 'requerido' => true, 'valor_por_defecto' => null, 'opciones' => null],
    ]]);

    $respuesta = $this->actingAs($usuario)->postJson(route('rh.formatos.preview'), [
        'document_template_id' => $plantilla->id,
        'tipo_sujeto' => 'colaborador',
        'sujeto_id' => $colaborador->colaborador_id,
    ])->assertOk();

    expect($respuesta->json('puede_generar'))->toBeFalse()
        ->and($respuesta->json('faltantes_requeridos'))->toBe(['curp']);
});

test('una variable automática requerida con valor sí permite generar', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $colaborador = User::factory()->for(Colaborador::factory()->state(['curp' => 'XAXX010101HNEXXXA4']), 'colaborador')->create();
    $ruta = 'plantillas/'.uniqid('curp-con-valor', true).'.docx';
    Storage::disk('nas')->put($ruta, crearDocxParaVariables('CURP: {{curp}}'));
    $plantilla = DocumentTemplate::factory()->create(['path' => $ruta]);
    $plantilla->update(['variables_manuales' => [
        ['clave' => 'curp', 'etiqueta' => null, 'descripcion' => null, 'tipo' => null, 'requerido' => true, 'valor_por_defecto' => null, 'opciones' => null],
    ]]);

    $respuesta = $this->actingAs($usuario)->postJson(route('rh.formatos.preview'), [
        'document_template_id' => $plantilla->id,
        'tipo_sujeto' => 'colaborador',
        'sujeto_id' => $colaborador->colaborador_id,
    ])->assertOk();

    expect($respuesta->json('puede_generar'))->toBeTrue()
        ->and($respuesta->json('faltantes_requeridos'))->toBe([]);
});

test('varias variables requeridas faltantes (una automática y una manual) se reportan todas', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');
    $colaborador = User::factory()->for(Colaborador::factory()->state(['curp' => null]), 'colaborador')->create();
    $ruta = 'plantillas/'.uniqid('varias-requeridas', true).'.docx';
    Storage::disk('nas')->put($ruta, crearDocxParaVariables('CURP: {{curp}}, folio: {{folio_interno_obra}}'));
    $plantilla = DocumentTemplate::factory()->create(['path' => $ruta]);
    $plantilla->update(['variables_manuales' => [
        ['clave' => 'curp', 'etiqueta' => null, 'descripcion' => null, 'tipo' => null, 'requerido' => true, 'valor_por_defecto' => null, 'opciones' => null],
        ['clave' => 'folio_interno_obra', 'etiqueta' => 'Folio', 'descripcion' => null, 'tipo' => 'text', 'requerido' => true, 'valor_por_defecto' => null, 'opciones' => null],
    ]]);

    $respuesta = $this->actingAs($usuario)->postJson(route('rh.formatos.preview'), [
        'document_template_id' => $plantilla->id,
        'tipo_sujeto' => 'colaborador',
        'sujeto_id' => $colaborador->colaborador_id,
    ])->assertOk();

    expect($respuesta->json('puede_generar'))->toBeFalse()
        ->and($respuesta->json('faltantes_requeridos'))->toBe(['curp', 'folio_interno_obra']);
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

test('generación real: el DOCX resultante reemplaza todos los placeholders (automáticos y manual) y no deja ninguno literal', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    $puesto = Puesto::factory()->create(['nombre' => 'Analista de Nómina']);
    $colaborador = User::factory()->for(Colaborador::factory()->state([
        'name' => 'Juan',
        'apellidos' => 'Pérez López',
        'curp' => 'PEXJ800101HDFRRN05',
        'puesto_id' => $puesto->id,
        'fecha_ingreso' => '2024-03-15',
    ]), 'colaborador')->create();

    $ruta = 'plantillas/'.uniqid('e2e', true).'.docx';
    Storage::disk('nas')->put($ruta, crearDocxParaVariables(
        'Nombre: {{nombre_completo}} | CURP: {{curp}} | Puesto: {{puesto}} | Ingreso: {{fecha_ingreso}} | Campo manual: {{campo_manual}}'
    ));
    $plantilla = DocumentTemplate::factory()->create(['path' => $ruta]);
    $plantilla->update(['variables_manuales' => [
        ['clave' => 'campo_manual', 'etiqueta' => 'Campo manual', 'descripcion' => null, 'tipo' => 'text', 'requerido' => true, 'valor_por_defecto' => null, 'opciones' => null],
    ]]);

    $this->actingAs($usuario)->post(route('rh.formatos.store'), [
        'document_template_id' => $plantilla->id,
        'tipo_sujeto' => 'colaborador',
        'sujeto_id' => $colaborador->colaborador_id,
        'extra' => ['campo_manual' => 'Valor manual de prueba'],
    ])->assertSessionHasNoErrors();

    $documento = GeneratedDocument::where('document_template_id', $plantilla->id)->firstOrFail();
    $docxBytes = Storage::disk('nas')->get($documento->path);

    $rutaTemporal = sys_get_temp_dir().'/'.uniqid('e2e-verificacion', true).'.docx';
    file_put_contents($rutaTemporal, $docxBytes);

    // No basta verificar el JSON de previsualización: se abre el DOCX
    // resultante como lo que realmente es (un ZIP con XML adentro) y se
    // revisa el texto plano, igual que lo abriría Word.
    $zip = new ZipArchive;
    expect($zip->open($rutaTemporal))->toBeTrue();
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    unlink($rutaTemporal);

    expect($xml)->not->toBeFalse();
    $texto = strip_tags((string) $xml);

    expect($texto)->toContain('Juan Pérez López')
        ->and($texto)->toContain('PEXJ800101HDFRRN05')
        ->and($texto)->toContain('Analista de Nómina')
        ->and($texto)->toContain('15/03/2024')
        ->and($texto)->toContain('Valor manual de prueba')
        ->and($texto)->not->toContain('{{nombre_completo}}')
        ->and($texto)->not->toContain('{{curp}}')
        ->and($texto)->not->toContain('{{puesto}}')
        ->and($texto)->not->toContain('{{fecha_ingreso}}')
        ->and($texto)->not->toContain('{{campo_manual}}');
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
