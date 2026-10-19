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
    // LEGACY (solo lectura): ver el escaneo firmado de responsivas antiguas.
    // El POST para SUBIR un escaneo firmado se retiró (P1-14): el flujo nuevo
    // no admite escaneos; la constancia de firma es «firmada físicamente».
    Route::get('responsivas/{responsiva}/firmada', [ResponsivaController::class, 'firmada'])->name('firmada');
    // Flujo vigente: firma en papel sin escaneo.
    Route::post('responsivas/{responsiva}/firmada-fisica', [ResponsivaController::class, 'marcarFirmadaFisicamente'])->name('marcar-firmada-fisica')->middleware('throttle:20,1');
    Route::post('responsivas/{responsiva}/devolucion', [ResponsivaController::class, 'devolucion'])->name('devolucion');
    Route::post('responsivas/{responsiva}/cancelar', [ResponsivaController::class, 'cancelar'])->name('cancelar');
});

/*
| Activos y responsivas (índice agregado, grupo «3 · Onboarding»): panorama
| DOCUMENTAL de todas las responsivas dentro del alcance. Las operaciones
| (firma física, devolución, cancelación) viven en las rutas per-persona de
| arriba; aquí solo se listan y se enlaza a la ficha del colaborador.
*/
Route::middleware(['auth', 'verified'])->prefix('rh')->group(function () {
    Route::get('activos-responsivas', [ResponsivaController::class, 'panorama'])->name('rh.activos-responsivas.index');
});
