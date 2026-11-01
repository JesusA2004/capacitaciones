<?php

namespace App\Services\Documentos\Versiones;

/**
 * Dato que RH puede corregir por documento. `original` es lo que hoy dice la
 * fuente; `obsoleta` trae la corrección anterior cuando la fuente cambió después
 * de hacerla: no se reaplica sola, RH decide.
 */
final readonly class CampoEditable
{
    public function __construct(
        public string $clave,
        public string $etiqueta,
        public string $tipo,
        public string $valor,
        public string $original,
        public ?string $obsoleta = null,
    ) {}

    /**
     * @return array{clave: string, etiqueta: string, tipo: string, valor: string, original: string, obsoleta: string|null}
     */
    public function aArray(): array
    {
        return [
            'clave' => $this->clave,
            'etiqueta' => $this->etiqueta,
            'tipo' => $this->tipo,
            'valor' => $this->valor,
            'original' => $this->original,
            'obsoleta' => $this->obsoleta,
        ];
    }
}
