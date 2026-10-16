<?php

use App\Http\Controllers\Rh\ResponsivaController;
use Illuminate\Support\Facades\Route;

/*
| Responsivas de equipo (docs/responsiva): el gerente o RH las genera desde
| la ficha del colaborador; se imprimen y se firman en papel (sin subir
| escaneo). Autorización en App\Policies\ResponsivaPolicy.
*/

Route::middleware(['auth', 'verified'])->prefix('rh')->name('rh.responsivas.')->group(function () {
    Route::get('colaboradores/{colaborador}/responsivas', [ResponsivaController::class, 'index'])->name('index');
    Route::post('colaboradores/{colaborador}/responsivas/vista-previa', [ResponsivaController::class, 'vistaPrevia'])->name('vista-previa')->middleware('throttle:20,1');
    Route::post('colaboradores/{colaborador}/responsivas', [ResponsivaController::class, 'store'])->name('store')->middleware('throttle:20,1');
    Route::get('responsivas/{responsiva}/pdf', [ResponsivaController::class, 'pdf'])->name('pdf');
    // LEGACY (solo lectura): sirve el escaneo firmado de registros antiguos.
    Route::get('responsivas/{responsiva}/firmada', [ResponsivaController::class, 'firmada'])->name('firmada');
    // Flujo vigente: firma en papel sin escaneo.
    Route::post('responsivas/{responsiva}/firmada-fisica', [ResponsivaController::class, 'marcarFirmadaFisicamente'])->name('marcar-firmada-fisica')->middleware('throttle:20,1');
    Route::post('responsivas/{responsiva}/devolucion', [ResponsivaController::class, 'devolucion'])->name('devolucion');
    Route::post('responsivas/{responsiva}/cancelar', [ResponsivaController::class, 'cancelar'])->name('cancelar');
});
