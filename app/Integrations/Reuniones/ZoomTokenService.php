<?php

namespace App\Integrations\Reuniones;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Autenticación Server-to-Server OAuth de Zoom, compartida por todas las
 * integraciones (creación de reuniones, reportes de asistencia). El token
 * de cuenta dura 1 hora; se cachea con margen de seguridad para no pedir
 * uno nuevo en cada llamada.
 */
class ZoomTokenService
{
    public function disponible(): bool
    {
        return (bool) config('services.zoom.habilitado')
            && config('services.zoom.account_id')
            && config('services.zoom.client_id')
            && config('services.zoom.client_secret')
            && config('services.zoom.host_email');
    }

    public function token(): string
    {
        return Cache::remember('zoom.token_cuenta', now()->addMinutes(50), function () {
            $respuesta = Http::asForm()
                ->withBasicAuth(config('services.zoom.client_id'), config('services.zoom.client_secret'))
                ->post('https://zoom.us/oauth/token', [
                    'grant_type' => 'account_credentials',
                    'account_id' => config('services.zoom.account_id'),
                ])
                ->throw()
                ->json();

            return $respuesta['access_token'];
        });
    }
}
