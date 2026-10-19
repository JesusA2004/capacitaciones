<?php

use App\Http\Controllers\Rh\DisenoDocumentoController;
use App\Http\Controllers\Rh\DocumentoAdministrativoController;
use App\Http\Controllers\Rh\DocumentoMaestroController;
use App\Http\Controllers\Rh\DocumentoProcesoController;
use Illuminate\Support\Facades\Route;

/*
| Motor documental (docs/MOTOR_DOCUMENTOS_MAESTROS.md):
|  - "Documentos del proceso": en contexto (ficha, cierre, solicitud,
|    préstamo, evaluación, entrega de activo). JSON, mismo controlador que la
|    API móvil.
|  - "Documentos maestros": administración (cargar/versionar/activar/probar).
| La autorización real vive en el Service (alcance + permiso por documento)
| y en GeneratedDocumentPolicy para el flujo físico.
*/
Route::middleware(['auth', 'verified'])->prefix('rh')->name('rh.')->group(function () {
    Route::prefix('documentos-proceso')->name('documentos-proceso.')->group(function () {
        Route::get('colaborador/{colaborador}', [DocumentoProcesoController::class, 'colaborador'])->name('colaborador')->withTrashed();
        // Buscador de testigos (negativa a firmar y firma física): activos del
        // alcance de quien opera, los del centro de trabajo del afectado primero.
        Route::get('testigos', [DocumentoProcesoController::class, 'buscarTestigos'])->name('testigos')->middleware('throttle:60,1');
        Route::get('{tipo}/{id}', [DocumentoProcesoController::class, 'show'])->name('show')->whereIn('tipo', ['contrato', 'cierre', 'solicitud', 'prestamo', 'evaluacion', 'entrega_activo', 'acta', 'colaborador'])->whereNumber('id');
        Route::post('{tipo}/{id}/generar', [DocumentoProcesoController::class, 'generar'])->name('generar')->whereIn('tipo', ['contrato', 'cierre', 'solicitud', 'prestamo', 'evaluacion', 'entrega_activo', 'acta', 'colaborador'])->whereNumber('id')->middleware('throttle:30,1');
        Route::post('{tipo}/{id}/paquete', [DocumentoProcesoController::class, 'paquete'])->name('paquete')->whereIn('tipo', ['contrato', 'cierre', 'prestamo'])->whereNumber('id')->middleware('throttle:10,1');
        Route::get('documento/{documento}/word', [DocumentoProcesoController::class, 'word'])->name('word');
        // 'envio', 'recepcion' y 'escaneo' son pasos de COMPATIBILIDAD LEGACY:
        // el flujo nuevo concluye en 'firma-fisica' y FlujoDocumentalService los
        // rechaza sobre documentos nuevos (ver features.documentos_flujo_legacy).
        Route::post('documento/{documento}/{accion}', [DocumentoProcesoController::class, 'operar'])->name('operar')->whereIn('accion', ['imprimir', 'firma-fisica', 'envio', 'recepcion', 'escaneo', 'archivar']);
    });

    Route::prefix('cierres/{cierre}/procedimiento')->name('cierres.procedimiento.')->group(function () {
        Route::post('negativa', [DocumentoProcesoController::class, 'negativa'])->name('negativa');
        Route::post('testigos', [DocumentoProcesoController::class, 'testigos'])->name('testigos');
        Route::post('etapa', [DocumentoProcesoController::class, 'etapa'])->name('etapa');
    });

    Route::prefix('documentos-maestros')->name('documentos-maestros.')->group(function () {
        Route::get('/', [DocumentoMaestroController::class, 'index'])->name('index');
        Route::get('cobertura', [DocumentoMaestroController::class, 'cobertura'])->name('cobertura');
        // Diseño de página y biblioteca de fondos.
        Route::prefix('fondos')->name('fondos.')->group(function () {
            Route::get('/', [DisenoDocumentoController::class, 'fondos'])->name('index');
            Route::post('/', [DisenoDocumentoController::class, 'store'])->name('store');
            Route::put('{fondo}', [DisenoDocumentoController::class, 'update'])->name('update');
            Route::post('{fondo}/reemplazar', [DisenoDocumentoController::class, 'reemplazar'])->name('reemplazar');
            Route::delete('{fondo}', [DisenoDocumentoController::class, 'destroy'])->name('destroy');
            Route::get('{fondo}/imagen', [DisenoDocumentoController::class, 'imagen'])->name('imagen');
        });
        // Documentos administrativos HTML (recibo de nómina, finiquito,
        // comprobante, constancia): diseño versionado + vista previa real.
        Route::prefix('administrativos')->name('administrativos.')->group(function () {
            Route::get('/', [DocumentoAdministrativoController::class, 'index'])->name('index');
            Route::get('{familia}', [DocumentoAdministrativoController::class, 'editar'])->name('editar');
            Route::post('{familia}/borrador', [DocumentoAdministrativoController::class, 'borrador'])->name('borrador');
            Route::get('{familia}/vista-previa', [DocumentoAdministrativoController::class, 'vistaPrevia'])->name('vista-previa')->middleware('throttle:30,1');
            Route::put('version/{plantilla}', [DocumentoAdministrativoController::class, 'guardar'])->name('guardar');
            Route::post('version/{plantilla}/activar', [DocumentoAdministrativoController::class, 'activar'])->name('activar');
            Route::delete('version/{plantilla}', [DocumentoAdministrativoController::class, 'descartar'])->name('descartar');
        });
        Route::get('diseno/{familia}', [DisenoDocumentoController::class, 'show'])->name('diseno.show')->where('familia', '[a-z0-9_.]+');
        Route::put('diseno/{familia}', [DisenoDocumentoController::class, 'updateFamilia'])->name('diseno.update')->where('familia', '[a-z0-9_.]+');
        Route::put('cobertura/puestos/{puesto}', [DocumentoMaestroController::class, 'decidirPuesto'])->name('cobertura.puesto');
        Route::get('colaboradores', [DocumentoMaestroController::class, 'buscarColaboradores'])->name('colaboradores')->middleware('throttle:60,1');
        Route::post('revalidar-pendientes', [DocumentoMaestroController::class, 'revalidarPendientes'])->name('revalidar-pendientes')->middleware('throttle:5,1');
        // Editor visual no-code: nuevo formato, espacios, datos largos, copia.
        Route::post('nuevo', [DocumentoMaestroController::class, 'nuevo'])->name('nuevo')->middleware('throttle:10,1');
        Route::post('revisar-fuentes', [DocumentoMaestroController::class, 'revisarFuentes'])->name('revisar-fuentes')->middleware('throttle:5,1');
        Route::get('{master}/editor', [DocumentoMaestroController::class, 'editor'])->name('editor')->whereNumber('master');
        Route::put('{master}/campos', [DocumentoMaestroController::class, 'guardarCampos'])->name('campos')->whereNumber('master')->middleware('throttle:60,1');
        Route::post('{master}/detectar', [DocumentoMaestroController::class, 'detectar'])->name('detectar')->whereNumber('master')->middleware('throttle:10,1');
        Route::post('{master}/marcas', [DocumentoMaestroController::class, 'marcas'])->name('marcas')->whereNumber('master')->middleware('throttle:10,1');
        Route::post('{master}/datos-largos', [DocumentoMaestroController::class, 'datosLargos'])->name('datos-largos')->whereNumber('master')->middleware('throttle:10,1');
        Route::post('{master}/copiar-version', [DocumentoMaestroController::class, 'copiarVersion'])->name('copiar-version')->whereNumber('master')->middleware('throttle:10,1');
        Route::get('{master}', [DocumentoMaestroController::class, 'show'])->name('show')->whereNumber('master');
        Route::post('familia/{familia}/versiones', [DocumentoMaestroController::class, 'cargarVersion'])->name('versiones.store')->where('familia', '[a-z0-9_.]+')->middleware('throttle:10,1');
        Route::post('{master}/validar-diseno', [DocumentoMaestroController::class, 'validarDiseno'])->name('validar-diseno')->whereNumber('master')->middleware('throttle:10,1');
        Route::post('{master}/activar', [DocumentoMaestroController::class, 'activar'])->name('activar')->whereNumber('master');
        Route::post('{master}/desactivar', [DocumentoMaestroController::class, 'desactivar'])->name('desactivar')->whereNumber('master');
        Route::post('{master}/probar', [DocumentoMaestroController::class, 'probar'])->name('probar')->whereNumber('master')->middleware('throttle:20,1');
        Route::get('{master}/prueba/{token}', [DocumentoMaestroController::class, 'pdfPrueba'])->name('prueba')->whereNumber('master')->whereUuid('token');
        Route::get('{master}/original', [DocumentoMaestroController::class, 'original'])->name('original')->whereNumber('master');
        Route::get('{master}/original-pdf', [DocumentoMaestroController::class, 'originalPdf'])->name('original-pdf')->whereNumber('master');
        Route::get('{master}/limpio-pdf', [DocumentoMaestroController::class, 'limpioPdf'])->name('limpio-pdf')->whereNumber('master');
    });
});
