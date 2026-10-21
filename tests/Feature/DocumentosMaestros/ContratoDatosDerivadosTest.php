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

test('NOVENA: el sueldo mensual capturado por RH manda tal cual (el diario sale de él)', function () {
    $valores = cdValores(cdColaborador(['salario_diario' => 400, 'sueldo_mensual' => 9999]));

    expect($valores['sueldo_mensual_numero'])->toBe('9,999.00');
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

test('con contrato real: TODAS las fechas del contrato son la FECHA DE INGRESO y la vigencia corre desde ella con su plazo', function () {
    // Decisión del negocio 2026-10-21: fecha del contrato = fecha de firma =
    // hoja de firmas = «a los N días…» = fecha de ingreso del colaborador;
    // vigencia = ingreso + plazo − 1 día. La fecha de hoy no aparece.
    $colaborador = cdColaborador(['salario_diario' => 350, 'fecha_ingreso' => '2026-10-07']);
    $contrato = ContratoLaboral::factory()->create([
        'colaborador_id' => $colaborador->id,
        'fecha_inicio' => '2026-10-05',
        'fecha_fin' => '2026-12-04',
        'sueldo_mensual' => 10500,
    ]);

    $valores = cdValores($colaborador, $contrato);

    expect($valores['fecha_inicio_contrato_larga'])->toBe('7 de octubre de 2026')
        ->and($valores['fecha_inicio_contrato_larga_mayusculas'])->toBe('7 DE OCTUBRE DE 2026')
        ->and($valores['fecha_inicio_contrato_dia'])->toBe('7')
        ->and($valores['fecha_fin_contrato_larga'])->toBe('6 de diciembre de 2026')
        ->and($valores['duracion_letra'])->toBe('dos meses')
        ->and($valores['lugar_y_fecha_inicio_contrato'])->toContain('el 7 de octubre de 2026')
        ->and($valores['fecha_firma_a_los_inicio_contrato'])->toBe('7 días del mes de octubre de 2026')
        ->and($valores['fecha_firma_dias_del_mes_inicio_contrato'])->toBe('7 días del mes de octubre del año 2026')
        ->and($valores['fecha_ingreso_larga'])->toBe('7 de octubre de 2026')
        ->and($valores['sueldo_mensual_numero'])->toBe('10,500.00');
});

test('gerente con ingreso el 21 de octubre de 2026 y contrato de 3 meses: todo dice 21 de octubre y la vigencia termina el 20 de enero de 2027', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 12:00', 'America/Mexico_City'));
    $colaborador = cdColaborador(['salario_diario' => 300, 'fecha_ingreso' => '2026-10-21'], 3);
    $contrato = ContratoLaboral::factory()->create([
        'colaborador_id' => $colaborador->id,
        'fecha_inicio' => '2026-10-19',
        'fecha_fin' => '2027-01-18',
        'sueldo_mensual' => 9000,
    ]);

    $valores = cdValores($colaborador, $contrato);

    expect($valores['fecha_inicio_contrato_larga'])->toBe('21 de octubre de 2026')
        ->and($valores['fecha_fin_contrato_larga'])->toBe('20 de enero de 2027')
        ->and($valores['duracion_letra'])->toBe('tres meses')
        ->and($valores['fecha_firma_a_los_inicio_contrato'])->toBe('21 días del mes de octubre de 2026')
        ->and($valores['ciudad_y_fecha_firma_a_los_inicio_contrato'])->toContain('21 días del mes de octubre de 2026')
        ->and($valores['lugar_y_fecha_inicio_contrato'])->toContain('21 de octubre de 2026')
        // Ningún dato de contrato con la fecha de hoy (23) ni con el inicio
        // registrado (19); los datos del ACTA son de otro documento.
        ->and(collect($valores)->reject(fn (string $v, string $k): bool => str_contains($k, 'acta'))->filter(fn (string $v): bool => str_contains($v, '23 de octubre') || str_contains($v, '19 de octubre'))->keys()->all())->toBe([]);
});

test('fuera del paquete del contrato (acta, evaluación) las fechas del contrato siguen siendo las registradas', function () {
    $colaborador = cdColaborador(['salario_diario' => 350]);
    $contrato = ContratoLaboral::factory()->create([
        'colaborador_id' => $colaborador->id,
        'fecha_inicio' => '2026-10-05',
        'fecha_fin' => '2026-12-04',
    ]);

    // Contexto de un documento a demanda del colaborador (no del contrato).
    $valores = app(DatosDocumentoService::class)->resolver(app(MotorDocumentalService::class)->contextoDesde($colaborador, $colaborador, User::factory()->create()));

    expect($contrato->exists)->toBeTrue()
        ->and($valores['fecha_inicio_contrato_larga'])->toBe('5 de octubre de 2026')
        ->and($valores['fecha_fin_contrato_larga'])->toBe('4 de diciembre de 2026');
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
