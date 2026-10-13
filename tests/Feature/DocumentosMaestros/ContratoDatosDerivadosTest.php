<?php

use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\DocumentosMaestros\DatosDocumentoService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;

/*
| Contratos: los datos DERIVABLES nunca se piden ni salen vacíos.
|  - Sueldo mensual = salario diario × 30 (NOVENA).
|  - Sin contrato todavía (vista previa): inicio = hoy (Ciudad de México),
|    fin = inicio + duración de capacitación del puesto (DÉCIMA CUARTA).
|  - Firma («lo firman en…», «a los … días», hoja de firmas): fecha del
|    documento.
|  - Lo que de verdad falta se pide por su nombre (Salario diario,
|    Beneficiario…), nunca «Contrato laboral (inicio, fin y sueldo)».
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00', 'America/Mexico_City'));
});

function cdValores(Colaborador $colaborador, ?ContratoLaboral $contrato = null): array
{
    $motor = app(MotorDocumentalService::class);

    return app(DatosDocumentoService::class)->resolver($motor->contextoDesde($colaborador, $contrato, User::factory()->create()));
}

function cdColaborador(array $datos = [], ?int $meses = 2): Colaborador
{
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->create(['empresa_id' => $empresa->id, 'ciudad' => 'Cuernavaca', 'estado' => 'Morelos']);
    $puesto = Puesto::factory()->create(['meses_periodo_prueba' => $meses]);

    return Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $puesto->id,
        'sueldo_mensual' => null,
        ...$datos,
    ]);
}

test('NOVENA: sin contrato, el sueldo mensual es salario diario × 30 (y no se pide «Sueldo mensual»)', function () {
    $valores = cdValores(cdColaborador(['salario_diario' => 350]));

    expect($valores['sueldo_mensual_numero'])->toBe('10,500.00')
        ->and($valores['sueldo_mensual_numero_letra'])->toStartWith('10,500.00, ');

    $fuente = app(DatosDocumentoService::class)->fuente('sueldo_mensual_numero');
    expect($fuente['etiqueta'])->toBe('Salario diario');
});

test('NOVENA: el salario diario manda sobre un sueldo mensual capturado distinto', function () {
    $valores = cdValores(cdColaborador(['salario_diario' => 400, 'sueldo_mensual' => 9999]));

    expect($valores['sueldo_mensual_numero'])->toBe('12,000.00');
});

test('DÉCIMA CUARTA y firma: sin contrato, inicio = hoy (MX), fin = inicio + meses del puesto − 1 día, firma = hoy', function () {
    $valores = cdValores(cdColaborador(['salario_diario' => 350]));

    expect($valores['fecha_inicio_contrato_larga'])->toBe('13 de octubre de 2026')
        ->and($valores['fecha_fin_contrato_larga'])->toBe('12 de diciembre de 2026')
        ->and($valores['duracion_letra'])->toBe('dos meses')
        ->and($valores['lugar_y_fecha_inicio_contrato'])->toContain('13 de octubre de 2026')
        ->and($valores['fecha_firma_a_los_inicio_contrato'])->toBe('13 días del mes de octubre de 2026')
        ->and($valores['fecha_inicio_contrato_dia'])->toBe('13');
});

test('con contrato real se usan SUS fechas y su sueldo; la firma lleva la fecha del documento', function () {
    $colaborador = cdColaborador(['salario_diario' => 350]);
    $contrato = ContratoLaboral::factory()->create([
        'colaborador_id' => $colaborador->id,
        'fecha_inicio' => '2026-10-05',
        'fecha_fin' => '2026-12-04',
        'sueldo_mensual' => 15000,
    ]);

    $valores = cdValores($colaborador, $contrato);

    expect($valores['fecha_inicio_contrato_larga'])->toBe('5 de octubre de 2026')
        ->and($valores['fecha_fin_contrato_larga'])->toBe('4 de diciembre de 2026')
        ->and($valores['sueldo_mensual_numero'])->toBe('15,000.00')
        ->and($valores['fecha_firma_a_los_inicio_contrato'])->toBe('13 días del mes de octubre de 2026');
});

test('contrario: sin salario diario, sin beneficiario y puesto sin duración → vacíos y se piden por su nombre', function () {
    $valores = cdValores(cdColaborador(['salario_diario' => null, 'beneficiario_nombre' => null, 'beneficiario_parentesco' => null], null));
    $datos = app(DatosDocumentoService::class);

    expect($valores['sueldo_mensual_numero'])->toBe('')
        ->and($valores['fecha_fin_contrato_larga'])->toBe('')
        ->and($valores['beneficiario_nombre'])->toBe('')
        ->and($valores['beneficiario_parentesco'])->toBe('')
        // El inicio nunca falta en una vista previa: es la fecha del documento.
        ->and($valores['fecha_inicio_contrato_larga'])->toBe('13 de octubre de 2026');

    expect($datos->fuente('sueldo_mensual_numero')['etiqueta'])->toBe('Salario diario')
        ->and($datos->fuente('fecha_fin_contrato_larga')['etiqueta'])->toStartWith('Fecha de fin del contrato')
        ->and($datos->fuente('fecha_inicio_contrato_larga')['etiqueta'])->toBe('Fecha de inicio del contrato')
        ->and($datos->fuente('beneficiario_nombre')['etiqueta'])->toBe('Beneficiario')
        ->and($datos->fuente('beneficiario_parentesco')['etiqueta'])->toBe('Parentesco del beneficiario');

    foreach (['sueldo_mensual_numero', 'fecha_inicio_contrato_larga', 'fecha_fin_contrato_larga', 'duracion_letra', 'lugar_y_fecha_inicio_contrato'] as $campo) {
        expect($datos->fuente($campo)['etiqueta'])->not->toBe('Contrato laboral (inicio, fin y sueldo)');
    }
});

test('DÉCIMA SÉPTIMA: beneficiario y parentesco capturados llegan al documento', function () {
    $valores = cdValores(cdColaborador(['beneficiario_nombre' => 'María del Carmen Beltrán', 'beneficiario_parentesco' => 'MADRE']));

    expect($valores['beneficiario_nombre'])->toBe('MARÍA DEL CARMEN BELTRÁN')
        ->and($valores['beneficiario_parentesco'])->toBe('MADRE');
});
