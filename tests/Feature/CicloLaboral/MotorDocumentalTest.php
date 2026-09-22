<?php

use App\Enums\EstadoDocumento;
use App\Enums\EstadoFlujoDocumento;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Expedientes\ExpedienteService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $estructura = clEstructura();
    $this->colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'sueldo_mensual' => 15000,
        'numero_empleado' => 'EMP-0100',
        'name' => 'Ana',
        'apellidos' => 'Ruiz',
    ]);
});

test('el documento generado guarda un snapshot: cambiar el sueldo después no altera el contrato histórico', function () {
    Sanctum::actingAs($this->rh);
    clPlantilla('contrato_confidencialidad');

    $primero = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/documentos-laborales", ['clave' => 'contrato_confidencialidad'])
        ->assertCreated()
        ->json('data');

    expect($primero['payload']['sueldo_mensual'])->toBe('$15,000.00')
        ->and($primero['checksum'])->toHaveLength(64)
        ->and($primero['version_plantilla'])->toBe(1)
        ->and($primero['estado'])->toBe(EstadoFlujoDocumento::Generado->value);

    $this->colaborador->update(['sueldo_mensual' => 18000]);

    $documento = GeneratedDocument::query()->findOrFail($primero['id']);
    expect($documento->payload['sueldo_mensual'])->toBe('$15,000.00');

    // El PDF vive en la carpeta de su categoría dentro del expediente (NAS), nunca en BD.
    expect($documento->path)->toContain('/Contratos/')
        ->and(hash('sha256', (string) Storage::disk('nas')->get($documento->path)))->toBe($documento->checksum);

    $segundo = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/documentos-laborales", ['clave' => 'contrato_confidencialidad'])->json('data');
    expect($segundo['payload']['sueldo_mensual'])->toBe('$18,000.00');
});

test('sin plantilla activa el sistema no inventa el formato: responde 422 con aviso', function () {
    Sanctum::actingAs($this->rh);

    $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/documentos-laborales", ['clave' => 'contrato_no_competencia'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('plantilla');
});

test('publicar una nueva versión de plantilla desactiva la anterior y conserva el historial', function () {
    Sanctum::actingAs($this->rh);

    foreach (['<p>v1 {{nombre_completo}}</p>', '<p>v2 {{nombre_completo}}</p>'] as $html) {
        $this->postJson('/api/v1/rh/plantillas-documentales', [
            'clave' => 'contrato_indeterminado',
            'nombre' => 'Contrato indeterminado',
            'motor' => 'html',
            'contenido_html' => $html,
            'requiere_firma_digital' => true,
            'requiere_impresion' => true,
            'requiere_firma_fisica' => true,
            'requiere_huella' => true,
        ])->assertCreated();
    }

    $versiones = $this->getJson('/api/v1/rh/plantillas-documentales?clave=contrato_indeterminado')->assertOk()->json('data');

    expect($versiones)->toHaveCount(2)
        ->and($versiones[0]['version'])->toBe(2)
        ->and($versiones[0]['activo'])->toBeTrue()
        ->and($versiones[1]['activo'])->toBeFalse()
        ->and($versiones[0]['requiere_huella'])->toBeTrue();
});

test('el expediente solo está completo cuando todos los obligatorios están APROBADOS, no solo cargados', function () {
    $obligatorios = DocumentType::factory()->count(2)->create(['requerido' => true, 'activo' => true]);
    DocumentType::factory()->create(['requerido' => false, 'activo' => true]);

    EmployeeDocument::factory()->create(['colaborador_id' => $this->colaborador->id, 'document_type_id' => $obligatorios[0]->id, 'status' => EstadoDocumento::Aprobado->value]);
    EmployeeDocument::factory()->create(['colaborador_id' => $this->colaborador->id, 'document_type_id' => $obligatorios[1]->id, 'status' => EstadoDocumento::EnRevision->value]);

    $estado = app(ExpedienteService::class)->estadoDocumental($this->colaborador);

    expect($estado['requeridos'])->toBe(2)
        ->and($estado['entregados'])->toBe(2)
        ->and($estado['aprobados'])->toBe(1)
        ->and($estado['faltantes'])->toBe(0)
        ->and($estado['porcentaje'])->toBe(50.0)
        ->and($estado['completo'])->toBeFalse();

    EmployeeDocument::query()->where('document_type_id', $obligatorios[1]->id)->update(['status' => EstadoDocumento::Rechazado->value]);
    $estado = (new ExpedienteService)->estadoDocumental($this->colaborador);

    expect($estado['faltantes'])->toBe(1)->and($estado['rechazados'])->toBe(1)->and($estado['completo'])->toBeFalse();
});

test('descargas privadas: el titular descarga su documento, otro colaborador recibe 403 (sin IDOR)', function () {
    Sanctum::actingAs($this->rh);
    clPlantilla('contrato_confidencialidad');
    $id = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/documentos-laborales", ['clave' => 'contrato_confidencialidad'])->json('data.id');

    $titular = User::factory()->create(['colaborador_id' => $this->colaborador->id]);
    $titular->assignRole('colaborador');
    Sanctum::actingAs($titular);

    $this->get("/api/v1/colaborador/documentos-laborales/{$id}/descargar")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    Sanctum::actingAs(clUsuario('colaborador'));
    $this->get("/api/v1/colaborador/documentos-laborales/{$id}/descargar")->assertForbidden();
    $this->getJson("/api/v1/rh/documentos-laborales/{$id}")->assertForbidden();

    // La respuesta JSON nunca expone disco ni ruta física del NAS.
    Sanctum::actingAs($this->rh);
    $json = $this->getJson("/api/v1/rh/documentos-laborales/{$id}")->assertOk()->getContent();
    expect($json)->not->toContain('"path"')->not->toContain('"disk"')->not->toContain('expedientes/');
});
