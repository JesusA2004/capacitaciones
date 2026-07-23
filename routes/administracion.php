<?php

use App\Http\Controllers\Administracion\DepartamentoController;
use App\Http\Controllers\Administracion\EmpresaController;
use App\Http\Controllers\Administracion\PuestoController;
use App\Http\Controllers\Administracion\RolController;
use App\Http\Controllers\Administracion\SucursalController;
use App\Http\Controllers\Administracion\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('administracion')
    ->name('administracion.')
    ->group(function () {
        Route::prefix('empresas')->name('empresas.')->group(function () {
            Route::get('/', [EmpresaController::class, 'index'])->name('index');
            Route::post('/', [EmpresaController::class, 'store'])->name('store');
            Route::post('{empresa}', [EmpresaController::class, 'update'])->name('update');
            Route::delete('{empresa}', [EmpresaController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('roles')->name('roles.')->group(function () {
            Route::get('/', [RolController::class, 'index'])->name('index');
            Route::post('/', [RolController::class, 'store'])->name('store');
            Route::put('{rol}', [RolController::class, 'update'])->name('update');
            Route::delete('{rol}', [RolController::class, 'destroy'])->name('destroy');
            Route::post('{rol}/clonar', [RolController::class, 'clonar'])->name('clonar');
        });

        Route::prefix('sucursales')->name('sucursales.')->group(function () {
            Route::get('/', [SucursalController::class, 'index'])->name('index');
            Route::post('/', [SucursalController::class, 'store'])->name('store');
            Route::put('{sucursal}', [SucursalController::class, 'update'])->name('update');
            Route::delete('{sucursal}', [SucursalController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('departamentos')->name('departamentos.')->group(function () {
            Route::get('/', [DepartamentoController::class, 'index'])->name('index');
            Route::post('/', [DepartamentoController::class, 'store'])->name('store');
            Route::put('{departamento}', [DepartamentoController::class, 'update'])->name('update');
            Route::delete('{departamento}', [DepartamentoController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('puestos')->name('puestos.')->group(function () {
            Route::get('/', [PuestoController::class, 'index'])->name('index');
            Route::post('/', [PuestoController::class, 'store'])->name('store');
            Route::put('{puesto}', [PuestoController::class, 'update'])->name('update');
            Route::delete('{puesto}', [PuestoController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('usuarios')->name('usuarios.')->group(function () {
            Route::get('/', [UsuarioController::class, 'index'])->name('index');
            Route::post('/', [UsuarioController::class, 'store'])->name('store');
            Route::put('{usuario}', [UsuarioController::class, 'update'])->name('update');
            Route::delete('{usuario}', [UsuarioController::class, 'destroy'])->name('destroy');
        });
    });
