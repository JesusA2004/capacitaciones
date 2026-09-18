<?php

namespace App\Services\Nomina;

use App\Models\Prestamo;
use App\Models\PrestamoMovimiento;
use App\Models\SolicitudInterna;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Administración real de préstamos internos (ver docs/SOLICITUDES_UNIFICADAS.md
 * y TipoSolicitudInterna::PrestamoInterno). Antes, aprobar una solicitud de
 * préstamo no dejaba ningún registro operativo del préstamo en sí — este
 * servicio es la fuente de verdad de saldo/estado/movimientos, separado de
 * la solicitud que le dio origen.
 *
 * `prestamos.saldo` NUNCA se actualiza a mano fuera de registrarMovimiento():
 * ese método es la única puerta de escritura, siempre a partir del ledger
 * append-only PrestamoMovimiento.
 */
class PrestamoService
{
    /**
     * Crea el préstamo a partir de una solicitud interna ya aprobada. Usa
     * `$solicitud->monto_solicitado`/`plazo_meses` como base si `$datos` no
     * los trae explícitos. Nace en estado 'pendiente_entrega': todavía no
     * cuenta como saldo vivo hasta que RH confirma la entrega (ver activar()).
     *
     * @param  array{monto?: float|string, plazo?: int, periodicidad?: string, pago_programado?: float|string, fecha_otorgamiento?: string, fecha_primer_descuento?: string}  $datos
     */
    public function crearDesdeSolicitud(SolicitudInterna $solicitud, array $datos, User $registradoPor): Prestamo
    {
        return DB::transaction(function () use ($solicitud, $datos, $registradoPor): Prestamo {
            $colaborador = $solicitud->usuario?->colaborador;

            abort_if($colaborador === null, 422, 'La solicitud de préstamo no tiene un colaborador enlazado.');

            $monto = (float) ($datos['monto'] ?? $solicitud->monto_solicitado ?? 0);
            $plazo = (int) ($datos['plazo'] ?? $solicitud->plazo_meses ?? 1);
            $plazo = max($plazo, 1);
            $periodicidad = $datos['periodicidad'] ?? 'quincenal';
            $pagoProgramado = (float) ($datos['pago_programado'] ?? round($monto / $plazo, 2));

            /** @var Prestamo $prestamo */
            $prestamo = Prestamo::query()->create([
                'colaborador_id' => $colaborador->id,
                'solicitud_id' => $solicitud->id,
                'monto_original' => $monto,
                'saldo' => $monto,
                'plazo' => $plazo,
                'periodicidad' => $periodicidad,
                'pago_programado' => $pagoProgramado,
                'fecha_otorgamiento' => $datos['fecha_otorgamiento'] ?? null,
                'fecha_primer_descuento' => $datos['fecha_primer_descuento'] ?? null,
                'estado' => 'pendiente_entrega',
            ]);

            return $prestamo;
        });
    }

    /**
     * Marca el préstamo como activo: RH confirma que el dinero ya se
     * entregó al colaborador. Antes de esto el préstamo existe pero no
     * cuenta como deuda vigente en la UI (ver Colaborador::prestamoActivo()).
     */
    public function activar(Prestamo $prestamo): Prestamo
    {
        $prestamo->update(['estado' => 'activo']);

        return $prestamo->fresh();
    }

    /**
     * Registra un abono/ajuste al saldo del préstamo (ledger append-only:
     * ver PrestamoMovimiento). Si el saldo llega a 0, el préstamo pasa
     * automáticamente a 'liquidado'.
     */
    public function registrarMovimiento(Prestamo $prestamo, float $monto, string $tipo, User $registradoPor): PrestamoMovimiento
    {
        return DB::transaction(function () use ($prestamo, $monto, $tipo, $registradoPor): PrestamoMovimiento {
            $saldoAnterior = (float) $prestamo->saldo;
            $saldoNuevo = round(max($saldoAnterior - $monto, 0), 2);

            /** @var PrestamoMovimiento $movimiento */
            $movimiento = PrestamoMovimiento::query()->create([
                'prestamo_id' => $prestamo->id,
                'fecha' => now()->toDateString(),
                'monto' => $monto,
                'tipo' => $tipo,
                'saldo_anterior' => $saldoAnterior,
                'saldo_nuevo' => $saldoNuevo,
                'registrado_por' => $registradoPor->id,
            ]);

            $prestamo->update([
                'saldo' => $saldoNuevo,
                'estado' => $saldoNuevo <= 0 ? 'liquidado' : $prestamo->estado,
            ]);

            return $movimiento;
        });
    }

    /**
     * Cancela un préstamo (no confundir con liquidado: cancelado significa
     * que nunca se cobró / se dejó sin efecto, no que se pagó por completo).
     */
    public function cancelar(Prestamo $prestamo): Prestamo
    {
        $prestamo->update(['estado' => 'cancelado']);

        return $prestamo->fresh();
    }
}
