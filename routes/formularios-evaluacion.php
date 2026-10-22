<?php

use App\Http\Controllers\Rh\FormularioEvaluacionController;
use Illuminate\Support\Facades\Route;

/*
| Formularios de evaluación (RH): configurar como en Google Forms la
| evaluación de inducción (la contesta el colaborador) y la de capacitación
| inicial / periodo de prueba (la contesta el jefe directo), con diseño,
| vista previa y versiones. Permiso formularios_evaluacion.administrar
| (FormularioEvaluacionPolicy + FormularioEvaluacionService).
*/

Route::middleware(['auth', 'verified'])->prefix('rh/formularios-evaluacion')->name('rh.formularios-evaluacion.')->group(function () {
    Route::get('/', [FormularioEvaluacionController::class, 'index'])->name('index');
    Route::get('versiones/{formulario}', [FormularioEvaluacionController::class, 'version'])->name('version');
    Route::put('borradores/{formulario}', [FormularioEvaluacionController::class, 'guardar'])->name('guardar');
    Route::post('borradores/{formulario}/publicar', [FormularioEvaluacionController::class, 'publicar'])->name('publicar');
    Route::delete('borradores/{formulario}', [FormularioEvaluacionController::class, 'descartar'])->name('descartar');
    Route::post('borradores/{formulario}/banner', [FormularioEvaluacionController::class, 'subirBanner'])->name('banner.subir');
    Route::delete('borradores/{formulario}/banner', [FormularioEvaluacionController::class, 'quitarBanner'])->name('banner.quitar');
    Route::get('{clave}', [FormularioEvaluacionController::class, 'editar'])->name('editar')->whereIn('clave', ['induccion', 'periodo_prueba']);
    Route::post('{clave}/borrador', [FormularioEvaluacionController::class, 'crearBorrador'])->name('borrador')->whereIn('clave', ['induccion', 'periodo_prueba']);
});
