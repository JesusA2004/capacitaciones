<?php

namespace App\Integrations\Reuniones;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Autenticación por cuenta de servicio con delegación de dominio, compartida
 * por todas las integraciones de Google (creación de reuniones, asistencia
 * real). Firma el JWT a mano con OpenSSL en vez del SDK oficial
 * `google/apiclient` — ver GoogleMeetProveedor para la justificación
 * completa. Cada combinación de scope + usuario impersonado se cachea por
 * separado, porque un token de Google solo es válido para los scopes con
 * los que se pidió.
 */
class GoogleServiceAccountTokenService
{
    private const URL_TOKEN = 'https://oauth2.googleapis.com/token';

    public function disponible(): bool
    {
        $rutaCredenciales = config('services.google_meet.service_account_path');

        return (bool) config('services.google_meet.habilitado')
            && config('services.google_meet.impersonated_user')
            && $rutaCredenciales
            && file_exists($rutaCredenciales);
    }

    /**
     * @param  string  $scope  uno o más scopes separados por espacio, como exige Google
     */
    public function token(string $scope, ?string $impersonar = null): string
    {
        $impersonar ??= (string) config('services.google_meet.impersonated_user');
        $clave = 'google_meet.token.'.md5($scope.'|'.$impersonar);

        return Cache::remember($clave, now()->addMinutes(50), function () use ($scope, $impersonar) {
            $respuesta = Http::asForm()->post(self::URL_TOKEN, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->firmarJwt($scope, $impersonar),
            ])->throw()->json();

            return $respuesta['access_token'];
        });
    }

    private function firmarJwt(string $scope, string $impersonar): string
    {
        /** @var array{client_email: string, private_key: string} $credenciales */
        $credenciales = json_decode((string) file_get_contents((string) config('services.google_meet.service_account_path')), true);

        $ahora = now()->getTimestamp();

        $encabezado = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $cuerpo = $this->base64Url(json_encode([
            'iss' => $credenciales['client_email'],
            'scope' => $scope,
            'aud' => self::URL_TOKEN,
            'iat' => $ahora,
            'exp' => $ahora + 3600,
            'sub' => $impersonar,
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
