<?php

namespace App\Integrations\Reuniones;

use App\Models\SesionEnVivo;
use Illuminate\Support\Facades\Http;

/**
 * Integracion real con la API de Zoom via Server-to-Server OAuth: obtiene un
 * token de cuenta (sin flujo interactivo de usuario) y crea/cancela la
 * reunion como el usuario configurado en ZOOM_HOST_EMAIL. Mientras no este
 * habilitada (o falten credenciales), se degrada con gracia: no genera
 * ningun enlace automatico y la sesion queda igual guardada para que el
 * instructor agregue un enlace a mano si lo necesita.
 *
 * La asistencia real (Report API) la resuelve ZoomAsistenciaSincronizador,
 * no esta clase.
 */
class ZoomProveedor implements ProveedorSesionEnVivo
{
    public function __construct(private readonly ZoomTokenService $auth) {}

    public function estaDisponible(): bool
    {
        return $this->auth->disponible();
    }

    public function crearReunion(SesionEnVivo $sesion): void
    {
        if (! $this->estaDisponible()) {
            return;
        }

        $respuesta = Http::withToken($this->auth->token())
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

        Http::withToken($this->auth->token())
            ->delete('https://api.zoom.us/v2/meetings/'.$sesion->id_reunion_externa)
            ->throw();
    }
}
