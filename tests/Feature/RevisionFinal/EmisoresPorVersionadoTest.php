<?php

use App\Enums\EstadoCierreLaboral;
use App\Enums\TipoBaja;
use App\Models\CierreLaboral;
use App\Models\GeneratedDocument;
use App\Services\CierreLaboral\CierreLaboralService;
use App\Services\Contratos\ContratoLaboralService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/apoyo.php';

/*
| Hallazgo 3: la API con contrato_id y el aviso de término emitían sin huella, sin
| motivo y sin revisar firma, y cada reintento creaba otro documento.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    ['colaborador' => $this->colaborador, 'contrato' => $this->contrato] = rfContrato();
    Sanctum::actingAs($this->rh);
    $this->url = "/api/v1/rh/colaboradores/{$this->colaborador->id}/documentos-laborales";
    $this->datos = ['clave' => 'contrato_capacitacion', 'contrato_id' => $this->contrato->id];
    $this->versiones = fn (string $clave = 'contrato_capacitacion') => GeneratedDocument::query()->where('clave_plantilla', $clave)->count();
});

test('API con contrato_id: el reintento no duplica y trae huella', function () {
    $primero = $this->postJson($this->url, $this->datos)->assertCreated()->json('data.id');
    $segundo = $this->postJson($this->url, $this->datos)->assertOk()->json('data.id');

    expect($segundo)->toBe($primero)
        ->and(($this->versiones)())->toBe(1)
        ->and(GeneratedDocument::query()->findOrFail($primero)->huella_datos)->not->toBeNull()
        ->and($this->contrato->refresh()->generated_document_id)->toBe($primero);
});

test('API con contrato_id: cambiar los datos exige motivo y queda ligado a la anterior', function () {
    $v1 = $this->postJson($this->url, $this->datos)->json('data.id');
    $this->colaborador->update(['name' => 'Otro']);

    $this->postJson($this->url, $this->datos)->assertUnprocessable()->assertJsonValidationErrors('motivo');
    expect(($this->versiones)())->toBe(1);

    $v2 = $this->postJson($this->url, [...$this->datos, 'motivo' => 'Se corrigió el nombre'])->assertCreated()->json('data.id');

    expect(GeneratedDocument::query()->findOrFail($v2)->revision_de_id)->toBe($v1)
        ->and($this->contrato->refresh()->generated_document_id)->toBe($v2);
});

test('API con contrato_id: un contrato firmado no se reapunta sin rectificación', function () {
    $v1 = $this->postJson($this->url, $this->datos)->json('data.id');
    rfFirmar(GeneratedDocument::query()->findOrFail($v1));
    $this->colaborador->update(['name' => 'Otro']);

    $this->postJson($this->url, [...$this->datos, 'motivo' => 'Se corrigió el nombre'])->assertUnprocessable()->assertJsonValidationErrors('documento');
    expect($this->contrato->refresh()->generated_document_id)->toBe($v1);

    $this->postJson($this->url, [...$this->datos, 'motivo' => 'Se corrigió el nombre', 'rectificar' => true])->assertCreated();
    expect(app(ContratoLaboralService::class)->contratoFirmado($this->contrato->refresh()))->toBeTrue();
});

test('el paquete inicial del contrato ya nace con huella', function () {
    $generado = app(ContratoLaboralService::class)->prepararDocumentos($this->contrato, ['contrato_capacitacion'], $this->rh)['generados'][0];

    expect($generado->huella_datos)->not->toBeNull()
        ->and($this->contrato->refresh()->generated_document_id)->toBe($generado->id);
});

test('generar el aviso de término dos veces devuelve el mismo documento', function () {
    clPlantilla('aviso_termino');
    $cierre = CierreLaboral::query()->create([
        'colaborador_id' => $this->colaborador->id,
        'tipo_baja' => TipoBaja::Renuncia,
        'motivo' => 'Renuncia voluntaria',
        'fecha_efectiva' => now()->toDateString(),
        'estado' => EstadoCierreLaboral::Iniciado,
        'iniciado_por' => $this->rh->id,
        'autorizado_rh_en' => now(),
    ]);
    $cierres = app(CierreLaboralService::class);

    $primero = $cierres->generarAviso($cierre, $this->rh);

    expect($cierres->generarAviso($cierre, $this->rh)->id)->toBe($primero->id)
        ->and(($this->versiones)('aviso_termino'))->toBe(1)
        ->and($primero->huella_datos)->not->toBeNull();

    $this->colaborador->update(['name' => 'Otro']);
    expect(fn () => $cierres->generarAviso($cierre->refresh(), $this->rh))->toThrow(ValidationException::class);
    expect(($this->versiones)('aviso_termino'))->toBe(1);
});

test('API sin contrato: el reintento idéntico tampoco duplica', function () {
    clPlantilla('contrato_confidencialidad');
    $datos = ['clave' => 'contrato_confidencialidad'];

    $primero = $this->postJson($this->url, $datos)->assertCreated()->json('data.id');
    $this->postJson($this->url, $datos)->assertOk()->assertJsonPath('data.id', $primero);
});
