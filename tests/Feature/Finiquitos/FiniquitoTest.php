<?php

use App\Models\FiniquitoCalculo;
use App\Models\SolicitudInterna;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    $this->rh = User::factory()->create();
    $this->rh->assignRole('rh_admin');
});

function crearSolicitudBaja(array $atributos = []): SolicitudInterna
{
    $colaborador = User::factory()->create(['fecha_ingreso' => now()->subYears(2)]);

    return SolicitudInterna::factory()->create([
        'tipo' => 'baja_colaborador',
        'estado' => 'en_revision',
        'colaborador_objetivo_id' => $colaborador->id,
        'fecha_efectiva' => now()->addWeek(),
        'tipo_baja' => 'despido',
        ...$atributos,
    ]);
}

test('calcular un finiquito genera un cálculo en borrador con montos derivados', function () {
    $solicitud = crearSolicitudBaja();

    $this->actingAs($this->rh)
        ->post(route('rh.solicitudes.finiquito.calcular', $solicitud), ['sueldo_mensual' => 15000])
        ->assertSessionHasNoErrors();

    $finiquito = $solicitud->finiquitoCalculo()->first();

    expect($finiquito)->not->toBeNull()
        ->and($finiquito->estado->value)->toBe('borrador')
        ->and((float) $finiquito->sueldo_mensual)->toBe(15000.0)
        ->and((float) $finiquito->sueldo_diario)->toBe(500.0)
        ->and($finiquito->antiguedad_anios)->toBe(2)
        ->and((float) $finiquito->indemnizacion)->toBeGreaterThan(0);
});

test('el cálculo guarda un snapshot al generar el pdf', function () {
    $solicitud = crearSolicitudBaja();
    $this->actingAs($this->rh)->post(route('rh.solicitudes.finiquito.calcular', $solicitud), ['sueldo_mensual' => 15000]);

    $this->actingAs($this->rh)
        ->post(route('rh.solicitudes.finiquito.generar-pdf', $solicitud))
        ->assertSessionHasNoErrors();

    $finiquito = $solicitud->finiquitoCalculo()->first();

    expect($finiquito->documento_generado_path)->not->toBeNull()
        ->and($finiquito->snapshot)->not->toBeNull()
        ->and($finiquito->snapshot['sueldo_mensual'])->not->toBeNull();

    Storage::disk('nas')->assertExists($finiquito->documento_generado_path);
});

test('rh_admin puede editar montos manuales y el total ajustado se recalcula', function () {
    $solicitud = crearSolicitudBaja();
    $this->actingAs($this->rh)->post(route('rh.solicitudes.finiquito.calcular', $solicitud), ['sueldo_mensual' => 15000]);

    $finiquito = $solicitud->finiquitoCalculo()->first();
    $totalCalculado = (float) $finiquito->total_calculado;

    $this->actingAs($this->rh)
        ->put(route('rh.solicitudes.finiquito.ajustes', $solicitud), [
            'bonos_extra' => 1000,
            'descuentos' => 200,
            'adeudos' => 0,
            'comentarios_ajuste' => 'Bono de puntualidad pendiente.',
        ])
        ->assertSessionHasNoErrors();

    $finiquito->refresh();

    expect((float) $finiquito->total_ajustado)->toBe(round($totalCalculado + 1000 - 200, 2))
        ->and($finiquito->estado->value)->toBe('borrador');
});

test('un finiquito firmado no acepta más ajustes', function () {
    $solicitud = crearSolicitudBaja();
    $this->actingAs($this->rh)->post(route('rh.solicitudes.finiquito.calcular', $solicitud), ['sueldo_mensual' => 15000]);
    $finiquito = $solicitud->finiquitoCalculo()->first();

    $this->actingAs($this->rh)
        ->post(route('rh.solicitudes.finiquito.firmado', $solicitud), [
            'archivo' => UploadedFile::fake()->create('finiquito-firmado.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasNoErrors();

    expect($finiquito->fresh()->estado->value)->toBe('firmado');

    $this->actingAs($this->rh)
        ->put(route('rh.solicitudes.finiquito.ajustes', $solicitud), ['bonos_extra' => 500])
        ->assertForbidden();
});

test('no se ejecuta la baja sin un cálculo de finiquito revisado', function () {
    $solicitud = crearSolicitudBaja();

    $this->actingAs($this->rh)->post(route('solicitudes.documentos.store', $solicitud), [
        'archivo' => UploadedFile::fake()->create('evidencia.pdf', 100, 'application/pdf'),
    ]);

    $this->actingAs($this->rh)
        ->post(route('rh.solicitudes.aprobar', $solicitud))
        ->assertSessionHasErrors('finiquito');

    expect($solicitud->fresh()->estado->value)->toBe('en_revision');
});

test('super_admin con permiso especial puede aprobar la baja sin finiquito revisado', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $solicitud = crearSolicitudBaja();

    $this->actingAs($superAdmin)->post(route('solicitudes.documentos.store', $solicitud), [
        'archivo' => UploadedFile::fake()->create('evidencia.pdf', 100, 'application/pdf'),
    ]);

    $this->actingAs($superAdmin)
        ->post(route('rh.solicitudes.aprobar', $solicitud))
        ->assertSessionHasNoErrors();

    expect($solicitud->fresh()->estado->value)->toBe('aprobada');
});

test('rh_auxiliar no puede revisar (aprobar) un cálculo de finiquito', function () {
    $auxiliar = User::factory()->create();
    $auxiliar->assignRole('rh_auxiliar');

    $solicitud = crearSolicitudBaja();
    $this->actingAs($this->rh)->post(route('rh.solicitudes.finiquito.calcular', $solicitud), ['sueldo_mensual' => 15000]);

    $this->actingAs($auxiliar)
        ->post(route('rh.solicitudes.finiquito.revisar', $solicitud))
        ->assertForbidden();
});

test('recalcular conserva los ajustes manuales ya capturados', function () {
    $solicitud = crearSolicitudBaja();
    $this->actingAs($this->rh)->post(route('rh.solicitudes.finiquito.calcular', $solicitud), ['sueldo_mensual' => 15000]);

    $this->actingAs($this->rh)->put(route('rh.solicitudes.finiquito.ajustes', $solicitud), ['bonos_extra' => 800]);

    $this->actingAs($this->rh)
        ->post(route('rh.solicitudes.finiquito.recalcular', $solicitud), ['sueldo_mensual' => 16000])
        ->assertSessionHasNoErrors();

    $finiquito = $solicitud->finiquitoCalculo()->first();

    expect((float) $finiquito->sueldo_mensual)->toBe(16000.0)
        ->and((float) $finiquito->bonos_extra)->toBe(800.0);
});

test('el finiquito no se calcula dos veces para la misma solicitud', function () {
    $solicitud = crearSolicitudBaja();
    $this->actingAs($this->rh)->post(route('rh.solicitudes.finiquito.calcular', $solicitud), ['sueldo_mensual' => 15000]);

    expect(FiniquitoCalculo::where('solicitud_interna_id', $solicitud->id)->count())->toBe(1);

    $this->actingAs($this->rh)
        ->post(route('rh.solicitudes.finiquito.calcular', $solicitud), ['sueldo_mensual' => 15000])
        ->assertStatus(500);

    expect(FiniquitoCalculo::where('solicitud_interna_id', $solicitud->id)->count())->toBe(1);
});
