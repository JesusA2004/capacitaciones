<?php

use App\Http\Controllers\Reclutamiento\AgendaController;
use Illuminate\Support\Facades\Route;

// Reclutamiento → Agenda de entrevistas (permisos agenda.*, alcance por sucursal).
Route::middleware(['auth', 'verified'])
    ->prefix('reclutamiento/agenda')
    ->name('agenda.')
    ->group(function () {
        Route::get('/', [AgendaController::class, 'index'])->name('index');
        Route::post('/', [AgendaController::class, 'store'])->name('store')->middleware('throttle:60,1');
        Route::get('empalmes', [AgendaController::class, 'empalmes'])->name('empalmes');
        Route::get('exportar/excel', [AgendaController::class, 'exportarExcel'])->name('exportar.excel');
        Route::get('exportar/pdf', [AgendaController::class, 'exportarPdf'])->name('exportar.pdf');
        Route::post('{evento}/reprogramar', [AgendaController::class, 'reprogramar'])->name('reprogramar');
        Route::post('{evento}/cancelar', [AgendaController::class, 'cancelar'])->name('cancelar');
        Route::post('{evento}/no-asistio', [AgendaController::class, 'noAsistio'])->name('no-asistio');
        Route::post('{evento}/resultado-entrevista', [AgendaController::class, 'resultadoEntrevista'])->name('resultado-entrevista');
        Route::post('{evento}/resultado-socioeconomico', [AgendaController::class, 'resultadoSocioeconomico'])->name('resultado-socioeconomico');
        Route::get('{evento}/cv', [AgendaController::class, 'cv'])->name('cv');
    });
