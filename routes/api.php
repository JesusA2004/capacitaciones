<?php

use App\Http\Controllers\Api\V1\AppConfigController;
use App\Http\Controllers\Api\V1\AppReleaseController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CelebracionController;
use App\Http\Controllers\Api\V1\CicloLaboralColaboradorController;
use App\Http\Controllers\Api\V1\ColaboradorController;
use App\Http\Controllers\Api\V1\ColaboradorCumpleanosController;
use App\Http\Controllers\Api\V1\DispositivoController;
use App\Http\Controllers\Api\V1\EquipoController;
use App\Http\Controllers\Api\V1\EvaluacionController;
use App\Http\Controllers\Api\V1\IncorporacionController;
use App\Http\Controllers\Api\V1\IncorporacionInvitacionController;
use App\Http\Controllers\Api\V1\MobileBootstrapController;
use App\Http\Controllers\Api\V1\MuroCumpleanosController;
use App\Http\Controllers\Api\V1\NotificacionController;
use App\Http\Controllers\Api\V1\Rh\ActaController;
use App\Http\Controllers\Api\V1\Rh\AltaColaboradorController;
use App\Http\Controllers\Api\V1\Rh\CatalogoController;
use App\Http\Controllers\Api\V1\Rh\CelebracionController as RhCelebracionController;
use App\Http\Controllers\Api\V1\Rh\CierreLaboralController;
use App\Http\Controllers\Api\V1\Rh\ColaboradorController as RhColaboradorController;
use App\Http\Controllers\Api\V1\Rh\ContratoController;
use App\Http\Controllers\Api\V1\Rh\CumpleanosController as RhCumpleanosController;
use App\Http\Controllers\Api\V1\Rh\DashboardController as RhDashboardController;
use App\Http\Controllers\Api\V1\Rh\DocumentoController as RhDocumentoController;
use App\Http\Controllers\Api\V1\Rh\DocumentoLaboralController;
use App\Http\Controllers\Api\V1\Rh\EstructuraController;
use App\Http\Controllers\Api\V1\Rh\ExpedienteController as RhExpedienteController;
use App\Http\Controllers\Api\V1\Rh\FormatoController as RhFormatoController;
use App\Http\Controllers\Api\V1\Rh\FormatoOficialController as RhFormatoOficialController;
use App\Http\Controllers\Api\V1\Rh\IncorporacionController as RhIncorporacionController;
use App\Http\Controllers\Api\V1\Rh\JerarquiaPuestoController as RhJerarquiaPuestoController;
use App\Http\Controllers\Api\V1\Rh\PendienteController as RhPendienteController;
use App\Http\Controllers\Api\V1\Rh\PlantillaDocumentalController;
use App\Http\Controllers\Api\V1\Rh\PrestamoController as RhPrestamoController;
use App\Http\Controllers\Api\V1\Rh\ReciboNominaController as RhReciboNominaController;
use App\Http\Controllers\Api\V1\Rh\SolicitudController as RhSolicitudController;
use App\Http\Controllers\Api\V1\Rh\VacacionController as RhVacacionController;
use App\Http\Controllers\Api\V1\Rh\VacanteController as RhVacanteController;
use App\Http\Controllers\Api\V1\SolicitudController;
use App\Http\Controllers\Api\V1\TareaController;
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
    Route::post('login', [AuthController::class, 'login'])->name('login')->middleware('throttle:api-login');

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

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
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
            Route::post('push-prueba', [DispositivoController::class, 'pushPrueba'])->name('push-prueba')->middleware('throttle:5,1');
        });

        // Muro de felicitaciones de cumpleaños (docs/CUMPLEANOS.md): RH lo
        // abre; cualquier colaborador activo deja mensaje y/o foto.
        Route::prefix('cumpleanos/muros')->name('cumpleanos.muros.')->group(function () {
            Route::get('/', [MuroCumpleanosController::class, 'index'])->name('index');
            Route::get('{greeting}', [MuroCumpleanosController::class, 'show'])->name('show');
            Route::get('{greeting}/foto', [MuroCumpleanosController::class, 'fotoCumpleanero'])->name('foto-cumpleanero');
            Route::get('{greeting}/mensajes', [MuroCumpleanosController::class, 'mensajes'])->name('mensajes.index');
            Route::post('{greeting}/mensajes', [MuroCumpleanosController::class, 'publicar'])->name('mensajes.store')->middleware('throttle:20,1');
            Route::delete('{greeting}/mensajes/{mensaje}', [MuroCumpleanosController::class, 'eliminar'])->name('mensajes.destroy');
            Route::get('{greeting}/mensajes/{mensaje}/foto', [MuroCumpleanosController::class, 'foto'])->name('mensajes.foto');
        });

        // Celebraciones: cumpleaños y aniversarios con felicitaciones
        // PRIVADAS (docs/CELEBRACIONES.md, docs/API_MOVIL.md).
        Route::prefix('celebraciones')->name('celebraciones.')->group(function () {
            Route::get('activas', [CelebracionController::class, 'activas'])->name('activas');
            Route::get('{celebracion}', [CelebracionController::class, 'show'])->name('show');
            Route::get('{celebracion}/tarjeta', [CelebracionController::class, 'tarjeta'])->name('tarjeta');
            Route::get('{celebracion}/foto', [CelebracionController::class, 'foto'])->name('foto');
            Route::get('{celebracion}/mensajes', [CelebracionController::class, 'mensajes'])->name('mensajes.index');
            Route::post('{celebracion}/mensajes', [CelebracionController::class, 'store'])->name('mensajes.store')->middleware('throttle:20,1');
            Route::patch('{celebracion}/mensajes/{mensaje}', [CelebracionController::class, 'update'])->name('mensajes.update');
            Route::delete('{celebracion}/mensajes/{mensaje}', [CelebracionController::class, 'destroy'])->name('mensajes.destroy');
            Route::get('{celebracion}/mensajes/{mensaje}/foto', [CelebracionController::class, 'mensajeFoto'])->name('mensajes.foto');
            Route::get('{celebracion}/mensajes/{mensaje}/autor-foto', [CelebracionController::class, 'autorFoto'])->name('autor-foto');
        });

        Route::prefix('colaborador')->name('colaborador.')->group(function () {
            Route::get('perfil', [ColaboradorController::class, 'perfil'])->name('perfil');
            Route::get('foto', [ColaboradorController::class, 'foto'])->name('foto');
            Route::post('foto', [ColaboradorController::class, 'subirFoto'])->name('foto.store');
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
            Route::post('{solicitud}/cancelar', [SolicitudController::class, 'cancelar'])->name('cancelar');
        });

        /*
        |------------------------------------------------------------------
        | Ciclo laboral completo (docs/backend-rh-completion.md)
        |------------------------------------------------------------------
        | Autoservicio del colaborador: siempre su propia información (el
        | colaborador sale de la sesión; los recursos por id pasan por Policy).
        */
        Route::prefix('colaborador')->name('colaborador.')->group(function () {
            Route::get('alta', [CicloLaboralColaboradorController::class, 'alta'])->name('alta');
            Route::get('expediente', [CicloLaboralColaboradorController::class, 'expediente'])->name('expediente');
            Route::get('documentos-pendientes', [CicloLaboralColaboradorController::class, 'documentosPendientes'])->name('documentos-pendientes');
            Route::get('documentos-laborales', [CicloLaboralColaboradorController::class, 'documentosLaborales'])->name('documentos-laborales.index');
            Route::get('documentos-laborales/{documento}', [CicloLaboralColaboradorController::class, 'documentoLaboral'])->name('documentos-laborales.show')->whereNumber('documento');
            Route::get('documentos-laborales/{documento}/descargar', [CicloLaboralColaboradorController::class, 'descargarDocumento'])->name('documentos-laborales.descargar');
            Route::post('documentos-laborales/{documento}/firmar', [CicloLaboralColaboradorController::class, 'firmarDocumento'])->name('documentos-laborales.firmar');
            Route::get('contratos', [CicloLaboralColaboradorController::class, 'contratos'])->name('contratos');
            Route::get('recibos', [CicloLaboralColaboradorController::class, 'recibos'])->name('recibos.index');
            Route::get('recibos/{recibo}', [CicloLaboralColaboradorController::class, 'recibo'])->name('recibos.show');
            Route::get('recibos/{recibo}/pdf', [CicloLaboralColaboradorController::class, 'reciboPdf'])->name('recibos.pdf');
            Route::get('prestamos', [CicloLaboralColaboradorController::class, 'prestamos'])->name('prestamos.index');
            Route::get('prestamos/{prestamo}', [CicloLaboralColaboradorController::class, 'prestamo'])->name('prestamos.show');
            Route::get('jerarquia', [CicloLaboralColaboradorController::class, 'jerarquia'])->name('jerarquia');
        });

        // Jefe: equipo directo, pendientes de su equipo y visto bueno.
        Route::prefix('equipo')->name('equipo.')->group(function () {
            Route::get('/', [EquipoController::class, 'index'])->name('index');
            Route::get('pendientes', [EquipoController::class, 'pendientes'])->name('pendientes');
            Route::post('solicitudes/{solicitud}/visto-bueno', [EquipoController::class, 'vistoBueno'])->name('solicitudes.visto-bueno');
        });

        // Evaluación de periodo de prueba (jefe captura, RH/Dirección autoriza).
        Route::prefix('evaluaciones')->name('evaluaciones.')->group(function () {
            Route::get('/', [EvaluacionController::class, 'index'])->name('index');
            Route::get('{evaluacion}', [EvaluacionController::class, 'show'])->name('show');
            Route::post('{evaluacion}/capturar', [EvaluacionController::class, 'capturar'])->name('capturar');
            Route::post('{evaluacion}/autorizar', [EvaluacionController::class, 'autorizar'])->name('autorizar');
            Route::post('{evaluacion}/devolver', [EvaluacionController::class, 'devolver'])->name('devolver');
        });

        // Bandeja de trabajo (pendientes/tareas).
        Route::prefix('tareas')->name('tareas.')->group(function () {
            Route::get('/', [TareaController::class, 'index'])->name('index');
            Route::post('{tarea}/leer', [TareaController::class, 'leer'])->name('leer');
            Route::post('{tarea}/resolver', [TareaController::class, 'resolver'])->name('resolver');
        });

        Route::prefix('notificaciones')->name('notificaciones.')->group(function () {
            Route::get('/', [NotificacionController::class, 'index'])->name('index');
            Route::post('leer-todas', [NotificacionController::class, 'marcarTodasLeidas'])->name('leer-todas');
            Route::post('{notificacion}/leer', [NotificacionController::class, 'marcarLeida'])->name('leer');
            Route::post('{notificacion}/abrir', [NotificacionController::class, 'abrir'])->name('abrir');
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
                // Cambio de estado unificado (mismo tablero Kanban que la web,
                // ver Rh\SolicitudController::actualizarEstado): reutiliza
                // SolicitudesService::moverEnTablero(), nunca duplica la lógica
                // de aprobar/rechazar/correccion/cerrar de arriba.
                Route::patch('{solicitud}/estado', [RhSolicitudController::class, 'actualizarEstado'])->name('estado');
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

            // Celebraciones para RH (docs/CELEBRACIONES.md): aniversarios y
            // acciones de ambos tipos (tipo = cumpleanos|aniversario_laboral).
            Route::prefix('celebraciones')->name('celebraciones.')->group(function () {
                Route::get('aniversarios', [RhCelebracionController::class, 'aniversarios'])->name('aniversarios');
                Route::post('evento/{celebracion}/recepcion', [RhCelebracionController::class, 'recepcion'])->name('recepcion');
                Route::get('{colaborador}/{tipo}', [RhCelebracionController::class, 'evento'])->name('evento');
                Route::post('{colaborador}/{tipo}/enviar', [RhCelebracionController::class, 'enviar'])->name('enviar');
                Route::post('{colaborador}/{tipo}/avisar-todos', [RhCelebracionController::class, 'avisarATodos'])->name('avisar-todos')->middleware('throttle:10,1');
                Route::post('{colaborador}/{tipo}/tarjeta', [RhCelebracionController::class, 'regenerarTarjeta'])->name('tarjeta.regenerar');
            });

            // Bandeja de cumpleanos para RH desde la app (docs/CUMPLEANOS.md).
            Route::prefix('cumpleanos')->name('cumpleanos.')->group(function () {
                Route::get('/', [RhCumpleanosController::class, 'index'])->name('index');
                Route::get('{colaborador}/foto', [RhCumpleanosController::class, 'foto'])->name('foto');
                Route::get('{greeting}/imagen', [RhCumpleanosController::class, 'imagen'])->name('imagen');
                Route::post('{greeting}/muro/abrir', [RhCumpleanosController::class, 'abrirMuro'])->name('muro.abrir');
                Route::post('{greeting}/muro/cerrar', [RhCumpleanosController::class, 'cerrarMuro'])->name('muro.cerrar');
                Route::get('{greeting}', [RhCumpleanosController::class, 'show'])->name('show');
            });

            // Organigrama (solo lectura, mismo permiso puestos.administrar
            // que el panel web — ver docs/JERARQUIA_PUESTOS.md).
            Route::get('jerarquia-puestos', [RhJerarquiaPuestoController::class, 'index'])->name('jerarquia-puestos.index');

            /*
            | Ciclo laboral completo — operación de RH/Dirección/Jurídico.
            | Cada acción: FormRequest + Policy (permiso + alcance) + Service.
            */
            Route::get('catalogos', CatalogoController::class)->name('catalogos');
            Route::post('colaboradores', [AltaColaboradorController::class, 'store'])->name('colaboradores.store');
            Route::get('colaboradores/{colaborador}/alta', [AltaColaboradorController::class, 'show'])->name('colaboradores.alta');
            Route::post('colaboradores/{colaborador}/activar', [AltaColaboradorController::class, 'activar'])->name('colaboradores.activar');
            Route::get('colaboradores/{colaborador}/jerarquia', [AltaColaboradorController::class, 'jerarquia'])->name('colaboradores.jerarquia');
            Route::get('colaboradores/{colaborador}/contratos', [ContratoController::class, 'delColaborador'])->name('colaboradores.contratos');
            Route::post('colaboradores/{colaborador}/documentos-laborales', [DocumentoLaboralController::class, 'store'])->name('colaboradores.documentos-laborales.store');
            Route::post('colaboradores/{colaborador}/cierres', [CierreLaboralController::class, 'store'])->name('colaboradores.cierres.store');
            Route::post('colaboradores/{colaborador}/recibos', [RhReciboNominaController::class, 'store'])->name('colaboradores.recibos.store');
            Route::post('colaboradores/{colaborador}/actas', [ActaController::class, 'store'])->name('colaboradores.actas.store');
            Route::post('candidatos/{candidato}/contratar', [AltaColaboradorController::class, 'contratarCandidato'])->name('candidatos.contratar');

            Route::prefix('plantillas-documentales')->name('plantillas-documentales.')->group(function () {
                Route::get('/', [PlantillaDocumentalController::class, 'index'])->name('index');
                Route::get('variables', [PlantillaDocumentalController::class, 'variables'])->name('variables');
                Route::post('/', [PlantillaDocumentalController::class, 'store'])->name('store')->middleware('throttle:api-cargas');
                Route::patch('{plantilla}', [PlantillaDocumentalController::class, 'update'])->name('update');
            });

            Route::prefix('documentos-laborales')->name('documentos-laborales.')->group(function () {
                Route::get('/', [DocumentoLaboralController::class, 'index'])->name('index');
                Route::get('pendientes', [DocumentoLaboralController::class, 'pendientes'])->name('pendientes');
                Route::get('{documento}', [DocumentoLaboralController::class, 'show'])->name('show');
                Route::get('{documento}/descargar', [DocumentoLaboralController::class, 'descargar'])->name('descargar');
                Route::post('{documento}/imprimir', [DocumentoLaboralController::class, 'imprimir'])->name('imprimir');
                Route::post('{documento}/firma-fisica', [DocumentoLaboralController::class, 'firmaFisica'])->name('firma-fisica');
                Route::post('{documento}/envio', [DocumentoLaboralController::class, 'envio'])->name('envio')->middleware('throttle:api-cargas');
                Route::post('{documento}/recepcion', [DocumentoLaboralController::class, 'recepcion'])->name('recepcion');
                Route::post('{documento}/escaneo', [DocumentoLaboralController::class, 'escaneo'])->name('escaneo')->middleware('throttle:api-cargas');
                Route::post('{documento}/archivar', [DocumentoLaboralController::class, 'archivar'])->name('archivar');
                Route::post('{documento}/cancelar', [DocumentoLaboralController::class, 'cancelar'])->name('cancelar');
            });

            Route::get('contratos/por-vencer', [ContratoController::class, 'porVencer'])->name('contratos.por-vencer');

            Route::prefix('cierres')->name('cierres.')->group(function () {
                Route::get('/', [CierreLaboralController::class, 'index'])->name('index');
                Route::get('{cierre}', [CierreLaboralController::class, 'show'])->name('show');
                Route::post('{cierre}/aviso', [CierreLaboralController::class, 'aviso'])->name('aviso')->middleware('throttle:api-cargas');
                Route::post('{cierre}/aviso/generar', [CierreLaboralController::class, 'generarAviso'])->name('aviso.generar');
                Route::post('{cierre}/finiquito/calcular', [CierreLaboralController::class, 'calcularFiniquito'])->name('finiquito.calcular');
                Route::post('{cierre}/finiquito/conceptos', [CierreLaboralController::class, 'agregarConcepto'])->name('finiquito.conceptos.store');
                Route::patch('{cierre}/finiquito/conceptos/{concepto}', [CierreLaboralController::class, 'actualizarConcepto'])->name('finiquito.conceptos.update');
                Route::delete('{cierre}/finiquito/conceptos/{concepto}', [CierreLaboralController::class, 'eliminarConcepto'])->name('finiquito.conceptos.destroy');
                Route::post('{cierre}/finiquito/revisar', [CierreLaboralController::class, 'revisarFiniquito'])->name('finiquito.revisar');
                Route::post('{cierre}/finiquito/documento', [CierreLaboralController::class, 'generarFiniquito'])->name('finiquito.documento');
                Route::post('{cierre}/finiquito/firmado', [CierreLaboralController::class, 'finiquitoFirmado'])->name('finiquito.firmado')->middleware('throttle:api-cargas');
                Route::post('{cierre}/finiquito/pago', [CierreLaboralController::class, 'confirmarPago'])->name('finiquito.pago');
                Route::post('{cierre}/ejecutar-baja', [CierreLaboralController::class, 'ejecutarBaja'])->name('ejecutar-baja');
                Route::post('{cierre}/cerrar-expediente', [CierreLaboralController::class, 'cerrarExpediente'])->name('cerrar-expediente');
                Route::post('{cierre}/cancelar', [CierreLaboralController::class, 'cancelar'])->name('cancelar');
            });

            Route::prefix('recibos')->name('recibos.')->group(function () {
                Route::get('/', [RhReciboNominaController::class, 'index'])->name('index');
                Route::post('importar', [RhReciboNominaController::class, 'importar'])->name('importar')->middleware('throttle:api-cargas');
                Route::get('{recibo}', [RhReciboNominaController::class, 'show'])->name('show');
                Route::get('{recibo}/pdf', [RhReciboNominaController::class, 'pdf'])->name('pdf');
                Route::post('{recibo}/regenerar-pdf', [RhReciboNominaController::class, 'regenerarPdf'])->name('regenerar-pdf');
            });

            Route::prefix('prestamos')->name('prestamos.')->group(function () {
                Route::get('/', [RhPrestamoController::class, 'index'])->name('index');
                Route::get('{prestamo}', [RhPrestamoController::class, 'show'])->name('show');
                Route::post('{prestamo}/documentos', [RhPrestamoController::class, 'generarDocumentos'])->name('documentos');
                Route::post('{prestamo}/resguardar', [RhPrestamoController::class, 'resguardar'])->name('resguardar');
            });
            Route::post('solicitudes/{solicitud}/prestamo/autorizar', [RhPrestamoController::class, 'autorizar'])->name('solicitudes.prestamo.autorizar');
            Route::post('solicitudes/{solicitud}/prestamo/rechazar', [RhPrestamoController::class, 'rechazar'])->name('solicitudes.prestamo.rechazar');

            Route::prefix('actas')->name('actas.')->group(function () {
                Route::get('/', [ActaController::class, 'index'])->name('index');
                Route::get('{acta}', [ActaController::class, 'show'])->name('show');
                Route::patch('{acta}', [ActaController::class, 'update'])->name('update');
                Route::post('{acta}/anexos', [ActaController::class, 'anexo'])->name('anexos.store')->middleware('throttle:api-cargas');
                Route::get('{acta}/anexos/{anexo}', [ActaController::class, 'descargarAnexo'])->name('anexos.show');
                Route::post('{acta}/documento', [ActaController::class, 'generarDocumento'])->name('documento');
                Route::post('{acta}/negativa-firma', [ActaController::class, 'negativaFirma'])->name('negativa-firma');
                Route::post('{acta}/seguimiento', [ActaController::class, 'seguimiento'])->name('seguimiento');
                Route::post('{acta}/cerrar', [ActaController::class, 'cerrar'])->name('cerrar');
            });

            Route::get('plantilla/cobertura', [EstructuraController::class, 'cobertura'])->name('plantilla.cobertura');
            Route::get('indicadores', [EstructuraController::class, 'indicadores'])->name('indicadores');
            Route::get('organigrama', [EstructuraController::class, 'organigrama'])->name('organigrama');
            Route::get('vacantes/{vacante}', [EstructuraController::class, 'vacante'])->name('vacantes.show');

            // Plantillas oficiales versionadas (docs/FORMATOS_OFICIALES.md):
            // mismo motor que el panel web — catálogo, variables, generar y
            // administración de versiones.
            Route::prefix('formatos-oficiales')->name('formatos-oficiales.')->group(function () {
                Route::get('/', [RhFormatoOficialController::class, 'index'])->name('index');
                Route::post('/', [RhFormatoOficialController::class, 'store'])->name('store');
                Route::get('variables', [RhFormatoOficialController::class, 'variables'])->name('variables');
                Route::get('generados', [RhFormatoOficialController::class, 'generados'])->name('generados');
                Route::get('generados/{generacion}/descargar', [RhFormatoOficialController::class, 'descargar'])->name('generaciones.descargar');
                Route::get('generados/{generacion}/ver', [RhFormatoOficialController::class, 'ver'])->name('generaciones.ver');
                Route::get('versiones/{version}/base', [RhFormatoOficialController::class, 'base'])->name('versiones.base');
                Route::put('versiones/{version}/campos', [RhFormatoOficialController::class, 'guardarCampos'])->name('versiones.campos');
                Route::post('versiones/{version}/analisis', [RhFormatoOficialController::class, 'refinarAnalisis'])->name('versiones.analisis');
                Route::post('versiones/{version}/publicar', [RhFormatoOficialController::class, 'publicar'])->name('versiones.publicar');
                Route::delete('versiones/{version}', [RhFormatoOficialController::class, 'descartar'])->name('versiones.descartar');
                Route::get('{formato}', [RhFormatoOficialController::class, 'show'])->name('show');
                Route::post('{formato}/versiones', [RhFormatoOficialController::class, 'nuevaVersion'])->name('versiones.store');
                Route::post('{formato}/archivar', [RhFormatoOficialController::class, 'archivar'])->name('archivar');
                Route::post('{formato}/reactivar', [RhFormatoOficialController::class, 'reactivar'])->name('reactivar');
                Route::post('{formato}/preparar', [RhFormatoOficialController::class, 'preparar'])->name('preparar');
                Route::post('{formato}/vista-previa', [RhFormatoOficialController::class, 'vistaPrevia'])->name('vista-previa');
                Route::post('{formato}/generar', [RhFormatoOficialController::class, 'generar'])->name('generar');
            });

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
