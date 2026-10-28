<?php

namespace App\Services\Reclutamiento;

use App\Enums\CanalReclutamiento;
use App\Enums\CriterioFechaReporteCampanas;
use App\Enums\EstadoCampanaReclutamiento;
use App\Enums\EstadoCandidato;
use Illuminate\Support\Carbon;

/**
 * Filtros ya validados del reporte de campañas de reclutamiento (ver
 * App\Http\Requests\Rh\ReporteCampanasRequest). Los mismos filtros arman la
 * pantalla del reporte, el PDF y el Excel: nunca se recalculan distinto.
 *
 * Una lista vacía (campañas, canales, estados, fases) significa «todas».
 */
final readonly class FiltrosReporteCampanas
{
    /** Fase «contratados» del filtro: el tablero la divide en capacitación inicial / laborando. */
    public const FASE_CONTRATADOS = 'contratado';

    /**
     * @param  list<int>  $campanaIds
     * @param  list<CanalReclutamiento>  $canales
     * @param  list<EstadoCampanaReclutamiento>  $estados
     * @param  list<string>  $fases  Claves de fasesCandidato().
     */
    public function __construct(
        public Carbon $desde,
        public Carbon $hasta,
        public CriterioFechaReporteCampanas $criterio = CriterioFechaReporteCampanas::Vigencia,
        public array $campanaIds = [],
        public array $canales = [],
        public array $estados = [],
        public array $fases = [],
        public ?int $empresaId = null,
        public ?int $sucursalId = null,
        public ?int $departamentoId = null,
        public ?int $puestoId = null,
        public ?int $vacanteId = null,
        public ?int $responsableId = null,
    ) {}

    /**
     * Sin fechas: el mes en curso. Con solo mes/año (enlace anterior de la
     * pantalla de Campañas): ese mes completo.
     *
     * @param  array<string, mixed>  $datos  Datos validados.
     */
    public static function desdeArreglo(array $datos): self
    {
        $mes = self::entero($datos['mes'] ?? null);
        $anio = self::entero($datos['anio'] ?? null);
        $base = $mes !== null && $anio !== null ? Carbon::create($anio, $mes, 1) : Carbon::today();
        $base = ($base ?? Carbon::today())->startOfDay();

        $desde = self::fecha($datos['desde'] ?? null);
        $hasta = self::fecha($datos['hasta'] ?? null);

        if ($desde === null && $hasta === null) {
            $desde = $base->copy()->startOfMonth();
            $hasta = $base->copy()->endOfMonth()->startOfDay();
        } elseif ($desde === null) {
            // Solo fin: desde el inicio de ese mes.
            $desde = $hasta->copy()->startOfMonth();
        } elseif ($hasta === null) {
            // Solo inicio: hasta hoy (o el mismo día si el inicio es futuro).
            $hasta = Carbon::today()->max($desde)->copy()->startOfDay();
        }

        return new self(
            desde: $desde,
            hasta: $hasta,
            criterio: CriterioFechaReporteCampanas::tryFrom((string) ($datos['criterio_fecha'] ?? '')) ?? CriterioFechaReporteCampanas::Vigencia,
            campanaIds: self::enteros($datos['campanas'] ?? []),
            canales: array_values(array_filter(array_map(fn ($v) => CanalReclutamiento::tryFrom((string) $v), self::lista($datos['canales'] ?? [])))),
            estados: array_values(array_filter(array_map(fn ($v) => EstadoCampanaReclutamiento::tryFrom((string) $v), self::lista($datos['estados'] ?? [])))),
            fases: array_values(array_intersect(array_map(fn ($v) => (string) $v, self::lista($datos['fases'] ?? [])), array_keys(self::fasesCandidato()))),
            empresaId: self::entero($datos['empresa_id'] ?? null),
            sucursalId: self::entero($datos['sucursal_id'] ?? null),
            departamentoId: self::entero($datos['departamento_id'] ?? null),
            puestoId: self::entero($datos['puesto_id'] ?? null),
            vacanteId: self::entero($datos['vacante_id'] ?? null),
            responsableId: self::entero($datos['responsable_id'] ?? null),
        );
    }

    /**
     * Fases del tablero por las que se puede filtrar a los candidatos.
     * Contratado se ofrece como una sola fase (Inducción … Contrato
     * indeterminado son el mismo estado de candidato).
     *
     * @return array<string, string>
     */
    public static function fasesCandidato(): array
    {
        $fases = [];

        foreach (EstadoCandidato::fases() as $clave => $etiqueta) {
            if (in_array($clave, EstadoCandidato::FASES_CONTRATADO, true)) {
                continue;
            }

            if ($clave === 'rechazado') {
                $fases[self::FASE_CONTRATADOS] = 'Contratados';
            }

            $fases[$clave] = $etiqueta;
        }

        return $fases;
    }

    /**
     * Estados de candidato que corresponden a las fases elegidas.
     *
     * @return list<string>
     */
    public function estadosCandidato(): array
    {
        $estados = [];

        foreach (EstadoCandidato::cases() as $estado) {
            $fase = $estado === EstadoCandidato::Contratado ? self::FASE_CONTRATADOS : $estado->faseCanonica();

            if (in_array($fase, $this->fases, true)) {
                $estados[] = $estado->value;
            }
        }

        return $estados;
    }

    /**
     * true si el reporte no cuenta a TODOS los candidatos de cada campaña
     * (por fecha de registro o por fase): los contratados se recuentan
     * sobre el grupo filtrado.
     */
    public function acotaCandidatos(): bool
    {
        return $this->criterio === CriterioFechaReporteCampanas::Registro || $this->fases !== [];
    }

    /**
     * Valores para la query string / props de la pantalla (cadenas, como
     * los usa el frontend).
     *
     * @return array<string, string|list<string>>
     */
    public function aQuery(): array
    {
        return [
            'desde' => $this->desde->toDateString(),
            'hasta' => $this->hasta->toDateString(),
            'criterio_fecha' => $this->criterio->value,
            'campanas' => array_map(fn (int $id) => (string) $id, $this->campanaIds),
            'canales' => array_map(fn (CanalReclutamiento $c) => $c->value, $this->canales),
            'estados' => array_map(fn (EstadoCampanaReclutamiento $e) => $e->value, $this->estados),
            'fases' => $this->fases,
            'empresa_id' => $this->empresaId !== null ? (string) $this->empresaId : '',
            'sucursal_id' => $this->sucursalId !== null ? (string) $this->sucursalId : '',
            'departamento_id' => $this->departamentoId !== null ? (string) $this->departamentoId : '',
            'puesto_id' => $this->puestoId !== null ? (string) $this->puestoId : '',
            'vacante_id' => $this->vacanteId !== null ? (string) $this->vacanteId : '',
            'responsable_id' => $this->responsableId !== null ? (string) $this->responsableId : '',
        ];
    }

    private static function fecha(mixed $valor): ?Carbon
    {
        if (! is_string($valor) || $valor === '') {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $valor)?->startOfDay();
    }

    private static function entero(mixed $valor): ?int
    {
        return is_numeric($valor) && (int) $valor > 0 ? (int) $valor : null;
    }

    /**
     * @return list<mixed>
     */
    private static function lista(mixed $valor): array
    {
        return is_array($valor) ? array_values($valor) : [];
    }

    /**
     * @return list<int>
     */
    private static function enteros(mixed $valor): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($v) => self::entero($v), self::lista($valor)), fn (?int $v) => $v !== null)));
    }
}
