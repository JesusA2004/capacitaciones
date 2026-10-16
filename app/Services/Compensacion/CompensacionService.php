<?php

namespace App\Services\Compensacion;

use App\Enums\TipoMovimientoLaboral;
use App\Models\Colaborador;
use App\Models\HistorialSalario;
use App\Models\MovimientoLaboral;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fuente única de cálculo salarial (CLAUDE.md "Compensación centralizada").
 * Regla fija, sin excepción de mes ni de año bisiesto: salario mensual =
 * salario diario × 30 SIEMPRE. La quincena ordinaria paga 15 días
 * salariales siempre, sin importar si el periodo calendario (16 al último
 * día real del mes) tiene 15 o 16 días. La semana es lunes→domingo, nunca
 * se confunde con la base mensual de 30.
 */
class CompensacionService
{
    public function mensual(float $salarioDiario): float
    {
        return round($salarioDiario * 30, 2);
    }

    /**
     * Pago base de una quincena ORDINARIA: siempre 15 días salariales (el
     * periodo calendario de la segunda quincena puede tener 16 días en
     * meses de 31 — eso no cambia el pago base).
     */
    public function quincena(float $salarioDiario): float
    {
        return round($salarioDiario * 15, 2);
    }

    public function semanal(float $salarioDiario): float
    {
        return round($salarioDiario * 7, 2);
    }

    /**
     * Salario diario a partir del mensual (mensual = diario × 30 ⇒ diario =
     * mensual ÷ 30) — el ÚNICO lugar del sistema donde se divide entre 30.
     */
    public function diarioDesdeMensual(float $mensual): float
    {
        return round($mensual / 30, 2);
    }

    /**
     * Quincena ordinaria (15 días salariales) cuando el dato capturado es el
     * mensual: (mensual ÷ 30) × 15 sin redondear el diario intermedio, que
     * es exactamente la mitad del mensual — así no se pierde un centavo.
     */
    public function quincenaDesdeMensual(float $mensual): float
    {
        return round($mensual / 2, 2);
    }

    /**
     * Pago proporcional de $dias días salariales (ingreso a media
     * quincena/semana) a partir del mensual, sin redondear el diario
     * intermedio.
     */
    public function proporcionalDesdeMensual(float $mensual, int $dias): float
    {
        return round($mensual / 30 * $dias, 2);
    }

    /**
     * Salario diario vigente de una persona: salario_diario (fuente real que
     * escribe actualizarSalario()); si aún no está sincronizado, el mensual
     * ÷ 30 como respaldo.
     */
    public function diarioDe(Colaborador $colaborador): float
    {
        return $colaborador->salario_diario !== null
            ? (float) $colaborador->salario_diario
            : $this->diarioDesdeMensual((float) ($colaborador->sueldo_mensual ?? 0));
    }

    /**
     * Periodo CALENDARIO de la quincena que contiene $fecha: 1-15, o 16 al
     * último día real del mes (28/29/30/31). `dias_salariales` es SIEMPRE
     * 15 — es el dato que importa para el pago, no los días calendario.
     *
     * @return array{inicio: Carbon, fin: Carbon, dias_calendario: int, dias_salariales: int}
     */
    public function periodoQuincena(Carbon $fecha): array
    {
        if ($fecha->day <= 15) {
            $inicio = $fecha->copy()->startOfMonth();
            $fin = $fecha->copy()->startOfMonth()->addDays(14);
        } else {
            $inicio = $fecha->copy()->startOfMonth()->addDays(15);
            $fin = $fecha->copy()->endOfMonth()->startOfDay();
        }

        return [
            'inicio' => $inicio,
            'fin' => $fin,
            'dias_calendario' => (int) $inicio->diffInDays($fin) + 1,
            'dias_salariales' => 15,
        ];
    }

    /**
     * Semana laboral lunes→domingo que contiene $fecha.
     *
     * @return array{inicio: Carbon, fin: Carbon}
     */
    public function periodoSemanal(Carbon $fecha): array
    {
        return [
            'inicio' => $fecha->copy()->startOfWeek(Carbon::MONDAY),
            'fin' => $fecha->copy()->endOfWeek(Carbon::SUNDAY),
        ];
    }

    /**
     * Actualiza el salario diario (y, si viene, el integrado) de un
     * colaborador: `sueldo_mensual` SIEMPRE queda sincronizado a diario×30
     * (nunca se edita aparte), deja rastro en `historial_salarios` (ledger
     * append-only, nunca sobrescribe sin rastro) y, si el diario cambió,
     * un MovimientoLaboral de cambio de sueldo para el expediente.
     *
     * @param  array{fuente: string, fecha_efectiva?: string, importacion_id?: int|null, motivo?: string|null}  $meta
     */
    public function actualizarSalario(Colaborador $colaborador, float $nuevoDiario, ?float $nuevoIntegrado, User $actor, array $meta): HistorialSalario
    {
        if ($nuevoDiario <= 0) {
            throw ValidationException::withMessages(['salario_diario' => 'El salario diario debe ser mayor a cero.']);
        }

        return DB::transaction(function () use ($colaborador, $nuevoDiario, $nuevoIntegrado, $actor, $meta): HistorialSalario {
            $colaborador = Colaborador::query()->lockForUpdate()->findOrFail($colaborador->id);
            $diarioAnterior = $colaborador->salario_diario !== null ? (float) $colaborador->salario_diario : null;
            $integradoAnterior = $colaborador->salario_diario_integrado !== null ? (float) $colaborador->salario_diario_integrado : null;

            // forceFill: los importes salariales quedan fuera del fillable
            // (defensa en profundidad); el cambio de sueldo SOLO pasa por aquí.
            $colaborador->forceFill([
                'salario_diario' => $nuevoDiario,
                'salario_diario_integrado' => $nuevoIntegrado ?? $colaborador->salario_diario_integrado,
                'sueldo_mensual' => $this->mensual($nuevoDiario),
            ])->save();

            $historial = HistorialSalario::query()->create([
                'colaborador_id' => $colaborador->id,
                'salario_diario_anterior' => $diarioAnterior,
                'salario_diario_nuevo' => $nuevoDiario,
                'salario_diario_integrado_anterior' => $integradoAnterior,
                'salario_diario_integrado_nuevo' => $nuevoIntegrado,
                'fecha_efectiva' => $meta['fecha_efectiva'] ?? now()->toDateString(),
                'fuente' => $meta['fuente'],
                'importacion_id' => $meta['importacion_id'] ?? null,
                'actor_id' => $actor->id,
                'motivo' => $meta['motivo'] ?? null,
            ]);

            if ($diarioAnterior !== null && abs($diarioAnterior - $nuevoDiario) > 0.001) {
                MovimientoLaboral::query()->create([
                    'colaborador_id' => $colaborador->id,
                    'user_id' => $colaborador->user?->id,
                    'tipo_movimiento' => TipoMovimientoLaboral::CambioSueldo->value,
                    'motivo' => $meta['motivo'] ?? sprintf('Actualización de salario diario (%s).', $meta['fuente']),
                    'observaciones' => sprintf(
                        'Salario diario: $%s → $%s (mensual $%s → $%s)',
                        number_format($diarioAnterior, 2),
                        number_format($nuevoDiario, 2),
                        number_format($this->mensual($diarioAnterior), 2),
                        number_format($this->mensual($nuevoDiario), 2),
                    ),
                    'fecha_movimiento' => now(),
                    'registrado_por' => $actor->id,
                ]);
            }

            return $historial;
        });
    }
}
