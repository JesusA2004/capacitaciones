<?php

namespace App\Services\Reuniones;

use App\Enums\EstadoIdentificacionParticipante;
use App\Enums\TipoParticipante;
use App\Integrations\Reuniones\DTO\ParticipanteExterno;
use App\Integrations\Reuniones\DTO\RegistroSesionExterno;
use App\Models\RegistroSesion;
use App\Models\SesionParticipante;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Traduce los participantes normalizados de cualquier proveedor
 * (App\Integrations\Reuniones\DTO\ParticipanteExterno) a filas de
 * `sesiones_participante` + `entradas_salidas_participante`, aplicando
 * siempre la misma regla de asociación: un `User` solo se vincula por
 * coincidencia exacta de correo electrónico, nunca por nombre. Reutilizado
 * tanto por Google Meet como por Zoom para no duplicar esta lógica crítica
 * de seguridad en cada integración. Ver docs/AUDITORIA_CUMPLIMIENTO.md
 * sección 4.
 */
class AsociadorParticipanteService
{
    /**
     * @return array<int, SesionParticipante>
     */
    public function asociar(RegistroSesion $registro, RegistroSesionExterno $datos): array
    {
        return array_map(
            fn (ParticipanteExterno $externo) => $this->procesarParticipante($registro, $externo),
            $datos->participantes,
        );
    }

    private function procesarParticipante(RegistroSesion $registro, ParticipanteExterno $externo): SesionParticipante
    {
        [$tipo, $estadoIdentificacion, $usuario] = $this->identificar($externo->correo);

        $sesionParticipante = SesionParticipante::updateOrCreate(
            [
                'registro_sesion_id' => $registro->id,
                'identificador_externo' => $externo->identificadorExterno,
            ],
            [
                'user_id' => $usuario?->id,
                'correo_detectado' => $externo->correo,
                'nombre_mostrado' => $externo->nombre,
                'tipo_participante' => $tipo->value,
                'estado_identificacion' => $estadoIdentificacion->value,
            ],
        );

        // Idempotente: se reemplaza el conjunto completo de tramos en cada
        // sincronización en vez de intentar diferenciar altas/bajas — el
        // proveedor es la fuente de verdad completa en cada consulta.
        $sesionParticipante->entradasSalidas()->delete();

        foreach ($externo->sesiones as $tramo) {
            $sesionParticipante->entradasSalidas()->create([
                'inicio' => $tramo->inicio,
                'fin' => $tramo->fin,
                'duracion_segundos' => $tramo->duracionSegundos(),
                'origen' => $tramo->origen,
                'identificador_externo' => $tramo->identificadorExterno,
            ]);
        }

        $minutosAcumulados = (int) round(
            array_sum(array_map(fn ($tramo) => $tramo->duracionSegundos() ?? 0, $externo->sesiones)) / 60,
        );

        $sesionParticipante->update([
            'minutos_acumulados' => $minutosAcumulados,
            'numero_reconexiones' => max(0, count($externo->sesiones) - 1),
        ]);

        return $sesionParticipante->fresh();
    }

    /**
     * @return array{0: TipoParticipante, 1: EstadoIdentificacionParticipante, 2: User|null}
     */
    private function identificar(?string $correo): array
    {
        if ($correo === null || trim($correo) === '') {
            return [TipoParticipante::Anonimo, EstadoIdentificacionParticipante::PendienteRevision, null];
        }

        $coincidencias = User::query()->whereRaw('LOWER(email) = ?', [Str::lower(trim($correo))])->get();

        if ($coincidencias->count() === 1) {
            return [TipoParticipante::Interno, EstadoIdentificacionParticipante::Identificado, $coincidencias->first()];
        }

        if ($coincidencias->count() > 1) {
            // No debería ocurrir (email es unico en users), pero si pasa, no
            // se adivina cual es el usuario correcto: queda para revision.
            return [TipoParticipante::Externo, EstadoIdentificacionParticipante::PendienteRevision, null];
        }

        // Correo real y sin ambigüedad, pero no pertenece a ningún
        // colaborador registrado: es un externo identificado (proveedor,
        // invitado), no requiere revisión solo por eso.
        return [TipoParticipante::Externo, EstadoIdentificacionParticipante::Identificado, null];
    }
}
