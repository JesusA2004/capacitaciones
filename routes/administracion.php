<?php

use App\Http\Controllers\Administracion\AppReleaseController;
use App\Http\Controllers\Administracion\DepartamentoController;
use App\Http\Controllers\Administracion\EmpresaController;
use App\Http\Controllers\Administracion\JerarquiaPuestoController;
use App\Http\Controllers\Administracion\MatrizComercialController;
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
            Route::get('{sucursal}', [SucursalController::class, 'show'])->name('show');
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

        Route::prefix('jerarquia-puestos')->name('jerarquia-puestos.')->group(function () {
            Route::get('/', [JerarquiaPuestoController::class, 'index'])->name('index');
            Route::get('{puesto}/historial', [JerarquiaPuestoController::class, 'historial'])->name('historial');
            Route::put('{puesto}', [JerarquiaPuestoController::class, 'actualizar'])->name('actualizar');
        });

        // Matriz comercial / territorial: vista B del Organigrama, árbol
        // distinto al de jerarquía de puestos (ver docs/HEADCOUNT_Y_VACANTES.md).
        Route::prefix('matriz-comercial')->name('matriz-comercial.')->group(function () {
            Route::get('/', [MatrizComercialController::class, 'index'])->name('index');
            Route::put('{nodo}/responsable', [MatrizComercialController::class, 'asignarResponsable'])->name('responsable');
            Route::post('{nodo}/apoyo', [MatrizComercialController::class, 'agregarApoyo'])->name('apoyo.agregar');
            Route::delete('{nodo}/apoyo', [MatrizComercialController::class, 'quitarApoyo'])->name('apoyo.quitar');
        });

        // Solo cuenta de acceso (correo, roles, bloqueo) — la baja/reactivación
        // laboral vive en rh.expedientes.dar-de-baja/reactivar (Colaborador,
        // funciona con o sin cuenta), ver App\Http\Controllers\Rh\ExpedienteController.
        // Listado global de cuentas (index) además de la gestión contextual
        // desde la pestaña «Cuenta» del expediente — ver docs/ROLES_Y_NAVEGACION.md.
        Route::prefix('usuarios')->name('usuarios.')->group(function () {
            Route::get('/', [UsuarioController::class, 'index'])->name('index');
            Route::post('/', [UsuarioController::class, 'store'])->name('store');
            Route::put('{usuario}', [UsuarioController::class, 'update'])->name('update');
            // Revocar/restablecer acceso: bloquea/desbloquea el login sin
            // tocar estatus laboral/headcount, ver UsuarioController::revocarAcceso().
            Route::post('{usuario}/revocar-acceso', [UsuarioController::class, 'revocarAcceso'])->name('revocar-acceso');
            Route::post('{usuario}/restablecer-acceso', [UsuarioController::class, 'restablecerAcceso'])->name('restablecer-acceso');
            // Devuelve JSON (no es una visita Inertia normal): el frontend
            // necesita la contraseña en texto plano para mostrarla una sola
            // vez, ver UsuarioController::establecerPassword().
            Route::post('{usuario}/establecer-password', [UsuarioController::class, 'establecerPassword'])->name('establecer-password');
            // Envía por correo la contraseña que acaba de devolver
            // establecer-password (el admin la pega en el body, no se
            // regenera aquí), ver UsuarioController::enviarPasswordCorreo().
            Route::post('{usuario}/enviar-password-correo', [UsuarioController::class, 'enviarPasswordCorreo'])->name('enviar-password-correo');
        });

        // URL en español (app-versiones) tal como la pidió el encargo; nombre
        // de ruta en inglés (app-releases) para que coincida con el modelo
        // MobileAppRelease y los servicios App\Services\AppReleases\*.
        Route::prefix('app-versiones')->name('app-releases.')->group(function () {
            Route::get('/', [AppReleaseController::class, 'index'])->name('index');
            Route::post('/', [AppReleaseController::class, 'store'])->name('store');
            Route::get('{release}', [AppReleaseController::class, 'show'])->name('show');
            Route::get('{release}/descargar', [AppReleaseController::class, 'descargar'])->name('descargar');
            Route::post('{release}/publicar', [AppReleaseController::class, 'publicar'])->name('publicar');
            Route::post('{release}/despublicar', [AppReleaseController::class, 'despublicar'])->name('despublicar');
            Route::delete('{release}', [AppReleaseController::class, 'destroy'])->name('destroy');
        });
    });
