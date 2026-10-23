<?php

use App\Enums\EstadoEvaluacionPrueba;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\User;
use App\Services\Contratos\EvaluacionPeriodoPruebaPdfService;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use App\Services\Expedientes\ExpedienteCentroService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/*
| FO-RH-EPP-001 es una evaluación DEL JEFE INMEDIATO sobre el colaborador,
| NO una autoevaluación. Cubre: quién puede contestarla, los campos nuevos
| del formato (Meta/%, faltas/retardos, descripciones por nivel) y el PDF
| oficial de 2 páginas con resultados, vinculado al expediente y protegido
| por permiso. Las reglas de puntaje/comentario/excepción viven en
| EvaluacionPeriodoPruebaOficialTest.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    clPlantilla('contrato_indeterminado');

    $this->rh = clUsuario('rh_admin');
    $this->jefeUsuario = clUsuario('jefe_directo');
    $this->otroJefeUsuario = clUsuario('jefe_directo');

    $this->colaborador = Colaborador::factory()->create(['jefe_id' => $this->jefeUsuario->colaborador_id]);
    $this->colaboradorUsuario = clUsuarioDe($this->colaborador);
    $this->contrato = ContratoLaboral::factory()->create(['colaborador_id' => $this->colaborador->id, 'fecha_fin' => now()->addDays(5)->toDateString()]);
    $this->evaluacion = EvaluacionPeriodoPrueba::factory()->create([
        'colaborador_id' => $this->colaborador->id,
        'contrato_laboral_id' => $this->contrato->id,
        'evaluador_colaborador_id' => $this->jefeUsuario->colaborador_id,
        'estado' => EstadoEvaluacionPrueba::Pendiente,
        'fecha_limite' => $this->contrato->fecha_fin,
    ]);
    $this->evaluaciones = app(EvaluacionPeriodoPruebaService::class);

    $this->criterios = fn (int $cal = 4, array $extra = []) => collect(config('contratos.evaluacion_periodo_prueba_v2.criterios'))
        ->map(fn (array $c) => array_merge(['clave' => $c['clave'], 'calificacion' => $cal], $extra[$c['clave']] ?? []))->all();
    $this->filtros = fn (bool $r = false) => collect(config('contratos.evaluacion_periodo_prueba_v2.filtros'))
        ->map(fn (array $f) => ['clave' => $f['clave'], 'respuesta' => $r])->all();
});

test('el colaborador evaluado NO puede contestar su propia evaluación (no es autoevaluación)', function () {
    expect($this->colaboradorUsuario->can('capturar', $this->evaluacion))->toBeFalse();
});

test('el jefe inmediato asignado SÍ puede capturarla', function () {
    expect($this->jefeUsuario->can('capturar', $this->evaluacion))->toBeTrue();
});

test('otro jefe que no es su jefe inmediato NO puede capturarla', function () {
    expect($this->otroJefeUsuario->can('capturar', $this->evaluacion))->toBeFalse();
});

test('RH puede asignar la evaluación del colaborador', function () {
    expect(Gate::forUser($this->rh)->allows('asignar', [EvaluacionPeriodoPrueba::class, $this->colaborador]))->toBeTrue();
});

test('RH autorizado con alcance sobre el colaborador sí puede capturar', function () {
    expect($this->rh->can('capturar', $this->evaluacion))->toBeTrue();
});

test('RH con permiso de autorizar pero sin alcance sobre el colaborador NO puede capturar', function () {
    $sinAlcance = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $sinAlcance->givePermissionTo('evaluaciones.autorizar');

    expect($sinAlcance->can('capturar', $this->evaluacion))->toBeFalse();
});

test('el jefe captura Meta/Logró y el sistema calcula el porcentaje; faltas y retardos se guardan', function () {
    $evaluacion = $this->evaluaciones->capturar($this->evaluacion, $this->jefeUsuario, [
        'criterios' => ($this->criterios)(4, ['cumple_metas' => ['meta' => 100, 'logro' => 90]]),
        'filtros' => ($this->filtros)(),
        'faltas_injustificadas' => 1,
        'retardos' => 3,
    ]);

    $cumpleMetas = collect($evaluacion->criterios)->firstWhere('clave', 'cumple_metas');
    expect((float) $cumpleMetas['meta'])->toBe(100.0)
        ->and((float) $cumpleMetas['logro'])->toBe(90.0)
        ->and((float) $cumpleMetas['porcentaje'])->toBe(90.0)
        ->and($evaluacion->faltas_injustificadas)->toBe(1)
        ->and($evaluacion->retardos)->toBe(3);

    $r = $this->evaluaciones->respuestas($evaluacion->refresh());
    $rMetas = collect($r['criterios'])->firstWhere('clave', 'cumple_metas');
    expect((float) $rMetas['porcentaje'])->toBe(90.0)
        ->and($r['faltas_injustificadas'])->toBe(1)
        ->and($r['retardos'])->toBe(3)
        // Cada criterio trae la descripción oficial de sus 4 niveles.
        ->and($rMetas['niveles']['4'] ?? '')->not->toBe('');
});

test('el PDF oficial sale con los resultados, tiene 2 páginas y corresponde a la versión usada', function () {
    $evaluacion = $this->evaluaciones->capturar($this->evaluacion, $this->jefeUsuario, [
        'criterios' => ($this->criterios)(4),
        'filtros' => ($this->filtros)(),
    ]);

    $pdfService = app(EvaluacionPeriodoPruebaPdfService::class);
    $html = $pdfService->html($evaluacion->refresh());

    // Datos del colaborador, criterios, Paso 3 y firmas del jefe.
    expect($html)->toContain($this->colaborador->nombreCompleto())
        ->toContain('Conoce y hace bien su trabajo')
        ->toContain('PASO 3')
        ->toContain('SÍ SE RENUEVA')
        ->toContain('EVALUÓ')
        ->toContain('COMENTARIOS DEL JEFE INMEDIATO');

    // La evaluación guardó la versión oficial del formato.
    expect($evaluacion->version)->toBe(2);

    // PDF real (motor de pruebas): exactamente 2 páginas A4/Carta.
    $pdf = $pdfService->renderizar($evaluacion);
    expect(substr($pdf, 0, 4))->toBe('%PDF');
    $paginas = preg_match_all('/\/Type\s*\/Pages[^>]*\/Count\s+(\d+)/s', $pdf, $m)
        ? max(array_map('intval', $m[1]))
        : preg_match_all('/\/Type\s*\/Page[^s]/', $pdf);
    expect($paginas)->toBe(2);
});

test('el PDF para firma está protegido por permiso: ajeno sin permiso 403, RH 200', function () {
    $this->evaluaciones->capturar($this->evaluacion, $this->jefeUsuario, [
        'criterios' => ($this->criterios)(4),
        'filtros' => ($this->filtros)(),
    ]);

    $ruta = route('rh.evaluaciones.pdf', $this->evaluacion->id);

    // Un colaborador cualquiera, ajeno y sin permiso de descarga: 403.
    $ajeno = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $ajeno->assignRole('colaborador');
    $this->actingAs($ajeno)->get($ruta)->assertForbidden();

    // RH con permiso de descargar: 200 (PDF).
    $this->actingAs($this->rh)->get($ruta)->assertOk();
});

test('la evaluación finalizada queda vinculada al expediente con su resultado y puntaje', function () {
    $this->evaluaciones->capturar($this->evaluacion, $this->jefeUsuario, [
        'criterios' => ($this->criterios)(4),
        'filtros' => ($this->filtros)(),
    ]);
    $this->evaluaciones->autorizar($this->evaluacion->refresh(), $this->rh, ['renovar' => true]);

    $centro = app(ExpedienteCentroService::class)->resumen($this->colaborador->fresh(), $this->rh);
    $tarjeta = collect($centro['tarjetas'])->firstWhere('clave', 'evaluaciones');

    expect($tarjeta)->not->toBeNull()
        ->and($tarjeta['detalle'])->toContain('36')
        ->and($tarjeta['url'])->not->toBeNull();
});
