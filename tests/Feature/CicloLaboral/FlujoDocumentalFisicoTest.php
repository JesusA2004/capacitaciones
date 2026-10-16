<?php

use App\Enums\EstadoFlujoDocumento;
use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\DocumentoEvento;
use App\Models\EmployeeDocument;
use App\Models\GeneratedDocument;
use App\Models\TareaRh;
use App\Models\User;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| Flujo documental físico (regla nueva 2026-10-16): el documento que se
| imprime y firma en PAPEL ya NO se vuelve a escanear ni subir. El sistema
| genera el PDF, RH lo imprime, se firma físicamente, el original se
| resguarda en papel y en PEOPLE solo se marca «firmado físicamente» → FIN.
| Los estados legacy (escaneado/archivado) siguen siendo legibles.
*/

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

test('el original físico recorre impresión → firma física + huella y queda FINAL en «firmado físicamente», sin exigir escaneo, con auditoría', function () {
    Sanctum::actingAs($this->rh);
    $id = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/documentos-laborales", ['clave' => 'contrato_indeterminado'])
        ->assertCreated()
        ->assertJsonPath('data.estado', 'pendiente_impresion')
        ->json('data.id');

    expect(TareaRh::query()->where('tipo', TipoTarea::ImpresionPendiente->value)->whereNull('resuelta_en')->count())->toBe(1);
    // Ya no hay etapa de escaneo/envío/recepción en el conteo de pendientes.
    $this->getJson('/api/v1/rh/documentos-laborales/pendientes')->assertOk()
        ->assertJsonPath('data.imprimir', 1)
        ->assertJsonMissingPath('data.escanear')
        ->assertJsonMissingPath('data.enviar')
        ->assertJsonMissingPath('data.recibir');

    $this->postJson("/api/v1/rh/documentos-laborales/{$id}/imprimir")->assertOk()->assertJsonPath('data.estado', 'pendiente_firma_fisica');

    // Requiere huella y testigos: sin ellos no se registra la firma física.
    $this->postJson("/api/v1/rh/documentos-laborales/{$id}/firma-fisica", ['huella_registrada' => false])->assertUnprocessable();
    $this->postJson("/api/v1/rh/documentos-laborales/{$id}/firma-fisica", ['huella_registrada' => true])->assertUnprocessable();

    // Marcar como firmado físicamente: es el ESTADO FINAL del documento en papel.
    $this->postJson("/api/v1/rh/documentos-laborales/{$id}/firma-fisica", [
        'huella_registrada' => true,
        'testigos' => [['nombre' => 'Testigo Uno', 'puesto' => 'Gerente']],
        'observaciones' => 'Firmado ante testigo; original resguardado en papel.',
    ])->assertOk()->assertJsonPath('data.estado', 'firmado_fisicamente');

    // Nunca se abrió una tarea de escaneo/envío/recepción (ya no existen en el flujo nuevo).
    expect(TareaRh::query()->whereIn('tipo', [
        TipoTarea::EscaneoPendiente->value,
        TipoTarea::EnvioOriginalPendiente->value,
        TipoTarea::RecepcionOriginalPendiente->value,
    ])->count())->toBe(0);

    // Ningún pendiente físico queda abierto al firmar: el documento ya está concluido.
    expect(TareaRh::query()->where('relacionado_id', $id)->whereNull('resuelta_en')->count())->toBe(0);

    // El original firmado se resguarda en papel: NO se crea un escaneo en el expediente.
    expect(EmployeeDocument::query()->where('colaborador_id', $this->colaborador->id)->where('origen', 'generado')->count())->toBe(0);

    // Bitácora del evento «firmado físicamente»: quién y cuándo.
    $acciones = DocumentoEvento::query()->where('generated_document_id', $id)->pluck('accion')->all();
    expect($acciones)->toContain('generado', 'impreso', 'firmado_fisicamente')
        ->and($acciones)->not->toContain('escaneado', 'enviado_corporativo', 'recibido_corporativo');
    expect(DocumentoEvento::query()->where('generated_document_id', $id)->where('accion', 'firmado_fisicamente')->value('user_id'))->toBe($this->rh->id);

    // Y el seguimiento físico guarda la huella y el testigo recabados.
    $documento = GeneratedDocument::query()->with('seguimientoFisico')->findOrFail($id);
    expect($documento->seguimientoFisico?->huella_registrada)->toBeTrue()
        ->and($documento->seguimientoFisico?->firma_fisica_registrada_por)->toBe($this->rh->id)
        ->and($documento->seguimientoFisico?->testigos)->not->toBeEmpty();
});

test('no se puede saltar etapas del flujo (p. ej. recibir/archivar algo que no ha pasado por firma física)', function () {
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

test('un documento legacy ya recibido en corporativo continúa su cadena antigua (escaneo → archivado) y sigue siendo legible', function () {
    Sanctum::actingAs($this->rh);
    $id = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/documentos-laborales", ['clave' => 'contrato_indeterminado'])->json('data.id');

    // Expediente HISTÓRICO: ya venía recorriendo el flujo anterior y está
    // recibido en corporativo (se fija el estado directo, como un registro
    // migrado). La regla nueva prohíbe INICIAR el flujo legacy desde
    // FirmadoFisicamente/Impreso (ver el test de bloqueo más abajo), pero un
    // histórico ya recibido SÍ puede continuar su cadena: recepción → escaneo.
    $flujo = app(FlujoDocumentalService::class);
    $documento = GeneratedDocument::query()->findOrFail($id);
    $documento->update(['estado_flujo' => EstadoFlujoDocumento::RecibidoCorporativo]);

    $flujo->registrarEscaneo($documento->refresh(), $this->rh, clArchivoPdf('firmado.pdf'));

    expect($documento->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::Escaneado)
        ->and($documento->signed_document_id)->not->toBeNull();

    // El registro legacy se lee sin problema.
    $this->getJson("/api/v1/rh/documentos-laborales/{$id}")->assertOk()->assertJsonPath('data.estado', 'escaneado');

    // Y un original legacy escaneado todavía puede archivarse para cerrarlo.
    $flujo->archivar($documento->refresh(), $this->rh);
    $this->getJson("/api/v1/rh/documentos-laborales/{$id}")->assertOk()->assertJsonPath('data.estado', 'archivado');
});
