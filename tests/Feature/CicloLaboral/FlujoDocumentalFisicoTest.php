<?php

use App\Enums\EstadoDocumento;
use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\DocumentoEvento;
use App\Models\EmployeeDocument;
use App\Models\TareaRh;
use App\Models\User;
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
    $this->colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $estructura['sucursal']->id]);
    clPlantilla('contrato_indeterminado', [
        'requiere_impresion' => true,
        'requiere_firma_fisica' => true,
        'requiere_huella' => true,
        'requiere_testigos' => true,
    ]);
});

test('el original físico recorre impresión → firma física + huella → envío → recepción → escaneo → archivo con auditoría', function () {
    Sanctum::actingAs($this->rh);
    $id = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/documentos-laborales", ['clave' => 'contrato_indeterminado'])
        ->assertCreated()
        ->assertJsonPath('data.estado', 'pendiente_impresion')
        ->json('data.id');

    expect(TareaRh::query()->where('tipo', TipoTarea::ImpresionPendiente->value)->whereNull('resuelta_en')->count())->toBe(1);
    $this->getJson('/api/v1/rh/documentos-laborales/pendientes')->assertOk()->assertJsonPath('data.imprimir', 1);

    $this->postJson("/api/v1/rh/documentos-laborales/{$id}/imprimir")->assertOk()->assertJsonPath('data.estado', 'pendiente_firma_fisica');

    // Requiere huella y testigos: sin ellos no se registra la firma física.
    $this->postJson("/api/v1/rh/documentos-laborales/{$id}/firma-fisica", ['huella_registrada' => false])->assertUnprocessable();
    $this->postJson("/api/v1/rh/documentos-laborales/{$id}/firma-fisica", ['huella_registrada' => true])->assertUnprocessable();

    $this->postJson("/api/v1/rh/documentos-laborales/{$id}/firma-fisica", [
        'huella_registrada' => true,
        'testigos' => [['nombre' => 'Testigo Uno', 'puesto' => 'Gerente']],
    ])->assertOk()->assertJsonPath('data.estado', 'firmado_fisicamente');

    $this->post("/api/v1/rh/documentos-laborales/{$id}/envio", [
        'paqueteria' => 'Estafeta',
        'numero_guia' => 'GU123456',
        'comprobante' => clArchivoPdf('guia.pdf'),
    ], ['Accept' => 'application/json'])->assertOk()
        ->assertJsonPath('data.estado', 'enviado_corporativo')
        ->assertJsonPath('data.original_fisico.numero_guia', 'GU123456')
        ->assertJsonPath('data.original_fisico.tiene_comprobante', true);

    $this->getJson('/api/v1/rh/documentos-laborales?etapa=recibir')->assertOk()->assertJsonCount(1, 'data');

    $this->postJson("/api/v1/rh/documentos-laborales/{$id}/recepcion")->assertOk()->assertJsonPath('data.estado', 'recibido_corporativo');

    $this->post("/api/v1/rh/documentos-laborales/{$id}/escaneo", ['archivo' => clArchivoPdf('firmado.pdf')], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.estado', 'escaneado');

    $this->postJson("/api/v1/rh/documentos-laborales/{$id}/archivar")->assertOk()->assertJsonPath('data.estado', 'archivado');

    // El escaneo final queda en el expediente como documento aprobado.
    $escaneo = EmployeeDocument::query()->where('colaborador_id', $this->colaborador->id)->where('origen', 'generado')->firstOrFail();
    expect($escaneo->status)->toBe(EstadoDocumento::Aprobado)
        ->and($escaneo->reviewed_by)->toBe($this->rh->id);

    // Bitácora: quién, qué, cuándo.
    $acciones = DocumentoEvento::query()->where('generated_document_id', $id)->pluck('accion')->all();
    expect($acciones)->toContain('generado', 'impreso', 'firmado_fisicamente', 'enviado_corporativo', 'recibido_corporativo', 'escaneado', 'archivado');
    expect(DocumentoEvento::query()->where('generated_document_id', $id)->where('accion', 'impreso')->value('user_id'))->toBe($this->rh->id);

    // Ningún pendiente físico queda abierto al archivar.
    expect(TareaRh::query()->where('relacionado_id', $id)->whereNull('resuelta_en')->count())->toBe(0);
});

test('no se puede saltar etapas del flujo (p. ej. recibir algo que no se ha enviado)', function () {
    Sanctum::actingAs($this->rh);
    $id = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/documentos-laborales", ['clave' => 'contrato_indeterminado'])->json('data.id');

    $this->postJson("/api/v1/rh/documentos-laborales/{$id}/recepcion")->assertUnprocessable();
    $this->postJson("/api/v1/rh/documentos-laborales/{$id}/archivar")->assertUnprocessable();
});

test('un colaborador no puede operar el flujo físico de documentos', function () {
    Sanctum::actingAs($this->rh);
    $id = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/documentos-laborales", ['clave' => 'contrato_indeterminado'])->json('data.id');

    $titular = User::factory()->create(['colaborador_id' => $this->colaborador->id]);
    $titular->assignRole('colaborador');
    Sanctum::actingAs($titular);

    $this->postJson("/api/v1/rh/documentos-laborales/{$id}/imprimir")->assertForbidden();
    // Tampoco puede "firmar digitalmente" un documento que no está pendiente de su firma.
    $this->postJson("/api/v1/colaborador/documentos-laborales/{$id}/firmar", ['acepto' => true])->assertForbidden();
});
