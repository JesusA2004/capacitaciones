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
     * @return array{monto_solicitado: float|null, prestamo_id: int|null, monto_autorizado: float|null, etapas: list<array{clave: string, etiqueta: string, estado: string}>}
     */
    public function paraColaborador(SolicitudInterna $solicitud): array
    {
        $prestamo = $solicitud->prestamo()->with(['contratoDocumento', 'pagareDocumento'])->first();
        $rechazada = $solicitud->estado === EstadoSolicitudInterna::Rechazada;
        $cancelada = $solicitud->estado === EstadoSolicitudInterna::Cancelada;

        $etapas = [['clave' => 'solicitud', 'etiqueta' => 'Solicitud enviada', 'estado' => 'hecho']];

        $vistoBuenoCumplido = true;
        if ($this->aprobaciones->requiereVistoBuenoJefe($solicitud)) {
            $decision = $this->aprobaciones->decisionJefe($solicitud)?->decision;
            $estado = match ($decision) {
                SolicitudAprobacion::DECISION_APROBADO => 'hecho',
                null => ($rechazada || $cancelada) ? 'pendiente' : 'actual',
                default => 'rechazado',
            };
            $vistoBuenoCumplido = $estado === 'hecho';
            $etapas[] = ['clave' => 'visto_bueno', 'etiqueta' => 'Visto bueno de tu jefe', 'estado' => $estado];
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
        ];

        $firmado = $prestamo !== null && ($prestamo->resguardado_en !== null
            || ($this->firmado($prestamo->contratoDocumento) && $this->firmado($prestamo->pagareDocumento)));
        $etapas[] = [
            'clave' => 'firma',
            'etiqueta' => 'Firma de contrato y pagaré',
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
