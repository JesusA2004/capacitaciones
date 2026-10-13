<?php

namespace App\Services\DocumentosMaestros;

use App\Models\DocumentTemplate;
use App\Services\DocumentosMaestros\Calidad\DiagnosticoFuentesChromeService;
use App\Services\DocumentosMaestros\Calidad\DiagnosticoFuentesService;
use App\Services\DocumentosMaestros\Calidad\HuellaEntornoService;
use App\Services\DocumentosMaestros\Calidad\RasterizadorPdf;
use App\Services\DocumentosMaestros\Deteccion\LectorTextoPdf;
use App\Services\DocumentosMaestros\Deteccion\OcrLocalService;
use App\Services\Formatos\Motor\ConversorDocxPdf;
use App\Services\Pdf\DetectorChromeHeadlessService;
use Illuminate\Support\Facades\Cache;
use Spatie\Browsershot\Browsershot;

/**
 * Centro de salud documental (encabezado de Documentos maestros y
 * Sistema → Diagnóstico): cuántos formatos están listos y si la
 * infraestructura que los genera está completa EN ESTE servidor.
 *
 * Lo caro (abrir Chrome para medir fuentes) se calcula al pulsar «Revisar
 * fuentes» y se guarda; la página nunca abre Chrome por sí sola.
 *
 * @phpstan-type Chequeo array{clave: string, etiqueta: string, estado: 'ok'|'advertencia'|'error'|'no_instalado', detalle: string|null}
 */
class SaludDocumentalService
{
    private const CACHE_FUENTES = 'documentos-maestros:fuentes-chrome';

    private const CACHE_HERRAMIENTAS = 'documentos-maestros:herramientas';

    public function __construct(
        private readonly DetectorChromeHeadlessService $chrome,
        private readonly ConversorDocxPdf $conversor,
        private readonly RasterizadorPdf $rasterizador,
        private readonly LectorTextoPdf $poppler,
        private readonly OcrLocalService $ocr,
        private readonly DiagnosticoFuentesChromeService $fuentesChrome,
        private readonly DiagnosticoFuentesService $fuentesSistema,
        private readonly HuellaEntornoService $huella,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $filas  DocumentosMaestrosAdminService::listar()
     * @return array{formatos: array{total: int, listos: int, revision: int, bloqueados: int}, infraestructura: list<Chequeo>}
     */
    public function resumen(array $filas): array
    {
        $operativas = array_filter($filas, fn (array $f): bool => (bool) ($f['operativo'] ?? true));
        $estado = fn (array $f): string => (string) ($f['estado_ejecutivo'] ?? '');

        return [
            'formatos' => [
                'total' => count($operativas),
                'listos' => count(array_filter($operativas, fn (array $f): bool => $estado($f) === 'listo')),
                'revision' => count(array_filter($operativas, fn (array $f): bool => $estado($f) === 'requiere_revision')),
                'bloqueados' => count(array_filter($operativas, fn (array $f): bool => in_array($estado($f), ['bloqueado', 'sin_formato'], true))),
            ],
            'infraestructura' => $this->infraestructura(),
        ];
    }

    /**
     * @return list<Chequeo>
     */
    public function infraestructura(): array
    {
        /** @var array{poppler: bool, ocr: bool, ocr_version: string|null} $herramientas */
        $herramientas = Cache::remember(self::CACHE_HERRAMIENTAS, now()->addMinutes(30), fn (): array => [
            'poppler' => $this->poppler->disponible(),
            'ocr' => $this->ocr->disponible(),
            'ocr_version' => $this->ocr->version(),
        ]);
        $rutaChrome = config('pdf.browsershot.chrome_path') ?: $this->chrome->detectar()['ruta'];
        $conversores = $this->conversor->conversoresFieles();

        return [
            ['clave' => 'chrome', 'etiqueta' => 'Chrome', 'estado' => $rutaChrome ? 'ok' : 'error', 'detalle' => $rutaChrome ? null : 'No se encontró Chrome/Chromium para imprimir.'],
            ['clave' => 'browsershot', 'etiqueta' => 'Browsershot', 'estado' => class_exists(Browsershot::class) ? 'ok' : 'error', 'detalle' => null],
            ['clave' => 'conversor', 'etiqueta' => $conversores === [] ? 'Word / LibreOffice' : implode(' / ', array_map('ucfirst', $conversores)), 'estado' => $conversores === [] ? 'error' : 'ok', 'detalle' => $conversores === [] ? 'Sin conversor fiel: los contratos en Word no se generan.' : null],
            ['clave' => 'poppler', 'etiqueta' => 'Poppler', 'estado' => $herramientas['poppler'] && $this->rasterizador->disponible() ? 'ok' : ($this->rasterizador->disponible() ? 'advertencia' : 'error'), 'detalle' => $herramientas['poppler'] ? null : 'Sin pdftotext: la detección de PDF solo usa líneas dibujadas.'],
            ['clave' => 'ocr', 'etiqueta' => 'OCR local', 'estado' => $herramientas['ocr'] ? 'ok' : 'no_instalado', 'detalle' => $herramientas['ocr'] ? $herramientas['ocr_version'] : 'Opcional: solo para PDF escaneados (Tesseract).'],
            $this->chequeoFuentes(),
            $this->chequeoRevalidar(),
        ];
    }

    /**
     * «Revisar fuentes»: limpia SOLO los cachés de fuentes y del entorno
     * y vuelve a medir con el Chrome real.
     *
     * @return array<string, mixed>
     */
    public function revisarFuentes(): array
    {
        $this->fuentesSistema->invalidarCache();
        $this->huella->invalidar();
        Cache::forget(self::CACHE_HERRAMIENTAS);

        $familias = [...$this->fuentesChrome->familiasDocumentos(), ...$this->fuentesDeMasters()];
        $resultado = [...$this->fuentesChrome->diagnosticar($familias), 'revisado_en' => now()->toIso8601String()];
        Cache::forever(self::CACHE_FUENTES, $resultado);

        return $resultado;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function ultimoDiagnosticoFuentes(): ?array
    {
        $guardado = Cache::get(self::CACHE_FUENTES);

        return is_array($guardado) ? $guardado : null;
    }

    /**
     * @return Chequeo
     */
    private function chequeoFuentes(): array
    {
        $diagnostico = $this->ultimoDiagnosticoFuentes();
        $faltanDocx = $this->fuentesFaltantesDocx();

        if ($diagnostico === null) {
            return ['clave' => 'fuentes', 'etiqueta' => 'Fuentes', 'estado' => $faltanDocx === [] ? 'advertencia' : 'error', 'detalle' => $faltanDocx !== [] ? 'Faltan: '.implode(', ', $faltanDocx) : 'Sin revisar en Chrome: pulsa «Revisar fuentes».'];
        }

        $sustituidas = array_values(array_map(fn (array $f): string => (string) $f['familia'], array_filter((array) ($diagnostico['fuentes'] ?? []), fn (mixed $f): bool => is_array($f) && ($f['sustituida'] ?? false))));
        $faltan = array_values(array_unique([...$faltanDocx, ...$sustituidas]));

        return ['clave' => 'fuentes', 'etiqueta' => 'Fuentes', 'estado' => $faltan === [] ? 'ok' : 'advertencia', 'detalle' => $faltan === [] ? null : 'Se sustituyen: '.implode(', ', array_slice($faltan, 0, 6))];
    }

    /**
     * @return Chequeo
     */
    private function chequeoRevalidar(): array
    {
        $cambiaron = DocumentTemplate::query()->where('activo', true)->whereNotNull('qa_entorno')->get(['id', 'qa_entorno'])
            ->filter(fn (DocumentTemplate $t): bool => $this->huella->cambio($t->qa_entorno))
            ->count();

        return ['clave' => 'entorno', 'etiqueta' => 'Validado aquí', 'estado' => $cambiaron === 0 ? 'ok' : 'advertencia', 'detalle' => $cambiaron === 0 ? null : sprintf('%d formato(s): revalidar diseño en este servidor.', $cambiaron)];
    }

    /**
     * Fuentes que usan los masters DOCX activos y no están en el servidor
     * (según su último diagnóstico de QA).
     *
     * @return list<string>
     */
    private function fuentesFaltantesDocx(): array
    {
        $faltan = [];

        foreach (DocumentTemplate::query()->where('activo', true)->whereNotNull('diagnostico_fuentes')->get(['id', 'diagnostico_fuentes']) as $master) {
            foreach ((array) $master->diagnostico_fuentes as $fuente) {
                if (is_array($fuente) && ! ($fuente['disponible'] ?? true)) {
                    $faltan[(string) $fuente['fuente']] = true;
                }
            }
        }

        return array_keys($faltan);
    }

    /**
     * @return list<string>
     */
    private function fuentesDeMasters(): array
    {
        $familias = [];

        foreach (DocumentTemplate::query()->where('activo', true)->whereNotNull('diagnostico_fuentes')->get(['id', 'diagnostico_fuentes']) as $master) {
            foreach ((array) $master->diagnostico_fuentes as $fuente) {
                if (is_array($fuente) && is_string($fuente['fuente'] ?? null)) {
                    $familias[$fuente['fuente']] = true;
                }
            }
        }

        return array_keys($familias);
    }
}
