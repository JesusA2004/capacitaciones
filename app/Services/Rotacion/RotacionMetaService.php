<?php

namespace App\Services\Rotacion;

use App\Enums\SegmentoRotacion;
use App\Models\RotacionMeta;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Metas mensuales de rotación por segmento. Un cambio agrega una fila con su
 * vigencia y nunca edita las anteriores: cada mes se mide contra la meta vigente
 * en ese mes y el historial explica cualquier cambio de color.
 */
class RotacionMetaService
{
    /** @var Collection<int, RotacionMeta>|null */
    private ?Collection $metas = null;

    /**
     * @return array{verde_hasta: float, ambar_hasta: float}|null
     */
    public function paraMes(SegmentoRotacion $segmento, CarbonImmutable $mes): ?array
    {
        $fecha = $mes->startOfMonth()->toDateString();
        $meta = $this->todas()
            ->filter(fn (RotacionMeta $m): bool => $m->segmento === $segmento && $m->vigente_desde->toDateString() <= $fecha)
            ->sortBy([
                fn (RotacionMeta $a, RotacionMeta $b): int => $b->vigente_desde->toDateString() <=> $a->vigente_desde->toDateString(),
                fn (RotacionMeta $a, RotacionMeta $b): int => $b->id <=> $a->id,
            ])
            ->first();

        return $meta instanceof RotacionMeta
            ? ['verde_hasta' => (float) $meta->verde_hasta, 'ambar_hasta' => (float) $meta->ambar_hasta]
            : null;
    }

    /**
     * Verde hasta el límite verde, ámbar hasta el límite ámbar, rojo arriba.
     * Sin índice (mes sin datos) no hay color.
     */
    public function semaforo(SegmentoRotacion $segmento, ?float $indice, CarbonImmutable $mes): ?string
    {
        $meta = $this->paraMes($segmento, $mes);

        if ($indice === null || $meta === null) {
            return null;
        }

        return match (true) {
            $indice <= $meta['verde_hasta'] => 'verde',
            $indice <= $meta['ambar_hasta'] => 'ambar',
            default => 'rojo',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function panel(CarbonImmutable $mes): array
    {
        return [
            'vigentes' => array_map(fn (SegmentoRotacion $s): array => [
                'segmento' => $s->value,
                'etiqueta' => $s->etiqueta(),
                ...($this->paraMes($s, $mes) ?? ['verde_hasta' => null, 'ambar_hasta' => null]),
            ], SegmentoRotacion::cases()),
            'historial' => array_values(RotacionMeta::query()->with('registradoPor:id,name,apellidos')->latest('id')->limit(30)->get()
                ->map(fn (RotacionMeta $m): array => [
                    'id' => $m->id,
                    'segmento' => $m->segmento->etiqueta(),
                    'verde_hasta' => (float) $m->verde_hasta,
                    'ambar_hasta' => (float) $m->ambar_hasta,
                    'vigente_desde' => $m->vigente_desde->format('Y-m'),
                    'motivo' => $m->motivo,
                    'registrado_por' => $m->registradoPor !== null ? trim(sprintf('%s %s', $m->registradoPor->name, $m->registradoPor->apellidos)) : null,
                    'registrado_en' => $m->created_at?->toDateTimeString(),
                ])->all()),
        ];
    }

    /**
     * @param  array<string, mixed>  $datos  Validados por GuardarMetaRotacionRequest.
     */
    public function registrar(array $datos, User $actor): RotacionMeta
    {
        $this->metas = null;

        return RotacionMeta::query()->create([
            'segmento' => SegmentoRotacion::from((string) $datos['segmento']),
            'verde_hasta' => $datos['verde_hasta'],
            'ambar_hasta' => $datos['ambar_hasta'],
            'vigente_desde' => sprintf('%s-01', (string) $datos['vigente_desde']),
            'motivo' => trim((string) $datos['motivo']),
            'registrado_por' => $actor->id,
        ]);
    }

    /**
     * @return Collection<int, RotacionMeta>
     */
    private function todas(): Collection
    {
        return $this->metas ??= RotacionMeta::query()->get();
    }
}
