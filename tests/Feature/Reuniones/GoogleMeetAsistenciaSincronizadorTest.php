<?php

use App\Enums\EstadoAsistencia;
use App\Enums\EstadoIdentificacionParticipante;
use App\Enums\EstadoSincronizacion;
use App\Enums\EstadoSincronizacionReunion;
use App\Enums\ProveedorSesion;
use App\Enums\TipoParticipante;
use App\Enums\TipoSincronizacionReunion;
use App\Models\Asistencia;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\InscripcionCurso;
use App\Models\Leccion;
use App\Models\RegistroSesion;
use App\Models\SesionEnVivo;
use App\Models\SesionParticipante;
use App\Models\User;
use App\Services\Reuniones\SincronizacionAsistenciaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Http;

/**
 * No hay credenciales reales de Google en este entorno (igual que
 * GoogleMeetProveedorTest de la Fase 5), así que estas pruebas usan la
 * misma llave RSA de prueba y Http::fake() con la forma real documentada
 * por Google para conferenceRecords/participants/participantSessions y la
 * Admin Directory API. Ver docs/AUDITORIA_CUMPLIMIENTO.md sección 1.
 */
beforeEach(function () {
    // Misma llave de prueba que tests/Feature/Reuniones/GoogleMeetProveedorTest.php
    // (generada una sola vez con el CLI de openssl para los fixtures de este proyecto).
    $llavePrueba = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDQS290iv3L96Jp
tfrZ8cUoNMdUhagE0NhcbATxaNud9KgU27VilIRqL6xk6QnkbbCO/2x9l6//05nm
WhVF9wxOH487MYEiVusCXSKdkQgKFDx2ncP2b0EDch+hKkNh2XavIGsvIVn5gdGd
uCas6dTHyjbHnv4PkpS+SXp58hELga762MQZ3h8QhxGNTLXOeF2jD8QEMe7xzrYt
hHSVLobMp4+BJKG+AnMS5MUKEpjPbXgLz92b494ABLu1NI1/YBW0Gy9Kt7a9yC/z
yrRaMh022i58PPaeziuTXXp1g6dQKIz7f4k+z9EznmuECy1B56z2LDvWJhgANy6/
SIkAxuE5AgMBAAECggEAZ9x9flDB/X8Eoo5lv1/xLbuneutMXN5gTDFmg5OEEq6I
UyI8vyOMJUCRRx32W0sgoyUUzUkiLr+tuTFI80KdBaVx75OfLubFN0AGfyfuqMvv
XDwNlydhdRzmTZ/7ymI7blaNa4pHSYTkZy8IRecwvNCFYSNTzqMJ/Ro5cm3z9tZz
5uBkBxQrymwgCS4AVfk9/uyHaRsQKX1H0QgCFYay4q7TT2DR8paTth9ejrkZdffH
kCQMio+jWtQn5CwlBD/KopiS1GF3sfurBkvpklEK091RIJrZnbU4D7bSwCM45sb7
nMG4s8sgc+tczHqRGUCMI7F/SMPPg/hkrIpd7BxxcwKBgQD4jX7ZcSXwgw2G8QYX
8HoOQwwDV6mU6aiAz79tkpv88Y+nm3CNrXSZzoY2RR1TtcihQNnXRylZLsAootXm
5qt/VCVWKVCAnvUL7Tu5JclLOH10DDLpnSY3QUOFS2GkA1RU+/eeRu12yNVPKMdz
nJJQOlCURQBoGzuhXbeZKGf9BwKBgQDWiSTDr4eLzn4r7ncCrIeRaNribd2WGApv
Va24e5iYRGge/9MVF3SgB/MmTrtKMRG83/+CmWYtAvBIsbT9lr6Zm3IGGvEZYdRD
y9gNj8l8S6B20OnVCWez2ykFxNI6b2GcFQhIzne8YDAR6UdK/FHkhlLzBmJ41ew0
XHRH4fjfvwKBgG5i2P1VJRZl/bH6hUxIfsyqtkEdw7Dg/PcITOoq/KAf4D7958Tk
Ti6o7C5aD6ZHy6ziEl1ru09iVfE2MG118KCCDHrxqlEVR5teZvHZeEax9fV+HXZn
Vruffo9KZTjkSaXcqaJfucLRevrHD52m4cxDudm/s2iI/7iw2INq0JQtAoGAQlZr
+NqJFlEGsLzvLfN9hBghPszsLOJIL78VlasaN1NHwvYmJip3lJiAtkK4JSvhKT82
egLHFnoHJONWbOe1DjiD3KFuFgQrJ7+bki35Bqc3+iWFeKuM1o+ZMsB2pT0VuLbE
Ngcp/STdGFzC/8vf4sMqWR2LS4QSoupHxoZ4d28CgYEAoR2Oz41vEvbWPIxvaOPL
N6n3Emz1kzCxTc94ifpmkqNaH5Y7sgry6l1XrNrONuMZugRG9QJvhK23POXEbqGk
ST1d47c0coduGTsHn/MgyLmm4W8cT+QPMWLLfj0s+AstBMp0ROGYNfJj3H/J4BPs
Ars9jZG1q8HR9ARFvHd82dk=
-----END PRIVATE KEY-----
PEM;

    $rutaCredenciales = tempnam(sys_get_temp_dir(), 'google-service-account-').'.json';
    file_put_contents($rutaCredenciales, json_encode([
        'client_email' => 'cuenta-servicio@mrlana-capacitacion.iam.gserviceaccount.com',
        'private_key' => $llavePrueba,
    ]));

    config([
        'services.google_meet.habilitado' => true,
        'services.google_meet.service_account_path' => $rutaCredenciales,
        'services.google_meet.impersonated_user' => 'coordinador@mrlana.test',
    ]);

    $this->seed(RolesYPermisosSeeder::class);

    $this->colaborador = User::factory()->create(['email' => 'colaborador@mrlana.test']);
    $this->colaborador->assignRole('colaborador');

    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $this->leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'sesion_en_vivo']);
    InscripcionCurso::create(['user_id' => $this->colaborador->id, 'curso_id' => $curso->id, 'estado' => 'pendiente']);

    $this->sesion = SesionEnVivo::factory()->create([
        'leccion_id' => $this->leccion->id,
        'proveedor' => ProveedorSesion::GoogleMeet,
        'enlace_reunion' => 'https://meet.google.com/abc-defg-hij',
        'fecha_inicio' => now()->subHour(),
        'duracion_minutos' => 60,
        'porcentaje_minimo_asistencia' => 80,
        'tolerancia_minutos' => 5,
    ]);

    Asistencia::create([
        'sesion_en_vivo_id' => $this->sesion->id,
        'user_id' => $this->colaborador->id,
        'estado' => 'pendiente',
    ]);
});

function respuestasMeetFalsas(array $sesionesColaborador, array $sesionesAnonimo = []): void
{
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token-falso', 'expires_in' => 3600]),
        'https://meet.googleapis.com/v2/conferenceRecords?*' => Http::response([
            'conferenceRecords' => [[
                'name' => 'conferenceRecords/abc123',
                'startTime' => now()->subHour()->toIso8601String(),
                'endTime' => now()->toIso8601String(),
            ]],
        ]),
        // Nota: '?*' (no solo '*') para que este patrón no capture también
        // las URLs de participantSessions, que empiezan con la misma ruta.
        'https://meet.googleapis.com/v2/conferenceRecords/abc123/participants?*' => Http::response([
            'participants' => array_filter([
                [
                    'name' => 'conferenceRecords/abc123/participants/p1',
                    'signedinUser' => ['user' => 'users/109988877', 'displayName' => 'Colaborador de Prueba'],
                ],
                $sesionesAnonimo !== [] ? [
                    'name' => 'conferenceRecords/abc123/participants/p2',
                    'anonymousUser' => ['displayName' => 'Invitado'],
                ] : null,
            ]),
        ]),
        'https://meet.googleapis.com/v2/conferenceRecords/abc123/participants/p1/participantSessions*' => Http::response([
            'participantSessions' => array_map(fn ($s) => [
                'name' => 'conferenceRecords/abc123/participants/p1/participantSessions/'.uniqid(),
                'startTime' => $s['inicio'],
                'endTime' => $s['fin'],
            ], $sesionesColaborador),
        ]),
        'https://meet.googleapis.com/v2/conferenceRecords/abc123/participants/p2/participantSessions*' => Http::response([
            'participantSessions' => array_map(fn ($s) => [
                'name' => 'conferenceRecords/abc123/participants/p2/participantSessions/'.uniqid(),
                'startTime' => $s['inicio'],
                'endTime' => $s['fin'],
            ], $sesionesAnonimo),
        ]),
        'https://admin.googleapis.com/admin/directory/v1/users/109988877*' => Http::response([
            'primaryEmail' => 'colaborador@mrlana.test',
        ]),
    ]);
}

test('sincronizar sin conferenceRecords disponibles marca el registro con error y propaga la excepcion para que el Job reintente', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token-falso', 'expires_in' => 3600]),
        'https://meet.googleapis.com/v2/conferenceRecords?*' => Http::response(['conferenceRecords' => []]),
    ]);

    try {
        app(SincronizacionAsistenciaService::class)->sincronizar($this->sesion, TipoSincronizacionReunion::Manual);
        $this->fail('Se esperaba que la sincronización lanzara una excepción para que el Job la reintente.');
    } catch (RuntimeException $excepcion) {
        expect($excepcion->getMessage())->toContain('conferencia');
    }

    $registro = RegistroSesion::where('sesion_en_vivo_id', $this->sesion->id)->firstOrFail();
    expect($registro->estado_sincronizacion)->toBe(EstadoSincronizacion::Error);
    expect($registro->intentos)->toBe(1);
    expect($registro->ultimo_error)->not->toBeNull();
    expect(SesionParticipante::count())->toBe(0);
});

test('agotar los reintentos marca el registro y la sincronizacion como agotados sin volver a lanzar la excepcion', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token-falso', 'expires_in' => 3600]),
        'https://meet.googleapis.com/v2/conferenceRecords?*' => Http::response(['conferenceRecords' => []]),
    ]);

    for ($intento = 1; $intento < 5; $intento++) {
        try {
            app(SincronizacionAsistenciaService::class)->sincronizar($this->sesion, TipoSincronizacionReunion::Reintento);
        } catch (RuntimeException) {
            // Esperado en cada intento salvo el ultimo.
        }
    }

    $sincronizacionFinal = app(SincronizacionAsistenciaService::class)->sincronizar($this->sesion, TipoSincronizacionReunion::Reintento);

    expect($sincronizacionFinal->estado)->toBe(EstadoSincronizacionReunion::Agotada);

    $registro = RegistroSesion::where('sesion_en_vivo_id', $this->sesion->id)->firstOrFail();
    expect($registro->estado_sincronizacion)->toBe(EstadoSincronizacion::Agotado);
    expect($registro->intentos)->toBe(5);
});

test('sincronizar con asistencia completa marca al colaborador identificado como presente', function () {
    respuestasMeetFalsas([
        ['inicio' => now()->subHour()->toIso8601String(), 'fin' => now()->toIso8601String()],
    ]);

    $sincronizacion = app(SincronizacionAsistenciaService::class)->sincronizar($this->sesion, TipoSincronizacionReunion::Manual);

    expect($sincronizacion->estado)->toBe(EstadoSincronizacionReunion::Completada);
    expect($sincronizacion->cantidad_participantes)->toBe(1);

    $participante = SesionParticipante::firstOrFail();
    expect($participante->tipo_participante)->toBe(TipoParticipante::Interno);
    expect($participante->estado_identificacion)->toBe(EstadoIdentificacionParticipante::Identificado);
    expect($participante->user_id)->toBe($this->colaborador->id);
    expect($participante->minutos_acumulados)->toBe(60);
    expect($participante->numero_reconexiones)->toBe(0);

    $asistencia = Asistencia::where('user_id', $this->colaborador->id)->firstOrFail();
    expect($asistencia->estado)->toBe(EstadoAsistencia::Presente);
    expect($asistencia->minutos_totales)->toBe(60);
    expect($asistencia->porcentaje_sesion)->toBe(100);
    expect($asistencia->sincronizado_en)->not->toBeNull();
});

test('varias reconexiones se suman y se cuentan correctamente', function () {
    respuestasMeetFalsas([
        ['inicio' => now()->subHour()->toIso8601String(), 'fin' => now()->subMinutes(45)->toIso8601String()],
        ['inicio' => now()->subMinutes(40)->toIso8601String(), 'fin' => now()->toIso8601String()],
    ]);

    app(SincronizacionAsistenciaService::class)->sincronizar($this->sesion, TipoSincronizacionReunion::Manual);

    $participante = SesionParticipante::firstOrFail();
    expect($participante->numero_reconexiones)->toBe(1);
    expect($participante->minutos_acumulados)->toBe(15 + 40);
});

test('una asistencia parcial (menos del minimo) queda como asistencia_parcial', function () {
    respuestasMeetFalsas([
        ['inicio' => now()->subHour()->toIso8601String(), 'fin' => now()->subMinutes(45)->toIso8601String()],
    ]);

    app(SincronizacionAsistenciaService::class)->sincronizar($this->sesion, TipoSincronizacionReunion::Manual);

    $asistencia = Asistencia::where('user_id', $this->colaborador->id)->firstOrFail();
    expect($asistencia->estado)->toBe(EstadoAsistencia::AsistenciaParcial);
});

test('sin ninguna participacion detectada la asistencia queda ausente', function () {
    respuestasMeetFalsas([]);

    app(SincronizacionAsistenciaService::class)->sincronizar($this->sesion, TipoSincronizacionReunion::Manual);

    $asistencia = Asistencia::where('user_id', $this->colaborador->id)->firstOrFail();
    expect($asistencia->estado)->toBe(EstadoAsistencia::Ausente);
    expect($asistencia->motivo_estado)->not->toBeNull();
});

test('un participante anonimo queda pendiente de revision y no se asocia a ningun colaborador', function () {
    respuestasMeetFalsas(
        [['inicio' => now()->subHour()->toIso8601String(), 'fin' => now()->toIso8601String()]],
        [['inicio' => now()->subMinutes(30)->toIso8601String(), 'fin' => now()->toIso8601String()]],
    );

    app(SincronizacionAsistenciaService::class)->sincronizar($this->sesion, TipoSincronizacionReunion::Manual);

    $anonimo = SesionParticipante::whereNull('correo_detectado')->firstOrFail();
    expect($anonimo->tipo_participante)->toBe(TipoParticipante::Anonimo);
    expect($anonimo->estado_identificacion)->toBe(EstadoIdentificacionParticipante::PendienteRevision);
    expect($anonimo->user_id)->toBeNull();
    expect($anonimo->resultado_calculado)->toBe('pendiente_revision');
});

test('una correccion manual previa no se sobrescribe al volver a sincronizar', function () {
    respuestasMeetFalsas([
        ['inicio' => now()->subHour()->toIso8601String(), 'fin' => now()->subMinutes(45)->toIso8601String()],
    ]);

    $asistencia = Asistencia::where('user_id', $this->colaborador->id)->firstOrFail();
    $asistencia->update(['estado' => 'presente', 'correccion_origen' => 'manual', 'minutos_totales' => 999]);

    app(SincronizacionAsistenciaService::class)->sincronizar($this->sesion, TipoSincronizacionReunion::Manual);

    expect($asistencia->fresh()->estado)->toBe(EstadoAsistencia::Presente);
    expect($asistencia->fresh()->minutos_totales)->toBe(999);
});

test('sincronizar dos veces no duplica sesiones_participante ni tramos de entrada salida', function () {
    respuestasMeetFalsas([
        ['inicio' => now()->subHour()->toIso8601String(), 'fin' => now()->toIso8601String()],
    ]);

    app(SincronizacionAsistenciaService::class)->sincronizar($this->sesion, TipoSincronizacionReunion::Manual);
    app(SincronizacionAsistenciaService::class)->sincronizar($this->sesion, TipoSincronizacionReunion::Manual);

    expect(SesionParticipante::count())->toBe(1);
    expect(SesionParticipante::firstOrFail()->entradasSalidas()->count())->toBe(1);
});
