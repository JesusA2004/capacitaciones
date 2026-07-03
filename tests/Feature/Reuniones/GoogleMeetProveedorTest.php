<?php

use App\Integrations\Reuniones\GoogleMeetProveedor;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\Leccion;
use App\Models\SesionEnVivo;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * No hay credenciales reales de Google en este entorno, asi que estas
 * pruebas usan una llave RSA de prueba (generada una sola vez con el CLI de
 * openssl para este fixture; `openssl_pkey_new()` no funciona en este WAMP
 * porque falta configurar `openssl.cnf`, pero `openssl_sign()` con una llave
 * ya existente en formato PEM si funciona sin ese archivo) para el archivo
 * de cuenta de servicio, y usan Http::fake() para simular el intercambio de
 * token y la creacion/cancelacion del evento de Calendar. La firma del JWT
 * si se ejecuta de verdad (con la llave de prueba): lo que se simula es
 * unicamente la respuesta de Google, no la logica propia de firmado.
 */
beforeEach(function () {
    $llavePrivada = <<<'PEM'
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

    $this->rutaCredenciales = tempnam(sys_get_temp_dir(), 'google_sa_').'.json';
    file_put_contents($this->rutaCredenciales, json_encode([
        'client_email' => 'cuenta-de-servicio@proyecto.iam.gserviceaccount.com',
        'private_key' => $llavePrivada,
    ]));

    config([
        'services.google_meet.habilitado' => true,
        'services.google_meet.impersonated_user' => 'instructor@mrlana.test',
        'services.google_meet.service_account_path' => $this->rutaCredenciales,
    ]);

    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'sesion_en_vivo']);

    $this->sesion = SesionEnVivo::factory()->create([
        'leccion_id' => $leccion->id,
        'proveedor' => 'google_meet',
        'creado_por' => User::factory()->create()->id,
    ]);
});

afterEach(function () {
    if (file_exists($this->rutaCredenciales)) {
        unlink($this->rutaCredenciales);
    }
});

test('sin el archivo de credenciales el proveedor no esta disponible y no llama a la API', function () {
    config(['services.google_meet.service_account_path' => '/ruta/que/no/existe.json']);
    Http::fake();

    app(GoogleMeetProveedor::class)->crearReunion($this->sesion);

    Http::assertNothingSent();
    expect($this->sesion->fresh()->enlace_reunion)->toBeNull();
});

test('crear la reunion intercambia el JWT por un token y guarda el enlace de Meet devuelto', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token-falso', 'expires_in' => 3600]),
        'https://www.googleapis.com/calendar/v3/*' => Http::response([
            'id' => 'evento123',
            'hangoutLink' => 'https://meet.google.com/abc-defg-hij',
            'htmlLink' => 'https://calendar.google.com/event?eid=evento123',
        ]),
    ]);

    app(GoogleMeetProveedor::class)->crearReunion($this->sesion);

    $this->sesion->refresh();
    expect($this->sesion->enlace_reunion)->toBe('https://meet.google.com/abc-defg-hij');
    expect($this->sesion->id_reunion_externa)->toBe('evento123');

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://oauth2.googleapis.com/token') {
            return false;
        }

        return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer';
    });
});

test('cancelar la reunion llama al endpoint de eliminacion de Calendar', function () {
    $this->sesion->update(['id_reunion_externa' => 'evento123']);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token-falso', 'expires_in' => 3600]),
        'https://www.googleapis.com/calendar/v3/*' => Http::response([], 204),
    ]);

    app(GoogleMeetProveedor::class)->cancelarReunion($this->sesion);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), '/events/evento123'));
});
