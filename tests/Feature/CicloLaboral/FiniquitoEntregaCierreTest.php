<?php

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoFiniquito;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\FiniquitoAjuste;
use App\Models\FiniquitoCalculo;
use App\Models\User;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\CierreLaboral\CierreLaboralService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/*
| Finiquito dentro del Cierre laboral: RH lo calcula (y ve el desglose al
| momento), ajusta montos por concepto con motivo y registra la ENTREGA,
| que congela el finiquito y cierra el cierre. Ya no existe «autorizar
| finiquito» (ni ruta ni acción).
*/

beforeEach(function () {
    // Fechas armadas con now(): el código usa el calendario de México.
    clRelojMediodiaMexico();
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $estructura = clEstructura();
    $this->colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'puesto_id' => $estructura['puesto']->id,
        'fecha_ingreso' => now()->subYears(3)->toDateString(),
        'salario_diario' => '315.04',
        'sueldo_mensual' => '9451.20',
        'estado_alta' => EstadoAltaColaborador::Activo->value,
    ]);
    $this->cuenta = User::factory()->create(['colaborador_id' => $this->colaborador->id]);
    $this->cuenta->assignRole('colaborador');
    ContratoLaboral::factory()->create(['colaborador_id' => $this->colaborador->id, 'tipo' => 'indeterminado', 'fecha_fin' => null]);

    $this->cierres = app(CierreLaboralService::class);
    $this->cierre = $this->cierres->solicitar($this->colaborador, [
        'tipo_baja' => 'renuncia',
        'motivo' => 'Renuncia voluntaria.',
        'fecha_efectiva' => now()->toDateString(),
    ], $this->rh);
});

function feFinicha(Colaborador $colaborador, User $usuario): array
{
    return app(CicloLaboralService::class)->ficha($colaborador, $usuario);
}

test('calcular finiquito sin capturar sueldo usa el salario diario registrado y deja ver el desglose al momento', function () {
    $this->actingAs($this->rh)
        ->post("/rh/cierres/{$this->cierre->id}/finiquito/calcular")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $finiquito = FiniquitoCalculo::query()->sole();
    expect($finiquito->estado)->toBe(EstadoFiniquito::Borrador)
        ->and((float) $finiquito->sueldo_mensual)->toBe(9451.20)
        ->and((float) $finiquito->sueldo_diario)->toBe(315.04);

    $ficha = feFinicha($this->colaborador, $this->rh);
    $datos = $ficha['cierre']['finiquito'];
    $conceptos = collect($datos['desglose'])->pluck('concepto_clave')->filter()->values()->all();

    expect($datos['neto'])->toBe($finiquito->neto)
        ->and($datos['editable'])->toBeTrue()
        ->and($datos['sueldo_diario'])->toBe('315.04')
        ->and($datos['ultima_edicion']['usuario'])->not->toBeNull()
        ->and($conceptos)->toContain('sueldo_pendiente', 'aguinaldo_proporcional', 'vacaciones_proporcionales', 'prima_vacacional', 'isr_retenido')
        ->and($ficha['cierre']['estado'])->toBe(EstadoCierreLaboral::FiniquitoEnProceso->value);

    $acciones = collect($ficha['ciclo']['acciones_permitidas'])->pluck('clave')->all();
    expect($acciones)->toContain('registrar_entrega_finiquito', 'ver_finiquito', 'calcular_finiquito')
        ->not->toContain('autorizar_finiquito');
});

test('editar el monto de un concepto persiste con motivo, quién y cuándo, y recalcula el total en el servidor', function () {
    $this->cierres->calcularFiniquito($this->cierre, $this->rh);
    $netoInicial = (float) FiniquitoCalculo::query()->sole()->neto;

    $this->actingAs($this->rh)
        ->post("/rh/cierres/{$this->cierre->id}/finiquito/conceptos-automaticos", [
            'concepto_clave' => 'sueldo_pendiente',
            'importe' => '2205.28',
            'motivo' => '7 días trabajados sin pagar',
        ])->assertRedirect()->assertSessionHasNoErrors();

    $finiquito = FiniquitoCalculo::query()->sole();
    expect((float) $finiquito->neto)->toBe(round($netoInicial + 2205.28, 2))
        ->and(FiniquitoAjuste::query()->sole()->user_id)->toBe($this->rh->id);

    // Renglón propio de RH (deducción) con motivo obligatorio.
    $this->post("/rh/cierres/{$this->cierre->id}/finiquito/conceptos", ['tipo' => 'deduccion', 'concepto' => 'Uniforme no devuelto', 'importe' => '350.00'])
        ->assertSessionHasErrors('observaciones');
    $this->post("/rh/cierres/{$this->cierre->id}/finiquito/conceptos", ['tipo' => 'deduccion', 'concepto' => 'Uniforme no devuelto', 'importe' => '350.00', 'observaciones' => 'Playera sin devolver'])
        ->assertSessionHasNoErrors();
    expect((float) $finiquito->refresh()->neto)->toBe(round($netoInicial + 2205.28 - 350, 2));

    // Dinero exacto: nunca más de dos decimales ni negativos.
    $this->post("/rh/cierres/{$this->cierre->id}/finiquito/conceptos-automaticos", ['concepto_clave' => 'aguinaldo_proporcional', 'importe' => '10.555', 'motivo' => 'Prueba'])
        ->assertSessionHasErrors('importe');
    $this->post("/rh/cierres/{$this->cierre->id}/finiquito/conceptos-automaticos", ['concepto_clave' => 'aguinaldo_proporcional', 'importe' => '-5', 'motivo' => 'Prueba'])
        ->assertSessionHasErrors('importe');

    $datos = feFinicha($this->colaborador, $this->rh)['cierre']['finiquito'];
    $fila = collect($datos['desglose'])->firstWhere('concepto_clave', 'sueldo_pendiente');
    expect($fila['importe'])->toBe(2205.28)
        ->and($fila['ajuste']['motivo'])->toBe('7 días trabajados sin pagar')
        ->and($fila['ajuste']['usuario'])->not->toBeNull()
        ->and($datos['ultima_edicion']['accion'])->toBe('Concepto de finiquito');

    // Recalcular conserva los ajustes de RH.
    $this->post("/rh/cierres/{$this->cierre->id}/finiquito/calcular")->assertSessionHasNoErrors();
    expect((float) $finiquito->refresh()->neto)->toBe(round($netoInicial + 2205.28 - 350, 2));
});

test('registrar la entrega congela el finiquito y deja el cierre laboral completo', function () {
    $this->cierres->calcularFiniquito($this->cierre, $this->rh);

    $this->actingAs($this->rh)
        ->post("/rh/cierres/{$this->cierre->id}/finiquito/entrega", [
            'fecha' => now()->toDateString(),
            'forma_pago' => 'transferencia',
            'comentario' => 'Entregado en sucursal.',
        ])->assertRedirect()->assertSessionHasNoErrors();

    $cierre = $this->cierre->refresh();
    $finiquito = FiniquitoCalculo::query()->sole();

    expect($cierre->estado)->toBe(EstadoCierreLaboral::ExpedienteCerrado)
        ->and($cierre->pago_metodo)->toBe('transferencia')
        ->and((float) $cierre->pago_monto)->toBe((float) $finiquito->neto)
        ->and($finiquito->estado)->toBe(EstadoFiniquito::Pagado)
        ->and($finiquito->snapshot['entrega']['comentario'])->toBe('Entregado en sucursal.')
        ->and($finiquito->snapshot['conceptos'])->not->toBeEmpty()
        ->and($this->colaborador->refresh()->estado_alta)->toBe(EstadoAltaColaborador::Baja)
        ->and(Colaborador::withTrashed()->whereKey($this->colaborador->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'finiquito_entregado')->exists())->toBeTrue();

    // La ficha sigue mostrando el cierre completo y el finiquito de solo lectura (100%).
    $ficha = feFinicha($this->colaborador, $this->rh);
    expect($ficha['ciclo']['progreso'])->toBe(100)
        ->and($ficha['cierre']['estado'])->toBe(EstadoCierreLaboral::ExpedienteCerrado->value)
        ->and($ficha['cierre']['finiquito']['editable'])->toBeFalse()
        ->and($ficha['cierre']['finiquito']['entrega']['forma_pago'])->toBe('Transferencia');

    // Entregado: ya no se edita.
    $this->post("/rh/cierres/{$this->cierre->id}/finiquito/conceptos-automaticos", ['concepto_clave' => 'aguinaldo_proporcional', 'importe' => '1.00', 'motivo' => 'Prueba'])
        ->assertSessionHasErrors();
    expect($finiquito->refresh()->estado)->toBe(EstadoFiniquito::Pagado);
});

test('un cierre del flujo anterior (finiquito autorizado) tampoco se queda atorado: la entrega lo cierra', function () {
    // El cierre "legacy" solo puede fabricarse con la bandera encendida
    // (autorizarFiniquito es un paso del flujo anterior); la entrega moderna
    // debe cerrarlo aunque la bandera vuelva a apagarse.
    config(['features.cierre_flujo_legacy' => true]);
    $this->cierres->calcularFiniquito($this->cierre, $this->rh);
    $cierre = $this->cierres->autorizarFiniquito($this->cierre->refresh(), $this->rh);
    config(['features.cierre_flujo_legacy' => false]);
    expect($cierre->estado)->toBe(EstadoCierreLaboral::FiniquitoAutorizado);

    $acciones = collect($this->cierres->accionesPermitidas($cierre, $this->rh))->pluck('clave')->all();
    expect($acciones)->toContain('registrar_entrega_finiquito')->not->toContain('autorizar_finiquito');

    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/rh/cierres/{$cierre->id}/finiquito/entrega", ['fecha' => now()->toDateString(), 'forma_pago' => 'efectivo'])
        ->assertOk()
        ->assertJsonPath('data.estado', EstadoCierreLaboral::ExpedienteCerrado->value)
        ->assertJsonPath('data.finiquito.estado', EstadoFiniquito::Pagado->value);
});

test('la entrega no se registra antes de la fecha efectiva ni con fecha futura', function () {
    $cierre = CierreLaboral::query()->findOrFail($this->cierre->id);
    $cierre->update(['fecha_efectiva' => now()->addDays(3)->toDateString()]);
    $this->cierres->calcularFiniquito($cierre, $this->rh);

    $this->actingAs($this->rh)
        ->post("/rh/cierres/{$cierre->id}/finiquito/entrega", ['fecha' => now()->toDateString()])
        ->assertSessionHasErrors('fecha');
    $this->post("/rh/cierres/{$cierre->id}/finiquito/entrega", ['fecha' => now()->addDay()->toDateString()])
        ->assertSessionHasErrors('fecha');

    expect($cierre->refresh()->estado)->toBe(EstadoCierreLaboral::FiniquitoEnProceso)
        ->and(FiniquitoCalculo::query()->sole()->estado)->toBe(EstadoFiniquito::Borrador);
});

test('ya no existe ninguna ruta para autorizar el finiquito (web ni API)', function () {
    expect(Route::has('rh.cierres.autorizarFiniquito'))->toBeFalse()
        ->and(collect(Route::getRoutes()->getRoutes())->contains(fn ($r) => str_contains($r->uri(), 'finiquito/autorizar') || str_contains($r->uri(), 'cierres/{cierre}/finiquito/revisar')))->toBeFalse();

    $this->cierres->calcularFiniquito($this->cierre, $this->rh);
    $this->actingAs($this->rh)->post("/rh/cierres/{$this->cierre->id}/finiquito/autorizar")->assertNotFound();
    expect(FiniquitoCalculo::query()->sole()->estado)->toBe(EstadoFiniquito::Borrador);
});
