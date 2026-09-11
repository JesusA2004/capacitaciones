<?php

namespace App\Support\Export\Pdf;

use App\Support\Export\ChartData;

/**
 * Dibuja App\Support\Export\ChartData como HTML/CSS puro (tablas + divs con
 * dimensiones en px calculadas en PHP), NO como SVG: dompdf renderiza tablas
 * y cajas con dimensiones fijas de forma mucho más confiable que SVG con
 * arcos/paths, así que en vez de una dona real se dibuja una barra
 * horizontal 100%-apilada + leyenda (visualmente equivalente, cero riesgo de
 * artefactos de render). Misma paleta de marca que el Dashboard
 * (resources/css/app.css: --brand-primary/--brand-secondary/--chart-3/4/5).
 * Los estilos (.grafica, .barra-apilada, .grafica-barras, ...) viven una
 * sola vez en resources/views/pdf/layout.blade.php.
 */
final class ChartRenderer
{
    private const PALETA = ['#64d64b', '#2dc7d3', '#274754', '#e9c468', '#f4a462'];

    private const ALTO_BARRA_PX = 110;

    public static function render(ChartData $datos, string $titulo = ''): string
    {
        return $datos->tipo === 'distribucion'
            ? self::distribucion($datos, $titulo)
            : self::barras($datos, $titulo);
    }

    /**
     * Barra 100%-apilada + leyenda: para distribuciones de pocas categorías
     * (equivalente visual de una dona, sin arcos SVG).
     */
    private static function distribucion(ChartData $datos, string $titulo): string
    {
        $valores = $datos->series[0]['valores'];
        $total = array_sum($valores) ?: 1;

        $segmentos = '';
        $leyenda = '';

        foreach ($datos->categorias as $i => $categoria) {
            $valor = $valores[$i];
            $porcentaje = round(($valor / $total) * 100, 1);
            $color = self::PALETA[$i % count(self::PALETA)];

            if ($valor > 0) {
                $segmentos .= '<td style="width:'.max($porcentaje, 0.5).'%;background-color:'.$color.';">&nbsp;</td>';
            }

            $leyenda .= '<div class="leyenda-item">'
                .'<span class="leyenda-punto" style="background-color:'.$color.';"></span>'
                .e($categoria).' <strong>'.self::formatoNumero($valor).'</strong>'
                .' <span class="leyenda-pct">('.self::formatoNumero($porcentaje).'%)</span>'
                .'</div>';
        }

        $tituloHtml = $titulo !== '' ? '<p class="grafica-titulo">'.e($titulo).'</p>' : '';

        return '<div class="grafica">'.$tituloHtml
            .'<table class="barra-apilada" cellspacing="0" cellpadding="0"><tr>'.$segmentos.'</tr></table>'
            .'<div class="leyenda">'.$leyenda.'</div>'
            .'</div>';
    }

    /**
     * Barras verticales (una serie o multi-serie agrupada), altura en px
     * calculada proporcional al valor máximo de todas las series.
     */
    private static function barras(ChartData $datos, string $titulo): string
    {
        $maxValor = 0.0;

        foreach ($datos->series as $serie) {
            $maxValor = max($maxValor, max($serie['valores'] ?: [0]));
        }

        $maxValor = $maxValor ?: 1.0;
        $multiSerie = count($datos->series) > 1;

        $columnas = '';
        $etiquetas = '';

        foreach ($datos->categorias as $i => $categoria) {
            $barrasSerie = '';

            foreach ($datos->series as $s => $serie) {
                $valor = $serie['valores'][$i];
                $alto = $valor > 0 ? max(2, (int) round(($valor / $maxValor) * self::ALTO_BARRA_PX)) : 0;
                $color = self::PALETA[$s % count(self::PALETA)];

                $barrasSerie .= '<td class="barra-celda" style="width:'.(int) (100 / max(count($datos->series), 1)).'%;">'
                    .($valor > 0 ? '<div class="valor-barra">'.self::formatoNumero($valor).'</div>' : '')
                    .'<div style="height:'.$alto.'px;background-color:'.$color.';" class="barra"></div>'
                    .'</td>';
            }

            $columnas .= '<td class="grafica-columna"><table cellspacing="0" cellpadding="0" class="grafica-columna-interna"><tr>'.$barrasSerie.'</tr></table></td>';
            $etiquetas .= '<td class="etiqueta-barra">'.e(self::etiquetaCorta($categoria)).'</td>';
        }

        $tituloHtml = $titulo !== '' ? '<p class="grafica-titulo">'.e($titulo).'</p>' : '';

        $leyendaSeries = '';

        if ($multiSerie) {
            foreach ($datos->series as $s => $serie) {
                $color = self::PALETA[$s % count(self::PALETA)];
                $leyendaSeries .= '<div class="leyenda-item"><span class="leyenda-punto" style="background-color:'.$color.';"></span>'.e($serie['nombre']).'</div>';
            }
        }

        $notaRecorte = $datos->recortado
            ? '<p class="grafica-nota">Mostrando las '.count($datos->categorias).' categorías con mayor valor.</p>'
            : '';

        return '<div class="grafica">'.$tituloHtml
            .'<table class="grafica-barras" cellspacing="0" cellpadding="0"><tr>'.$columnas.'</tr><tr>'.$etiquetas.'</tr></table>'
            .($multiSerie ? '<div class="leyenda">'.$leyendaSeries.'</div>' : '')
            .$notaRecorte
            .'</div>';
    }

    private static function etiquetaCorta(string $texto): string
    {
        return mb_strlen($texto) > 14 ? mb_substr($texto, 0, 13).'…' : $texto;
    }

    private static function formatoNumero(float $valor): string
    {
        return floor($valor) == $valor ? number_format($valor, 0, ',', '.') : number_format($valor, 1, ',', '.');
    }
}
