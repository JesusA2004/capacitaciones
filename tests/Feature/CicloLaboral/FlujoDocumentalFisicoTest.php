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
use Illuminate\Validation\ValidationException;
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

test('registrarEnvio exige la firma física primero: desde «Impreso» se rechaza aun con el flujo legacy habilitado (P1-8)', function () {
    // Flujo legacy habilitado para aislar el cambio del ORIGEN del envío (que
    // ahora solo parte de FirmadoFisicamente, nunca de Impreso).
    config(['features.documentos_flujo_legacy' => true]);
    // Documento que solo se imprime (sin firma física automática): queda en «Impreso».
    clPlantilla('carta_solo_impresion', ['requiere_impresion' => true, 'requiere_firma_fisica' => false]);

    Sanctum::actingAs($this->rh);
    $id = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/documentos-laborales", ['clave' => 'carta_solo_impresion'])->json('data.id');

    $flujo = app(FlujoDocumentalService::class);
    $documento = GeneratedDocument::query()->findOrFail($id);
    $flujo->marcarImpreso($documento, $this->rh);
    expect($documento->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::Impreso);

    // Desde «Impreso» el envío se rechaza: falta registrar la firma física.
    expect(fn () => $flujo->registrarEnvio($documento->refresh(), $this->rh, ['paqueteria' => 'DHL', 'numero_guia' => 'X1']))
        ->toThrow(ValidationException::class);
    expect($documento->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::Impreso);

    // Registrada la firma física (→ FirmadoFisicamente), el envío ya procede.
    $flujo->registrarFirmaFisica($documento->refresh(), $this->rh, []);
    expect($documento->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::FirmadoFisicamente);
    $flujo->registrarEnvio($documento->refresh(), $this->rh, ['paqueteria' => 'DHL', 'numero_guia' => 'X1']);
    expect($documento->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::EnviadoCorporativo);
});

test('los pasos legacy (envío/escaneo) se rechazan sobre documentos NUEVOS con la bandera apagada (P1-8)', function () {
    Sanctum::actingAs($this->rh);
    $id = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/documentos-laborales", ['clave' => 'contrato_indeterminado'])->json('data.id');

    $flujo = app(FlujoDocumentalService::class);
    $documento = GeneratedDocument::query()->findOrFail($id);
    $flujo->marcarImpreso($documento, $this->rh);
    $flujo->registrarFirmaFisica($documento->refresh(), $this->rh, ['huella_registrada' => true, 'testigos' => [['nombre' => 'Testigo Uno', 'puesto' => 'Gerente']]]);
    expect($documento->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::FirmadoFisicamente);

    // Firmado físicamente = CONCLUIDO: el envío y el escaneo del flujo anterior
    // no proceden (la bandera documentos_flujo_legacy está apagada por defecto).
    expect(fn () => $flujo->registrarEnvio($documento->refresh(), $this->rh, ['paqueteria' => 'DHL', 'numero_guia' => 'X1']))
        ->toThrow(ValidationException::class);
    expect(fn () => $flujo->registrarEscaneo($documento->refresh(), $this->rh, clArchivoPdf('x.pdf')))
        ->toThrow(ValidationException::class);
    expect($documento->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::FirmadoFisicamente);
});

test('un documento firmado físicamente ya no puede cancelarse; uno aún no concluido sí (P1-7)', function () {
    Sanctum::actingAs($this->rh);
    $flujo = app(FlujoDocumentalService::class);

    // Aún en pendiente_impresion (no concluido): se puede cancelar.
    $idCancelable = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/documentos-laborales", ['clave' => 'contrato_indeterminado'])->json('data.id');
    $cancelable = GeneratedDocument::query()->findOrFail($idCancelable);
    $flujo->cancelar($cancelable, $this->rh, 'Error de captura.');
    expect($cancelable->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::Cancelado);

    // Firmado físicamente (su original quedó resguardado): cancelar se rechaza.
    $id = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/documentos-laborales", ['clave' => 'contrato_indeterminado'])->json('data.id');
    $firmado = GeneratedDocument::query()->findOrFail($id);
    $flujo->marcarImpreso($firmado, $this->rh);
    $flujo->registrarFirmaFisica($firmado->refresh(), $this->rh, ['huella_registrada' => true, 'testigos' => [['nombre' => 'Testigo Uno', 'puesto' => 'Gerente']]]);
    expect($firmado->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::FirmadoFisicamente);

    expect(fn () => $flujo->cancelar($firmado->refresh(), $this->rh, 'Ya no aplica.'))
        ->toThrow(ValidationException::class);
    expect($firmado->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::FirmadoFisicamente);
});

test('un documento legacy en estado Escaneado/Archivado sigue siendo legible (solo lectura)', function () {
    Sanctum::actingAs($this->rh);
    $id = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/documentos-laborales", ['clave' => 'contrato_indeterminado'])->json('data.id');

    // Se recorre a mano el flujo ANTERIOR (los métodos se conservan para compat):
    // firma física → escaneo → archivo. Representa un expediente ya existente.
    // El escaneo es compatibilidad legacy: requiere habilitar la bandera (el
    // flujo nuevo concluye en la firma física).
    config(['features.documentos_flujo_legacy' => true]);
    $flujo = app(FlujoDocumentalService::class);
    $documento = GeneratedDocument::query()->findOrFail($id);
    $flujo->marcarImpreso($documento, $this->rh);
    $flujo->registrarFirmaFisica($documento->refresh(), $this->rh, ['huella_registrada' => true, 'testigos' => [['nombre' => 'Testigo Uno', 'puesto' => 'Gerente']]]);
    $flujo->registrarEscaneo($documento->refresh(), $this->rh, clArchivoPdf('firmado.pdf'));

    expect($documento->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::Escaneado)
        ->and($documento->signed_document_id)->not->toBeNull();

    // El registro legacy se lee sin problema.
    $this->getJson("/api/v1/rh/documentos-laborales/{$id}")->assertOk()->assertJsonPath('data.estado', 'escaneado');

    // Y un original legacy escaneado todavía puede archivarse para cerrarlo.
    $flujo->archivar($documento->refresh(), $this->rh);
    $this->getJson("/api/v1/rh/documentos-laborales/{$id}")->assertOk()->assertJsonPath('data.estado', 'archivado');
});
