<?php

use App\Enums\EstadoDocumento;
use App\Models\AltaDigital;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Onboarding\OnboardingService;

test('el checklist marca datos personales/laborales y expediente segun el estado real del colaborador', function () {
    $usuario = User::factory()->create([
        'curp' => null,
        'domicilio' => null,
        'sucursal_principal_id' => null,
        'puesto_id' => null,
        'fecha_ingreso' => null,
    ]);

    $servicio = app(OnboardingService::class);
    $checklist = collect($servicio->checklist($usuario))->keyBy('clave');

    expect($checklist['datos_personales']['completado'])->toBeFalse()
        ->and($checklist['datos_laborales']['completado'])->toBeFalse()
        ->and($checklist['alta_aprobada']['completado'])->toBeFalse();
});

test('el checklist reconoce el contrato firmado y el alta aprobada', function () {
    $usuario = User::factory()->create();
    $tipoContrato = DocumentType::factory()->create(['clave' => 'contrato']);

    EmployeeDocument::factory()->create([
        'user_id' => $usuario->id,
        'document_type_id' => $tipoContrato->id,
        'status' => EstadoDocumento::Aprobado->value,
    ]);

    AltaDigital::factory()->create([
        'user_id' => $usuario->id,
        'estado' => 'convertida_a_colaborador',
        'aviso_privacidad_aceptado' => true,
        'consentimiento_datos_aceptado' => true,
    ]);

    $servicio = app(OnboardingService::class);
    $checklist = collect($servicio->checklist($usuario))->keyBy('clave');

    expect($checklist['contrato_firmado']['completado'])->toBeTrue()
        ->and($checklist['alta_aprobada']['completado'])->toBeTrue()
        ->and($checklist['aviso_privacidad']['completado'])->toBeTrue()
        ->and($checklist['consentimiento']['completado'])->toBeTrue();
});
