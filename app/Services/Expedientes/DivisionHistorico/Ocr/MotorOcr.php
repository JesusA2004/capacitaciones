<?php

namespace App\Services\Expedientes\DivisionHistorico\Ocr;

/**
 * OCR LOCAL de una página de PDF escaneado para la división del expediente
 * histórico (docs/DIVISION_EXPEDIENTE_HISTORICO.md). Nunca un servicio
 * externo: el documento no sale del servidor. La implementación se elige en
 * config('expedientes.division_historico.ocr.driver') (AppServiceProvider).
 *
 * Sin motor disponible la división NO falla: esas páginas quedan como
 * «Página escaneada sin OCR disponible» y se reportan explícitamente.
 */
interface MotorOcr
{
    public function disponible(): bool;

    /** Nombre legible del motor para auditoría («Tesseract 5.3.0 (spa)»). */
    public function nombre(): string;

    /** Por qué no está disponible (null si sí lo está). */
    public function motivoNoDisponible(): ?string;

    /**
     * Texto reconocido de la página $pagina (1-based) del PDF en $rutaPdf,
     * o null si no se reconoció nada. Nunca lanza.
     */
    public function textoDePagina(string $rutaPdf, int $pagina): ?string;
}
