<?php

namespace App\Services\RecepcionDocumental\Reglas;

use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\ReglaRecepcionDocumental;
use App\Models\ContratoLaboral;
use App\Models\GeneratedDocument;
use App\Services\RecepcionDocumental\EsperadoRecepcion;
use App\Services\RecepcionDocumental\PeriodoRecepcion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * CONTRATO INDETERMINADO (renovación): el original se espera en el mes en
 * que se GENERÓ por primera vez el documento `contrato_indeterminado` de la
 * renovación (MIN(created_at) en hora de México), no en el mes del alta.
 * Una nueva versión del mismo contrato en otro mes no vuelve a esperarse:
 * se liga el renglón original a la versión vigente.
 *
 * La contratación DIRECTA por tiempo indeterminado (sin capacitación) es
 * parte del paquete de alta y la cubre ReglaAltas.
 */
class ReglaContratoIndeterminado implements ReglaRecepcion
{
    public const TIPO = 'contrato_indeterminado';

    public function regla(): ReglaRecepcionDocumental
    {
        return ReglaRecepcionDocumental::ContratoIndeterminado;
    }

    public function esperados(PeriodoRecepcion $periodo): array
    {
        [$desde, $hasta] = $periodo->rangoUtc();
        $morph = (new ContratoLaboral)->getMorphClass();

        $primeros = GeneratedDocument::query()
            ->where('clave_plantilla', self::TIPO)
            ->where('documentable_type', $morph)
            ->where('created_at', '<=', $hasta)
            ->groupBy('documentable_id')
            ->havingRaw('MIN(created_at) >= ? AND MIN(created_at) <= ?', [$desde, $hasta])
            ->selectRaw('documentable_id, MIN(created_at) AS primero')
            ->toBase()
            ->get();

        if ($primeros->isEmpty()) {
            return [];
        }

        /** @var array<int, string> $fechas documentable_id => primer created_at (UTC) */
        $fechas = [];

        foreach ($primeros as $fila) {
            $fechas[(int) $fila->documentable_id] = (string) $fila->primero;
        }

        $contratos = ContratoLaboral::query()
            ->whereIn('id', array_keys($fechas))
            ->whereNotNull('contrato_anterior_id')
            ->where('estado', '!=', EstadoContratoLaboral::Cancelado->value)
            ->with(['colaborador' => fn ($q) => $q->withTrashed()->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])])
            ->get();

        $vigentes = GeneratedDocument::query()
            ->where('clave_plantilla', self::TIPO)
            ->where('documentable_type', $morph)
            ->whereIn('documentable_id', $contratos->modelKeys())
            ->where(fn (Builder $q) => $q->whereNull('estado_flujo')->orWhere('estado_flujo', '!=', EstadoFlujoDocumento::Cancelado->value))
            ->orderByDesc('id')
            ->get(['id', 'documentable_id'])
            ->unique('documentable_id')
            ->keyBy('documentable_id');

        $esperados = [];

        foreach ($contratos as $contrato) {
            $generado = CarbonImmutable::parse($fechas[$contrato->id], 'UTC');
            $vigente = $vigentes->get($contrato->id);

            $esperados[] = EsperadoRecepcion::deColaborador(
                $this->regla(),
                self::TIPO,
                EsperadoRecepcion::etiquetaTipo(self::TIPO),
                $contrato,
                $contrato->colaborador,
                PeriodoRecepcion::fechaMexico($generado),
                sprintf('Contrato indeterminado generado el %s (inicio %s)', $generado->setTimezone('America/Mexico_City')->format('d/m/Y'), $contrato->fecha_inicio->format('d/m/Y')),
                $vigente instanceof GeneratedDocument ? $vigente->id : null,
            );
        }

        return $esperados;
    }
}
