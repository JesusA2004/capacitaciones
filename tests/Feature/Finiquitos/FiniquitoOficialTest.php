<?php

use App\Enums\FamiliaAdministrativa;
use App\Enums\MotorPdf;
use App\Models\Colaborador;
use App\Models\FiniquitoCalculo;
use App\Models\GeneratedDocument;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\DocumentosAdministrativos\DatosDocumentoAdministrativo;
use App\Services\DocumentosAdministrativos\DisenoAdministrativoService;
use App\Services\DocumentosAdministrativos\DocumentoAdministrativoService;
use App\Services\Finiquitos\FiniquitoService;
use App\Services\Vacaciones\VacacionesService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;

/*
 * Finiquito con el FORMATO OFICIAL (docs/formatosRH/Formato_Finiquito.docx):
 * claves 001–004 / 101–102, vacaciones y prima en filas DISTINTAS, ajustes
 * autorizados con valor calculado/final/motivo/usuario/fecha, total siempre
 * del servidor y snapshot inmutable al cerrar.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    $this->rh = User::factory()->create();
    $this->rh->assignRole('rh_admin');
    $estructura = clEstructura();
    $this->persona = Colaborador::factory()->create([
        'name' => 'Pedro', 'apellidos' => 'Juárez', 'fecha_ingreso' => now()->subYears(2)->subMonths(3),
        'sucursal_principal_id' => $estructura['sucursal']->id, 'rfc' => 'JUPP800101AB1', 'curp' => 'JUPP800101HMSRDD09', 'nss' => '11223344556',
    ]);
    $this->solicitud = SolicitudInterna::factory()->create([
        'tipo' => 'baja_colaborador', 'estado' => 'en_revision', 'objetivo_colaborador_id' => $this->persona->id,
        'fecha_efectiva' => now()->addWeek(), 'tipo_baja' => 'renuncia',
    ]);
    $this->servicio = app(FiniquitoService::class);
    $this->finiquito = $this->servicio->calcular($this->solicitud, $this->rh, 15000, 2500);
});

test('vacaciones proporcionales y prima vacacional son filas distintas con su clave oficial', function () {
    $renglones = collect($this->servicio->desglose($this->finiquito))->keyBy('clave');

    expect($renglones->keys()->take(4)->all())->toBe(['001', '002', '003', '004'])
        ->and($renglones['001']['concepto'])->toBe('Sueldo pendiente de pago')
        ->and($renglones['003']['concepto'])->toBe('Vacaciones proporcionales')
        ->and($renglones['004']['concepto'])->toBe('Prima vacacional (25%)')
        ->and($renglones['003']['importe'])->not->toBe($renglones['004']['importe'])
        ->and($renglones->has('101'))->toBeTrue()
        ->and($renglones->has('102'))->toBeTrue();

    $dias = (float) $this->finiquito->vacaciones_pendientes;
    // Vacaciones = días × salario diario; prima = 25 % de eso.
    expect($renglones['003']['importe'])->toBe(round($dias * 500, 2))
        ->and($renglones['004']['importe'])->toBe(round($dias * 500 * 0.25, 2));
});

test('RH ajusta un concepto automático: se guarda calculado/final/motivo/usuario y el total lo recalcula el servidor', function () {
    $antes = (float) $this->finiquito->neto;

    $this->actingAs($this->rh)->post(route('rh.solicitudes.finiquito.conceptos.ajustar', $this->solicitud), [
        'concepto_clave' => 'isr_retenido',
        'importe' => 350.50,
        'motivo' => 'ISR calculado por contabilidad',
        // Un total enviado por el frontend se ignora.
        'total' => 1,
    ])->assertSessionHasNoErrors();

    $finiquito = $this->finiquito->refresh();
    $ajuste = $finiquito->ajustes()->firstOrFail();

    expect((float) $ajuste->valor_calculado)->toBe(0.0)
        ->and((float) $ajuste->valor_final)->toBe(350.5)
        ->and((float) $ajuste->ajuste)->toBe(350.5)
        ->and($ajuste->motivo)->toBe('ISR calculado por contabilidad')
        ->and($ajuste->user_id)->toBe($this->rh->id)
        ->and($ajuste->created_at)->not->toBeNull()
        ->and((float) $finiquito->neto)->toBe(round($antes - 350.5, 2))
        ->and($finiquito->estado->value)->toBe('borrador');

    $isr = collect($this->servicio->desglose($finiquito))->firstWhere('clave', '101');
    expect($isr['importe'])->toBe(350.5)->and($isr['ajustado'])->toBeTrue()->and($isr['valor_calculado'])->toBe(0.0);
});

test('RH agrega, edita y quita conceptos manuales; el total siempre sale del servidor', function () {
    $concepto = $this->servicio->agregarConcepto($this->finiquito, ['tipo' => 'percepcion', 'concepto' => 'Bono de permanencia', 'importe' => 1000], $this->rh);
    expect((float) $this->finiquito->refresh()->total_percepciones)->toBeGreaterThan(1000);
    $conBono = (float) $this->finiquito->neto;

    $this->servicio->actualizarConcepto($concepto, ['importe' => 400], $this->rh);
    expect((float) $this->finiquito->refresh()->neto)->toBe(round($conBono - 600, 2));

    $this->servicio->eliminarConcepto($concepto, $this->rh);
    expect((float) $this->finiquito->refresh()->neto)->toBe(round($conBono - 1000, 2));
});

test('un ajuste sin motivo se rechaza', function () {
    $this->actingAs($this->rh)->post(route('rh.solicitudes.finiquito.conceptos.ajustar', $this->solicitud), [
        'concepto_clave' => 'aguinaldo_proporcional', 'importe' => 10,
    ])->assertSessionHasErrors('motivo');
});

test('al generar el PDF queda un snapshot inmutable con conceptos y ajustes; firmado ya no se modifica', function () {
    $this->servicio->ajustarConceptoAutomatico($this->finiquito, 'otras_deducciones', 200, 'Adeudo de uniforme', $this->rh);
    $this->servicio->generarPdf($this->finiquito->refresh(), $this->rh);
    $finiquito = $this->finiquito->refresh();

    expect($finiquito->snapshot['conceptos'])->toBeArray()
        ->and(collect($finiquito->snapshot['conceptos'])->pluck('clave')->all())->toContain('001', '003', '004', '101', '102')
        ->and($finiquito->snapshot['ajustes'][0]['motivo'])->toBe('Adeudo de uniforme')
        ->and(GeneratedDocument::query()->whereKey($finiquito->generated_document_id)->value('master_familia'))->toBe('administrativo.finiquito');

    $finiquito->update(['estado' => 'firmado']);
    expect(fn () => $this->servicio->ajustarConceptoAutomatico($finiquito, 'isr_retenido', 1, 'x', $this->rh))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('el PDF sigue el formato oficial: encabezado, fechas de alta/baja, claves, total a pagar e importe con letra', function () {
    $familia = FamiliaAdministrativa::Finiquito;
    $html = app(DocumentoAdministrativoService::class)->html($familia, app(DatosDocumentoAdministrativo::class)->finiquito($this->finiquito, $this->servicio->desglose($this->finiquito)), app(DisenoAdministrativoService::class)->porDefecto($familia), MotorPdf::DomPdf);

    expect($html)->toContain('FINIQUITO')
        ->toContain('Comprobante de pago por terminación laboral')
        ->toContain('Fecha de alta')
        ->toContain('Fecha de baja')
        ->toContain('Días trabajados')
        ->toContain('PEDRO JUÁREZ')
        ->toContain('JUPP800101HMSRDD09')
        ->toContain('Sueldo pendiente de pago')
        ->toContain('Aguinaldo proporcional')
        ->toContain('Vacaciones proporcionales')
        ->toContain('Prima vacacional (25%)')
        ->toContain('ISR retenido')
        ->toContain('Otras deducciones')
        ->toContain('TOTAL A PAGAR')
        ->toContain('PESOS')
        ->toContain('M.N.')
        ->toContain('Nombre y firma del trabajador')
        ->toContain('Recibí de conformidad');
});

test('la vista previa del finiquito no guarda nada', function () {
    $this->actingAs($this->rh)->get(route('rh.solicitudes.finiquito.vista-previa', $this->solicitud))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect(FiniquitoCalculo::query()->whereKey($this->finiquito->id)->value('documento_generado_path'))->toBeNull();
});
