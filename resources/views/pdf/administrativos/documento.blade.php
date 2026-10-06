{{--
    Documento administrativo HTML (recibo de nómina, finiquito, comprobante,
    constancia). PRESENTACIÓN solamente: todo lo que se muestra sale de $d
    (datos ya calculados por su servicio: ReciboNominaService,
    FiniquitoService…) y el estilo sale de $diseno (versión de plantilla en
    Administración → Documentos maestros → Documentos administrativos).
    La plantilla no puede cambiar montos ni fechas: solo cómo se ven.
    CSS de impresión real: @page, thead repetido, break-inside.
--}}
@php
    $p = $diseno['page'];
    $t = $diseno['typography'];
    $par = $diseno['paragraph'];
    $c = $diseno['colors'];
    $h = $diseno['header'];
    $f = $diseno['footer'];
    $bg = $diseno['background'];
    $tb = $diseno['tables'];
    $sg = $diseno['signatures'];
    $sec = $diseno['sections'];
    $mm = fn ($v) => sprintf('%.2fmm', (float) $v);
    $pt = fn ($v) => sprintf('%.2fpt', (float) $v);
    $bordeTabla = (float) $tb['border_width_px'] > 0 ? sprintf('%.2fpx solid %s', (float) $tb['border_width_px'], $tb['border_color']) : 'none';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo_documento }}</title>
    <style>
        @page {
            @if ($motor === 'browsershot') size: {{ $p['size'] === 'a4' ? 'A4' : 'letter' }} {{ $p['orientation'] }}; @endif
            margin: {{ $mm($p['margins_mm']['top']) }} {{ $mm($p['margins_mm']['right']) }} {{ $mm($p['margins_mm']['bottom']) }} {{ $mm($p['margins_mm']['left']) }};
        }
        * { box-sizing: border-box; }
        /* Solo body: en DomPDF el margen de <html> ES el margen de página. */
        body { margin: 0; padding: 0; }
        body {
            font-family: {!! $fuente_css !!};
            font-size: {{ $pt($t['base_size_pt']) }};
            line-height: {{ (float) $t['line_height'] }};
            color: {{ $t['color'] }};
            font-weight: {{ (int) $t['weight'] }};
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        p {
            margin: {{ $pt($par['space_before_pt']) }} {{ $mm($par['indent_right_mm']) }} {{ $pt($par['space_after_pt']) }} {{ $mm($par['indent_left_mm']) }};
            text-indent: {{ $mm($par['first_line_mm']) }};
            text-align: {{ $par['align'] }};
        }
        .fondo {
            position: {{ $bg['apply_to'] === 'first_page' ? 'absolute' : 'fixed' }};
            top: -{{ $mm($p['margins_mm']['top']) }};
            left: -{{ $mm($p['margins_mm']['left']) }};
            width: {{ $mm($pagina_mm['ancho']) }};
            height: {{ $mm($pagina_mm['alto']) }};
            z-index: -1;
            opacity: {{ max(0, min(100, (int) $bg['opacity'])) / 100 }};
            overflow: hidden;
        }
        .fondo img {
            width: 100%;
            height: 100%;
            @if ($bg['fit'] === 'contain') object-fit: contain; @elseif ($bg['fit'] === 'cover') object-fit: cover; @else object-fit: fill; @endif
            object-position: {{ $bg['position'] }};
        }
        .contenido { padding: {{ $mm($bg['safe_area_mm']) }}; }
        .encabezado {
            min-height: {{ $mm($h['height_mm']) }};
            border-bottom: 2px solid {{ $c['primary'] }};
            padding-bottom: 3mm;
            margin-bottom: {{ $mm($sec['spacing_mm']) }};
            text-align: {{ $h['logo_position'] }};
        }
        .encabezado img.logo { height: {{ $mm($h['logo_height_mm']) }}; margin-bottom: 2mm; }
        .marca { font-size: {{ $pt((float) $t['base_size_pt'] * 0.85) }}; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: {{ $c['accent'] }}; margin: 0; text-indent: 0; }
        h1 { font-size: {{ $pt((float) $sec['title_size_pt'] * 1.5) }}; margin: 1mm 0; color: {{ $c['primary'] }}; }
        .subtitulo { color: {{ $c['muted'] }}; font-size: {{ $pt((float) $t['base_size_pt'] * 0.9) }}; margin: 0; text-indent: 0; }
        .seccion { margin-top: {{ $mm($sec['spacing_mm']) }}; }
        .seccion-titulo {
            font-size: {{ $pt($sec['title_size_pt']) }};
            font-weight: 700;
            color: {{ $sec['title_color'] }};
            margin: 0 0 2mm;
            text-indent: 0;
            @if ($sec['divider']) border-top: 1px solid {{ $tb['border_color'] }}; padding-top: 2mm; @endif
        }
        table { width: 100%; border-collapse: collapse; }
        table.datos td { padding: 1mm 0; vertical-align: top; width: 50%; font-size: {{ $pt($tb['font_size_pt']) }}; }
        table.datos .etiqueta { color: {{ $c['muted'] }}; }
        table.conceptos { font-size: {{ $pt($tb['font_size_pt']) }}; }
        table.conceptos th, table.conceptos td { border: {{ $bordeTabla }}; padding: {{ $mm($tb['padding_mm']) }}; }
        table.conceptos thead { display: {{ $tb['repeat_header'] ? 'table-header-group' : 'table-row-group' }}; }
        @if ($tb['avoid_row_break']) table.conceptos tr { page-break-inside: avoid; break-inside: avoid; } @endif
        @if ($tb['header_style'] === 'solid')
            table.conceptos th { background: {{ $c['table_header_bg'] }}; color: {{ $c['table_header_text'] }}; text-align: left; text-transform: uppercase; font-size: {{ $pt((float) $tb['font_size_pt'] * 0.85) }}; letter-spacing: 0.3px; }
        @elseif ($tb['header_style'] === 'light')
            table.conceptos th { border-bottom: 2px solid {{ $c['primary'] }}; color: {{ $c['primary'] }}; text-align: left; }
        @else
            table.conceptos th { text-align: left; }
        @endif
        @if ($tb['zebra']) table.conceptos tbody tr:nth-child(even) td { background: rgba(0, 0, 0, 0.035); } @endif
        table.conceptos .monto { text-align: right; white-space: nowrap; }
        table.conceptos tr.subtotal td { font-weight: 700; }
        table.conceptos tr.total td { font-weight: 700; background: {{ $c['total_bg'] }}; color: {{ $c['total_text'] }}; font-size: {{ $pt((float) $tb['font_size_pt'] * 1.2) }}; }
        .leyenda { margin-top: {{ $mm($sec['spacing_mm']) }}; padding: 2.5mm 3mm; border-left: 3px solid {{ $c['accent'] }}; color: {{ $c['muted'] }}; font-size: {{ $pt((float) $t['base_size_pt'] * 0.9) }}; page-break-inside: avoid; break-inside: avoid; }
        .leyenda p { margin: 0; text-indent: 0; }
        .firmas { margin-top: {{ $mm($sg['gap_mm']) }}; width: 100%; page-break-inside: avoid; break-inside: avoid; }
        .firmas td { text-align: center; vertical-align: bottom; padding: 0 4mm; font-size: {{ $pt((float) $t['base_size_pt'] * 0.95) }}; }
        .firmas .linea { border-top: 1px solid {{ $t['color'] }}; width: {{ $mm($sg['line_width_mm']) }}; margin: 0 auto 1.5mm; }
        .pie {
            position: fixed;
            bottom: -{{ $mm(max(0, (float) $p['margins_mm']['bottom'] - (float) $f['distance_mm'])) }};
            left: 0; right: 0;
            text-align: center;
            color: {{ $c['muted'] }};
            font-size: {{ $pt((float) $t['base_size_pt'] * 0.8) }};
        }
        .pie p { margin: 0; text-indent: 0; text-align: center; }
    </style>
</head>
<body>
    @if ($fondo_data_uri)
        <div class="fondo"><img src="{{ $fondo_data_uri }}" alt=""></div>
    @endif

    {{-- Chrome dibuja el pie (texto + número de página) en su propio margen; DomPDF, aquí. --}}
    @if ($motor === 'dompdf' && $f['show'] && $f['text'] !== '')
        <div class="pie"><p>{{ $f['text'] }}</p></div>
    @endif

    <div class="contenido">
        @if ($h['show'])
            <div class="encabezado">
                @if ($logo_data_uri)
                    <img class="logo" src="{{ $logo_data_uri }}" alt="Logo">
                @endif
                @if ($h['brand_text'] !== '')
                    <p class="marca">{{ $h['brand_text'] }}</p>
                @endif
                <h1>{{ $contenido['titulo'] }}</h1>
                @if ($contenido['subtitulo'] !== '')
                    <p class="subtitulo">{{ $contenido['subtitulo'] }}</p>
                @endif
            </div>
        @endif

        @include('pdf.administrativos.familias.'.$vista_familia)

        @if (($sec['visibles']['leyenda'] ?? true) && $contenido['leyenda'] !== '')
            <div class="leyenda"><p>{{ $contenido['leyenda'] }}</p></div>
        @endif

        @if ($sg['show'] && count($sg['blocks']) > 0)
            <table class="firmas">
                <tr>
                    @foreach ($sg['blocks'] as $bloque)
                        <td style="text-align: {{ $sg['position'] === 'split' ? 'center' : $sg['position'] }}">
                            <div class="linea"></div>
                            {{ $bloque['label'] }}
                        </td>
                    @endforeach
                </tr>
            </table>
        @endif
    </div>
</body>
</html>
