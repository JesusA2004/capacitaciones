<?php

use App\Enums\EstadoUsuario;
use App\Enums\FormaSalida;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
| Catálogo de bajas de la especificación de RH: 3 formas de salida
| (RV / NR / RE) y 17 causales con clave; el cierre laboral guarda el tipo
| equivalente (renuncia / fin_contrato / rescision).
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

test('el catálogo tiene las causales de la especificación (RV/NR/RE) más el grupo OT, con sus claves y textos', function () {
    $vigentes = CausalRotacion::query()->delCatalogo()->orderBy('clave')->get();

    expect($vigentes->pluck('clave')->all())->toBe([
        'NR-01', 'NR-02', 'NR-03', 'NR-04', 'NR-05', 'NR-06',
        'OT-01', 'OT-02', 'OT-03',
        'RE-01', 'RE-02', 'RE-03', 'RE-04', 'RE-05',
        'RV-01', 'RV-02', 'RV-03', 'RV-04', 'RV-05', 'RV-06',
    ]);

    $nombres = $vigentes->pluck('nombre', 'clave');
    expect($nombres['RV-01'])->toBe('Mejor oferta laboral o salarial')
        ->and($nombres['RV-04'])->toBe('Relación con el jefe o clima laboral')
        ->and($nombres['NR-02'])->toBe('No aprobó periodo de prueba / perfil no idóneo')
        ->and($nombres['RE-02'])->toBe('Faltantes en caja o cartera (desfaces)')
        ->and($nombres['RE-05'])->toBe('Incumplimiento de reglamento o políticas');

    foreach ($vigentes as $causal) {
        expect(str_starts_with((string) $causal->clave, $causal->forma()->value))->toBeTrue()
            ->and($causal->tipo)->toBe($causal->forma()->tipoBaja())
            ->and($causal->activo)->toBeTrue();
    }

    // «Integrar expediente probatorio» (requiere_juridico) y comentario
    // obligatorio según la especificación; OT pide comentario (casos sensibles).
    expect($vigentes->where('requiere_juridico', true)->pluck('clave')->sort()->values()->all())
        ->toBe(['NR-01', 'NR-03', 'NR-05', 'NR-06', 'RE-01', 'RE-02', 'RE-03', 'RE-04', 'RE-05'])
        ->and($vigentes->where('comentario_obligatorio', true)->pluck('clave')->sort()->values()->all())
        ->toBe(['OT-01', 'OT-02', 'OT-03', 'RE-01', 'RE-02', 'RE-03', 'RE-04', 'RE-05', 'RV-05'])
        ->and(causalPorClave('RV-04')->alerta)->toContain('NOM-035');

    // C2 — marca «Implica indemnización» en las no renovaciones indicadas.
    expect($vigentes->where('implica_indemnizacion', true)->pluck('clave')->sort()->values()->all())
        ->toBe(['NR-01', 'NR-03', 'NR-05', 'NR-06']);

    // C3 — grupo OT (otras terminaciones) con su forma y tipo.
    expect(causalPorClave('OT-01')->nombre)->toBe('Fallecimiento')
        ->and(causalPorClave('OT-01')->forma())->toBe(FormaSalida::OtraTerminacion)
        ->and(causalPorClave('OT-01')->tipo)->toBe(TipoBaja::OtraTerminacion)
        ->and(TipoBaja::OtraTerminacion->contemplaIndemnizacion())->toBeFalse();

    // Las causales del catálogo anterior quedan como legado: inactivas, nunca borradas.
    expect(CausalRotacion::query()->where('legado', true)->where('activo', true)->count())->toBe(0);
});

test('sembrar dos veces no duplica nada', function () {
    $antes = [CausalRotacion::query()->count(), DB::table('documentos_requeridos_baja')->count(), DB::table('formas_salida')->count()];
    $this->seed(CausalesRotacionSeeder::class);

    expect([CausalRotacion::query()->count(), DB::table('documentos_requeridos_baja')->count(), DB::table('formas_salida')->count()])->toBe($antes)
        ->and($antes[2])->toBe(4);
});

test('la causal debe ser activa y de la forma elegida; el atajo acepta la causal vacía (APK instaladas)', function () {
    $this->actingAs($this->rh)
        ->post(route('rh.colaboradores.cierres.store', $this->persona), [
            'tipo_baja' => 'rescision',
            'causal_rotacion_id' => causalPorClave('RV-01')->id,
            'fecha_efectiva' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('causal_rotacion_id');

    $inactiva = causalPorClave('RE-03');
    $inactiva->update(['activo' => false]);

    $this->actingAs($this->rh)
        ->post(route('rh.colaboradores.cierres.store', $this->persona), [
            'tipo_baja' => 'rescision',
            'causal_rotacion_id' => $inactiva->id,
            'fecha_efectiva' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('causal_rotacion_id');

    expect(CierreLaboral::query()->count())->toBe(0);

    // Sin causal: se aplica y la baja queda «causal pendiente» para RH.
    $this->actingAs($this->rh)
        ->post(route('rh.colaboradores.cierres.store', $this->persona), [
            'tipo_baja' => 'rescision',
            'fecha_efectiva' => now()->toDateString(),
        ])
        ->assertSessionHasNoErrors();

    $cierre = CierreLaboral::query()->firstOrFail();
    expect($cierre->causal_rotacion_id)->toBeNull()
        ->and($cierre->baja()->firstOrFail()->causal_rotacion_id)->toBeNull();
});

test('la rescisión no genera documentos y guarda tipo + causal en cierre, solicitud y baja', function () {
    $causal = causalPorClave('RE-05');

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
        ->and($solicitud->causal_rotacion_id)->toBe($causal->id)
        ->and($cierre->baja()->firstOrFail()->forma_salida)->toBe(FormaSalida::Rescision)
        ->and(app(DocumentoProcesoService::class)->clavesCausa($cierre))->toBe([])
        ->and(GeneratedDocument::query()->whereIn('clave_plantilla', ['carta_renuncia', 'aviso_rescision', 'aviso_termino'])->count())->toBe(0);
});

test('documentos generados por causal: renuncia → carta; NR-02 → evaluación + aviso; NR-04 → aviso; NR-01 → nada', function () {
    $documentos = app(DocumentoProcesoService::class);
    $con = fn (TipoBaja $tipo, ?string $clave): CierreLaboral => new CierreLaboral(['tipo_baja' => $tipo, 'causal_rotacion_id' => $clave !== null ? causalPorClave($clave)->id : null]);

    expect($documentos->clavesCausa($con(TipoBaja::Renuncia, 'RV-01')))->toBe(['carta_renuncia'])
        ->and($documentos->clavesCausa($con(TipoBaja::FinContrato, 'NR-02')))->toBe(['evaluacion_capacitacion', 'aviso_terminacion'])
        ->and($documentos->clavesCausa($con(TipoBaja::FinContrato, 'NR-04')))->toBe(['aviso_terminacion'])
        ->and($documentos->clavesCausa($con(TipoBaja::FinContrato, 'NR-01')))->toBe([])
        ->and($documentos->clavesCausa($con(TipoBaja::FinContrato, null)))->toBe(['evaluacion_capacitacion', 'aviso_terminacion'])
        ->and($documentos->clavesCausa($con(TipoBaja::Rescision, 'RE-01')))->toBe([]);
});

test('el diálogo de iniciar baja ofrece las formas en orden RV, NR, RE, OT con causales, ayuda y reglas', function () {
    $opciones = app(CausalRotacionService::class)->opcionesIniciarBaja($this->rh);

    expect(array_column($opciones, 'forma'))->toBe(['RV', 'NR', 'RE', 'OT'])
        ->and(array_column($opciones, 'value'))->toBe(['renuncia', 'fin_contrato', 'rescision', 'otra_terminacion']);

    $rescision = collect($opciones)->firstWhere('forma', 'RE');
    expect(array_column($rescision['causales'], 'clave'))->toBe(['RE-01', 'RE-02', 'RE-03', 'RE-04', 'RE-05'])
        ->and($rescision['causales'][0]['ayuda'])->toContain('art. 47')
        ->and($rescision['causales'][0]['comentario_obligatorio'])->toBeTrue();
});

test('RH administra el catálogo desde Bajas y finiquitos; las causales nunca se borran; sin permiso no', function () {
    $this->actingAs($this->rh)->get(route('rh.cierres.index', ['seccion' => 'causales']))
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->component('Rh/Cierres/Index')->where('seccion', 'causales')->has('causales', 20));

    $this->actingAs($this->rh)
        ->post(route('rh.causales-rotacion.store'), ['tipo' => 'rescision', 'nombre' => 'Acoso laboral', 'requiere_juridico' => true])
        ->assertSessionHasNoErrors();
    $nueva = CausalRotacion::query()->where('nombre', 'Acoso laboral')->firstOrFail();
    expect($nueva->clave)->toBe('RE-06')
        ->and($nueva->forma_salida)->toBe(FormaSalida::Rescision)
        ->and($nueva->requiere_juridico)->toBeTrue()
        ->and($nueva->comentario_obligatorio)->toBeTrue();

    $this->actingAs($this->rh)
        ->post(route('rh.causales-rotacion.store'), ['tipo' => 'rescision', 'nombre' => 'Acoso laboral'])
        ->assertSessionHasErrors('nombre');

    $this->actingAs($this->rh)
        ->put(route('rh.causales-rotacion.update', $nueva), ['tipo' => 'rescision', 'nombre' => 'Acoso u hostigamiento', 'descripcion' => 'Conducta de acoso documentada.', 'alerta' => 'Aviso a Jurídico'])
        ->assertSessionHasNoErrors();
    expect($nueva->refresh()->nombre)->toBe('Acoso u hostigamiento')->and($nueva->alerta)->toBe('Aviso a Jurídico')->and($nueva->clave)->toBe('RE-06');

    $this->actingAs($this->rh)->patch(route('rh.causales-rotacion.activo', $nueva), ['activo' => false])->assertSessionHasNoErrors();
    expect($nueva->refresh()->activo)->toBeFalse();

    // RN-15: no existe ruta para borrar.
    expect(Route::has('rh.causales-rotacion.destroy'))->toBeFalse();

    $this->actingAs($this->gerente)
        ->post(route('rh.causales-rotacion.store'), ['tipo' => 'renuncia', 'nombre' => 'Otra'])
        ->assertForbidden();
    $this->actingAs($this->gerente)->get(route('rh.cierres.index'))
        ->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->where('causales', null));
});

test('una causal ya usada no cambia de forma de salida: solo se desactiva', function () {
    $causal = causalPorClave('RV-01');

    $this->actingAs($this->rh)
        ->post(route('rh.colaboradores.cierres.store', $this->persona), [
            'tipo_baja' => 'renuncia',
            'causal_rotacion_id' => $causal->id,
            'motivo' => 'Se va a otra financiera.',
            'fecha_efectiva' => now()->addWeek()->toDateString(),
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->rh)
        ->put(route('rh.causales-rotacion.update', $causal), ['tipo' => 'rescision', 'nombre' => $causal->nombre])
        ->assertSessionHasErrors('tipo');

    $this->actingAs($this->rh)->patch(route('rh.causales-rotacion.activo', $causal), ['activo' => false])->assertSessionHasNoErrors();

    expect($causal->refresh()->activo)->toBeFalse()
        ->and(CierreLaboral::query()->firstOrFail()->motivo)->toBe('Se va a otra financiera.');
});

test('el finiquito decide la indemnización por forma y causal', function () {
    $metodo = new ReflectionMethod(FiniquitoService::class, 'calcularIndemnizacion');
    $finiquitos = app(FiniquitoService::class);
    config(['finiquitos.indemnizacion_habilitada' => true, 'finiquitos.dias_indemnizacion_por_anio' => 12]);

    $renuncia = new SolicitudInterna(['tipo_baja' => TipoBaja::Renuncia]);
    $rescision = new SolicitudInterna(['tipo_baja' => TipoBaja::Rescision, 'causal_rotacion_id' => causalPorClave('RE-05')->id]);
    $abandono = new SolicitudInterna(['tipo_baja' => TipoBaja::Rescision, 'causal_rotacion_id' => causalPorClave('RE-01')->id]);
    $noRenovacion = new SolicitudInterna(['tipo_baja' => TipoBaja::FinContrato, 'causal_rotacion_id' => causalPorClave('NR-01')->id]);

    expect($metodo->invoke($finiquitos, $renuncia, 2, 100.0))->toBe(0.0)
        // PDF Etapa 1: con causal del catálogo solo indemniza la marcada «Implica indemnización».
        ->and($metodo->invoke($finiquitos, $rescision, 2, 100.0))->toBe(0.0)
        ->and($metodo->invoke($finiquitos, $abandono, 2, 100.0))->toBe(0.0)
        ->and($metodo->invoke($finiquitos, $noRenovacion, 2, 100.0))->toBe(2400.0);
});

test('las causas anteriores se traducen a su tipo vigente y conservan su etiqueta en el historial', function () {
    expect(TipoBaja::desdeValor('baja_inmediata'))->toBe(TipoBaja::Rescision)
        ->and(TipoBaja::desdeValor('no_renovacion'))->toBe(TipoBaja::FinContrato)
        ->and(TipoBaja::desdeValor('mutuo_acuerdo'))->toBe(TipoBaja::Renuncia)
        ->and(TipoBaja::etiquetaDeValor('despido'))->toBe('Despido')
        ->and(TipoBaja::etiquetaDeValor('rescision'))->toBe('Rescisión')
        ->and(TipoBaja::FinContrato->etiqueta())->toBe('No renovación');
});
