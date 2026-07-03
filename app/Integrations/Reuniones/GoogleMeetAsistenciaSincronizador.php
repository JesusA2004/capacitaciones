<?php

namespace App\Integrations\Reuniones;

use App\Integrations\Reuniones\DTO\ParticipanteExterno;
use App\Integrations\Reuniones\DTO\RegistroSesionExterno;
use App\Integrations\Reuniones\DTO\SesionParticipanteExterna;
use App\Models\SesionEnVivo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Asistencia real de Google Meet vía la Google Meet REST API
 * (conferenceRecords / participants / participantSessions), como pide
 * explícitamente el encargo. El correo del participante identificado
 * (`signedinUser.user`, un ID de Directory, no un correo) se resuelve con
 * una llamada adicional a la Admin Directory API — Meet API por sí sola no
 * entrega el correo de un `signedinUser`. Participantes anónimos
 * (`anonymousUser`) o telefónicos (`phoneUser`) nunca tienen correo: quedan
 * como pendiente_revision/anónimos en AsociadorParticipanteService, nunca
 * se asocian por nombre.
 *
 * Ver docs/GOOGLE_MEET.md para los scopes exactos y los pasos de activación.
 */
class GoogleMeetAsistenciaSincronizador implements SincronizadorAsistencia
{
    private const SCOPE_MEET = 'https://www.googleapis.com/auth/meetings.space.readonly';

    private const SCOPE_DIRECTORY = 'https://www.googleapis.com/auth/admin.directory.user.readonly';

    private const URL_CONFERENCE_RECORDS = 'https://meet.googleapis.com/v2/conferenceRecords';

    public function __construct(private readonly GoogleServiceAccountTokenService $auth) {}

    public function estaDisponible(): bool
    {
        return $this->auth->disponible();
    }

    public function obtenerDatosAsistencia(SesionEnVivo $sesion): RegistroSesionExterno
    {
        $codigoReunion = $this->extraerCodigoReunion($sesion);

        if ($codigoReunion === null) {
            throw new \RuntimeException('No se pudo determinar el código de la reunión de Meet a partir del enlace guardado.');
        }

        $tokenMeet = $this->auth->token(self::SCOPE_MEET);

        $respuesta = Http::withToken($tokenMeet)
            ->get(self::URL_CONFERENCE_RECORDS, [
                'filter' => 'space.meeting_code = "'.$codigoReunion.'"',
            ])
            ->throw()
            ->json();

        $registros = $respuesta['conferenceRecords'] ?? [];

        if ($registros === []) {
            throw new \RuntimeException('Todavía no hay un registro de conferencia disponible para esta reunión en Google Meet.');
        }

        // El primero es el más reciente (Meet los ordena por startTime desc).
        $registro = $registros[0];

        $tokenDirectorio = $this->auth->token(self::SCOPE_DIRECTORY);
        $participantes = $this->listarParticipantes($tokenMeet, $tokenDirectorio, $registro['name']);

        return new RegistroSesionExterno(
            identificadorExterno: $registro['name'] ?? null,
            registroConferenciaExterno: $registro['name'] ?? null,
            inicioReal: isset($registro['startTime']) ? Carbon::parse($registro['startTime']) : null,
            finReal: isset($registro['endTime']) ? Carbon::parse($registro['endTime']) : null,
            duracionRealSegundos: isset($registro['startTime'], $registro['endTime'])
                ? (int) Carbon::parse($registro['startTime'])->diffInSeconds(Carbon::parse($registro['endTime']))
                : null,
            participantes: $participantes,
            respuestaNormalizada: $registro,
        );
    }

    /**
     * @return array<int, ParticipanteExterno>
     */
    private function listarParticipantes(string $tokenMeet, string $tokenDirectorio, string $conferenceRecordName): array
    {
        $participantes = [];
        $paginaSiguiente = null;

        do {
            $respuesta = Http::withToken($tokenMeet)
                ->get("https://meet.googleapis.com/v2/{$conferenceRecordName}/participants", array_filter([
                    'pageSize' => 100,
                    'pageToken' => $paginaSiguiente,
                ]))
                ->throw()
                ->json();

            foreach ($respuesta['participants'] ?? [] as $participante) {
                $participantes[] = $this->normalizarParticipante($tokenMeet, $tokenDirectorio, $participante);
            }

            $paginaSiguiente = $respuesta['nextPageToken'] ?? null;
        } while ($paginaSiguiente);

        return $participantes;
    }

    /**
     * @param  array<string, mixed>  $participante
     */
    private function normalizarParticipante(string $tokenMeet, string $tokenDirectorio, array $participante): ParticipanteExterno
    {
        $correo = null;
        $nombre = null;

        if (isset($participante['signedinUser'])) {
            $nombre = $participante['signedinUser']['displayName'] ?? null;
            $recursoUsuario = $participante['signedinUser']['user'] ?? null;

            if ($recursoUsuario) {
                $correo = $this->resolverCorreo($tokenDirectorio, $recursoUsuario);
            }
        } elseif (isset($participante['anonymousUser'])) {
            $nombre = $participante['anonymousUser']['displayName'] ?? 'Participante anónimo';
        } elseif (isset($participante['phoneUser'])) {
            $nombre = $participante['phoneUser']['displayName'] ?? 'Participante telefónico';
        }

        return new ParticipanteExterno(
            identificadorExterno: $participante['name'],
            correo: $correo,
            nombre: $nombre,
            sesiones: $this->listarSesionesDeParticipante($tokenMeet, $participante['name']),
        );
    }

    /**
     * @return array<int, SesionParticipanteExterna>
     */
    private function listarSesionesDeParticipante(string $tokenMeet, string $participantName): array
    {
        $sesiones = [];
        $paginaSiguiente = null;

        do {
            $respuesta = Http::withToken($tokenMeet)
                ->get("https://meet.googleapis.com/v2/{$participantName}/participantSessions", array_filter([
                    'pageSize' => 100,
                    'pageToken' => $paginaSiguiente,
                ]))
                ->throw()
                ->json();

            foreach ($respuesta['participantSessions'] ?? [] as $sesion) {
                if (! isset($sesion['startTime'])) {
                    continue;
                }

                $sesiones[] = new SesionParticipanteExterna(
                    inicio: Carbon::parse($sesion['startTime']),
                    fin: isset($sesion['endTime']) ? Carbon::parse($sesion['endTime']) : null,
                    origen: 'google_meet',
                    identificadorExterno: $sesion['name'] ?? null,
                );
            }

            $paginaSiguiente = $respuesta['nextPageToken'] ?? null;
        } while ($paginaSiguiente);

        return $sesiones;
    }

    /**
     * Resuelve el correo real de un participante identificado a partir del
     * recurso `users/{id}` que entrega Meet, vía la Admin Directory API. Si
     * falla (usuario externo sin cuenta en el dominio, permisos
     * insuficientes, etc.) devuelve null en vez de propagar el error: un
     * correo no resuelto dejará al participante como pendiente_revision,
     * nunca bloquea el resto de la sincronización.
     */
    private function resolverCorreo(string $tokenDirectorio, string $recursoUsuario): ?string
    {
        $id = Str::after($recursoUsuario, 'users/');

        try {
            $respuesta = Http::withToken($tokenDirectorio)
                ->get("https://admin.googleapis.com/admin/directory/v1/users/{$id}", ['fields' => 'primaryEmail'])
                ->throw()
                ->json();

            return $respuesta['primaryEmail'] ?? null;
        } catch (\Throwable $excepcion) {
            report($excepcion);

            return null;
        }
    }

    private function extraerCodigoReunion(SesionEnVivo $sesion): ?string
    {
        if ($sesion->enlace_reunion && preg_match('#meet\.google\.com/([a-z0-9-]+)#i', $sesion->enlace_reunion, $coincidencias)) {
            return $coincidencias[1];
        }

        return null;
    }
}
