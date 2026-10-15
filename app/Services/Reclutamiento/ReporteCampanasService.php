<?php

namespace App\Services\Reclutamiento;

use App\Enums\AlcanceDatos;
use App\Enums\CanalReclutamiento;
use App\Enums\CriterioFechaReporteCampanas;
use App\Enums\EstadoCampanaReclutamiento;
use App\Enums\EstadoCandidato;
use App\Enums\MotorPdf;
use App\Exports\ReporteCampanasExport;
use App\Models\CampanaReclutamiento;
use App\Models\Candidato;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Pdf\PdfRendererException;
use App\Services\Pdf\PdfRendererFactory;
use App\Services\Permisos\AlcanceModuloService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reporte PDF (Chrome/Browsershot) de una o varias campañas de
 * reclutamiento: cuántos llegaron, hasta qué fase avanzaron, en qué fase se
 * detuvieron y por qué (motivo de catálogo agrupado por categoría), y cuánto
 * costó cada candidato y cada contratado.
 *
 * Solo agrega datos reales: los candidatos que RH registró con esa campaña
 * (`candidatos.campana_reclutamiento_id`). Contratados y costo por
 * colaborador salen de CostoReclutamientoService::porCampana() — la misma
 * cifra que muestra la pantalla de Campañas.
 */
class ReporteCampanasService
{
    public const TITULO = 'Reporte de campañas de reclutamiento';

    public function __construct(
        private readonly CostoReclutamientoService $costos,
        private readonly PdfRendererFactory $pdf,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AlcanceModuloService $alcanceModulo,
    ) {}

    /**
     * Reporte con filtros (pantalla, PDF y Excel salen de aquí):
     *
     * - Rango de fechas según el criterio elegido: campañas cuya vigencia se
     *   cruza con el rango, o candidatos registrados dentro del rango.
     * - Filtros de la campaña: campañas puntuales (vacío = todas, reporte
     *   general), canal, estado (programada/vigente/concluida), empresa,
     *   sucursal, departamento, puesto, vacante y responsable de RH.
     * - Filtros del candidato: fase del tablero.
     *
     * El gasto de campañas no se acota por sucursal (ver
     * CampanaReclutamientoController), pero los candidatos SÍ respetan el
     * alcance del usuario en el módulo de candidatos.
     *
     * @return array{campanas: list<array<string, mixed>>, totales: array<string, mixed>, filtros_aplicados: list<array{etiqueta: string, valor: string}>, periodo: string, criterio: string}
     */
    public function reporte(FiltrosReporteCampanas $filtros, User $usuario, bool $conCandidatos): array
    {
        $campanas = $this->consulta($filtros, $usuario)->get();
        $recontar = $filtros->acotaCandidatos() || $this->alcanceModulo->nivel($usuario, 'candidatos') !== AlcanceDatos::Todos;

        return [
            ...$this->datos($campanas, $conCandidatos, $this->acotarCandidatos($filtros, $usuario), $recontar),
            'filtros_aplicados' => $this->filtrosAplicados($filtros),
            'periodo' => $this->periodo($filtros),
            'criterio' => $filtros->criterio->explicacion(),
        ];
    }

    /**
     * Campañas que entran al reporte con los filtros dados.
     *
     * @return Builder<CampanaReclutamiento>
     */
    public function consulta(FiltrosReporteCampanas $filtros, User $usuario): Builder
    {
        $consulta = CampanaReclutamiento::query();

        if ($filtros->criterio === CriterioFechaReporteCampanas::Registro) {
            $consulta->whereHas('candidatos', $this->acotarCandidatos($filtros, $usuario));
        } else {
            $this->vigentesEnRango($consulta, $filtros->desde, $filtros->hasta);
        }

        if ($filtros->estados !== []) {
            $hoy = Carbon::today();
            $consulta->where(function (Builder $q) use ($filtros, $hoy): void {
                foreach ($filtros->estados as $estado) {
                    $q->orWhere(fn (Builder $s) => $this->enEstado($s, $estado, $hoy));
                }
            });
        }

        return $consulta
            ->when($filtros->campanaIds !== [], fn (Builder $q) => $q->whereIn('id', $filtros->campanaIds))
            ->when($filtros->canales !== [], fn (Builder $q) => $q->whereIn('canal', array_map(fn (CanalReclutamiento $c) => $c->value, $filtros->canales)))
            ->when($filtros->empresaId !== null, fn (Builder $q) => $q->where('empresa_id', $filtros->empresaId))
            ->when($filtros->sucursalId !== null, fn (Builder $q) => $q->where('sucursal_id', $filtros->sucursalId))
            ->when($filtros->departamentoId !== null, fn (Builder $q) => $q->where('departamento_id', $filtros->departamentoId))
            ->when($filtros->puestoId !== null, fn (Builder $q) => $q->where('puesto_id', $filtros->puestoId))
            ->when($filtros->vacanteId !== null, fn (Builder $q) => $q->where('vacante_id', $filtros->vacanteId))
            ->when($filtros->responsableId !== null, fn (Builder $q) => $q->where('responsable_id', $filtros->responsableId))
            ->orderBy('fecha_inicio')
            ->orderBy('id');
    }

    /**
     * Candidatos que cuentan en el reporte: alcance del usuario (módulo de
     * candidatos) + fecha de registro (criterio «registro») + fase.
     *
     * @return Closure(Builder<Candidato>): Builder<Candidato>
     */
    private function acotarCandidatos(FiltrosReporteCampanas $filtros, User $usuario): Closure
    {
        $estados = $filtros->estadosCandidato();

        return function (Builder $q) use ($filtros, $usuario, $estados): Builder {
            $this->alcance->limitarPorSucursal($q, $usuario, 'sucursal_id', 'candidatos');

            if ($filtros->criterio === CriterioFechaReporteCampanas::Registro) {
                $q->whereBetween('created_at', [$filtros->desde->copy()->startOfDay(), $filtros->hasta->copy()->endOfDay()]);
            }

            if ($filtros->fases !== []) {
                $q->whereIn('estado', $estados);
            }

            return $q;
        };
    }

    /**
     * Vigencia de la campaña cruzada con [desde, hasta]. Sin fecha de fin =
     * sigue vigente; sin fecha de inicio (histórico por mes/año) = su mes.
     *
     * @param  Builder<CampanaReclutamiento>  $consulta
     */
    private function vigentesEnRango(Builder $consulta, Carbon $desde, Carbon $hasta): void
    {
        $consulta->where(fn (Builder $w) => $w
            ->where(fn (Builder $s) => $s
                ->whereNotNull('fecha_inicio')
                ->whereDate('fecha_inicio', '<=', $hasta->toDateString())
                ->where(fn (Builder $f) => $f->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>=', $desde->toDateString())))
            ->orWhere(fn (Builder $s) => $s
                ->whereNull('fecha_inicio')
                ->whereRaw('(anio * 100 + mes) between ? and ?', [$this->claveMes($desde), $this->claveMes($hasta)])));
    }

    /**
     * Mismo criterio que EstadoCampanaReclutamiento::de(), en SQL.
     *
     * @param  Builder<CampanaReclutamiento>  $q
     * @return Builder<CampanaReclutamiento>
     */
    private function enEstado(Builder $q, EstadoCampanaReclutamiento $estado, Carbon $hoy): Builder
    {
        $fecha = $hoy->toDateString();
        $clave = $this->claveMes($hoy);

        return match ($estado) {
            EstadoCampanaReclutamiento::Programada => $q
                ->where(fn (Builder $s) => $s->whereNotNull('fecha_inicio')->whereDate('fecha_inicio', '>', $fecha))
                ->orWhere(fn (Builder $s) => $s->whereNull('fecha_inicio')->whereRaw('(anio * 100 + mes) > ?', [$clave])),
            EstadoCampanaReclutamiento::Concluida => $q
                ->where(fn (Builder $s) => $s->whereNotNull('fecha_inicio')->whereDate('fecha_inicio', '<=', $fecha)->whereNotNull('fecha_fin')->whereDate('fecha_fin', '<', $fecha))
                ->orWhere(fn (Builder $s) => $s->whereNull('fecha_inicio')->whereRaw('(anio * 100 + mes) < ?', [$clave])),
            EstadoCampanaReclutamiento::Vigente => $q
                ->where(fn (Builder $s) => $s->whereNotNull('fecha_inicio')->whereDate('fecha_inicio', '<=', $fecha)
                    ->where(fn (Builder $f) => $f->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>=', $fecha)))
                ->orWhere(fn (Builder $s) => $s->whereNull('fecha_inicio')->whereRaw('(anio * 100 + mes) = ?', [$clave])),
        };
    }

    private function claveMes(Carbon $fecha): int
    {
        return (int) $fecha->format('Y') * 100 + (int) $fecha->format('n');
    }

    public function periodo(FiltrosReporteCampanas $filtros): string
    {
        return sprintf('Del %s al %s', $filtros->desde->format('d/m/Y'), $filtros->hasta->format('d/m/Y'));
    }

    /**
     * Filtros en texto (encabezado del PDF/Excel y resumen en pantalla).
     * Siempre van el periodo, la fecha que se filtra y las campañas; el
     * resto solo si se eligió algo.
     *
     * @return list<array{etiqueta: string, valor: string}>
     */
    public function filtrosAplicados(FiltrosReporteCampanas $filtros): array
    {
        $lista = [
            ['etiqueta' => 'Periodo', 'valor' => $this->periodo($filtros)],
            ['etiqueta' => 'Fecha que se filtra', 'valor' => $filtros->criterio->etiqueta()],
            ['etiqueta' => 'Campañas', 'valor' => $filtros->campanaIds === []
                ? 'Todas (reporte general)'
                : implode(', ', CampanaReclutamiento::query()->whereIn('id', $filtros->campanaIds)->orderBy('id')->get()
                    ->map(fn (CampanaReclutamiento $c): string => $this->nombreCampana($c))->all())],
        ];

        $agregar = function (string $etiqueta, ?string $valor) use (&$lista): void {
            if ($valor !== null && $valor !== '') {
                $lista[] = ['etiqueta' => $etiqueta, 'valor' => $valor];
            }
        };

        $agregar('Canal / medio', implode(', ', array_map(fn (CanalReclutamiento $c) => $c->etiqueta(), $filtros->canales)));
        $agregar('Estado de la campaña', implode(', ', array_map(fn (EstadoCampanaReclutamiento $e) => $e->etiqueta(), $filtros->estados)));
        $fases = FiltrosReporteCampanas::fasesCandidato();
        $agregar('Fase del candidato', implode(', ', array_map(fn (string $f) => $fases[$f] ?? $f, $filtros->fases)));
        $agregar('Empresa', $filtros->empresaId !== null ? Empresa::query()->whereKey($filtros->empresaId)->value('nombre') : null);
        $agregar('Sucursal', $filtros->sucursalId !== null ? Sucursal::query()->whereKey($filtros->sucursalId)->value('nombre') : null);
        $agregar('Departamento', $filtros->departamentoId !== null ? Departamento::query()->whereKey($filtros->departamentoId)->value('nombre') : null);
        $agregar('Puesto', $filtros->puestoId !== null ? Puesto::query()->whereKey($filtros->puestoId)->value('nombre') : null);
        $agregar('Vacante', $filtros->vacanteId !== null ? $this->etiquetaVacante(Vacante::query()->with(['puesto:id,nombre', 'sucursal:id,nombre'])->where('id', $filtros->vacanteId)->first()) : null);
        $agregar('Responsable de RH', $filtros->responsableId !== null ? User::query()->where('id', $filtros->responsableId)->first()?->nombreCompleto() : null);

        return $lista;
    }

    /**
     * Catálogos para los filtros de la pantalla del reporte.
     *
     * @return array<string, mixed>
     */
    public function opciones(): array
    {
        $campanas = CampanaReclutamiento::query()
            ->orderByDesc('anio')->orderByDesc('mes')->orderByDesc('id')
            ->get(['id', 'nombre', 'canal', 'mes', 'anio', 'fecha_inicio', 'fecha_fin', 'vacante_id', 'responsable_id']);

        $vacantes = Vacante::query()
            ->whereIn('id', $campanas->pluck('vacante_id')->filter()->unique())
            ->with(['puesto:id,nombre', 'sucursal:id,nombre'])
            ->orderByDesc('id')
            ->get();

        return [
            'campanas' => array_values($campanas->map(fn (CampanaReclutamiento $c): array => [
                'id' => $c->id,
                'etiqueta' => sprintf('%s · %s · %s', $this->nombreCampana($c), $c->canal->etiqueta(), $this->vigencia($c)),
            ])->all()),
            'canales' => array_map(fn (CanalReclutamiento $c) => ['value' => $c->value, 'etiqueta' => $c->etiqueta()], CanalReclutamiento::cases()),
            'estados' => EstadoCampanaReclutamiento::opciones(),
            'criterios' => CriterioFechaReporteCampanas::opciones(),
            'fases' => array_map(fn (string $value, string $etiqueta) => ['value' => $value, 'etiqueta' => $etiqueta], array_keys(FiltrosReporteCampanas::fasesCandidato()), FiltrosReporteCampanas::fasesCandidato()),
            'empresas' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
            'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
            'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            'puestos' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre', 'departamento_id']),
            'vacantes' => array_values($vacantes->map(fn (Vacante $v): array => ['id' => $v->id, 'etiqueta' => $this->etiquetaVacante($v) ?? ''])->all()),
            // Quien lleva o puede llevar una campaña.
            'responsables' => array_values(User::query()
                ->where(fn (Builder $q) => $q
                    ->whereIn('id', $campanas->pluck('responsable_id')->filter()->unique())
                    ->orWhereIn('id', User::permission('reclutamiento.campanas.administrar')->select('id')))
                ->orderBy('name')
                ->get(['id', 'name', 'apellidos'])
                ->map(fn (User $u): array => ['id' => $u->id, 'nombre' => $u->nombreCompleto()])
                ->all()),
        ];
    }

    /**
     * Filas planas para el Excel (hoja de campañas y, si aplica, de candidatos).
     *
     * @param  array{campanas: list<array<string, mixed>>, totales: array<string, mixed>, filtros_aplicados: list<array{etiqueta: string, valor: string}>}  $reporte
     */
    public function excel(array $reporte, bool $conCandidatos): ReporteCampanasExport
    {
        $columnas = ['Campaña', 'Canal', 'Vacante', 'Responsable', 'Inicio', 'Fin', 'Estado', 'Presupuesto', 'Gasto', 'Llegaron', 'En proceso', 'No continuaron', 'Contratados', 'Conversión %', 'Costo por candidato', 'Costo por contratado'];
        $numero = fn (mixed $v): int|float|null => is_int($v) || is_float($v) ? $v : null;
        $texto = fn (mixed $v): ?string => is_string($v) ? $v : null;

        $filas = array_map(fn (array $c): array => [
            $texto($c['nombre']), $texto($c['canal']), $texto($c['vacante']), $texto($c['responsable']),
            $texto($c['fecha_inicio']), $texto($c['fecha_fin']) ?? 'Sin fecha de fin', $texto($c['estado']),
            $numero($c['presupuesto']), $numero($c['gasto']), $numero($c['llegaron']), $numero($c['en_proceso']),
            $numero($c['no_continuaron']), $numero($c['contratados']), $numero($c['conversion']),
            $numero($c['costo_por_candidato']), $numero($c['costo_por_contratado']),
        ], $reporte['campanas']);

        $t = $reporte['totales'];
        $filas[] = [
            'TOTAL', null, null, null, null, null, null,
            $numero($t['presupuesto']), $numero($t['gasto']), $numero($t['llegaron']), $numero($t['en_proceso']),
            $numero($t['no_continuaron']), $numero($t['contratados']), $numero($t['conversion']),
            $numero($t['costo_por_candidato']), $numero($t['costo_por_contratado']),
        ];

        $candidatos = null;

        if ($conCandidatos) {
            $candidatos = [];

            foreach ($reporte['campanas'] as $c) {
                /** @var list<array<string, mixed>> $suyos */
                $suyos = is_array($c['candidatos']) ? array_values($c['candidatos']) : [];

                foreach ($suyos as $cand) {
                    $candidatos[] = [$texto($c['nombre']), $texto($cand['nombre']), $texto($cand['registrado']), $texto($cand['fase_alcanzada']), $texto($cand['estado']), $texto($cand['motivo'])];
                }
            }
        }

        return new ReporteCampanasExport(
            self::TITULO,
            $reporte['filtros_aplicados'],
            $columnas,
            $filas,
            ['Campaña', 'Candidato', 'Registrado', 'Fase alcanzada', 'Situación', '¿Por qué no continuó?'],
            $candidatos,
        );
    }

    /**
     * PDF del reporte con filtros: el encabezado lista los filtros aplicados.
     *
     * @param  array{campanas: list<array<string, mixed>>, totales: array<string, mixed>, filtros_aplicados: list<array{etiqueta: string, valor: string}>, periodo: string}  $reporte
     *
     * @throws PdfRendererException
     */
    public function pdfDeReporte(array $reporte, bool $conCandidatos): string
    {
        return $this->renderizarPdf(
            ['campanas' => $reporte['campanas'], 'totales' => $reporte['totales']],
            self::TITULO,
            $reporte['periodo'],
            $conCandidatos,
            $reporte['filtros_aplicados'],
        );
    }

    private function nombreCampana(CampanaReclutamiento $campana): string
    {
        return $campana->nombre !== null && $campana->nombre !== '' ? $campana->nombre : $campana->canal->etiqueta();
    }

    private function vigencia(CampanaReclutamiento $campana): string
    {
        if ($campana->fecha_inicio === null) {
            return sprintf('%02d/%d', $campana->mes, $campana->anio);
        }

        return $campana->fecha_fin !== null
            ? sprintf('%s – %s', $campana->fecha_inicio->format('d/m/Y'), $campana->fecha_fin->format('d/m/Y'))
            : sprintf('Desde %s', $campana->fecha_inicio->format('d/m/Y'));
    }

    private function etiquetaVacante(?Vacante $vacante): ?string
    {
        return $vacante !== null
            ? sprintf('#%d · %s · %s', $vacante->id, $vacante->puesto->nombre ?? 'Sin puesto', $vacante->sucursal->nombre ?? 'Sin sucursal')
            : null;
    }

    /**
     * @param  iterable<CampanaReclutamiento>  $campanas
     *
     * @throws PdfRendererException
     */
    public function pdf(iterable $campanas, string $titulo, string $periodo, bool $conCandidatos): string
    {
        return $this->renderizarPdf($this->datos($campanas, $conCandidatos), $titulo, $periodo, $conCandidatos, []);
    }

    /**
     * @param  array{campanas: list<array<string, mixed>>, totales: array<string, mixed>}  $datos
     * @param  list<array{etiqueta: string, valor: string}>  $filtrosAplicados
     *
     * @throws PdfRendererException
     */
    private function renderizarPdf(array $datos, string $titulo, string $periodo, bool $conCandidatos, array $filtrosAplicados): string
    {
        $html = view('pdf.reporte-campanas', [
            ...$datos,
            'titulo' => $titulo,
            'periodo' => $periodo,
            'conCandidatos' => $conCandidatos,
            'filtrosAplicados' => $filtrosAplicados,
            'generado' => now()->format('d/m/Y H:i'),
        ])->render();

        return $this->pdf->renderizar(
            MotorPdf::Browsershot,
            $html,
            new OpcionesPdf(tamano: 'letter', orientacion: 'portrait', numerarPaginas: true, textoPie: 'MR. LANA PEOPLE · Reporte de campañas de reclutamiento'),
        )['pdf'];
    }

    /**
     * @param  iterable<CampanaReclutamiento>  $campanas
     * @param  (Closure(Builder<Candidato>): Builder<Candidato>)|null  $acotarCandidatos  Alcance/fecha/fase de los candidatos que cuentan.
     * @param  bool  $recontar  true cuando no cuentan TODOS los candidatos de cada campaña: los contratados (y su costo) se recuentan sobre el grupo filtrado en vez de la cifra general de la pantalla de Campañas.
     * @return array{campanas: list<array<string, mixed>>, totales: array<string, mixed>}
     */
    public function datos(iterable $campanas, bool $conCandidatos = true, ?Closure $acotarCandidatos = null, bool $recontar = false): array
    {
        $lista = new EloquentCollection(collect($campanas)->values()->all());
        $lista->loadMissing(['vacante.puesto:id,nombre', 'vacante.sucursal:id,nombre', 'responsable:id,name,apellidos']);

        $costos = $this->costos->porCampana($lista);
        $consulta = Candidato::query()->whereIn('campana_reclutamiento_id', $lista->pluck('id'));

        if ($acotarCandidatos !== null) {
            $acotarCandidatos($consulta);
        }

        $candidatos = $consulta
            ->with(['motivoRechazo:id,nombre,categoria', 'colaborador:id,activado_en'])
            ->orderBy('created_at')
            ->get()
            ->groupBy('campana_reclutamiento_id');

        $gasto = (float) $costos['gasto'];
        $contratadosTotal = (int) $costos['contratados'];
        $costoPorContratado = $costos['costo_por_colaborador'];

        if ($recontar) {
            $contratadosTotal = 0;
        }

        $filas = [];

        foreach ($lista as $campana) {
            /** @var Collection<int, Candidato> $suyos */
            $suyos = $candidatos->get($campana->id, collect());
            $costo = $costos['por_campana'][$campana->id] ?? [];

            if ($recontar) {
                $contratados = $suyos->filter(fn (Candidato $c): bool => $c->estado === EstadoCandidato::Contratado)->count();
                $contratadosTotal += $contratados;
                $costo['contratados'] = $contratados;
                $costo['costo_por_colaborador'] = $contratados > 0 ? round((float) $campana->monto / $contratados, 2) : null;
            }

            $filas[] = $this->campana($campana, $suyos, $costo, $conCandidatos);
        }

        if ($recontar) {
            $costoPorContratado = $contratadosTotal > 0 ? round($gasto / $contratadosTotal, 2) : null;
        }

        /** @var Collection<int, Candidato> $todos */
        $todos = $candidatos->flatten(1);

        return [
            'campanas' => $filas,
            'totales' => [
                'campanas' => count($filas),
                'gasto' => $gasto,
                'presupuesto' => (float) $lista->sum(fn (CampanaReclutamiento $c): float => (float) ($c->presupuesto ?? 0)),
                ...$this->resumen($todos, $gasto, $contratadosTotal),
                'costo_por_contratado' => $costoPorContratado,
            ],
        ];
    }

    /**
     * @param  Collection<int, Candidato>  $candidatos
     * @param  array<string, mixed>  $costo  Fila de CostoReclutamientoService::porCampana().
     * @return array<string, mixed>
     */
    private function campana(CampanaReclutamiento $campana, Collection $candidatos, array $costo, bool $conCandidatos): array
    {
        $gasto = (float) $campana->monto;
        $contratados = (int) ($costo['contratados'] ?? 0);
        $resumen = $this->resumen($candidatos, $gasto, $contratados);

        return [
            'id' => $campana->id,
            'nombre' => $campana->nombre !== null && $campana->nombre !== '' ? $campana->nombre : $campana->canal->etiqueta(),
            'canal' => $campana->canal->etiqueta(),
            'vacante' => $campana->vacante !== null
                ? sprintf('#%d · %s · %s', $campana->vacante->id, $campana->vacante->puesto->nombre ?? 'Sin puesto', $campana->vacante->sucursal->nombre ?? 'Sin sucursal')
                : 'Gasto general (sin vacante)',
            'fecha_inicio' => $campana->fecha_inicio?->format('d/m/Y'),
            'fecha_fin' => $campana->fecha_fin?->format('d/m/Y'),
            'estado' => EstadoCampanaReclutamiento::de($campana)->etiqueta(),
            'responsable' => $campana->responsable?->nombreCompleto(),
            'presupuesto' => $campana->presupuesto !== null ? (float) $campana->presupuesto : null,
            'gasto' => $gasto,
            'impresiones' => $campana->impresiones,
            'clics' => $campana->clics,
            'costo_por_clic' => $costo['costo_por_clic'] ?? null,
            'dias_cobertura' => $costo['dias_cobertura'] ?? null,
            ...$resumen,
            'costo_por_contratado' => $costo['costo_por_colaborador'] ?? null,
            'candidatos' => $conCandidatos ? $candidatos->map(fn (Candidato $c): array => [
                'nombre' => $c->nombreCompleto(),
                'registrado' => $c->created_at?->format('d/m/Y'),
                'fase_alcanzada' => $this->etiquetaFase($this->faseAlcanzada($c)),
                'estado' => $c->etiquetaEstado(),
                'sigue' => ! $c->estado->esTerminal(),
                'salio' => $c->estado->esSalida(),
                'motivo' => $c->estado->esSalida()
                    ? ($c->motivoRechazo !== null ? $c->motivoRechazo->nombre : ($c->motivo_salida ?? 'Sin motivo capturado'))
                    : null,
            ])->values()->all() : [],
        ];
    }

    /**
     * Embudo, fases donde se detuvieron, motivos y costos de un grupo de
     * candidatos (una campaña o todas).
     *
     * @param  Collection<int, Candidato>  $candidatos
     * @return array<string, mixed>
     */
    private function resumen(Collection $candidatos, float $gasto, int $contratados): array
    {
        $llegaron = $candidatos->count();
        $salidas = $candidatos->filter(fn (Candidato $c): bool => $c->estado->esSalida());
        $enProceso = $candidatos->filter(fn (Candidato $c): bool => ! $c->estado->esTerminal());
        $costoPorCandidato = $llegaron > 0 ? round($gasto / $llegaron, 2) : null;

        return [
            'llegaron' => $llegaron,
            'en_proceso' => $enProceso->count(),
            'no_continuaron' => $salidas->count(),
            'contratados' => $contratados,
            'laborando' => $candidatos->filter(fn (Candidato $c): bool => $c->estado === EstadoCandidato::Contratado && $c->colaborador?->activado_en !== null)->count(),
            'conversion' => $llegaron > 0 ? round($contratados / $llegaron * 100, 1) : null,
            'costo_por_candidato' => $costoPorCandidato,
            // Lo que se pagó por candidatos que no continuaron (mismo costo
            // por candidato que todos: no se inventa otro reparto).
            'gasto_en_no_continuaron' => $llegaron > 0 ? round($gasto * $salidas->count() / $llegaron, 2) : null,
            'embudo' => $this->embudo($candidatos),
            'detenidos_por_fase' => $this->contarPorFase($salidas),
            'en_proceso_por_fase' => $this->contarPorFase($enProceso),
            'motivos' => $this->motivos($salidas),
        ];
    }

    /**
     * Cuántos llegaron al menos a cada hito del pipeline (etapa_maxima
     * conserva el hito más avanzado aunque después hayan salido).
     *
     * @param  Collection<int, Candidato>  $candidatos
     * @return list<array{etapa: string, total: int, porcentaje: float|null}>
     */
    private function embudo(Collection $candidatos): array
    {
        $llegaron = $candidatos->count();
        $hitos = [
            'Llegaron (registrados)' => EstadoCandidato::Recibidos->orden(),
            'Pasaron el filtro de RH' => EstadoCandidato::EntrevistaPendiente->orden(),
            'Llegaron a psicométricos' => EstadoCandidato::PsicometricasPendientes->orden(),
            'Llegaron a socioeconómico' => EstadoCandidato::SocioeconomicoPendiente->orden(),
            'Llegaron a referencias' => EstadoCandidato::ReferenciasPendientes->orden(),
            'Llegaron a autorización' => EstadoCandidato::PreseleccionGerente->orden(),
        ];

        $filas = [];

        foreach ($hitos as $etapa => $orden) {
            $total = $candidatos->filter(fn (Candidato $c): bool => $this->ordenAlcanzado($c) >= $orden)->count();
            $filas[] = ['etapa' => $etapa, 'total' => $total, 'porcentaje' => $llegaron > 0 ? round($total / $llegaron * 100, 1) : null];
        }

        $contratados = $candidatos->filter(fn (Candidato $c): bool => $c->estado === EstadoCandidato::Contratado)->count();
        $filas[] = ['etapa' => 'Contratados', 'total' => $contratados, 'porcentaje' => $llegaron > 0 ? round($contratados / $llegaron * 100, 1) : null];

        return $filas;
    }

    /**
     * @param  Collection<int, Candidato>  $candidatos
     * @return list<array{fase: string, total: int}>
     */
    private function contarPorFase(Collection $candidatos): array
    {
        $conteo = $candidatos->countBy(fn (Candidato $c): string => $this->faseAlcanzada($c));
        $filas = [];

        // En el orden real del tablero, no alfabético.
        foreach (EstadoCandidato::fases() as $clave => $etiqueta) {
            if (($conteo[$clave] ?? 0) > 0) {
                $filas[] = ['fase' => $etiqueta, 'total' => (int) $conteo[$clave]];
            }
        }

        return $filas;
    }

    /**
     * Por qué ya no continuaron: motivo de catálogo agrupado por categoría.
     * Una salida sin motivo de catálogo (histórico anterior a la
     * clasificación automática) se reporta como tal, nunca se adivina.
     *
     * @param  Collection<int, Candidato>  $salidas
     * @return list<array{categoria: string, total: int, motivos: list<array{motivo: string, total: int}>}>
     */
    private function motivos(Collection $salidas): array
    {
        return array_values($salidas
            ->groupBy(fn (Candidato $c): string => $c->motivoRechazo?->categoria->etiqueta() ?? 'Sin clasificar')
            ->map(fn (Collection $grupo, string $categoria): array => [
                'categoria' => $categoria,
                'total' => $grupo->count(),
                'motivos' => array_values($grupo
                    ->countBy(fn (Candidato $c): string => $c->motivoRechazo->nombre ?? 'Sin motivo de catálogo')
                    ->sortDesc()
                    ->map(fn (int $total, string $motivo): array => ['motivo' => $motivo, 'total' => $total])
                    ->all()),
            ])
            ->sortByDesc('total')
            ->all());
    }

    /**
     * Hito más avanzado: el estado actual si sigue abierto; si ya salió, el
     * que quedó guardado en etapa_maxima.
     */
    private function ordenAlcanzado(Candidato $candidato): int
    {
        return max($candidato->etapa_maxima, $candidato->estado->orden());
    }

    /**
     * Columna del tablero en la que está (abierto/contratado) o en la que se
     * detuvo (salida).
     */
    private function faseAlcanzada(Candidato $candidato): string
    {
        if (! $candidato->estado->esSalida()) {
            return $candidato->fase;
        }

        $orden = $this->ordenAlcanzado($candidato);

        foreach (EstadoCandidato::cases() as $estado) {
            if ($estado->orden() === $orden && $orden > 0) {
                return $estado->faseCanonica();
            }
        }

        return 'filtro_rh';
    }

    private function etiquetaFase(string $fase): string
    {
        return EstadoCandidato::fases()[$fase] ?? $fase;
    }
}
