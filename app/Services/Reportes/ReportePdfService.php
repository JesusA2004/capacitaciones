<?php

namespace App\Services\Reportes;

use App\Enums\MotorPdf;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Pdf\PdfRendererException;
use App\Services\Pdf\PdfRendererFactory;
use App\Services\Reportes\Propios\ReportePropioService;
use App\Support\Export\ChartData;
use App\Support\Export\Pdf\GraficaSvg;

/**
 * PDF de reportes con Chrome (Browsershot) y la identidad Mr. Lana. Siempre
 * Chrome: si no está disponible se lanza PdfRendererException con un mensaje
 * legible, nunca se cae en silencio a otro motor con otro diseño.
 *
 * @phpstan-import-type Resultado from ReportePropioService
 *
 * @phpstan-type Vista array{titulo: string, subtitulo: string, columnas: list<array{etiqueta: string, numerica: bool}>, filas: list<list<string>>, totales: list<string>|null, filtrosTexto: list<string>, avisos: list<string>, graficaSvg: string|null, graficaTitulo: string, color: string, encabezado: bool, ajuste: string, tamano: string, orientacion: string}
 */
class ReportePdfService
{
    public function __construct(private readonly PdfRendererFactory $pdf) {}

    /**
     * @param  Resultado  $resultado
     *
     * @throws PdfRendererException
     */
    public function dePropio(array $resultado): string
    {
        $diseno = $resultado['diseno'];
        $color = $this->colorHex($diseno['color'] ?? null);
        $grafica = $resultado['grafica'];
        $tipos = array_column($resultado['columnas'], 'tipo');

        return $this->imprimir([
            'titulo' => $resultado['titulo'],
            'subtitulo' => is_string($diseno['subtitulo'] ?? null) ? $diseno['subtitulo'] : '',
            'columnas' => array_map(fn (array $c): array => ['etiqueta' => $c['etiqueta'], 'numerica' => in_array($c['tipo'], ['numero', 'moneda'], true)], $resultado['columnas']),
            'filas' => array_map(fn (array $fila): array => array_map(fn ($v, $i) => ReportePropioService::formatear($v, $tipos[$i] ?? 'texto'), $fila, array_keys($fila)), $resultado['filas']),
            'totales' => $resultado['totales'] === null ? null : array_map(fn ($v, $i) => $v === null ? '' : ReportePropioService::formatear($v, is_string($v) ? 'texto' : ($tipos[$i] ?? 'numero')), $resultado['totales'], array_keys($resultado['totales'])),
            'filtrosTexto' => ($diseno['mostrar_filtros'] ?? true) ? $resultado['filtros_texto'] : [],
            'avisos' => $resultado['avisos'],
            'graficaSvg' => $grafica === null ? null : GraficaSvg::render($grafica['tipo'], $grafica['categorias'], $grafica['valores'], $color),
            'graficaTitulo' => $grafica['titulo'] ?? '',
            'color' => $color,
            'encabezado' => (bool) ($diseno['encabezado'] ?? true),
            'ajuste' => is_string($diseno['ajuste'] ?? null) ? $diseno['ajuste'] : 'normal',
            'tamano' => is_string($diseno['tamano'] ?? null) ? $diseno['tamano'] : 'letter',
            'orientacion' => is_string($diseno['orientacion'] ?? null) ? $diseno['orientacion'] : 'landscape',
        ]);
    }

    /**
     * Reportes del catálogo: la misma tabla {titulo, columnas, filas} de pantalla.
     *
     * @param  array{titulo: string, columnas: array<int, string>, filas: array<int, array<int, string|int|float|null>>, avisos?: list<string>, sin_grafica?: bool}  $reporte
     * @param  list<string>  $filtrosTexto
     *
     * @throws PdfRendererException
     */
    public function deCatalogo(array $reporte, array $filtrosTexto = []): string
    {
        $color = $this->colorHex('petroleo');
        $filas = array_values($reporte['filas']);
        $numericas = [];

        foreach (array_values($reporte['columnas']) as $i => $columna) {
            $conDato = array_filter($filas, fn (array $f): bool => ($f[$i] ?? null) !== null && ($f[$i] ?? null) !== '');
            $numericas[$i] = $conDato !== [] && array_filter($conDato, fn (array $f): bool => ! is_numeric($f[$i])) === [];
        }

        $datos = ($reporte['sin_grafica'] ?? false) ? null : ChartData::fromTable($reporte['columnas'], $reporte['filas']);
        $svg = null;

        if ($datos !== null && count($datos->series) === 1) {
            $tipo = match ($datos->tipo) {
                'distribucion' => 'pastel',
                'tiempo' => 'lineas',
                default => 'barras',
            };
            $svg = GraficaSvg::render($tipo, array_values($datos->categorias), array_values($datos->series[0]['valores']), $color);
        }

        return $this->imprimir([
            'titulo' => $reporte['titulo'],
            'subtitulo' => '',
            'columnas' => array_map(fn (string $c, int $i): array => ['etiqueta' => $c, 'numerica' => $numericas[$i] ?? false], array_values($reporte['columnas']), array_keys(array_values($reporte['columnas']))),
            'filas' => array_map(fn (array $f): array => array_map(fn ($v) => $v === null || $v === '' ? '—' : (string) $v, array_values($f)), $filas),
            'totales' => null,
            'filtrosTexto' => $filtrosTexto,
            'avisos' => $reporte['avisos'] ?? [],
            'graficaSvg' => $svg,
            'graficaTitulo' => $reporte['titulo'],
            'color' => $color,
            'encabezado' => true,
            'ajuste' => count($reporte['columnas']) > 9 ? 'compacto' : 'normal',
            'tamano' => 'letter',
            'orientacion' => 'landscape',
        ]);
    }

    /**
     * @param  Vista  $vista
     *
     * @throws PdfRendererException
     */
    private function imprimir(array $vista): string
    {
        $html = view('pdf.reportes.reporte', [...$vista, 'generado' => now('America/Mexico_City')->format('d/m/Y H:i')])->render();

        return $this->pdf->para(MotorPdf::Browsershot)->renderizar(
            $html,
            new OpcionesPdf(
                tamano: $vista['tamano'],
                orientacion: $vista['orientacion'],
                numerarPaginas: true,
                textoPie: 'MR. LANA PEOPLE · Reportes de Recursos Humanos',
            ),
        );
    }

    private function colorHex(mixed $token): string
    {
        $colores = (array) config('reportes.colores', []);
        $elegido = is_string($token) && isset($colores[$token]) ? $colores[$token] : ($colores['petroleo'] ?? []);

        return is_array($elegido) && is_string($elegido['hex'] ?? null) ? $elegido['hex'] : '#0D3E43';
    }
}
