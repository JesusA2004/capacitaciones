<?php

use App\Http\Controllers\Colaborador\PortalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('mi-portal', [PortalController::class, 'index'])->name('portal.index');
    Route::get('mi-perfil', [PortalController::class, 'perfil'])->name('portal.perfil');
});
