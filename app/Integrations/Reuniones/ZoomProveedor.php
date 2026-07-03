<?php

namespace App\Integrations\Reuniones;

use App\Models\SesionEnVivo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Integracion real con la API de Zoom via Server-to-Server OAuth: obtiene un
 * token de cuenta (sin flujo interactivo de usuario) y crea/cancela la
 * reunion como el usuario configurado en ZOOM_HOST_EMAIL. Mientras no este
 * habilitada (o falten credenciales), se degrada con gracia: no genera
 * ningun enlace automatico y la sesion queda igual guardada para que el
 * instructor agregue un enlace a mano si lo necesita.
 */
class ZoomProveedor implements ProveedorSesionEnVivo
{
    public function estaDisponible(): bool
    {
        return (bool) config('services.zoom.habilitado')
            && config('services.zoom.account_id')
            && config('services.zoom.client_id')
            && config('services.zoom.client_secret')
            && config('services.zoom.host_email');
    }

    public function crearReunion(SesionEnVivo $sesion): void
    {
        if (! $this->estaDisponible()) {
            return;
        }

        $respuesta = Http::withToken($this->obtenerToken())
            ->post('https://api.zoom.us/v2/users/'.config('services.zoom.host_email').'/meetings', [
                'topic' => $sesion->titulo,
                'type' => 2,
                'start_time' => $sesion->fecha_inicio->utc()->format('Y-m-d\TH:i:s\Z'),
                'duration' => $sesion->duracion_minutos,
                'timezone' => 'UTC',
                'settings' => [
                    'join_before_host' => true,
                    'waiting_room' => false,
                ],
            ])
            ->throw()
            ->json();

        $sesion->update([
            'enlace_reunion' => $respuesta['join_url'] ?? null,
            'id_reunion_externa' => isset($respuesta['id']) ? (string) $respuesta['id'] : null,
            'datos_proveedor' => $respuesta,
        ]);
    }

    public function cancelarReunion(SesionEnVivo $sesion): void
    {
        if (! $this->estaDisponible() || ! $sesion->id_reunion_externa) {
            return;
        }

        Http::withToken($this->obtenerToken())
            ->delete('https://api.zoom.us/v2/meetings/'.$sesion->id_reunion_externa)
            ->throw();
    }

    /**
     * El token de cuenta S2S dura 1 hora; se cachea con un margen de
     * seguridad para no pedir uno nuevo en cada llamada.
     */
    private function obtenerToken(): string
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
