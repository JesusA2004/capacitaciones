{{-- Reporte de RH impreso con Chrome (Browsershot). Compartido por el catálogo de reportes y por Mis reportes. --}}
@php
    $densidad = ['compacto' => ['fuente' => '8px', 'celda' => '3px 6px'], 'normal' => ['fuente' => '9.5px', 'celda' => '6px 8px'], 'amplio' => ['fuente' => '10.5px', 'celda' => '9px 10px']][$ajuste] ?? ['fuente' => '9.5px', 'celda' => '6px 8px'];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <style>
        @page { size: {{ $tamano }} {{ $orientacion }}; margin: 13mm 12mm 17mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Segoe UI', 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #2B3533; font-size: 10px; line-height: 1.45; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .portada { display: flex; align-items: stretch; border-radius: 12px; overflow: hidden; background: {{ $color }}; color: #fff; margin-bottom: 12px; }
        .portada-filete { width: 8px; background: #AF8B51; flex: none; }
        .portada-cuerpo { flex: 1; padding: 14px 18px; display: flex; justify-content: space-between; gap: 18px; align-items: center; }
        .marca { display: flex; align-items: center; gap: 9px; flex: none; }
        .marca-texto strong { display: block; font-family: Georgia, 'Times New Roman', serif; font-size: 16px; letter-spacing: .2px; }
        .marca-texto span { display: block; font-size: 7px; letter-spacing: 2.2px; text-transform: uppercase; opacity: .78; }
        .titulo h1 { margin: 0; font-size: 18px; line-height: 1.2; font-weight: 700; text-align: right; }
        .titulo p { margin: 3px 0 0; font-size: 10px; opacity: .85; text-align: right; }
        .titulo-simple { margin: 0 0 10px; border-bottom: 2px solid {{ $color }}; padding-bottom: 6px; }
        .titulo-simple h1 { margin: 0; font-size: 16px; color: {{ $color }}; }
        .titulo-simple p { margin: 2px 0 0; color: #707874; }
        .datos { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
        .dato { background: #FAF6EE; border: 1px solid #E7DED1; border-left: 3px solid {{ $color }}; border-radius: 8px; padding: 6px 11px; min-width: 110px; }
        .dato small { display: block; color: #707874; font-size: 8px; text-transform: uppercase; letter-spacing: .6px; }
        .dato strong { font-size: 13px; color: {{ $color }}; }
        .filtros { margin-bottom: 10px; display: flex; flex-wrap: wrap; gap: 5px; }
        .filtro { background: #EEF3EF; color: #225C54; border-radius: 999px; padding: 2px 9px; font-size: 8.5px; }
        .aviso { background: #FBF0D9; border-radius: 8px; padding: 6px 10px; margin-bottom: 10px; color: #754711; font-size: 9px; }
        .grafica { border: 1px solid #E7DED1; border-radius: 10px; padding: 10px 14px; margin-bottom: 12px; break-inside: avoid; }
        .grafica h2 { margin: 0 0 7px; font-size: 11.5px; color: {{ $color }}; }
        .grafica-vacia { color: #707874; margin: 0; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: {{ $densidad['fuente'] }}; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        thead th { background: {{ $color }}; color: #fff; text-align: left; padding: {{ $densidad['celda'] }}; font-weight: 600; font-size: .92em; }
        thead th:first-child { border-top-left-radius: 7px; }
        thead th:last-child { border-top-right-radius: 7px; }
        tbody td { padding: {{ $densidad['celda'] }}; border-bottom: 1px solid #EDE6DA; vertical-align: top; }
        tbody tr:nth-child(even) td { background: #FBF9F4; }
        tfoot td { padding: {{ $densidad['celda'] }}; font-weight: 700; background: #F3EDE1; border-top: 2px solid {{ $color }}; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .sin-datos { text-align: center; color: #707874; padding: 28px; border: 1px dashed #E7DED1; border-radius: 10px; }
    </style>
</head>
<body>
    @if ($encabezado)
        <div class="portada">
            <div class="portada-filete"></div>
            <div class="portada-cuerpo">
                <div class="marca">
                    <svg width="38" height="26" viewBox="0 0 38 26" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <circle cx="9" cy="8" r="4.2" fill="#E9D6B0"/><path d="M2 25c0-6 3-10 7-10s7 4 7 10z" fill="#E9D6B0"/>
                        <circle cx="29" cy="8" r="4.2" fill="#7FA79B"/><path d="M22 25c0-6 3-10 7-10s7 4 7 10z" fill="#7FA79B"/>
                        <circle cx="19" cy="6" r="4.8" fill="#AF8B51"/><path d="M11 25c0-7 3.5-11 8-11s8 4 8 11z" fill="#AF8B51"/>
                    </svg>
                    <div class="marca-texto"><strong>Mr. Lana</strong><span>People · Contigo</span></div>
                </div>
                <div class="titulo">
                    <h1>{{ $titulo }}</h1>
                    @if ($subtitulo !== '')<p>{{ $subtitulo }}</p>@endif
                </div>
            </div>
        </div>
    @else
        <div class="titulo-simple">
            <h1>{{ $titulo }}</h1>
            @if ($subtitulo !== '')<p>{{ $subtitulo }}</p>@endif
        </div>
    @endif

    <div class="datos">
        <div class="dato"><small>Registros</small><strong>{{ number_format(count($filas)) }}</strong></div>
        <div class="dato"><small>Generado</small><strong style="font-size: 10.5px;">{{ $generado }}</strong></div>
    </div>

    @if (count($filtrosTexto) > 0)
        <div class="filtros">@foreach ($filtrosTexto as $filtro)<span class="filtro">{{ $filtro }}</span>@endforeach</div>
    @endif

    @foreach ($avisos as $aviso)
        <div class="aviso">{{ $aviso }}</div>
    @endforeach

    @if ($graficaSvg !== null)
        <div class="grafica">
            <h2>{{ $graficaTitulo }}</h2>
            {!! $graficaSvg !!}
        </div>
    @endif

    @if (count($filas) === 0)
        <div class="sin-datos">No hay información con los filtros seleccionados.</div>
    @else
        <table>
            <thead>
                <tr>@foreach ($columnas as $columna)<th @class(['num' => $columna['numerica']])>{{ $columna['etiqueta'] }}</th>@endforeach</tr>
            </thead>
            <tbody>
                @foreach ($filas as $fila)
                    <tr>@foreach ($fila as $i => $celda)<td @class(['num' => $columnas[$i]['numerica'] ?? false])>{{ $celda }}</td>@endforeach</tr>
                @endforeach
            </tbody>
            @if ($totales !== null)
                <tfoot>
                    <tr>@foreach ($totales as $i => $celda)<td @class(['num' => $columnas[$i]['numerica'] ?? false])>{{ $celda }}</td>@endforeach</tr>
                </tfoot>
            @endif
        </table>
    @endif
</body>
</html>
