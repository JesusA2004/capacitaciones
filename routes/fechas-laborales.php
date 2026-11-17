<?php

use App\Http\Controllers\Rh\FechasLaboralesController;
use Illuminate\Support\Facades\Route;

/*
| Fechas laborales del colaborador (docs/FECHAS_LABORALES.md): sección del
| expediente (JSON) y captura masiva para RH. La escritura va por el sistema
| de correcciones (dominio fechas_laborales, routes/correcciones.php).
*/
Route::middleware(['auth', 'verified'])->prefix('rh')->name('rh.')->group(function () {
    Route::get('expedientes/{colaborador}/fechas-laborales', [FechasLaboralesController::class, 'show'])
        ->name('expedientes.fechas-laborales')->whereNumber('colaborador');
    Route::get('fechas-laborales', [FechasLaboralesController::class, 'index'])->name('fechas-laborales.index');
    Route::post('fechas-laborales/lote', [FechasLaboralesController::class, 'lote'])
        ->name('fechas-laborales.lote')->middleware('throttle:20,1');
});
