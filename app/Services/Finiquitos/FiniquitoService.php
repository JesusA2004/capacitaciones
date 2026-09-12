<?php

namespace App\Services\Finiquitos;

use App\Enums\EstadoFiniquito;
use App\Enums\TipoBaja;
use App\Models\FiniquitoCalculo;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Solicitudes\SolicitudDocumentoStorageService;
use App\Services\Vacaciones\VacacionesService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Único origen del cálculo de finiquito de una baja de colaborador (ver
 * docs/SOLICITUDES_UNIFICADAS.md, sección "Baja de colaborador"). El
 * cálculo es automático a partir de fechas/sueldo capturados, pero siempre
 * editable por RH/contabilidad antes de aprobarse — nunca se presenta como
 * una cifra legal definitiva (ver config/finiquitos.php).
 */
class FiniquitoService
{
    public function __construct(
        private readonly VacacionesService $vacaciones,
        private readonly SolicitudDocumentoStorageService $storage,
    ) {}

    /**
     * Primer cálculo de un finiquito para esta solicitud de baja. Lanza si
     * ya existe uno (usar recalcular() para actualizar montos existentes).
     */
    public function calcular(SolicitudInterna $solicitud, User $actor, float $sueldoMensual): FiniquitoCalculo
    {
        if (FiniquitoCalculo::query()->where('solicitud_interna_id', $solicitud->id)->exists()) {
            throw new RuntimeException('Ya existe un cálculo de finiquito para esta baja; usa recalcular().');
        }

        $solicitud->loadMissing('colaboradorObjetivo');
        $colaborador = $solicitud->colaboradorObjetivo;
        abort_unless($colaborador !== null, 422, 'Esta solicitud no tiene un colaborador objetivo.');
        abort_unless($colaborador->fecha_ingreso !== null, 422, 'El colaborador no tiene fecha de ingreso registrada.');

        $automaticos = $this->calcularAutomaticos($solicitud, $colaborador, $sueldoMensual);

        return DB::transaction(function () use ($solicitud, $colaborador, $actor, $automaticos): FiniquitoCalculo {
            $finiquito = FiniquitoCalculo::create([
                'solicitud_interna_id' => $solicitud->id,
                'colaborador_id' => $colaborador->id,
                'calculado_por_id' => $actor->id,
                'fecha_calculo' => now(),
                ...$automaticos,
                'bonos_extra' => 0,
                'descuentos' => 0,
                'adeudos' => 0,
                'total_ajustado' => $automaticos['total_calculado'],
                'estado' => EstadoFiniquito::Borrador->value,
            ]);

            $this->registrarHistorial($solicitud, $actor, 'finiquito_calculado');

            return $finiquito;
        });
    }

    /**
     * Vuelve a correr las fórmulas automáticas (por ejemplo, si cambió el
     * sueldo capturado o la fecha efectiva de baja) preservando los ajustes
     * manuales ya capturados (bonos/descuentos/adeudos/otros conceptos):
     * recalcular no debe borrar trabajo de RH. Regresa el finiquito a
     * "borrador" porque los montos cambiaron y necesita revisarse de nuevo.
     */
    public function recalcular(FiniquitoCalculo $finiquito, User $actor, float $sueldoMensual): FiniquitoCalculo
    {
        $finiquito->loadMissing('solicitudInterna.colaboradorObjetivo');
        $solicitud = $finiquito->solicitudInterna;
        $colaborador = $solicitud->colaboradorObjetivo;
        abort_unless($colaborador !== null, 422, 'Esta solicitud no tiene un colaborador objetivo.');
        abort_unless($colaborador->fecha_ingreso !== null, 422, 'El colaborador no tiene fecha de ingreso registrada.');

        $automaticos = $this->calcularAutomaticos($solicitud, $colaborador, $sueldoMensual);

        return DB::transaction(function () use ($finiquito, $solicitud, $actor, $automaticos): FiniquitoCalculo {
            $totalAjustado = $automaticos['total_calculado']
                + (float) $finiquito->bonos_extra
                - (float) $finiquito->descuentos
                - (float) $finiquito->adeudos
                + $this->sumaOtrosConceptos($finiquito->otros_conceptos);

            $finiquito->update([
                ...$automaticos,
                'total_ajustado' => round($totalAjustado, 2),
                'estado' => EstadoFiniquito::Borrador->value,
                'revisado_por_id' => null,
            ]);

            $this->registrarHistorial($solicitud, $actor, 'finiquito_recalculado');

            return $finiquito->refresh();
        });
    }

    /**
     * @param  array{bonos_extra?: float, descuentos?: float, adeudos?: float, otros_conceptos?: array<string, float>, comentarios_ajuste?: string|null}  $datos
     */
    public function actualizarAjustes(FiniquitoCalculo $finiquito, User $actor, array $datos): FiniquitoCalculo
    {
        $bonosExtra = (float) ($datos['bonos_extra'] ?? $finiquito->bonos_extra);
        $descuentos = (float) ($datos['descuentos'] ?? $finiquito->descuentos);
        $adeudos = (float) ($datos['adeudos'] ?? $finiquito->adeudos);
        $otrosConceptos = $datos['otros_conceptos'] ?? $finiquito->otros_conceptos;

        $totalAjustado = (float) $finiquito->total_calculado + $bonosExtra - $descuentos - $adeudos + $this->sumaOtrosConceptos($otrosConceptos);

        return DB::transaction(function () use ($finiquito, $actor, $datos, $bonosExtra, $descuentos, $adeudos, $otrosConceptos, $totalAjustado): FiniquitoCalculo {
            $finiquito->update([
                'bonos_extra' => $bonosExtra,
                'descuentos' => $descuentos,
                'adeudos' => $adeudos,
                'otros_conceptos' => $otrosConceptos,
                'comentarios_ajuste' => $datos['comentarios_ajuste'] ?? $finiquito->comentarios_ajuste,
                'total_ajustado' => round($totalAjustado, 2),
                // Un ajuste manual sobre un cálculo ya revisado obliga a
                // revisarlo de nuevo — nunca se queda "revisado" con
                // números distintos a los que se revisaron.
                'estado' => $finiquito->estado === EstadoFiniquito::Firmado
                    ? $finiquito->estado->value
                    : EstadoFiniquito::Borrador->value,
                'revisado_por_id' => $finiquito->estado === EstadoFiniquito::Firmado ? $finiquito->revisado_por_id : null,
            ]);

            $this->registrarHistorial($finiquito->solicitudInterna, $actor, 'finiquito_ajustado', $datos['comentarios_ajuste'] ?? null);

            return $finiquito->refresh();
        });
    }

    public function aprobarCalculo(FiniquitoCalculo $finiquito, User $actor): FiniquitoCalculo
    {
        $finiquito->update([
            'estado' => EstadoFiniquito::Revisado->value,
            'revisado_por_id' => $actor->id,
        ]);

        $this->registrarHistorial($finiquito->solicitudInterna, $actor, 'finiquito_revisado');

        return $finiquito->refresh();
    }

    /**
     * Se llama cuando la solicitud de baja queda aprobada (ver
     * App\Services\Solicitudes\SolicitudesService::cambiarEstado()): el
     * finiquito pasa de "revisado" a "aprobado" junto con la baja. Si se
     * aprobó sin revisión (permiso especial solicitudes.bajas.omitir_finiquito),
     * también se marca aprobado para no dejarlo huérfano en "borrador".
     */
    public function marcarAprobadoConLaBaja(SolicitudInterna $solicitud): void
    {
        FiniquitoCalculo::query()
            ->where('solicitud_interna_id', $solicitud->id)
            ->whereIn('estado', [EstadoFiniquito::Borrador->value, EstadoFiniquito::Revisado->value])
            ->update(['estado' => EstadoFiniquito::Aprobado->value]);
    }

    public function subirFirmado(FiniquitoCalculo $finiquito, UploadedFile $archivo, User $actor): FiniquitoCalculo
    {
        $nombreInterno = $this->storage->nombreInterno($archivo->getClientOriginalName());
        $ruta = "solicitudes/{$finiquito->solicitud_interna_id}/finiquito/firmado-{$nombreInterno}";
        $this->storage->guardar($archivo, $ruta);

        $finiquito->update([
            'documento_firmado_path' => $ruta,
            'estado' => EstadoFiniquito::Firmado->value,
        ]);

        $this->registrarHistorial($finiquito->solicitudInterna, $actor, 'finiquito_firmado_subido');

        return $finiquito->refresh();
    }

    /**
     * Genera y persiste el PDF del finiquito (fallback simple mientras no
     * exista un overlay de formato oficial configurado — ver sección 8 del
     * encargo, "Formatos oficiales": conectar el overlay queda pendiente).
     * Congela un snapshot de los datos usados en este documento.
     */
    public function generarPdf(FiniquitoCalculo $finiquito): FiniquitoCalculo
    {
        $finiquito->loadMissing(['colaborador', 'calculadoPor', 'revisadoPor', 'solicitudInterna']);

        $pdf = Pdf::loadView('pdf.finiquito', ['finiquito' => $finiquito])->setPaper('letter', 'portrait');

        $nombreInterno = 'generado-'.now()->timestamp.'.pdf';
        $ruta = "solicitudes/{$finiquito->solicitud_interna_id}/finiquito/{$nombreInterno}";

        $this->storage->disco()->put($ruta, $pdf->output());

        $finiquito->update([
            'documento_generado_path' => $ruta,
            'snapshot' => $finiquito->only([
                'sueldo_mensual', 'sueldo_diario', 'antiguedad_anios', 'antiguedad_meses',
                'dias_trabajados_periodo', 'vacaciones_pendientes', 'prima_vacacional',
                'aguinaldo_proporcional', 'sueldo_pendiente', 'indemnizacion', 'bonos_extra',
                'descuentos', 'adeudos', 'otros_conceptos', 'total_calculado', 'total_ajustado',
                'comentarios_ajuste',
            ]),
        ]);

        return $finiquito->refresh();
    }

    public function descargarPdf(FiniquitoCalculo $finiquito): StreamedResponse
    {
        abort_unless($finiquito->documento_generado_path !== null, 404, 'Genera el PDF del finiquito antes de descargarlo.');

        return $this->storage->respuesta($finiquito->documento_generado_path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="finiquito-'.$finiquito->solicitud_interna_id.'.pdf"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function calcularAutomaticos(SolicitudInterna $solicitud, User $colaborador, float $sueldoMensual): array
    {
        $fechaIngreso = Carbon::parse($colaborador->fecha_ingreso);
        $fechaBaja = $solicitud->fecha_efectiva !== null ? Carbon::parse($solicitud->fecha_efectiva) : Carbon::now();

        if ($fechaBaja->lt($fechaIngreso)) {
            throw ValidationException::withMessages(['fecha_baja' => 'La fecha de baja no puede ser anterior a la fecha de ingreso.']);
        }

        $antiguedadAnios = (int) $fechaIngreso->diffInYears($fechaBaja);
        $ultimoAniversario = $fechaIngreso->copy()->addYears($antiguedadAnios);
        $antiguedadMeses = (int) $ultimoAniversario->diffInMonths($fechaBaja);
        $diasTrabajadosPeriodo = (int) $ultimoAniversario->diffInDays($fechaBaja);

        $sueldoDiario = round($sueldoMensual / 30, 2);
        $vacacionesPendientes = $this->vacaciones->saldo($colaborador)['dias_disponibles'];

        $primaVacacionalPorcentaje = (int) config('finiquitos.prima_vacacional_porcentaje');
        $primaVacacional = round($vacacionesPendientes * $sueldoDiario * ($primaVacacionalPorcentaje / 100), 2);

        $diasAguinaldo = (int) config('finiquitos.dias_aguinaldo');
        $aguinaldoProporcional = round($diasAguinaldo * $diasTrabajadosPeriodo / 365 * $sueldoDiario, 2);

        $indemnizacion = $this->calcularIndemnizacion($solicitud, $antiguedadAnios, $sueldoDiario);

        $totalCalculado = round($primaVacacional + $aguinaldoProporcional + $indemnizacion, 2);

        return [
            'fecha_ingreso' => $fechaIngreso->toDateString(),
            'fecha_baja' => $fechaBaja->toDateString(),
            'sueldo_mensual' => $sueldoMensual,
            'sueldo_diario' => $sueldoDiario,
            'antiguedad_anios' => $antiguedadAnios,
            'antiguedad_meses' => $antiguedadMeses,
            'dias_trabajados_periodo' => $diasTrabajadosPeriodo,
            'vacaciones_pendientes' => $vacacionesPendientes,
            'prima_vacacional' => $primaVacacional,
            'aguinaldo_proporcional' => $aguinaldoProporcional,
            'sueldo_pendiente' => 0,
            'indemnizacion' => $indemnizacion,
            'total_calculado' => $totalCalculado,
        ];
    }

    /**
     * Estimación configurable, no un mínimo de ley fijo (ver
     * config/finiquitos.php): solo aplica si la baja no es renuncia
     * voluntaria y la política de indemnización está habilitada.
     */
    private function calcularIndemnizacion(SolicitudInterna $solicitud, int $antiguedadAnios, float $sueldoDiario): float
    {
        if (! config('finiquitos.indemnizacion_habilitada')) {
            return 0.0;
        }

        if (in_array($solicitud->tipo_baja, [TipoBaja::Renuncia, TipoBaja::Abandono, null], true)) {
            return 0.0;
        }

        $diasPorAnio = (int) config('finiquitos.dias_indemnizacion_por_anio');

        return round($diasPorAnio * $antiguedadAnios * $sueldoDiario, 2);
    }

    /**
     * @param  array<string, mixed>|null  $otrosConceptos
     */
    private function sumaOtrosConceptos(?array $otrosConceptos): float
    {
        if ($otrosConceptos === null) {
            return 0.0;
        }

        return array_sum(array_map(static fn ($valor): float => (float) $valor, $otrosConceptos));
    }

    private function registrarHistorial(SolicitudInterna $solicitud, User $actor, string $accion, ?string $comentario = null): void
    {
        $solicitud->historial()->create([
            'user_id' => $actor->id,
            'accion' => $accion,
            'comentario' => $comentario,
            'created_at' => now(),
        ]);
    }
}
