<?php

use App\Models\GeneratedDocument;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/apoyo.php';

/*
| Hallazgo 8: `completar` sobrescribía fecha de ingreso, CURP, RFC y NSS ya capturados,
| sin motivo ni bloqueos, y antes de rechazar «ya generado».
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    ['colaborador' => $this->colaborador, 'contrato' => $this->contrato] = rfContrato([
        'curp' => 'PELJ900510HMSRPN01', 'rfc' => 'PELJ900510AB1', 'nss' => '12345678901', 'fecha_ingreso' => '2026-10-20',
    ]);
    $this->actingAs($this->rh);
    $this->generar = fn (array $completar) => $this->postJson("/rh/documentos-proceso/contrato/{$this->contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta', 'completar' => $completar]);
});

test('no sobrescribe un dato crítico ya capturado', function (string $campo, string $valor) {
    $antes = (string) $this->colaborador->refresh()->getRawOriginal($campo);

    ($this->generar)([$campo => $valor])->assertUnprocessable()->assertJsonValidationErrors($campo);

    expect((string) $this->colaborador->refresh()->getRawOriginal($campo))->toBe($antes)
        ->and(GeneratedDocument::query()->count())->toBe(0);
})->with([
    ['curp', 'AAAA900510HMSRPN09'],
    ['rfc', 'AAAA900510AB9'],
    ['nss', '99999999999'],
    ['fecha_ingreso', '2020-01-01'],
]);

test('el mismo valor ya capturado no estorba y un vacío sí se rellena', function () {
    $this->colaborador->update(['beneficiario_parentesco' => null]);

    ($this->generar)(['curp' => 'PELJ900510HMSRPN01', 'beneficiario_parentesco' => 'MADRE'])->assertCreated();

    expect($this->colaborador->refresh()->beneficiario_parentesco)->toBe('MADRE');
});

test('rellenar la fecha de ingreso respeta los bloqueos', function () {
    rfFirmar(rfEmitir($this->contrato, $this->rh));
    $this->colaborador->update(['fecha_ingreso' => null]);

    $this->postJson("/rh/documentos-proceso/contrato/{$this->contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta', 'regenerar' => true, 'motivo' => 'Se intenta rellenar la fecha', 'completar' => ['fecha_ingreso' => '2020-01-01']])
        ->assertUnprocessable()->assertJsonValidationErrors('fecha_ingreso');

    expect($this->colaborador->refresh()->fecha_ingreso)->toBeNull();
});

test('si el documento ya está generado, completar no escribe nada', function () {
    rfEmitir($this->contrato, $this->rh);
    $this->colaborador->update(['beneficiario_parentesco' => null]);

    ($this->generar)(['beneficiario_parentesco' => 'MADRE'])->assertUnprocessable()->assertJsonValidationErrors('documento');

    expect($this->colaborador->refresh()->beneficiario_parentesco)->toBeNull();
});

test('la vista previa tampoco muestra un dato ya capturado con otro valor', function () {
    $this->getJson("/rh/documentos-proceso/contrato/{$this->contrato->id}/vista-previa?".http_build_query(['clave' => 'contrato_capacitacion', 'proceso' => 'alta', 'completar' => ['curp' => 'AAAA900510HMSRPN09']]))
        ->assertUnprocessable()->assertJsonValidationErrors('curp');
});
