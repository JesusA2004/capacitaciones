<?php

namespace App\Services\Nomina;

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoReciboNomina;
use App\Enums\FamiliaAdministrativa;
use App\Enums\MotorPdf;
use App\Enums\TipoConceptoNomina;
use App\Exceptions\ArchivoGeneradoNoDisponibleException;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\NominaLote;
use App\Models\Prestamo;
use App\Models\PrestamoMovimiento;
use App\Models\ReciboNomina;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Colaboradores\BuscadorColaboradoresService;
use App\Services\DocumentosAdministrativos\DatosDocumentoAdministrativo;
use App\Services\DocumentosAdministrativos\DisenoAdministrativoService;
use App\Services\DocumentosAdministrativos\DocumentoAdministrativoService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\Tareas\NotificadorRhService;
use App\Support\Paginacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Recibo de nómina (semanal por defecto). No se timbra, no calcula
 * ISR/IMSS, no sustituye al sistema de nómina y no se integra con NOI: RH
 * captura (individualmente o por importación CSV/XLSX administrativa, ver
 * ReciboNominaImportService) los conceptos ya calculados y el sistema emite
 * el "RECIBO DE NÓMINA", archivado en el expediente del colaborador
 * (carpeta NominaInterna).
 *
 * Cada recibo guarda snapshot de sus conceptos: un cambio posterior de
 * sueldo no reescribe recibos emitidos.
 */
class ReciboNominaService
{
    public const TIPO_PERIODO_SEMANAL = 'semanal';

    public const TIPO_PERIODO_QUINCENAL = 'quincenal';

    public function __construct(
        private readonly PrestamoService $prestamos,
        private readonly MotorDocumentalService $motor,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AuditoriaService $auditoria,
        private readonly NotificadorRhService $notificador,
        private readonly DocumentoAdministrativoService $documentosAdministrativos,
        private readonly DatosDocumentoAdministrativo $datosDocumento,
        private readonly DisenoAdministrativoService $disenos,
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
    public function generar(Colaborador $colaborador, array $datos, ?User $generadoPor, ?string $lote = null, bool $borrador = false): ReciboNomina
    {
        if ($generadoPor === null && ! $borrador) {
            throw ValidationException::withMessages(['recibo' => 'Un recibo emitido necesita al usuario que lo emite.']);
        }

        $conceptos = $this->normalizarConceptos($datos);
        $atributos = $this->atributosRecibo($colaborador, $datos, $conceptos);
        $periodoInicio = $atributos['periodo_inicio'];
        $periodoFin = $atributos['periodo_fin'];

        $recibo = DB::transaction(function () use ($colaborador, $generadoPor, $conceptos, $atributos, $periodoInicio, $periodoFin, $lote): ReciboNomina {
            // Bloqueo por colaborador: dos capturas/importaciones simultáneas
            // no pueden emitir dos recibos del mismo periodo.
            Colaborador::query()->whereKey($colaborador->id)->lockForUpdate()->first();

            // Un recibo CANCELADO no bloquea volver a preparar el periodo.
            $duplicado = ReciboNomina::query()
                ->where('colaborador_id', $colaborador->id)
                ->where('estado', '!=', EstadoReciboNomina::Cancelado->value)
                ->whereDate('periodo_inicio', $periodoInicio->toDateString())
                ->whereDate('periodo_fin', $periodoFin->toDateString())
                ->exists();

            if ($duplicado) {
                throw ValidationException::withMessages([
                    'periodo_inicio' => sprintf('%s ya tiene un recibo del periodo %s – %s.', $colaborador->nombreCompleto(), $periodoInicio->format('d/m/Y'), $periodoFin->format('d/m/Y')),
                ]);
            }

            $recibo = ReciboNomina::query()->create([
                ...$atributos,
                'lote_importacion' => $lote,
                'generado_por' => $generadoPor?->id,
            ]);

            $recibo->update(['folio' => sprintf('RIN-%06d', $recibo->id)]);

            foreach ($conceptos as $orden => $concepto) {
                $recibo->conceptos()->create([
                    'tipo' => $concepto['tipo'],
                    'clave' => $concepto['clave'] ?? null,
                    'concepto' => $concepto['concepto'],
                    'cantidad' => $concepto['cantidad'],
                    'importe' => $concepto['importe'],
                    'observaciones' => $concepto['observaciones'],
                    'orden' => $orden,
                ]);
            }

            // El saldo del préstamo NO se toca aquí: el recibo nace en
            // borrador y el descuento se registra solo al EMITIRSE (ver
            // registrarDescuentosPrestamo()).

            return $recibo;
        });

        $this->auditoria->registrar($borrador ? 'recibo_nomina_preparado' : 'recibo_nomina_generado', $recibo, $generadoPor, [
            'colaborador_id' => $colaborador->id,
            'periodo' => $periodoInicio->toDateString().' / '.$periodoFin->toDateString(),
            'neto' => $recibo->neto,
            'lote' => $lote,
        ]);

        if (! $borrador) {
            // Si el PDF falla, el recibo queda preparado (borrador) y el
            // error llega a RH con un motivo humano para reintentar.
            return $this->emitir($recibo, $generadoPor, $lote === null);
        }

        return $recibo->refresh();
    }

    /**
     * Vista previa REAL del recibo (mismo diseño vigente y mismos datos que
     * generar() + emitir()) con lo capturado en el formulario, SIN crear el
     * recibo, sin PDF en el expediente, sin auditoría ni avisos: RH revisa
     * antes de «Generar». El folio aún no existe (se asigna al generarlo).
     *
     * @param  array<string, mixed>  $datos  Misma forma que generar().
     *
     * @throws ValidationException Sin conceptos o periodo inválido.
     */
    public function vistaPreviaPdf(Colaborador $colaborador, array $datos): string
    {
        $recibo = new ReciboNomina($this->atributosRecibo($colaborador, $datos, $this->normalizarConceptos($datos)));
        $recibo->folio = 'VISTA PREVIA';
        $recibo->setRelation('colaborador', $colaborador);

        return $this->documentosAdministrativos->renderizarVigente(FamiliaAdministrativa::ReciboNomina, $this->datosDocumento->recibo($recibo));
    }

    /**
     * Atributos del recibo (periodo, totales y snapshot de conceptos) a
     * partir de lo capturado: única fuente para generar() y la vista previa.
     *
     * @param  array<string, mixed>  $datos
     * @param  list<array{tipo: string, clave?: string|null, concepto: string, cantidad: float, importe: float, observaciones: string|null, clasificacion?: string|null, prestamo_id?: int|null}>  $conceptos
     * @return array<string, mixed>&array{periodo_inicio: Carbon, periodo_fin: Carbon}
     *
     * @throws ValidationException Sin conceptos o periodo inválido.
     */
    private function atributosRecibo(Colaborador $colaborador, array $datos, array $conceptos): array
    {
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
        $this->exigirNetoNoNegativo($totalPercepciones, $totalDeducciones);

        return [
            'colaborador_id' => $colaborador->id,
            'periodo_inicio' => $periodoInicio,
            'periodo_fin' => $periodoFin,
            'fecha_pago' => Carbon::parse($datos['fecha_pago'] ?? $periodoFin),
            'tipo_periodo' => $tipoPeriodo,
            'ejercicio' => $tipoPeriodo === self::TIPO_PERIODO_QUINCENAL ? $periodoInicio->year : (int) $periodoInicio->isoFormat('GGGG'),
            'numero_periodo' => match ($tipoPeriodo) {
                self::TIPO_PERIODO_SEMANAL => $periodoInicio->isoWeek(),
                // 1–24: dos quincenas por mes.
                self::TIPO_PERIODO_QUINCENAL => ($periodoInicio->month - 1) * 2 + ($periodoInicio->day <= 15 ? 1 : 2),
                default => null,
            },
            // Siempre nace como borrador: solo emitir() lo marca emitido, y
            // únicamente DESPUÉS de que su PDF quedó guardado.
            'estado' => EstadoReciboNomina::Borrador,
            'nomina_lote_id' => isset($datos['nomina_lote_id']) ? (int) $datos['nomina_lote_id'] : null,
            'dias_pagados' => isset($datos['dias_pagados']) ? round((float) $datos['dias_pagados'], 2) : null,
            'dias_falta' => isset($datos['dias_falta']) ? round((float) $datos['dias_falta'], 2) : null,
            'dias_incapacidad' => isset($datos['dias_incapacidad']) ? round((float) $datos['dias_incapacidad'], 2) : null,
            'advertencias' => isset($datos['advertencias']) && is_array($datos['advertencias']) && $datos['advertencias'] !== [] ? array_values($datos['advertencias']) : null,
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
        ];
    }

    /**
     * Ajusta los conceptos de un recibo (borrador o ya emitido). Si ya se
     * había emitido, se vuelve a generar su PDF (el anterior queda en el
     * historial del expediente) y se registra en auditoría.
     *
     * @param  array<string, mixed>  $datos  `conceptos` [{tipo, concepto, cantidad?, importe, observaciones?}] y `observaciones?`.
     */
    public function actualizar(ReciboNomina $recibo, array $datos, User $actor): ReciboNomina
    {
        $conceptos = $this->normalizarConceptos(['conceptos' => $datos['conceptos'] ?? []]);

        if ($conceptos === []) {
            throw ValidationException::withMessages(['conceptos' => 'Captura al menos un concepto.']);
        }

        $antes = (string) $recibo->neto;

        DB::transaction(function () use ($recibo, $conceptos, $datos): void {
            ReciboNomina::query()->whereKey($recibo->id)->lockForUpdate()->first();
            $prestamosAntes = $this->descuentosPorPrestamo($recibo);
            $this->guardarConceptos($recibo, $conceptos);

            // Un recibo emitido ya descontó el préstamo: su monto no se
            // cambia aquí (el saldo quedaría distinto del recibo).
            if ($recibo->estado === EstadoReciboNomina::Emitido && $this->descuentosPorPrestamo($recibo->refresh()) !== $prestamosAntes) {
                throw ValidationException::withMessages(['conceptos' => 'Este recibo ya está emitido y descontó el préstamo: no se cambia ese descuento aquí. Cancela el recibo y genera uno nuevo.']);
            }

            if (array_key_exists('observaciones', $datos)) {
                $recibo->update(['observaciones' => $datos['observaciones'] !== null ? (string) $datos['observaciones'] : null]);
            }

            // El PDF anterior (montos viejos) deja de ser "el" recibo; queda
            // en el historial del expediente. Si el nuevo no se pudiera
            // generar, la pantalla ofrece repararlo en vez de mostrar montos
            // que ya no corresponden.
            if ($recibo->estado === EstadoReciboNomina::Emitido) {
                $recibo->update(['pdf_disk' => null, 'pdf_path' => null, 'checksum' => null]);
            }
        });

        // Totales del lote (si el recibo pertenece a uno): siempre del servidor.
        if ($recibo->nomina_lote_id !== null) {
            $totales = ReciboNomina::query()
                ->where('nomina_lote_id', $recibo->nomina_lote_id)
                ->where('estado', '!=', EstadoReciboNomina::Cancelado->value)
                ->selectRaw('coalesce(sum(total_percepciones), 0) as percepciones, coalesce(sum(total_deducciones), 0) as deducciones, coalesce(sum(neto), 0) as neto')
                ->toBase()
                ->first();

            NominaLote::query()->whereKey($recibo->nomina_lote_id)->update([
                'total_percepciones' => round((float) ($totales->percepciones ?? 0), 2),
                'total_deducciones' => round((float) ($totales->deducciones ?? 0), 2),
                'total_neto' => round((float) ($totales->neto ?? 0), 2),
            ]);
        }

        $this->auditoria->registrar('recibo_nomina_editado', $recibo, $actor, [
            'neto_anterior' => $antes,
            'neto_nuevo' => (string) $recibo->neto,
            'estado' => $recibo->estado->value,
        ]);

        if ($recibo->estado === EstadoReciboNomina::Emitido) {
            try {
                $this->generarPdf($recibo, $actor);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['pdf' => sprintf('Los cambios se guardaron, pero %s Usa «Reparar PDF» para reintentar.', lcfirst($this->primerMensaje($e)))]);
            }
        }

        return $recibo->refresh();
    }

    /**
     * Emite un borrador: genera su PDF, lo archiva en el expediente y le
     * avisa al colaborador. Idempotente (un recibo ya emitido no se toca).
     */
    /**
     * Orden garantizado: PREPARADO → PDF guardado → EMITIDO. Si el PDF no se
     * puede generar/guardar, el recibo sigue en borrador y se lanza un
     * ValidationException con el motivo (RH reintenta): nunca existe un
     * recibo "emitido" sin archivo.
     *
     * @throws ValidationException
     */
    public function emitir(ReciboNomina $recibo, User $actor, bool $notificar = true): ReciboNomina
    {
        $recibo->refresh();

        // Solo un borrador se emite: emitido (doble emisión) o cancelado no se tocan.
        if ($recibo->estado !== EstadoReciboNomina::Borrador) {
            return $recibo;
        }

        if ($recibo->generado_por === null) {
            $recibo->update(['generado_por' => $actor->id]);
        }

        $this->generarPdf($recibo, $actor);

        $emitido = DB::transaction(function () use ($recibo, $actor): bool {
            $fila = ReciboNomina::query()->whereKey($recibo->id)->lockForUpdate()->first();

            if ($fila === null || $fila->estado !== EstadoReciboNomina::Borrador) {
                return false;
            }

            // Antes de marcarlo emitido: si el préstamo no admite el
            // descuento (liquidado, saldo menor), el recibo sigue en
            // borrador con el motivo para RH.
            $this->registrarDescuentosPrestamo($fila, $actor);

            $fila->update(['estado' => EstadoReciboNomina::Emitido, 'emitido_at' => now(), 'emitido_por' => $actor->id]);

            return true;
        });

        if (! $emitido) {
            return $recibo->refresh();
        }

        $recibo->refresh();
        $this->auditoria->registrar('recibo_nomina_emitido', $recibo, $actor, ['neto' => (string) $recibo->neto]);

        if ($notificar) {
            $this->notificarColaborador($recibo);
        }

        return $recibo->refresh();
    }

    /**
     * Cancela un recibo (se deja de mostrar al trabajador, se conserva para
     * auditoría y el periodo puede volver a prepararse). No avisa a nadie.
     */
    public function cancelar(ReciboNomina $recibo, User $actor): ReciboNomina
    {
        $cancelado = DB::transaction(function () use ($recibo, $actor): int {
            $filas = ReciboNomina::query()
                ->whereKey($recibo->id)
                ->where('estado', '!=', EstadoReciboNomina::Cancelado->value)
                ->update(['estado' => EstadoReciboNomina::Cancelado->value, 'cancelado_at' => now(), 'cancelado_por' => $actor->id]);

            // Si ya se había emitido (y descontado del préstamo), el saldo
            // regresa con un movimiento compensatorio.
            if ($filas > 0) {
                $this->revertirDescuentosPrestamo($recibo, $actor);
            }

            return $filas;
        });

        if ($cancelado > 0) {
            $this->auditoria->registrar('recibo_nomina_cancelado', $recibo, $actor, ['folio' => $recibo->folio]);
        }

        return $recibo->refresh();
    }

    /**
     * Registra en el ledger del préstamo las deducciones de préstamo que RH
     * confirmó explícitamente en el recibo (snapshot `deducciones` con
     * tipo `prestamo` y prestamo_id). Se llama SOLO al emitir, dentro de la
     * transacción que lo marca emitido. Idempotente por recibo+préstamo:
     * reintentar la emisión nunca descuenta dos veces. Varias líneas del
     * mismo préstamo se suman en un solo movimiento. MR. LANA PEOPLE no
     * ejecuta descuentos de nómina: es el saldo administrativo/informativo.
     */
    private function registrarDescuentosPrestamo(ReciboNomina $recibo, User $actor): void
    {
        foreach ($this->descuentosPorPrestamo($recibo) as $prestamoId => $monto) {
            $prestamo = Prestamo::query()->where('id', $prestamoId)->where('colaborador_id', $recibo->colaborador_id)->first();

            if ($prestamo === null || $monto <= 0) {
                continue;
            }

            $this->prestamos->registrarMovimiento($prestamo, $monto, PrestamoService::TIPO_NOMINA, $actor, $recibo->id);
        }
    }

    /**
     * Descuento total por préstamo según las deducciones del recibo.
     *
     * @return array<int, float>
     */
    private function descuentosPorPrestamo(ReciboNomina $recibo): array
    {
        $montos = [];

        foreach ($recibo->deducciones as $deduccion) {
            if (($deduccion['tipo'] ?? null) !== 'prestamo' || ! isset($deduccion['prestamo_id'])) {
                continue;
            }

            $prestamoId = (int) $deduccion['prestamo_id'];
            $montos[$prestamoId] = round(($montos[$prestamoId] ?? 0.0) + (float) $deduccion['monto'], 2);
        }

        ksort($montos);

        return $montos;
    }

    /**
     * Revierte (movimiento compensatorio `reverso_nomina`, idempotente) los
     * descuentos de préstamo que registró un recibo que se está cancelando.
     * Un borrador nunca descontó, así que no genera nada. Debe llamarse
     * dentro de la transacción de la cancelación.
     */
    public function revertirDescuentosPrestamo(ReciboNomina $recibo, User $actor): void
    {
        PrestamoMovimiento::query()
            ->where('recibo_nomina_id', $recibo->id)
            ->where('tipo', PrestamoService::TIPO_NOMINA)
            ->orderBy('id')
            ->get()
            ->each(fn (PrestamoMovimiento $movimiento) => $this->prestamos->revertirMovimientoNomina($movimiento, $actor));
    }

    /**
     * Un fallo al avisar nunca deshace la emisión (ver CLAUDE.md).
     */
    private function notificarColaborador(ReciboNomina $recibo): void
    {
        // Sin autoservicio de recibos (se entregan impresos) no hay nada que
        // el colaborador pueda abrir: no se le avisa.
        if (! config('features.recibos_colaborador')) {
            return;
        }

        try {
            $recibo->loadMissing('colaborador.user');
            $usuario = $recibo->colaborador->user;

            if ($usuario !== null) {
                // NotificadorRhService envía la notificación in-app Y el push
                // encolado (SendExpoPushJob) que abre el recibo en la app: un
                // solo aviso por recibo.
                $this->notificador->notificar([$usuario], 'recibo_nomina', 'Recibo de nómina disponible', sprintf('Ya puedes consultar tu recibo de nómina del %s al %s.', $recibo->periodo_inicio->format('d/m/Y'), $recibo->periodo_fin->format('d/m/Y')), $recibo, 'ver_recibo', 'baja');
            }
        } catch (Throwable $e) {
            Log::warning('ReciboNominaService: no se pudo avisar al colaborador de su recibo.', ['recibo_id' => $recibo->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Reemplaza el detalle de conceptos y recalcula totales y snapshot JSON.
     * Debe llamarse dentro de una transacción.
     *
     * @param  list<array{tipo: string, clave?: string|null, concepto: string, cantidad: float, importe: float, observaciones: string|null, clasificacion?: string|null, prestamo_id?: int|null}>  $conceptos
     */
    private function guardarConceptos(ReciboNomina $recibo, array $conceptos): void
    {
        $percepciones = array_values(array_filter($conceptos, fn (array $c) => $c['tipo'] === TipoConceptoNomina::Percepcion->value));
        $deducciones = array_values(array_filter($conceptos, fn (array $c) => $c['tipo'] === TipoConceptoNomina::Deduccion->value));
        $totalPercepciones = round(array_sum(array_column($percepciones, 'importe')), 2);
        $totalDeducciones = round(array_sum(array_column($deducciones, 'importe')), 2);
        $this->exigirNetoNoNegativo($totalPercepciones, $totalDeducciones);

        $recibo->conceptos()->delete();

        foreach ($conceptos as $orden => $concepto) {
            $recibo->conceptos()->create([
                'tipo' => $concepto['tipo'],
                'clave' => $concepto['clave'] ?? null,
                'concepto' => $concepto['concepto'],
                'cantidad' => $concepto['cantidad'],
                'importe' => $concepto['importe'],
                'observaciones' => $concepto['observaciones'],
                'orden' => $orden,
            ]);
        }

        // La línea de préstamo confirmada por RH se conserva aunque el editor
        // (o el ajuste masivo) no reenvíe su clasificación: sin esto, editar
        // un borrador perdía el enlace y al emitirlo no se descontaba.
        $prestamosPrevios = [];

        foreach ($recibo->deducciones as $previa) {
            if (($previa['tipo'] ?? null) === 'prestamo' && isset($previa['prestamo_id'])) {
                $prestamosPrevios[(string) $previa['concepto']] = (int) $previa['prestamo_id'];
            }
        }

        $recibo->update([
            'percepciones' => array_map(fn (array $c) => ['concepto' => $c['concepto'], 'monto' => $c['importe']], $percepciones),
            'deducciones' => array_map(function (array $c) use ($prestamosPrevios): array {
                $prestamoId = $c['prestamo_id'] ?? null;
                $clasificacion = $c['clasificacion'] ?? null;

                if ($prestamoId === null && $clasificacion === null && isset($prestamosPrevios[$c['concepto']])) {
                    $prestamoId = $prestamosPrevios[$c['concepto']];
                    $clasificacion = 'prestamo';
                }

                return array_filter([
                    'concepto' => $c['concepto'],
                    'monto' => $c['importe'],
                    'tipo' => $clasificacion,
                    'prestamo_id' => $prestamoId,
                ], fn ($v) => $v !== null);
            }, $deducciones),
            'total_percepciones' => $totalPercepciones,
            'total_deducciones' => $totalDeducciones,
            'neto' => round($totalPercepciones - $totalDeducciones, 2),
        ]);
    }

    /**
     * Conceptos actuales de un recibo en la forma que acepta actualizar().
     *
     * @return list<array{tipo: string, concepto: string, cantidad: float, importe: float, observaciones: string|null}>
     */
    public function conceptosEditables(ReciboNomina $recibo): array
    {
        return array_values(array_map(fn (array $c): array => [
            'tipo' => (string) $c['tipo'],
            'clave' => isset($c['clave']) ? (string) $c['clave'] : null,
            'concepto' => (string) $c['concepto'],
            'cantidad' => (float) $c['cantidad'],
            'importe' => (float) $c['importe'],
            'observaciones' => isset($c['observaciones']) ? (string) $c['observaciones'] : null,
        ], $this->aArray($recibo, true)['conceptos']));
    }

    /**
     * Repara el PDF de un recibo existente (pdf_path vacío o archivo que ya
     * no está en el almacenamiento). Recupera en este orden:
     *  1. el archivo actual, si sigue ahí (no hace nada);
     *  2. el último GeneratedDocument del recibo cuyo archivo sigue presente
     *     → solo corrige las referencias del recibo;
     *  3. lo regenera con el SNAPSHOT del recibo (periodo, conceptos, neto;
     *     nunca el sueldo actual) y con el diseño HISTÓRICO guardado en el
     *     layout_snapshot del documento original; si nunca tuvo snapshot,
     *     con el diseño vigente y queda auditado
     *     `regenerado_con_version_actual = true`.
     * Un borrador no se repara: se emite.
     *
     * @throws ValidationException Si no se pudo regenerar (motivo humano).
     */
    public function regenerarPdf(ReciboNomina $recibo, ?User $actor = null): ReciboNomina
    {
        $recibo->refresh();

        if ($recibo->estado !== EstadoReciboNomina::Emitido) {
            throw ValidationException::withMessages(['pdf' => 'Solo un recibo emitido tiene PDF guardado; emítelo para generarlo.']);
        }

        if ($this->pdfDisponible($recibo, false)) {
            return $recibo;
        }

        $anterior = $recibo->pdf_path;
        $documentos = GeneratedDocument::query()
            ->where('documentable_type', $recibo->getMorphClass())
            ->where('documentable_id', $recibo->id)
            ->orderByDesc('id')
            ->get();

        $existente = $documentos->first(fn (GeneratedDocument $d) => $this->existe($d->disk, $d->path));

        if ($existente !== null) {
            $recibo->update(['pdf_disk' => $existente->disk, 'pdf_path' => $existente->path, 'checksum' => $existente->checksum]);
            $this->auditoria->registrar('recibo_nomina_pdf_reparado', $recibo, $actor, [
                'metodo' => 'referencia_documento_generado',
                'generated_document_id' => $existente->id,
                'ruta_anterior' => $anterior,
            ]);

            return $recibo->refresh();
        }

        $historico = $documentos->first(fn (GeneratedDocument $d) => is_array($d->layout_snapshot['diseno'] ?? null));
        $documento = $this->generarPdf($recibo, $actor, $historico, $documentos->first());

        $this->auditoria->registrar('recibo_nomina_pdf_reparado', $recibo, $actor, [
            'metodo' => 'regenerado_desde_snapshot',
            'generated_document_id' => $documento->id,
            'ruta_anterior' => $anterior,
            'regenerado_con_version_actual' => $historico === null,
            'version_diseno' => $historico?->layout_snapshot['version'] ?? null,
        ]);

        return $recibo->refresh();
    }

    /**
     * ¿El PDF del recibo existe físicamente? El resultado POSITIVO se
     * guarda en caché por ruta+checksum (1 h): un listado de 50 recibos no
     * consulta el NAS 50 veces en cada carga, y un archivo reparado se ve
     * al instante porque el negativo nunca se guarda.
     */
    public function pdfDisponible(ReciboNomina $recibo, bool $usarCache = true): bool
    {
        if ($recibo->pdf_path === null || $recibo->pdf_disk === null) {
            return false;
        }

        $clave = sprintf('recibo_pdf_existe:%d:%s', $recibo->id, sha1($recibo->pdf_path.'|'.$recibo->checksum));

        if ($usarCache && Cache::get($clave) === true) {
            return true;
        }

        $existe = $this->existe($recibo->pdf_disk, $recibo->pdf_path);

        if ($existe) {
            Cache::put($clave, true, now()->addHour());
        }

        return $existe;
    }

    private function existe(string $disco, string $ruta): bool
    {
        try {
            return Storage::disk($disco)->exists($ruta);
        } catch (Throwable $e) {
            Log::warning('ReciboNominaService: no se pudo verificar el archivo del recibo.', ['disco' => $disco, 'ruta' => $ruta, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @return LengthAwarePaginator<int, ReciboNomina>
     */
    public function delColaborador(Colaborador $colaborador, int $porPagina = 20): LengthAwarePaginator
    {
        // El colaborador solo ve recibos emitidos (los borradores son de RH).
        return ReciboNomina::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('estado', EstadoReciboNomina::Emitido->value)
            ->orderByDesc('periodo_inicio')
            ->orderByDesc('id')
            ->paginate(Paginacion::porPagina($porPagina));
    }

    /**
     * Listado administrativo (RH) acotado por alcance organizacional.
     *
     * @param  array{periodo_inicio?: string|null, periodo_fin?: string|null, colaborador_id?: int|string|null, lote?: string|null, estado?: string|null, q?: string|null, per_page?: int|string|null}  $filtros
     * @return LengthAwarePaginator<int, ReciboNomina>
     */
    public function listar(User $usuario, array $filtros = []): LengthAwarePaginator
    {
        $query = ReciboNomina::query()->with('colaborador:id,name,apellidos,numero_empleado,sucursal_principal_id');

        // Alcance del módulo «recibos» (Roles y permisos), no por nombre de rol.
        $this->alcance->limitarPorModulo($query, $usuario, 'recibos', 'colaborador_id', true);

        return $query
            ->when($filtros['periodo_inicio'] ?? null, fn (Builder $q, string $v) => $q->whereDate('periodo_inicio', '>=', $v))
            ->when($filtros['periodo_fin'] ?? null, fn (Builder $q, string $v) => $q->whereDate('periodo_fin', '<=', $v))
            ->when($filtros['colaborador_id'] ?? null, fn (Builder $q, int|string $v) => $q->where('colaborador_id', (int) $v))
            ->when($filtros['lote'] ?? null, fn (Builder $q, string $v) => $q->where('lote_importacion', $v))
            ->when($filtros['estado'] ?? null, fn (Builder $q, string $v) => $q->where('estado', $v))
            ->when($filtros['q'] ?? null, fn (Builder $q, string $v) => app(BuscadorColaboradoresService::class)->aplicarPorColumna($q, $v))
            ->orderByDesc('periodo_inicio')
            ->orderByDesc('id')
            ->paginate(Paginacion::porPagina(20, $filtros['per_page'] ?? null));
    }

    /**
     * El recibo YA existe (la ruta lo resolvió): si su archivo falta es un
     * error operativo recuperable, nunca un 404 — quien puede regenerarlo ve
     * el botón para hacerlo.
     *
     * @throws ArchivoGeneradoNoDisponibleException
     */
    public function respuestaPdf(ReciboNomina $recibo, ?User $usuario = null): StreamedResponse
    {
        if (! $this->pdfDisponible($recibo, false)) {
            $puedeReparar = $usuario !== null && $recibo->estado === EstadoReciboNomina::Emitido && $usuario->can('regenerar', $recibo);

            throw new ArchivoGeneradoNoDisponibleException(
                titulo: sprintf('Recibo %s', $recibo->folio ?? $recibo->id),
                mensaje: $puedeReparar
                    ? 'El recibo existe, pero su PDF no está disponible. Puedes generarlo de nuevo con los mismos montos.'
                    : 'El recibo existe, pero su PDF no está disponible por ahora. Recursos Humanos puede generarlo de nuevo.',
                urlReparar: $puedeReparar ? route('rh.nomina.recibos.reparar-pdf', $recibo) : null,
            );
        }

        $ruta = (string) $recibo->pdf_path;
        $disco = Storage::disk((string) $recibo->pdf_disk);
        $nombre = sprintf('%s.pdf', $recibo->folio ?? 'recibo-'.$recibo->id);

        return $disco->response($ruta, $nombre, [
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
        $tienePdf = $this->pdfDisponible($recibo);
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
            'estado' => $recibo->estado->value,
            'estado_etiqueta' => $recibo->estado->etiqueta(),
            'emitido_at' => $recibo->emitido_at?->toIso8601String(),
            // Que el ARCHIVO exista, no solo la referencia (ver pdfDisponible()).
            'tiene_pdf' => $tienePdf,
            'requiere_reparacion' => $recibo->estado === EstadoReciboNomina::Emitido && ! $tienePdf,
            'leyenda' => 'RECIBO DE NÓMINA',
        ];

        if ($detalle) {
            $datos['conceptos'] = $recibo->conceptos()->get()->map(fn ($c) => [
                'tipo' => $c->tipo->value,
                'clave' => $c->clave,
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
     * @return list<array{tipo: string, clave?: string|null, concepto: string, cantidad: float, importe: float, observaciones: string|null, clasificacion?: string|null, prestamo_id?: int|null}>
     */
    private function normalizarConceptos(array $datos): array
    {
        $conceptos = [];

        if (isset($datos['conceptos']) && is_array($datos['conceptos'])) {
            foreach ($datos['conceptos'] as $c) {
                $conceptos[] = [
                    'tipo' => TipoConceptoNomina::from((string) $c['tipo'])->value,
                    'clave' => isset($c['clave']) && trim((string) $c['clave']) !== '' ? mb_substr(trim((string) $c['clave']), 0, 10) : null,
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
     * Genera el PDF del recibo y lo archiva en el expediente
     * (NominaInterna). Si falla, lanza un ValidationException con un motivo
     * humano (el detalle técnico va al log): quien llama decide — emitir()
     * deja el recibo en borrador, regenerarPdf() lo reporta para reintentar.
     *
     * Con `$historico` (documento original con layout_snapshot) se respeta
     * el DISEÑO con el que se emitió; sin él, el diseño vigente. Los DATOS
     * siempre son el snapshot del recibo.
     *
     * @throws ValidationException
     */
    private function generarPdf(ReciboNomina $recibo, ?User $actor, ?GeneratedDocument $historico = null, ?GeneratedDocument $revisionDe = null): GeneratedDocument
    {
        $recibo->loadMissing(['colaborador.puesto', 'colaborador.sucursalPrincipal.empresa', 'generadoPor']);
        $actor ??= $recibo->generadoPor;

        if ($actor === null) {
            throw ValidationException::withMessages(['pdf' => 'No se pudo generar el PDF del recibo: no tiene registrado quién lo emitió.']);
        }

        try {
            $datos = $this->datosDocumento->recibo($recibo);

            if ($historico !== null && is_array($historico->layout_snapshot)) {
                $snapshot = $historico->layout_snapshot;
                $diseno = $this->disenos->normalizar(FamiliaAdministrativa::ReciboNomina, (array) $snapshot['diseno']);
                $motor = MotorPdf::tryFrom((string) ($snapshot['motor_configurado'] ?? '')) ?? MotorPdf::porDefecto();
                $render = $this->documentosAdministrativos->renderizar(FamiliaAdministrativa::ReciboNomina, $datos, $diseno, $motor);
                $documento = [
                    'pdf' => $render['pdf'],
                    'opciones_registro' => [
                        'master_familia' => $historico->master_familia,
                        'master_version' => $historico->master_version,
                        'master_hash' => $historico->master_hash,
                        'version' => $historico->version_plantilla,
                        'conversion_engine' => $render['motor']->value,
                        'layout_snapshot' => [...$snapshot, 'regenerado_en' => now()->toIso8601String(), 'regenerado_con_version_actual' => false],
                    ],
                ];
            } else {
                // DATOS del recibo (snapshot) + DISEÑO vigente de Documentos
                // maestros → Documentos administrativos. El diseño no toca montos.
                $documento = $this->documentosAdministrativos->generar(FamiliaAdministrativa::ReciboNomina, $datos, $actor);

                if ($revisionDe !== null) {
                    $documento['opciones_registro']['layout_snapshot']['regenerado_con_version_actual'] = true;
                }
            }

            $contenido = $documento['pdf'];
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('ReciboNominaService: no se pudo generar el PDF del recibo.', ['recibo_id' => $recibo->id, 'error' => $e->getMessage()]);

            throw ValidationException::withMessages(['pdf' => 'No se pudo generar el PDF del recibo: el generador de documentos no respondió. Intenta de nuevo en un momento; si continúa, avisa a Sistemas.']);
        }

        // registrarPdf ya traduce una falla del almacenamiento (NAS) a un
        // ValidationException con mensaje humano.
        $documento = $this->motor->registrarPdf($recibo->colaborador, $contenido, sprintf('Recibo de nómina %s', $recibo->folio ?? $recibo->id), $actor, [
            ...$documento['opciones_registro'],
            'revision_de_id' => $revisionDe?->id,
            'motivo_revision' => $revisionDe !== null ? 'Recuperación del PDF faltante del recibo' : null,
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

        return $documento;
    }

    /**
     * Ningún recibo sale con neto negativo: RH ajusta las deducciones.
     *
     * @throws ValidationException
     */
    private function exigirNetoNoNegativo(float $percepciones, float $deducciones): void
    {
        if (round($percepciones - $deducciones, 2) < 0) {
            throw ValidationException::withMessages(['conceptos' => sprintf('Las deducciones ($%s) superan las percepciones ($%s): el neto no puede ser negativo.', number_format($deducciones, 2), number_format($percepciones, 2))]);
        }
    }

    private function primerMensaje(ValidationException $e): string
    {
        $mensaje = (string) (collect($e->errors())->flatten()->first() ?? 'no se pudo generar el PDF.');

        return str_ends_with($mensaje, '.') ? $mensaje : $mensaje.'.';
    }
}
