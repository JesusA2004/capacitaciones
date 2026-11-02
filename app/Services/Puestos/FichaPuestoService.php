<?php

namespace App\Services\Puestos;

use App\Enums\BandaSalarial;
use App\Models\ImportacionExcel;
use App\Models\MatrizMerito;
use App\Models\Puesto;
use App\Models\TabuladorSalarial;
use App\Models\User;
use App\Services\Salarios\MatrizMeritoService;
use App\Services\Salarios\PosicionSalarialService;
use App\Services\Salarios\TabuladorImportService;
use App\Services\Salarios\TabuladorSalarialService;
use Illuminate\Support\Collection;

/**
 * «Puestos y salarios»: un solo módulo para el catálogo de puestos y su
 * tabulador. Arma lo que ve cada pantalla a partir de los servicios que ya
 * existen (MovimientoPuestoService, App\Services\Salarios\*); los importes
 * solo salen cuando quien mira tiene salarios.ver (dato sensible).
 */
class FichaPuestoService
{
    public function __construct(
        private readonly MovimientoPuestoService $movimientos,
        private readonly PosicionSalarialService $posiciones,
        private readonly TabuladorSalarialService $tabulador,
        private readonly MatrizMeritoService $matriz,
    ) {}

    /**
     * Personas del puesto; con salarios.ver, cada una con su sueldo y su
     * posición en el tabulador vigente del puesto.
     *
     * @return list<array<string, mixed>>
     */
    public function personas(Puesto $puesto, User $usuario, bool $conSueldos): array
    {
        $personas = $this->movimientos->colaboradoresDe($puesto, $usuario);

        if (! $conSueldos) {
            return $personas;
        }

        $posiciones = $this->posiciones->posiciones($usuario)
            ->filter(fn (array $fila): bool => (int) $fila['puesto_id'] === (int) $puesto->id)
            ->keyBy('id');

        return array_map(function (array $persona) use ($posiciones): array {
            /** @var array<string, mixed>|null $fila */
            $fila = $posiciones->get($persona['id']);

            return [
                ...$persona,
                'sueldo' => $fila['sueldo'] ?? null,
                'compa_ratio' => $fila['compa_ratio'] ?? null,
                'banda' => $fila['banda'] ?? null,
                'banda_etiqueta' => $fila['banda_etiqueta'] ?? null,
                'desempeno_etiqueta' => $fila['desempeno_etiqueta'] ?? null,
                'tope' => $fila['tope'] ?? null,
                'margen' => $fila['margen'] ?? null,
            ];
        }, $personas);
    }

    /**
     * Rango vigente del puesto, o null si aún no se captura.
     *
     * @return array{minimo: float, medio: float, maximo: float, fuente: string, fecha_actualizacion: string, version: int}|null
     */
    public function rangoVigente(Puesto $puesto): ?array
    {
        $vigente = $this->tabulador->vigentes()->get($puesto->id);

        return $vigente instanceof TabuladorSalarial ? [
            'minimo' => (float) $vigente->minimo,
            'medio' => (float) $vigente->medio,
            'maximo' => (float) $vigente->maximo,
            'fuente' => (string) $vigente->fuente,
            'fecha_actualizacion' => $vigente->fecha_actualizacion->toDateString(),
            'version' => (int) $vigente->version,
        ] : null;
    }

    /**
     * @return list<array{version: int, minimo: float, medio: float, maximo: float, fuente: string, fecha_actualizacion: string, vigente_desde: string, vigente_hasta: string|null, origen: string, usuario: string|null}>
     */
    public function versiones(Puesto $puesto): array
    {
        return array_values($this->tabulador->versiones($puesto->id)->map(fn (TabuladorSalarial $v): array => [
            'version' => (int) $v->version,
            'minimo' => (float) $v->minimo,
            'medio' => (float) $v->medio,
            'maximo' => (float) $v->maximo,
            'fuente' => (string) $v->fuente,
            'fecha_actualizacion' => $v->fecha_actualizacion->toDateString(),
            'vigente_desde' => $v->vigente_desde->toDateString(),
            'vigente_hasta' => $v->vigente_hasta?->toDateString(),
            'origen' => (string) $v->origen,
            'usuario' => $v->usuario?->name,
        ])->all());
    }

    /**
     * Tabulador vigente y compa-ratio de cada puesto (por puesto_id), para
     * las columnas del catálogo.
     *
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return Collection<int, array<string, mixed>>
     */
    public function tabuladorPorPuesto(Collection $filas): Collection
    {
        return collect($this->posiciones->renglonesTabulador($filas))->keyBy('puesto_id');
    }

    /**
     * Todo lo salarial del módulo: tarjetas, alertas accionables (quién está
     * fuera de rango y en qué puesto), matriz de mérito e importación.
     *
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return array<string, mixed>
     */
    public function salarios(Collection $filas, User $usuario, ?int $importacionId): array
    {
        $fueraDeRango = $filas
            ->filter(fn (array $f): bool => in_array($f['banda'], [BandaSalarial::BajoMinimo->value, BandaSalarial::SobreMaximo->value], true))
            ->map(fn (array $f): array => [
                'id' => $f['id'],
                'nombre' => $f['nombre'],
                'puesto_id' => $f['puesto_id'],
                'puesto' => $f['puesto'],
                'sucursal' => $f['sucursal'],
                'sueldo' => $f['sueldo'],
                'banda' => $f['banda'],
                'banda_etiqueta' => $f['banda_etiqueta'],
                'compa_ratio' => $f['compa_ratio'],
            ])
            ->sortBy('compa_ratio')
            ->values();

        $sinTabulador = collect($this->posiciones->renglonesTabulador($filas))
            ->filter(fn (array $r): bool => $r['version'] === null)
            ->map(fn (array $r): array => ['puesto_id' => $r['puesto_id'], 'puesto' => $r['puesto'], 'colaboradores' => $r['colaboradores']])
            ->sortByDesc('colaboradores')
            ->values();

        $puedeImportar = $usuario->can('salarios.importar');

        return [
            'resumen' => $this->posiciones->resumen($filas),
            'fuera_de_rango' => $fueraDeRango->all(),
            'sin_tabulador' => $sinTabulador->all(),
            'matriz' => $this->matriz->vigente()?->celdas,
            'historial_matriz' => array_values($this->matriz->historial()->map(fn (MatrizMerito $m): array => [
                'id' => $m->id,
                'vigente_desde' => $m->vigente_desde->toDateString(),
                'vigente_hasta' => $m->vigente_hasta?->toDateString(),
                'motivo' => $m->motivo,
                'usuario' => $m->usuario?->name,
            ])->all()),
            'importacion' => $puedeImportar && $importacionId !== null && $importacionId > 0
                ? ImportacionExcel::query()->where('tipo', TabuladorImportService::TIPO)->whereKey($importacionId)->first()?->only([
                    'id', 'nombre_archivo', 'estado', 'total_filas', 'filas_aplicadas', 'filas_revision', 'filas_sin_cambios', 'filas_error', 'detalle',
                ])
                : null,
        ];
    }
}
