<?php

use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoExcepcionDireccionGeneral;
use App\Enums\TipoContratacion;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use App\Services\Contratos\SolicitudExcepcionPeriodoPruebaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
| Evaluación de Término de Periodo de Prueba — formato OFICIAL FO-RH-EPP-001
| (docs/onboarding/Evaluacion_Periodo_de_Prueba_Operativo.pdf). Casos
| exigidos por CLAUDE.md/el encargo: 9 criterios, un 1 bloquea, un Sí
| bloquea, 25 renueva, 24 no, comentario obligatorio, excepción de Dirección
| General, legacy intacto, renovación idempotente.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    clPlantilla('contrato_indeterminado');

    $this->rh = clUsuario('rh_admin');
    $this->dg = clUsuario('direccion');
    $this->jefeUsuario = clUsuario('jefe_directo');
    $this->colaborador = Colaborador::factory()->create(['jefe_id' => $this->jefeUsuario->colaborador_id]);
    $this->contrato = ContratoLaboral::factory()->create(['colaborador_id' => $this->colaborador->id, 'fecha_fin' => now()->addDays(5)->toDateString()]);
    $this->evaluacion = EvaluacionPeriodoPrueba::factory()->create([
        'colaborador_id' => $this->colaborador->id,
        'contrato_laboral_id' => $this->contrato->id,
        'evaluador_colaborador_id' => $this->jefeUsuario->colaborador_id,
        'estado' => EstadoEvaluacionPrueba::Pendiente,
        'fecha_limite' => $this->contrato->fecha_fin,
    ]);
    $this->evaluaciones = app(EvaluacionPeriodoPruebaService::class);

    $this->criterios = fn (int $calificacion = 4) => collect(config('contratos.evaluacion_periodo_prueba_v2.criterios'))
        ->map(fn (array $c) => ['clave' => $c['clave'], 'calificacion' => $calificacion])->all();
    $this->filtros = fn (bool $respuesta = false) => collect(config('contratos.evaluacion_periodo_prueba_v2.filtros'))
        ->map(fn (array $f) => ['clave' => $f['clave'], 'respuesta' => $respuesta])->all();
});

test('la captura exige exactamente los 9 criterios y las 5 preguntas filtro del catálogo oficial', function () {
    expect(fn () => $this->evaluaciones->capturar($this->evaluacion, $this->jefeUsuario, [
        'criterios' => array_slice(($this->criterios)(), 0, 8),
        'filtros' => ($this->filtros)(),
    ]))->toThrow(ValidationException::class);
});

test('25 puntos sin ningún 1 y sin ningún filtro en Sí: SÍ renueva', function () {
    // 9 criterios: siete en 3 (21) + dos en 2 (4) = 25 exacto, sin usar ningún 1.
    $criterios = ($this->criterios)(3);
    $criterios[0]['calificacion'] = 2;
    $criterios[1]['calificacion'] = 2;
    $evaluacion = $this->evaluaciones->capturar($this->evaluacion, $this->jefeUsuario, [
        'criterios' => $criterios,
        'filtros' => ($this->filtros)(),
        'observaciones' => 'Dos criterios en regular; aun así alcanza el mínimo de 25.',
    ]);

    expect((float) $evaluacion->calificacion)->toBe(25.0)
        ->and($evaluacion->version)->toBe(2)
        ->and($evaluacion->recomienda_renovar)->toBeTrue()
        ->and($evaluacion->resultado->value)->toBe('aprobado');
});

test('24 puntos NO renueva aunque no haya ningún 1 ni ningún filtro en Sí (regla canónica: mínimo 25)', function () {
    // 9 criterios: seis en 3 (18) + tres en 2 (6) = 24 exacto, sin usar ningún 1.
    $criterios = ($this->criterios)(3); // 9 × 3 = 27
    $criterios[0]['calificacion'] = 2;
    $criterios[1]['calificacion'] = 2;
    $criterios[2]['calificacion'] = 2; // 27 - 3 = 24
    $evaluacion = $this->evaluaciones->capturar($this->evaluacion, $this->jefeUsuario, [
        'criterios' => $criterios,
        'filtros' => ($this->filtros)(),
        'observaciones' => 'Tres criterios en regular; no alcanza el mínimo de 25 para renovar.',
    ]);

    expect((float) $evaluacion->calificacion)->toBe(24.0)
        ->and($evaluacion->recomienda_renovar)->toBeFalse()
        ->and($evaluacion->resultado->value)->toBe('no_aprobado');
});

test('un solo criterio en 1 bloquea la renovación aunque el total sea alto', function () {
    $criterios = ($this->criterios)(4); // 9 × 4 = 36
    $criterios[0]['calificacion'] = 1; // 8×4 + 1×1 = 33, muy por encima de 25
    $evaluacion = $this->evaluaciones->capturar($this->evaluacion, $this->jefeUsuario, [
        'criterios' => $criterios,
        'filtros' => ($this->filtros)(),
        'observaciones' => 'Un criterio deficiente: no se renueva sin importar el total.',
    ]);

    expect((float) $evaluacion->calificacion)->toBe(33.0)
        ->and($evaluacion->recomienda_renovar)->toBeFalse();
});

test('un solo filtro en Sí bloquea la renovación aunque el total sea perfecto', function () {
    $filtros = ($this->filtros)(false);
    $filtros[0]['respuesta'] = true;
    $evaluacion = $this->evaluaciones->capturar($this->evaluacion, $this->jefeUsuario, [
        'criterios' => ($this->criterios)(4),
        'filtros' => $filtros,
        'observaciones' => 'Marcó Sí en una pregunta filtro: no se renueva.',
    ]);

    expect((float) $evaluacion->calificacion)->toBe(36.0)
        ->and($evaluacion->recomienda_renovar)->toBeFalse();
});

test('el comentario del jefe es obligatorio si hay un criterio en 1 o 2, o un filtro en Sí', function () {
    expect(fn () => $this->evaluaciones->capturar($this->evaluacion, $this->jefeUsuario, [
        'criterios' => ($this->criterios)(2),
        'filtros' => ($this->filtros)(),
    ]))->toThrow(ValidationException::class);

    $filtros = ($this->filtros)(false);
    $filtros[0]['respuesta'] = true;
    expect(fn () => $this->evaluaciones->capturar($this->evaluacion, $this->jefeUsuario, [
        'criterios' => ($this->criterios)(4),
        'filtros' => $filtros,
    ]))->toThrow(ValidationException::class);

    // Con 4s y ningún filtro en Sí no hace falta comentario.
    $evaluacion = $this->evaluaciones->capturar($this->evaluacion, $this->jefeUsuario, [
        'criterios' => ($this->criterios)(4),
        'filtros' => ($this->filtros)(),
    ]);
    expect($evaluacion->observaciones)->toBeNull();
});

test('RH no puede forzar la renovación de una evaluación que no alcanza la regla: necesita excepción de Dirección General ya aprobada', function () {
    $this->evaluaciones->capturar($this->evaluacion, $this->jefeUsuario, [
        'criterios' => ($this->criterios)(2),
        'filtros' => ($this->filtros)(),
        'observaciones' => 'No alcanza el mínimo.',
    ]);

    expect(fn () => $this->evaluaciones->autorizar($this->evaluacion->refresh(), $this->rh, ['renovar' => true, 'comentario' => 'Quiero renovar de todos modos.']))
        ->toThrow(ValidationException::class);

    $excepciones = app(SolicitudExcepcionPeriodoPruebaService::class);
    $solicitud = $excepciones->crear($this->evaluacion->refresh(), $this->rh, ['motivo' => 'El bajo desempeño se debió a una contingencia ajena documentada.']);

    expect($solicitud->estado)->toBe(EstadoExcepcionDireccionGeneral::Pendiente);

    // Sigue sin poder mientras la excepción no esté APROBADA.
    expect(fn () => $this->evaluaciones->autorizar($this->evaluacion->refresh(), $this->rh, ['renovar' => true, 'comentario' => 'x']))
        ->toThrow(ValidationException::class);

    $excepciones->resolver($solicitud, $this->dg, true, 'Autorizado por contingencia documentada.');

    $evaluacion = $this->evaluaciones->autorizar($this->evaluacion->refresh(), $this->rh, ['renovar' => true, 'comentario' => 'Dirección General autorizó la excepción.']);

    expect($evaluacion->decision_renovar)->toBeTrue()
        ->and(ContratoLaboral::query()->where('contrato_anterior_id', $this->contrato->id)->where('tipo', TipoContratacion::Indeterminado->value)->exists())->toBeTrue();
});

test('Dirección General resuelve la excepción; Regional y Dirección Comercial nunca pueden', function () {
    $this->evaluaciones->capturar($this->evaluacion, $this->jefeUsuario, [
        'criterios' => ($this->criterios)(2),
        'filtros' => ($this->filtros)(),
        'observaciones' => 'No alcanza el mínimo.',
    ]);

    $excepciones = app(SolicitudExcepcionPeriodoPruebaService::class);
    $solicitud = $excepciones->crear($this->evaluacion->refresh(), $this->rh, ['motivo' => 'Motivo documentado.']);

    $regional = clUsuario('gerente_regional');
    $comercial = clUsuario('director_comercial');

    expect($regional->can('excepciones.autorizar'))->toBeFalse()
        ->and($comercial->can('excepciones.autorizar'))->toBeFalse()
        ->and($this->dg->can('excepciones.autorizar'))->toBeTrue();

    $resuelta = $excepciones->resolver($solicitud, $this->dg, false, 'No procede.');
    expect($resuelta->estado)->toBe(EstadoExcepcionDireccionGeneral::Rechazada);

    expect(fn () => $excepciones->resolver($resuelta, $this->dg, true))->toThrow(ValidationException::class);
});

test('legacy (version null): el histórico ya capturado con el formato libre nunca se recalcula con la regla oficial', function () {
    $legacy = EvaluacionPeriodoPrueba::factory()->create([
        'colaborador_id' => $this->colaborador->id,
        'contrato_laboral_id' => ContratoLaboral::factory()->create(['colaborador_id' => $this->colaborador->id])->id,
        'estado' => EstadoEvaluacionPrueba::Capturada,
        'criterios' => [['criterio' => 'Cobranza', 'calificacion' => 9, 'acredita' => true]],
        'calificacion' => 9,
        'resultado' => 'aprobado',
        'recomienda_renovar' => true,
        'version' => null,
    ]);

    $datos = $this->evaluaciones->aArray($legacy->refresh());

    expect($datos['version'])->toBeNull()
        ->and($datos['es_oficial'])->toBeFalse()
        ->and($datos['criterios'])->toBe([['criterio' => 'Cobranza', 'calificacion' => 9, 'acredita' => true]])
        ->and($legacy->refresh()->version)->toBeNull();

    // La regla canónica (ningún 1, ningún Sí, total >= 25) NUNCA se evalúa
    // sobre este histórico: solo aplica cuando version >= 2.
    expect((int) ($legacy->version ?? 1))->toBeLessThan(2);
});

test('aprobar dos veces no duplica el contrato indeterminado (idempotente, igual que el formato libre)', function () {
    $this->evaluaciones->capturar($this->evaluacion, $this->jefeUsuario, [
        'criterios' => ($this->criterios)(4),
        'filtros' => ($this->filtros)(),
    ]);

    $this->evaluaciones->autorizar($this->evaluacion->refresh(), $this->rh, ['renovar' => true]);

    expect(fn () => $this->evaluaciones->autorizar($this->evaluacion->refresh(), $this->rh, ['renovar' => true]))
        ->toThrow(ValidationException::class);

    expect(ContratoLaboral::query()->where('contrato_anterior_id', $this->contrato->id)->count())->toBe(1);
});
