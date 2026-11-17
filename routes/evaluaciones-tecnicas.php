<?php

use App\Http\Controllers\Portal\MisEvaluacionesTecnicasController;
use App\Http\Controllers\Rh\FormularioEvaluacionController;
use App\Http\Controllers\Seguimiento\ConfiguracionEvaluacionesTecnicasController;
use App\Http\Controllers\Seguimiento\EvaluacionesTecnicasController;
use Illuminate\Support\Facades\Route;

/*
| Evaluaciones técnicas por puesto («Seguimiento 15 / 30 / 60…», Fase 2,
| docs/EVALUACIONES_TECNICAS.md). Permisos evaluaciones_tecnicas.ver /
| aplicar / configurar (EvaluacionTecnicaPolicy). Contestar no exige el
| permiso del módulo: lo decide la Policy (el colaborador su examen, el jefe
| directo del organigrama la de su equipo).
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('seguimiento/evaluaciones-tecnicas')->name('evaluaciones-tecnicas.')->group(function () {
        Route::get('/', [EvaluacionesTecnicasController::class, 'index'])->name('index');
        Route::post('programar', [EvaluacionesTecnicasController::class, 'programar'])->name('programar')->middleware('throttle:6,1');
        Route::get('colaboradores/{colaborador}', [EvaluacionesTecnicasController::class, 'colaborador'])->name('colaborador')->whereNumber('colaborador');

        Route::get('configuracion', [ConfiguracionEvaluacionesTecnicasController::class, 'index'])->name('configuracion');
        Route::put('configuracion/puestos/{puesto}', [ConfiguracionEvaluacionesTecnicasController::class, 'guardarCalendario'])->name('calendario.guardar')->whereNumber('puesto');
        Route::post('configuracion/puestos/{puesto}/copiar', [ConfiguracionEvaluacionesTecnicasController::class, 'copiarCalendario'])->name('calendario.copiar')->whereNumber('puesto');

        Route::post('formularios', [ConfiguracionEvaluacionesTecnicasController::class, 'crearFormulario'])->name('formularios.store');
        Route::put('formularios/{plantilla}', [ConfiguracionEvaluacionesTecnicasController::class, 'actualizarFormulario'])->name('formularios.update')->whereNumber('plantilla');
        // Mismo constructor tipo Google Forms que inducción / periodo de prueba.
        Route::get('formularios/{plantilla}/editor', [FormularioEvaluacionController::class, 'editarTecnico'])->name('formularios.editar')->whereNumber('plantilla');
        Route::post('formularios/{plantilla}/borrador', [FormularioEvaluacionController::class, 'crearBorradorTecnico'])->name('formularios.borrador')->whereNumber('plantilla');

        Route::get('{evaluacion}', [EvaluacionesTecnicasController::class, 'show'])->name('show')->whereNumber('evaluacion');
        Route::get('{evaluacion}/contestar', [EvaluacionesTecnicasController::class, 'contestar'])->name('contestar')->whereNumber('evaluacion');
        Route::post('{evaluacion}/contestar', [EvaluacionesTecnicasController::class, 'responder'])->name('responder')->whereNumber('evaluacion')->middleware('throttle:20,1');
    });

    // Mi espacio: mis evaluaciones técnicas (y las de mi equipo por evaluar).
    Route::middleware('can:modo-colaborador')->group(function () {
        Route::get('mis-evaluaciones-tecnicas', [MisEvaluacionesTecnicasController::class, 'index'])->name('portal.evaluaciones-tecnicas');
    });
});
