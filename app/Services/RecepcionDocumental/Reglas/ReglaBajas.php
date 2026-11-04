<?php

namespace App\Services\RecepcionDocumental\Reglas;

use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstatusBaja;
use App\Enums\OrigenBaja;
use App\Enums\ReglaRecepcionDocumental;
use App\Models\Baja;
use App\Models\DocumentoRequeridoBaja;
use App\Models\FiniquitoCalculo;
use App\Models\GeneratedDocument;
use App\Services\RecepcionDocumental\EsperadoRecepcion;
use App\Services\RecepcionDocumental\PeriodoRecepcion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * BAJAS: por cada baja cuya fecha_baja (DATE, la fecha legal de la baja)
 * cae en el mes, los originales del catálogo «Documentos requeridos de la
 * baja» de su forma de salida + los adicionales de su causal (el mismo
 * criterio de CatalogoBajasService::documentosRequeridos que usa el
 * expediente de baja): carta de renuncia, finiquito firmado, aviso de
 * terminación, acta, evidencias…
 *
 * Por qué fecha_baja y no la fecha de cada documento: el paquete de salida
 * de una persona viaja junto y se concilia una sola vez; usar la fecha de
 * firma de cada pieza lo repartiría entre meses.
 *
 * Cuentan las bajas en trámite o cerradas (no borrador). Una baja
 * cancelada o rechazada deja de esperarse (sus pendientes pasan solos a
 * «No aplica»). Las bajas «cierre previo» (anteriores al módulo) no exigen
 * documentos, igual que en su expediente.
 */
class ReglaBajas implements ReglaRecepcion
{
    public const PREFIJO = 'baja.';

    public function regla(): ReglaRecepcionDocumental
    {
        return ReglaRecepcionDocumental::Bajas;
    }

    public function esperados(PeriodoRecepcion $periodo): array
    {
        $bajas = Baja::query()
            ->whereBetween('fecha_baja', $periodo->rangoFechas())
            ->whereIn('estatus', [EstatusBaja::EnRevisionRh->value, EstatusBaja::EnRevisionJuridico->value, EstatusBaja::Cerrada->value])
            ->where('origen', '!=', OrigenBaja::CierrePrevio->value)
            ->with(['sucursal:id,nombre', 'puesto:id,nombre'])
            ->orderBy('id')
            ->get();

        if ($bajas->isEmpty()) {
            return [];
        }

        $requeridos = DocumentoRequeridoBaja::query()
            ->where('activo', true)
            ->orderByRaw('case when causal_rotacion_id is null then 0 else 1 end')
            ->orderBy('orden')
            ->get();

        $colaboradores = $bajas->pluck('colaborador_id')->filter()->unique()->values()->all();
        $documentos = $this->documentosSistema($colaboradores);
        $finiquitos = FiniquitoCalculo::query()
            ->whereIn('colaborador_id', $colaboradores)
            ->whereNotNull('generated_document_id')
            ->orderByDesc('id')
            ->get(['id', 'colaborador_id', 'generated_document_id'])
            ->unique('colaborador_id')
            ->keyBy('colaborador_id');

        $esperados = [];

        foreach ($bajas as $baja) {
            $suyos = $requeridos->filter(fn (DocumentoRequeridoBaja $r): bool => ($baja->forma_salida !== null && $r->forma_salida === $baja->forma_salida)
                || ($baja->causal_rotacion_id !== null && $r->causal_rotacion_id === $baja->causal_rotacion_id));
            $descripcion = sprintf('Baja del %s%s', $baja->fecha_baja?->format('d/m/Y') ?? 'sin fecha', $baja->forma_salida !== null ? sprintf(' · %s', $baja->forma_salida->etiqueta()) : '');

            foreach ($suyos as $requerido) {
                $documentoId = match ($requerido->documento_sistema) {
                    null => null,
                    'finiquito' => $finiquitos->get($baja->colaborador_id)?->generated_document_id,
                    default => $documentos->get(sprintf('%d|%s', $baja->colaborador_id, $requerido->documento_sistema)),
                };

                $esperados[] = new EsperadoRecepcion(
                    $this->regla(),
                    mb_substr(self::PREFIJO.$requerido->clave, 0, 60),
                    mb_substr($requerido->nombre, 0, 160),
                    $baja,
                    is_int($documentoId) ? $documentoId : null,
                    $baja->colaborador_id,
                    $baja->sucursal_id,
                    $baja->nombre_colaborador,
                    $baja->numero_empleado,
                    $baja->puesto?->nombre,
                    $baja->sucursal?->nombre,
                    $baja->fecha_baja?->toDateString(),
                    $descripcion,
                );
            }
        }

        return $esperados;
    }

    /**
     * Documento generado vigente más reciente de cada colaborador por clave
     * (carta de renuncia, aviso de terminación…): «colaborador|clave» => id.
     *
     * @param  array<int, mixed>  $colaboradores
     * @return Collection<string, int>
     */
    private function documentosSistema(array $colaboradores): Collection
    {
        if ($colaboradores === []) {
            return collect();
        }

        $resultado = collect();

        GeneratedDocument::query()
            ->whereIn('colaborador_id', $colaboradores)
            ->whereIn('clave_plantilla', ['carta_renuncia', 'aviso_terminacion', 'evaluacion_capacitacion'])
            ->where(fn (Builder $q) => $q->whereNull('estado_flujo')->orWhere('estado_flujo', '!=', EstadoFlujoDocumento::Cancelado->value))
            ->orderByDesc('id')
            ->get(['id', 'colaborador_id', 'clave_plantilla'])
            ->each(function (GeneratedDocument $d) use ($resultado): void {
                $clave = sprintf('%d|%s', (int) $d->colaborador_id, (string) $d->clave_plantilla);

                if (! $resultado->has($clave)) {
                    $resultado->put($clave, $d->id);
                }
            });

        return $resultado;
    }
}
