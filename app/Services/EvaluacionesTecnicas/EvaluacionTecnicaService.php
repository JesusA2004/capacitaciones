<?php

namespace App\Services\EvaluacionesTecnicas;

use App\Enums\ClaveFormularioEvaluacion;
use App\Enums\EstadoEvaluacionTecnica;
use App\Enums\QuienContestaEvaluacionTecnica;
use App\Models\Colaborador;
use App\Models\EvaluacionTecnica;
use App\Models\PasoEvaluacionTecnica;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Colaboradores\FechasLaboralesService;
use App\Services\Colaboradores\FotoColaboradorService;
use App\Services\Evaluaciones\FormularioEvaluacionService;
use App\Support\FechaMexico;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Evaluaciones técnicas («Seguimiento N»): consultar (tablero, línea de
 * tiempo por colaborador, mis evaluaciones), contestar y calificar. El
 * alcance de lo que ve cada quien es el del módulo «evaluaciones_tecnicas»
 * de Roles y permisos (AlcanceOrganizacionalService::limitarPorModulo).
 */
class EvaluacionTecnicaService
{
    public const MODULO = 'evaluaciones_tecnicas';

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly FormularioEvaluacionService $formularios,
        private readonly DefinicionFormularioTecnicoService $definicion,
        private readonly AvisosEvaluacionTecnicaService $avisos,
        private readonly AuditoriaService $auditoria,
        private readonly FotoColaboradorService $fotos,
        private readonly FechasLaboralesService $fechas,
    ) {}

    /**
     * @return Builder<EvaluacionTecnica>
     */
    public function visibles(User $usuario): Builder
    {
        return $this->alcance->limitarPorModulo(EvaluacionTecnica::query(), $usuario, self::MODULO);
    }

    public function alcanza(User $usuario, EvaluacionTecnica $evaluacion): bool
    {
        $evaluacion->loadMissing('colaborador');

        return $this->alcance->alcanzaColaboradorEnModulo($usuario, $evaluacion->colaborador, self::MODULO);
    }

    // ---------------------------------------------------------------------
    // Tablero
    // ---------------------------------------------------------------------

    /**
     * @param  array{buscar?: string|null, estado?: string|null, puesto_id?: int|null, sucursal_id?: int|null, contesta?: string|null}  $filtros
     * @return array<string, mixed>
     */
    public function tablero(User $usuario, array $filtros): array
    {
        $hoy = CarbonImmutable::parse(FechaMexico::hoy());
        $alerta = max(1, (int) config('evaluaciones_tecnicas.dias_alerta_vencimiento', 3));
        $base = fn (): Builder => $this->visibles($usuario);

        $pendientes = (clone $base())->where('estado', EstadoEvaluacionTecnica::Pendiente->value);
        $contestadas = (clone $base())->where('estado', EstadoEvaluacionTecnica::Contestada->value);

        $kpis = [
            'pendientes' => (clone $pendientes)->whereDate('fecha_limite', '>=', $hoy->toDateString())->count(),
            'vencidas' => (clone $pendientes)->whereDate('fecha_limite', '<', $hoy->toDateString())->count(),
            'por_vencer' => (clone $pendientes)->whereBetween('fecha_limite', [$hoy->toDateString(), $hoy->addDays($alerta)->toDateString()])->count(),
            'contestadas_30' => (clone $contestadas)->where('contestada_en', '>=', $hoy->subDays(30))->count(),
            'aprobacion' => $this->porcentaje((clone $contestadas)->where('aprobada', true)->count(), (clone $contestadas)->whereNotNull('aprobada')->count()),
            'promedio' => ($promedio = (clone $contestadas)->avg('calificacion')) !== null ? round((float) $promedio, 1) : null,
        ];

        $porPuesto = (clone $contestadas)
            ->join('puestos', 'puestos.id', '=', 'evaluaciones_tecnicas.puesto_id')
            ->groupBy('puestos.id', 'puestos.nombre')
            ->selectRaw('puestos.id as id, puestos.nombre as nombre, count(*) as contestadas, sum(case when aprobada = 1 then 1 else 0 end) as aprobadas, avg(calificacion) as promedio')
            ->orderByDesc('contestadas')
            ->limit(12)
            ->toBase()
            ->get()
            ->map(fn (object $f): array => [
                'id' => (int) $f->id,
                'nombre' => (string) $f->nombre,
                'contestadas' => (int) $f->contestadas,
                'aprobadas' => (int) $f->aprobadas,
                'aprobacion' => $this->porcentaje((int) $f->aprobadas, (int) $f->contestadas),
                'promedio' => $f->promedio !== null ? round((float) $f->promedio, 1) : null,
            ])
            ->values()
            ->all();

        $porDia = (clone $contestadas)
            ->groupBy('dias')
            ->selectRaw('dias, count(*) as contestadas, avg(calificacion) as promedio, sum(case when aprobada = 1 then 1 else 0 end) as aprobadas')
            ->orderBy('dias')
            ->toBase()
            ->get()
            ->map(fn (object $f): array => [
                'dias' => (int) $f->dias,
                'nombre' => PasoEvaluacionTecnica::nombrePorDefecto((int) $f->dias),
                'contestadas' => (int) $f->contestadas,
                'aprobacion' => $this->porcentaje((int) $f->aprobadas, (int) $f->contestadas),
                'promedio' => $f->promedio !== null ? round((float) $f->promedio, 1) : null,
            ])
            ->values()
            ->all();

        $conRelaciones = ['colaborador:id,name,apellidos,foto_path,sucursal_principal_id,puesto_id', 'colaborador.sucursalPrincipal:id,nombre', 'puesto:id,nombre', 'evaluador:id,name,apellidos'];

        return [
            'kpis' => $kpis,
            'por_puesto' => $porPuesto,
            'por_dia' => $porDia,
            'por_vencer' => array_values((clone $pendientes)
                ->whereDate('fecha_limite', '>=', $hoy->toDateString())
                ->with($conRelaciones)
                ->orderBy('fecha_limite')
                ->limit(8)
                ->get()
                ->map(fn (EvaluacionTecnica $e): array => $this->fila($e, $hoy))
                ->all()),
            'vencidas' => array_values((clone $pendientes)
                ->whereDate('fecha_limite', '<', $hoy->toDateString())
                ->with($conRelaciones)
                ->orderBy('fecha_limite')
                ->limit(8)
                ->get()
                ->map(fn (EvaluacionTecnica $e): array => $this->fila($e, $hoy))
                ->all()),
            'lista' => $this->lista($usuario, $filtros, $hoy),
        ];
    }

    /**
     * @param  array{buscar?: string|null, estado?: string|null, puesto_id?: int|null, sucursal_id?: int|null, contesta?: string|null}  $filtros
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function lista(User $usuario, array $filtros, CarbonImmutable $hoy): LengthAwarePaginator
    {
        $query = $this->visibles($usuario)
            ->with(['colaborador:id,name,apellidos,foto_path,sucursal_principal_id,puesto_id', 'colaborador.sucursalPrincipal:id,nombre', 'puesto:id,nombre', 'evaluador:id,name,apellidos']);

        $buscar = trim((string) ($filtros['buscar'] ?? ''));

        if ($buscar !== '') {
            $query->where(fn (Builder $q) => $q
                ->where('nombre', 'like', sprintf('%%%s%%', $buscar))
                ->orWhereIn('colaborador_id', Colaborador::query()->buscar($buscar)->select('id')));
        }

        match ($filtros['estado'] ?? null) {
            'pendiente' => $query->where('estado', EstadoEvaluacionTecnica::Pendiente->value)->whereDate('fecha_limite', '>=', $hoy->toDateString()),
            'vencida' => $query->where('estado', EstadoEvaluacionTecnica::Pendiente->value)->whereDate('fecha_limite', '<', $hoy->toDateString()),
            'aprobada' => $query->where('estado', EstadoEvaluacionTecnica::Contestada->value)->where('aprobada', true),
            'no_aprobada' => $query->where('estado', EstadoEvaluacionTecnica::Contestada->value)->where('aprobada', false),
            'cancelada' => $query->where('estado', EstadoEvaluacionTecnica::Cancelada->value),
            default => $query->where('estado', '!=', EstadoEvaluacionTecnica::Cancelada->value),
        };

        if (! empty($filtros['puesto_id'])) {
            $query->where('puesto_id', (int) $filtros['puesto_id']);
        }

        if (! empty($filtros['sucursal_id'])) {
            $query->whereHas('colaborador', fn (Builder $c) => $c->where('sucursal_principal_id', (int) $filtros['sucursal_id']));
        }

        if (QuienContestaEvaluacionTecnica::tryFrom((string) ($filtros['contesta'] ?? '')) !== null) {
            $query->where('contesta', (string) $filtros['contesta']);
        }

        return $query
            ->orderByRaw('case when estado = ? then 0 else 1 end', [EstadoEvaluacionTecnica::Pendiente->value])
            ->orderBy('fecha_limite')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (EvaluacionTecnica $e): array => $this->fila($e, $hoy));
    }

    // ---------------------------------------------------------------------
    // Línea de tiempo por colaborador
    // ---------------------------------------------------------------------

    /**
     * Las evaluaciones generadas + los pasos del calendario de su puesto que
     * aún no llegan (programadas), en orden de día.
     *
     * @return array<string, mixed>
     */
    public function lineaDeTiempo(Colaborador $colaborador): array
    {
        $hoy = CarbonImmutable::parse(FechaMexico::hoy());
        $colaborador->loadMissing(['puesto:id,nombre,meses_periodo_prueba', 'sucursalPrincipal:id,nombre', 'jefe:id,name,apellidos']);

        $evaluaciones = EvaluacionTecnica::query()
            ->where('colaborador_id', $colaborador->id)
            ->with(['puesto:id,nombre', 'evaluador:id,name,apellidos', 'contestadaPor:id,name,apellidos'])
            ->orderBy('fecha_programada')
            ->get();

        $hitos = $evaluaciones->map(fn (EvaluacionTecnica $e): array => $this->fila($e, $hoy))->all();
        $generados = $evaluaciones->where('puesto_id', $colaborador->puesto_id)->pluck('dias')->all();

        // Día N = inicio de capacitación inicial (o ingreso) + N días.
        $ingreso = $this->fechas->baseSeguimientos($colaborador);

        if ($colaborador->puesto_id !== null && $ingreso !== null) {

            foreach (PasoEvaluacionTecnica::query()->where('puesto_id', $colaborador->puesto_id)->where('activo', true)->orderBy('dias')->get() as $paso) {
                if (in_array($paso->dias, $generados, true)) {
                    continue;
                }

                $fecha = $ingreso->addDays($paso->dias);
                $hitos[] = [
                    'id' => null,
                    'nombre' => $paso->nombre,
                    'dias' => $paso->dias,
                    'es_final' => $paso->es_final,
                    'estado' => $fecha->lt($hoy) ? 'no_generada' : 'programada',
                    'estado_etiqueta' => $fecha->lt($hoy) ? 'No se generó (ya había pasado al configurarse)' : 'Programada',
                    'fecha_programada' => $fecha->toDateString(),
                    'fecha_limite' => $fecha->addDays($paso->dias_para_contestar)->toDateString(),
                    'contesta' => $paso->contesta->value,
                    'contesta_etiqueta' => $paso->contesta->corta(),
                    'calificacion_minima' => (float) $paso->calificacion_minima,
                    'calificacion' => null,
                    'aprobada' => null,
                    'dias_restantes' => (int) $hoy->diffInDays($fecha, false),
                ];
            }
        }

        usort($hitos, fn (array $a, array $b): int => [$a['dias'], (string) $a['fecha_programada']] <=> [$b['dias'], (string) $b['fecha_programada']]);

        $contestadas = $evaluaciones->where('estado', EstadoEvaluacionTecnica::Contestada);

        return [
            'colaborador' => [
                'id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'foto_url' => $this->fotos->url($colaborador),
                'puesto' => $colaborador->puesto?->nombre,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'jefe' => $colaborador->jefe?->nombreCompleto(),
                'fecha_ingreso' => $colaborador->fecha_ingreso?->toDateString(),
                'dias_desde_ingreso' => $colaborador->fecha_ingreso !== null ? (int) CarbonImmutable::parse($colaborador->fecha_ingreso)->startOfDay()->diffInDays($hoy) : null,
                // Aditivo: desde dónde corren los días de los Seguimientos.
                'inicio_capacitacion' => $ingreso?->toDateString(),
            ],
            'hitos' => $hitos,
            'resumen' => [
                'contestadas' => $contestadas->count(),
                'aprobadas' => $contestadas->where('aprobada', true)->count(),
                'promedio' => $contestadas->isNotEmpty() ? round((float) $contestadas->avg(fn (EvaluacionTecnica $e): float => (float) $e->calificacion), 1) : null,
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Contestar
    // ---------------------------------------------------------------------

    /**
     * Lo que ve quien contesta: diseño + preguntas, sin respuestas correctas.
     *
     * @return array<string, mixed>
     */
    public function contenido(EvaluacionTecnica $evaluacion): array
    {
        $definicion = $this->definicionDe($evaluacion);

        return [
            ...$this->formularios->presentacion(ClaveFormularioEvaluacion::Tecnica, $definicion),
            ...$this->definicion->contenidoPublico($definicion),
        ];
    }

    /**
     * @param  array<mixed>  $respuestas
     */
    public function contestar(EvaluacionTecnica $evaluacion, User $actor, array $respuestas, ?string $comentarios): EvaluacionTecnica
    {
        $contestada = DB::transaction(function () use ($evaluacion, $actor, $respuestas, $comentarios): EvaluacionTecnica {
            $evaluacion = EvaluacionTecnica::query()->lockForUpdate()->findOrFail($evaluacion->id);

            if (! $evaluacion->estaPendiente()) {
                throw ValidationException::withMessages(['evaluacion' => 'Esta evaluación ya se contestó o se canceló.']);
            }

            $resultado = $this->definicion->calificar($this->definicionDe($evaluacion), $respuestas);
            $calificacion = $resultado['calificacion'];

            $evaluacion->update([
                'estado' => EstadoEvaluacionTecnica::Contestada,
                'respuestas' => ['valores' => $resultado['respuestas'], 'detalle' => $resultado['detalle']],
                'puntos_obtenidos' => $resultado['puntos_obtenidos'],
                'puntos_totales' => $resultado['puntos_totales'],
                'calificacion' => $calificacion,
                'aprobada' => $calificacion !== null ? $calificacion >= (float) $evaluacion->calificacion_minima : null,
                'comentarios' => $comentarios !== null && trim($comentarios) !== '' ? mb_substr(trim($comentarios), 0, 3000) : null,
                'contestada_en' => now(),
                'contestada_por' => $actor->id,
            ]);

            return $evaluacion;
        });

        $this->auditoria->registrar('evaluacion_tecnica_contestada', $contestada, $actor, [
            'nombre' => $contestada->nombre,
            'calificacion' => $contestada->calificacion,
            'aprobada' => $contestada->aprobada,
        ]);

        $this->avisos->contestada($contestada);

        return $contestada;
    }

    /**
     * Resultado de una evaluación. Con $conRespuestas (RH / evaluador /
     * quien la ve con alcance) incluye cada respuesta y si fue correcta.
     *
     * @return array<string, mixed>
     */
    public function detalle(EvaluacionTecnica $evaluacion, bool $conRespuestas): array
    {
        $hoy = CarbonImmutable::parse(FechaMexico::hoy());
        $evaluacion->loadMissing(['colaborador.puesto:id,nombre', 'colaborador.sucursalPrincipal:id,nombre', 'puesto:id,nombre', 'evaluador:id,name,apellidos', 'contestadaPor:id,name,apellidos']);
        $preguntas = [];

        if ($conRespuestas && $evaluacion->estado === EstadoEvaluacionTecnica::Contestada) {
            $definicion = $this->definicionDe($evaluacion);
            $valores = (array) ($evaluacion->respuestas['valores'] ?? []);
            $detalle = (array) ($evaluacion->respuestas['detalle'] ?? []);

            foreach ((array) $definicion['secciones'] as $s) {
                foreach ((array) $s['preguntas'] as $p) {
                    $clave = (string) $p['clave'];
                    $opciones = collect((array) $p['opciones'])->pluck('texto', 'clave');
                    $valor = $valores[$clave] ?? null;
                    $preguntas[] = [
                        'seccion' => $s['titulo'],
                        'enunciado' => $p['enunciado'],
                        'tipo' => $p['tipo'],
                        'respuesta' => $this->textoRespuesta($valor, $opciones),
                        'correcta' => $this->textoRespuesta($p['correctas'] === [] ? null : $p['correctas'], $opciones),
                        'obtenidos' => $detalle[$clave]['obtenidos'] ?? null,
                        'maximos' => $detalle[$clave]['maximos'] ?? null,
                        'acierto' => $detalle[$clave]['correcta'] ?? null,
                    ];
                }
            }
        }

        return [
            ...$this->fila($evaluacion, $hoy),
            'puntos_obtenidos' => $evaluacion->puntos_obtenidos !== null ? (float) $evaluacion->puntos_obtenidos : null,
            'puntos_totales' => $evaluacion->puntos_totales !== null ? (float) $evaluacion->puntos_totales : null,
            'comentarios' => $conRespuestas ? $evaluacion->comentarios : null,
            'contestada_por' => $evaluacion->contestadaPor?->nombreCompleto(),
            'motivo_cancelacion' => $evaluacion->motivo_cancelacion,
            'preguntas' => $preguntas,
        ];
    }

    /**
     * Resultado que ve el propio colaborador: sin respuestas y, si el
     * formulario no muestra resultados, sin calificación.
     *
     * @return array<string, mixed>
     */
    public function detalleParaDueno(EvaluacionTecnica $evaluacion): array
    {
        $evaluacion->loadMissing('formulario');

        $detalle = $this->detalle($evaluacion, false);
        $propia = $this->filaPropia($evaluacion, CarbonImmutable::parse(FechaMexico::hoy()));

        if ($propia['calificacion'] === null) {
            $detalle['puntos_obtenidos'] = null;
            $detalle['puntos_totales'] = null;
        }

        return [...$detalle, ...array_intersect_key($propia, array_flip(['calificacion', 'aprobada', 'estado', 'estado_etiqueta']))];
    }

    // ---------------------------------------------------------------------
    // Mi espacio y bandeja del evaluador
    // ---------------------------------------------------------------------

    /**
     * Las evaluaciones propias del colaborador (las que él contesta y los
     * resultados que puede ver).
     *
     * @return array<string, mixed>
     */
    public function misEvaluaciones(User $usuario): array
    {
        $hoy = CarbonImmutable::parse(FechaMexico::hoy());
        $colaboradorId = $usuario->colaborador_id;

        if ($colaboradorId === null) {
            return ['pendientes' => [], 'historial' => [], 'por_evaluar' => []];
        }

        $propias = EvaluacionTecnica::query()
            ->where('colaborador_id', $colaboradorId)
            ->where('estado', '!=', EstadoEvaluacionTecnica::Cancelada->value)
            ->with(['puesto:id,nombre', 'evaluador:id,name,apellidos', 'formulario:id,definicion'])
            ->orderByDesc('fecha_programada')
            ->get();

        return [
            'pendientes' => array_values($propias
                ->filter(fn (EvaluacionTecnica $e): bool => $e->estaPendiente() && $e->contesta === QuienContestaEvaluacionTecnica::Colaborador)
                ->sortBy('fecha_limite')
                ->map(fn (EvaluacionTecnica $e): array => $this->fila($e, $hoy))
                ->all()),
            'historial' => array_values($propias
                ->filter(fn (EvaluacionTecnica $e): bool => $e->estado === EstadoEvaluacionTecnica::Contestada)
                ->map(fn (EvaluacionTecnica $e): array => $this->filaPropia($e, $hoy))
                ->all()),
            'por_evaluar' => $this->porEvaluar($usuario, $hoy),
        ];
    }

    /**
     * Pendientes que este usuario debe contestar como jefe directo.
     *
     * @return list<array<string, mixed>>
     */
    public function porEvaluar(User $usuario, ?CarbonImmutable $hoy = null): array
    {
        $hoy ??= CarbonImmutable::parse(FechaMexico::hoy());

        if ($usuario->colaborador_id === null) {
            return [];
        }

        return array_values(EvaluacionTecnica::query()
            ->where('evaluador_colaborador_id', $usuario->colaborador_id)
            ->where('contesta', QuienContestaEvaluacionTecnica::JefeDirecto->value)
            ->where('estado', EstadoEvaluacionTecnica::Pendiente->value)
            ->with(['colaborador:id,name,apellidos,foto_path,sucursal_principal_id,puesto_id', 'colaborador.sucursalPrincipal:id,nombre', 'puesto:id,nombre'])
            ->orderBy('fecha_limite')
            ->get()
            ->map(fn (EvaluacionTecnica $e): array => $this->fila($e, $hoy))
            ->all());
    }

    // ---------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function fila(EvaluacionTecnica $e, CarbonImmutable $hoy): array
    {
        $vencida = $e->estaVencida($hoy);
        [$estado, $etiqueta] = match (true) {
            $e->estado === EstadoEvaluacionTecnica::Cancelada => ['cancelada', 'Cancelada'],
            $e->estado === EstadoEvaluacionTecnica::Contestada && $e->aprobada === true => ['aprobada', 'Aprobada'],
            $e->estado === EstadoEvaluacionTecnica::Contestada && $e->aprobada === false => ['no_aprobada', 'No aprobada'],
            $e->estado === EstadoEvaluacionTecnica::Contestada => ['contestada', 'Contestada'],
            $vencida => ['vencida', 'Vencida'],
            default => ['pendiente', 'Pendiente'],
        };

        $colaborador = $e->relationLoaded('colaborador') ? $e->colaborador : null;

        return [
            'id' => $e->id,
            'nombre' => $e->nombre,
            'dias' => $e->dias,
            'es_final' => $e->es_final,
            'estado' => $estado,
            'estado_etiqueta' => $etiqueta,
            'fecha_programada' => $e->fecha_programada->toDateString(),
            'fecha_limite' => $e->fecha_limite->toDateString(),
            'dias_restantes' => (int) $hoy->diffInDays(CarbonImmutable::parse($e->fecha_limite), false),
            'contesta' => $e->contesta->value,
            'contesta_etiqueta' => $e->contesta->corta(),
            'calificacion_minima' => (float) $e->calificacion_minima,
            'calificacion' => $e->calificacion !== null ? (float) $e->calificacion : null,
            'aprobada' => $e->aprobada,
            'contestada_en' => $e->contestada_en?->toIso8601String(),
            'puesto' => $e->relationLoaded('puesto') ? $e->puesto?->nombre : null,
            'evaluador' => $e->relationLoaded('evaluador') ? $e->evaluador?->nombreCompleto() : null,
            'colaborador' => $colaborador !== null ? [
                'id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'foto_url' => $this->fotos->url($colaborador),
                'sucursal' => $colaborador->relationLoaded('sucursalPrincipal') ? $colaborador->sucursalPrincipal?->nombre : null,
            ] : null,
        ];
    }

    /**
     * Fila del historial propio: la calificación solo si el formulario lo permite.
     *
     * @return array<string, mixed>
     */
    private function filaPropia(EvaluacionTecnica $e, CarbonImmutable $hoy): array
    {
        $fila = $this->fila($e, $hoy);
        $mostrar = $e->contesta === QuienContestaEvaluacionTecnica::Colaborador
            && filter_var($e->formulario->definicion['mostrar_resultado'] ?? true, FILTER_VALIDATE_BOOLEAN);

        if (! $mostrar) {
            $fila['calificacion'] = null;
            $fila['aprobada'] = null;
            $fila['estado'] = 'contestada';
            $fila['estado_etiqueta'] = 'Contestada';
        }

        return $fila;
    }

    /**
     * @return array<string, mixed>
     */
    private function definicionDe(EvaluacionTecnica $evaluacion): array
    {
        $evaluacion->loadMissing('formulario');

        if ($evaluacion->formulario === null) {
            throw ValidationException::withMessages(['evaluacion' => 'El formulario de esta evaluación ya no existe. Avisa a Recursos Humanos.']);
        }

        return $this->formularios->definicionDe(ClaveFormularioEvaluacion::Tecnica, $evaluacion->formulario);
    }

    /**
     * @param  Collection<array-key, mixed>  $opciones
     */
    private function textoRespuesta(mixed $valor, Collection $opciones): ?string
    {
        if ($valor === null) {
            return null;
        }

        if (is_array($valor)) {
            return implode(' · ', array_map(fn (mixed $v): string => (string) ($opciones[(string) $v] ?? $v), $valor));
        }

        return (string) ($opciones[(string) $valor] ?? $valor);
    }

    private function porcentaje(int $parte, int $total): ?float
    {
        return $total > 0 ? round($parte / $total * 100, 1) : null;
    }
}
