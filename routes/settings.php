<?php

use App\Http\Controllers\Settings\PersonalizacionController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // Sin DELETE settings/profile: nadie elimina su propia cuenta. La eliminación
    // definitiva es exclusiva de super_admin en Administración → Usuarios.

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/personalizacion', [PersonalizacionController::class, 'edit'])->name('personalizacion.edit');
    Route::patch('settings/personalizacion', [PersonalizacionController::class, 'update'])->name('personalizacion');
});
