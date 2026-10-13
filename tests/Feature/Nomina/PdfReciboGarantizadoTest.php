<?php

use App\Enums\EstadoReciboNomina;
use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\ReciboNomina;
use App\Models\User;
use App\Services\DocumentosAdministrativos\DocumentoAdministrativoService;
use App\Services\Nomina\ReciboNominaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

/**
 * Un recibo EMITIDO siempre tiene su PDF: preparar → PDF guardado → emitir.
 * Si el archivo de un recibo existente desaparece, se repara (nunca 404
 * para un registro que sí existe).
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $estructura = clEstructura();
    $this->colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $estructura['sucursal']->id, 'estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => 12000, 'fecha_ingreso' => '2025-01-01']);
    $this->datos = ['periodo_inicio' => '2026-10-01', 'periodo_fin' => '2026-10-15', 'tipo_periodo' => 'quincenal', 'conceptos' => [['tipo' => 'percepcion', 'concepto' => 'Sueldo', 'importe' => 6000]]];
});

function reciboEmitido(object $prueba): ReciboNomina
{
    return app(ReciboNominaService::class)->generar($prueba->colaborador, $prueba->datos, $prueba->rh);
}

test('emitir genera y guarda el archivo; el PDF abre con 200 application/pdf', function () {
    $recibo = reciboEmitido($this);

    expect($recibo->estado)->toBe(EstadoReciboNomina::Emitido)
        ->and($recibo->pdf_path)->not->toBeNull();
    Storage::disk('nas')->assertExists((string) $recibo->pdf_path);

    $this->actingAs($this->rh)->get(route('rh.nomina.recibos.pdf', $recibo))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('si el PDF no se puede generar, el recibo NO queda emitido y el motivo es humano', function () {
    $this->mock(DocumentoAdministrativoService::class)->shouldReceive('generar')->andThrow(new RuntimeException('Chrome exited with code 1'));

    try {
        reciboEmitido($this);
        $this->fail('Debió lanzar ValidationException.');
    } catch (ValidationException $e) {
        $mensaje = (string) collect($e->errors())->flatten()->first();
        expect($mensaje)->toContain('No se pudo generar el PDF del recibo')
            ->and($mensaje)->not->toContain('Chrome');
    }

    $recibo = ReciboNomina::query()->firstOrFail();
    expect($recibo->estado)->toBe(EstadoReciboNomina::Borrador)
        ->and($recibo->emitido_at)->toBeNull()
        ->and($recibo->pdf_path)->toBeNull();
});

test('archivo faltante: pantalla recuperable (no 404) y reparar vuelve a enlazar el documento existente', function () {
    $recibo = reciboEmitido($this);
    $original = GeneratedDocument::query()->where('documentable_id', $recibo->id)->firstOrFail();
    $recibo->update(['pdf_path' => 'expedientes/no-existe.pdf']);

    $this->actingAs($this->rh)->get(route('rh.nomina.recibos.pdf', $recibo))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('ArchivoNoDisponible')->where('urlReparar', route('rh.nomina.recibos.reparar-pdf', $recibo)));

    $this->actingAs($this->rh)->postJson(route('rh.nomina.recibos.reparar-pdf', $recibo))
        ->assertOk()
        ->assertJson(['url' => route('rh.nomina.recibos.pdf', $recibo)]);

    expect($recibo->refresh()->pdf_path)->toBe($original->path);
    $this->assertDatabaseHas('activity_log', ['event' => 'recibo_nomina_pdf_reparado']);
});

test('pdf_path null y sin archivo físico: se regenera con el snapshot del recibo (mismos montos)', function () {
    $recibo = reciboEmitido($this);
    Storage::disk('nas')->delete((string) $recibo->pdf_path);
    $recibo->update(['pdf_path' => null, 'pdf_disk' => null]);
    // Un cambio de sueldo posterior no altera el recibo.
    $this->colaborador->update(['sueldo_mensual' => 99999]);

    $reparado = app(ReciboNominaService::class)->regenerarPdf($recibo, $this->rh);

    expect($reparado->pdf_path)->not->toBeNull()
        ->and($reparado->neto)->toBe('6000.00');
    Storage::disk('nas')->assertExists((string) $reparado->pdf_path);

    $nuevo = GeneratedDocument::query()->where('documentable_id', $recibo->id)->latest('id')->firstOrFail();
    expect($nuevo->revision_de_id)->not->toBeNull()
        // El original tenía diseño guardado: se respeta, no se "moderniza".
        ->and($nuevo->layout_snapshot['regenerado_con_version_actual'] ?? null)->toBeFalse()
        ->and($nuevo->payload['neto'])->toBe('6000.00');
});

test('API: archivo faltante responde 409 con código estable; inexistente 404; sin permiso 403', function () {
    $recibo = reciboEmitido($this);
    $recibo->update(['pdf_path' => 'expedientes/no-existe.pdf']);

    Sanctum::actingAs($this->rh);
    $this->getJson("/api/v1/rh/recibos/{$recibo->id}/pdf")
        ->assertStatus(409)
        ->assertJson(['codigo' => 'archivo_no_disponible', 'puede_reparar' => true]);
    $this->getJson('/api/v1/rh/recibos/999999/pdf')->assertNotFound();

    $ajeno = User::factory()->create();
    $ajeno->assignRole('colaborador');
    $this->actingAs($ajeno)->get(route('rh.nomina.recibos.pdf', $recibo))->assertForbidden();
    $this->actingAs($this->rh)->get('/rh/nomina/recibos/999999/pdf')->assertNotFound();
});
