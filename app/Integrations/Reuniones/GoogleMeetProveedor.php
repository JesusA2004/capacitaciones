<?php

namespace App\Integrations\Reuniones;

use App\Models\SesionEnVivo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Integracion real con Google Calendar (un enlace de Meet no se crea con una
 * API propia: se crea pidiendo un "createRequest" de Meet dentro de un
 * evento de Calendar). Se autentica con una cuenta de servicio con
 * delegacion de dominio completa, actuando como GOOGLE_IMPERSONATED_USER,
 * firmando el JWT a mano con las funciones nativas de OpenSSL de PHP en vez
 * de instalar el SDK oficial de Google (`google/apiclient`): ese paquete
 * arrastra `google/apiclient-services`, que agrega clases para cientos de
 * APIs de Google que no se usan aqui, y su autoload de mas de 30,000
 * archivos resulto poco practico en este entorno de desarrollo. Llamar
 * directamente al API REST con el cliente HTTP de Laravel es equivalente y
 * mas ligero.
 *
 * Mientras no este habilitada (o falte el archivo de credenciales), se
 * degrada con gracia: no genera ningun enlace automatico y la sesion queda
 * igual guardada para que el instructor agregue un enlace a mano.
 */
class GoogleMeetProveedor implements ProveedorSesionEnVivo
{
    private const SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    private const URL_TOKEN = 'https://oauth2.googleapis.com/token';

    private const URL_EVENTOS = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    public function estaDisponible(): bool
    {
        $rutaCredenciales = config('services.google_meet.service_account_path');

        return (bool) config('services.google_meet.habilitado')
            && config('services.google_meet.impersonated_user')
            && $rutaCredenciales
            && file_exists($rutaCredenciales);
    }

    public function crearReunion(SesionEnVivo $sesion): void
    {
        if (! $this->estaDisponible()) {
            return;
        }

        $respuesta = Http::withToken($this->obtenerToken())
            ->post(self::URL_EVENTOS.'?conferenceDataVersion=1', [
                'summary' => $sesion->titulo,
                'description' => $sesion->descripcion,
                'start' => ['dateTime' => $sesion->fecha_inicio->toRfc3339String(), 'timeZone' => 'UTC'],
                'end' => [
                    'dateTime' => $sesion->fecha_inicio->clone()->addMinutes($sesion->duracion_minutos)->toRfc3339String(),
                    'timeZone' => 'UTC',
                ],
                'conferenceData' => [
                    'createRequest' => [
                        'requestId' => (string) Str::uuid(),
                        'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                    ],
                ],
            ])
            ->throw()
            ->json();

        $sesion->update([
            'enlace_reunion' => $respuesta['hangoutLink'] ?? null,
            'id_reunion_externa' => $respuesta['id'] ?? null,
            'datos_proveedor' => ['event_id' => $respuesta['id'] ?? null, 'html_link' => $respuesta['htmlLink'] ?? null],
        ]);
    }

    public function cancelarReunion(SesionEnVivo $sesion): void
    {
        if (! $this->estaDisponible() || ! $sesion->id_reunion_externa) {
            return;
        }

        Http::withToken($this->obtenerToken())
            ->delete(self::URL_EVENTOS.'/'.$sesion->id_reunion_externa)
            ->throw();
    }

    /**
     * Intercambia un JWT firmado por la cuenta de servicio (delegacion de
     * dominio completa) por un token de acceso de corta duracion. Se
     * cachea con un margen de seguridad para no firmar/pedir uno nuevo en
     * cada llamada.
     */
    private function obtenerToken(): string
    {
        return Cache::remember('google_meet.token_cuenta', now()->addMinutes(50), function () {
            $respuesta = Http::asForm()->post(self::URL_TOKEN, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->firmarJwt(),
            ])->throw()->json();

            return $respuesta['access_token'];
        });
    }

    private function firmarJwt(): string
    {
        /** @var array{client_email: string, private_key: string} $credenciales */
        $credenciales = json_decode((string) file_get_contents(config('services.google_meet.service_account_path')), true);

        $ahora = now()->getTimestamp();

        $encabezado = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $cuerpo = $this->base64Url(json_encode([
            'iss' => $credenciales['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::URL_TOKEN,
            'iat' => $ahora,
            'exp' => $ahora + 3600,
            'sub' => config('services.google_meet.impersonated_user'),
        ]));

        $contenidoAFirmar = "{$encabezado}.{$cuerpo}";
        openssl_sign($contenidoAFirmar, $firma, $credenciales['private_key'], OPENSSL_ALGO_SHA256);

        return "{$contenidoAFirmar}.".$this->base64Url($firma);
    }

    private function base64Url(string|false $datos): string
    {
        return rtrim(strtr(base64_encode((string) $datos), '+/', '-_'), '=');
    }
}
