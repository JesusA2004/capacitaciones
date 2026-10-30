<?php

namespace App\Services\Reclutamiento;

use App\Enums\EstadoAgendaEvento;
use App\Enums\OrigenAgendaEvento;
use App\Enums\ResultadoAgendaEvento;
use App\Enums\TipoAgendaEvento;
use App\Models\AgendaEvento;
use App\Models\AgendaEventoHistorial;
use App\Models\User;
use App\Services\Permisos\AlcanceModuloService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lecturas de la agenda: listado, ficha e indicadores, siempre acotados al
 * alcance del módulo. Web, API y exportaciones usan estas mismas consultas.
 *
 * Fórmulas de los indicadores (sobre las citas de entrevista, por fecha de cita):
 *  - Agendadas: sin canceladas ni reprogramadas. Una cita movida cuenta solo en
 *    su versión vigente; una inasistencia que luego se reprograma son dos citas
 *    reales, porque el candidato sí faltó a la primera.
 *  - Inasistencia: no asistió ÷ (realizadas + no asistió). Lo aún programado no
 *    entra, para no bajar el porcentaje con citas que no han ocurrido.
 *  - Viabilidad: viables ÷ realizadas.
 *  - Agendadas por Reclutamiento: origen reclutamiento ÷ agendadas.
 *  - Días de cita a resultado: promedio de resultado_en − fecha_hora, sobre
 *    entrevistas y estudios realizados (nunca negativo).
 * Sin denominador el indicador es null («sin datos»), nunca 0%.
 */
class AgendaConsultaService
{
    public function __construct(private readonly AlcanceModuloService $alcance) {}

    /**
     * @param  array<string, mixed>  $filtros  desde?, hasta?, sucursal_id?, tipo?, estado?, busqueda?
     * @return Builder<AgendaEvento>
     */
    public function consulta(User $usuario, array $filtros = []): Builder
    {
        return $this->alcance->limitarPorSucursal(AgendaEvento::query(), $usuario, 'agenda')
            ->when($filtros['desde'] ?? null, fn (Builder $q, $desde) => $q->where('fecha_hora', '>=', Carbon::parse((string) $desde)->startOfDay()))
            ->when($filtros['hasta'] ?? null, fn (Builder $q, $hasta) => $q->where('fecha_hora', '<=', Carbon::parse((string) $hasta)->endOfDay()))
            ->when($filtros['sucursal_id'] ?? null, fn (Builder $q, $id) => $q->where('sucursal_id', (int) $id))
            ->when($filtros['tipo'] ?? null, fn (Builder $q, $tipo) => $q->where('tipo', (string) $tipo))
            ->when($filtros['estado'] ?? null, fn (Builder $q, $estado) => $q->where('estado', (string) $estado))
            ->when($filtros['busqueda'] ?? null, function (Builder $q, $busqueda): void {
                $termino = sprintf('%%%s%%', (string) $busqueda);
                $q->whereHas('candidato', fn (Builder $c) => $c->where('nombre', 'like', $termino)->orWhere('apellidos', 'like', $termino));
            });
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, AgendaEvento>
     */
    public function listar(User $usuario, array $filtros = []): Collection
    {
        return $this->consulta($usuario, $filtros)
            ->with(['candidato.puestoObjetivo:id,nombre', 'candidato.vacante.puesto:id,nombre', 'sucursal:id,nombre', 'agendadoPor:id,name'])
            ->orderBy('fecha_hora')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(AgendaEvento $evento, User $viewer, bool $conHistorial = false): array
    {
        $evento->loadMissing(['candidato.puestoObjetivo:id,nombre', 'candidato.vacante.puesto:id,nombre', 'sucursal:id,nombre', 'agendadoPor:id,name']);
        $candidato = $evento->candidato;
        $verDatos = $viewer->can('verDatosCandidato', $evento);
        $vigente = $evento->estado === EstadoAgendaEvento::Programado;

        return [
            'id' => $evento->id,
            'tipo' => $evento->tipo->value,
            'tipo_etiqueta' => $evento->tipo->etiqueta(),
            'fecha_hora' => $evento->fecha_hora->format('Y-m-d H:i'),
            'fecha' => $evento->fecha_hora->toDateString(),
            'hora' => $evento->fecha_hora->format('H:i'),
            'estado' => $evento->estado->value,
            'estado_etiqueta' => $evento->estado->etiqueta(),
            'origen' => $evento->origen->value,
            'origen_etiqueta' => $evento->origen->etiqueta(),
            'agendado_por' => $evento->agendadoPor?->name,
            'sucursal' => ['id' => $evento->sucursal_id, 'nombre' => $evento->sucursal->nombre],
            'candidato' => [
                'id' => $candidato->id,
                'nombre' => $candidato->nombreCompleto(),
                'puesto' => $candidato->puestoObjetivo->nombre ?? $candidato->vacante?->puesto->nombre,
                'estado' => $candidato->estado->value,
                'estado_etiqueta' => $candidato->estado->etiqueta(),
                'telefono' => $verDatos ? $candidato->telefono : null,
                'tiene_cv' => $verDatos ? $candidato->cv_path !== null : null,
            ],
            'domicilio' => $verDatos ? $evento->domicilio : null,
            'notas' => $evento->notas,
            'resultado' => $evento->resultado?->value,
            'resultado_etiqueta' => $evento->resultado?->etiqueta(),
            'motivo_no_viable' => $evento->motivo_no_viable?->value,
            'motivo_no_viable_etiqueta' => $evento->motivo_no_viable?->etiqueta(),
            'comentario_resultado' => $evento->comentario_resultado,
            'resultado_en' => $evento->resultado_en?->format('Y-m-d H:i'),
            'motivo_cancelacion' => $evento->motivo_cancelacion,
            'reprogramado_de_id' => $evento->reprogramado_de_id,
            'reprogramado_a_id' => $evento->reprogramado_a_id,
            'puede' => [
                'gestionar' => $viewer->can('gestionar', $evento),
                'registrar_resultado' => $viewer->can('registrarResultado', $evento),
                'ver_datos_candidato' => $verDatos,
                'reprogramar' => $evento->reprogramado_a_id === null && ($vigente || $evento->estado === EstadoAgendaEvento::NoAsistio),
                'cancelar' => $vigente,
                'capturar' => $vigente,
            ],
            'historial' => $conHistorial ? $evento->historial()->with('usuario:id,name')->get()->map(fn (AgendaEventoHistorial $h): array => [
                'accion' => $h->accion,
                'estado_nuevo' => $h->estado_nuevo !== null ? EstadoAgendaEvento::from($h->estado_nuevo)->etiqueta() : null,
                'detalle' => $h->detalle,
                'usuario' => $h->usuario?->name,
                'fecha' => $h->created_at?->setTimezone('America/Mexico_City')->format('Y-m-d H:i'),
            ])->all() : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros  desde, hasta, sucursal_id?
     * @return array<string, mixed>
     */
    public function indicadores(User $usuario, array $filtros): array
    {
        return $this->calcular($this->eventosParaIndicadores($usuario, $filtros));
    }

    /**
     * Récord por sucursal del periodo y, para comparar, el mes anterior a su inicio.
     *
     * @param  array<string, mixed>  $filtros
     * @return list<array<string, mixed>>
     */
    public function recordPorSucursal(User $usuario, array $filtros): array
    {
        $actuales = $this->eventosParaIndicadores($usuario, $filtros)->groupBy('sucursal_id');
        $inicio = Carbon::parse((string) ($filtros['desde'] ?? now()->startOfMonth()))->startOfMonth();
        $anteriores = $this->eventosParaIndicadores($usuario, [
            ...$filtros,
            'desde' => $inicio->copy()->subMonth()->toDateString(),
            'hasta' => $inicio->copy()->subDay()->toDateString(),
        ])->groupBy('sucursal_id');

        return array_values($actuales
            ->map(function (Collection $eventos, int|string $sucursalId) use ($anteriores): array {
                $primero = $eventos->first();

                return [
                    'sucursal_id' => (int) $sucursalId,
                    'sucursal' => $primero instanceof AgendaEvento ? $primero->sucursal->nombre : '',
                    ...$this->calcular($eventos),
                    'agendadas_mes_anterior' => $this->calcular($anteriores->get($sucursalId, collect()))['agendadas'],
                ];
            })
            ->sortByDesc('agendadas')
            ->all());
    }

    /**
     * Ninguna cancelada entra al cálculo; las reprogramadas se excluyen en calcular().
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, AgendaEvento>
     */
    private function eventosParaIndicadores(User $usuario, array $filtros): Collection
    {
        unset($filtros['estado'], $filtros['tipo'], $filtros['busqueda']);

        return $this->consulta($usuario, $filtros)
            ->where('estado', '!=', EstadoAgendaEvento::Cancelado->value)
            ->with('sucursal:id,nombre')
            ->get();
    }

    /**
     * @param  Collection<int, AgendaEvento>  $eventos
     * @return array<string, mixed>
     */
    private function calcular(Collection $eventos): array
    {
        $vigentes = $eventos->filter(fn (AgendaEvento $e): bool => $e->estado !== EstadoAgendaEvento::Reprogramado);
        $entrevistas = $vigentes->filter(fn (AgendaEvento $e): bool => $e->tipo === TipoAgendaEvento::Entrevista);
        $estudios = $vigentes->filter(fn (AgendaEvento $e): bool => $e->tipo === TipoAgendaEvento::Socioeconomico);

        $agendadas = $entrevistas->count();
        $realizadas = $entrevistas->filter(fn (AgendaEvento $e): bool => $e->estado === EstadoAgendaEvento::Realizado)->count();
        $noAsistio = $entrevistas->filter(fn (AgendaEvento $e): bool => $e->estado === EstadoAgendaEvento::NoAsistio)->count();
        $viables = $entrevistas->filter(fn (AgendaEvento $e): bool => $e->resultado === ResultadoAgendaEvento::Viable)->count();
        $porReclutamiento = $entrevistas->filter(fn (AgendaEvento $e): bool => $e->origen === OrigenAgendaEvento::Reclutamiento)->count();

        $dias = $vigentes
            ->filter(fn (AgendaEvento $e): bool => $e->estado === EstadoAgendaEvento::Realizado && $e->resultado_en !== null)
            ->map(fn (AgendaEvento $e): float => max(0.0, $e->fecha_hora->diffInMinutes($e->resultado_en, false) / 1440));

        return [
            'agendadas' => $agendadas,
            'realizadas' => $realizadas,
            'no_asistio' => $noAsistio,
            'viables' => $viables,
            'socioeconomicos_agendados' => $estudios->count(),
            'socioeconomicos_realizados' => $estudios->filter(fn (AgendaEvento $e): bool => $e->estado === EstadoAgendaEvento::Realizado)->count(),
            'pct_realizadas' => $this->porcentaje($realizadas, $agendadas),
            'pct_inasistencia' => $this->porcentaje($noAsistio, $realizadas + $noAsistio),
            'pct_viabilidad' => $this->porcentaje($viables, $realizadas),
            'pct_reclutamiento' => $this->porcentaje($porReclutamiento, $agendadas),
            'agendadas_reclutamiento' => $porReclutamiento,
            'dias_cita_resultado' => $dias->isNotEmpty() ? round((float) $dias->avg(), 1) : null,
        ];
    }

    private function porcentaje(int $parte, int $total): ?float
    {
        return $total > 0 ? round($parte / $total * 100, 1) : null;
    }
}
