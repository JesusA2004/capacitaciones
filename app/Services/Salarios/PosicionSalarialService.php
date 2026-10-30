<?php

namespace App\Services\Salarios;

use App\Enums\BandaSalarial;
use App\Enums\EstadoUsuario;
use App\Enums\NivelDesempeno;
use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\TabuladorSalarial;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Compensacion\CompensacionService;
use Illuminate\Support\Collection;

/**
 * Cruza el sueldo vigente (solo CompensacionService) con el tabulador del puesto.
 * Solo lee: ningún método cambia sueldos ni aplica incrementos.
 */
class PosicionSalarialService
{
    public const SIN_EVALUACION = 'Sin evaluación de desempeño vigente';

    public function __construct(
        private readonly CompensacionService $compensacion,
        private readonly TabuladorSalarialService $tabulador,
        private readonly MatrizMeritoService $matriz,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public static function compaRatio(float $sueldo, float $medio): ?float
    {
        return $medio > 0 ? round($sueldo / $medio * 100, 2) : null;
    }

    public static function banda(float $sueldo, float $minimo, float $medio, float $maximo): BandaSalarial
    {
        return match (true) {
            $sueldo < $minimo => BandaSalarial::BajoMinimo,
            $sueldo < $medio => BandaSalarial::MinimoAMedio,
            $sueldo <= $maximo => BandaSalarial::MedioAMaximo,
            default => BandaSalarial::SobreMaximo,
        };
    }

    /** Hasta dónde puede llegar según desempeño. Bajo: sin incremento de sueldo base. */
    public static function tope(NivelDesempeno $nivel, float $medio, float $maximo): ?float
    {
        return match ($nivel) {
            NivelDesempeno::Sobresaliente => $maximo,
            NivelDesempeno::Satisfactorio => $medio,
            NivelDesempeno::Bajo => null,
        };
    }

    /**
     * El sistema aún no tiene evaluación formal de desempeño (el feature
     * evaluacion_desempeno está apagado y sin backend). La inducción y el periodo
     * de prueba NO cuentan: por eso hoy es null y la pantalla lo dice tal cual.
     */
    public function desempenoVigente(Colaborador $colaborador): ?NivelDesempeno
    {
        return null;
    }

    /**
     * @param  array<mixed>|null  $celdasMatriz
     * @return array<string, mixed>
     */
    public function fila(Colaborador $colaborador, ?TabuladorSalarial $tabulador, ?NivelDesempeno $nivel, ?array $celdasMatriz): array
    {
        $sueldo = $this->compensacion->mensualDe($colaborador);
        $fila = [
            'id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'puesto_id' => $colaborador->puesto_id,
            'puesto' => $colaborador->puesto?->nombre,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
            'sueldo' => $sueldo,
            'compa_ratio' => null,
            'banda' => null,
            'banda_etiqueta' => null,
            'desempeno' => $nivel?->value,
            'desempeno_etiqueta' => $nivel?->etiqueta() ?? self::SIN_EVALUACION,
            'tope' => null,
            'margen' => null,
            'sugerido' => null,
        ];

        if ($sueldo === null || $tabulador === null) {
            return $fila;
        }

        $minimo = (float) $tabulador->minimo;
        $medio = (float) $tabulador->medio;
        $maximo = (float) $tabulador->maximo;
        $banda = self::banda($sueldo, $minimo, $medio, $maximo);
        $fila['compa_ratio'] = self::compaRatio($sueldo, $medio);
        $fila['banda'] = $banda->value;
        $fila['banda_etiqueta'] = $banda->etiqueta();

        if ($nivel !== null) {
            $tope = self::tope($nivel, $medio, $maximo);
            $fila['tope'] = $tope;
            $fila['margen'] = $tope === null ? 0.0 : round(max($tope - $sueldo, 0), 2);
            $fila['sugerido'] = $this->matriz->sugerencia($celdasMatriz, $nivel, $banda);
        }

        return $fila;
    }

    /**
     * Toda la plantilla vigente visible para el usuario.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function posiciones(User $usuario, ?int $sucursalId = null): Collection
    {
        $vigentes = $this->tabulador->vigentes();
        $celdas = $this->matriz->vigente()?->celdas;

        $consulta = Colaborador::query()
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])
            ->whereIn('estatus', EstadoUsuario::valoresVigentes())
            ->when($sucursalId !== null, fn ($q) => $q->where('sucursal_principal_id', $sucursalId));

        return $this->alcance->limitarColaboradoresPorAlcance($consulta, $usuario)
            ->get()
            ->map(fn (Colaborador $c): array => $this->fila($c, $vigentes->get((int) $c->puesto_id), $this->desempenoVigente($c), $celdas))
            ->sortBy('nombre')
            ->values();
    }

    /**
     * Tarjetas y alertas. Un dato que no se puede calcular va en null: la pantalla
     * lo muestra como «Sin dato», nunca como 0.
     *
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return array<string, mixed>
     */
    public function resumen(Collection $filas): array
    {
        $puestos = Puesto::query()->where('activo', true)->count();
        $conTabulador = Puesto::query()->where('activo', true)->whereIn('id', $this->tabulador->vigentes()->keys())->count();
        $comparados = $filas->whereNotNull('compa_ratio');
        $conMargen = $filas->whereNotNull('margen');

        return [
            'puestos_total' => $puestos,
            'puestos_con_tabulador' => $conTabulador,
            'puestos_sin_tabulador' => max($puestos - $conTabulador, 0),
            'compa_ratio_promedio' => $comparados->isEmpty() ? null : round((float) $comparados->avg('compa_ratio'), 2),
            'bajo_minimo' => $filas->where('banda', BandaSalarial::BajoMinimo->value)->count(),
            'sobre_maximo' => $filas->where('banda', BandaSalarial::SobreMaximo->value)->count(),
            'margen' => $conMargen->isEmpty() ? null : round((float) $conMargen->sum('margen'), 2),
            'con_evaluacion' => $conMargen->count(),
            'colaboradores' => $filas->count(),
            'sin_sueldo' => $filas->whereNull('sueldo')->count(),
        ];
    }

    /**
     * Renglones del tabulador: todos los puestos activos, capturados o no.
     *
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return list<array<string, mixed>>
     */
    public function renglonesTabulador(Collection $filas): array
    {
        $vigentes = $this->tabulador->vigentes();
        $porPuesto = $filas->groupBy('puesto_id');

        return array_values(Puesto::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre'])
            ->map(function (Puesto $puesto) use ($vigentes, $porPuesto): array {
                $tabulador = $vigentes->get($puesto->id);
                $personas = $porPuesto->get($puesto->id, collect());
                $comparados = $personas->whereNotNull('compa_ratio');

                return [
                    'puesto_id' => $puesto->id,
                    'puesto' => $puesto->nombre,
                    'minimo' => $tabulador !== null ? (float) $tabulador->minimo : null,
                    'medio' => $tabulador !== null ? (float) $tabulador->medio : null,
                    'maximo' => $tabulador !== null ? (float) $tabulador->maximo : null,
                    'fuente' => $tabulador?->fuente,
                    'fecha_actualizacion' => $tabulador?->fecha_actualizacion->toDateString(),
                    'version' => $tabulador?->version,
                    'colaboradores' => $personas->count(),
                    'compa_ratio' => $comparados->isEmpty() ? null : round((float) $comparados->avg('compa_ratio'), 2),
                ];
            })
            ->all());
    }

    /**
     * @return array{titulo: string, columnas: array<int, string>, filas: array<int, array<int, mixed>>}
     */
    public function tablaTabulador(User $usuario): array
    {
        return [
            'titulo' => 'Tabulador de sueldos mensuales',
            'columnas' => ['Puesto', 'Mínimo', 'Medio', 'Máximo', 'Fuente', 'Actualización', 'Versión', 'Colaboradores', 'Compa-ratio %'],
            'filas' => array_map(fn (array $r): array => [
                $r['puesto'], $r['minimo'] ?? 'Sin capturar', $r['medio'] ?? 'Sin capturar', $r['maximo'] ?? 'Sin capturar',
                $r['fuente'] ?? '—', $r['fecha_actualizacion'] ?? '—', $r['version'] ?? '—', $r['colaboradores'], $r['compa_ratio'] ?? 'Sin dato',
            ], $this->renglonesTabulador($this->posiciones($usuario))),
        ];
    }

    /**
     * @return array{titulo: string, columnas: array<int, string>, filas: array<int, array<int, mixed>>}
     */
    public function tablaPosiciones(User $usuario, ?int $sucursalId, ?int $puestoId): array
    {
        $filas = $this->posiciones($usuario, $sucursalId)
            ->when($puestoId !== null, fn (Collection $c) => $c->where('puesto_id', $puestoId));

        return [
            'titulo' => 'Posición salarial por colaborador',
            'columnas' => ['Colaborador', 'Puesto', 'Sucursal', 'Sueldo actual', 'Compa-ratio %', 'Posición', 'Desempeño', 'Tope', 'Puede subir'],
            'filas' => $filas->map(fn (array $f): array => [
                $f['nombre'], $f['puesto'] ?? '—', $f['sucursal'] ?? '—', $f['sueldo'] ?? 'Sin capturar', $f['compa_ratio'] ?? 'Sin dato',
                $f['banda_etiqueta'] ?? 'Sin tabulador', $f['desempeno_etiqueta'], $f['tope'] ?? '—', $f['margen'] ?? '—',
            ])->values()->all(),
        ];
    }
}
