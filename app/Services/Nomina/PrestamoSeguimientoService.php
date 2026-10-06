<?php

namespace App\Services\Nomina;

use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstadoSolicitudInterna;
use App\Models\GeneratedDocument;
use App\Models\SolicitudAprobacion;
use App\Models\SolicitudInterna;
use App\Services\Solicitudes\AprobacionJerarquicaService;

/**
 * Seguimiento de un préstamo para el COLABORADOR (la app solo pinta esto):
 * lo que pidió y en qué etapa va — visto bueno del jefe (solo si aplica),
 * revisión y autorización de RH, y firma de contrato/pagaré. Nunca expone
 * datos de decisión internos (comentarios de RH, montos de otros, etc.).
 *
 * Etapas: `hecho` | `actual` | `pendiente` | `rechazado`.
 */
class PrestamoSeguimientoService
{
    private const FIRMADOS = [
        EstadoFlujoDocumento::FirmadoDigitalmente,
        EstadoFlujoDocumento::FirmadoFisicamente,
        EstadoFlujoDocumento::EnviadoCorporativo,
        EstadoFlujoDocumento::RecibidoCorporativo,
        EstadoFlujoDocumento::Escaneado,
        EstadoFlujoDocumento::Archivado,
    ];

    public function __construct(private readonly AprobacionJerarquicaService $aprobaciones) {}

    /**
     * @return array{monto_solicitado: float|null, prestamo_id: int|null, monto_autorizado: float|null, etapas: list<array{clave: string, etiqueta: string, estado: string, aprobador: string|null}>}
     */
    public function paraColaborador(SolicitudInterna $solicitud): array
    {
        $prestamo = $solicitud->prestamo()->with(['contratoDocumento', 'pagareDocumento'])->first();
        $rechazada = $solicitud->estado === EstadoSolicitudInterna::Rechazada;
        $cancelada = $solicitud->estado === EstadoSolicitudInterna::Cancelada;

        $etapas = [['clave' => 'solicitud', 'etiqueta' => 'Solicitud enviada', 'estado' => 'hecho', 'aprobador' => null]];

        // Un paso POR NIVEL (gerente, regional, dirección comercial si
        // aplica): el colaborador debe ver a quién le toca en cada momento,
        // nunca un "visto bueno de tu jefe" genérico que esconda los demás
        // niveles. Nunca se expone el comentario (solo RH lo ve).
        $vistoBuenoCumplido = true;
        $actualAsignado = false;

        foreach ($this->aprobaciones->resumen($solicitud) as $nivel) {
            $estado = match (true) {
                $nivel['estado'] === SolicitudAprobacion::DECISION_APROBADO => 'hecho',
                $nivel['estado'] === SolicitudAprobacion::DECISION_RECHAZADO => 'rechazado',
                ! $actualAsignado && ! $rechazada && ! $cancelada => 'actual',
                default => 'pendiente',
            };

            if ($estado !== 'hecho') {
                $vistoBuenoCumplido = false;
            }

            if (in_array($estado, ['actual', 'rechazado'], true)) {
                $actualAsignado = true;
            }

            $etapas[] = [
                'clave' => 'visto_bueno_'.$nivel['nivel'],
                'etiqueta' => sprintf('Visto bueno: %s', $nivel['etiqueta']),
                'estado' => $estado,
                'aprobador' => $nivel['decidio'] ?? ($nivel['aprobadores'][0] ?? null),
            ];
        }

        $autorizado = $prestamo !== null;
        $etapas[] = [
            'clave' => 'autorizacion',
            'etiqueta' => 'Revisión y autorización de RH',
            'estado' => match (true) {
                $autorizado => 'hecho',
                $rechazada && $vistoBuenoCumplido => 'rechazado',
                $vistoBuenoCumplido && ! $cancelada => 'actual',
                default => 'pendiente',
            },
            'aprobador' => null,
        ];

        $firmado = $prestamo !== null && ($prestamo->resguardado_en !== null
            || ($this->firmado($prestamo->contratoDocumento) && $this->firmado($prestamo->pagareDocumento)));
        $etapas[] = [
            'clave' => 'firma',
            'etiqueta' => 'Firma de contrato y pagaré',
            'aprobador' => null,
            'estado' => $firmado ? 'hecho' : ($autorizado ? 'actual' : 'pendiente'),
        ];

        return [
            'monto_solicitado' => $solicitud->monto_solicitado !== null ? (float) $solicitud->monto_solicitado : null,
            'prestamo_id' => $prestamo?->id,
            'monto_autorizado' => $prestamo !== null ? (float) $prestamo->monto_original : null,
            'etapas' => $etapas,
        ];
    }

    private function firmado(?GeneratedDocument $documento): bool
    {
        return $documento !== null && in_array($documento->estado_flujo, self::FIRMADOS, true);
    }
}
