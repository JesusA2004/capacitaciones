<?php

use App\Http\Controllers\Rh\RecepcionDocumentalController;
use Illuminate\Support\Facades\Route;

// Recepción documental RH (docs/RECEPCION_DOCUMENTAL_RH.md): control
// mensual de originales firmados recibidos por paquetería. Permisos
// recepcion_documental.* en las Policies; mismo servicio que la API móvil.
Route::middleware(['auth', 'verified'])->prefix('rh/recepcion-documental')->name('rh.recepcion-documental.')->group(function () {
    Route::get('/', [RecepcionDocumentalController::class, 'index'])->name('index');
    Route::post('/', [RecepcionDocumentalController::class, 'generar'])->name('generar')->middleware('throttle:20,1');

    Route::patch('renglones/{renglon}', [RecepcionDocumentalController::class, 'marcar'])->name('renglones.marcar')->whereNumber('renglon')->middleware('throttle:240,1');
    Route::post('renglones/{renglon}/evidencia', [RecepcionDocumentalController::class, 'evidencia'])->name('renglones.evidencia')->whereNumber('renglon')->middleware('throttle:30,1');
    Route::get('renglones/{renglon}/evidencia', [RecepcionDocumentalController::class, 'verEvidencia'])->name('renglones.evidencia.ver')->whereNumber('renglon');
    Route::get('renglones/{renglon}/historial', [RecepcionDocumentalController::class, 'historial'])->name('renglones.historial')->whereNumber('renglon');

    Route::get('{corte}', [RecepcionDocumentalController::class, 'show'])->name('show')->whereNumber('corte');
    Route::post('{corte}/materializar', [RecepcionDocumentalController::class, 'materializar'])->name('materializar')->whereNumber('corte')->middleware('throttle:20,1');
    Route::post('{corte}/cerrar', [RecepcionDocumentalController::class, 'cerrar'])->name('cerrar')->whereNumber('corte');
    Route::post('{corte}/reabrir', [RecepcionDocumentalController::class, 'reabrir'])->name('reabrir')->whereNumber('corte');
    Route::get('{corte}/pdf', [RecepcionDocumentalController::class, 'pdf'])->name('pdf')->whereNumber('corte')->middleware('throttle:20,1');
    Route::get('{corte}/pdfs/{pdf}', [RecepcionDocumentalController::class, 'verPdf'])->name('pdfs.show')->whereNumber('corte')->whereNumber('pdf');
});
