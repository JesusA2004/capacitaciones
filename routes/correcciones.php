<?php

use App\Http\Controllers\Correcciones\CorreccionController;
use Illuminate\Support\Facades\Route;

// Sistema universal de correcciones (docs/CORRECCIONES.md). Responde JSON: el
// diálogo pide la vista previa antes de confirmar. Espejo móvil en routes/api.php.
Route::middleware(['auth', 'verified', 'throttle:60,1'])
    ->prefix('correcciones')
    ->name('correcciones.')
    ->group(function () {
        Route::get('colaboradores/{colaborador}/historial', [CorreccionController::class, 'historialColaborador'])->name('colaborador.historial');
        Route::get('{dominio}/{id}', [CorreccionController::class, 'mostrar'])->name('mostrar')->whereNumber('id');
        Route::post('{dominio}/{id}/vista-previa', [CorreccionController::class, 'vistaPrevia'])->name('vista-previa')->whereNumber('id');
        Route::post('{dominio}/{id}', [CorreccionController::class, 'aplicar'])->name('aplicar')->whereNumber('id');
        Route::get('{dominio}/{id}/historial', [CorreccionController::class, 'historial'])->name('historial')->whereNumber('id');
    });
