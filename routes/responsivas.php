<?php

use App\Http\Controllers\Rh\ResponsivaController;
use Illuminate\Support\Facades\Route;

/*
| Responsivas de equipo (docs/responsiva): el gerente o RH las genera desde
| la ficha del colaborador; se imprimen, se firman y se sube el escaneo.
| Autorización en App\Policies\ResponsivaPolicy.
*/

Route::middleware(['auth', 'verified'])->prefix('rh')->name('rh.responsivas.')->group(function () {
    Route::get('colaboradores/{colaborador}/responsivas', [ResponsivaController::class, 'index'])->name('index');
    Route::post('colaboradores/{colaborador}/responsivas/vista-previa', [ResponsivaController::class, 'vistaPrevia'])->name('vista-previa')->middleware('throttle:20,1');
    Route::post('colaboradores/{colaborador}/responsivas', [ResponsivaController::class, 'store'])->name('store')->middleware('throttle:20,1');
    Route::get('responsivas/{responsiva}/pdf', [ResponsivaController::class, 'pdf'])->name('pdf');
    Route::get('responsivas/{responsiva}/firmada', [ResponsivaController::class, 'firmada'])->name('firmada');
    // Flujo vigente: firma en papel sin escaneo.
    Route::post('responsivas/{responsiva}/firmada-fisica', [ResponsivaController::class, 'marcarFirmadaFisicamente'])->name('marcar-firmada-fisica')->middleware('throttle:20,1');
    // LEGACY: subir el escaneo firmado (registros antiguos).
    Route::post('responsivas/{responsiva}/firmada', [ResponsivaController::class, 'subirFirmada'])->name('subir-firmada')->middleware('throttle:20,1');
    Route::post('responsivas/{responsiva}/devolucion', [ResponsivaController::class, 'devolucion'])->name('devolucion');
    Route::post('responsivas/{responsiva}/cancelar', [ResponsivaController::class, 'cancelar'])->name('cancelar');
});
