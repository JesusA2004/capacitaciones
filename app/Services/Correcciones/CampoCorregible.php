<?php

namespace App\Services\Correcciones;

use App\Enums\ModoCorreccion;

/**
 * Un dato tal como lo ve quien corrige: valor vigente, modo efectivo según el
 * estado del registro y por qué no se puede corregir, si es el caso.
 */
final readonly class CampoCorregible
{
    /**
     * @param  list<array{valor: string, etiqueta: string}>  $opciones
     * @param  list<mixed>  $reglas
     */
    public function __construct(
        public string $campo,
        public string $etiqueta,
        public string $tipo,
        public ModoCorreccion $modo,
        public ?string $valor,
        public ?string $legible,
        public bool $permitido,
        public bool $confidencial = false,
        public ?string $explicacion = null,
        public array $opciones = [],
        public array $reglas = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function aArray(): array
    {
        return [
            'campo' => $this->campo,
            'etiqueta' => $this->etiqueta,
            'tipo' => $this->tipo,
            'modo' => $this->modo->value,
            'modo_etiqueta' => $this->modo->etiqueta(),
            'valor' => $this->valor,
            'legible' => $this->legible,
            'editable' => $this->permitido && $this->modo->admiteCaptura(),
            'explicacion' => $this->explicacion ?? (! $this->permitido ? 'No tienes permiso para corregir este dato.' : null),
            'opciones' => $this->opciones,
        ];
    }
}
