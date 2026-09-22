<?php

use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\ReciboNomina;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $estructura = clEstructura();
    $this->colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $estructura['sucursal']->id, 'numero_empleado' => 'EMP-0200']);
    $this->cuenta = User::factory()->create(['colaborador_id' => $this->colaborador->id]);
    $this->cuenta->assignRole('colaborador');
});

function clReciboPayload(array $extra = []): array
{
    return [
        'periodo_inicio' => '2026-09-14',
        'periodo_fin' => '2026-09-20',
        'fecha_pago' => '2026-09-21',
        'conceptos' => [
            ['tipo' => 'percepcion', 'concepto' => 'Sueldo semanal', 'importe' => 3500],
            ['tipo' => 'percepcion', 'concepto' => 'Comisiones', 'cantidad' => 3, 'importe' => 600],
            ['tipo' => 'deduccion', 'concepto' => 'Anticipo', 'importe' => 400],
        ],
        'observaciones' => 'Semana con comisiones.',
        ...$extra,
    ];
}

test('rh crea un recibo interno semanal con detalle, totales, folio y PDF no fiscal en el expediente', function () {
    Sanctum::actingAs($this->rh);

    $datos = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/recibos", clReciboPayload())
        ->assertCreated()
        ->json('data');

    expect($datos['total_percepciones'])->toBe('4100.00')
        ->and($datos['total_deducciones'])->toBe('400.00')
        ->and($datos['neto'])->toBe('3700.00')
        ->and($datos['tipo_periodo'])->toBe('semanal')
        ->and($datos['numero_periodo'])->toBe(38)
        ->and($datos['folio'])->toStartWith('RIN-')
        ->and($datos['leyenda'])->toBe('RECIBO INTERNO DE NÓMINA - NO FISCAL')
        ->and($datos['conceptos'])->toHaveCount(3)
        ->and($datos['tiene_pdf'])->toBeTrue();

    $recibo = ReciboNomina::query()->findOrFail($datos['id']);
    expect($recibo->pdf_path)->toContain('/NominaInterna/');
    Storage::disk('nas')->assertExists($recibo->pdf_path);
    expect(GeneratedDocument::query()->where('documentable_type', $recibo->getMorphClass())->where('documentable_id', $recibo->id)->exists())->toBeTrue();

    // La leyenda es visible en el formato impreso.
    $html = view('pdf.recibo-nomina', [
        'recibo' => $recibo, 'colaborador' => $this->colaborador, 'periodo_inicio' => $recibo->periodo_inicio,
        'periodo_fin' => $recibo->periodo_fin, 'fecha_pago' => $recibo->fecha_pago, 'percepciones' => $recibo->percepciones,
        'deducciones' => $recibo->deducciones, 'total_percepciones' => 4100.0, 'total_deducciones' => 400.0, 'neto' => 3700.0,
    ])->render();
    expect($html)->toContain('RECIBO INTERNO DE NÓMINA - NO FISCAL');

    // Mismo periodo: no se duplica.
    $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/recibos", clReciboPayload())->assertUnprocessable();
});

test('el colaborador solo consulta y descarga SUS recibos', function () {
    Sanctum::actingAs($this->rh);
    $id = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/recibos", clReciboPayload())->json('data.id');

    Sanctum::actingAs($this->cuenta);
    $this->getJson('/api/v1/colaborador/recibos')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    $this->getJson("/api/v1/colaborador/recibos/{$id}")->assertOk()->assertJsonCount(3, 'data.conceptos');
    $this->get("/api/v1/colaborador/recibos/{$id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $otro = clUsuario('colaborador');
    Sanctum::actingAs($otro);
    $this->getJson('/api/v1/colaborador/recibos')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/colaborador/recibos/{$id}")->assertForbidden();
    $this->get("/api/v1/colaborador/recibos/{$id}/pdf")->assertForbidden();
    $this->getJson('/api/v1/rh/recibos')->assertForbidden();
});

test('importación masiva CSV: simula, reporta filas inválidas y no emite recibos incompletos', function () {
    Sanctum::actingAs($this->rh);
    Colaborador::factory()->create(['numero_empleado' => 'EMP-0201']);

    $csv = "numero_empleado,tipo,concepto,importe,cantidad\n"
        ."EMP-0200,percepcion,Sueldo semanal,3000,\n"
        ."EMP-0200,deduccion,Anticipo,200,\n"
        ."EMP-0201,percepcion,Sueldo semanal,2800,\n"
        ."EMP-0201,bono,Tipo inválido,100,\n"
        ."EMP-9999,percepcion,Sueldo semanal,1000,\n";

    $archivo = fn () => UploadedFile::fake()->createWithContent('semana38.csv', $csv);

    $simulacion = $this->post('/api/v1/rh/recibos/importar', [
        'archivo' => $archivo(), 'periodo_inicio' => '2026-09-14', 'periodo_fin' => '2026-09-20', 'simular' => true,
    ], ['Accept' => 'application/json'])->assertOk()->json('data');

    expect($simulacion['simulacion'])->toBeTrue()
        ->and($simulacion['recibos_generados'])->toBe(0)
        ->and(ReciboNomina::query()->count())->toBe(0);

    $resultado = $this->post('/api/v1/rh/recibos/importar', [
        'archivo' => $archivo(), 'periodo_inicio' => '2026-09-14', 'periodo_fin' => '2026-09-20',
    ], ['Accept' => 'application/json'])->assertCreated()->json('data');

    expect($resultado['recibos_generados'])->toBe(1)
        ->and($resultado['lote'])->toStartWith('IMP-')
        ->and(collect($resultado['errores'])->pluck('fila')->filter()->values()->all())->toBe([5])
        ->and(collect($resultado['errores'])->pluck('numero_empleado')->all())->toContain('EMP-0201', 'EMP-9999');

    $recibo = ReciboNomina::query()->where('colaborador_id', $this->colaborador->id)->firstOrFail();
    expect($recibo->neto)->toBe('2800.00')->and($recibo->lote_importacion)->toBe($resultado['lote']);
});
