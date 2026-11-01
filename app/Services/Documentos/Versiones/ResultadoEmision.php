<?php

namespace App\Services\Documentos\Versiones;

use App\Models\GeneratedDocument;

/**
 * `creado` es false cuando los datos no cambiaron: se devolvió la vigente.
 */
final readonly class ResultadoEmision
{
    public function __construct(
        public GeneratedDocument $documento,
        public bool $creado,
    ) {}
}
