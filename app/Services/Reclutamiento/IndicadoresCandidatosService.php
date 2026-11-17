<?php

namespace App\Services\Reclutamiento;

use App\Enums\EstadoCandidato;
use App\Models\Candidato;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * KPIs del encabezado de Candidatos: recibidos en el mes, en proceso,
 * finalistas (esperando o con autorización de RH), contratados, tasa de
 * conversión y tiempo promedio de contratación.
 *
 * Usa la MISMA consulta del tablero (CandidatoPresenter::consulta: alcance
 * por sucursal + aprobador de entrevista) con los filtros de organización y
 * fuente. La plantilla histórica nunca cuenta: no es reclutamiento.
 */
class IndicadoresCandidatosService
{
    /** Filtros del tablero que también acotan los indicadores. */
    private const FILTROS = ['empresa_id', 'sucursal_id', 'departamento_id', 'puesto_objetivo_id', 'fuente'];

    public function __construct(private readonly CandidatoPresenter $presenter) {}

    /**
     * @param  array<string, mixed>  $filtros  Los de FILTROS más `mes` (AAAA-MM; vacío = mes actual).
     * @return array<string, int|float|null>
     */
    public function kpis(User $usuario, array $filtros): array
    {
        [$inicioPeriodo, $finPeriodo] = $this->rangoMes(is_string($filtros['mes'] ?? null) ? $filtros['mes'] : '');

        $base = fn (): Builder => $this->presenter
            ->consulta($usuario, [...array_intersect_key($filtros, array_flip(self::FILTROS)), 'historicos' => 'ocultar'])
            ->setEagerLoads([]);

        $recibidosPeriodo = $base()->whereBetween('created_at', [$inicioPeriodo, $finPeriodo])->count();
        $enProceso = $base()->whereIn('estado', array_map(fn (EstadoCandidato $e) => $e->value, EstadoCandidato::abiertos()))->count();
        $finalistas = $base()->whereIn('estado', [EstadoCandidato::AutorizacionRhPendiente->value, EstadoCandidato::AutorizadoRh->value])->count();

        $contratados = $base()
            ->where('estado', EstadoCandidato::Contratado->value)
            ->whereNotNull('contratado_en')
            ->whereBetween('contratado_en', [$inicioPeriodo, $finPeriodo])
            ->get(['id', 'created_at', 'contratado_en']);

        $dias = $contratados
            ->map(fn (Candidato $c) => $c->created_at !== null && $c->contratado_en !== null ? $c->created_at->diffInDays($c->contratado_en) : null)
            ->filter(fn ($d) => $d !== null);

        return [
            'recibidos_periodo' => $recibidosPeriodo,
            'en_proceso' => $enProceso,
            'finalistas' => $finalistas,
            'contratados_periodo' => $contratados->count(),
            'tasa_conversion' => $recibidosPeriodo > 0 ? round($contratados->count() / $recibidosPeriodo, 4) : 0.0,
            'tiempo_promedio_contratacion_dias' => $dias->isEmpty() ? null : round((float) $dias->avg(), 1),
        ];
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function rangoMes(string $mes): array
    {
        if ($mes !== '' && preg_match('/^\d{4}-\d{2}$/', $mes) === 1) {
            $inicio = Carbon::parse(sprintf('%s-01', $mes))->startOfDay();

            return [$inicio, $inicio->copy()->endOfMonth()];
        }

        return [now()->startOfMonth(), now()->copy()->endOfMonth()];
    }
}
