<?php

namespace App\Services\Compensacion;

use App\Enums\EstadoContratoLaboral;
use App\Enums\TipoCambioSalario;
use App\Enums\TipoMovimientoLaboral;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\HistorialSalario;
use App\Models\MovimientoLaboral;
use App\Models\User;
use App\Services\Contratos\ContratoLaboralService;
use App\Support\FechaMexico;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fuente única de cálculo salarial (CLAUDE.md "Compensación centralizada").
 * El sueldo MENSUAL que captura RH es la fuente de verdad y se guarda exacto;
 * el salario diario se deriva de él (mensual ÷ 30, sin excepción de mes ni
 * de año bisiesto). Solo una fuente que trae únicamente el diario (reporte
 * NOI) calcula el mensual como diario × 30. La quincena ordinaria paga 15 días
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
    public function proporcionalDesdeMensual(float $mensual, int|float $dias): float
    {
        return round($mensual / 30 * $dias, 2);
    }

    /**
     * Sueldo mensual vigente de una persona (fuente ÚNICA de lectura: nómina,
     * finiquito, documentos): el mensual guardado, EXACTO como lo capturó RH
     * (regla del negocio 2026-10-21: el mensual es la fuente de verdad, nunca
     * se recalcula desde el diario). Solo un registro antiguo que nunca tuvo
     * mensual cae al diario × 30. Null si no tiene sueldo.
     */
    public function mensualDe(Colaborador $colaborador): ?float
    {
        if ($colaborador->sueldo_mensual !== null && (float) $colaborador->sueldo_mensual > 0) {
            return (float) $colaborador->sueldo_mensual;
        }

        return $colaborador->salario_diario !== null && (float) $colaborador->salario_diario > 0
            ? $this->mensual((float) $colaborador->salario_diario)
            : null;
    }

    /**
     * Sueldo vigente en una FECHA, leído del ledger por `fecha_efectiva`. Null
     * si el ledger demuestra que aún no tenía sueldo: no se adivina. Sin ningún
     * renglón no consta cambio alguno y se responde con la columna vigente,
     * marcado `exacto = false` para que quien lo use lo reporte. Los renglones
     * antiguos solo guardaron el diario: el mensual es diario × 30, no exacto.
     *
     * @return array{mensual: float, diario: float, fecha_efectiva: string|null, exacto: bool, fuente: string}|null
     */
    public function vigenteEn(Colaborador $colaborador, CarbonInterface|string $fecha): ?array
    {
        $dia = is_string($fecha) ? substr($fecha, 0, 10) : $fecha->format('Y-m-d');
        $renglones = HistorialSalario::query()->where('colaborador_id', $colaborador->id);

        $aplicable = (clone $renglones)->whereDate('fecha_efectiva', '<=', $dia)
            ->orderByDesc('fecha_efectiva')->orderByDesc('id')->first();

        if ($aplicable !== null) {
            $mensual = $aplicable->sueldo_mensual_nuevo !== null ? (float) $aplicable->sueldo_mensual_nuevo : null;

            return [
                'mensual' => $mensual ?? $this->mensual((float) $aplicable->salario_diario_nuevo),
                'diario' => (float) $aplicable->salario_diario_nuevo,
                'fecha_efectiva' => $aplicable->fecha_efectiva->toDateString(),
                'exacto' => $mensual !== null,
                'fuente' => 'historial',
            ];
        }

        $siguiente = (clone $renglones)->orderBy('fecha_efectiva')->orderBy('id')->first();

        if ($siguiente === null) {
            $mensual = $this->mensualDe($colaborador);

            return $mensual === null ? null : [
                'mensual' => $mensual,
                'diario' => $this->diarioDe($colaborador),
                'fecha_efectiva' => null,
                'exacto' => false,
                'fuente' => 'sin_historial',
            ];
        }

        // Antes del primer renglón regía el valor «anterior» de ese renglón.
        if ($siguiente->salario_diario_anterior === null) {
            return null;
        }

        $mensual = $siguiente->sueldo_mensual_anterior !== null ? (float) $siguiente->sueldo_mensual_anterior : null;

        return [
            'mensual' => $mensual ?? $this->mensual((float) $siguiente->salario_diario_anterior),
            'diario' => (float) $siguiente->salario_diario_anterior,
            'fecha_efectiva' => null,
            'exacto' => $mensual !== null,
            'fuente' => 'historial',
        ];
    }

    /**
     * Fecha desde la que rige un cambio, según las reglas de su tipo. Lanza si
     * el acto no es válido; no escribe nada (lo usa también la vista previa).
     *
     * - Primera captura: solo sin sueldo previo.
     * - Aumento: sobre un sueldo existente, mayor a él y desde hoy.
     * - Corrección: el valor vigente fue un error; rige desde que ese valor
     *   entró en vigor. No se elige fecha.
     * - Ajuste con vigencia: desde una fecha elegida, nunca futura (el sistema
     *   aún no programa cambios) ni anterior a un cambio posterior registrado.
     * - Importación: solo por los importadores.
     *
     * @throws ValidationException
     */
    public function fechaEfectivaDe(Colaborador $colaborador, float $mensual, TipoCambioSalario $tipo, ?string $fecha = null): string
    {
        $hoy = FechaMexico::hoy();
        $actual = $this->mensualDe($colaborador);

        if ($mensual <= 0) {
            throw ValidationException::withMessages(['sueldo_mensual' => 'El sueldo mensual debe ser mayor a cero.']);
        }

        if ($tipo === TipoCambioSalario::PrimeraCaptura) {
            if ($actual !== null) {
                throw ValidationException::withMessages(['tipo' => 'Esta persona ya tiene sueldo: elige corrección, aumento o ajuste con vigencia.']);
            }

            return $colaborador->fecha_ingreso?->toDateString() ?? $hoy;
        }

        if ($actual === null) {
            throw ValidationException::withMessages(['tipo' => 'Esta persona aún no tiene sueldo: regístralo como primera captura.']);
        }

        if (abs($actual - round($mensual, 2)) < 0.005) {
            throw ValidationException::withMessages(['sueldo_mensual' => 'El sueldo capturado es igual al vigente.']);
        }

        $ultimo = HistorialSalario::query()->where('colaborador_id', $colaborador->id)
            ->orderByDesc('fecha_efectiva')->orderByDesc('id')->first();

        switch ($tipo) {
            case TipoCambioSalario::Aumento:
                if ($mensual <= $actual) {
                    throw ValidationException::withMessages(['sueldo_mensual' => 'Un aumento debe ser mayor al sueldo vigente. Si el vigente es un error, elige corrección.']);
                }

                return $hoy;

            case TipoCambioSalario::Correccion:
                return $ultimo?->fecha_efectiva->toDateString() ?? $colaborador->fecha_ingreso?->toDateString() ?? $hoy;

            case TipoCambioSalario::AjusteConVigencia:
                $dia = is_string($fecha) && $fecha !== '' ? substr($fecha, 0, 10) : null;

                if ($dia === null) {
                    throw ValidationException::withMessages(['vigente_desde' => 'Indica desde qué fecha aplica el ajuste.']);
                }

                if ($dia > $hoy) {
                    throw ValidationException::withMessages(['vigente_desde' => 'Todavía no se programan cambios a futuro: captura el ajuste el día en que entra en vigor.']);
                }

                if ($colaborador->fecha_ingreso !== null && $dia < $colaborador->fecha_ingreso->toDateString()) {
                    throw ValidationException::withMessages(['vigente_desde' => 'La vigencia no puede ser anterior a la fecha de ingreso.']);
                }

                if ($ultimo !== null && $ultimo->fecha_efectiva->toDateString() > $dia) {
                    throw ValidationException::withMessages(['vigente_desde' => sprintf('Hay un cambio de sueldo posterior (%s): corrige primero ese registro.', $ultimo->fecha_efectiva->format('d/m/Y'))]);
                }

                return $dia;

            default:
                throw ValidationException::withMessages(['tipo' => 'Este tipo de cambio solo lo registran los importadores.']);
        }
    }

    /**
     * Cambio de sueldo clasificado, con motivo exigido aquí (no solo en el
     * formulario) y las reglas de fechaEfectivaDe().
     *
     * @throws ValidationException
     */
    public function cambiarSueldo(Colaborador $colaborador, float $mensual, TipoCambioSalario $tipo, string $motivo, User $actor, ?string $fecha = null, string $fuente = 'correccion'): HistorialSalario
    {
        if (mb_strlen(trim($motivo)) < 5) {
            throw ValidationException::withMessages(['motivo' => 'Explica el motivo del cambio de sueldo.']);
        }

        $mensual = round($mensual, 2);

        return $this->registrar($colaborador, $this->diarioDesdeMensual($mensual), null, $mensual, $actor, [
            'fuente' => $fuente,
            'tipo' => $tipo,
            'motivo' => trim($motivo),
            'fecha_efectiva' => $this->fechaEfectivaDe($colaborador, $mensual, $tipo, $fecha),
        ]);
    }

    /**
     * Contratos vigentes con original firmado: el sueldo de su registro es lo
     * firmado y no se sobrescribe. Un cambio de sueldo los reporta como
     * «requiere modificación contractual».
     *
     * @return Collection<int, ContratoLaboral>
     */
    public function contratosVigentesFirmados(Colaborador $colaborador): Collection
    {
        $contratos = app(ContratoLaboralService::class);

        return ContratoLaboral::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('estado', EstadoContratoLaboral::Vigente->value)
            ->with('documento')
            ->get()
            ->filter(fn (ContratoLaboral $contrato): bool => $contratos->contratoFirmado($contrato))
            ->values();
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
     * Cambia el sueldo a partir del MENSUAL que captura RH (expediente, alta,
     * reingreso): el mensual se guarda EXACTO (9,582.47 queda 9,582.47) y el
     * diario SIEMPRE se deriva de él (round(mensual ÷ 30, 2) = 319.42) —
     * nunca al revés. Mismo ledger, contrato vigente y movimiento laboral que
     * actualizarSalario().
     *
     * @param  array{fuente: string, fecha_efectiva?: string, importacion_id?: int|null, motivo?: string|null}  $meta
     */
    public function actualizarSueldoMensual(Colaborador $colaborador, float $mensual, User $actor, array $meta): HistorialSalario
    {
        if ($mensual <= 0) {
            throw ValidationException::withMessages(['sueldo_mensual' => 'El sueldo mensual debe ser mayor a cero.']);
        }

        $mensual = round($mensual, 2);

        return $this->registrar($colaborador, $this->diarioDesdeMensual($mensual), null, $mensual, $actor, $meta);
    }

    /**
     * Cambia el sueldo cuando la fuente SOLO trae el salario DIARIO (reportes
     * de nómina NOI de las importaciones): es el único caso en que el mensual
     * se calcula (diario × 30), porque no existe un mensual capturado.
     * Cualquier captura de RH usa actualizarSueldoMensual().
     *
     * @param  array{fuente: string, fecha_efectiva?: string, importacion_id?: int|null, motivo?: string|null}  $meta
     */
    public function actualizarSalario(Colaborador $colaborador, float $nuevoDiario, ?float $nuevoIntegrado, User $actor, array $meta): HistorialSalario
    {
        if ($nuevoDiario <= 0) {
            throw ValidationException::withMessages(['salario_diario' => 'El salario diario debe ser mayor a cero.']);
        }

        return $this->registrar($colaborador, $nuevoDiario, $nuevoIntegrado, $this->mensual($nuevoDiario), $actor, $meta);
    }

    /**
     * Escribe diario + mensual, sincroniza el contrato vigente SIN FIRMAR, deja
     * rastro en `historial_salarios` (ledger append-only) y, si el sueldo
     * cambió, un MovimientoLaboral para el expediente. Sin `tipo`, se deduce
     * del acto (ver tipoDeducido()).
     *
     * @param  array{fuente: string, tipo?: TipoCambioSalario, fecha_efectiva?: string, importacion_id?: int|null, motivo?: string|null}  $meta
     */
    private function registrar(Colaborador $colaborador, float $nuevoDiario, ?float $nuevoIntegrado, float $nuevoMensual, User $actor, array $meta): HistorialSalario
    {
        return DB::transaction(function () use ($colaborador, $nuevoDiario, $nuevoIntegrado, $nuevoMensual, $actor, $meta): HistorialSalario {
            $colaborador = Colaborador::query()->lockForUpdate()->findOrFail($colaborador->id);
            $diarioAnterior = $colaborador->salario_diario !== null ? (float) $colaborador->salario_diario : null;
            $mensualAnterior = $colaborador->sueldo_mensual !== null ? (float) $colaborador->sueldo_mensual : null;
            $integradoAnterior = $colaborador->salario_diario_integrado !== null ? (float) $colaborador->salario_diario_integrado : null;

            $colaborador->update([
                'salario_diario' => $nuevoDiario,
                'salario_diario_integrado' => $nuevoIntegrado ?? $colaborador->salario_diario_integrado,
                'sueldo_mensual' => $nuevoMensual,
            ]);

            // Un contrato vigente SIN FIRMAR refleja el sueldo vigente: su
            // registro guardaba la copia de cuando se capturó y la vista previa
            // salía con el sueldo viejo. El FIRMADO conserva lo firmado
            // (contratosVigentesFirmados() lo reporta). Uno por uno (no update
            // masivo) para que quede en su bitácora.
            $firmados = $this->contratosVigentesFirmados($colaborador)->modelKeys();
            ContratoLaboral::query()
                ->where('colaborador_id', $colaborador->id)
                ->where('estado', EstadoContratoLaboral::Vigente->value)
                ->whereNotIn('id', $firmados)
                ->get()
                ->each(fn (ContratoLaboral $contrato) => $contrato->update(['sueldo_mensual' => $nuevoMensual]));

            $tipo = $meta['tipo'] ?? $this->tipoDeducido($diarioAnterior, $mensualAnterior, $meta);

            $historial = HistorialSalario::query()->create([
                'colaborador_id' => $colaborador->id,
                'tipo' => $tipo,
                'sueldo_mensual_anterior' => $mensualAnterior,
                'sueldo_mensual_nuevo' => $nuevoMensual,
                'salario_diario_anterior' => $diarioAnterior,
                'salario_diario_nuevo' => $nuevoDiario,
                'salario_diario_integrado_anterior' => $integradoAnterior,
                'salario_diario_integrado_nuevo' => $nuevoIntegrado,
                // Vigencia del salario: día calendario de México, no UTC (P1-28).
                'fecha_efectiva' => $meta['fecha_efectiva'] ?? FechaMexico::hoy(),
                'fuente' => $meta['fuente'],
                'importacion_id' => $meta['importacion_id'] ?? null,
                'actor_id' => $actor->id,
                'motivo' => $meta['motivo'] ?? null,
            ]);

            $cambioDiario = $diarioAnterior !== null && abs($diarioAnterior - $nuevoDiario) > 0.001;
            $cambioMensual = $mensualAnterior !== null && abs($mensualAnterior - $nuevoMensual) > 0.001;

            if ($diarioAnterior !== null && ($cambioDiario || $cambioMensual)) {
                MovimientoLaboral::query()->create([
                    'colaborador_id' => $colaborador->id,
                    'user_id' => $colaborador->user?->id,
                    'tipo_movimiento' => ($tipo === TipoCambioSalario::Correccion ? TipoMovimientoLaboral::AjusteManual : TipoMovimientoLaboral::CambioSueldo)->value,
                    'motivo' => $meta['motivo'] ?? sprintf('Actualización de sueldo (%s).', $meta['fuente']),
                    'observaciones' => sprintf(
                        'Sueldo mensual: $%s → $%s (diario $%s → $%s)',
                        number_format($mensualAnterior ?? $this->mensual($diarioAnterior), 2),
                        number_format($nuevoMensual, 2),
                        number_format($diarioAnterior, 2),
                        number_format($nuevoDiario, 2),
                    ),
                    'fecha_movimiento' => now(),
                    'registrado_por' => $actor->id,
                ]);
            }

            return $historial;
        });
    }

    /**
     * Tipo de los flujos que no clasifican su cambio (alta, reingreso,
     * importadores, edición del expediente).
     *
     * @param  array{fuente: string, fecha_efectiva?: string, importacion_id?: int|null}  $meta
     */
    private function tipoDeducido(?float $diarioAnterior, ?float $mensualAnterior, array $meta): TipoCambioSalario
    {
        return match (true) {
            ($meta['importacion_id'] ?? null) !== null || str_contains($meta['fuente'], 'import') || str_contains($meta['fuente'], 'carga') => TipoCambioSalario::Importacion,
            $diarioAnterior === null && $mensualAnterior === null => TipoCambioSalario::PrimeraCaptura,
            isset($meta['fecha_efectiva']) && substr($meta['fecha_efectiva'], 0, 10) !== FechaMexico::hoy() => TipoCambioSalario::AjusteConVigencia,
            default => TipoCambioSalario::Aumento,
        };
    }
}
