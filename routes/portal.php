<?php

use App\Http\Controllers\Colaborador\PortalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    // La autorización real vive aquí (permisos personales de "modo
    // colaborador", ver App\Services\Navigation\NavigationService y
    // database/seeders/RolesYPermisosSeeder::PERMISOS_PERSONALES): un
    // usuario operativo puro (super_admin, rh_admin, etc.) recibe 403 tanto
    // por URL directa como si el sidebar llegara a mostrar el enlace.
    Route::get('mi-portal', [PortalController::class, 'index'])->name('portal.index')->middleware('can:portal.ver');
    Route::get('mi-perfil', [PortalController::class, 'perfil'])->name('portal.perfil')->middleware('can:portal.perfil.ver');
    Route::get('mis-notificaciones', [PortalController::class, 'notificaciones'])->name('portal.notificaciones')->middleware('can:portal.notificaciones.ver');
});
