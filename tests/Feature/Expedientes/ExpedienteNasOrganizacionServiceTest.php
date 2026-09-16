<?php

use App\Enums\EstadoDocumento;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Expedientes\ExpedienteNasOrganizacionService;
use Illuminate\Support\Facades\Storage;

function colaboradorLegacy(): User
{
    $empresa = Empresa::factory()->create(['nombre' => 'MR LANA']);
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id, 'nombre' => 'Cuernavaca']);

    return User::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'numero_empleado' => '00125',
        'name' => 'Juan',
        'apellidos' => 'Perez',
    ]);
}

beforeEach(function () {
    Storage::fake('nas');
    $this->servicio = app(ExpedienteNasOrganizacionService::class);
});

test('un documento legacy se mueve a la ruta legible, BD y NAS quedan consistentes', function () {
    $colaborador = colaboradorLegacy();
    $tipo = DocumentType::factory()->create(['nombre' => 'Acta de nacimiento']);
    $contenido = 'contenido-real-del-acta';

    Storage::disk('nas')->put('expedientes/6/abc123.pdf', $contenido);

    $documento = EmployeeDocument::factory()->create([
        'user_id' => $colaborador->id,
        'document_type_id' => $tipo->id,
        'disk' => 'nas',
        'path' => 'expedientes/6/abc123.pdf',
        'stored_name' => 'abc123.pdf',
        'version' => 1,
        'hash' => hash('sha256', $contenido),
        'status' => EstadoDocumento::Aprobado->value,
    ]);

    $plan = $this->servicio->planificar();
    expect($plan->firstWhere('employee_document_id', $documento->id)['accion'])->toBe('mover');

    $manifiesto = $this->servicio->ejecutar($plan, aplicar: true);
    $fila = $manifiesto->firstWhere('employee_document_id', $documento->id);

    expect($fila['resultado'])->toBe('ok');

    $documento->refresh();
    expect($documento->path)->toBe('expedientes/MR LANA/Cuernavaca/00125 - Juan Perez/Acta de nacimiento - v1.pdf')
        ->and($documento->stored_name)->toBe(basename($documento->path));

    Storage::disk('nas')->assertExists($documento->path);
    Storage::disk('nas')->assertMissing('expedientes/6/abc123.pdf');
    Storage::disk('nas')->assertExists($documento->path) && expect(Storage::disk('nas')->get($documento->path))->toBe($contenido);

    expect($colaborador->fresh()->expediente_storage_path)->toBe('expedientes/MR LANA/Cuernavaca/00125 - Juan Perez');
});

test('un dry run nunca toca disco ni BD ni asigna expediente_storage_path', function () {
    $colaborador = colaboradorLegacy();
    $tipo = DocumentType::factory()->create(['nombre' => 'CURP']);
    $contenido = 'contenido-curp';

    Storage::disk('nas')->put('expedientes/7/xyz.pdf', $contenido);

    $documento = EmployeeDocument::factory()->create([
        'user_id' => $colaborador->id,
        'document_type_id' => $tipo->id,
        'disk' => 'nas',
        'path' => 'expedientes/7/xyz.pdf',
        'version' => 1,
        'hash' => hash('sha256', $contenido),
    ]);

    $plan = $this->servicio->planificar();
    $manifiesto = $this->servicio->ejecutar($plan, aplicar: false);

    $fila = $manifiesto->firstWhere('employee_document_id', $documento->id);
    expect($fila['resultado'])->toBe('pendiente_de_aplicar');

    Storage::disk('nas')->assertExists('expedientes/7/xyz.pdf');
    Storage::disk('nas')->assertMissing($fila['new_path']);
    expect($documento->fresh()->path)->toBe('expedientes/7/xyz.pdf')
        ->and($colaborador->fresh()->expediente_storage_path)->toBeNull();
});

test('duplicado (mismo hash en destino) adopta el destino y limpia el legacy al aplicar', function () {
    $colaborador = colaboradorLegacy();
    $tipo = DocumentType::factory()->create(['nombre' => 'RFC']);
    $contenido = 'contenido-rfc-identico';

    $rutaNueva = 'expedientes/MR LANA/Cuernavaca/00125 - Juan Perez/RFC - v1.pdf';
    Storage::disk('nas')->put($rutaNueva, $contenido);
    Storage::disk('nas')->put('expedientes/8/dup.pdf', $contenido);

    $documento = EmployeeDocument::factory()->create([
        'user_id' => $colaborador->id,
        'document_type_id' => $tipo->id,
        'disk' => 'nas',
        'path' => 'expedientes/8/dup.pdf',
        'version' => 1,
        'hash' => hash('sha256', $contenido),
    ]);

    $plan = $this->servicio->planificar();
    expect($plan->firstWhere('employee_document_id', $documento->id)['accion'])->toBe('duplicado');

    $manifiesto = $this->servicio->ejecutar($plan, aplicar: true);
    $fila = $manifiesto->firstWhere('employee_document_id', $documento->id);

    expect($fila['resultado'])->toBe('duplicado_resuelto');

    $documento->refresh();
    expect($documento->path)->toBe($rutaNueva);

    Storage::disk('nas')->assertExists($rutaNueva);
    Storage::disk('nas')->assertMissing('expedientes/8/dup.pdf');
});

test('conflicto (distinto hash en destino) no toca nada, ni en dry run ni al aplicar', function () {
    $colaborador = colaboradorLegacy();
    $tipo = DocumentType::factory()->create(['nombre' => 'NSS']);

    $rutaNueva = 'expedientes/MR LANA/Cuernavaca/00125 - Juan Perez/NSS - v1.pdf';
    Storage::disk('nas')->put($rutaNueva, 'contenido-diferente-en-destino');
    Storage::disk('nas')->put('expedientes/9/legacy.pdf', 'contenido-legacy-original');

    $documento = EmployeeDocument::factory()->create([
        'user_id' => $colaborador->id,
        'document_type_id' => $tipo->id,
        'disk' => 'nas',
        'path' => 'expedientes/9/legacy.pdf',
        'version' => 1,
        'hash' => hash('sha256', 'contenido-legacy-original'),
    ]);

    $plan = $this->servicio->planificar();
    $manifiesto = $this->servicio->ejecutar($plan, aplicar: true);
    $fila = $manifiesto->firstWhere('employee_document_id', $documento->id);

    expect($fila['resultado'])->toBe('conflicto');
    expect($documento->fresh()->path)->toBe('expedientes/9/legacy.pdf');

    Storage::disk('nas')->assertExists('expedientes/9/legacy.pdf');
    expect(Storage::disk('nas')->get($rutaNueva))->toBe('contenido-diferente-en-destino');
});

test('las carpetas legacy numericas vacias solo se borran cuando de verdad estan vacias', function () {
    $colaborador = colaboradorLegacy();
    $tipo = DocumentType::factory()->create(['nombre' => 'Contrato laboral']);
    $contenido = 'contenido-contrato';

    Storage::disk('nas')->put('expedientes/10/uuid.pdf', $contenido);
    Storage::disk('nas')->put('expedientes/11/sigue-aqui.pdf', 'huerfano-real');

    $documento = EmployeeDocument::factory()->create([
        'user_id' => $colaborador->id,
        'document_type_id' => $tipo->id,
        'disk' => 'nas',
        'path' => 'expedientes/10/uuid.pdf',
        'version' => 1,
        'hash' => hash('sha256', $contenido),
    ]);

    $plan = $this->servicio->planificar();
    $this->servicio->ejecutar($plan, aplicar: true);

    $vacias = $this->servicio->carpetasLegacyVacias();
    expect($vacias)->toContain('expedientes/10')
        ->and($vacias)->not->toContain('expedientes/11');

    $podadas = $this->servicio->podarCarpetasLegaciesVacias();

    expect($podadas)->toBe(['expedientes/10']);
    Storage::disk('nas')->assertMissing('expedientes/10/uuid.pdf');
    Storage::disk('nas')->assertExists('expedientes/11/sigue-aqui.pdf');
});

test('huerfanos() reporta pero nunca borra archivos sin fila en BD', function () {
    Storage::disk('nas')->put('expedientes/12/misterioso.pdf', 'nadie-sabe-que-es-esto');

    $huerfanos = $this->servicio->huerfanos();

    expect($huerfanos)->toContain('expedientes/12/misterioso.pdf');
    Storage::disk('nas')->assertExists('expedientes/12/misterioso.pdf');
});

test('rollback revierte un manifiesto aplicado: BD y NAS regresan al estado legacy', function () {
    $colaborador = colaboradorLegacy();
    $tipo = DocumentType::factory()->create(['nombre' => 'Acta de nacimiento']);
    $contenido = 'contenido-para-rollback';

    Storage::disk('nas')->put('expedientes/13/original.pdf', $contenido);

    $documento = EmployeeDocument::factory()->create([
        'user_id' => $colaborador->id,
        'document_type_id' => $tipo->id,
        'disk' => 'nas',
        'path' => 'expedientes/13/original.pdf',
        'stored_name' => 'original.pdf',
        'version' => 1,
        'hash' => hash('sha256', $contenido),
    ]);

    $plan = $this->servicio->planificar();
    $manifiesto = $this->servicio->ejecutar($plan, aplicar: true);
    $rutaManifiesto = $this->servicio->escribirManifiesto($manifiesto);

    $documento->refresh();
    $rutaNueva = $documento->path;
    expect($rutaNueva)->not->toBe('expedientes/13/original.pdf');
    Storage::disk('nas')->assertExists($rutaNueva);
    Storage::disk('nas')->assertMissing('expedientes/13/original.pdf');

    $resultados = $this->servicio->rollback($rutaManifiesto);

    expect($resultados)->toHaveCount(1)
        ->and($resultados[0]['rollback'])->toBe('ok');

    $documento->refresh();
    expect($documento->path)->toBe('expedientes/13/original.pdf')
        ->and($documento->stored_name)->toBe('original.pdf');

    Storage::disk('nas')->assertExists('expedientes/13/original.pdf');
    Storage::disk('nas')->assertMissing($rutaNueva);
    expect(Storage::disk('nas')->get('expedientes/13/original.pdf'))->toBe($contenido);
});
