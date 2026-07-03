<?php

namespace App\Integrations\Reuniones;

use App\Integrations\Reuniones\DTO\ParticipanteExterno;
use App\Integrations\Reuniones\DTO\RegistroSesionExterno;
use App\Integrations\Reuniones\DTO\SesionParticipanteExterna;
use App\Models\SesionEnVivo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Asistencia real de Zoom vía la Report API
 * (`GET /report/meetings/{meetingId}/participants`), disponible una vez que
 * la reunión terminó. Cada reconexión aparece como una fila independiente
 * en la respuesta de Zoom (mismo participante, varias entradas/salidas);
 * se agrupan aquí por correo (o por `user_id`/nombre si Zoom no reporta
 * correo, típico de invitados externos) antes de normalizar. Ver
 * docs/AUDITORIA_CUMPLIMIENTO.md sección 4.
 */
class ZoomAsistenciaSincronizador implements SincronizadorAsistencia
{
    public function __construct(private readonly ZoomTokenService $auth) {}

    public function estaDisponible(): bool
    {
        return $this->auth->disponible();
    }

    public function obtenerDatosAsistencia(SesionEnVivo $sesion): RegistroSesionExterno
    {
        if ($sesion->id_reunion_externa === null) {
            throw new \RuntimeException('La sesión no tiene un identificador de reunión de Zoom asociado.');
        }

        $token = $this->auth->token();

        $reunion = Http::withToken($token)
            ->get("https://api.zoom.us/v2/report/meetings/{$sesion->id_reunion_externa}")
            ->throw()
            ->json();

        if (($reunion['end_time'] ?? null) === null) {
            throw new \RuntimeException('El reporte de la reunión todavía no está disponible en Zoom (la reunión no ha finalizado o el reporte no se ha generado).');
        }

        $filas = $this->listarParticipantes($token, $sesion->id_reunion_externa);
        $participantes = $this->agruparParticipantes($filas);

        return new RegistroSesionExterno(
            identificadorExterno: (string) ($reunion['id'] ?? $sesion->id_reunion_externa),
            registroConferenciaExterno: $reunion['uuid'] ?? null,
            inicioReal: isset($reunion['start_time']) ? Carbon::parse($reunion['start_time']) : null,
            finReal: isset($reunion['end_time']) ? Carbon::parse($reunion['end_time']) : null,
            duracionRealSegundos: isset($reunion['duration']) ? ((int) $reunion['duration']) * 60 : null,
            participantes: $participantes,
            respuestaNormalizada: $reunion,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listarParticipantes(string $token, string $idReunion): array
    {
        $filas = [];
        $paginaSiguiente = null;

        do {
            $respuesta = Http::withToken($token)
                ->get("https://api.zoom.us/v2/report/meetings/{$idReunion}/participants", array_filter([
                    'page_size' => 300,
                    'next_page_token' => $paginaSiguiente,
                ]))
                ->throw()
                ->json();

            foreach ($respuesta['participants'] ?? [] as $fila) {
                $filas[] = $fila;
            }

            $paginaSiguiente = $respuesta['next_page_token'] ?? null;
        } while ($paginaSiguiente);

        return $filas;
    }

    /**
     * @param  array<int, array<string, mixed>>  $filas
     * @return array<int, ParticipanteExterno>
     */
    private function agruparParticipantes(array $filas): array
    {
        $grupos = [];

        foreach ($filas as $fila) {
            $correo = ! empty($fila['user_email']) ? mb_strtolower(trim($fila['user_email'])) : null;
            $clave = $correo ?? ('sin-correo:'.($fila['name'] ?? 'anonimo').':'.($fila['id'] ?? $fila['user_id'] ?? uniqid('', true)));

            $grupos[$clave] ??= [
                'identificador' => $fila['id'] ?? $fila['user_id'] ?? $clave,
                'correo' => $correo,
                'nombre' => $fila['name'] ?? null,
                'sesiones' => [],
            ];

            if (isset($fila['join_time'])) {
                $grupos[$clave]['sesiones'][] = new SesionParticipanteExterna(
                    inicio: Carbon::parse($fila['join_time']),
                    fin: isset($fila['leave_time']) ? Carbon::parse($fila['leave_time']) : null,
                    origen: 'zoom',
                    identificadorExterno: isset($fila['id']) ? (string) $fila['id'] : null,
                );
            }
        }

        return array_values(array_map(
            fn (array $grupo) => new ParticipanteExterno(
                identificadorExterno: (string) $grupo['identificador'],
                correo: $grupo['correo'],
                nombre: $grupo['nombre'],
                sesiones: $grupo['sesiones'],
            ),
            $grupos,
        ));
    }
}
