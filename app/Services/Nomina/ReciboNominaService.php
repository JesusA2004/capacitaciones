<?php

namespace App\Services\Nomina;

use App\Models\Colaborador;
use App\Models\Prestamo;
use App\Models\ReciboNomina;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Recibo de nómina SIMPLE e informativo: percepciones/deducciones a partir
 * del sueldo mensual capturado en el expediente más los conceptos que RH
 * agrega a mano (bono, comisión, préstamo, etc.). No es un CFDI timbrado
 * ante el SAT ni calcula ISR/IMSS — para eso se necesita un proveedor de
 * nómina certificado. Cada llamada a generar() PERSISTE el recibo (a
 * diferencia de la versión anterior, que solo devolvía un array volátil),
 * para que el expediente conserve un historial real.
 */
class ReciboNominaService
{
    public function __construct(
        private readonly PrestamoService $prestamos,
    ) {}

    private function disco(): string
    {
        return config('nomina.disk', 'nas');
    }

    /**
     * @param  array{periodo_inicio: string, periodo_fin: string, fecha_pago: string, sueldo_base: float|string, percepciones?: array<int, array{concepto: string, monto: float|string}>, deducciones?: array<int, array{concepto: string, monto: float|string, tipo?: string|null, prestamo_id?: int|null}>}  $datos
     */
    public function generar(Colaborador $colaborador, array $datos, User $generadoPor): ReciboNomina
    {
        // Sueldo BASE DE ESTE RECIBO, confirmado/editado por RH en el
        // diálogo (nunca el sueldo mensual completo aplicado ciegamente a
        // un periodo de 15 días) — ver GenerarReciboNominaRequest y
        // ExpedienteController::generarReciboNomina().
        $sueldoBase = round((float) $datos['sueldo_base'], 2);

        $percepciones = [
            ['concepto' => 'Sueldo base del periodo', 'monto' => $sueldoBase],
        ];

        foreach ($datos['percepciones'] ?? [] as $item) {
            $percepciones[] = [
                'concepto' => (string) $item['concepto'],
                'monto' => round((float) $item['monto'], 2),
            ];
        }

        // Sin ISR/IMSS automáticos: este recibo es informativo, no un
        // cálculo fiscal. Las únicas deducciones son las que RH confirma
        // explícitamente en el formulario (incluida la línea sugerida de
        // pago de préstamo, si el frontend la dejó).
        $deducciones = [];

        foreach ($datos['deducciones'] ?? [] as $item) {
            $deducciones[] = array_filter([
                'concepto' => (string) $item['concepto'],
                'monto' => round((float) $item['monto'], 2),
                'tipo' => $item['tipo'] ?? null,
                'prestamo_id' => $item['prestamo_id'] ?? null,
            ], static fn (mixed $valor): bool => $valor !== null);
        }

        $totalPercepciones = round(array_sum(array_column($percepciones, 'monto')), 2);
        $totalDeducciones = round(array_sum(array_column($deducciones, 'monto')), 2);
        $neto = round($totalPercepciones - $totalDeducciones, 2);

        $periodoInicio = Carbon::parse($datos['periodo_inicio']);
        $periodoFin = Carbon::parse($datos['periodo_fin']);
        $fechaPago = Carbon::parse($datos['fecha_pago']);

        return DB::transaction(function () use (
            $colaborador,
            $percepciones,
            $deducciones,
            $totalPercepciones,
            $totalDeducciones,
            $neto,
            $sueldoBase,
            $periodoInicio,
            $periodoFin,
            $fechaPago,
            $generadoPor,
        ): ReciboNomina {
            /** @var ReciboNomina $recibo */
            $recibo = ReciboNomina::query()->create([
                'colaborador_id' => $colaborador->id,
                'periodo_inicio' => $periodoInicio,
                'periodo_fin' => $periodoFin,
                'fecha_pago' => $fechaPago,
                'sueldo_base' => $sueldoBase,
                'percepciones' => $percepciones,
                'deducciones' => $deducciones,
                'total_percepciones' => $totalPercepciones,
                'total_deducciones' => $totalDeducciones,
                'neto' => $neto,
                'generado_por' => $generadoPor->id,
            ]);

            // Si RH confirmó (sin quitarla ni ajustarla a $0) la línea
            // sugerida de pago de préstamo, se refleja como abono real en el
            // ledger del préstamo — nunca al revés (el ledger nunca genera
            // un recibo por su cuenta).
            foreach ($deducciones as $deduccion) {
                if (($deduccion['tipo'] ?? null) !== 'prestamo' || ! isset($deduccion['prestamo_id'])) {
                    continue;
                }

                $prestamo = Prestamo::query()->find($deduccion['prestamo_id']);

                if ($prestamo !== null) {
                    $this->prestamos->registrarMovimiento($prestamo, (float) $deduccion['monto'], 'nomina', $generadoPor);
                }
            }

            $this->generarPdf($recibo);

            return $recibo->fresh();
        });
    }

    /**
     * Reintenta generar/guardar el PDF de un recibo ya persistido cuyo
     * pdf_path quedó en null (ver generarPdf()). Nunca recalcula montos:
     * usa el snapshot de percepciones/deducciones/totales ya guardado en
     * la fila, tal como quedó confirmado en su momento.
     */
    public function regenerarPdf(ReciboNomina $recibo): ReciboNomina
    {
        $this->generarPdf($recibo);

        return $recibo->fresh();
    }

    /**
     * Genera el PDF del recibo y solo guarda pdf_disk/pdf_path si el
     * storage confirma que el archivo quedó escrito — un fallo aquí nunca
     * debe dejar un recibo con una ruta que apunta a nada.
     */
    private function generarPdf(ReciboNomina $recibo): void
    {
        $recibo->loadMissing(['colaborador.puesto', 'colaborador.sucursalPrincipal']);

        try {
            $contenido = Pdf::loadView('pdf.recibo-nomina', [
                'recibo' => $recibo,
                'colaborador' => $recibo->colaborador,
                'periodo_inicio' => $recibo->periodo_inicio,
                'periodo_fin' => $recibo->periodo_fin,
                'fecha_pago' => $recibo->fecha_pago,
                'percepciones' => $recibo->percepciones,
                'deducciones' => $recibo->deducciones,
                'total_percepciones' => (float) $recibo->total_percepciones,
                'total_deducciones' => (float) $recibo->total_deducciones,
                'neto' => (float) $recibo->neto,
            ])->setPaper('letter', 'portrait')->output();

            $ruta = sprintf('recibos-nomina/%d/%s.pdf', $recibo->colaborador_id, Str::uuid());
            $disco = $this->disco();

            Storage::disk($disco)->put($ruta, $contenido);

            if (Storage::disk($disco)->exists($ruta)) {
                $recibo->update(['pdf_disk' => $disco, 'pdf_path' => $ruta]);
            }
        } catch (Throwable $e) {
            // Un fallo al generar/guardar el PDF nunca revierte el recibo ya
            // persistido (los montos/periodo siguen siendo válidos e
            // históricos) — solo se queda sin archivo descargable, y RH
            // puede reintentarlo. Se registra para diagnóstico.
            Log::warning('ReciboNominaService: no se pudo generar/guardar el PDF del recibo.', [
                'recibo_id' => $recibo->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
