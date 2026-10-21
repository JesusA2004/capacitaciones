<?php

namespace App\Services\Expedientes;

use App\Enums\MotivoBloqueoDocumentos;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Incorporacion\RegistroAppService;

/**
 * ¿La app de esta cuenta está limitada a subir sus documentos pendientes?
 *
 * - Sin documentos exigibles pendientes (DocumentosPendientesService): no.
 * - Con carta compromiso dentro de plazo (persona de nuevo ingreso): no;
 *   la app muestra el aviso con la fecha límite.
 * - Con la carta vencida: sí («carta_vencida»).
 * - Nuevo ingreso que cerró su registro con más faltantes de los que cubre
 *   una carta (carta diferida): sí («excede_maximo_carta») hasta que le
 *   falten `max_documentos_carta` o menos; entonces la carta se genera sola
 *   y el bloqueo se levanta.
 * - Sin carta (personal que ya laboraba): sí, de inmediato («sin_carta»).
 *
 * Nunca a super_admin, nunca mientras el registro en la app sigue abierto
 * (ese asistente ya tiene su propio candado) y nunca con
 * config('expedientes.bloqueo_documentos_faltantes') = false.
 *
 * @phpstan-type EstadoBloqueo array{activo: bool, motivo: string|null, fecha_limite: string|null, pendientes: int, max_documentos_carta: int}
 */
class BloqueoDocumentosService
{
    public function __construct(
        private readonly DocumentosPendientesService $pendientes,
        private readonly CartaCompromisoService $cartas,
        private readonly RegistroAppService $registro,
    ) {}

    /**
     * @return EstadoBloqueo
     */
    public function estado(User $usuario): array
    {
        $colaborador = $usuario->colaborador;

        if ($colaborador === null) {
            return $this->sinBloqueo(0, null);
        }

        return $this->estadoDe($usuario, $colaborador, count($this->pendientes->pendientes($colaborador)));
    }

    /**
     * Todo lo que la pantalla «Documentos pendientes» de la app necesita
     * (GET colaborador/carta-compromiso). Pone al día el estado de la carta
     * (y genera la carta diferida si ya le faltan max o menos).
     *
     * @return array{carta: array<string, mixed>|null, documentos_pendientes: list<array<string, mixed>>, bloqueo: EstadoBloqueo}
     */
    public function resumen(User $usuario, Colaborador $colaborador): array
    {
        $carta = $this->cartas->sincronizar($colaborador);
        $pendientes = $this->pendientes->pendientes($colaborador);

        return [
            'carta' => $carta !== null ? $this->cartas->aArray($carta) : null,
            'documentos_pendientes' => $pendientes,
            'bloqueo' => $this->estadoDe($usuario, $colaborador, count($pendientes)),
        ];
    }

    /**
     * Para /me y login: carta más reciente en su forma corta.
     *
     * @return array{folio: string|null, fecha_limite: string, dias_restantes: int, estado: string}|null
     */
    public function cartaResumida(User $usuario): ?array
    {
        $colaborador = $usuario->colaborador;
        $carta = $colaborador !== null ? $this->cartas->ultima($colaborador) : null;

        return $carta !== null ? $this->cartas->resumenCorto($carta) : null;
    }

    /**
     * @return EstadoBloqueo
     */
    private function estadoDe(User $usuario, Colaborador $colaborador, int $pendientes): array
    {
        if ($pendientes === 0) {
            return $this->sinBloqueo(0, null);
        }

        $carta = $this->cartas->ultima($colaborador);

        if (! (bool) config('expedientes.bloqueo_documentos_faltantes', true)
            || $usuario->hasRole('super_admin')
            || $this->registro->aplica($usuario)) {
            return $this->sinBloqueo($pendientes, $carta?->fecha_limite->toDateString());
        }

        $diferida = $carta === null && $this->cartas->esperaCartaAutomatica($colaborador);

        // Carta diferida que ya puede generarse (p. ej. RH dejó de exigir un
        // documento): se genera aquí mismo y se evalúa con ella.
        if ($diferida && $pendientes <= $this->cartas->maxDocumentos()) {
            $carta = $this->cartas->sincronizarSinFallar($colaborador);
            $diferida = $carta === null;
        }

        $fechaLimite = $carta?->fecha_limite->toDateString();

        if ($carta !== null && ! $carta->plazoVencido()) {
            return $this->sinBloqueo($pendientes, $fechaLimite);
        }

        $motivo = match (true) {
            $carta !== null => MotivoBloqueoDocumentos::CartaVencida,
            $diferida => MotivoBloqueoDocumentos::ExcedeMaximoCarta,
            default => MotivoBloqueoDocumentos::SinCarta,
        };

        return [
            'activo' => true,
            'motivo' => $motivo->value,
            'fecha_limite' => $fechaLimite,
            'pendientes' => $pendientes,
            'max_documentos_carta' => $this->cartas->maxDocumentos(),
        ];
    }

    /**
     * @return EstadoBloqueo
     */
    private function sinBloqueo(int $pendientes, ?string $fechaLimite): array
    {
        return [
            'activo' => false,
            'motivo' => null,
            'fecha_limite' => $fechaLimite,
            'pendientes' => $pendientes,
            'max_documentos_carta' => $this->cartas->maxDocumentos(),
        ];
    }
}
