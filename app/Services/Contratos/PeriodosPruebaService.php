<?php

namespace App\Services\Contratos;

use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoUsuario;
use App\Enums\TipoContratacion;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\Puesto;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Support\FechaMexico;
use App\Support\Paginacion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Periodos de prueba por vencer (Etapa 4 · Cierre del periodo de prueba):
 * quién sigue DENTRO de su periodo de prueba, detectado por su FECHA DE
 * INGRESO y la duración de su puesto — no depende de que el contrato ya esté
 * generado ni de que el scheduler haya abierto la evaluación. Alimenta la
 * pestaña «Próximas / por asignar» de «Evaluaciones de periodo de prueba»
 * (rh.evaluaciones.index; la antigua pantalla rh.periodos-prueba.index ahora
 * redirige ahí).
 *
 * Reglas (fuente en código):
 *  - vencimiento = SOLO fecha de ingreso + puestos.meses_periodo_prueba − 1 día
 *    (ContratoLaboralService::fechaFinPeriodoPrueba); el contrato solo se informa;
 *  - quien ya tiene contrato indeterminado vigente ya no está en periodo;
 *  - alerta: vence dentro de contratos.regularizacion.dias_alerta_periodo_prueba
 *    días NATURALES (15). El aviso automático que abre la evaluación
 *    (VencimientoContratosService) sigue en días HÁBILES; no se toca.
 *  - «Asignar evaluación» solo procede con contrato vigente con fecha de
 *    término (VencimientoContratosService::motivoNoAsignable dice por qué no).
 */
class PeriodosPruebaService
{
    public function __construct(
        private readonly ContratoLaboralService $contratos,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly VencimientoContratosService $vencimientos,
    ) {}

    public function puedeVer(User $usuario): bool
    {
        return $usuario->can('evaluaciones.capturar') || $usuario->can('evaluaciones.autorizar') || $usuario->can('evaluaciones.ver_todos');
    }

    /**
     * @param  array<string, mixed>  $filtros  busqueda, sucursal_id, solo_alerta
     * @return array{filas: LengthAwarePaginator<int, non-empty-array<string, mixed>>, resumen: array{en_periodo: int, en_alerta: int, sin_contrato: int, sin_duracion: int}, sin_duracion: list<array<string, mixed>>, dias_alerta: int}
     */
    public function listar(User $usuario, array $filtros = [], ?CarbonInterface $hoy = null): array
    {
        $calculo = $this->calcular($usuario, $filtros, $hoy);
        $todas = $calculo['filas'];

        $resumen = [
            'en_periodo' => $todas->count(),
            'en_alerta' => $todas->where('alerta', true)->count(),
            'sin_contrato' => $todas->where('tiene_contrato', false)->count(),
            'sin_duracion' => count($calculo['sin_duracion']),
        ];

        $visibles = filter_var($filtros['solo_alerta'] ?? false, FILTER_VALIDATE_BOOLEAN) ? $todas->where('alerta', true)->values() : $todas;

        return [
            'filas' => $this->paginar($visibles),
            'resumen' => $resumen,
            'sin_duracion' => $calculo['sin_duracion'],
            'dias_alerta' => $calculo['dias_alerta'],
        ];
    }

    /**
     * Pestaña «Próximas / por asignar»: quien sigue en periodo de prueba y
     * todavía NO tiene evaluación, ordenado por fecha de fin (los más
     * urgentes primero), con si ya se le puede asignar y, si no, por qué.
     *
     * @param  array<string, mixed>  $filtros  busqueda, sucursal_id, solo_alerta
     * @return array{filas: LengthAwarePaginator<int, non-empty-array<string, mixed>>, total: int, en_alerta: int, asignables: int, sin_duracion: list<array<string, mixed>>, dias_alerta: int}
     */
    public function porAsignar(User $usuario, array $filtros = [], ?CarbonInterface $hoy = null): array
    {
        $calculo = $this->calcular($usuario, $filtros, $hoy);
        $sinEvaluacion = $calculo['filas']->whereNull('evaluacion')->values();
        $visibles = filter_var($filtros['solo_alerta'] ?? false, FILTER_VALIDATE_BOOLEAN) ? $sinEvaluacion->where('alerta', true)->values() : $sinEvaluacion;

        return [
            'filas' => $this->paginar($visibles),
            'total' => $sinEvaluacion->count(),
            'en_alerta' => $sinEvaluacion->where('alerta', true)->count(),
            'asignables' => $sinEvaluacion->where('puede_asignar', true)->count(),
            'sin_duracion' => $calculo['sin_duracion'],
            'dias_alerta' => $calculo['dias_alerta'],
        ];
    }

    /**
     * Todas las filas (sin paginar) de quien sigue en periodo de prueba.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{filas: Collection<int, non-empty-array<string, mixed>>, sin_duracion: list<array<string, mixed>>, dias_alerta: int}
     */
    private function calcular(User $usuario, array $filtros, ?CarbonInterface $hoy): array
    {
        $hoy = CarbonImmutable::parse(($hoy ?? FechaMexico::inicioDeHoy())->toDateString());
        $diasAlerta = (int) config('contratos.regularizacion.dias_alerta_periodo_prueba', 15);
        $limiteAlerta = $hoy->addDays($diasAlerta);
        $maxMeses = max(1, (int) Puesto::query()->max('meses_periodo_prueba'));

        $colaboradores = $this->query($usuario, $filtros)
            ->whereNotNull('fecha_ingreso')
            ->whereDate('fecha_ingreso', '>=', $hoy->subMonthsNoOverflow($maxMeses)->toDateString())
            ->get();

        $contratos = ContratoLaboral::query()
            ->whereIn('colaborador_id', $colaboradores->pluck('id'))
            ->where('estado', EstadoContratoLaboral::Vigente->value)
            ->get()
            ->groupBy('colaborador_id');
        $evaluaciones = EvaluacionPeriodoPrueba::query()
            ->whereIn('colaborador_id', $colaboradores->pluck('id'))
            ->orderByDesc('id')
            ->get()
            ->unique('colaborador_id')
            ->keyBy('colaborador_id');

        $filas = [];
        $sinDuracion = [];

        foreach ($colaboradores as $colaborador) {
            /** @var Collection<int, ContratoLaboral> $vigentes */
            $vigentes = $contratos->get($colaborador->id, new Collection);

            if ($vigentes->contains(fn (ContratoLaboral $c): bool => $c->tipo === TipoContratacion::Indeterminado)) {
                continue;
            }

            $contrato = $vigentes->first(fn (ContratoLaboral $c): bool => $c->tipo->tieneVencimiento() && $c->fecha_fin !== null);
            // Mismo contrato que tomaría «Asignar evaluación»
            // (VencimientoContratosService::contratoEvaluable).
            $evaluable = $vigentes
                ->filter(fn (ContratoLaboral $c): bool => $c->fecha_fin !== null)
                ->sortBy(fn (ContratoLaboral $c): string => (string) $c->fecha_fin?->toDateString())
                ->first();
            $ingreso = CarbonImmutable::parse((string) $colaborador->fecha_ingreso?->toDateString());

            // Regla del negocio: SOLO fecha de ingreso + duración del puesto
            // (el contrato registrado, si lo hay, solo se informa).
            if ((int) ($colaborador->puesto->meses_periodo_prueba ?? 0) >= 1) {
                $fin = CarbonImmutable::parse($this->contratos->fechaFinPeriodoPrueba($colaborador->puesto_id, $ingreso)->toDateString());
                $fuente = 'fecha_ingreso';
            } else {
                $sinDuracion[] = $this->base($colaborador);

                continue;
            }

            if ($fin->lt($hoy)) {
                continue;
            }

            $evaluacion = $evaluaciones->get($colaborador->id);
            $motivo = $this->vencimientos->motivoNoAsignable($colaborador, $evaluable, $evaluacion instanceof EvaluacionPeriodoPrueba);
            $filas[] = [
                ...$this->base($colaborador),
                'fin_periodo' => $fin->toDateString(),
                'fuente' => $fuente,
                'fuente_etiqueta' => 'Calculado por fecha de ingreso',
                'dias_restantes' => (int) $hoy->diffInDays($fin, false),
                'dias_transcurridos' => (int) $ingreso->diffInDays($hoy, false),
                'alerta' => $fin->lte($limiteAlerta),
                'tiene_contrato' => $contrato !== null,
                'contrato_generado' => $contrato?->generated_document_id !== null,
                'fin_contrato' => $evaluable?->fecha_fin?->toDateString(),
                'puede_asignar' => $motivo === null,
                'motivo_no_asignable' => $motivo,
                'evaluacion' => $evaluacion instanceof EvaluacionPeriodoPrueba ? [
                    'id' => $evaluacion->id,
                    'estado' => $evaluacion->estado->value,
                    'estado_etiqueta' => $evaluacion->estado->etiqueta(),
                ] : null,
            ];
        }

        usort($filas, fn (array $a, array $b): int => strcmp((string) $a['fin_periodo'], (string) $b['fin_periodo']));

        return ['filas' => collect($filas), 'sin_duracion' => $sinDuracion, 'dias_alerta' => $diasAlerta];
    }

    /**
     * @param  Collection<int, non-empty-array<string, mixed>>  $filas
     * @return LengthAwarePaginator<int, non-empty-array<string, mixed>>
     */
    private function paginar(Collection $filas): LengthAwarePaginator
    {
        $porPagina = Paginacion::porPagina();
        $pagina = max(1, LengthAwarePaginator::resolveCurrentPage());
        $paginador = new LengthAwarePaginator(
            $filas->forPage($pagina, $porPagina)->values(),
            $filas->count(),
            $porPagina,
            $pagina,
            ['path' => LengthAwarePaginator::resolveCurrentPath()],
        );

        return $paginador->withQueryString();
    }

    /**
     * Colaboradores vigentes (activos o en incorporación) dentro del alcance:
     * mismo criterio que el listado de evaluaciones (quien no autoriza ve a
     * su equipo; con autorización o lectura global, el alcance del módulo).
     *
     * @param  array<string, mixed>  $filtros
     * @return Builder<Colaborador>
     */
    private function query(User $usuario, array $filtros): Builder
    {
        $busqueda = isset($filtros['busqueda']) ? trim((string) $filtros['busqueda']) : '';
        $sucursalId = isset($filtros['sucursal_id']) ? (int) $filtros['sucursal_id'] : 0;

        $query = Colaborador::query()
            ->whereIn('estatus', [EstadoUsuario::Activo->value, EstadoUsuario::EnIncorporacion->value])
            ->with(['puesto', 'sucursalPrincipal', 'jefe:id,name,apellidos', 'gerente:id,name,apellidos']);

        if (! $usuario->can(EvaluacionPeriodoPruebaService::PERMISO_AUTORIZAR) && ! $usuario->can('evaluaciones.ver_todos')) {
            $query->where(fn (Builder $q) => $q
                ->where('jefe_id', $usuario->colaborador_id ?? 0)
                ->orWhere('gerente_id', $usuario->colaborador_id ?? 0));
        } else {
            $this->alcance->limitarPorModulo($query, $usuario, 'evaluaciones', 'id');
        }

        return $query
            ->when($busqueda !== '', fn (Builder $q) => $q->buscar($busqueda))
            ->when($sucursalId > 0, fn (Builder $q) => $q->where('sucursal_principal_id', $sucursalId));
    }

    /**
     * @return array<string, mixed>
     */
    private function base(Colaborador $colaborador): array
    {
        return [
            'id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'numero_empleado' => $colaborador->numero_empleado,
            'puesto' => $colaborador->puesto?->nombre,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
            'jefe' => $colaborador->jefe?->nombreCompleto(),
            // Quien recibirá la evaluación: el jefe directo del organigrama
            // o, si no hay, su gerente (VencimientoContratosService).
            'evaluador' => ($colaborador->jefe ?? $colaborador->gerente)?->nombreCompleto(),
            'fecha_ingreso' => $colaborador->fecha_ingreso?->toDateString(),
            'meses_periodo_prueba' => $colaborador->puesto?->meses_periodo_prueba,
            'ciclo_url' => sprintf('/rh/colaboradores/%d/ciclo', $colaborador->id),
        ];
    }
}
