<?php

use App\Enums\ClaveFormularioEvaluacion;
use App\Services\Evaluaciones\FormularioEvaluacionService;
use Tests\TestCase;

// Boota la app (para config()/contenedor) sin tocar la base de datos.
uses(TestCase::class);

/*
| FO-RH-EPP-001 (periodo de prueba): catálogo oficial de 9 criterios con las
| descripciones de nivel 4/3/2/1, las 5 preguntas filtro y el relleno SEGURO
| de niveles para borradores. Todo sin DB (config + función pura).
*/

test('la evaluación de periodo de prueba la contesta el evaluador, nunca es autoevaluación', function () {
    expect(ClaveFormularioEvaluacion::PeriodoPrueba->quienContesta())->toBe('evaluador')
        ->and(mb_strtolower(ClaveFormularioEvaluacion::PeriodoPrueba->descripcion()))->not->toContain('autoevalua')
        // Inducción sí la contesta el colaborador.
        ->and(ClaveFormularioEvaluacion::Induccion->quienContesta())->toBe('colaborador');
});

test('el catálogo oficial tiene exactamente los 9 criterios en orden', function () {
    $claves = collect(config('contratos.evaluacion_periodo_prueba_v2.criterios'))->pluck('clave')->all();

    expect($claves)->toBe([
        'conoce_trabajo',
        'cumple_metas',
        'sin_errores',
        'asistencia_puntualidad',
        'cumplimiento_politicas',
        'imagen_uniforme',
        'trato_cliente',
        'trabajo_equipo',
        'aprendizaje_iniciativa',
    ]);
});

test('cada uno de los 9 criterios tiene descripción oficial en los cuatro niveles 4/3/2/1', function () {
    foreach (config('contratos.evaluacion_periodo_prueba_v2.criterios') as $criterio) {
        foreach (['4', '3', '2', '1'] as $nivel) {
            expect(trim((string) ($criterio['niveles'][$nivel] ?? '')))
                ->not->toBe('', "El criterio {$criterio['clave']} no tiene descripción para el nivel {$nivel}.");
        }
    }
});

test('solo «Cumple sus metas» captura Meta/Logró/% y hay 5 preguntas filtro', function () {
    $conMeta = collect(config('contratos.evaluacion_periodo_prueba_v2.criterios'))
        ->filter(fn (array $c): bool => ! empty($c['captura_meta']))
        ->pluck('clave')
        ->all();

    expect($conMeta)->toBe(['cumple_metas'])
        ->and(config('contratos.evaluacion_periodo_prueba_v2.criterios'))->toHaveCount(9)
        ->and(config('contratos.evaluacion_periodo_prueba_v2.filtros'))->toHaveCount(5)
        ->and((int) config('contratos.evaluacion_periodo_prueba_v2.total_minimo_renovar'))->toBe(25)
        ->and((int) config('contratos.evaluacion_periodo_prueba_v2.total_maximo'))->toBe(36);
});

test('el relleno de niveles completa lo faltante sin pisar lo que RH editó, deja criterios personalizados intactos y es idempotente', function () {
    $servicio = app(FormularioEvaluacionService::class);

    $definicion = [
        'criterios' => [
            ['clave' => 'conoce_trabajo', 'etiqueta' => 'Conoce y hace bien su trabajo', 'niveles' => ['4' => '', '3' => '', '2' => '', '1' => '']],
            ['clave' => 'cumple_metas', 'etiqueta' => 'Cumple sus metas', 'niveles' => ['4' => 'TEXTO QUE RH YA ESCRIBIÓ', '3' => '', '2' => '', '1' => '']],
            ['clave' => 'criterio_extra_rh', 'etiqueta' => 'Criterio agregado por RH', 'niveles' => ['4' => '', '3' => '', '2' => '', '1' => '']],
        ],
        'filtros' => [],
    ];

    $out = $servicio->rellenarCriteriosOficialesPeriodoPrueba($definicion);

    expect($out['criterios'][0]['niveles']['4'])->toBe('Lo hace todo solo y sin errores; puede enseñar a otros.')
        // No pisa el texto de RH.
        ->and($out['criterios'][1]['niveles']['4'])->toBe('TEXTO QUE RH YA ESCRIBIÓ')
        // Rellena los que faltaban.
        ->and($out['criterios'][1]['niveles']['3'])->toBe('Logró del 90 % al 99 %.')
        // Habilita Meta/% en el renglón oficial.
        ->and($out['criterios'][1]['captura_meta'])->toBeTrue()
        // No toca un criterio personalizado (clave fuera del catálogo).
        ->and($out['criterios'][2]['niveles']['4'])->toBe('')
        // Idempotente.
        ->and($servicio->rellenarCriteriosOficialesPeriodoPrueba($out))->toBe($out);
});
