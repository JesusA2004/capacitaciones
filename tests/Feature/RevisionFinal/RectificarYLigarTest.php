<?php

use App\Enums\EstadoFlujoDocumento;
use App\Models\ContratoLaboral;
use App\Models\GeneratedDocument;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/apoyo.php';

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    ['colaborador' => $this->colaborador, 'contrato' => $this->contrato] = rfContrato();
});

/* Hallazgo 5: el registro se ligaba después del commit de la emisión. */
test('si ligar la versión al registro falla, la anterior sigue vigente y el reintento liga', function () {
    $v1 = rfEmitir($this->contrato, $this->rh);
    $this->colaborador->update(['domicilio' => 'Calle Dos 20']);

    Event::listen('eloquent.updating: '.ContratoLaboral::class, function (): void {
        throw new RuntimeException('Falla simulada al ligar.');
    });

    expect(fn () => rfEmitir($this->contrato, $this->rh, 'Se corrigió el domicilio'))->toThrow(ValidationException::class);

    expect($v1->refresh()->estado_flujo)->not->toBe(EstadoFlujoDocumento::Cancelado)
        ->and(GeneratedDocument::query()->where('clave_plantilla', 'contrato_capacitacion')->count())->toBe(1)
        ->and($this->contrato->refresh()->generated_document_id)->toBe($v1->id);

    Event::forget('eloquent.updating: '.ContratoLaboral::class);

    $v2 = rfEmitir($this->contrato, $this->rh, 'Se corrigió el domicilio');

    expect($this->contrato->refresh()->generated_document_id)->toBe($v2->id)
        ->and($v1->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::Cancelado);
});

/* Hallazgo 7: rectificar=true no evaluaba la policy regenerar. */
test('nadie rectifica su propio contrato firmado, por ninguna vía', function () {
    rfFirmar(rfEmitir($this->contrato, $this->rh));
    $this->colaborador->update(['domicilio' => 'Calle Dos 20']);
    $mismo = clUsuarioDe($this->colaborador);
    $mismo->assignRole('rh_admin');
    $this->actingAs($mismo);
    $datos = ['clave' => 'contrato_capacitacion', 'motivo' => 'Corrijo mi propio contrato', 'rectificar' => true];

    $this->postJson("/rh/documentos-edicion/contrato/{$this->contrato->id}/emitir", $datos)->assertForbidden();
    $this->postJson("/rh/documentos-proceso/contrato/{$this->contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta', 'revision' => true, 'motivo' => 'Corrijo mi propio contrato'])->assertForbidden();
    $this->getJson("/rh/documentos-edicion/contrato/{$this->contrato->id}/campos?clave=contrato_capacitacion")->assertOk()->assertJsonPath('puede_rectificar', false);

    expect(GeneratedDocument::query()->where('clave_plantilla', 'contrato_capacitacion')->count())->toBe(1);
});

test('RH con el permiso sí rectifica el contrato firmado de otra persona', function () {
    rfFirmar(rfEmitir($this->contrato, $this->rh));
    $this->colaborador->update(['domicilio' => 'Calle Dos 20']);

    $this->actingAs($this->rh)->postJson("/rh/documentos-edicion/contrato/{$this->contrato->id}/emitir", ['clave' => 'contrato_capacitacion', 'motivo' => 'Se corrigió el domicilio', 'rectificar' => true])->assertCreated();
    expect(GeneratedDocument::query()->where('clave_plantilla', 'contrato_capacitacion')->count())->toBe(2);
});
