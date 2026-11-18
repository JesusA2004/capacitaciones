<?php

namespace App\Services\NineBox;

use App\Enums\AlcanceDatos;
use App\Enums\CuadranteNineBox;
use App\Enums\EstadoEvaluacionTecnica;
use App\Enums\NivelNineBox;
use App\Enums\TipoPeriodoNineBox;
use App\Models\Colaborador;
use App\Models\EvaluacionTecnica;
use App\Models\NineBoxCalibracion;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Colaboradores\FotoColaboradorService;
use App\Services\EvaluacionesTecnicas\ResultadosEvaluacionTecnicaService;
use App\Services\Permisos\AlcanceModuloService;
use App\Services\Seguimiento\DatoReportes;
use App\Services\Seguimiento\ReportesCatalogoService;
use App\Services\Seguimiento\ReportesNoDisponibleException;
use App\Services\Seguimiento\SeguimientoService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Nine Box de Seguimiento (docs/NINE_BOX.md): cada gestor de Reportes en
 * una matriz 3×3 Desempeño × Potencial por periodo (mes de Reportes o
 * trimestre), acotada al alcance del módulo «nine_box».
 *
 *   - DESEMPEÑO (0-100): colocación, recuperación y mora del periodo que
 *     Reportes ya calculó (PEOPLE no recalcula KPIs). Cada indicador se
 *     normaliza con la configuración vigente: cumplimiento contra meta o,
 *     sin meta, percentil contra TODOS los gestores de la empresa (así el
 *     puntaje no cambia según quién mira); la mora, lineal entre «excelente»
 *     y «crítica».
 *   - POTENCIAL (0-100): promedio de evaluaciones técnicas («Seguimiento
 *     15/30/60…») + calibración del jefe directo o RH, con sus pesos. Sin
 *     ninguno de los dos, la persona queda «por calibrar» (fuera de la
 *     cuadrícula: nunca se inventa un potencial).
 *
 * Un gestor sin vínculo con un colaborador de PEOPLE tiene desempeño pero
 * no potencial: aparece en «Sin vincular».
 *
 * @phpstan-import-type ValoresNineBox from ConfiguracionNineBoxService
 *
 * @phpstan-type PeriodoNineBox array{tipo: string, clave: string, etiqueta: string, descripcion: string, inicio: string|null, fin: string|null, meses_ids: list<int>, meses: list<string>, completo: bool}
 * @phpstan-type Agregado array{reportes_id: int, nombre: string, puesto: string|null, colaborador_id: int|null, sucursal_id: int|null, branch: string|null, colocacion: float, operaciones: int, recuperacion: float, cartera: float|null, vencida: float|null, semanas: list<array{semana_id: int, etiqueta: string, mes: string, colocacion: float, recuperacion: float}>}
 */
class NineBoxService
{
    public const MODULO = 'nine_box';

    private const MESES_CORTOS = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    public function __construct(
        private readonly ReportesCatalogoService $catalogo,
        private readonly SeguimientoService $seguimiento,
        private readonly ConfiguracionNineBoxService $configuracion,
        private readonly AlcanceModuloService $alcanceModulo,
        private readonly ResultadosEvaluacionTecnicaService $resultados,
        private readonly FotoColaboradorService $fotos,
    ) {}

    /**
     * Meses (con radiografía) y trimestres que se ofrecen, del más reciente
     * al más antiguo.
     *
     * @return array{meses: list<PeriodoNineBox>, trimestres: list<PeriodoNineBox>}
     *
     * @throws ReportesNoDisponibleException
     */
    public function periodos(): array
    {
        $limite = max(1, (int) config('seguimiento.meses_tendencia', 6)) * 2;
        $meses = array_slice(array_values(array_filter($this->catalogo->meses(), fn (array $m): bool => $m['radiografia'])), 0, $limite);

        $listaMeses = [];
        /** @var array<string, array{anio: int, trimestre: int, meses: list<array{id: int, codigo: string, etiqueta: string, inicio: string|null, fin: string|null, radiografia: bool, semanas_ids: list<int>}>}> $porTrimestre */
        $porTrimestre = [];

        foreach ($meses as $mes) {
            $listaMeses[] = [
                'tipo' => TipoPeriodoNineBox::Mes->value,
                'clave' => $mes['codigo'] !== '' ? $mes['codigo'] : sprintf('mes-%d', $mes['id']),
                'etiqueta' => $mes['etiqueta'],
                'descripcion' => sprintf('%d semanas de Reportes', max(1, count($mes['semanas_ids']))),
                'inicio' => $mes['inicio'],
                'fin' => $mes['fin'],
                'meses_ids' => [$mes['id']],
                'meses' => [$mes['etiqueta']],
                'completo' => true,
            ];

            [$anio, $numero] = $this->anioMes($mes);

            if ($anio !== null) {
                $trimestre = intdiv($numero - 1, 3) + 1;
                $clave = sprintf('%d-T%d', $anio, $trimestre);
                $porTrimestre[$clave] ??= ['anio' => $anio, 'trimestre' => $trimestre, 'meses' => []];
                $porTrimestre[$clave]['meses'][] = $mes;
            }
        }

        $trimestres = [];

        foreach ($porTrimestre as $clave => $grupo) {
            $delTrimestre = $grupo['meses'];
            usort($delTrimestre, fn (array $a, array $b): int => strcmp((string) $a['inicio'], (string) $b['inicio']));
            $primerMes = ($grupo['trimestre'] - 1) * 3 + 1;
            $trimestres[] = [
                'tipo' => TipoPeriodoNineBox::Trimestre->value,
                'clave' => $clave,
                'etiqueta' => sprintf('Trimestre %d · %d', $grupo['trimestre'], $grupo['anio']),
                'descripcion' => sprintf('%s – %s', ucfirst(self::MESES_CORTOS[$primerMes]), self::MESES_CORTOS[$primerMes + 2]),
                'inicio' => $delTrimestre[0]['inicio'],
                'fin' => $delTrimestre[count($delTrimestre) - 1]['fin'],
                'meses_ids' => array_map(fn (array $m): int => $m['id'], $delTrimestre),
                'meses' => array_map(fn (array $m): string => $m['etiqueta'], $delTrimestre),
                'completo' => count($delTrimestre) === 3,
            ];
        }

        return ['meses' => $listaMeses, 'trimestres' => $trimestres];
    }

    /**
     * El periodo pedido (o el más reciente de ese tipo).
     *
     * @return PeriodoNineBox|null
     *
     * @throws ReportesNoDisponibleException
     */
    public function periodo(TipoPeriodoNineBox $tipo, ?string $clave): ?array
    {
        $periodos = $this->periodos();
        $lista = $tipo === TipoPeriodoNineBox::Mes ? $periodos['meses'] : $periodos['trimestres'];

        foreach ($lista as $candidato) {
            if ($candidato['clave'] === $clave) {
                return $candidato;
            }
        }

        return $lista[0] ?? null;
    }

    /**
     * La matriz del periodo para el usuario.
     *
     * @return array<string, mixed>
     *
     * @throws ReportesNoDisponibleException
     */
    public function matriz(User $usuario, TipoPeriodoNineBox $tipo, ?string $clave, ?int $sucursalId): array
    {
        $periodos = $this->periodos();
        $periodo = $this->periodo($tipo, $clave);
        $config = $this->configuracion->valores();
        $base = [
            'periodos' => $periodos,
            'periodo' => $periodo,
            'configuracion' => $config,
            'cuadrantes' => CuadranteNineBox::catalogo(),
        ];

        if ($periodo === null) {
            return [...$base, 'estado' => 'sin_datos', 'personas' => [], 'conteos' => [], 'resumen' => $this->resumen([]), 'opciones_sucursal' => []];
        }

        [$agregados, $conDatos] = $this->agregar($periodo);
        $puntajesDesempeno = $this->puntajesDesempeno($agregados, $config, count($periodo['meses_ids']));

        // Alcance del módulo «nine_box».
        $nivel = $this->alcanceModulo->nivel($usuario, self::MODULO);
        $sucursalesAlcance = $nivel->esPorSucursales()
            ? array_values(array_map(fn ($id): int => (int) $id, $this->alcanceModulo->sucursalesPara($usuario, $nivel)->all()))
            : [];

        $idsColaboradores = array_values(array_unique(array_filter(array_map(fn (array $a): ?int => $a['colaborador_id'], $agregados))));
        /** @var Collection<int, Colaborador> $colaboradores */
        $colaboradores = Colaborador::query()
            ->with(['puesto:id,nombre'])
            ->whereIn('id', $idsColaboradores)
            ->get(['id', 'name', 'apellidos', 'puesto_id', 'sucursal_principal_id', 'jefe_id', 'gerente_id', 'foto_path'])
            ->keyBy('id');

        $visibles = [];

        foreach ($agregados as $agregado) {
            $colaborador = $agregado['colaborador_id'] === null ? null : $colaboradores->get($agregado['colaborador_id']);
            $sucursalPersona = $colaborador !== null
                ? ($colaborador->sucursal_principal_id === null ? null : (int) $colaborador->sucursal_principal_id)
                : $agregado['sucursal_id'];

            if (! $this->alcanza($usuario, $nivel, $sucursalesAlcance, $colaborador, $sucursalPersona)) {
                continue;
            }

            $visibles[] = [$agregado, $colaborador, $sucursalPersona];
        }

        $nombresSucursal = Sucursal::query()->pluck('nombre', 'id');
        $opcionesSucursal = collect($visibles)
            ->map(fn (array $v): ?int => $v[2])->filter()->unique()
            ->map(fn (int $id): array => ['value' => $id, 'label' => (string) ($nombresSucursal[$id] ?? sprintf('Sucursal %d', $id))])
            ->sortBy('label')->values()->all();

        if ($sucursalId !== null) {
            $visibles = array_values(array_filter($visibles, fn (array $v): bool => $v[2] === $sucursalId));
        }

        // Potencial: evaluaciones técnicas en la ventana + calibración vigente.
        $fin = Carbon::parse((string) ($periodo['fin'] ?? now()->toDateString()))->endOfDay();
        $desde = $fin->copy()->subMonthsNoOverflow($config['potencial']['ventana_evaluaciones_meses'])->startOfDay();
        $idsVisibles = array_values(array_filter(array_map(fn (array $v): ?int => $v[1]?->id, $visibles)));
        $promedios = $this->resultados->promedioPorColaborador($idsVisibles, $desde, $fin);
        $calibraciones = $this->calibracionesVigentes($idsVisibles, $fin);
        $puedeCalibrar = $usuario->can('nine_box.calibrar');

        $personas = [];

        foreach ($visibles as [$agregado, $colaborador, $sucursalPersona]) {
            $desempeno = $puntajesDesempeno[$agregado['reportes_id']];
            $calibracion = $colaborador === null ? null : ($calibraciones[$colaborador->id] ?? null);
            $evaluaciones = $colaborador === null ? null : ($promedios[$colaborador->id] ?? null);
            $potencial = $colaborador === null ? null : $this->potencial($evaluaciones, $calibracion, $config);

            $nivelDesempeno = $desempeno['puntaje'] === null ? null
                : NivelNineBox::dePuntaje($desempeno['puntaje'], $config['cortes']['desempeno']['medio'], $config['cortes']['desempeno']['alto']);
            $nivelPotencial = $potencial === null ? null
                : NivelNineBox::dePuntaje($potencial, $config['cortes']['potencial']['medio'], $config['cortes']['potencial']['alto']);
            $cuadrante = $nivelDesempeno !== null && $nivelPotencial !== null ? CuadranteNineBox::de($nivelDesempeno, $nivelPotencial) : null;
            $nombre = $colaborador?->nombreCompleto() ?? $agregado['nombre'];

            $personas[] = [
                'id' => $agregado['reportes_id'],
                'colaborador_id' => $colaborador?->id,
                'nombre' => $nombre,
                'iniciales' => $this->iniciales($nombre),
                'foto_url' => $colaborador === null ? null : $this->fotos->urlPara($colaborador, $usuario),
                'puesto' => $colaborador?->puesto->nombre ?? $agregado['puesto'],
                'sucursal_id' => $sucursalPersona,
                'sucursal' => $sucursalPersona === null ? null : (string) ($nombresSucursal[$sucursalPersona] ?? ''),
                'branch' => $agregado['branch'],
                'vinculado' => $colaborador !== null,
                'estado' => $colaborador === null ? 'sin_vincular' : ($cuadrante === null ? 'sin_potencial' : 'ubicado'),
                'cuadrante' => $cuadrante?->value,
                'desempeno' => [
                    'puntaje' => $desempeno['puntaje'],
                    'nivel' => $nivelDesempeno?->value,
                    'componentes' => $desempeno['componentes'],
                ],
                'potencial' => [
                    'puntaje' => $potencial,
                    'nivel' => $nivelPotencial?->value,
                    'evaluaciones' => $evaluaciones,
                    'calibracion' => $calibracion === null ? null : [
                        'nivel' => $calibracion->nivel->value,
                        'etiqueta' => $calibracion->nivel->etiqueta(),
                        'comentario' => $calibracion->comentario,
                        'periodo' => $calibracion->periodo_etiqueta,
                        'heredada' => $calibracion->periodo_clave !== $periodo['clave'],
                        'capturada_por' => $calibracion->capturadaPor?->name,
                        'capturada_en' => $calibracion->created_at?->toIso8601String(),
                    ],
                ],
                'kpis' => [
                    'colocacion' => $agregado['colocacion'],
                    'operaciones' => $agregado['operaciones'],
                    'recuperacion' => $agregado['recuperacion'],
                    'cartera' => $agregado['cartera'],
                    'vencida' => $agregado['vencida'],
                    'mora' => $this->seguimiento->mora($agregado['cartera'], $agregado['vencida']),
                ],
                'semanas' => $agregado['semanas'],
                'puede_calibrar' => $puedeCalibrar && $colaborador !== null && $colaborador->id !== $usuario->colaborador_id,
            ];
        }

        usort($personas, fn (array $a, array $b): int => ($b['desempeno']['puntaje'] ?? -1) <=> ($a['desempeno']['puntaje'] ?? -1));

        $conteos = array_fill_keys(array_map(fn (CuadranteNineBox $c): string => $c->value, CuadranteNineBox::cases()), 0);

        foreach ($personas as $persona) {
            if ($persona['cuadrante'] !== null) {
                $conteos[$persona['cuadrante']]++;
            }
        }

        return [
            ...$base,
            'estado' => $conDatos ? 'ok' : 'sin_datos',
            'personas' => $personas,
            'conteos' => $conteos,
            'resumen' => $this->resumen($personas),
            'opciones_sucursal' => $opcionesSucursal,
        ];
    }

    /**
     * Evaluaciones técnicas y calibraciones (historial) de UNA persona, para
     * el panel lateral. 403 si está fuera del alcance de la Nine Box.
     *
     * @return array{evaluaciones: list<array<string, mixed>>, calibraciones: list<array<string, mixed>>}
     *
     * @throws AuthorizationException
     */
    public function detallePersona(User $usuario, Colaborador $colaborador): array
    {
        if (! $this->alcanceModulo->alcanzaColaborador($usuario, $colaborador, self::MODULO)) {
            throw new AuthorizationException('Esta persona está fuera de tu alcance en la Nine Box.');
        }

        $evaluaciones = EvaluacionTecnica::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('estado', '!=', EstadoEvaluacionTecnica::Cancelada->value)
            ->orderBy('fecha_programada')
            ->get(['id', 'nombre', 'dias', 'es_final', 'fecha_programada', 'fecha_limite', 'estado', 'calificacion', 'calificacion_minima', 'aprobada', 'contestada_en'])
            ->map(fn (EvaluacionTecnica $e): array => [
                'id' => $e->id,
                'nombre' => $e->nombre,
                'dias' => $e->dias,
                'es_final' => $e->es_final,
                'fecha_programada' => $e->fecha_programada->toDateString(),
                'vencida' => $e->estado === EstadoEvaluacionTecnica::Pendiente && $e->fecha_limite->isPast(),
                'estado' => $e->estado->value,
                'estado_etiqueta' => $e->estado->etiqueta(),
                'calificacion' => $e->calificacion === null ? null : (float) $e->calificacion,
                'aprobada' => $e->aprobada,
                'contestada_en' => $e->contestada_en?->toIso8601String(),
            ])->values()->all();

        $calibraciones = NineBoxCalibracion::query()->with('capturadaPor:id,name')
            ->where('colaborador_id', $colaborador->id)
            ->orderByDesc('periodo_fin')->orderByDesc('id')
            ->get()
            ->map(fn (NineBoxCalibracion $c): array => [
                'id' => $c->id,
                'periodo' => $c->periodo_etiqueta,
                'periodo_tipo' => $c->periodo_tipo->value,
                'nivel' => $c->nivel->value,
                'etiqueta' => $c->nivel->etiqueta(),
                'comentario' => $c->comentario,
                'capturada_por' => $c->capturadaPor?->name,
                'capturada_en' => $c->created_at?->toIso8601String(),
            ])->values()->all();

        return ['evaluaciones' => array_values($evaluaciones), 'calibraciones' => array_values($calibraciones)];
    }

    /**
     * ¿El usuario ve a esta persona en la Nine Box? Mismo criterio que
     * AlcanceModuloService::alcanzaColaborador, resuelto una sola vez por
     * pantalla (sin consultas por persona). Un gestor sin vínculo se ubica
     * por la sucursal de su branch y solo lo ve un alcance por sucursal.
     *
     * @param  list<int>  $sucursalesAlcance
     */
    private function alcanza(User $usuario, AlcanceDatos $nivel, array $sucursalesAlcance, ?Colaborador $colaborador, ?int $sucursal): bool
    {
        return match ($nivel) {
            AlcanceDatos::Todos => true,
            AlcanceDatos::Region, AlcanceDatos::SucursalCorporativo, AlcanceDatos::Sucursal => $sucursal !== null && in_array($sucursal, $sucursalesAlcance, true),
            AlcanceDatos::Equipo => $colaborador !== null && $usuario->colaborador_id !== null
                && ($colaborador->jefe_id === $usuario->colaborador_id || $colaborador->gerente_id === $usuario->colaborador_id),
            AlcanceDatos::Propios => false,
        };
    }

    /**
     * Suma los meses del periodo por gestor de Reportes.
     *
     * @param  PeriodoNineBox  $periodo
     * @return array{0: array<int, Agregado>, 1: bool}
     *
     * @throws ReportesNoDisponibleException
     */
    private function agregar(array $periodo): array
    {
        /** @var array<int, Agregado> $agregados */
        $agregados = [];
        $conDatos = false;

        // meses_ids viene en orden cronológico: el corte de cartera/mora
        // que queda es el del ÚLTIMO mes con dato.
        foreach ($periodo['meses_ids'] as $posicion => $mesId) {
            $datos = $this->seguimiento->actividadGestoresMes($mesId);
            $etiquetaMes = $periodo['meses'][$posicion] ?? '';
            $conDatos = $conDatos || $datos['estado'] === 'ok';

            foreach ($datos['gestores'] as $gestor) {
                $id = $gestor['reportes_id'];
                $agregados[$id] ??= [
                    'reportes_id' => $id,
                    'nombre' => $gestor['nombre'],
                    'puesto' => $gestor['puesto'],
                    'colaborador_id' => null,
                    'sucursal_id' => null,
                    'branch' => null,
                    'colocacion' => 0.0,
                    'operaciones' => 0,
                    'recuperacion' => 0.0,
                    'cartera' => null,
                    'vencida' => null,
                    'semanas' => [],
                ];
                $agregado = $agregados[$id];
                $agregado['nombre'] = $gestor['nombre'];
                $agregado['puesto'] = $gestor['puesto'] ?? $agregado['puesto'];
                $agregado['colaborador_id'] = $gestor['colaborador_id'] ?? $agregado['colaborador_id'];
                $agregado['sucursal_id'] = $gestor['sucursal_id'] ?? $agregado['sucursal_id'];
                $agregado['branch'] = $gestor['branch'] ?? $agregado['branch'];
                $agregado['colocacion'] = round($agregado['colocacion'] + (float) ($gestor['mes_colocacion'] ?? 0), 2);
                $agregado['operaciones'] += (int) ($gestor['mes_operaciones'] ?? 0);
                $agregado['recuperacion'] = round($agregado['recuperacion'] + (float) ($gestor['mes_recuperacion'] ?? 0), 2);

                $porSemana = [];

                foreach ($gestor['semanas'] as $semana) {
                    $semanaId = DatoReportes::entero($semana['period_id'] ?? null);

                    if ($semanaId !== null) {
                        $porSemana[$semanaId] = $semana;
                    }
                }

                foreach ($datos['semanas'] as $semana) {
                    $fila = $porSemana[$semana['id']] ?? null;
                    $agregado['semanas'][] = [
                        'semana_id' => $semana['id'],
                        'etiqueta' => sprintf('S%d %s', $semana['indice'], mb_substr($etiquetaMes, 0, 3)),
                        'mes' => $etiquetaMes,
                        'colocacion' => round(DatoReportes::decimal(DatoReportes::ruta($fila, 'placement.amount')), 2),
                        'recuperacion' => round(DatoReportes::decimal(DatoReportes::ruta($fila, 'recovery.total')), 2),
                    ];
                }

                $oficial = null;

                foreach ($gestor['ids'] as $candidato) {
                    $oficial ??= $datos['oficiales'][$candidato] ?? null;
                }

                if ($oficial !== null) {
                    $cartera = DatoReportes::decimalONulo(DatoReportes::ruta($oficial, 'kpis.portfolio.total'));
                    $vencida = DatoReportes::decimalONulo(DatoReportes::ruta($oficial, 'kpis.portfolio.overdue'));

                    if ($cartera !== null) {
                        $agregado['cartera'] = $cartera;
                        $agregado['vencida'] = $vencida;
                    }
                }

                $agregados[$id] = $agregado;
            }
        }

        return [$agregados, $conDatos];
    }

    /**
     * Puntaje de desempeño 0-100 de TODOS los gestores del periodo (el
     * percentil se calcula contra la empresa completa).
     *
     * @param  array<int, Agregado>  $agregados
     * @param  ValoresNineBox  $config
     * @return array<int, array{puntaje: float|null, componentes: list<array{clave: string, etiqueta: string, valor: float|null, formato: string, puntaje: float|null, peso: float, metodo: string}>}>
     */
    private function puntajesDesempeno(array $agregados, array $config, int $meses): array
    {
        $d = $config['desempeno'];
        $colocaciones = array_map(fn (array $a): float => $a['colocacion'], array_values($agregados));
        $recuperaciones = array_map(fn (array $a): float => $a['recuperacion'], array_values($agregados));
        sort($colocaciones);
        sort($recuperaciones);

        $resultado = [];

        foreach ($agregados as $id => $a) {
            $mora = $this->seguimiento->mora($a['cartera'], $a['vencida']);
            $componentes = [
                $this->componenteMonto('colocacion', 'Colocación', $a['colocacion'], $d['meta_colocacion_mensual'], $meses, $colocaciones, $d['pesos']['colocacion']),
                $this->componenteMonto('recuperacion', 'Recuperación', $a['recuperacion'], $d['meta_recuperacion_mensual'], $meses, $recuperaciones, $d['pesos']['recuperacion']),
                [
                    'clave' => 'mora',
                    'etiqueta' => 'Mora',
                    'valor' => $mora,
                    'formato' => 'porcentaje',
                    'puntaje' => $mora === null ? null : $this->puntajeMora($mora, $d['mora_excelente'], $d['mora_critica']),
                    'peso' => $d['pesos']['mora'],
                    'metodo' => 'rango',
                ],
            ];

            $suma = 0.0;
            $pesos = 0.0;

            foreach ($componentes as $c) {
                if ($c['puntaje'] !== null && $c['peso'] > 0) {
                    $suma += $c['puntaje'] * $c['peso'];
                    $pesos += $c['peso'];
                }
            }

            $resultado[$id] = ['puntaje' => $pesos > 0 ? round($suma / $pesos, 1) : null, 'componentes' => $componentes];
        }

        return $resultado;
    }

    /**
     * @param  list<float>  $universo  ordenado de menor a mayor
     * @return array{clave: string, etiqueta: string, valor: float|null, formato: string, puntaje: float|null, peso: float, metodo: string}
     */
    private function componenteMonto(string $clave, string $etiqueta, float $valor, ?float $metaMensual, int $meses, array $universo, float $peso): array
    {
        if ($metaMensual !== null) {
            $meta = $metaMensual * max(1, $meses);

            return ['clave' => $clave, 'etiqueta' => $etiqueta, 'valor' => $valor, 'formato' => 'moneda', 'puntaje' => round(min(100.0, max(0.0, $valor / $meta * 100)), 1), 'peso' => $peso, 'metodo' => 'meta'];
        }

        return ['clave' => $clave, 'etiqueta' => $etiqueta, 'valor' => $valor, 'formato' => 'moneda', 'puntaje' => $this->percentil($valor, $universo), 'peso' => $peso, 'metodo' => 'percentil'];
    }

    /**
     * Percentil por rango medio: 0 = el menor, 100 = el mayor; empates
     * comparten el promedio de sus posiciones.
     *
     * @param  list<float>  $universo  ordenado de menor a mayor
     */
    private function percentil(float $valor, array $universo): float
    {
        $total = count($universo);

        if ($total <= 1) {
            return 100.0;
        }

        $menores = 0;
        $iguales = 0;

        foreach ($universo as $otro) {
            if ($otro < $valor) {
                $menores++;
            } elseif ($otro == $valor) {
                $iguales++;
            }
        }

        return round(($menores + max(0, $iguales - 1) / 2) / ($total - 1) * 100, 1);
    }

    private function puntajeMora(float $mora, float $excelente, float $critica): float
    {
        if ($mora <= $excelente) {
            return 100.0;
        }

        if ($mora >= $critica) {
            return 0.0;
        }

        return round(($critica - $mora) / ($critica - $excelente) * 100, 1);
    }

    /**
     * @param  ValoresNineBox  $config
     */
    private function potencial(?float $evaluaciones, ?NineBoxCalibracion $calibracion, array $config): ?float
    {
        $pesos = $config['potencial']['pesos'];
        $suma = 0.0;
        $total = 0.0;

        if ($evaluaciones !== null && $pesos['evaluaciones'] > 0) {
            $suma += $evaluaciones * $pesos['evaluaciones'];
            $total += $pesos['evaluaciones'];
        }

        if ($calibracion !== null && $pesos['calibracion'] > 0) {
            $suma += $config['potencial']['valores_calibracion'][$calibracion->nivel->value] * $pesos['calibracion'];
            $total += $pesos['calibracion'];
        }

        return $total > 0 ? round($suma / $total, 1) : null;
    }

    /**
     * Calibración vigente de cada colaborador al fin del periodo: la más
     * reciente cuyo periodo no termina después (sigue vigente en los
     * periodos siguientes hasta que alguien recalibra).
     *
     * @param  list<int>  $ids
     * @return array<int, NineBoxCalibracion>
     */
    private function calibracionesVigentes(array $ids, Carbon $fin): array
    {
        if ($ids === []) {
            return [];
        }

        $vigentes = [];

        NineBoxCalibracion::query()->with('capturadaPor:id,name')
            ->whereIn('colaborador_id', $ids)
            ->whereDate('periodo_fin', '<=', $fin->toDateString())
            ->orderBy('periodo_fin')->orderBy('id')
            ->get()
            ->each(function (NineBoxCalibracion $c) use (&$vigentes): void {
                $vigentes[$c->colaborador_id] = $c;
            });

        return $vigentes;
    }

    /**
     * @param  list<array<string, mixed>>  $personas
     * @return array{total: int, ubicados: int, sin_vincular: int, sin_potencial: int, calibrados: int}
     */
    private function resumen(array $personas): array
    {
        $contar = fn (string $estado): int => count(array_filter($personas, fn (array $p): bool => $p['estado'] === $estado));

        return [
            'total' => count($personas),
            'ubicados' => $contar('ubicado'),
            'sin_vincular' => $contar('sin_vincular'),
            'sin_potencial' => $contar('sin_potencial'),
            'calibrados' => count(array_filter($personas, fn (array $p): bool => is_array($p['potencial']) && ($p['potencial']['calibracion'] ?? null) !== null)),
        ];
    }

    private function iniciales(string $nombre): string
    {
        $palabras = array_values(array_filter(preg_split('/\s+/u', trim($nombre)) ?: []));
        $primera = mb_substr($palabras[0] ?? '?', 0, 1);
        $segunda = count($palabras) > 1 ? mb_substr($palabras[1], 0, 1) : '';

        return mb_strtoupper(sprintf('%s%s', $primera, $segunda));
    }

    /**
     * Año y número de mes de un mes de Reportes, por su código «M-AAAA-MM»
     * o, si no lo trae, por su fecha de fin.
     *
     * @param  array{id: int, codigo: string, etiqueta: string, inicio: string|null, fin: string|null, radiografia: bool, semanas_ids: list<int>}  $mes
     * @return array{0: int|null, 1: int}
     */
    private function anioMes(array $mes): array
    {
        if (preg_match('/(\d{4})-(\d{2})$/', $mes['codigo'], $partes) === 1) {
            return [(int) $partes[1], max(1, min(12, (int) $partes[2]))];
        }

        if ($mes['fin'] !== null && preg_match('/^(\d{4})-(\d{2})/', $mes['fin'], $partes) === 1) {
            return [(int) $partes[1], max(1, min(12, (int) $partes[2]))];
        }

        return [null, 1];
    }
}
