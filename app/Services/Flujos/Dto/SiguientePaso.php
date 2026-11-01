<?php

namespace App\Services\Flujos\Dto;

use App\Enums\FlujoOperativo;
use App\Enums\ModoFlujo;
use App\Enums\TipoSiguientePaso;

/**
 * Dónde está el proceso, qué sigue y qué falta. Mismo contrato para Inertia y API.
 *
 * @phpstan-type Paso array{clave: string, etiqueta: string, estado: string}
 */
final readonly class SiguientePaso
{
    /** Máximo de enlaces junto a la acción principal. */
    public const MAX_RELACIONADAS = 2;

    /** @var list<EnlaceFlujo> */
    public array $relacionadas;

    /**
     * @param  array{clave: string, etiqueta: string}  $estado
     * @param  array{rol: string, nombre: string|null}  $responsable
     * @param  list<Paso>  $pasos
     * @param  list<string>  $bloqueos
     * @param  list<EnlaceFlujo|null>  $relacionadas  los null (sin permiso) se descartan
     */
    public function __construct(
        public FlujoOperativo $flujo,
        public ModoFlujo $modo,
        public array $estado,
        public TipoSiguientePaso $tipo,
        public ?AccionFlujo $accion,
        public string $mensaje,
        public array $responsable = ['rol' => '—', 'nombre' => null],
        public ?int $progreso = null,
        public array $pasos = [],
        public array $bloqueos = [],
        array $relacionadas = [],
    ) {
        $this->relacionadas = array_slice(array_values(array_filter($relacionadas)), 0, self::MAX_RELACIONADAS);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'flujo' => $this->flujo->value,
            'modo' => $this->modo->value,
            'estado' => $this->estado,
            'tipo' => $this->tipo->value,
            'accion' => $this->accion?->toArray(),
            'mensaje' => $this->mensaje,
            'responsable' => $this->responsable,
            'progreso' => $this->progreso,
            'pasos' => $this->pasos,
            'bloqueos' => $this->bloqueos,
            'relacionadas' => array_map(fn (EnlaceFlujo $e): array => $e->toArray(), $this->relacionadas),
        ];
    }
}
