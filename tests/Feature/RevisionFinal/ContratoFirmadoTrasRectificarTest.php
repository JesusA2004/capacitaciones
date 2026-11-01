<?php

use App\Models\ContratoLaboral;
use App\Services\Compensacion\CompensacionService;
use App\Services\Contratos\ContratoLaboralService;
use App\Services\Expedientes\DatosExpedienteService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/apoyo.php';

/*
| Hallazgo 2: rectificar movía el puntero del contrato a una versión sin firmar y el
| contrato dejaba de contar como firmado: un aumento sobrescribía el sueldo pactado.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    ['colaborador' => $this->colaborador, 'contrato' => $this->contrato] = rfContrato();
    rfFirmar(rfEmitir($this->contrato, $this->rh));
    $this->contrato->refresh();

    // Rectificar el domicilio: nueva versión sin firmar; el original se conserva.
    $this->colaborador->update(['domicilio' => 'Calle Dos 20']);
    $this->rectificacion = rfEmitir($this->contrato, $this->rh, 'Se corrigió el domicilio');
    $this->contrato->refresh();
});

test('el contrato con una versión firmada sigue contando como firmado tras rectificar', function () {
    expect($this->contrato->generated_document_id)->toBe($this->rectificacion->id)
        ->and(app(ContratoLaboralService::class)->contratoFirmado($this->contrato))->toBeTrue()
        ->and(app(ContratoLaboralService::class)->aArray($this->contrato, $this->rh)['documento_firmado'])->toBeTrue()
        ->and(app(ContratoLaboralService::class)->paqueteFirmado($this->contrato))->toBeTrue();
});

test('un aumento no sobrescribe el sueldo pactado del contrato firmado y se reporta', function () {
    app(CompensacionService::class)->actualizarSueldoMensual($this->colaborador->refresh(), 15000, $this->rh, ['motivo' => 'Aumento autorizado.', 'fuente' => 'correccion']);

    expect((float) ContratoLaboral::query()->findOrFail($this->contrato->id)->sueldo_mensual)->toBe(12000.0)
        ->and(app(CompensacionService::class)->contratosVigentesFirmados($this->colaborador->refresh())->modelKeys())->toContain($this->contrato->id);
});

test('la fecha de ingreso sigue bloqueada y el registro del contrato no se corrige directo', function () {
    expect(app(DatosExpedienteService::class)->bloqueosFechaIngreso($this->colaborador))->toContain('tiene un contrato firmado');
    expect(fn () => app(ContratoLaboralService::class)->corregirRegistro($this->contrato, ['sueldo_mensual' => 1]))->toThrow(ValidationException::class);
});

test('la corrección de contratación ofrece nueva versión, no edición directa', function () {
    $this->actingAs($this->rh);

    $campos = collect($this->getJson("/correcciones/contratacion/{$this->contrato->id}")->assertOk()->json('data.campos'));

    expect($campos->firstWhere('campo', 'fecha_fin')['modo'] ?? null)->toBe('nueva_version');
});

test('sin firma en ninguna versión el contrato no cuenta como firmado', function () {
    ['contrato' => $otro] = rfContrato();
    rfEmitir($otro, $this->rh);

    expect(app(ContratoLaboralService::class)->contratoFirmado($otro->refresh()))->toBeFalse();
});
