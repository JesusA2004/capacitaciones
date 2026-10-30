<?php

namespace App\Services\Finiquitos;

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoFiniquito;
use App\Enums\FamiliaAdministrativa;
use App\Enums\TipoConceptoNomina;
use App\Enums\TipoFiniquito;
use App\Enums\TipoFormatoOficial;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\FiniquitoCalculo;
use App\Models\FiniquitoConcepto;
use App\Models\OfficialFormat;
use App\Models\SolicitudInterna;
use App\Models\SolicitudInternaHistorial;
use App\Models\User;
use App\Services\Compensacion\CompensacionService;
use App\Services\DocumentosAdministrativos\DatosDocumentoAdministrativo;
use App\Services\DocumentosAdministrativos\DocumentoAdministrativoService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Formatos\GeneradorFormatoService;
use App\Services\Plantillas\PlaceholderResolver;
use App\Services\Solicitudes\SolicitudDocumentoStorageService;
use App\Services\Vacaciones\VacacionesService;
use App\Support\ExtensionArchivo;
use App\Support\FechaMexico;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
        private readonly DocumentoAdministrativoService $documentosAdministrativos,
        private readonly DatosDocumentoAdministrativo $datosDocumento,
        private readonly CompensacionService $compensacion,
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
                'tipo_documento' => $this->tipoPropuesto($solicitud),
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
                // Recalcular vuelve a proponer el tipo según la causal vigente.
                'tipo_documento' => $this->tipoPropuesto($solicitud),
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
     * Tipo que se PROPONE según el catálogo: la causal marcada como «Implica
     * indemnización» genera liquidación. Es clasificación con revisión de
     * RH/Jurídico (cambiarTipo() y los ajustes por concepto), no resolución legal.
     */
    public function tipoPropuesto(SolicitudInterna $solicitud): TipoFiniquito
    {
        return $solicitud->causalRotacion?->implica_indemnizacion === true ? TipoFiniquito::Liquidacion : TipoFiniquito::Finiquito;
    }

    /**
     * Tipo de un finiquito. Los calculados antes de existir la marca no se
     * reescriben: se derivan de su causal y de si llevan indemnización.
     */
    public function tipoDe(FiniquitoCalculo $finiquito): TipoFiniquito
    {
        if ($finiquito->tipo_documento !== null) {
            return $finiquito->tipo_documento;
        }

        return (float) $finiquito->indemnizacion > 0 || $finiquito->solicitudInterna->causalRotacion?->implica_indemnizacion === true
            ? TipoFiniquito::Liquidacion
            : TipoFiniquito::Finiquito;
    }

    /**
     * RH/Jurídico corrige el tipo propuesto para el supuesto concreto, con
     * motivo en la bitácora. No mueve montos: la indemnización se ajusta por
     * concepto. Un cambio obliga a revisar de nuevo.
     */
    public function cambiarTipo(FiniquitoCalculo $finiquito, TipoFiniquito $tipo, string $motivo, User $actor): FiniquitoCalculo
    {
        $this->asegurarNoFirmado($finiquito);
        $anterior = $this->tipoDe($finiquito);

        return DB::transaction(function () use ($finiquito, $tipo, $motivo, $actor, $anterior): FiniquitoCalculo {
            $finiquito->update(['tipo_documento' => $tipo, 'estado' => EstadoFiniquito::Borrador->value, 'revisado_por_id' => null]);
            $this->registrarHistorial($finiquito->solicitudInterna, $actor, 'finiquito_tipo', sprintf('%s → %s. %s', $anterior->etiqueta(), $tipo->etiqueta(), trim($motivo)));

            return $finiquito->refresh();
        });
    }

    /**
     * Bitácora de ajustes con importe anterior y actualizado, más reciente primero.
     *
     * @return list<array{concepto: string, anterior: float, actualizado: float, motivo: string, usuario: string|null, fecha: string|null}>
     */
    public function historialAjustes(FiniquitoCalculo $finiquito): array
    {
        $finiquito->loadMissing(['ajustes.usuario:id,name,apellidos']);
        $previo = [];
        $historial = [];

        foreach ($finiquito->ajustes->sortBy('id') as $ajuste) {
            $anterior = $previo[$ajuste->concepto_clave] ?? (float) $ajuste->valor_calculado;
            $historial[] = [
                'concepto' => $ajuste->concepto,
                'anterior' => $anterior,
                'actualizado' => (float) $ajuste->valor_final,
                'motivo' => $ajuste->motivo,
                'usuario' => $ajuste->usuario !== null ? trim($ajuste->usuario->name.' '.$ajuste->usuario->apellidos) : null,
                'fecha' => $ajuste->created_at?->toDateTimeString(),
            ];
            $previo[$ajuste->concepto_clave] = (float) $ajuste->valor_final;
        }

        return array_reverse($historial);
    }

    /**
     * Conceptos AUTOMÁTICOS del finiquito con su clave del formato oficial
     * (docs/formatosRH/Formato_Finiquito.docx). 001–004 y 101–102 siempre
     * aparecen (aunque valgan $0.00), igual que en el formato impreso.
     * `campo`: columna de finiquito_calculos con el valor calculado.
     *
     * @return array<string, array{clave: string, concepto: string, tipo: TipoConceptoNomina, campo: string, siempre: bool}>
     */
    public function conceptosAutomaticos(): array
    {
        return [
            'sueldo_pendiente' => ['clave' => '001', 'concepto' => 'Sueldo pendiente de pago', 'tipo' => TipoConceptoNomina::Percepcion, 'campo' => 'sueldo_pendiente', 'siempre' => true],
            'aguinaldo_proporcional' => ['clave' => '002', 'concepto' => 'Aguinaldo proporcional', 'tipo' => TipoConceptoNomina::Percepcion, 'campo' => 'aguinaldo_proporcional', 'siempre' => true],
            'vacaciones_proporcionales' => ['clave' => '003', 'concepto' => 'Vacaciones proporcionales', 'tipo' => TipoConceptoNomina::Percepcion, 'campo' => 'vacaciones_pendientes_pago', 'siempre' => true],
            'prima_vacacional' => ['clave' => '004', 'concepto' => sprintf('Prima vacacional (%d%%)', (int) config('finiquitos.prima_vacacional_porcentaje')), 'tipo' => TipoConceptoNomina::Percepcion, 'campo' => 'prima_vacacional', 'siempre' => true],
            'indemnizacion' => ['clave' => '005', 'concepto' => 'Indemnización', 'tipo' => TipoConceptoNomina::Percepcion, 'campo' => 'indemnizacion', 'siempre' => false],
            'bonos_extra' => ['clave' => '006', 'concepto' => 'Bonos extra', 'tipo' => TipoConceptoNomina::Percepcion, 'campo' => 'bonos_extra', 'siempre' => false],
            'isr_retenido' => ['clave' => '101', 'concepto' => 'ISR retenido', 'tipo' => TipoConceptoNomina::Deduccion, 'campo' => 'isr_retenido', 'siempre' => true],
            'otras_deducciones' => ['clave' => '102', 'concepto' => 'Otras deducciones', 'tipo' => TipoConceptoNomina::Deduccion, 'campo' => 'descuentos', 'siempre' => true],
            'adeudos' => ['clave' => '103', 'concepto' => 'Adeudos', 'tipo' => TipoConceptoNomina::Deduccion, 'campo' => 'adeudos', 'siempre' => false],
        ];
    }

    /**
     * Desglose del finiquito en el orden del formato oficial: conceptos
     * automáticos (con su valor calculado y, si RH lo ajustó, el valor
     * final autorizado + motivo + quién + cuándo) y después los conceptos
     * capturados a mano por RH. Vacaciones proporcionales (pago de los días)
     * y prima vacacional (el % adicional) son SIEMPRE filas distintas.
     *
     * @return list<array{id: int|null, clave: string, concepto_clave: string|null, concepto: string, tipo: string, cantidad: float, dias: float|null, importe: float, valor_calculado: float|null, ajustado: bool, ajuste: array{motivo: string, usuario: string|null, fecha: string|null, valor_calculado: float, valor_final: float}|null, observaciones: string|null, origen: string, editable: bool}>
     */
    public function desglose(FiniquitoCalculo $finiquito): array
    {
        $finiquito->loadMissing(['ajustes.usuario:id,name,apellidos']);
        $vigentes = $finiquito->ajustes->keyBy('concepto_clave');
        $renglones = [];
        $diasVacaciones = $this->diasVacaciones($finiquito);
        $dias = [
            'vacaciones_proporcionales' => $diasVacaciones,
            'prima_vacacional' => $diasVacaciones,
            'aguinaldo_proporcional' => $this->diasAguinaldo($finiquito),
        ];
        $proporcionales = (float) $finiquito->vacaciones_proporcionales;
        $observacionVacaciones = $proporcionales > 0
            ? sprintf('%d día(s) pendientes + %s proporcionales del año en curso, a salario diario', $finiquito->vacaciones_pendientes, number_format($proporcionales, 2))
            : 'Días pendientes pagados a salario diario';
        $baseAguinaldo = $finiquito->dias_trabajados_anio;
        $observacionAguinaldo = $baseAguinaldo !== null
            ? sprintf('%d días de aguinaldo × %d días trabajados en el año / 365', (int) config('finiquitos.dias_aguinaldo'), $baseAguinaldo)
            : null;

        foreach ($this->conceptosAutomaticos() as $clave => $def) {
            $calculado = round((float) $finiquito->getAttribute($def['campo']), 2);
            $ajuste = $vigentes->get($clave);
            $importe = $ajuste !== null ? round((float) $ajuste->valor_final, 2) : $calculado;

            if (! $def['siempre'] && abs($importe) < 0.005 && $ajuste === null) {
                continue;
            }

            $renglones[] = [
                'id' => null,
                'clave' => $def['clave'],
                'concepto_clave' => $clave,
                'concepto' => $def['concepto'],
                'tipo' => $def['tipo']->value,
                'cantidad' => $dias[$clave] ?? 1.0,
                'dias' => isset($dias[$clave]) && $dias[$clave] > 0 ? $dias[$clave] : null,
                'importe' => $importe,
                'valor_calculado' => $calculado,
                'ajustado' => $ajuste !== null,
                'ajuste' => $ajuste !== null ? [
                    'motivo' => $ajuste->motivo,
                    'usuario' => $ajuste->usuario !== null ? trim($ajuste->usuario->name.' '.$ajuste->usuario->apellidos) : null,
                    'fecha' => $ajuste->created_at?->toIso8601String(),
                    'valor_calculado' => (float) $ajuste->valor_calculado,
                    'valor_final' => (float) $ajuste->valor_final,
                ] : null,
                'observaciones' => match ($clave) {
                    'vacaciones_proporcionales' => $observacionVacaciones,
                    'aguinaldo_proporcional' => $observacionAguinaldo,
                    'prima_vacacional' => sprintf('%d%% adicional sobre los días de vacaciones', (int) config('finiquitos.prima_vacacional_porcentaje')),
                    default => null,
                },
                'origen' => 'automatico',
                'editable' => true,
            ];
        }

        $siguiente = ['percepcion' => 7, 'deduccion' => 104];

        foreach ($finiquito->otros_conceptos ?? [] as $nombre => $valor) {
            $valor = (float) $valor;

            if (abs(round($valor, 2)) < 0.005) {
                continue;
            }

            $tipo = $valor >= 0 ? TipoConceptoNomina::Percepcion : TipoConceptoNomina::Deduccion;
            $renglones[] = ['id' => null, 'clave' => sprintf('%03d', $siguiente[$tipo->value]++), 'concepto_clave' => null, 'concepto' => (string) $nombre, 'tipo' => $tipo->value, 'cantidad' => 1.0, 'dias' => null, 'importe' => round(abs($valor), 2), 'valor_calculado' => null, 'ajustado' => false, 'ajuste' => null, 'observaciones' => null, 'origen' => 'automatico', 'editable' => false];
        }

        foreach ($finiquito->conceptos()->get() as $concepto) {
            $renglones[] = [
                'id' => $concepto->id,
                'clave' => $concepto->clave ?: sprintf('%03d', $siguiente[$concepto->tipo->value]++),
                'concepto_clave' => null,
                'concepto' => $concepto->concepto,
                'tipo' => $concepto->tipo->value,
                'cantidad' => (float) $concepto->cantidad,
                'dias' => (float) $concepto->cantidad !== 1.0 ? (float) $concepto->cantidad : null,
                'importe' => round((float) $concepto->importe, 2),
                'valor_calculado' => null,
                'ajustado' => false,
                'ajuste' => null,
                'observaciones' => $concepto->observaciones,
                'origen' => 'manual',
                'editable' => true,
            ];
        }

        // Percepciones primero, luego deducciones; cada grupo por clave.
        usort($renglones, fn (array $a, array $b): int => [$a['tipo'] === 'deduccion', $a['clave']] <=> [$b['tipo'] === 'deduccion', $b['clave']]);

        return $renglones;
    }

    /**
     * Ajuste autorizado a un concepto automático: el sistema conserva el
     * valor calculado y registra el valor final, la diferencia, el motivo,
     * quién y cuándo. El total lo recalcula SIEMPRE el servidor
     * (recalcularTotales()), nunca se toma del frontend. Ajustar al mismo
     * valor calculado equivale a quitar el ajuste (queda en el historial).
     */
    public function ajustarConceptoAutomatico(FiniquitoCalculo $finiquito, string $conceptoClave, float $valorFinal, string $motivo, User $actor): FiniquitoCalculo
    {
        $this->asegurarNoFirmado($finiquito);
        $definicion = $this->conceptosAutomaticos()[$conceptoClave] ?? null;

        if ($definicion === null) {
            throw ValidationException::withMessages(['concepto_clave' => 'Ese concepto no se puede ajustar.']);
        }

        if ($valorFinal < 0) {
            throw ValidationException::withMessages(['importe' => 'El importe no puede ser negativo.']);
        }

        if (trim($motivo) === '') {
            throw ValidationException::withMessages(['motivo' => 'Indica el motivo del ajuste.']);
        }

        return DB::transaction(function () use ($finiquito, $conceptoClave, $definicion, $valorFinal, $motivo, $actor): FiniquitoCalculo {
            $bloqueado = FiniquitoCalculo::query()->lockForUpdate()->findOrFail($finiquito->id);
            $calculado = round((float) $bloqueado->getAttribute($definicion['campo']), 2);
            $final = round($valorFinal, 2);

            $bloqueado->ajustes()->create([
                'concepto_clave' => $conceptoClave,
                'concepto' => $definicion['concepto'],
                'valor_calculado' => $calculado,
                'valor_final' => $final,
                'ajuste' => round($final - $calculado, 2),
                'motivo' => mb_substr(trim($motivo), 0, 500),
                'user_id' => $actor->id,
            ]);

            // Un cambio de montos obliga a revisar de nuevo.
            $bloqueado->update(['estado' => EstadoFiniquito::Borrador->value, 'revisado_por_id' => null]);
            $bloqueado->unsetRelation('ajustes');
            $this->registrarHistorial($bloqueado->solicitudInterna, $actor, 'finiquito_ajuste', sprintf('%s %s: $%s → $%s. %s', $definicion['clave'], $definicion['concepto'], number_format($calculado, 2), number_format($final, 2), trim($motivo)));

            return $this->recalcularTotales($bloqueado);
        });
    }

    /**
     * Vista previa REAL del PDF del finiquito (mismo HTML y diseño que el
     * documento definitivo) sin guardar nada: RH revisa antes de generar.
     */
    public function vistaPreviaPdf(FiniquitoCalculo $finiquito): string
    {
        $finiquito->loadMissing(['colaborador', 'solicitudInterna']);

        return $this->documentosAdministrativos->renderizarVigente(FamiliaAdministrativa::Finiquito, $this->datosDocumento->finiquito($finiquito, $this->desglose($finiquito)));
    }

    /**
     * Finiquito PROPUESTO de una baja que todavía no se registra, armado EN
     * MEMORIA con las mismas fórmulas que calcular() (la vista previa del
     * formulario de baja, docs/BAJAS_Y_CAUSALES.md). No guarda nada: ni
     * solicitud, ni finiquito, ni historial. $solicitud es la solicitud de
     * baja sin guardar (tipo_baja, fecha_efectiva, causal).
     */
    public function propuestaEnMemoria(SolicitudInterna $solicitud, Colaborador $colaborador, float $sueldoMensual, float $sueldoPendiente = 0): FiniquitoCalculo
    {
        if ($colaborador->fecha_ingreso === null) {
            throw ValidationException::withMessages(['colaborador_id' => 'El colaborador no tiene fecha de ingreso registrada: no se puede calcular el finiquito.']);
        }

        $automaticos = $this->calcularAutomaticos($solicitud, $colaborador, $sueldoMensual, $sueldoPendiente);
        $finiquito = new FiniquitoCalculo;
        $finiquito->forceFill([
            'colaborador_id' => $colaborador->id,
            'fecha_calculo' => now(),
            ...$automaticos,
            'bonos_extra' => 0,
            'descuentos' => 0,
            'adeudos' => 0,
            'total_ajustado' => $automaticos['total_calculado'],
            'estado' => EstadoFiniquito::Borrador->value,
            'tipo_documento' => $this->tipoPropuesto($solicitud),
        ]);
        $finiquito->setRelation('colaborador', $colaborador);
        $finiquito->setRelation('solicitudInterna', $solicitud);
        $finiquito->setRelation('ajustes', new EloquentCollection);

        $percepciones = 0.0;
        $deducciones = 0.0;

        foreach ($this->desglose($finiquito) as $renglon) {
            if ($renglon['tipo'] === TipoConceptoNomina::Percepcion->value) {
                $percepciones += $renglon['importe'];
            } else {
                $deducciones += $renglon['importe'];
            }
        }

        $finiquito->forceFill([
            'total_percepciones' => round($percepciones, 2),
            'total_deducciones' => round($deducciones, 2),
            'neto' => round($percepciones - $deducciones, 2),
            'total_ajustado' => round($percepciones - $deducciones, 2),
        ]);

        return $finiquito;
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
    public function confirmarPago(FiniquitoCalculo $finiquito, User $actor, string $referencia, ?Carbon $fecha = null): FiniquitoCalculo
    {
        return DB::transaction(function () use ($finiquito, $actor, $referencia, $fecha): FiniquitoCalculo {
            $finiquito = FiniquitoCalculo::query()->lockForUpdate()->findOrFail($finiquito->id);

            if ($finiquito->pagado_en !== null) {
                throw ValidationException::withMessages(['finiquito' => 'El pago de este finiquito ya fue confirmado.']);
            }

            if ($finiquito->estado !== EstadoFiniquito::Firmado) {
                throw ValidationException::withMessages(['finiquito' => 'El finiquito debe estar firmado antes de confirmar el pago.']);
            }

            $finiquito->update([
                'pagado_en' => $fecha ?? now(),
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

    /**
     * Firma física sin escaneo (regla vigente): el finiquito se imprime y se
     * firma en PAPEL; RH no vuelve a escanearlo ni subirlo, solo deja
     * constancia de quién lo marcó como firmado y cuándo. Exige que el
     * cálculo ya esté Aprobado (revisado y aprobado con la baja); deja el
     * finiquito en estado Firmado SIN archivo y, a partir de ahí, se puede
     * confirmar el pago. Mantiene la inmutabilidad: una vez firmado no se
     * recalcula ni ajusta (asegurarNoFirmado()).
     *
     * El flujo con escaneo (subirFirmado()/documento_firmado_path) se conserva
     * como LEGACY de solo lectura para los finiquitos antiguos.
     */
    public function marcarFirmadoFisicamente(FiniquitoCalculo $finiquito, User $actor, ?string $observacion = null): FiniquitoCalculo
    {
        $this->asegurarNoFirmado($finiquito);

        if ($finiquito->estado !== EstadoFiniquito::Aprobado) {
            throw ValidationException::withMessages([
                'estado' => 'El finiquito debe estar aprobado antes de marcarlo como firmado físicamente.',
            ]);
        }

        $observacion = is_string($observacion) && trim($observacion) !== '' ? mb_substr(trim($observacion), 0, 500) : null;

        return DB::transaction(function () use ($finiquito, $actor, $observacion): FiniquitoCalculo {
            $finiquito->update([
                'firmado_fisicamente_en' => now(),
                'firmado_fisicamente_por' => $actor->id,
                'estado' => EstadoFiniquito::Firmado->value,
            ]);

            // Auditoría del evento (entidad/id/actor/fecha/colaborador/observaciones)
            // sobre el historial de la solicitud de baja, misma bitácora que el
            // resto del ciclo del finiquito.
            $this->registrarHistorial($finiquito->solicitudInterna, $actor, 'finiquito_firmado_fisicamente', $observacion);

            return $finiquito->refresh();
        });
    }

    /**
     * LEGACY: subir el escaneo firmado pertenece al flujo anterior del
     * finiquito. Con CIERRE_FLUJO_LEGACY=false (vigente) se rechaza: el
     * finiquito nuevo se cierra registrando su entrega en el cierre laboral.
     */
    public function subirFirmado(FiniquitoCalculo $finiquito, UploadedFile $archivo, User $actor): FiniquitoCalculo
    {
        if (! (bool) config('features.cierre_flujo_legacy', false)) {
            throw ValidationException::withMessages([
                'flujo' => 'Este paso pertenece al flujo anterior del finiquito. Ahora RH registra la entrega del finiquito, que cierra el cierre laboral.',
            ]);
        }

        $this->asegurarNoFirmado($finiquito);

        return $this->guardarFirmado($finiquito, $archivo, $actor);
    }

    private function guardarFirmado(FiniquitoCalculo $finiquito, UploadedFile $archivo, User $actor): FiniquitoCalculo
    {
        // Extensión del contenido real (una foto JPG llamada «.pdf» se guarda
        // como .jpg), para que la descarga sirva el tipo correcto.
        $nombreInterno = $this->storage->nombreInterno(sprintf('firmado.%s', ExtensionArchivo::real($archivo)));
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
     * Pestaña Finiquitos (PDF Etapa 1, F4): RH sube el recibo firmado desde
     * «Revisado». El archivo no reemplaza al generado: queda aparte y con copia
     * en el expediente. Desde aquí el finiquito es de solo lectura.
     */
    public function registrarFirmado(FiniquitoCalculo $finiquito, UploadedFile $archivo, User $actor): FiniquitoCalculo
    {
        $this->asegurarNoFirmado($finiquito);

        if (! in_array($finiquito->estado, [EstadoFiniquito::Revisado, EstadoFiniquito::Aprobado], true)) {
            throw ValidationException::withMessages(['estado' => 'Marca el finiquito como revisado antes de subir el recibo firmado.']);
        }

        return $this->guardarFirmado($finiquito, $archivo, $actor);
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
        // Por defecto: el FORMATO OFICIAL de RH (docs/formatosRH/Formato_Finiquito.docx)
        // como documento administrativo. La plantilla de Jurídico o el
        // overlay anterior solo si se pide explícitamente (FINIQUITO_MOTOR).
        $motorLegado = config('finiquitos.motor') === 'legado';

        if ($motorLegado && $this->motor->tienePlantillaActiva('finiquito')) {
            $documento = $this->motor->generar($finiquito->colaborador, 'finiquito', $actor, $variables, $finiquito, 'Finiquito');
        } else {
            $formatoOficial = OfficialFormat::query()
                ->where('tipo', TipoFormatoOficial::Finiquito->value)
                ->where('is_active', true)
                ->first();

            $opciones = [
                'clave' => 'finiquito',
                'categoria' => CategoriaDocumento::BajaFiniquito,
                'payload' => $this->resolver->resolver($finiquito->colaborador, $variables),
                'documentable' => $finiquito,
                'requiere_impresion' => true,
                'requiere_firma_fisica' => true,
            ];

            if ($motorLegado && $formatoOficial !== null && $formatoOficial->tieneConfiguracion()) {
                $contenido = $this->formatosOficiales->renderizarPara($formatoOficial, $this->formatosOficiales->contextoDesde($finiquito->colaborador, $finiquito, $actor), $variables);
                $documento = $this->motor->registrarPdf($finiquito->colaborador, $contenido, 'Finiquito', $actor, $opciones);
            } else {
                // Sin formato oficial: documento administrativo con el diseño vigente
                // (Documentos maestros → Documentos administrativos → Finiquito).
                $administrativo = $this->documentosAdministrativos->generar(FamiliaAdministrativa::Finiquito, $this->datosDocumento->finiquito($finiquito, $desglose), $actor);
                $documento = $this->motor->registrarPdf($finiquito->colaborador, $administrativo['pdf'], 'Finiquito', $actor, [...$administrativo['opciones_registro'], ...$opciones]);
            }
        }

        $finiquito->update([
            'documento_generado_path' => $documento->path,
            'generated_document_id' => $documento->id,
            'snapshot' => $this->snapshot($finiquito, $desglose, ['generated_document_id' => $documento->id]),
        ]);

        return $finiquito->refresh();
    }

    /**
     * Sueldo mensual de partida para el cálculo: el mensual vigente del
     * colaborador (CompensacionService::mensualDe, la única fuente de
     * compensación: el mismo que lee la nómina). 0 si no tiene salario.
     */
    public function sueldoMensualSugerido(Colaborador $colaborador): float
    {
        return $this->compensacion->mensualDe($colaborador) ?? 0.0;
    }

    /**
     * Entrega del finiquito al excolaborador (último paso del cierre
     * laboral, CierreLaboralService::registrarEntregaFiniquito()): recalcula
     * los totales en el servidor, CONGELA el desglose en el snapshot y deja
     * el finiquito como pagado/firmado — desde aquí es de solo lectura
     * (asegurarNoFirmado()). Un finiquito que ya estaba pagado (flujo
     * anterior) no se vuelve a congelar.
     */
    public function registrarEntrega(FiniquitoCalculo $finiquito, User $actor, Carbon $fecha, ?string $formaPago = null, ?string $comentario = null): FiniquitoCalculo
    {
        return DB::transaction(function () use ($finiquito, $actor, $fecha, $formaPago, $comentario): FiniquitoCalculo {
            $bloqueado = FiniquitoCalculo::query()->lockForUpdate()->findOrFail($finiquito->id);

            if ($bloqueado->estado === EstadoFiniquito::Pagado) {
                return $bloqueado;
            }

            $bloqueado = $this->recalcularTotales($bloqueado);
            $desglose = $this->desglose($bloqueado);

            $bloqueado->update([
                'estado' => EstadoFiniquito::Pagado->value,
                'pagado_en' => $fecha,
                'pago_confirmado_por' => $actor->id,
                'referencia_pago' => $formaPago,
                'snapshot' => $this->snapshot($bloqueado, $desglose, [
                    'generated_document_id' => $bloqueado->generated_document_id,
                    'entrega' => [
                        'fecha' => $fecha->toDateString(),
                        'forma_pago' => $formaPago,
                        'comentario' => $comentario,
                        'registrada_por' => $actor->id,
                        'registrada_en' => now()->toIso8601String(),
                    ],
                ]),
            ]);

            $this->registrarHistorial($bloqueado->solicitudInterna, $actor, 'finiquito_entregado', trim(sprintf('Finiquito entregado el %s por $%s. %s', $fecha->format('d/m/Y'), number_format((float) $bloqueado->neto, 2), $comentario ?? '')));

            return $bloqueado->refresh();
        });
    }

    /**
     * Última edición del finiquito (cálculo, ajuste o concepto) con quién y
     * cuándo, a partir del historial de la solicitud de baja.
     *
     * @return array{accion: string, usuario: string|null, fecha: string|null}|null
     */
    public function ultimaEdicion(FiniquitoCalculo $finiquito): ?array
    {
        $evento = $finiquito->solicitudInterna->historial()
            ->with('usuario:id,name,apellidos')
            ->where('accion', 'like', 'finiquito%')
            ->reorder()
            ->latest('id')
            ->first();

        if ($evento === null) {
            return null;
        }

        return [
            'accion' => SolicitudInternaHistorial::etiquetaDe($evento->accion),
            'usuario' => $evento->usuario !== null ? trim(sprintf('%s %s', $evento->usuario->name, $evento->usuario->apellidos)) : null,
            'fecha' => $evento->created_at->toIso8601String(),
        ];
    }

    /**
     * Datos congelados del finiquito (PDF generado o entrega registrada).
     *
     * @param  list<array<string, mixed>>  $desglose
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function snapshot(FiniquitoCalculo $finiquito, array $desglose, array $extra = []): array
    {
        return [
            ...$finiquito->only([
                'sueldo_mensual', 'sueldo_diario', 'antiguedad_anios', 'antiguedad_meses',
                'dias_trabajados_periodo', 'dias_trabajados_anio', 'vacaciones_pendientes', 'vacaciones_proporcionales', 'vacaciones_pendientes_pago', 'prima_vacacional',
                'aguinaldo_proporcional', 'sueldo_pendiente', 'indemnizacion', 'bonos_extra',
                'descuentos', 'adeudos', 'isr_retenido', 'otros_conceptos', 'total_calculado', 'total_ajustado',
                'comentarios_ajuste', 'total_percepciones', 'total_deducciones', 'neto',
            ]),
            'conceptos' => $desglose,
            'ajustes' => $finiquito->ajustes()->get(['concepto_clave', 'concepto', 'valor_calculado', 'valor_final', 'ajuste', 'motivo', 'user_id', 'created_at'])->toArray(),
            'congelado_en' => now()->toIso8601String(),
            ...$extra,
        ];
    }

    /**
     * true si el próximo generarPdf() usará el formato oficial de finiquito
     * en vez del respaldo DomPDF — para que la UI avise cuál va a usar.
     */
    public function tieneFormatoOficialConfigurado(): bool
    {
        if (config('finiquitos.motor') !== 'legado') {
            return false;
        }

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
            // Días que se pagan (pendientes + proporcionales del año en curso),
            // los mismos con los que se calculó el importe de vacaciones.
            'finiquito_vacaciones_pendientes' => sprintf('%s días', rtrim(rtrim(number_format($this->diasVacaciones($finiquito), 2), '0'), '.')),
            'finiquito_vacaciones_pendientes_pago' => $this->moneda($finiquito->vacaciones_pendientes_pago),
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
        // Una vez firmado, el documento que vale es el escaneo firmado; antes,
        // el PDF generado para firma.
        $ruta = $finiquito->documento_firmado_path ?? $finiquito->documento_generado_path;

        abort_unless($ruta !== null, 404, 'Genera el PDF del finiquito antes de descargarlo.');

        // El firmado puede ser un escaneo JPG/PNG: el tipo MIME lo infiere el
        // disco a partir del archivo real, no se fuerza a PDF.
        $extension = pathinfo($ruta, PATHINFO_EXTENSION) ?: 'pdf';

        abort_unless($this->storage->disco()->exists($ruta), 404, 'El archivo del finiquito ya no está en el almacenamiento.');

        // La URL es la misma para el PDF generado, el regenerado tras un
        // ajuste y el firmado: sin no-store el navegador mostraría la versión
        // anterior en caché.
        return $this->storage->respuesta($ruta, [
            'Content-Disposition' => sprintf('inline; filename="finiquito-%d.%s"', $finiquito->solicitud_interna_id, $extension),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
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
        // Sin fecha efectiva capturada, la baja se toma al día calendario de
        // México (no UTC, que de noche ya cuenta el día siguiente). P1-28.
        $fechaBaja = $solicitud->fecha_efectiva !== null ? Carbon::parse($solicitud->fecha_efectiva) : FechaMexico::inicioDeHoy();

        if ($fechaBaja->lt($fechaIngreso)) {
            throw ValidationException::withMessages(['fecha_baja' => 'La fecha de baja no puede ser anterior a la fecha de ingreso.']);
        }

        $antiguedadAnios = (int) $fechaIngreso->diffInYears($fechaBaja);
        $ultimoAniversario = $fechaIngreso->copy()->addYears($antiguedadAnios);
        $antiguedadMeses = (int) $ultimoAniversario->diffInMonths($fechaBaja);
        // Días del año de servicio en curso: del último aniversario (o del
        // ingreso, si aún no cumple el año) a la baja, ambos inclusive.
        $diasTrabajadosPeriodo = (int) $ultimoAniversario->diffInDays($fechaBaja) + 1;
        $diasTrabajadosAnio = self::diasTrabajadosAnioCalendario($fechaIngreso, $fechaBaja);

        $sueldoDiario = $this->compensacion->diarioDesdeMensual($sueldoMensual);

        // Vacaciones (LFT art. 79): el saldo pendiente de los años ya
        // cumplidos (VacacionesService no prorratea: genera los días hasta el
        // aniversario) MÁS la parte proporcional del año de servicio en
        // curso, con los días que le corresponderían al cumplirlo (tabla de
        // config/vacaciones.php). Sin doble conteo: el saldo nunca incluye
        // el año en curso.
        $vacacionesPendientes = $this->vacaciones->saldoColaborador($colaborador)['dias_disponibles'];
        $vacacionesProporcionales = round($this->vacaciones->diasPorAntiguedad($antiguedadAnios + 1) * $diasTrabajadosPeriodo / 365, 2);
        $diasVacaciones = $vacacionesPendientes + $vacacionesProporcionales;

        // Vacaciones (el pago de los días a sueldo diario) y prima
        // vacacional (el % extra sobre esos mismos días) son conceptos
        // legales distintos (CLAUDE.md §20/§36): nunca se combinan en un
        // solo monto.
        $vacacionesPendientesPago = round($diasVacaciones * $sueldoDiario, 2);
        $primaVacacionalPorcentaje = (int) config('finiquitos.prima_vacacional_porcentaje');
        $primaVacacional = round($diasVacaciones * $sueldoDiario * ($primaVacacionalPorcentaje / 100), 2);

        // Aguinaldo (LFT art. 87): proporcional a los días trabajados en el
        // año calendario de la baja, no desde el último aniversario.
        $diasAguinaldo = (int) config('finiquitos.dias_aguinaldo');
        $aguinaldoProporcional = round($diasAguinaldo * $diasTrabajadosAnio / 365 * $sueldoDiario, 2);

        $indemnizacion = $this->calcularIndemnizacion($solicitud, $antiguedadAnios, $sueldoDiario);

        $totalCalculado = round($sueldoPendiente + $vacacionesPendientesPago + $primaVacacional + $aguinaldoProporcional + $indemnizacion, 2);

        return [
            'fecha_ingreso' => $fechaIngreso->toDateString(),
            'fecha_baja' => $fechaBaja->toDateString(),
            'sueldo_mensual' => $sueldoMensual,
            'sueldo_diario' => $sueldoDiario,
            'antiguedad_anios' => $antiguedadAnios,
            'antiguedad_meses' => $antiguedadMeses,
            'dias_trabajados_periodo' => $diasTrabajadosPeriodo,
            'dias_trabajados_anio' => $diasTrabajadosAnio,
            'vacaciones_pendientes' => $vacacionesPendientes,
            'vacaciones_proporcionales' => $vacacionesProporcionales,
            'vacaciones_pendientes_pago' => $vacacionesPendientesPago,
            'prima_vacacional' => $primaVacacional,
            'aguinaldo_proporcional' => $aguinaldoProporcional,
            'sueldo_pendiente' => $sueldoPendiente,
            'indemnizacion' => $indemnizacion,
            'total_calculado' => $totalCalculado,
        ];
    }

    /**
     * Días trabajados en el año calendario de la baja (base del aguinaldo,
     * LFT art. 87): del 1 de enero de ese año —o de la fecha de ingreso, si
     * fue posterior— a la fecha de baja, ambos inclusive.
     */
    public static function diasTrabajadosAnioCalendario(Carbon $fechaIngreso, Carbon $fechaBaja): int
    {
        $inicioAnio = $fechaBaja->copy()->startOfYear()->startOfDay();
        $desde = $fechaIngreso->copy()->startOfDay()->max($inicioAnio);

        return (int) $desde->diffInDays($fechaBaja->copy()->startOfDay()) + 1;
    }

    /**
     * Días de aguinaldo proporcional de un finiquito (para la columna
     * «Días»). Los calculados antes de existir dias_trabajados_anio
     * conservan la base con la que se calculó su importe.
     */
    public function diasAguinaldo(FiniquitoCalculo $finiquito): float
    {
        $base = $finiquito->dias_trabajados_anio ?? $finiquito->dias_trabajados_periodo;

        return round((int) config('finiquitos.dias_aguinaldo') * $base / 365, 2);
    }

    /**
     * Días de vacaciones pagados: saldo pendiente + proporcionales del año
     * de servicio en curso.
     */
    public function diasVacaciones(FiniquitoCalculo $finiquito): float
    {
        return round($finiquito->vacaciones_pendientes + (float) $finiquito->vacaciones_proporcionales, 2);
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

        // Decide el TIPO (renuncia nunca) y, dentro del tipo, la causal de
        // rotación (p. ej. abandono de trabajo la excluye).
        if ($solicitud->tipo_baja === null || ! $solicitud->tipo_baja->contemplaIndemnizacion()) {
            return 0.0;
        }

        $causal = $solicitud->causalRotacion;

        if ($causal?->excluye_indemnizacion === true) {
            return 0.0;
        }

        // Con causal del catálogo, manda su marca «Implica indemnización». Sin
        // causal (bajas anteriores al catálogo) se conserva la regla por tipo.
        if ($causal !== null && ! $causal->implica_indemnizacion) {
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
