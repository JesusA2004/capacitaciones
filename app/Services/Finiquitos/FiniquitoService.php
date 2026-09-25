<?php

namespace App\Services\Finiquitos;

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoFiniquito;
use App\Enums\TipoBaja;
use App\Enums\TipoConceptoNomina;
use App\Enums\TipoFormatoOficial;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\FiniquitoCalculo;
use App\Models\FiniquitoConcepto;
use App\Models\OfficialFormat;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Formatos\GeneradorFormatoService;
use App\Services\Plantillas\PlaceholderResolver;
use App\Services\Solicitudes\SolicitudDocumentoStorageService;
use App\Services\Vacaciones\VacacionesService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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
        private readonly GeneradorFormatoService $formatosOficiales,
        private readonly PlaceholderResolver $resolver,
        private readonly MotorDocumentalService $motor,
        private readonly DocumentoStorageService $expediente,
    ) {}

    /**
     * Primer cálculo de un finiquito para esta solicitud de baja. Lanza si
     * ya existe uno (usar recalcular() para actualizar montos existentes).
     */
    public function calcular(SolicitudInterna $solicitud, User $actor, float $sueldoMensual, float $sueldoPendiente = 0): FiniquitoCalculo
    {
        if (FiniquitoCalculo::query()->where('solicitud_interna_id', $solicitud->id)->exists()) {
            throw new RuntimeException('Ya existe un cálculo de finiquito para esta baja; usa recalcular().');
        }

        $solicitud->loadMissing(['objetivoColaborador', 'colaboradorObjetivo.colaborador']);
        $colaborador = $solicitud->colaboradorDeBaja();
        abort_unless($colaborador !== null, 422, 'Esta solicitud no tiene un colaborador objetivo con expediente vinculado.');
        abort_unless($colaborador->fecha_ingreso !== null, 422, 'El colaborador no tiene fecha de ingreso registrada.');

        $automaticos = $this->calcularAutomaticos($solicitud, $colaborador, $sueldoMensual, $sueldoPendiente);

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

            return $this->recalcularTotales($finiquito);
        });
    }

    /**
     * Vuelve a correr las fórmulas automáticas (por ejemplo, si cambió el
     * sueldo capturado o la fecha efectiva de baja) preservando los ajustes
     * manuales ya capturados (bonos/descuentos/adeudos/otros conceptos):
     * recalcular no debe borrar trabajo de RH. Regresa el finiquito a
     * "borrador" porque los montos cambiaron y necesita revisarse de nuevo.
     */
    public function recalcular(FiniquitoCalculo $finiquito, User $actor, float $sueldoMensual, float $sueldoPendiente = 0): FiniquitoCalculo
    {
        $this->asegurarNoFirmado($finiquito);

        $finiquito->loadMissing(['solicitudInterna.objetivoColaborador', 'solicitudInterna.colaboradorObjetivo.colaborador']);
        $solicitud = $finiquito->solicitudInterna;
        $colaborador = $solicitud->colaboradorDeBaja();
        abort_unless($colaborador !== null, 422, 'Esta solicitud no tiene un colaborador objetivo con expediente vinculado.');
        abort_unless($colaborador->fecha_ingreso !== null, 422, 'El colaborador no tiene fecha de ingreso registrada.');

        $automaticos = $this->calcularAutomaticos($solicitud, $colaborador, $sueldoMensual, $sueldoPendiente);

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

            return $this->recalcularTotales($finiquito->refresh());
        });
    }

    /**
     * @param  array{bonos_extra?: float, descuentos?: float, adeudos?: float, otros_conceptos?: array<string, float>, comentarios_ajuste?: string|null}  $datos
     */
    public function actualizarAjustes(FiniquitoCalculo $finiquito, User $actor, array $datos): FiniquitoCalculo
    {
        $this->asegurarNoFirmado($finiquito);

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
                // números distintos a los que se revisaron. (asegurarNoFirmado()
                // ya garantizó arriba que nunca llegamos aquí en estado firmado.)
                'estado' => EstadoFiniquito::Borrador->value,
                'revisado_por_id' => null,
            ]);

            $this->registrarHistorial($finiquito->solicitudInterna, $actor, 'finiquito_ajustado', $datos['comentarios_ajuste'] ?? null);

            return $this->recalcularTotales($finiquito->refresh());
        });
    }

    /**
     * Desglose modular del finiquito: conceptos automáticos (derivados del
     * cálculo y los ajustes existentes) + conceptos capturados por RH. Cada
     * renglón: concepto, tipo (percepción/deducción), cantidad, importe,
     * observaciones y origen.
     *
     * @return list<array{id: int|null, concepto: string, tipo: string, cantidad: float, importe: float, observaciones: string|null, origen: string}>
     */
    public function desglose(FiniquitoCalculo $finiquito): array
    {
        $renglones = [];
        $agregar = function (string $concepto, TipoConceptoNomina $tipo, float $importe, float $cantidad = 1, ?string $observaciones = null) use (&$renglones): void {
            if (abs(round($importe, 2)) < 0.005) {
                return;
            }

            $renglones[] = ['id' => null, 'concepto' => $concepto, 'tipo' => $tipo->value, 'cantidad' => $cantidad, 'importe' => round($importe, 2), 'observaciones' => $observaciones, 'origen' => 'automatico'];
        };

        $agregar('Sueldo pendiente', TipoConceptoNomina::Percepcion, (float) $finiquito->sueldo_pendiente);
        $agregar('Vacaciones pendientes (prima vacacional)', TipoConceptoNomina::Percepcion, (float) $finiquito->prima_vacacional, (float) $finiquito->vacaciones_pendientes, 'Días pendientes como cantidad');
        $agregar('Aguinaldo proporcional', TipoConceptoNomina::Percepcion, (float) $finiquito->aguinaldo_proporcional);
        $agregar('Indemnización', TipoConceptoNomina::Percepcion, (float) $finiquito->indemnizacion);
        $agregar('Bonos extra', TipoConceptoNomina::Percepcion, (float) $finiquito->bonos_extra);

        foreach ($finiquito->otros_conceptos ?? [] as $clave => $valor) {
            $valor = (float) $valor;
            $agregar((string) $clave, $valor >= 0 ? TipoConceptoNomina::Percepcion : TipoConceptoNomina::Deduccion, abs($valor));
        }

        $agregar('Descuentos', TipoConceptoNomina::Deduccion, (float) $finiquito->descuentos);
        $agregar('Adeudos', TipoConceptoNomina::Deduccion, (float) $finiquito->adeudos);

        foreach ($finiquito->conceptos()->get() as $concepto) {
            $renglones[] = [
                'id' => $concepto->id,
                'concepto' => $concepto->concepto,
                'tipo' => $concepto->tipo->value,
                'cantidad' => (float) $concepto->cantidad,
                'importe' => round((float) $concepto->importe, 2),
                'observaciones' => $concepto->observaciones,
                'origen' => 'manual',
            ];
        }

        return $renglones;
    }

    /**
     * Total percepciones, total deducciones y neto a partir del desglose.
     * total_ajustado se mantiene igual al neto por compatibilidad con el
     * flujo existente (web de finiquitos).
     */
    public function recalcularTotales(FiniquitoCalculo $finiquito): FiniquitoCalculo
    {
        $percepciones = 0.0;
        $deducciones = 0.0;

        foreach ($this->desglose($finiquito) as $renglon) {
            if ($renglon['tipo'] === TipoConceptoNomina::Percepcion->value) {
                $percepciones += $renglon['importe'];
            } else {
                $deducciones += $renglon['importe'];
            }
        }

        $finiquito->update([
            'total_percepciones' => round($percepciones, 2),
            'total_deducciones' => round($deducciones, 2),
            'neto' => round($percepciones - $deducciones, 2),
            'total_ajustado' => round($percepciones - $deducciones, 2),
        ]);

        return $finiquito->refresh();
    }

    /**
     * @param  array<string, mixed>  $datos  tipo, concepto, cantidad?, importe, observaciones? (validado por FiniquitoCierreRequest).
     */
    public function agregarConcepto(FiniquitoCalculo $finiquito, array $datos, User $actor): FiniquitoConcepto
    {
        $this->asegurarNoFirmado($finiquito);

        return DB::transaction(function () use ($finiquito, $datos, $actor): FiniquitoConcepto {
            $concepto = $finiquito->conceptos()->create([
                'tipo' => TipoConceptoNomina::from((string) $datos['tipo']),
                'concepto' => (string) $datos['concepto'],
                'cantidad' => $datos['cantidad'] ?? 1,
                'importe' => round((float) $datos['importe'], 2),
                'observaciones' => $datos['observaciones'] ?? null,
                'capturado_por' => $actor->id,
            ]);

            // Un cambio de montos obliga a revisar de nuevo.
            $finiquito->update(['estado' => EstadoFiniquito::Borrador->value, 'revisado_por_id' => null]);
            $this->recalcularTotales($finiquito);
            $this->registrarHistorial($finiquito->solicitudInterna, $actor, 'finiquito_concepto', sprintf('%s: %s', (string) $datos['tipo'], (string) $datos['concepto']));

            return $concepto;
        });
    }

    /**
     * @param  array<string, mixed>  $datos  tipo?, concepto?, cantidad?, importe?, observaciones? (validado por FiniquitoCierreRequest).
     */
    public function actualizarConcepto(FiniquitoConcepto $concepto, array $datos, User $actor): FiniquitoConcepto
    {
        $finiquito = $concepto->finiquito;
        $this->asegurarNoFirmado($finiquito);

        DB::transaction(function () use ($concepto, $finiquito, $datos, $actor): void {
            $concepto->update(array_filter([
                'tipo' => isset($datos['tipo']) ? TipoConceptoNomina::from((string) $datos['tipo']) : null,
                'concepto' => $datos['concepto'] ?? null,
                'cantidad' => $datos['cantidad'] ?? null,
                'importe' => isset($datos['importe']) ? round((float) $datos['importe'], 2) : null,
                'observaciones' => $datos['observaciones'] ?? null,
            ], fn ($v) => $v !== null));

            $finiquito->update(['estado' => EstadoFiniquito::Borrador->value, 'revisado_por_id' => null]);
            $this->recalcularTotales($finiquito);
            $this->registrarHistorial($finiquito->solicitudInterna, $actor, 'finiquito_concepto', sprintf('Concepto actualizado: %s', $concepto->concepto));
        });

        return $concepto->refresh();
    }

    public function eliminarConcepto(FiniquitoConcepto $concepto, User $actor): void
    {
        $finiquito = $concepto->finiquito;
        $this->asegurarNoFirmado($finiquito);

        DB::transaction(function () use ($concepto, $finiquito, $actor): void {
            $nombre = $concepto->concepto;
            $concepto->delete();
            $finiquito->update(['estado' => EstadoFiniquito::Borrador->value, 'revisado_por_id' => null]);
            $this->recalcularTotales($finiquito);
            $this->registrarHistorial($finiquito->solicitudInterna, $actor, 'finiquito_concepto', sprintf('Concepto eliminado: %s', $nombre));
        });
    }

    /**
     * Confirmación administrativa del pago del finiquito (el sistema no
     * dispersa pagos: RH registra que el pago se realizó y su referencia).
     */
    public function confirmarPago(FiniquitoCalculo $finiquito, User $actor, string $referencia): FiniquitoCalculo
    {
        return DB::transaction(function () use ($finiquito, $actor, $referencia): FiniquitoCalculo {
            $finiquito = FiniquitoCalculo::query()->lockForUpdate()->findOrFail($finiquito->id);

            if ($finiquito->pagado_en !== null) {
                throw ValidationException::withMessages(['finiquito' => 'El pago de este finiquito ya fue confirmado.']);
            }

            if ($finiquito->estado !== EstadoFiniquito::Firmado) {
                throw ValidationException::withMessages(['finiquito' => 'El finiquito debe estar firmado antes de confirmar el pago.']);
            }

            $finiquito->update([
                'pagado_en' => now(),
                'pago_confirmado_por' => $actor->id,
                'referencia_pago' => $referencia,
                'estado' => EstadoFiniquito::Pagado->value,
            ]);

            $this->registrarHistorial($finiquito->solicitudInterna, $actor, 'finiquito_pagado', sprintf('Referencia: %s', $referencia));

            return $finiquito->refresh();
        });
    }

    public function aprobarCalculo(FiniquitoCalculo $finiquito, User $actor): FiniquitoCalculo
    {
        $this->asegurarNoFirmado($finiquito);

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
        $this->asegurarNoFirmado($finiquito);

        $nombreInterno = $this->storage->nombreInterno($archivo->getClientOriginalName());
        $ruta = "solicitudes/{$finiquito->solicitud_interna_id}/finiquito/firmado-{$nombreInterno}";

        try {
            $this->storage->guardar($archivo, $ruta);

            if (! $this->storage->disco()->exists($ruta)) {
                throw new RuntimeException('El almacenamiento no confirmó haber guardado el archivo.');
            }
        } catch (Throwable $e) {
            Log::error('FiniquitoService: fallo al guardar el documento firmado en el almacenamiento.', [
                'finiquito_id' => $finiquito->id,
                'message' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'archivo' => 'No se pudo guardar el documento firmado en el almacenamiento (NAS/disco no disponible). Intenta de nuevo; si el problema continúa, avisa a sistemas.',
            ]);
        }

        $finiquito->update([
            'documento_firmado_path' => $ruta,
            'estado' => EstadoFiniquito::Firmado->value,
        ]);

        // El original firmado también queda en el expediente del colaborador
        // (categoría Baja y finiquito) como documento aprobado.
        try {
            $finiquito->loadMissing('colaborador');
            $tipo = DocumentType::query()->firstOrCreate(
                ['clave' => 'finiquito_firmado'],
                ['nombre' => 'Finiquito firmado', 'categoria' => CategoriaDocumento::BajaFiniquito->value, 'requerido' => false, 'aplica_alta' => false, 'activo' => true],
            );
            $this->expediente->subirVersion($finiquito->colaborador, $tipo, $archivo, $actor->id, EstadoDocumento::Aprobado, 'generado');
        } catch (Throwable $e) {
            Log::warning('FiniquitoService: el finiquito firmado no se pudo copiar al expediente.', ['finiquito_id' => $finiquito->id, 'error' => $e->getMessage()]);
        }

        $this->registrarHistorial($finiquito->solicitudInterna, $actor, 'finiquito_firmado_subido');

        return $finiquito->refresh();
    }

    /**
     * Genera y persiste el PDF del finiquito: usa el overlay sobre el
     * formato oficial de finiquito si hay uno activo y configurado (ver
     * docs/FORMATOS_OFICIALES.md), o el formato interno DomPDF como
     * respaldo cuando no lo hay. Congela un snapshot de los datos usados.
     */
    public function generarPdf(FiniquitoCalculo $finiquito, ?User $actor = null): FiniquitoCalculo
    {
        $this->asegurarNoFirmado($finiquito);

        $finiquito->loadMissing(['colaborador', 'calculadoPor', 'revisadoPor', 'solicitudInterna']);
        $actor ??= $finiquito->calculadoPor;
        $finiquito = $this->recalcularTotales($finiquito);
        $desglose = $this->desglose($finiquito);
        $variables = $this->datosOverlay($finiquito);

        // 1) Plantilla "finiquito" cargada por Jurídico en el motor documental.
        // 2) Formato oficial PDF de finiquito (overlay) si está configurado.
        // 3) Respaldo interno DomPDF (solo desglose de montos, sin cláusulas).
        // En los tres casos el PDF queda en el expediente (carpeta
        // BajaFiniquito) como documento laboral con snapshot y flujo de firma.
        if ($this->motor->tienePlantillaActiva('finiquito')) {
            $documento = $this->motor->generar($finiquito->colaborador, 'finiquito', $actor, $variables, $finiquito, 'Finiquito');
        } else {
            $formatoOficial = OfficialFormat::query()
                ->where('tipo', TipoFormatoOficial::Finiquito->value)
                ->where('is_active', true)
                ->first();

            $contenido = $formatoOficial !== null && $formatoOficial->tieneConfiguracion()
                ? $this->formatosOficiales->renderizarPara($formatoOficial, $this->formatosOficiales->contextoDesde($finiquito->colaborador, $finiquito, $actor), $variables)
                : Pdf::loadView('pdf.finiquito', ['finiquito' => $finiquito, 'desglose' => $desglose])->setPaper('letter', 'portrait')->output();

            $documento = $this->motor->registrarPdf($finiquito->colaborador, $contenido, 'Finiquito', $actor, [
                'clave' => 'finiquito',
                'categoria' => CategoriaDocumento::BajaFiniquito,
                'payload' => $this->resolver->resolver($finiquito->colaborador, $variables),
                'documentable' => $finiquito,
                'requiere_impresion' => true,
                'requiere_firma_fisica' => true,
            ]);
        }

        $finiquito->update([
            'documento_generado_path' => $documento->path,
            'generated_document_id' => $documento->id,
            'snapshot' => [
                ...$finiquito->only([
                    'sueldo_mensual', 'sueldo_diario', 'antiguedad_anios', 'antiguedad_meses',
                    'dias_trabajados_periodo', 'vacaciones_pendientes', 'prima_vacacional',
                    'aguinaldo_proporcional', 'sueldo_pendiente', 'indemnizacion', 'bonos_extra',
                    'descuentos', 'adeudos', 'otros_conceptos', 'total_calculado', 'total_ajustado',
                    'comentarios_ajuste', 'total_percepciones', 'total_deducciones', 'neto',
                ]),
                'conceptos' => $desglose,
                'generated_document_id' => $documento->id,
            ],
        ]);

        return $finiquito->refresh();
    }

    /**
     * true si el próximo generarPdf() usará el formato oficial de finiquito
     * en vez del respaldo DomPDF — para que la UI avise cuál va a usar.
     */
    public function tieneFormatoOficialConfigurado(): bool
    {
        return OfficialFormat::query()
            ->where('tipo', TipoFormatoOficial::Finiquito->value)
            ->where('is_active', true)
            ->get()
            ->contains(fn (OfficialFormat $formato) => $formato->tieneConfiguracion());
    }

    /**
     * @return array<string, string>
     */
    private function datosOverlay(FiniquitoCalculo $finiquito): array
    {
        return [
            'finiquito_fecha_baja' => $finiquito->fecha_baja->format('d/m/Y'),
            'finiquito_antiguedad' => "{$finiquito->antiguedad_anios} año(s), {$finiquito->antiguedad_meses} mes(es)",
            'finiquito_sueldo_diario' => $this->moneda($finiquito->sueldo_diario),
            'finiquito_sueldo_mensual' => $this->moneda($finiquito->sueldo_mensual),
            'finiquito_sueldo_pendiente' => $this->moneda($finiquito->sueldo_pendiente),
            'finiquito_vacaciones_pendientes' => "{$finiquito->vacaciones_pendientes} días",
            'finiquito_prima_vacacional' => $this->moneda($finiquito->prima_vacacional),
            'finiquito_aguinaldo_proporcional' => $this->moneda($finiquito->aguinaldo_proporcional),
            'finiquito_indemnizacion' => $this->moneda($finiquito->indemnizacion),
            'finiquito_bonos_extra' => $this->moneda($finiquito->bonos_extra),
            'finiquito_descuentos' => $this->moneda($finiquito->descuentos),
            'finiquito_adeudos' => $this->moneda($finiquito->adeudos),
            'finiquito_otros_conceptos' => $this->moneda($this->sumaOtrosConceptos($finiquito->otros_conceptos)),
            'finiquito_total_ajustado' => $this->moneda($finiquito->total_ajustado),
            'finiquito_fecha_generacion' => now()->format('d/m/Y'),
        ];
    }

    private function moneda(string|float $valor): string
    {
        return '$'.number_format((float) $valor, 2);
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
     * @param  Colaborador  $colaborador  Fuente de verdad de persona/empleo (fecha_ingreso y saldo
     *                                    de vacaciones, ver VacacionesService::saldoColaborador()).
     *                                    El sueldo se recibe aparte porque RH puede capturarlo/editarlo
     *                                    antes de aprobarse (ver docblock de la clase). No requiere
     *                                    que el colaborador tenga cuenta de acceso (User).
     * @return array<string, mixed>
     */
    private function calcularAutomaticos(SolicitudInterna $solicitud, Colaborador $colaborador, float $sueldoMensual, float $sueldoPendiente = 0): array
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
        $vacacionesPendientes = $this->vacaciones->saldoColaborador($colaborador)['dias_disponibles'];

        $primaVacacionalPorcentaje = (int) config('finiquitos.prima_vacacional_porcentaje');
        $primaVacacional = round($vacacionesPendientes * $sueldoDiario * ($primaVacacionalPorcentaje / 100), 2);

        $diasAguinaldo = (int) config('finiquitos.dias_aguinaldo');
        $aguinaldoProporcional = round($diasAguinaldo * $diasTrabajadosPeriodo / 365 * $sueldoDiario, 2);

        $indemnizacion = $this->calcularIndemnizacion($solicitud, $antiguedadAnios, $sueldoDiario);

        $totalCalculado = round($sueldoPendiente + $primaVacacional + $aguinaldoProporcional + $indemnizacion, 2);

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
            'sueldo_pendiente' => $sueldoPendiente,
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
    public static function sumaOtrosConceptos(?array $otrosConceptos): float
    {
        if ($otrosConceptos === null) {
            return 0.0;
        }

        return array_sum(array_map(static fn ($valor): float => (float) $valor, $otrosConceptos));
    }

    /**
     * Invariante de negocio: un finiquito firmado no se recalcula, no se
     * ajusta, no se vuelve a revisar y no se regenera el PDF por encima del
     * ya entregado — el documento firmado por el colaborador es definitivo.
     * Falta por implementar (fuera de alcance de este cambio): un flujo
     * explícito de "corrección/anulación" que cree una versión nueva en vez
     * de mutar la firmada, para cuando RH detecte un error después de
     * firmado.
     */
    private function asegurarNoFirmado(FiniquitoCalculo $finiquito): void
    {
        if ($finiquito->estado === EstadoFiniquito::Firmado || $finiquito->estado === EstadoFiniquito::Pagado) {
            throw ValidationException::withMessages([
                'estado' => 'Este finiquito ya está firmado y no puede modificarse. Si hay un error, genera una corrección/anulación explícita.',
            ]);
        }
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
