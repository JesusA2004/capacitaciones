<?php

use App\Http\Controllers\Sistemas\PendientesAltaController;
use App\Http\Controllers\Sistemas\PendientesBajaController;
use Illuminate\Support\Facades\Route;

// Sistemas (docs/SISTEMAS_PENDIENTES_ALTA.md): bandeja «Pendientes de alta en
// sistemas» — quién entró a contratación y aún no tiene sus usuarios en las
// demás plataformas. Permisos sistemas.altas.ver / sistemas.altas.atender
// (AvisoAltaSistemasPolicy); reglas en PendientesAltaSistemasService.
Route::middleware(['auth', 'verified'])->prefix('sistemas/pendientes-alta')->name('sistemas.pendientes-alta.')->group(function () {
    Route::get('/', [PendientesAltaController::class, 'index'])->name('index');
    Route::get('{aviso}', [PendientesAltaController::class, 'show'])->name('show')->whereNumber('aviso');
    Route::post('{aviso}/atender', [PendientesAltaController::class, 'atender'])->name('atender')->whereNumber('aviso')->middleware('throttle:120,1');
    Route::post('{aviso}/reabrir', [PendientesAltaController::class, 'reabrir'])->name('reabrir')->whereNumber('aviso')->middleware('throttle:60,1');
    Route::post('{aviso}/reenviar', [PendientesAltaController::class, 'reenviar'])->name('reenviar')->whereNumber('aviso')->middleware('throttle:20,1');
});

// Bandeja «Pendientes de baja en sistemas»: los correos de baja a Sistemas
// (dar de baja accesos / baja concluida / baja cancelada) quedan pendientes
// hasta que Sistemas los marca atendidos. Mismos permisos que la de alta
// (AvisoBajaSistemasPolicy); reglas en PendientesBajaSistemasService.
Route::middleware(['auth', 'verified'])->prefix('sistemas/pendientes-baja')->name('sistemas.pendientes-baja.')->group(function () {
    Route::get('/', [PendientesBajaController::class, 'index'])->name('index');
    Route::get('{aviso}', [PendientesBajaController::class, 'show'])->name('show')->whereNumber('aviso');
    Route::post('{aviso}/atender', [PendientesBajaController::class, 'atender'])->name('atender')->whereNumber('aviso')->middleware('throttle:120,1');
    Route::post('{aviso}/reabrir', [PendientesBajaController::class, 'reabrir'])->name('reabrir')->whereNumber('aviso')->middleware('throttle:60,1');
    Route::post('{aviso}/reenviar', [PendientesBajaController::class, 'reenviar'])->name('reenviar')->whereNumber('aviso')->middleware('throttle:20,1');
});
