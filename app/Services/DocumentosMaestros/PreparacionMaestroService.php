<?php

namespace App\Services\DocumentosMaestros;

use App\Services\DocumentosMaestros\Docx\DetectorCamposDocx;
use App\Services\DocumentosMaestros\Docx\PreparadorMasterDocx;
use App\Services\DocumentosMaestros\Pdf\NormalizadorPdf;
use App\Services\DocumentosMaestros\Pdf\RenderizadorOverlayMaestro;
use Throwable;

/**
 * ORIGINAL → MASTER técnico, sin intervención del usuario.
 *
 *  - DOCX: se aplican las reglas versionadas (PreparadorMasterDocx) y el
 *    detector revisa que no quede ningún blanco/dato de ejemplo sin mapear.
 *  - PDF: se normaliza a PDF clásico (NormalizadorPdf) y se valida que las
 *    páginas y campos del overlay existan.
 *
 * estado: listo | con_pendientes (no se puede activar) | referencia (no
 * operativo: procedimiento, contrato de clientes).
 */
class PreparacionMaestroService
{
    public function __construct(
        private readonly PreparadorMasterDocx $preparador,
        private readonly DetectorCamposDocx $detector,
        private readonly NormalizadorPdf $normalizador,
        private readonly RenderizadorOverlayMaestro $overlay,
        private readonly CatalogoMaestrosService $catalogo,
    ) {}

    /**
     * @param  array<string, mixed>  $definicion
     * @return array{master: string, extension: string, mapping: array<string, mixed>, analisis: array<string, mixed>, estado: string}
     */
    public function preparar(string $original, array $definicion, int $version): array
    {
        $motor = (string) ($definicion['motor'] ?? 'docx');
        $operativo = (bool) ($definicion['operativo'] ?? true);

        return $motor === 'pdf_overlay'
            ? $this->prepararPdf($original, $definicion, $operativo)
            : $this->prepararDocx($original, $definicion, $version, $operativo);
    }

    /**
     * @param  array<string, mixed>  $definicion
     * @return array{master: string, extension: string, mapping: array<string, mixed>, analisis: array<string, mixed>, estado: string}
     */
    private function prepararDocx(string $original, array $definicion, int $version, bool $operativo): array
    {
        $reglas = $this->catalogo->reglas($definicion, $version);
        $resultado = $this->preparador->preparar($original, $reglas);
        $analisis = $this->detector->analizar($resultado['documento'], $resultado['instancias'], $definicion);
        $analisis['reglas'] = count($reglas);
        $analisis['reglas_aplicadas'] = $resultado['aplicadas'];
        $analisis['reglas_pendientes'] = $resultado['pendientes'];

        $pendientes = count($analisis['pendientes']) + count($resultado['pendientes']);
        $estado = ! $operativo ? 'referencia' : ($pendientes === 0 ? 'listo' : 'con_pendientes');

        return [
            'master' => $resultado['master'],
            'extension' => 'docx',
            'mapping' => [
                'motor' => 'docx',
                'instancias' => $resultado['instancias'],
                'campos' => $analisis['campos'],
                'version_reglas' => hash('sha256', (string) json_encode($reglas)),
            ],
            'analisis' => $analisis,
            'estado' => $estado,
        ];
    }

    /**
     * @param  array<string, mixed>  $definicion
     * @return array{master: string, extension: string, mapping: array<string, mixed>, analisis: array<string, mixed>, estado: string}
     */
    private function prepararPdf(string $original, array $definicion, bool $operativo): array
    {
        $pendientes = [];

        try {
            $master = $this->normalizador->normalizar($original);
            $paginas = $this->overlay->paginas($master);
        } catch (Throwable $e) {
            return [
                'master' => $original,
                'extension' => 'pdf',
                'mapping' => ['motor' => 'pdf_overlay', 'campos' => []],
                'analisis' => ['detectados' => 0, 'mapeados' => 0, 'firmas' => 0, 'pendientes' => [['referencia' => 'pdf', 'tipo' => 'pdf_no_procesable', 'contexto' => $e->getMessage()]], 'campos' => [], 'medios' => []],
                'estado' => $operativo ? 'con_pendientes' : 'referencia',
            ];
        }

        $numeros = array_map(fn (array $p): int => $p['numero'], $paginas);
        $seleccion = array_values(array_map('intval', (array) ($definicion['paginas'] ?? $numeros)));
        $campos = array_values(array_filter((array) ($definicion['campos'] ?? []), 'is_array'));

        foreach ($seleccion as $pagina) {
            if (! in_array($pagina, $numeros, true)) {
                $pendientes[] = ['referencia' => 'pagina '.$pagina, 'tipo' => 'pagina_inexistente', 'contexto' => 'El original no tiene esa página.'];
            }
        }

        foreach ($campos as $campo) {
            if (! in_array((int) ($campo['pagina'] ?? 1), $seleccion, true)) {
                $pendientes[] = ['referencia' => (string) ($campo['campo'] ?? '?'), 'tipo' => 'campo_fuera_de_rango', 'contexto' => 'El campo apunta a una página que no forma parte del documento.'];
            }
        }

        if ($operativo && $campos === []) {
            $pendientes[] = ['referencia' => 'overlay', 'tipo' => 'sin_campos', 'contexto' => 'El formato no tiene campos de overlay definidos.'];
        }

        $nombres = array_values(array_unique(array_map(fn (array $c): string => (string) $c['campo'], $campos)));

        return [
            'master' => $master,
            'extension' => 'pdf',
            'mapping' => [
                'motor' => 'pdf_overlay',
                'campos' => $campos,
                'paginas' => $seleccion,
                'copias_offset_y' => array_map('floatval', (array) ($definicion['copias_offset_y'] ?? [0.0])),
                'requeridos' => array_values(array_map('strval', (array) ($definicion['requeridos'] ?? []))),
                'normalizado' => $master !== $original,
            ],
            'analisis' => [
                'detectados' => count($campos),
                'mapeados' => count($campos) - count(array_filter($pendientes, fn (array $p): bool => $p['tipo'] === 'campo_fuera_de_rango')),
                'firmas' => 0,
                'pendientes' => $pendientes,
                'campos' => $nombres,
                'medios' => [],
                'paginas' => $paginas,
            ],
            'estado' => ! $operativo ? 'referencia' : ($pendientes === [] ? 'listo' : 'con_pendientes'),
        ];
    }
}
