<?php

namespace App\Services\Nomina;

use App\Models\Prestamo;
use App\Models\PrestamoMovimiento;
use App\Models\SolicitudInterna;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
     * @param  array<string, mixed>  $datos  monto_autorizado/plazo_autorizado (o monto/plazo), periodicidad, pago_programado, fechas, observaciones.
     */
    public function crearDesdeSolicitud(SolicitudInterna $solicitud, array $datos, User $registradoPor): Prestamo
    {
        return DB::transaction(function () use ($solicitud, $datos, $registradoPor): Prestamo {
            $colaborador = $solicitud->personaSolicitante();

            abort_if($colaborador === null, 422, 'La solicitud de préstamo no tiene un colaborador enlazado.');

            $existente = Prestamo::query()->where('solicitud_id', $solicitud->id)->first();

            if ($existente !== null) {
                return $existente;
            }

            $monto = (float) ($datos['monto_autorizado'] ?? $datos['monto'] ?? $solicitud->monto_solicitado ?? 0);
            $plazo = (int) ($datos['plazo_autorizado'] ?? $datos['plazo'] ?? $solicitud->plazo_meses ?? 1);
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
                'monto_solicitado' => $solicitud->monto_solicitado,
                'plazo_solicitado' => $solicitud->plazo_meses,
                'motivo' => $solicitud->motivo,
                'fecha_solicitud' => $solicitud->created_at?->toDateString(),
                'observaciones' => $datos['observaciones'] ?? null,
                'autorizado_por' => $registradoPor->id,
                'autorizado_en' => now(),
            ]);

            return $prestamo;
        });
    }

    /**
     * Marca el préstamo como activo: RH confirma que el dinero ya se
     * entregó al colaborador, capturando los datos reales de la entrega
     * (antes de esto el préstamo existe pero no cuenta como deuda vigente
     * en la UI, ver Colaborador::prestamoActivo()). Usa lockForUpdate()
     * para que dos confirmaciones simultáneas del mismo préstamo no
     * pisen el estado una a la otra.
     *
     * @param  array{fecha_otorgamiento: string, fecha_primer_descuento: string, periodicidad: string, pago_programado: float|string}  $datos
     */
    public function activar(Prestamo $prestamo, array $datos): Prestamo
    {
        return DB::transaction(function () use ($prestamo, $datos): Prestamo {
            /** @var Prestamo $prestamo */
            $prestamo = Prestamo::query()->lockForUpdate()->findOrFail($prestamo->id);

            if ($prestamo->estado !== 'pendiente_entrega') {
                throw ValidationException::withMessages([
                    'estado' => 'Este préstamo ya no está pendiente de entrega.',
                ]);
            }

            $prestamo->update([
                'estado' => 'activo',
                'fecha_otorgamiento' => $datos['fecha_otorgamiento'],
                'fecha_primer_descuento' => $datos['fecha_primer_descuento'],
                'periodicidad' => $datos['periodicidad'],
                'pago_programado' => $datos['pago_programado'],
            ]);

            return $prestamo->fresh();
        });
    }

    /**
     * Registra un abono/ajuste al saldo del préstamo (ledger append-only:
     * ver PrestamoMovimiento). Si el saldo llega a 0, el préstamo pasa
     * automáticamente a 'liquidado'. lockForUpdate() evita que dos abonos
     * concurrentes (p. ej. dos recibos de nómina generados al mismo
     * tiempo) lean el mismo saldo y se pisen entre sí.
     *
     * 'ajuste' es la única excepción que puede exceder el saldo vigente
     * (una corrección deliberada de RH/contabilidad, no un pago normal):
     * 'manual'/'nomina' nunca pueden dejar el saldo en negativo en
     * silencio, se rechazan con una validación explícita.
     */
    public function registrarMovimiento(Prestamo $prestamo, float $monto, string $tipo, User $registradoPor): PrestamoMovimiento
    {
        if ($monto <= 0) {
            throw ValidationException::withMessages([
                'monto' => 'El monto debe ser mayor a cero.',
            ]);
        }

        return DB::transaction(function () use ($prestamo, $monto, $tipo, $registradoPor): PrestamoMovimiento {
            /** @var Prestamo $prestamo */
            $prestamo = Prestamo::query()->lockForUpdate()->findOrFail($prestamo->id);

            if ($prestamo->estado !== 'activo') {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se pueden registrar movimientos sobre un préstamo activo.',
                ]);
            }

            $saldoAnterior = (float) $prestamo->saldo;

            if ($tipo !== 'ajuste' && $monto > $saldoAnterior) {
                throw ValidationException::withMessages([
                    'monto' => sprintf('El monto excede el saldo actual del préstamo ($%s).', number_format($saldoAnterior, 2)),
                ]);
            }

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
