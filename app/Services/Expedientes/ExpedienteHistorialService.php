<?php

namespace App\Services\Expedientes;

use App\Enums\TipoMovimientoLaboral;
use App\Enums\TipoSolicitudInterna;
use App\Models\AltaDigital;
use App\Models\Colaborador;
use App\Models\MovimientoLaboral;
use App\Models\Prestamo;
use App\Models\PrestamoMovimiento;
use App\Models\ReciboNomina;
use App\Models\SolicitudInterna;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Pestañas de historial del expediente (vacaciones, solicitudes, recibos,
 * préstamos, movimientos laborales, avisos de privacidad): las consultas y
 * su forma de salida, fuera del controlador. Los permisos (qué pestaña se
 * manda) los decide quien llama.
 *
 * colaborador_id es la fuente real de identidad de una solicitud (ver
 * SolicitudInterna::personaSolicitante()): un colaborador SIN cuenta puede
 * tener saldo de vacaciones, solicitudes, préstamos y recibos. user_id se
 * conserva solo como respaldo de lectura para filas legacy.
 */
class ExpedienteHistorialService
{
    /**
     * Últimas 10 vacaciones (tabla unificada solicitudes_internas, nunca la
     * legacy solicitudes_vacaciones; ver docs/SOLICITUDES_UNIFICADAS.md).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function solicitudesVacaciones(Colaborador $colaborador, ?int $usuarioId): Collection
    {
        return $this->solicitudesDe($colaborador, $usuarioId)
            ->where('tipo', TipoSolicitudInterna::Vacaciones)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'user_id', 'fecha_inicio', 'fecha_fin', 'dias_solicitados', 'motivo', 'estado', 'motivo_rechazo', 'created_at'])
            ->map(fn (SolicitudInterna $solicitud): array => $this->item([
                'id' => $solicitud->id,
                'user_id' => $solicitud->user_id,
                'fecha_inicio' => $solicitud->fecha_inicio?->toDateString(),
                'fecha_fin' => $solicitud->fecha_fin?->toDateString(),
                'dias_solicitados' => $solicitud->dias_solicitados,
                'comentario' => $solicitud->motivo,
                'estado' => $solicitud->estado->value,
                'motivo_rechazo' => $solicitud->motivo_rechazo,
                'created_at' => $solicitud->created_at?->toISOString(),
            ]))
            ->toBase();
    }

    /**
     * Pestaña «Solicitudes»: las últimas 20 de cualquier tipo.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function solicitudes(Colaborador $colaborador, ?int $usuarioId): Collection
    {
        return $this->solicitudesDe($colaborador, $usuarioId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'folio', 'tipo', 'estado', 'motivo', 'created_at'])
            ->map(fn (SolicitudInterna $solicitud): array => $this->item([
                'id' => $solicitud->id,
                'folio' => $solicitud->folio,
                'tipo' => $solicitud->tipo->value,
                'tipo_etiqueta' => $solicitud->tipo->etiqueta(),
                'estado' => $solicitud->estado->value,
                'motivo' => $solicitud->motivo,
                'created_at' => $solicitud->created_at?->toISOString(),
            ]))
            ->toBase();
    }

    /**
     * Pestaña «Recibos de nómina»: los últimos 20 (el PDF se descarga aparte).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function recibosNomina(Colaborador $colaborador): Collection
    {
        return $colaborador->recibosNomina()
            ->limit(20)
            ->get()
            ->map(fn (ReciboNomina $recibo): array => $this->item([
                'id' => $recibo->id,
                'periodo_inicio' => $recibo->periodo_inicio->toDateString(),
                'periodo_fin' => $recibo->periodo_fin->toDateString(),
                'fecha_pago' => $recibo->fecha_pago->toDateString(),
                'sueldo_base' => (float) $recibo->sueldo_base,
                'percepciones' => $recibo->percepciones,
                'deducciones' => $recibo->deducciones,
                'total_percepciones' => (float) $recibo->total_percepciones,
                'total_deducciones' => (float) $recibo->total_deducciones,
                'neto' => (float) $recibo->neto,
                'tiene_pdf' => $recibo->pdf_path !== null,
                'created_at' => $recibo->created_at?->toISOString(),
            ]))
            ->toBase();
    }

    /**
     * Pestaña «Préstamos»: los últimos 10 con su historial de movimientos
     * (ledger append-only, ver App\Services\Nomina\PrestamoService).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function prestamos(Colaborador $colaborador): Collection
    {
        return collect(array_map(
            fn (Prestamo $prestamo): array => $this->item($this->prestamoResumen($prestamo)),
            $colaborador->prestamos()
                ->with('movimientos.registradoPor:id,name,apellidos')
                ->limit(10)
                ->get()
                ->all(),
        ));
    }

    /**
     * Últimos 30 movimientos laborales; sin acceso a sueldos, sin cambios de
     * sueldo. Mismas relaciones que el historial del puesto (un único tipo
     * MovimientoLaboralItem en el frontend, ver docs/MOVIMIENTOS_LABORALES.md).
     *
     * @return EloquentCollection<int, MovimientoLaboral>
     */
    public function movimientosLaborales(Colaborador $colaborador, bool $puedeVerSueldo): EloquentCollection
    {
        return MovimientoLaboral::query()
            ->where('colaborador_id', $colaborador->id)
            ->when(! $puedeVerSueldo, fn (Builder $q) => $q->where('tipo_movimiento', '!=', TipoMovimientoLaboral::CambioSueldo->value))
            ->with([
                'colaborador:id,name,apellidos',
                'puestoAnterior:id,nombre', 'puestoNuevo:id,nombre',
                'sucursalAnterior:id,nombre', 'sucursalNueva:id,nombre',
                'departamentoAnterior:id,nombre', 'departamentoNuevo:id,nombre',
                'empresaAnterior:id,nombre', 'empresaNueva:id,nombre',
                'jefeAnterior:id,name,apellidos', 'jefeNuevo:id,name,apellidos',
                'vacante:id,puesto_id', 'vacante.puesto:id,nombre',
                'documento:id,original_name',
                'registradoPor:id,name,apellidos',
            ])
            ->orderByDesc('fecha_movimiento')
            ->orderByDesc('id')
            ->limit(30)
            ->get();
    }

    /**
     * Aviso de privacidad y consentimiento: si hay alta digital, ella es la
     * fuente de verdad (`altaDigital`) y no se ofrece el registro manual
     * (`avisosManual` = null; ver AvisoPrivacidadService).
     *
     * @return array{altaDigital: array<string, mixed>|null, avisosManual: array<string, mixed>|null}
     */
    public function avisosPrivacidad(Colaborador $colaborador): array
    {
        $alta = AltaDigital::query()->where('colaborador_id', $colaborador->id)->first();
        $colaborador->loadMissing('avisosRegistradoPor:id,name,apellidos');

        return [
            'altaDigital' => $alta ? [
                'id' => $alta->id,
                'estado' => $alta->estado->value,
                'aviso_privacidad_aceptado' => $alta->aviso_privacidad_aceptado,
                'aviso_privacidad_aceptado_en' => $alta->aviso_privacidad_aceptado_en?->toDateTimeString(),
                'consentimiento_datos_aceptado' => $alta->consentimiento_datos_aceptado,
                'consentimiento_datos_aceptado_en' => $alta->consentimiento_datos_aceptado_en?->toDateTimeString(),
            ] : null,
            'avisosManual' => $alta ? null : [
                'aviso_privacidad_aceptado' => $colaborador->aviso_privacidad_aceptado,
                'aviso_privacidad_aceptado_en' => $colaborador->aviso_privacidad_aceptado_en?->toDateTimeString(),
                'consentimiento_datos_aceptado' => $colaborador->consentimiento_datos_aceptado,
                'consentimiento_datos_aceptado_en' => $colaborador->consentimiento_datos_aceptado_en?->toDateTimeString(),
                'registrado_por' => $this->nombreActor($colaborador->avisosRegistradoPor),
            ],
        ];
    }

    /**
     * @return Builder<SolicitudInterna>
     */
    private function solicitudesDe(Colaborador $colaborador, ?int $usuarioId): Builder
    {
        return SolicitudInterna::query()
            ->where(fn (Builder $q) => $q->where('colaborador_id', $colaborador->id)->when(
                $usuarioId !== null,
                fn (Builder $sub) => $sub->orWhere('user_id', $usuarioId),
            ));
    }

    /**
     * @return array<string, mixed>
     */
    private function prestamoResumen(Prestamo $prestamo): array
    {
        return [
            'id' => $prestamo->id,
            'monto_original' => (float) $prestamo->monto_original,
            'saldo' => (float) $prestamo->saldo,
            'plazo' => $prestamo->plazo,
            'periodicidad' => $prestamo->periodicidad,
            'pago_programado' => (float) $prestamo->pago_programado,
            'porcentaje_pagado' => (float) $prestamo->monto_original > 0
                ? (int) round((1 - ((float) $prestamo->saldo / (float) $prestamo->monto_original)) * 100)
                : 0,
            'fecha_otorgamiento' => $prestamo->fecha_otorgamiento?->toDateString(),
            'fecha_primer_descuento' => $prestamo->fecha_primer_descuento?->toDateString(),
            'estado' => $prestamo->estado,
            'movimientos' => collect(array_map(
                fn (PrestamoMovimiento $m): array => $this->item($this->movimientoResumen($m)),
                $prestamo->movimientos->all(),
            )),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function movimientoResumen(PrestamoMovimiento $movimiento): array
    {
        return [
            'id' => $movimiento->id,
            'fecha' => $movimiento->fecha->toDateString(),
            'monto' => (float) $movimiento->monto,
            'tipo' => $movimiento->tipo,
            'saldo_anterior' => (float) $movimiento->saldo_anterior,
            'saldo_nuevo' => (float) $movimiento->saldo_nuevo,
            'registrado_por' => $this->nombreActor($movimiento->registradoPor),
        ];
    }

    private function nombreActor(?User $actor): ?string
    {
        return $actor ? trim(sprintf('%s %s', $actor->name, $actor->apellidos)) : null;
    }

    /**
     * Collection no es covariante: se ensancha el shape literal en la
     * frontera (mismo patrón que RhPendientesService::item()).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function item(array $item): array
    {
        return $item;
    }
}
