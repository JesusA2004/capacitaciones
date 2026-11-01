<?php

namespace App\Services\Documentos\Versiones;

use App\Enums\CategoriaDocumento;
use App\Services\DocumentosLaborales\MotorDocumentalService;

/**
 * PDF ya generado, en memoria y sin persistir, con las opciones que
 * MotorDocumentalService::registrarPdf() necesita para registrarlo.
 *
 * @phpstan-import-type OpcionesRegistro from MotorDocumentalService
 */
final readonly class DocumentoRenderizado
{
    /**
     * @param  OpcionesRegistro  $opciones
     */
    public function __construct(
        public string $pdf,
        public string $titulo,
        public CategoriaDocumento $categoria,
        public array $opciones,
    ) {}
}
