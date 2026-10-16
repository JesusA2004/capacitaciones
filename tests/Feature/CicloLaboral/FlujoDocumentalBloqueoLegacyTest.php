<?php

use App\Enums\EstadoActa;
use App\Enums\EstadoFlujoDocumento;
use App\Models\ActaAdministrativa;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Services\Actas\ActaService;
use App\Services\Contratos\ContratoLaboralService;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

/*
| Endurecimiento P0 (2026-10-16): el flujo NUEVO concluye con la firma
| física (estado FINAL, no cancelable). El flujo legacy (envío/recepción/
| escaneo a corporativo) ya NO puede INICIARSE desde un documento del flujo
| nuevo (FirmadoFisicamente/Impreso): el bloqueo vive en el servicio, no solo
| en la UI. Los expedientes históricos siguen siendo legibles y pueden
| continuar su cadena legacy donde quedaron (recepción → escaneo).
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $estructura = clEstructura();
    $this->colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $estructura['sucursal']->id]);
    $this->flujo = app(FlujoDocumentalService::class);
});

/** Documento NUEVO que requiere original físico, llevado hasta FirmadoFisicamente (estado FINAL). */
function bllFirmadoFisicamente(object $prueba): GeneratedDocument
{
    clPlantilla('contrato_indeterminado', ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true]);
    Sanctum::actingAs($prueba->rh);
    $id = $prueba->postJson("/api/v1/rh/colaboradores/{$prueba->colaborador->id}/documentos-laborales", ['clave' => 'contrato_indeterminado'])->json('data.id');
    $documento = GeneratedDocument::query()->findOrFail($id);
    $prueba->flujo->marcarImpreso($documento, $prueba->rh);
    $prueba->flujo->registrarFirmaFisica($documento->refresh(), $prueba->rh, ['huella_registrada' => true]);

    return $documento->refresh();
}

test('un documento NUEVO firmado físicamente NO puede entrar al flujo legacy de envío a corporativo (servicio y API)', function () {
    $documento = bllFirmadoFisicamente($this);
    expect($documento->estado_flujo)->toBe(EstadoFlujoDocumento::FirmadoFisicamente);

    expect(fn () => $this->flujo->registrarEnvio($documento, $this->rh, ['paqueteria' => 'DHL', 'numero_guia' => '123']))
        ->toThrow(ValidationException::class);

    // El controller solo delega: el bloqueo real está en el servicio → 422.
    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/rh/documentos-laborales/{$documento->id}/envio", ['paqueteria' => 'DHL', 'numero_guia' => '123'])
        ->assertUnprocessable();

    expect($documento->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::FirmadoFisicamente);
});

test('no se puede registrar recepción de un documento firmado físicamente (flujo nuevo)', function () {
    $documento = bllFirmadoFisicamente($this);

    expect(fn () => $this->flujo->registrarRecepcion($documento, $this->rh))->toThrow(ValidationException::class);
    expect($documento->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::FirmadoFisicamente);
});

test('no se puede escanear un documento firmado físicamente ni uno solo impreso (flujo nuevo)', function () {
    $firmado = bllFirmadoFisicamente($this);
    expect(fn () => $this->flujo->registrarEscaneo($firmado, $this->rh, clArchivoPdf()))->toThrow(ValidationException::class);

    // Documento que solo requiere impresión (sin firma física): queda en Impreso.
    clPlantilla('carta_responsiva', ['requiere_impresion' => true, 'requiere_firma_fisica' => false]);
    Sanctum::actingAs($this->rh);
    $id = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/documentos-laborales", ['clave' => 'carta_responsiva'])->json('data.id');
    $impreso = GeneratedDocument::query()->findOrFail($id);
    $this->flujo->marcarImpreso($impreso, $this->rh);
    expect($impreso->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::Impreso);

    expect(fn () => $this->flujo->registrarEscaneo($impreso, $this->rh, clArchivoPdf()))->toThrow(ValidationException::class);
    expect($impreso->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::Impreso);
});

test('un documento firmado físicamente ya no puede cancelarse (estado FINAL)', function () {
    $documento = bllFirmadoFisicamente($this);

    $error = null;
    try {
        $this->flujo->cancelar($documento, $this->rh, 'motivo de prueba');
    } catch (ValidationException $e) {
        $error = $e->errors()['documento'][0] ?? '';
    }

    expect($error)->not->toBeNull()->toContain('firmado');
    expect($documento->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::FirmadoFisicamente);
});

test('acta (no circunstanciada) impresa pero NO firmada físicamente no cierra; firmada físicamente o con negativa sí', function () {
    $actas = app(ActaService::class);

    $doc = GeneratedDocument::factory()->create([
        'colaborador_id' => $this->colaborador->id,
        'estado_flujo' => EstadoFlujoDocumento::Impreso,
    ]);
    $acta = ActaAdministrativa::query()->create([
        'colaborador_id' => $this->colaborador->id,
        'tipo' => 'administrativa',
        'estado' => EstadoActa::Generada,
        'formato' => null, // histórica / no circunstanciada → rama que antes usaba estaFirmado()
        'fecha' => now()->toDateString(),
        'hechos' => 'Faltante de caja.',
        'generated_document_id' => $doc->id,
        'creado_por' => $this->rh->id,
    ]);

    // Impreso-pero-no-firmado: imprimir no es firmar → no cierra.
    expect($actas->faltanteParaCerrar($acta->refresh()))->toContain('firma');

    // Firma física registrada: cierra.
    $doc->update(['estado_flujo' => EstadoFlujoDocumento::FirmadoFisicamente]);
    expect($actas->faltanteParaCerrar($acta->refresh()))->toBeNull();

    // Vuelve a estar solo impreso, pero con negativa a firmar asentada: también cierra.
    $doc->update(['estado_flujo' => EstadoFlujoDocumento::Impreso]);
    $acta->update(['negativa_firma' => true]);
    expect($actas->faltanteParaCerrar($acta->refresh()))->toBeNull();
});

test('criterio de firma por flujo (gate de resguardo de préstamo): física exige el original firmado; digital basta con la firma digital', function () {
    $contratos = app(ContratoLaboralService::class);

    // Instrumento con firma física (p. ej. pagaré): impreso NO es firmado.
    $fisico = GeneratedDocument::factory()->create([
        'requiere_impresion' => true,
        'requiere_firma_fisica' => true,
        'estado_flujo' => EstadoFlujoDocumento::Impreso,
    ]);
    expect($contratos->documentoFirmadoSegunFlujo($fisico))->toBeFalse();
    $fisico->update(['estado_flujo' => EstadoFlujoDocumento::FirmadoFisicamente]);
    expect($contratos->documentoFirmadoSegunFlujo($fisico->refresh()))->toBeTrue();

    // Documento solo digital: basta la firma digital.
    $digital = GeneratedDocument::factory()->create([
        'requiere_firma_digital' => true,
        'estado_flujo' => EstadoFlujoDocumento::PendienteFirmaColaborador,
    ]);
    expect($contratos->documentoFirmadoSegunFlujo($digital))->toBeFalse();
    $digital->update(['estado_flujo' => EstadoFlujoDocumento::FirmadoDigitalmente]);
    expect($contratos->documentoFirmadoSegunFlujo($digital->refresh()))->toBeTrue();
});
