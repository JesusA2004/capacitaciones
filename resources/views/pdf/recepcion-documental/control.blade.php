<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    {{-- Recepción documental RH (RecepcionDocumentalPdfService). Operativo, sin adornos:
         casillas dibujadas con bordes (DomPDF no garantiza ☐/☑ en sus fuentes). --}}
    <style>
        @page { margin: 30px 32px 40px; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; color: {{ $pal['texto'] }}; font-size: 9.5px; line-height: 1.35; }
        .encabezado { border-bottom: 2px solid {{ $pal['petroleo'] }}; padding-bottom: 6px; margin-bottom: 10px; }
        .encabezado table { width: 100%; border-collapse: collapse; }
        .encabezado td { vertical-align: middle; }
        .logo { height: 34px; }
        h1 { font-size: 13px; margin: 0; color: {{ $pal['petroleo'] }}; letter-spacing: 0.5px; }
        .meta { font-size: 9px; color: {{ $pal['texto_suave'] }}; margin: 2px 0 0; }
        .leyenda { font-size: 8.5px; color: {{ $pal['texto_suave'] }}; margin: 0 0 8px; }
        .sucursal { margin: 10px 0 4px; padding: 4px 6px; background: {{ $pal['petroleo'] }}; color: {{ $pal['blanco'] }}; font-weight: bold; font-size: 10.5px; }
        .bloque { margin: 6px 0 2px; font-weight: bold; font-size: 9.5px; color: {{ $pal['petroleo'] }}; border-bottom: 1px solid {{ $pal['borde'] }}; }
        table.docs { width: 100%; border-collapse: collapse; page-break-inside: auto; }
        table.docs tr { page-break-inside: avoid; }
        table.docs td { padding: 3px 4px; vertical-align: top; border-bottom: 1px solid {{ $pal['borde_claro'] }}; }
        .persona { font-weight: bold; }
        .evento { color: {{ $pal['texto_suave'] }}; font-size: 8.5px; }
        .casilla { width: 14px; height: 14px; border: 1.4px solid {{ $pal['texto'] }}; text-align: center; font-size: 10px; line-height: 12px; font-weight: bold; }
        .c-ok { background: {{ $pal['exito_suave'] }}; color: {{ $pal['exito_texto'] }}; }
        .c-alerta { background: {{ $pal['alerta_suave'] }}; color: {{ $pal['alerta_texto'] }}; }
        .c-na { color: {{ $pal['texto_tenue'] }}; font-size: 7px; }
        .col-casilla { width: 22px; }
        .col-cant { width: 34px; text-align: center; color: {{ $pal['texto_suave'] }}; }
        .col-nota { width: 32%; color: {{ $pal['texto_suave'] }}; font-size: 8.5px; }
        .subtotal { margin: 4px 0 2px; text-align: right; font-size: 9px; background: {{ $pal['fondo_total'] }}; padding: 3px 6px; }
        .total { margin-top: 12px; border: 1.5px solid {{ $pal['petroleo'] }}; padding: 6px 8px; font-size: 10.5px; font-weight: bold; }
        .firma { margin-top: 18px; width: 100%; border-collapse: collapse; page-break-inside: avoid; }
        .firma td { padding: 14px 6px 2px; vertical-align: bottom; }
        .linea { border-bottom: 1px solid {{ $pal['texto'] }}; height: 16px; }
        .etq { font-size: 8.5px; color: {{ $pal['texto_suave'] }}; }
        .vacio { padding: 18px 0; text-align: center; color: {{ $pal['texto_suave'] }}; }
    </style>
</head>
<body>
    <div class="encabezado">
        <table>
            <tr>
                <td>
                    <h1>{{ $titulo }}</h1>
                    <p class="meta">{{ $periodo }} · Generado el {{ $generado }} (hora de México) · Corte: {{ $estado_corte }}</p>
                    <p class="meta">{{ $variante === 'estado' ? 'Estado digital de la recepción' : 'Hoja de control para marcar a mano' }}</p>
                </td>
                @if ($logo)
                    <td style="text-align: right; width: 90px;"><img class="logo" src="{{ $logo }}" alt="MR. LANA"></td>
                @endif
            </tr>
        </table>
    </div>

    @if ($variante === 'estado')
        <p class="leyenda">Casilla: ✓ recibido · ! recibido con observación o no recibido · N/A no aplica · vacía = pendiente.</p>
    @else
        <p class="leyenda">Marca cada casilla al recibir el original firmado. Anota en «Observaciones» cualquier faltante o documento incompleto.</p>
    @endif

    @forelse ($secciones as $seccion)
        <div class="sucursal">{{ $seccion['sucursal'] }}</div>

        @foreach ($seccion['bloques'] as $bloque)
            <div class="bloque">{{ $bloque['etiqueta'] }}</div>
            <table class="docs">
                @foreach ($bloque['eventos'] as $evento)
                    <tr>
                        <td colspan="4">
                            <span class="persona">{{ $evento['titulo'] }}</span>
                            @if ($evento['descripcion'])
                                <span class="evento"> — {{ $evento['descripcion'] }}</span>
                            @endif
                        </td>
                    </tr>
                    @foreach ($evento['documentos'] as $doc)
                        <tr>
                            <td class="col-casilla">
                                @if ($variante === 'estado')
                                    <div class="casilla {{ $doc['marca'] === 'ok' ? 'c-ok' : ($doc['marca'] === 'alerta' ? 'c-alerta' : ($doc['marca'] === 'na' ? 'c-na' : '')) }}">{{ $doc['marca'] === 'ok' ? '✓' : ($doc['marca'] === 'alerta' ? '!' : ($doc['marca'] === 'na' ? 'N/A' : '')) }}</div>
                                @else
                                    <div class="casilla"></div>
                                @endif
                            </td>
                            <td>{{ $doc['etiqueta'] }}</td>
                            <td class="col-cant">{{ $doc['cantidad'] > 1 ? sprintf('× %d', $doc['cantidad']) : '' }}</td>
                            <td class="col-nota">{{ $variante === 'estado' ? ($doc['nota'] ?? '') : '' }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </table>
        @endforeach

        <div class="subtotal">
            Subtotal {{ $seccion['sucursal'] }}: esperados {{ $seccion['metricas']['esperados'] }} · recibidos {{ $variante === 'estado' ? $seccion['metricas']['recibidos'] : '____' }} · pendientes {{ $variante === 'estado' ? $seccion['metricas']['pendientes'] : '____' }}
        </div>
    @empty
        <p class="vacio">No hay originales esperados en {{ mb_strtolower($periodo_etiqueta) }} con estos filtros.</p>
    @endforelse

    <div class="total">
        Total general: esperados {{ $total['esperados'] }} · recibidos {{ $variante === 'estado' ? $total['recibidos'] : '____' }} · pendientes {{ $variante === 'estado' ? $total['pendientes'] : '____' }}
    </div>

    <table class="firma">
        <tr>
            <td style="width: 38%;"><div class="linea"></div><div class="etq">Recibido por (nombre)</div></td>
            <td style="width: 22%;"><div class="linea"></div><div class="etq">Fecha</div></td>
            <td style="width: 40%;"><div class="linea"></div><div class="etq">Firma</div></td>
        </tr>
        <tr>
            <td colspan="3"><div class="linea"></div><div class="linea"></div><div class="etq">Observaciones</div></td>
        </tr>
    </table>
</body>
</html>
