<?php

namespace App\Services\DocumentosMaestros\Calidad;

use App\Services\Formatos\Motor\ConversorDocxPdf;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Huella del servidor donde se valida el diseño de un documento: sistema
 * operativo, conversor (Word / LibreOffice y su versión) y el conjunto de
 * fuentes instaladas. Se guarda con cada QA visual
 * (document_templates.qa_entorno); si el servidor cambia (otro SO, otra
 * versión de LibreOffice, fuentes distintas) la versión se marca «Revalidar
 * diseño en este servidor» — un QA aprobado en Windows NO garantiza Linux.
 *
 * @phpstan-type Huella array{sistema: string, conversores: list<string>, libreoffice: string|null, fuentes_hash: string, hash: string}
 */
class HuellaEntornoService
{
    private const CACHE = 'documentos-maestros:huella-entorno';

    public function __construct(
        private readonly ConversorDocxPdf $conversor,
        private readonly DiagnosticoFuentesService $fuentes,
    ) {}

    /**
     * @return Huella
     */
    public function actual(): array
    {
        /** @var Huella $huella */
        $huella = Cache::remember(self::CACHE, now()->addMinutes(30), function (): array {
            $familias = array_map('mb_strtolower', array_keys($this->fuentes->disponibles()));
            sort($familias);
            $base = [
                'sistema' => PHP_OS_FAMILY,
                'conversores' => $this->conversor->conversoresFieles(),
                'libreoffice' => $this->versionLibreOffice(),
                'fuentes_hash' => hash('sha256', implode('|', $familias)),
            ];

            return [...$base, 'hash' => hash('sha256', (string) json_encode($base))];
        });

        return $huella;
    }

    public function invalidar(): void
    {
        Cache::forget(self::CACHE);
    }

    /**
     * ¿El QA guardado se hizo en un entorno distinto al actual?
     *
     * @param  array<string, mixed>|null  $guardada
     */
    public function cambio(?array $guardada): bool
    {
        return is_array($guardada) && isset($guardada['hash']) && $guardada['hash'] !== $this->actual()['hash'];
    }

    private function versionLibreOffice(): ?string
    {
        $ruta = config('formatos_oficiales.libreoffice');

        if (! is_string($ruta) || $ruta === '') {
            return null;
        }

        try {
            $salida = Process::timeout(20)->run([$ruta, '--version']);

            return trim(strtok($salida->output(), "\n") ?: '') ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
