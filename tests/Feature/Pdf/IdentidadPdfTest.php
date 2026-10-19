<?php

use App\Enums\FamiliaAdministrativa;
use App\Services\DocumentosAdministrativos\DisenoAdministrativoService;
use App\Support\Export\ChartData;
use App\Support\Export\Pdf\ChartRenderer;

/**
 * Identidad institucional PEOPLE en los PDF (sección 11, P1-23..P1-27).
 *
 * La paleta vive UNA sola vez en config/pdf_identidad.php; estos tests
 * afirman que los PDF administrativos y de reporte usan el petróleo
 * institucional (#315B59) y que la familia de color RETIRADA (azul marino
 * #0d1b4b, cian #2dc7d3, teal #0d3e43, lima #64d64b, pizarra #274754, bronce
 * viejo #a8834a/#b08d57) ya no aparece. Sin Chrome: solo HTML/aserciones.
 */

/** Hex de marca retirados que ningún PDF debe volver a pintar. */
$prohibidos = ['#0d1b4b', '#2dc7d3', '#0d3e43', '#64d64b', '#274754', '#a8834a', '#b08d57'];

it('publica la paleta institucional PEOPLE como fuente única de color', function () use ($prohibidos) {
    $pal = config('pdf_identidad');

    expect($pal)->toBeArray()
        ->and($pal['petroleo'])->toBe('#315B59')
        ->and($pal['dorado'])->toBe('#C7A66B')
        ->and($pal['grafica'][0])->toBe('#315B59');

    $serializada = strtolower((string) json_encode($pal));

    foreach ($prohibidos as $hex) {
        expect($serializada)->not->toContain($hex);
    }
});

it('arma el diseño administrativo por defecto en petróleo, no en azul marino', function () {
    $diseno = app(DisenoAdministrativoService::class)->porDefecto(FamiliaAdministrativa::Finiquito);

    expect(strtolower($diseno['colors']['primary']))->toBe('#315b59')
        ->and(strtolower($diseno['colors']['table_header_bg']))->toBe('#315b59')
        ->and(strtolower($diseno['colors']['table_header_text']))->toBe('#ffffff')
        ->and(strtolower($diseno['colors']['accent']))->toBe('#c7a66b')
        ->and(strtolower($diseno['sections']['title_color']))->toBe('#315b59')
        ->and(strtolower((string) json_encode($diseno['colors'])))->not->toContain('#0d1b4b');
});

it('dibuja las gráficas de reporte con la secuencia institucional, sin teal', function () {
    $datos = ChartData::fromTable(['Sucursal', 'Total'], [['Norte', 7], ['Sur', 4]]);

    $html = strtolower(ChartRenderer::render($datos, 'Distribución'));

    expect($html)->toContain('#315b59')
        ->and($html)->not->toContain('#0d3e43');
});

it('renderiza el reporte de RH con identidad institucional y sin azul ni cian', function () use ($prohibidos) {
    $html = strtolower(view('pdf.reporte-rh', [
        'titulo' => 'Reporte de prueba',
        'columnas' => ['Sucursal', 'Total'],
        'filas' => [['Norte', 7], ['Sur', 4]],
    ])->render());

    expect($html)->toContain('#315b59');

    foreach ($prohibidos as $hex) {
        expect($html)->not->toContain($hex);
    }
});
