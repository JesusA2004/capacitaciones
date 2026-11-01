<?php

namespace App\Services\Documentos\Versiones;

use App\Models\GeneratedDocument;
use App\Models\User;
use Closure;

/**
 * Escritura al emitir expresada como función, sin una clase por cada módulo.
 */
final class AlEmitirConFuncion implements AlEmitirDocumento
{
    public function __construct(private readonly Closure $funcion) {}

    public function __invoke(GeneratedDocument $documento, ?GeneratedDocument $anterior, array $correcciones, ?string $motivo, User $actor): void
    {
        ($this->funcion)($documento, $anterior);
    }
}
