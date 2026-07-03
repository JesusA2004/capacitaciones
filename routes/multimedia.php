<?php

use App\Http\Controllers\Multimedia\RecursoMultimediaController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('multimedia')
    ->name('multimedia.')
    ->group(function () {
        Route::get('/', [RecursoMultimediaController::class, 'index'])->name('index');
        Route::post('/', [RecursoMultimediaController::class, 'store'])->name('store');
        Route::get('{recurso}/estado', [RecursoMultimediaController::class, 'estado'])->name('estado');
        Route::delete('{recurso}', [RecursoMultimediaController::class, 'destroy'])->name('destroy');
    });
