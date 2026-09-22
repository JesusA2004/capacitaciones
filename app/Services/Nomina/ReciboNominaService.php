<?php

namespace App\Services\Nomina;

use App\Enums\CategoriaDocumento;
use App\Enums\TipoConceptoNomina;
use App\Models\Colaborador;
use App\Models\Prestamo;
use App\Models\ReciboNomina;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\Tareas\NotificadorRhService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Recibo INTERNO de nómina (semanal por defecto). NO es CFDI, NO se timbra,
 * NO calcula ISR/IMSS, NO sustituye al sistema de nómina y NO se integra
 * con NOI: RH captura (individualmente o por importación CSV/XLSX
 * administrativa, ver ReciboNominaImportService) los conceptos ya
 * calculados y el sistema emite un comprobante interno con la leyenda
 * "RECIBO INTERNO DE NÓMINA - NO FISCAL", archivado en el expediente del
 * colaborador (carpeta NominaInterna).
 *
 * Cada recibo guarda snapshot de sus conceptos: un cambio posterior de
 * sueldo no reescribe recibos emitidos.
 */
class ReciboNominaService
{
    public const TIPO_PERIODO_SEMANAL = 'semanal';

    public function __construct(
        private readonly PrestamoService $prestamos,
        private readonly MotorDocumentalService $motor,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AuditoriaService $auditoria,
        private readonly NotificadorRhService $notificador,
    ) {}

    /**
     * Acepta dos formas de captura:
     *  - detallada (API): `conceptos` [{tipo, concepto, cantidad?, importe, observaciones?}];
     *  - simple (portal web existente): `sueldo_base` + `percepciones`/`deducciones` [{concepto, monto}].
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException Periodo duplicado o sin conceptos.
     */
    public function generar(Colaborador $colaborador, array $datos, User $generadoPor, ?string $lote = null): ReciboNomina
    {
        $conceptos = $this->normalizarConceptos($datos);

        if ($conceptos === []) {
            throw ValidationException::withMessages(['conceptos' => 'Captura al menos un concepto.']);
        }

        $periodoInicio = Carbon::parse($datos['periodo_inicio'])->startOfDay();
        $periodoFin = Carbon::parse($datos['periodo_fin'])->startOfDay();
        $tipoPeriodo = (string) ($datos['tipo_periodo'] ?? (isset($datos['conceptos']) ? self::TIPO_PERIODO_SEMANAL : 'libre'));

        if ($periodoFin->lt($periodoInicio)) {
            throw ValidationException::withMessages(['periodo_fin' => 'La fecha final no puede ser anterior a la inicial.']);
        }

        $percepciones = array_values(array_filter($conceptos, fn (array $c) => $c['tipo'] === TipoConceptoNomina::Percepcion->value));
        $deducciones = array_values(array_filter($conceptos, fn (array $c) => $c['tipo'] === TipoConceptoNomina::Deduccion->value));
        $totalPercepciones = round(array_sum(array_column($percepciones, 'importe')), 2);
        $totalDeducciones = round(array_sum(array_column($deducciones, 'importe')), 2);

        $recibo = DB::transaction(function () use ($colaborador, $datos, $generadoPor, $conceptos, $percepciones, $deducciones, $totalPercepciones, $totalDeducciones, $periodoInicio, $periodoFin, $tipoPeriodo, $lote): ReciboNomina {
            // Bloqueo por colaborador: dos capturas/importaciones simultáneas
            // no pueden emitir dos recibos del mismo periodo.
            Colaborador::query()->whereKey($colaborador->id)->lockForUpdate()->first();

            $duplicado = ReciboNomina::query()
                ->where('colaborador_id', $colaborador->id)
                ->whereDate('periodo_inicio', $periodoInicio->toDateString())
                ->whereDate('periodo_fin', $periodoFin->toDateString())
                ->exists();

            if ($duplicado) {
                throw ValidationException::withMessages([
                    'periodo_inicio' => sprintf('%s ya tiene un recibo del periodo %s – %s.', $colaborador->nombreCompleto(), $periodoInicio->format('d/m/Y'), $periodoFin->format('d/m/Y')),
                ]);
            }

            $recibo = ReciboNomina::query()->create([
                'colaborador_id' => $colaborador->id,
                'periodo_inicio' => $periodoInicio,
                'periodo_fin' => $periodoFin,
                'fecha_pago' => Carbon::parse($datos['fecha_pago'] ?? $periodoFin),
                'tipo_periodo' => $tipoPeriodo,
                'ejercicio' => (int) $periodoInicio->isoFormat('GGGG'),
                'numero_periodo' => $tipoPeriodo === self::TIPO_PERIODO_SEMANAL ? $periodoInicio->isoWeek() : null,
                'sueldo_base' => round((float) ($datos['sueldo_base'] ?? $percepciones[0]['importe'] ?? 0), 2),
                // Snapshot JSON compatible con el portal existente.
                'percepciones' => array_map(fn (array $c) => ['concepto' => $c['concepto'], 'monto' => $c['importe']], $percepciones),
                'deducciones' => array_map(fn (array $c) => array_filter([
                    'concepto' => $c['concepto'],
                    'monto' => $c['importe'],
                    'tipo' => $c['clasificacion'] ?? null,
                    'prestamo_id' => $c['prestamo_id'] ?? null,
                ], fn ($v) => $v !== null), $deducciones),
                'total_percepciones' => $totalPercepciones,
                'total_deducciones' => $totalDeducciones,
                'neto' => round($totalPercepciones - $totalDeducciones, 2),
                'observaciones' => $datos['observaciones'] ?? null,
                'lote_importacion' => $lote,
                'generado_por' => $generadoPor->id,
            ]);

            $recibo->update(['folio' => sprintf('RIN-%06d', $recibo->id)]);

            foreach ($conceptos as $orden => $concepto) {
                $recibo->conceptos()->create([
                    'tipo' => $concepto['tipo'],
                    'concepto' => $concepto['concepto'],
                    'cantidad' => $concepto['cantidad'],
                    'importe' => $concepto['importe'],
                    'observaciones' => $concepto['observaciones'],
                    'orden' => $orden,
                ]);
            }

            // Saldo administrativo/informativo del préstamo: solo si RH
            // confirmó explícitamente la línea de préstamo. MR. LANA PEOPLE no
            // ejecuta descuentos de nómina.
            foreach ($deducciones as $deduccion) {
                if (($deduccion['clasificacion'] ?? null) !== 'prestamo' || ! isset($deduccion['prestamo_id'])) {
                    continue;
                }

                $prestamo = Prestamo::query()->where('id', $deduccion['prestamo_id'])->where('colaborador_id', $colaborador->id)->first();

                if ($prestamo !== null) {
                    $this->prestamos->registrarMovimiento($prestamo, (float) $deduccion['importe'], 'nomina', $generadoPor);
                }
            }

            return $recibo;
        });

        $this->generarPdf($recibo, $generadoPor);
        $this->auditoria->registrar('recibo_nomina_generado', $recibo, $generadoPor, [
            'colaborador_id' => $colaborador->id,
            'periodo' => $periodoInicio->toDateString().' / '.$periodoFin->toDateString(),
            'neto' => $recibo->neto,
            'lote' => $lote,
        ]);

        $colaborador->loadMissing('user');

        if ($colaborador->user !== null && $lote === null) {
            $this->notificador->notificar([$colaborador->user], 'recibo_nomina', 'Recibo interno disponible', sprintf('Ya puedes consultar tu recibo interno del %s al %s.', $periodoInicio->format('d/m/Y'), $periodoFin->format('d/m/Y')), $recibo, 'ver_recibo', 'baja');
        }

        return $recibo->refresh();
    }

    /**
     * Reintenta el PDF de un recibo cuyo pdf_path quedó vacío. Nunca
     * recalcula montos: usa el snapshot guardado.
     */
    public function regenerarPdf(ReciboNomina $recibo, ?User $actor = null): ReciboNomina
    {
        $this->generarPdf($recibo, $actor);

        return $recibo->fresh() ?? $recibo;
    }

    /**
     * @return LengthAwarePaginator<int, ReciboNomina>
     */
    public function delColaborador(Colaborador $colaborador, int $porPagina = 20): LengthAwarePaginator
    {
        return ReciboNomina::query()
            ->where('colaborador_id', $colaborador->id)
            ->orderByDesc('periodo_inicio')
            ->orderByDesc('id')
            ->paginate(max(1, min(100, $porPagina)));
    }

    /**
     * Listado administrativo (RH) acotado por alcance organizacional.
     *
     * @param  array{periodo_inicio?: string|null, periodo_fin?: string|null, colaborador_id?: int|string|null, lote?: string|null, per_page?: int|string|null}  $filtros
     * @return LengthAwarePaginator<int, ReciboNomina>
     */
    public function listar(User $usuario, array $filtros = []): LengthAwarePaginator
    {
        $query = ReciboNomina::query()->with('colaborador:id,name,apellidos,numero_empleado,sucursal_principal_id');

        if (! $this->alcance->tieneAlcanceGlobal($usuario)) {
            $query->whereIn('colaborador_id', $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query()->withTrashed(), $usuario)->select('id'));
        }

        return $query
            ->when($filtros['periodo_inicio'] ?? null, fn (Builder $q, string $v) => $q->whereDate('periodo_inicio', '>=', $v))
            ->when($filtros['periodo_fin'] ?? null, fn (Builder $q, string $v) => $q->whereDate('periodo_fin', '<=', $v))
            ->when($filtros['colaborador_id'] ?? null, fn (Builder $q, int|string $v) => $q->where('colaborador_id', (int) $v))
            ->when($filtros['lote'] ?? null, fn (Builder $q, string $v) => $q->where('lote_importacion', $v))
            ->orderByDesc('periodo_inicio')
            ->orderByDesc('id')
            ->paginate(max(1, min(100, (int) ($filtros['per_page'] ?? 20))));
    }

    public function respuestaPdf(ReciboNomina $recibo): StreamedResponse
    {
        abort_if($recibo->pdf_path === null || $recibo->pdf_disk === null, 404, 'El PDF de este recibo no está disponible.');
        $disco = Storage::disk($recibo->pdf_disk);
        abort_unless($disco->exists($recibo->pdf_path), 404, 'El PDF de este recibo no está disponible.');

        $nombre = sprintf('%s.pdf', $recibo->folio ?? 'recibo-'.$recibo->id);

        return $disco->response($recibo->pdf_path, $nombre, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nombre.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(ReciboNomina $recibo, bool $detalle = false): array
    {
        $datos = [
            'id' => $recibo->id,
            'folio' => $recibo->folio,
            'tipo_periodo' => $recibo->tipo_periodo,
            'ejercicio' => $recibo->ejercicio,
            'numero_periodo' => $recibo->numero_periodo,
            'periodo_inicio' => $recibo->periodo_inicio->toDateString(),
            'periodo_fin' => $recibo->periodo_fin->toDateString(),
            'fecha_pago' => $recibo->fecha_pago->toDateString(),
            'total_percepciones' => $recibo->total_percepciones,
            'total_deducciones' => $recibo->total_deducciones,
            'neto' => $recibo->neto,
            'observaciones' => $recibo->observaciones,
            'tiene_pdf' => $recibo->pdf_path !== null,
            'leyenda' => 'RECIBO INTERNO DE NÓMINA - NO FISCAL',
        ];

        if ($detalle) {
            $datos['conceptos'] = $recibo->conceptos()->get()->map(fn ($c) => [
                'tipo' => $c->tipo->value,
                'concepto' => $c->concepto,
                'cantidad' => $c->cantidad,
                'importe' => $c->importe,
                'observaciones' => $c->observaciones,
            ])->all();

            // Recibos anteriores a la tabla de detalle: se reconstruye desde el snapshot JSON.
            if ($datos['conceptos'] === []) {
                $datos['conceptos'] = [
                    ...array_map(fn (array $p) => ['tipo' => 'percepcion', 'concepto' => $p['concepto'], 'cantidad' => '1.00', 'importe' => number_format((float) $p['monto'], 2, '.', ''), 'observaciones' => null], $recibo->percepciones),
                    ...array_map(fn (array $d) => ['tipo' => 'deduccion', 'concepto' => $d['concepto'], 'cantidad' => '1.00', 'importe' => number_format((float) $d['monto'], 2, '.', ''), 'observaciones' => null], $recibo->deducciones),
                ];
            }
        }

        return $datos;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return list<array{tipo: string, concepto: string, cantidad: float, importe: float, observaciones: string|null, clasificacion?: string|null, prestamo_id?: int|null}>
     */
    private function normalizarConceptos(array $datos): array
    {
        $conceptos = [];

        if (isset($datos['conceptos']) && is_array($datos['conceptos'])) {
            foreach ($datos['conceptos'] as $c) {
                $conceptos[] = [
                    'tipo' => TipoConceptoNomina::from((string) $c['tipo'])->value,
                    'concepto' => (string) $c['concepto'],
                    'cantidad' => round((float) ($c['cantidad'] ?? 1), 2),
                    'importe' => round((float) $c['importe'], 2),
                    'observaciones' => isset($c['observaciones']) ? (string) $c['observaciones'] : null,
                    'clasificacion' => isset($c['clasificacion']) ? (string) $c['clasificacion'] : null,
                    'prestamo_id' => isset($c['prestamo_id']) ? (int) $c['prestamo_id'] : null,
                ];
            }

            return $conceptos;
        }

        if (isset($datos['sueldo_base'])) {
            $conceptos[] = ['tipo' => TipoConceptoNomina::Percepcion->value, 'concepto' => 'Sueldo base del periodo', 'cantidad' => 1.0, 'importe' => round((float) $datos['sueldo_base'], 2), 'observaciones' => null];
        }

        foreach (($datos['percepciones'] ?? []) as $p) {
            $conceptos[] = ['tipo' => TipoConceptoNomina::Percepcion->value, 'concepto' => (string) $p['concepto'], 'cantidad' => 1.0, 'importe' => round((float) $p['monto'], 2), 'observaciones' => null];
        }

        foreach (($datos['deducciones'] ?? []) as $d) {
            $conceptos[] = [
                'tipo' => TipoConceptoNomina::Deduccion->value,
                'concepto' => (string) $d['concepto'],
                'cantidad' => 1.0,
                'importe' => round((float) $d['monto'], 2),
                'observaciones' => null,
                'clasificacion' => isset($d['tipo']) ? (string) $d['tipo'] : null,
                'prestamo_id' => isset($d['prestamo_id']) ? (int) $d['prestamo_id'] : null,
            ];
        }

        return $conceptos;
    }

    /**
     * Genera el PDF (leyenda NO FISCAL) y lo archiva en el expediente
     * (NominaInterna). Un fallo aquí nunca revierte el recibo ya persistido:
     * se registra y RH puede reintentar con regenerarPdf().
     */
    private function generarPdf(ReciboNomina $recibo, ?User $actor): void
    {
        $recibo->loadMissing(['colaborador.puesto', 'colaborador.sucursalPrincipal.empresa', 'generadoPor']);
        $actor ??= $recibo->generadoPor;

        try {
            $contenido = Pdf::loadView('pdf.recibo-nomina', [
                'recibo' => $recibo,
                'colaborador' => $recibo->colaborador,
                'periodo_inicio' => $recibo->periodo_inicio,
                'periodo_fin' => $recibo->periodo_fin,
                'fecha_pago' => $recibo->fecha_pago,
                'percepciones' => $recibo->percepciones,
                'deducciones' => $recibo->deducciones,
                'conceptos' => $recibo->conceptos()->get(),
                'total_percepciones' => (float) $recibo->total_percepciones,
                'total_deducciones' => (float) $recibo->total_deducciones,
                'neto' => (float) $recibo->neto,
            ])->setPaper('letter', 'portrait')->output();

            if ($actor === null) {
                throw ValidationException::withMessages(['recibo' => 'El recibo no tiene usuario generador.']);
            }

            $documento = $this->motor->registrarPdf($recibo->colaborador, $contenido, sprintf('Recibo interno %s', $recibo->folio ?? $recibo->id), $actor, [
                'clave' => 'recibo_nomina_interno',
                'categoria' => CategoriaDocumento::NominaInterna,
                'payload' => [
                    'folio' => (string) $recibo->folio,
                    'periodo_inicio' => $recibo->periodo_inicio->toDateString(),
                    'periodo_fin' => $recibo->periodo_fin->toDateString(),
                    'total_percepciones' => (string) $recibo->total_percepciones,
                    'total_deducciones' => (string) $recibo->total_deducciones,
                    'neto' => (string) $recibo->neto,
                ],
                'documentable' => $recibo,
            ]);

            $recibo->update(['pdf_disk' => $documento->disk, 'pdf_path' => $documento->path, 'checksum' => $documento->checksum]);
        } catch (Throwable $e) {
            Log::warning('ReciboNominaService: no se pudo generar/guardar el PDF del recibo.', [
                'recibo_id' => $recibo->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
