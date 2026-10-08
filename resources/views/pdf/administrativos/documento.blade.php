{{--
    Documento administrativo HTML (recibo de nómina, finiquito, permiso,
    comprobante, constancia) con la estructura del FORMATO OFICIAL de RH
    (docs/formatosRH/*.docx): encabezado patronal (barra bronce, logo, razón
    social, RFC, registro patronal, domicilio y C.P.), caja de título a la
    derecha, bandas de sección y líneas de firma física.

    PRESENTACIÓN solamente: todo lo que se muestra sale de $d (datos ya
    calculados por su servicio) y el estilo sale de $diseno (versión de
    plantilla en Documentos maestros → Documentos administrativos). La
    plantilla no puede cambiar montos ni fechas. Solo tablas (sin flexbox):
    DomPDF y Chrome lo imprimen igual.
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
    $borde = (float) $tb['border_width_px'] > 0 ? sprintf('%.2fpx solid %s', (float) $tb['border_width_px'], $tb['border_color']) : 'none';
    $suave = $c['fondo_suave'] ?? '#f3f6fa';
    $empresa = is_array($d['empresa'] ?? null) ? $d['empresa'] : [];
    $caja = is_array($d['caja'] ?? null) ? $d['caja'] : [];
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
        table { width: 100%; border-collapse: collapse; }

        /* ---------- Encabezado patronal + caja de título ---------- */
        table.encabezado td { vertical-align: top; }
        .patron { border-left: 2.2mm solid {{ $c['accent'] }}; padding-left: 3mm; }
        .patron img.logo { height: {{ $mm($h['logo_height_mm']) }}; margin: 0 0 1.5mm; display: block; }
        .razon { font-size: {{ $pt((float) $t['base_size_pt'] * 1.18) }}; font-weight: 700; color: {{ $c['primary'] }}; margin: 0; text-indent: 0; text-transform: uppercase; line-height: 1.2; }
        .fiscal { font-size: {{ $pt((float) $t['base_size_pt'] * 0.92) }}; margin: 0.6mm 0 0; text-indent: 0; color: {{ $t['color'] }}; }
        .fiscal b.l { color: {{ $c['muted'] }}; font-weight: 700; }
        .fiscal span.sep { display: inline-block; width: 4mm; }
        table.caja { border: {{ $borde }}; }
        table.caja th { background: {{ $c['table_header_bg'] }}; color: {{ $c['table_header_text'] }}; font-size: {{ $pt((float) $sec['title_size_pt'] * 1.65) }}; letter-spacing: 1.5px; padding: 1.6mm 2mm; text-align: center; font-weight: 700; }
        table.caja td.sub { background: {{ $suave }}; color: {{ $c['muted'] }}; font-style: italic; font-size: {{ $pt((float) $t['base_size_pt'] * 0.82) }}; text-align: center; padding: 0.8mm 2mm; border-bottom: {{ $borde }}; }
        table.caja td.dato { text-align: center; padding: 1.8mm 1.5mm; border-top: {{ $borde }}; vertical-align: top; }
        table.caja td.dato + td.dato { border-left: {{ $borde }}; }
        .regla { border-top: 2px solid {{ $c['primary'] }}; margin: {{ $mm((float) $sec['spacing_mm'] * 0.9) }} 0 {{ $mm($sec['spacing_mm']) }}; height: 0; }

        /* ---------- Etiquetas y valores ---------- */
        .lbl { display: block; font-size: {{ $pt((float) $sec['title_size_pt'] * 0.86) }}; font-weight: 700; letter-spacing: 0.8px; color: {{ $c['muted'] }}; text-transform: uppercase; }
        .val { display: block; font-size: {{ $pt((float) $t['base_size_pt'] * 1.12) }}; color: {{ $t['color'] }}; margin-top: 0.8mm; min-height: 3.6mm; }
        .val.fuerte { font-weight: 700; }

        /* ---------- Secciones con banda ---------- */
        .seccion { margin-top: {{ $mm($sec['spacing_mm']) }}; page-break-inside: avoid; break-inside: avoid; }
        table.grid { border: {{ $borde }}; }
        table.grid td { border: {{ $borde }}; padding: {{ $mm($tb['padding_mm']) }} 2mm; vertical-align: top; height: 9mm; }
        table.grid td.banda, table.grid th.banda { background: {{ $c['table_header_bg'] }}; color: {{ $c['table_header_text'] }}; font-weight: 700; font-size: {{ $pt($sec['title_size_pt']) }}; letter-spacing: 1.4px; text-transform: uppercase; height: auto; padding: 0.9mm 2mm; text-align: left; }
        table.grid td.centro { text-align: center; }
        table.grid td.suave { background: {{ $suave }}; height: auto; padding: 0.9mm 2mm; }

        /* ---------- Conceptos (percepciones | deducciones) ---------- */
        table.conceptos { border: {{ $borde }}; font-size: {{ $pt($tb['font_size_pt']) }}; }
        table.conceptos th, table.conceptos td { border-left: {{ $borde }}; border-right: {{ $borde }}; padding: {{ $mm($tb['padding_mm']) }} 1.6mm; }
        table.conceptos thead { display: {{ $tb['repeat_header'] ? 'table-header-group' : 'table-row-group' }}; }
        @if ($tb['avoid_row_break']) table.conceptos tr { page-break-inside: avoid; break-inside: avoid; } @endif
        table.conceptos th.banda { text-transform: uppercase; background: {{ $c['table_header_bg'] }}; color: {{ $c['table_header_text'] }}; text-align: center; font-size: {{ $pt($sec['title_size_pt']) }}; letter-spacing: 1.4px; padding: 0.9mm; }
        table.conceptos th.col { background: {{ $suave }}; color: {{ $c['primary'] }}; font-size: {{ $pt((float) $sec['title_size_pt'] * 0.9) }}; letter-spacing: 0.8px; text-transform: uppercase; border-bottom: {{ $borde }}; padding: 0.8mm 1.6mm; text-align: left; }
        table.conceptos td { border-bottom: {{ $borde }}; height: 8mm; }
        @if ($tb['zebra']) table.conceptos tbody tr:nth-child(even) td { background: rgba(0, 0, 0, 0.03); } @endif
        table.conceptos .clave { text-align: center; width: 11mm; }
        table.conceptos .num { text-align: center; width: 12mm; }
        table.conceptos .monto { text-align: right; white-space: nowrap; width: 24mm; }
        table.conceptos tr.total td { background: {{ $suave }}; font-weight: 700; color: {{ $c['primary'] }}; height: auto; padding: 1.2mm 1.6mm; letter-spacing: 0.6px; }

        /* ---------- Total a pagar ---------- */
        table.pagar { border: {{ $borde }}; }
        table.pagar td { vertical-align: middle; padding: 1.8mm 2.5mm; }
        table.pagar td.etq { background: {{ $c['table_header_bg'] }}; color: {{ $c['table_header_text'] }}; font-weight: 700; letter-spacing: 1.8px; font-size: {{ $pt((float) $t['base_size_pt'] * 1.35) }}; width: 32%; text-align: center; }
        table.pagar td.imp { background: {{ $c['total_bg'] }}; color: {{ $c['total_text'] }}; font-weight: 700; font-size: {{ $pt((float) $t['base_size_pt'] * 1.45) }}; width: 22%; text-align: center; }
        table.pagar td.letra { border-left: {{ $borde }}; }

        /* ---------- Casillas del formato ---------- */
        .chk { display: inline-block; width: 3.4mm; height: 3.4mm; border: 1px solid {{ $c['primary'] }}; text-align: center; line-height: 3.2mm; font-size: {{ $pt((float) $t['base_size_pt'] * 1.05) }}; font-weight: 700; color: {{ $c['primary'] }}; vertical-align: -0.6mm; margin-right: 1.6mm; }
        .opcion { font-weight: 700; color: {{ $c['primary'] }}; letter-spacing: 0.6px; text-transform: uppercase; }
        .nota-italica { font-style: italic; color: {{ $c['muted'] }}; font-size: {{ $pt((float) $t['base_size_pt'] * 0.9) }}; }

        .leyenda { margin-top: {{ $mm($sec['spacing_mm']) }}; padding: 2mm 2.5mm; border: {{ $borde }}; background: {{ $suave }}; font-size: {{ $pt((float) $t['base_size_pt'] * 0.95) }}; page-break-inside: avoid; break-inside: avoid; }
        .leyenda p { margin: 0; text-indent: 0; text-align: justify; }
        .autorizado { margin-top: 2mm; font-size: {{ $pt((float) $t['base_size_pt'] * 0.82) }}; color: {{ $c['muted'] }}; }
        .firmas { margin-top: {{ $mm($sg['gap_mm']) }}; width: 100%; page-break-inside: avoid; break-inside: avoid; }
        .firmas td { text-align: center; vertical-align: bottom; padding: 0 3mm; font-size: {{ $pt((float) $t['base_size_pt'] * 1.05) }}; font-weight: 700; color: {{ $c['primary'] }}; }
        .firmas .linea { border-top: 1.4px solid {{ $c['primary'] }}; width: {{ $mm($sg['line_width_mm']) }}; margin: 0 auto 1.2mm; }
        .firmas .detalle { display: block; font-weight: 400; color: {{ $c['muted'] }}; font-size: {{ $pt((float) $t['base_size_pt'] * 0.85) }}; }
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
            <table class="encabezado">
                <tr>
                    <td style="width: 62%; padding-right: 4mm;">
                        <div class="patron">
                            @if ($logo_data_uri)
                                <img class="logo" src="{{ $logo_data_uri }}" alt="Logo">
                            @endif
                            @if ($h['brand_text'] !== '')
                                <p class="razon">{{ $h['brand_text'] }}</p>
                            @endif
                            @if (($empresa['rfc'] ?? '') !== '' || ($empresa['registro_patronal'] ?? '') !== '')
                                <p class="fiscal">
                                    @if (($empresa['rfc'] ?? '') !== '')<b class="l">RFC:</b> <b>{{ $empresa['rfc'] }}</b>@endif
                                    @if (($empresa['registro_patronal'] ?? '') !== '')<span class="sep"></span><b class="l">Registro patronal IMSS:</b> <b>{{ $empresa['registro_patronal'] }}</b>@endif
                                </p>
                            @endif
                            @if (($empresa['domicilio_fiscal'] ?? '') !== '')
                                <p class="fiscal"><b class="l">Domicilio fiscal:</b> {{ $empresa['domicilio_fiscal'] }}</p>
                            @endif
                            @if (($empresa['codigo_postal'] ?? '') !== '')
                                <p class="fiscal"><b class="l">Lugar de expedición (C.P.):</b> {{ $empresa['codigo_postal'] }}</p>
                            @endif
                        </div>
                    </td>
                    <td style="width: 38%;">
                        <table class="caja">
                            <tr><th colspan="2">{{ $contenido['titulo'] }}</th></tr>
                            @if ($contenido['subtitulo'] !== '')
                                <tr><td class="sub" colspan="2">{{ $contenido['subtitulo'] }}</td></tr>
                            @endif
                            @foreach ($caja as $fila)
                                <tr>
                                    @foreach ($fila as $celda)
                                        <td class="dato" @if (count($fila) === 1) colspan="2" @endif>
                                            <span class="lbl">{{ $celda[0] }}</span>
                                            <span class="val fuerte">{{ $celda[1] }}</span>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </table>
                    </td>
                </tr>
            </table>
            <div class="regla"></div>
        @endif

        @include('pdf.administrativos.familias.'.$vista_familia)

        @if (($sec['visibles']['leyenda'] ?? true) && $contenido['leyenda'] !== '')
            <div class="leyenda"><p>{{ $contenido['leyenda'] }}</p></div>
        @endif

        @if ($sg['show'] && count($sg['blocks']) > 0)
            <table class="firmas">
                <tr>
                    @if ($sg['position'] === 'split' && count($sg['blocks']) === 1)
                        <td style="width: 25%;"></td>
                    @endif
                    @foreach ($sg['blocks'] as $bloque)
                        <td style="text-align: {{ $sg['position'] === 'split' ? 'center' : $sg['position'] }}">
                            <div class="linea"></div>
                            {{ $bloque['label'] }}
                            @if (($bloque['detalle'] ?? '') !== '')
                                <span class="detalle">{{ $bloque['detalle'] }}</span>
                            @endif
                        </td>
                    @endforeach
                    @if ($sg['position'] === 'split' && count($sg['blocks']) === 1)
                        <td style="width: 25%;"></td>
                    @endif
                </tr>
            </table>
        @endif
    </div>
</body>
</html>
