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
 * «Periodos por vencer» (Etapa 4 · Cierre del periodo de prueba): quién
 * sigue DENTRO de su periodo de prueba, detectado por su FECHA DE INGRESO y
 * la duración de su puesto — no depende de que el contrato ya esté generado
 * ni de que el scheduler haya abierto la evaluación.
 *
 * Reglas (fuente en código):
 *  - vencimiento = SOLO fecha de ingreso + puestos.meses_periodo_prueba − 1 día
 *    (ContratoLaboralService::fechaFinPeriodoPrueba); el contrato solo se informa;
 *  - quien ya tiene contrato indeterminado vigente ya no está en periodo;
 *  - alerta: vence dentro de contratos.regularizacion.dias_alerta_periodo_prueba
 *    días NATURALES (15). El aviso automático que abre la evaluación
 *    (VencimientoContratosService) sigue en días HÁBILES; no se toca.
 */
class PeriodosPruebaService
{
    public function __construct(
        private readonly ContratoLaboralService $contratos,
        private readonly AlcanceOrganizacionalService $alcance,
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
                'evaluacion' => $evaluacion instanceof EvaluacionPeriodoPrueba ? [
                    'id' => $evaluacion->id,
                    'estado' => $evaluacion->estado->value,
                    'estado_etiqueta' => $evaluacion->estado->etiqueta(),
                ] : null,
            ];
        }

        usort($filas, fn (array $a, array $b): int => strcmp((string) $a['fin_periodo'], (string) $b['fin_periodo']));
        $todas = collect($filas);
        $resumen = [
            'en_periodo' => $todas->count(),
            'en_alerta' => $todas->where('alerta', true)->count(),
            'sin_contrato' => $todas->where('tiene_contrato', false)->count(),
            'sin_duracion' => count($sinDuracion),
        ];

        $visibles = filter_var($filtros['solo_alerta'] ?? false, FILTER_VALIDATE_BOOLEAN) ? $todas->where('alerta', true)->values() : $todas;
        $porPagina = Paginacion::porPagina();
        $pagina = max(1, LengthAwarePaginator::resolveCurrentPage());
        $paginador = new LengthAwarePaginator(
            $visibles->forPage($pagina, $porPagina)->values(),
            $visibles->count(),
            $porPagina,
            $pagina,
            ['path' => LengthAwarePaginator::resolveCurrentPath()],
        );

        return [
            'filas' => $paginador->withQueryString(),
            'resumen' => $resumen,
            'sin_duracion' => $sinDuracion,
            'dias_alerta' => $diasAlerta,
        ];
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
            ->with(['puesto', 'sucursalPrincipal', 'jefe:id,name,apellidos']);

        if (! $usuario->can(EvaluacionPeriodoPruebaService::PERMISO_AUTORIZAR) && ! $usuario->can('evaluaciones.ver_todos')) {
            $query->where(fn (Builder $q) => $q
                ->where('jefe_id', $usuario->colaborador_id ?? 0)
                ->orWhere('gerente_id', $usuario->colaborador_id ?? 0));
        } else {
            $this->alcance->limitarPorModulo($query, $usuario, 'evaluaciones', 'id');
        }

        return $query
            ->when($busqueda !== '', fn (Builder $q) => $q->where(function (Builder $sub) use ($busqueda): void {
                $sub->where('name', 'like', "%{$busqueda}%")
                    ->orWhere('apellidos', 'like', "%{$busqueda}%")
                    ->orWhere('numero_empleado', 'like', "%{$busqueda}%");
            }))
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
            'fecha_ingreso' => $colaborador->fecha_ingreso?->toDateString(),
            'meses_periodo_prueba' => $colaborador->puesto?->meses_periodo_prueba,
            'ciclo_url' => sprintf('/rh/colaboradores/%d/ciclo', $colaborador->id),
        ];
    }
}
