<?php

use App\Http\Controllers\Api\V1\AppConfigController;
use App\Http\Controllers\Api\V1\AppReleaseController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ColaboradorController;
use App\Http\Controllers\Api\V1\ColaboradorCumpleanosController;
use App\Http\Controllers\Api\V1\DispositivoController;
use App\Http\Controllers\Api\V1\IncorporacionController;
use App\Http\Controllers\Api\V1\IncorporacionInvitacionController;
use App\Http\Controllers\Api\V1\MobileBootstrapController;
use App\Http\Controllers\Api\V1\NotificacionController;
use App\Http\Controllers\Api\V1\Rh\ColaboradorController as RhColaboradorController;
use App\Http\Controllers\Api\V1\Rh\CumpleanosController as RhCumpleanosController;
use App\Http\Controllers\Api\V1\Rh\DashboardController as RhDashboardController;
use App\Http\Controllers\Api\V1\Rh\DocumentoController as RhDocumentoController;
use App\Http\Controllers\Api\V1\Rh\ExpedienteController as RhExpedienteController;
use App\Http\Controllers\Api\V1\Rh\FormatoController as RhFormatoController;
use App\Http\Controllers\Api\V1\Rh\IncorporacionController as RhIncorporacionController;
use App\Http\Controllers\Api\V1\Rh\JerarquiaPuestoController as RhJerarquiaPuestoController;
use App\Http\Controllers\Api\V1\Rh\PendienteController as RhPendienteController;
use App\Http\Controllers\Api\V1\Rh\SolicitudController as RhSolicitudController;
use App\Http\Controllers\Api\V1\Rh\VacacionController as RhVacacionController;
use App\Http\Controllers\Api\V1\Rh\VacanteController as RhVacanteController;
use App\Http\Controllers\Api\V1\SolicitudController;
use App\Http\Controllers\Api\V1\VacacionesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — app móvil (colaboradores, RH, aprobadores)
|--------------------------------------------------------------------------
|
| Autenticación por token personal (Laravel Sanctum), sin cookies ni CSRF:
| cada dispositivo obtiene su propio token en /api/v1/login y lo manda como
| "Authorization: Bearer <token>" en cada request subsecuente. Ver
| docs/API_MOVIL.md y docs/RH_MOBILE_API.md.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('login');

    // Publica (sin auth:sanctum): la app la consulta antes de iniciar sesion
    // para saber si debe forzar actualizacion o mostrar mantenimiento.
    Route::get('app/config', AppConfigController::class)->name('app.config');

    // Publica (sin auth:sanctum): version disponible para descarga directa
    // mientras la app no este en Play Store (ver docs/APP_RELEASES.md).
    Route::prefix('app/releases')->name('app.releases.')->group(function () {
        Route::get('latest', [AppReleaseController::class, 'latest'])->name('latest');
        Route::get('/', [AppReleaseController::class, 'index'])->name('index');
    });

    // Publico (sin auth:sanctum, sin sesion web): el token del QR es la
    // unica puerta de entrada, validado en cada accion. Un colaborador no
    // puede registrarse libremente, solo con una invitacion activa que RH
    // genero antes desde el Portal RH (ver
    // App\Services\Incorporacion\IncorporacionInvitacionService y
    // docs/API_MOVIL.md, "Registro por QR temporal"). Throttle propio: son
    // requests anonimas, no cubiertas por el limite por-usuario del resto
    // de la API.
    Route::prefix('incorporacion/invitaciones/{token}')
        ->name('incorporacion.invitaciones.')
        ->middleware('throttle:30,1')
        ->group(function () {
            Route::get('validar', [IncorporacionInvitacionController::class, 'validar'])->name('validar');
            Route::get('fases', [IncorporacionInvitacionController::class, 'fases'])->name('fases');
            Route::post('registrar', [IncorporacionInvitacionController::class, 'registrar'])->name('registrar');
        });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');

        // Contexto inicial de la app tras autenticarse: quien es, que puede
        // hacer (capabilities/features) y contadores. Ver
        // App\Services\Mobile\MobileBootstrapService.
        Route::get('mobile/bootstrap', MobileBootstrapController::class)->name('mobile.bootstrap');

        // Autorizacion de canales privados de Reverb (WebSocket) para
        // clientes con Bearer token: equivalente movil de /broadcasting/auth
        // (que usa sesion web). La app aun no la consume (fuera de alcance:
        // "NO tocar la app movil"), pero queda lista — mismo canal
        // "App.Models.User.{id}" que usa el portal web (ver
        // routes/channels.php y docs/PUSH_NOTIFICATIONS.md).
        Route::post('broadcasting/auth', fn (Request $request) => Broadcast::auth($request))
            ->name('broadcasting.auth');

        Route::prefix('dispositivos')->name('dispositivos.')->group(function () {
            Route::post('push-token', [DispositivoController::class, 'registrarPushToken'])->name('push-token.registrar');
            Route::delete('push-token', [DispositivoController::class, 'revocarPushToken'])->name('push-token.revocar');
        });

        Route::prefix('colaborador')->name('colaborador.')->group(function () {
            Route::get('perfil', [ColaboradorController::class, 'perfil'])->name('perfil');
            Route::get('foto', [ColaboradorController::class, 'foto'])->name('foto');
            Route::get('dashboard', [ColaboradorController::class, 'dashboard'])->name('dashboard');
            Route::get('vacaciones', [ColaboradorController::class, 'vacaciones'])->name('vacaciones');
            Route::get('solicitudes', [ColaboradorController::class, 'solicitudes'])->name('solicitudes.index');
            Route::post('solicitudes', [ColaboradorController::class, 'storeSolicitud'])->name('solicitudes.store');
            Route::get('notificaciones', [ColaboradorController::class, 'notificaciones'])->name('notificaciones');

            // Checklist de expediente documental para colaboradores en proceso de
            // alta: nunca el expediente completo, solo estado/progreso por
            // documento (ver App\Services\Incorporacion\IncorporacionService y
            // docs/API_MOVIL.md).
            Route::prefix('incorporacion')->name('incorporacion.')->group(function () {
                Route::get('/', [IncorporacionController::class, 'index'])->name('index');
                Route::get('resumen', [IncorporacionController::class, 'resumen'])->name('resumen');
                Route::post('documentos/{documentoRequerido}/subir', [IncorporacionController::class, 'subirDocumento'])->name('documentos.subir');
                Route::post('documentos/{documento}/solicitar-cambio', [IncorporacionController::class, 'solicitarCambio'])->name('documentos.solicitar-cambio');
            });

            // Felicitacion de cumpleanos del propio colaborador (docs/CUMPLEANOS.md).
            Route::prefix('cumpleanos')->name('cumpleanos.')->group(function () {
                Route::get('felicitacion-actual', [ColaboradorCumpleanosController::class, 'felicitacionActual'])->name('felicitacion-actual');
                Route::get('felicitacion-actual/imagen', [ColaboradorCumpleanosController::class, 'imagen'])->name('felicitacion-actual.imagen');
            });
        });

        // Legacy: conservar hasta que la app móvil migre por completo a
        // solicitudes unificadas (usa la tabla legacy `solicitudes_vacaciones`,
        // no `solicitudes_internas`). La app nueva debe usar en su lugar:
        // GET /api/v1/solicitudes, POST /api/v1/solicitudes,
        // GET /api/v1/solicitudes/configuracion — ver docs/API_MOVIL.md y
        // docs/SOLICITUDES_UNIFICADAS.md.
        Route::prefix('vacaciones')->name('vacaciones.')->group(function () {
            Route::get('saldo', [VacacionesController::class, 'saldo'])->name('saldo');
            Route::get('solicitudes', [VacacionesController::class, 'solicitudes'])->name('solicitudes.index');
            Route::post('solicitudes', [VacacionesController::class, 'storeSolicitud'])->name('solicitudes.store');
        });

        Route::prefix('solicitudes')->name('solicitudes.')->group(function () {
            Route::get('/', [SolicitudController::class, 'index'])->name('index');
            Route::post('/', [SolicitudController::class, 'store'])->name('store');
            Route::get('configuracion', [SolicitudController::class, 'configuracion'])->name('configuracion');
            Route::get('{solicitud}', [SolicitudController::class, 'show'])->name('show');
            Route::post('{solicitud}/adjuntos', [SolicitudController::class, 'adjuntos'])->name('adjuntos');
        });

        Route::prefix('notificaciones')->name('notificaciones.')->group(function () {
            Route::get('/', [NotificacionController::class, 'index'])->name('index');
            Route::post('leer-todas', [NotificacionController::class, 'marcarTodasLeidas'])->name('leer-todas');
            Route::post('{notificacion}/leer', [NotificacionController::class, 'marcarLeida'])->name('leer');
        });

        // RH desde la app movil: expedientes completos, bandeja unificada,
        // solicitudes/vacaciones/documentos/incorporaciones y directorio de
        // colaboradores, siempre dentro del alcance organizacional y con
        // permisos rh.* (ver App\Services\AlcanceOrganizacionalService y
        // docs/RH_MOBILE_API.md).
        Route::prefix('rh')->name('rh.')->group(function () {
            Route::get('dashboard', RhDashboardController::class)->name('dashboard');
            Route::get('pendientes', [RhPendienteController::class, 'index'])->name('pendientes');

            Route::prefix('solicitudes')->name('solicitudes.')->group(function () {
                Route::get('/', [RhSolicitudController::class, 'index'])->name('index');
                Route::get('{solicitud}', [RhSolicitudController::class, 'show'])->name('show');
                Route::post('{solicitud}/aprobar', [RhSolicitudController::class, 'aprobar'])->name('aprobar');
                Route::post('{solicitud}/rechazar', [RhSolicitudController::class, 'rechazar'])->name('rechazar');
                Route::post('{solicitud}/correccion', [RhSolicitudController::class, 'correccion'])->name('correccion');
            });

            // Legacy: conservar hasta que la app móvil migre por completo a
            // solicitudes unificadas (misma tabla legacy `solicitudes_vacaciones`
            // que /api/v1/vacaciones arriba). La app nueva debe usar en su
            // lugar la bandeja RH unificada: GET/POST .../rh/solicitudes/*
            // — ver docs/API_MOVIL.md y docs/SOLICITUDES_UNIFICADAS.md.
            Route::prefix('vacaciones')->name('vacaciones.')->group(function () {
                Route::get('/', [RhVacacionController::class, 'index'])->name('index');
                Route::get('{vacacion}', [RhVacacionController::class, 'show'])->name('show');
                Route::post('{vacacion}/aprobar', [RhVacacionController::class, 'aprobar'])->name('aprobar');
                Route::post('{vacacion}/rechazar', [RhVacacionController::class, 'rechazar'])->name('rechazar');
            });

            Route::prefix('documentos')->name('documentos.')->group(function () {
                Route::get('/', [RhDocumentoController::class, 'index'])->name('index');
                Route::get('{documento}', [RhDocumentoController::class, 'show'])->name('show');
                Route::get('{documento}/ver', [RhDocumentoController::class, 'ver'])->name('ver');
                Route::post('{documento}/aprobar', [RhDocumentoController::class, 'aprobar'])->name('aprobar');
                Route::post('{documento}/rechazar', [RhDocumentoController::class, 'rechazar'])->name('rechazar');
                Route::get('{documento}/extraccion', [RhDocumentoController::class, 'extraccion'])->name('extraccion');
                Route::post('{documento}/extraccion/aplicar', [RhDocumentoController::class, 'aplicarExtraccion'])->name('extraccion.aplicar');
                Route::post('{documento}/extraccion/ignorar', [RhDocumentoController::class, 'ignorarExtraccion'])->name('extraccion.ignorar');
            });

            Route::prefix('incorporaciones')->name('incorporaciones.')->group(function () {
                Route::get('/', [RhIncorporacionController::class, 'index'])->name('index');
                Route::get('{colaborador}', [RhIncorporacionController::class, 'show'])->name('show');
                Route::post('{colaborador}/aprobar', [RhIncorporacionController::class, 'aprobar'])->name('aprobar');
                Route::post('{colaborador}/rechazar', [RhIncorporacionController::class, 'rechazar'])->name('rechazar');
            });

            Route::prefix('colaboradores')->name('colaboradores.')->group(function () {
                Route::get('/', [RhColaboradorController::class, 'index'])->name('index');
                Route::get('{colaborador}', [RhColaboradorController::class, 'show'])->name('show');
            });

            Route::get('vacantes', [RhVacanteController::class, 'index'])->name('vacantes.index');

            Route::prefix('expedientes')->name('expedientes.')->group(function () {
                Route::get('/', [RhExpedienteController::class, 'index'])->name('index');
                Route::get('{colaborador}', [RhExpedienteController::class, 'show'])->name('show');
                Route::get('{colaborador}/documentos/{documento}/ver', [RhExpedienteController::class, 'verDocumento'])->name('documentos.ver');
                Route::post('{colaborador}/documentos/{documento}/aprobar', [RhExpedienteController::class, 'aprobarDocumento'])->name('documentos.aprobar');
                Route::post('{colaborador}/documentos/{documento}/rechazar', [RhExpedienteController::class, 'rechazarDocumento'])->name('documentos.rechazar');
                Route::post('{colaborador}/documentos/{documento}/autorizar-cambio', [RhExpedienteController::class, 'autorizarCambioDocumento'])->name('documentos.autorizar-cambio');
                Route::post('{colaborador}/aprobar-incorporacion', [RhExpedienteController::class, 'aprobarIncorporacion'])->name('aprobar-incorporacion');
                Route::post('{colaborador}/rechazar-incorporacion', [RhExpedienteController::class, 'rechazarIncorporacion'])->name('rechazar-incorporacion');
            });

            // Bandeja de cumpleanos para RH desde la app (docs/CUMPLEANOS.md).
            Route::prefix('cumpleanos')->name('cumpleanos.')->group(function () {
                Route::get('/', [RhCumpleanosController::class, 'index'])->name('index');
                Route::get('{colaborador}/foto', [RhCumpleanosController::class, 'foto'])->name('foto');
                Route::get('{greeting}/imagen', [RhCumpleanosController::class, 'imagen'])->name('imagen');
                Route::get('{greeting}', [RhCumpleanosController::class, 'show'])->name('show');
            });

            // Organigrama (solo lectura, mismo permiso puestos.administrar
            // que el panel web — ver docs/JERARQUIA_PUESTOS.md).
            Route::get('jerarquia-puestos', [RhJerarquiaPuestoController::class, 'index'])->name('jerarquia-puestos.index');

            // Catalogo de formatos y descarga de documentos ya generados
            // (generar/vista previa se quedan en el panel web por ahora,
            // ver docs/FORMATOS.md).
            Route::prefix('formatos')->name('formatos.')->group(function () {
                Route::get('/', [RhFormatoController::class, 'index'])->name('index');
                Route::get('{documento}/descargar', [RhFormatoController::class, 'descargar'])->name('descargar');
                Route::get('{documento}/descargar-pdf', [RhFormatoController::class, 'descargarPdf'])->name('descargar-pdf');
            });
        });
    });
});
