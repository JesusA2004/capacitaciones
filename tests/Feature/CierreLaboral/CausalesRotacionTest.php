<?php

use App\Enums\EstadoUsuario;
use App\Enums\TipoBaja;
use App\Models\CausalRotacion;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\CierreLaboral\CausalRotacionService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\Finiquitos\FiniquitoService;
use Database\Seeders\CausalesRotacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Solo tres tipos de baja (renuncia, rescisión, fin de contrato) y su motivo
| = causal de rotación, administrada en Bajas y finiquitos.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $estructura = clEstructura();
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $estructura['sucursal']->id]);
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $estructura['sucursal']->id]);
    $this->persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'jefe_id' => $this->gerente->colaborador_id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => '2024-01-01',
    ]);
    User::factory()->create(['colaborador_id' => $this->persona->id])->assignRole('colaborador');
});

function causalPorClave(string $clave): CausalRotacion
{
    return CausalRotacion::query()->where('clave', $clave)->firstOrFail();
}

test('solo existen tres tipos de baja y los valores anteriores se rechazan', function () {
    expect(array_column(TipoBaja::cases(), 'value'))->toBe(['renuncia', 'rescision', 'fin_contrato']);

    foreach (['despido', 'baja_inmediata', 'no_renovacion', 'bajo_desempeno', 'otro'] as $viejo) {
        $this->actingAs($this->rh)
            ->post(route('rh.colaboradores.cierres.store', $this->persona), [
                'tipo_baja' => $viejo,
                'fecha_efectiva' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('tipo_baja');
    }

    expect(CierreLaboral::query()->count())->toBe(0);
});

test('el catálogo inicial se siembra sin duplicarse y cada tipo tiene causales', function () {
    $total = CausalRotacion::query()->count();
    $this->seed(CausalesRotacionSeeder::class);

    expect(CausalRotacion::query()->count())->toBe($total);

    foreach (TipoBaja::cases() as $tipo) {
        expect(CausalRotacion::query()->where('tipo', $tipo->value)->activas()->count())->toBeGreaterThan(0);
    }
});

test('la causal es obligatoria y debe pertenecer al tipo elegido', function () {
    $this->actingAs($this->rh)
        ->post(route('rh.colaboradores.cierres.store', $this->persona), [
            'tipo_baja' => 'rescision',
            'fecha_efectiva' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('causal_rotacion_id');

    $this->actingAs($this->rh)
        ->post(route('rh.colaboradores.cierres.store', $this->persona), [
            'tipo_baja' => 'rescision',
            'causal_rotacion_id' => causalPorClave('renuncia_voluntaria')->id,
            'fecha_efectiva' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('causal_rotacion_id');

    $inactiva = causalPorClave('falta_probidad');
    $inactiva->update(['activo' => false]);

    $this->actingAs($this->rh)
        ->post(route('rh.colaboradores.cierres.store', $this->persona), [
            'tipo_baja' => 'rescision',
            'causal_rotacion_id' => $inactiva->id,
            'fecha_efectiva' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('causal_rotacion_id');

    expect(CierreLaboral::query()->count())->toBe(0);
});

test('la rescisión se aplica sin generar documentos y guarda tipo + causal en cierre y solicitud', function () {
    $causal = causalPorClave('bajo_desempeno');

    $this->actingAs($this->rh)
        ->post(route('rh.colaboradores.cierres.store', $this->persona), [
            'tipo_baja' => 'rescision',
            'causal_rotacion_id' => $causal->id,
            'fecha_efectiva' => now()->toDateString(),
        ])
        ->assertSessionHasNoErrors();

    $cierre = CierreLaboral::query()->firstOrFail();
    $solicitud = SolicitudInterna::query()->findOrFail($cierre->solicitud_interna_id);

    expect($cierre->tipo_baja)->toBe(TipoBaja::Rescision)
        ->and($cierre->causal_rotacion_id)->toBe($causal->id)
        ->and($cierre->motivo)->toBe('Bajo desempeño')
        ->and($solicitud->causal_rotacion_id)->toBe($causal->id)
        ->and(app(DocumentoProcesoService::class)->clavesCausa($cierre))->toBe([])
        ->and(GeneratedDocument::query()->where('documentable_type', $cierre->getMorphClass())->where('documentable_id', $cierre->id)->count())->toBe(0)
        // Ni aviso de rescisión ni carta de renuncia.
        ->and(GeneratedDocument::query()->whereIn('clave_plantilla', ['carta_renuncia', 'aviso_rescision', 'aviso_termino'])->count())->toBe(0);
});

test('renuncia conserva la carta de renuncia y fin de contrato la evaluación + aviso', function () {
    $documentos = app(DocumentoProcesoService::class);

    expect($documentos->clavesCausa(new CierreLaboral(['tipo_baja' => TipoBaja::Renuncia])))->toBe(['carta_renuncia'])
        ->and($documentos->clavesCausa(new CierreLaboral(['tipo_baja' => TipoBaja::FinContrato])))->toBe(['evaluacion_capacitacion', 'aviso_terminacion'])
        ->and($documentos->clavesCausa(new CierreLaboral(['tipo_baja' => TipoBaja::Rescision])))->toBe([]);
});

test('el diálogo de iniciar baja ofrece los tres tipos con sus causales activas', function () {
    $opciones = app(CausalRotacionService::class)->opcionesIniciarBaja($this->rh);

    expect(array_column($opciones, 'value'))->toBe(['renuncia', 'rescision', 'fin_contrato']);

    $rescision = collect($opciones)->firstWhere('value', 'rescision');
    $nombres = array_column($rescision['causales'], 'nombre');
    expect($nombres)->toContain('Bajo desempeño', 'Abandono de trabajo', 'Falta de probidad u honradez')
        ->not->toContain('Renuncia voluntaria');
});

test('RH administra las causales desde Bajas y finiquitos; sin permiso no', function () {
    $this->actingAs($this->rh)->get(route('rh.cierres.index', ['seccion' => 'causales']))
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->component('Rh/Cierres/Index')->where('seccion', 'causales')->has('causales'));

    $this->actingAs($this->rh)
        ->post(route('rh.causales-rotacion.store'), ['tipo' => 'rescision', 'nombre' => 'Acoso laboral'])
        ->assertSessionHasNoErrors();
    $nueva = CausalRotacion::query()->where('nombre', 'Acoso laboral')->firstOrFail();
    expect($nueva->tipo)->toBe(TipoBaja::Rescision)->and($nueva->activo)->toBeTrue();

    // Duplicado en el mismo tipo: rechazado.
    $this->actingAs($this->rh)
        ->post(route('rh.causales-rotacion.store'), ['tipo' => 'rescision', 'nombre' => 'Acoso laboral'])
        ->assertSessionHasErrors('nombre');

    $this->actingAs($this->rh)
        ->put(route('rh.causales-rotacion.update', $nueva), ['tipo' => 'rescision', 'nombre' => 'Acoso u hostigamiento', 'excluye_indemnizacion' => true])
        ->assertSessionHasNoErrors();
    expect($nueva->refresh()->nombre)->toBe('Acoso u hostigamiento')->and($nueva->excluye_indemnizacion)->toBeTrue();

    $this->actingAs($this->rh)->patch(route('rh.causales-rotacion.activo', $nueva), ['activo' => false])->assertSessionHasNoErrors();
    expect($nueva->refresh()->activo)->toBeFalse();

    $this->actingAs($this->rh)->post(route('rh.causales-rotacion.mover', $nueva), ['direccion' => 'arriba'])->assertSessionHasNoErrors();

    // Nunca usada y sin clave del sistema: se puede eliminar.
    $this->actingAs($this->rh)->delete(route('rh.causales-rotacion.destroy', $nueva))->assertSessionHasNoErrors();
    expect(CausalRotacion::query()->whereKey($nueva->id)->exists())->toBeFalse();

    // Un gerente no administra causales (ni ve la pestaña).
    $this->actingAs($this->gerente)
        ->post(route('rh.causales-rotacion.store'), ['tipo' => 'renuncia', 'nombre' => 'Otra'])
        ->assertForbidden();
    $this->actingAs($this->gerente)->get(route('rh.cierres.index'))
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->where('causales', null));
});

test('una causal ya usada no se elimina ni cambia de tipo: solo se desactiva', function () {
    $causal = causalPorClave('mejor_oferta');

    $this->actingAs($this->rh)
        ->post(route('rh.colaboradores.cierres.store', $this->persona), [
            'tipo_baja' => 'renuncia',
            'causal_rotacion_id' => $causal->id,
            'motivo' => 'Se va a otra financiera.',
            'fecha_efectiva' => now()->addWeek()->toDateString(),
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->rh)->delete(route('rh.causales-rotacion.destroy', $causal))->assertSessionHasErrors('causal');
    $this->actingAs($this->rh)
        ->put(route('rh.causales-rotacion.update', $causal), ['tipo' => 'rescision', 'nombre' => $causal->nombre])
        ->assertSessionHasErrors('tipo');

    $this->actingAs($this->rh)->patch(route('rh.causales-rotacion.activo', $causal), ['activo' => false])->assertSessionHasNoErrors();

    expect($causal->refresh()->activo)->toBeFalse()
        ->and(CierreLaboral::query()->firstOrFail()->motivo)->toBe('Se va a otra financiera.');
});

test('el finiquito decide la indemnización por tipo y causal', function () {
    $metodo = new ReflectionMethod(FiniquitoService::class, 'calcularIndemnizacion');
    $finiquitos = app(FiniquitoService::class);
    config(['finiquitos.indemnizacion_habilitada' => true, 'finiquitos.dias_indemnizacion_por_anio' => 12]);

    $renuncia = new SolicitudInterna(['tipo_baja' => TipoBaja::Renuncia]);
    $rescision = new SolicitudInterna(['tipo_baja' => TipoBaja::Rescision, 'causal_rotacion_id' => causalPorClave('bajo_desempeno')->id]);
    $abandono = new SolicitudInterna(['tipo_baja' => TipoBaja::Rescision, 'causal_rotacion_id' => causalPorClave('abandono_trabajo')->id]);
    $finContrato = new SolicitudInterna(['tipo_baja' => TipoBaja::FinContrato]);

    expect($metodo->invoke($finiquitos, $renuncia, 2, 100.0))->toBe(0.0)
        ->and($metodo->invoke($finiquitos, $rescision, 2, 100.0))->toBe(2400.0)
        ->and($metodo->invoke($finiquitos, $abandono, 2, 100.0))->toBe(0.0)
        ->and($metodo->invoke($finiquitos, $finContrato, 2, 100.0))->toBe(2400.0);
});

test('las causas anteriores se traducen a su tipo vigente y conservan su etiqueta en el historial', function () {
    expect(TipoBaja::desdeValor('baja_inmediata'))->toBe(TipoBaja::Rescision)
        ->and(TipoBaja::desdeValor('no_renovacion'))->toBe(TipoBaja::FinContrato)
        ->and(TipoBaja::desdeValor('mutuo_acuerdo'))->toBe(TipoBaja::Renuncia)
        ->and(TipoBaja::etiquetaDeValor('despido'))->toBe('Despido')
        ->and(TipoBaja::etiquetaDeValor('rescision'))->toBe('Rescisión');
});
