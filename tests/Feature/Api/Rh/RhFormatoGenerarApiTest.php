<?php

use App\Models\Colaborador;
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

function crearDocxParaApiMovil(string $texto): string
{
    $phpWord = new PhpWord;
    $seccion = $phpWord->addSection();
    $seccion->addText($texto);

    $ruta = sys_get_temp_dir().'/'.uniqid('plantilla_api_movil', true).'.docx';
    $phpWord->save($ruta, 'Word2007');

    $contenido = file_get_contents($ruta);
    unlink($ruta);

    return $contenido !== false ? $contenido : '';
}

function tokenRhAdmin(): string
{
    $usuario = User::factory()->create();
    $usuario->assignRole('rh_admin');

    return $usuario->createToken('test')->plainTextToken;
}

test('preparar regresa los datos resueltos, faltantes y puede_generar', function () {
    $colaborador = User::factory()->for(Colaborador::factory()->state(['name' => 'Ana', 'apellidos' => 'García']), 'colaborador')->create();
    $ruta = 'plantillas/'.uniqid('api', true).'.docx';
    Storage::disk('nas')->put($ruta, crearDocxParaApiMovil('Hola {{nombre_completo}}, folio: {{folio_obra}}.'));
    $plantilla = DocumentTemplate::factory()->create(['path' => $ruta]);
    $plantilla->update(['variables_manuales' => [
        ['clave' => 'folio_obra', 'etiqueta' => 'Folio de obra', 'tipo' => 'text', 'requerido' => true, 'descripcion' => null, 'valor_por_defecto' => null, 'opciones' => null],
    ]]);

    $respuesta = $this->withHeaders(['Authorization' => 'Bearer '.tokenRhAdmin()])
        ->postJson("/api/v1/rh/formatos/{$plantilla->id}/preparar", [
            'tipo_sujeto' => 'colaborador',
            'sujeto_id' => $colaborador->colaborador_id,
        ])
        ->assertOk();

    expect($respuesta->json('data.puede_generar'))->toBeFalse()
        ->and($respuesta->json('data.sujeto.nombre'))->toBe('Ana García')
        ->and(collect($respuesta->json('data.manuales'))->pluck('clave')->all())->toBe(['folio_obra'])
        ->and($respuesta->json('data.output_available.docx'))->toBeTrue();
});

test('generar crea el documento cuando la variable manual requerida llega en extra', function () {
    $colaborador = User::factory()->for(Colaborador::factory(), 'colaborador')->create();
    $ruta = 'plantillas/'.uniqid('api', true).'.docx';
    Storage::disk('nas')->put($ruta, crearDocxParaApiMovil('Hola {{nombre_completo}}, folio: {{folio_obra}}.'));
    $plantilla = DocumentTemplate::factory()->create(['path' => $ruta]);
    $plantilla->update(['variables_manuales' => [
        ['clave' => 'folio_obra', 'etiqueta' => 'Folio de obra', 'tipo' => 'text', 'requerido' => true, 'descripcion' => null, 'valor_por_defecto' => null, 'opciones' => null],
    ]]);

    $token = tokenRhAdmin();

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson("/api/v1/rh/formatos/{$plantilla->id}/generar", [
            'tipo_sujeto' => 'colaborador',
            'sujeto_id' => $colaborador->colaborador_id,
        ])
        ->assertStatus(422);

    expect(GeneratedDocument::where('document_template_id', $plantilla->id)->exists())->toBeFalse();

    $respuesta = $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson("/api/v1/rh/formatos/{$plantilla->id}/generar", [
            'tipo_sujeto' => 'colaborador',
            'sujeto_id' => $colaborador->colaborador_id,
            'extra' => ['folio_obra' => 'OBRA-42'],
        ])
        ->assertOk();

    $documento = GeneratedDocument::where('document_template_id', $plantilla->id)->firstOrFail();
    expect($respuesta->json('data.documento_generado_id'))->toBe($documento->id)
        ->and($respuesta->json('data.acciones_permitidas'))->toContain('download');
});

test('extra rechaza una clave que no es ni conocida ni una manual declarada', function () {
    $colaborador = User::factory()->for(Colaborador::factory(), 'colaborador')->create();
    $ruta = 'plantillas/'.uniqid('api', true).'.docx';
    Storage::disk('nas')->put($ruta, crearDocxParaApiMovil('Hola {{nombre_completo}}'));
    $plantilla = DocumentTemplate::factory()->create(['path' => $ruta]);

    $this->withHeaders(['Authorization' => 'Bearer '.tokenRhAdmin()])
        ->postJson("/api/v1/rh/formatos/{$plantilla->id}/preparar", [
            'tipo_sujeto' => 'colaborador',
            'sujeto_id' => $colaborador->colaborador_id,
            'extra' => ['clave_arbitraria' => 'x'],
        ])
        ->assertStatus(422);
});

test('el alcance por sucursal bloquea generar para un colaborador fuera de esa sucursal', function () {
    // Hoy `plantillas.generar` solo lo tienen rh_admin/rh_auxiliar (alcance
    // global por diseño, ver AlcanceOrganizacionalService::ROLES_ALCANCE_GLOBAL),
    // así que ningún rol real de hoy combina ese permiso con alcance de
    // sucursal. Esta prueba verifica que el guardado por alcance en
    // FormatoController::resolverSujetoAutorizado() funciona de todas
    // formas a nivel de código (defensa en profundidad si el modelo de
    // permisos cambia), otorgando el permiso directamente a un rol
    // restringido por sucursal.
    $sucursalA = Sucursal::factory()->create();
    $sucursalB = Sucursal::factory()->create();

    $gerente = User::factory()->for(Colaborador::factory()->state(['sucursal_principal_id' => $sucursalA->id]), 'colaborador')->create();
    $gerente->assignRole('gerente_sucursal');
    $gerente->givePermissionTo('plantillas.generar');

    $colaboradorB = User::factory()->for(Colaborador::factory()->state(['sucursal_principal_id' => $sucursalB->id]), 'colaborador')->create();

    $ruta = 'plantillas/'.uniqid('api', true).'.docx';
    Storage::disk('nas')->put($ruta, crearDocxParaApiMovil('Hola {{nombre_completo}}'));
    $plantilla = DocumentTemplate::factory()->create(['path' => $ruta]);

    $this->withHeaders(['Authorization' => 'Bearer '.$gerente->createToken('test')->plainTextToken])
        ->postJson("/api/v1/rh/formatos/{$plantilla->id}/preparar", [
            'tipo_sujeto' => 'colaborador',
            'sujeto_id' => $colaboradorB->colaborador_id,
        ])
        ->assertStatus(404);
});

test('un colaborador sin permiso de generar no puede llamar a preparar/generar', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');
    $colaborador = User::factory()->for(Colaborador::factory(), 'colaborador')->create();

    $ruta = 'plantillas/'.uniqid('api', true).'.docx';
    Storage::disk('nas')->put($ruta, crearDocxParaApiMovil('Hola {{nombre_completo}}'));
    $plantilla = DocumentTemplate::factory()->create(['path' => $ruta]);

    $this->withHeaders(['Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken])
        ->postJson("/api/v1/rh/formatos/{$plantilla->id}/preparar", [
            'tipo_sujeto' => 'colaborador',
            'sujeto_id' => $colaborador->colaborador_id,
        ])
        ->assertForbidden();
});
