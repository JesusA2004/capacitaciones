<?php

namespace App\Integrations\Reuniones;

use App\Models\SesionEnVivo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Integracion real con Google Calendar (un enlace de Meet no se crea con una
 * API propia: se crea pidiendo un "createRequest" de Meet dentro de un
 * evento de Calendar). Se autentica con una cuenta de servicio con
 * delegacion de dominio completa, actuando como GOOGLE_IMPERSONATED_USER
 * (GoogleServiceAccountTokenService), firmando el JWT a mano con las
 * funciones nativas de OpenSSL de PHP en vez de instalar el SDK oficial de
 * Google (`google/apiclient`): ese paquete arrastra
 * `google/apiclient-services`, que agrega clases para cientos de APIs de
 * Google que no se usan aqui, y su autoload de mas de 30,000 archivos
 * resulto poco practico en este entorno de desarrollo. Llamar directamente
 * al API REST con el cliente HTTP de Laravel es equivalente y mas ligero.
 *
 * Mientras no este habilitada (o falte el archivo de credenciales), se
 * degrada con gracia: no genera ningun enlace automatico y la sesion queda
 * igual guardada para que el instructor agregue un enlace a mano.
 *
 * La asistencia real (conferenceRecords/participants) la resuelve
 * GoogleMeetAsistenciaSincronizador, no esta clase: crear la reunion y leer
 * su asistencia usan scopes distintos de Google.
 */
class GoogleMeetProveedor implements ProveedorSesionEnVivo
{
    private const SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    private function urlEventos(): string
    {
        return 'https://www.googleapis.com/calendar/v3/calendars/'.config('services.google_meet.calendar_id', 'primary').'/events';
    }

    public function __construct(private readonly GoogleServiceAccountTokenService $auth) {}

    public function estaDisponible(): bool
    {
        return $this->auth->disponible();
    }

    public function crearReunion(SesionEnVivo $sesion): void
    {
        if (! $this->estaDisponible()) {
            return;
        }

        $respuesta = Http::withToken($this->auth->token(self::SCOPE))
            ->post($this->urlEventos().'?conferenceDataVersion=1', [
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

        Http::withToken($this->auth->token(self::SCOPE))
            ->delete($this->urlEventos().'/'.$sesion->id_reunion_externa)
            ->throw();
    }
}
