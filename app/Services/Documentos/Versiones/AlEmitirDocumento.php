<?php

namespace App\Services\Documentos\Versiones;

use App\Models\GeneratedDocument;
use App\Models\User;

/**
 * Escritura propia del módulo dentro de la transacción de la emisión (edición y
 * bitácora de una baja): si falla, tampoco queda la versión.
 */
interface AlEmitirDocumento
{
    /**
     * @param  array<string, string>  $correcciones
     */
    public function __invoke(GeneratedDocument $documento, ?GeneratedDocument $anterior, array $correcciones, ?string $motivo, User $actor): void;
}
