{{--
    Rotación de personal · Resumen ejecutivo (carta horizontal, Browsershot).
    Recibe $datos = RotacionExportService::datos() y $detalle (no se usa aquí).
    Todas las cifras salen de $datos: aquí solo se da formato (y el ancho de
    las barras como proporción del máximo), así el PDF nunca difiere de la
    pantalla. Un dato que no existe se muestra «—» o «Sin datos», nunca 0.
    El pie con «Página X de Y» lo dibuja el motor (OpcionesPdf).
--}}
@php
    use Carbon\CarbonImmutable;

    $alcance = $datos['alcance'];
    $resumen = $datos['resumen'];
    $tendencia = $datos['tendencia'];
    $causales = $datos['causales'];
    $antiguedad = $datos['antiguedad'];
    $sucursales = $datos['sucursales'];
    $hallazgos = $datos['hallazgos'] ?? [];

    $entero = fn ($v): string => $v === null ? '—' : number_format((int) $v);
    $decimal = fn ($v): string => $v === null ? '—' : number_format((float) $v, 1);
    $pct = fn ($v): string => $v === null ? '—' : number_format((float) $v, 1).' %';
    $fecha = fn (?string $v): string => $v === null || $v === '' ? '—' : CarbonImmutable::parse($v)->format('d/m/Y');
    $mesesCortos = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $mesesLargos = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $etiquetaMes = function (?string $periodo) use ($mesesLargos): string {
        if ($periodo === null) {
            return '—';
        }
        [$a, $m] = array_map('intval', explode('-', $periodo));

        return ($mesesLargos[$m] ?? $periodo).' '.$a;
    };

    // Con filtros de detalle no hay plantilla por causal/antigüedad: el índice no aplica.
    $conDetalle = ($alcance['puesto'] ?? null) !== null || ($alcance['area'] ?? null) !== null
        || ($alcance['clasificacion'] ?? null) !== null || ($alcance['causal'] ?? null) !== null
        || ($alcance['antiguedad'] ?? null) !== null;

    $rotacion = $resumen['rotacion'] ?? [];
    $temprana = $resumen['temprana'];
    $comparativo = $resumen['comparativo'] ?? null;

    $filtrosDetalle = array_filter([
        'Puesto' => $alcance['puesto'] ?? null,
        'Área' => $alcance['area'] ?? null,
        'Tipo de salida' => $alcance['clasificacion'] ?? null,
        'Causal' => $alcance['causal'] ?? null,
        'Antigüedad' => $alcance['antiguedad'] ?? null,
    ], fn ($v) => $v !== null);

    $maxMes = max(1, ...array_map(fn (array $m): int => (int) $m['total'], $tendencia['meses'] ?: [['total' => 0]]));
    $topSucursales = array_slice($sucursales, 0, 10);
    $maxSucursal = max(1, ...array_map(fn (array $s): int => (int) $s['bajas'], $topSucursales ?: [['bajas' => 0]]));
    $topCausales = array_slice($causales['causales'], 0, 10);
    $maxCausal = max(1, ...array_map(fn (array $c): int => (int) $c['bajas'], $topCausales ?: [['bajas' => 0]]));
    $maxRango = max(1, ...array_map(fn (array $r): int => (int) $r['bajas'], $antiguedad['rangos'] ?: [['bajas' => 0]]));
    $ancho = fn (int|float $v, int|float $max): string => number_format($max > 0 ? min(100, $v * 100 / $max) : 0, 2, '.', '');

    $claseClasif = fn (?string $c): string => match ($c) {
        'voluntaria' => 'vol',
        'involuntaria' => 'invol',
        default => 'sincl',
    };
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Rotación de personal · Resumen ejecutivo</title>
    <style>
        /* Paleta crema/pastel del sistema (resources/css/app.css → --pastel-*). */
        :root {
            --petroleo: #315b59;
            --esmeralda: #6e9b8f;
            --oro: #c7a66b;
            --bronce: #a98252;
            --crema: #f3e8d3;
            --salvia: #dce8df;
            --menta: #eef3ef;
            --superficie: #fffdf9;
            --texto: #303a38;
            --texto-2: #707874;
            --borde: #e7ded1;
            --alerta: #f3e3b8;
            --alerta-texto: #754711;
            --peligro: #eecbc7;
            --peligro-texto: #9b4a41;
            --vol: #6e9b8f;
            --invol: #c7a66b;
            --sincl: #c9c3b8;
        }

        @page { size: letter landscape; margin: 10mm 11mm 16mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Segoe UI', 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 9px; line-height: 1.4; color: var(--texto); -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        .portada { display: flex; border-radius: 10px; overflow: hidden; background: var(--petroleo); color: #fff; margin-bottom: 8px; }
        .portada-filete { width: 7px; background: #af8b51; flex: none; }
        .portada-cuerpo { flex: 1; padding: 10px 16px; display: flex; justify-content: space-between; align-items: center; gap: 16px; }
        .marca { display: flex; align-items: center; gap: 9px; }
        .marca strong { display: block; font-family: Georgia, 'Times New Roman', serif; font-size: 15px; }
        .marca span { display: block; font-size: 6.5px; letter-spacing: 2.2px; text-transform: uppercase; opacity: .78; }
        .titulo { text-align: right; }
        .titulo h1 { margin: 0; font-size: 17px; line-height: 1.2; }
        .titulo p { margin: 2px 0 0; font-size: 9.5px; opacity: .88; }

        .alcance { display: flex; flex-wrap: wrap; gap: 4px 6px; margin-bottom: 8px; align-items: center; }
        .chip { background: var(--menta); color: #225c54; border-radius: 999px; padding: 2px 9px; font-size: 8px; }
        .chip b { font-weight: 700; }
        .chip.aviso { background: var(--alerta); color: var(--alerta-texto); }

        .kpis { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; margin-bottom: 8px; }
        .kpi { background: var(--superficie); border: 1px solid var(--borde); border-left: 4px solid var(--esmeralda); border-radius: 6px; padding: 6px 8px; }
        .kpi.oro { border-left-color: var(--oro); }
        .kpi.gris { border-left-color: #b5aea2; }
        .kpi.peligro { border-left-color: var(--peligro-texto); }
        .kpi .etiqueta { font-size: 7px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: var(--texto-2); margin: 0 0 2px; }
        .kpi .valor { font-size: 17px; font-weight: 700; color: var(--petroleo); margin: 0; line-height: 1.15; }
        .kpi .valor small { font-size: 10px; font-weight: 600; color: var(--texto-2); }
        .kpi .valor.texto { font-size: 10.5px; line-height: 1.25; color: var(--texto-2); }
        .kpi .nota { font-size: 7px; color: var(--texto-2); margin: 2px 0 0; }

        .rejilla { display: grid; grid-template-columns: 1.65fr 1fr; gap: 8px; }
        .rejilla-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .caja { border: 1px solid var(--borde); border-radius: 8px; padding: 8px 10px; background: var(--superficie); break-inside: avoid; margin-bottom: 8px; }
        h2 { font-size: 10px; color: var(--petroleo); margin: 0 0 6px; text-transform: uppercase; letter-spacing: .5px; }
        h2 small { text-transform: none; letter-spacing: 0; font-weight: 400; color: var(--texto-2); font-size: 8px; }

        /* Tendencia mensual: columnas apiladas. */
        .grafica { display: flex; align-items: flex-end; gap: 3px; height: 200px; border-bottom: 1px solid var(--borde); padding-top: 14px; }
        .col { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; min-width: 0; }
        .col-valor { font-size: 7.5px; font-weight: 700; color: var(--petroleo); margin-bottom: 1px; white-space: nowrap; }
        .pila { width: 72%; max-width: 30px; display: flex; flex-direction: column-reverse; border-radius: 3px 3px 0 0; overflow: hidden; }
        .pila .vol { background: var(--vol); }
        .pila .invol { background: var(--invol); }
        .pila .sincl { background: var(--sincl); }
        .col.mayor .pila { outline: 2px solid var(--peligro-texto); outline-offset: 1px; }
        .col.mayor .col-valor { color: var(--peligro-texto); }
        .sin-datos-col { width: 72%; max-width: 30px; height: 60%; border: 1px dashed #b5aea2; border-bottom: none; border-radius: 3px 3px 0 0; display: flex; align-items: center; justify-content: center; }
        .sin-datos-col span { writing-mode: vertical-rl; transform: rotate(180deg); font-size: 6.5px; color: var(--texto-2); }
        .ejes { display: flex; gap: 3px; margin-top: 2px; }
        .eje { flex: 1; text-align: center; font-size: 7px; color: var(--texto-2); min-width: 0; line-height: 1.2; }
        .eje b { color: var(--peligro-texto); }
        .eje .parcial { display: block; font-size: 6px; color: var(--alerta-texto); }
        .leyenda { display: flex; flex-wrap: wrap; gap: 4px 12px; font-size: 7.5px; color: var(--texto-2); margin-top: 6px; }
        .leyenda i { display: inline-block; width: 9px; height: 9px; border-radius: 2px; vertical-align: -1px; margin-right: 3px; }

        /* Barras horizontales. */
        .barra-fila { display: grid; grid-template-columns: 40% 1fr 76px; align-items: center; gap: 6px; margin-bottom: 3px; }
        .barra-etiqueta { font-size: 8px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .barra-pista { height: 10px; background: var(--menta); border-radius: 3px; overflow: hidden; display: flex; }
        .barra-pista .relleno { height: 100%; background: var(--esmeralda); }
        .barra-pista .relleno.vol { background: var(--vol); }
        .barra-pista .relleno.invol { background: var(--invol); }
        .barra-pista .relleno.sincl { background: var(--sincl); }
        .barra-pista .relleno.temprana { background: #d9a199; }
        .barra-valor { font-size: 8px; text-align: right; white-space: nowrap; }
        .barra-valor b { color: var(--petroleo); }

        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        th { background: var(--salvia); color: var(--petroleo); font-size: 7px; text-transform: uppercase; letter-spacing: .3px; text-align: left; padding: 4px 5px; }
        td { padding: 3px 5px; border-bottom: 1px solid var(--borde); font-size: 8px; vertical-align: middle; }
        td.num, th.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        td.tenue { color: var(--texto-2); font-size: 7px; }
        .mini-barra { height: 6px; background: var(--esmeralda); border-radius: 2px; }

        .pastilla { display: inline-block; border-radius: 999px; padding: 0 6px; font-size: 7px; font-weight: 700; white-space: nowrap; }
        .pastilla.vol { background: var(--salvia); color: var(--petroleo); }
        .pastilla.invol { background: var(--alerta); color: var(--alerta-texto); }
        .pastilla.sincl { background: #ece8e1; color: var(--texto-2); }

        .notas { margin: 0; padding-left: 14px; font-size: 7.5px; color: var(--texto-2); }
        .notas li { margin-bottom: 1px; }
        .hallazgo { border-left: 3px solid var(--esmeralda); padding: 2px 0 2px 7px; margin-bottom: 5px; }
        .hallazgo.alerta { border-left-color: var(--oro); }
        .hallazgo.peligro { border-left-color: var(--peligro-texto); }
        .hallazgo.neutro { border-left-color: #b5aea2; }
        .hallazgo b { display: block; font-size: 8.5px; color: var(--petroleo); }
        .hallazgo span { font-size: 8px; color: var(--texto-2); }
        .vacio { color: var(--texto-2); font-style: italic; font-size: 8px; margin: 2px 0; }
        .pie-dato { font-size: 7px; color: var(--texto-2); margin: 4px 0 0; }
        .salto { break-before: page; }
    </style>
</head>
<body>

<div class="portada">
    <div class="portada-filete"></div>
    <div class="portada-cuerpo">
        <div class="marca">
            <svg width="36" height="25" viewBox="0 0 38 26" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <circle cx="9" cy="8" r="4.2" fill="#E9D6B0"/><path d="M2 25c0-6 3-10 7-10s7 4 7 10z" fill="#E9D6B0"/>
                <circle cx="29" cy="8" r="4.2" fill="#7FA79B"/><path d="M22 25c0-6 3-10 7-10s7 4 7 10z" fill="#7FA79B"/>
                <circle cx="19" cy="6" r="4.8" fill="#AF8B51"/><path d="M11 25c0-7 3.5-11 8-11s8 4 8 11z" fill="#AF8B51"/>
            </svg>
            <div><strong>Mr. Lana</strong><span>People · Contigo</span></div>
        </div>
        <div class="titulo">
            <h1>Rotación de personal · Resumen ejecutivo</h1>
            <p>{{ $alcance['empresa'] ?? '—' }} · {{ $alcance['sucursal'] ?? '—' }}</p>
        </div>
    </div>
</div>

<div class="alcance">
    <span class="chip"><b>Periodo:</b> {{ $fecha($alcance['desde'] ?? null) }} al {{ $fecha($alcance['hasta'] ?? null) }}</span>
    <span class="chip"><b>Fecha de corte:</b> {{ $fecha($alcance['corte'] ?? null) }}</span>
    <span class="chip"><b>Fuente:</b> {{ $alcance['fuente'] ?? '—' }}</span>
    @foreach ($filtrosDetalle as $etiqueta => $valor)
        <span class="chip"><b>{{ $etiqueta }}:</b> {{ $valor }}</span>
    @endforeach
    @if (! empty($alcance['corte_parcial']))
        <span class="chip aviso"><b>Corte parcial:</b> el último mes aún no termina</span>
    @endif
    <span class="chip"><b>Generado:</b> {{ $alcance['generado'] ?? '—' }}</span>
</div>

<div class="kpis">
    <div class="kpi">
        <p class="etiqueta">Total de bajas</p>
        <p class="valor">{{ $entero($resumen['total']) }}</p>
        <p class="nota">
            @if ($comparativo !== null)
                Periodo anterior: {{ $entero($comparativo['total']) }} ({{ $comparativo['delta_total'] > 0 ? '+' : '' }}{{ $entero($comparativo['delta_total']) }})
            @else
                Sin periodo anterior comparable
            @endif
        </p>
    </div>
    <div class="kpi">
        <p class="etiqueta">Promedio mensual</p>
        <p class="valor">{{ $decimal($resumen['promedio_mensual']) }} <small>bajas/mes</small></p>
        <p class="nota">{{ $entero($resumen['meses']) }} mes(es) con información</p>
    </div>
    <div class="kpi">
        <p class="etiqueta">Voluntarias</p>
        <p class="valor">{{ $entero($resumen['voluntarias']['bajas']) }} <small>{{ $pct($resumen['voluntarias']['porcentaje']) }}</small></p>
        <p class="nota">Del total de bajas</p>
    </div>
    <div class="kpi oro">
        <p class="etiqueta">Involuntarias</p>
        <p class="valor">{{ $entero($resumen['involuntarias']['bajas']) }} <small>{{ $pct($resumen['involuntarias']['porcentaje']) }}</small></p>
        <p class="nota">Del total de bajas</p>
    </div>
    <div class="kpi gris">
        <p class="etiqueta">Sin clasificar</p>
        <p class="valor">{{ $entero($resumen['sin_clasificar']['bajas']) }} <small>{{ $pct($resumen['sin_clasificar']['porcentaje']) }}</small></p>
        <p class="nota">
            {{ $entero($resumen['sin_clasificar']['sin_desglose']) }} del histórico sin desglose · {{ $entero($resumen['sin_clasificar']['por_revisar']) }} por revisar
        </p>
    </div>
    <div class="kpi peligro">
        <p class="etiqueta">Rotación temprana</p>
        @if ($temprana['porcentaje'] === null)
            <p class="valor texto">Sin fechas de ingreso válidas</p>
        @else
            <p class="valor">{{ $pct($temprana['porcentaje']) }}</p>
        @endif
        <p class="nota">{{ $entero($temprana['bajas']) }} de {{ $entero($temprana['base']) }} con fecha de ingreso válida (antes de 6 meses){{ (int) $temprana['excluidas'] > 0 ? ' · '.$entero($temprana['excluidas']).' excluidas' : '' }}</p>
    </div>
    <div class="kpi">
        <p class="etiqueta">Índice de rotación</p>
        @if ($conDetalle)
            <p class="valor texto">No aplica con filtros de detalle</p>
            <p class="nota">No hay plantilla por causal, puesto o antigüedad</p>
        @elseif (($rotacion['indice'] ?? null) === null)
            <p class="valor texto">Sin datos de plantilla</p>
            <p class="nota">No se calcula como 0 %</p>
        @else
            <p class="valor">{{ $pct($rotacion['indice']) }}</p>
            <p class="nota">
                Plantilla promedio {{ $decimal($rotacion['plantilla_promedio'] ?? null) }}
                @if (($comparativo['delta_indice'] ?? null) !== null)
                    · vs. anterior {{ $comparativo['delta_indice'] > 0 ? '+' : '' }}{{ number_format((float) $comparativo['delta_indice'], 1) }} pts
                @endif
            </p>
        @endif
    </div>
</div>

@if ((int) ($resumen['no_desglosables'] ?? 0) > 0)
    <p class="pie-dato" style="margin: -3px 0 6px;">
        Con los filtros de detalle aplicados, {{ $entero($resumen['no_desglosables']) }} baja(s) del histórico agregado (Excel) no se pudieron desglosar y no se cuentan en estas cifras.
    </p>
@endif

<div class="rejilla">
    <div class="caja">
        <h2>Tendencia mensual <small>· bajas por mes y tipo de salida</small></h2>
        @if ($tendencia['meses'] === [])
            <p class="vacio">Sin meses en el periodo consultado.</p>
        @else
            <div class="grafica">
                @foreach ($tendencia['meses'] as $m)
                    @php $esMayor = $m['periodo'] === $tendencia['mes_mayor']; @endphp
                    <div class="col {{ $esMayor ? 'mayor' : '' }}">
                        @if ($m['sin_datos'])
                            <div class="sin-datos-col"><span>Sin datos</span></div>
                        @else
                            <div class="col-valor">{{ $entero($m['total']) }}</div>
                            <div class="pila" style="height: {{ $ancho($m['total'], $maxMes) }}%;">
                                @if ($m['voluntarias'] > 0)<div class="vol" style="height: {{ $ancho($m['voluntarias'], max(1, $m['total'])) }}%;"></div>@endif
                                @if ($m['involuntarias'] > 0)<div class="invol" style="height: {{ $ancho($m['involuntarias'], max(1, $m['total'])) }}%;"></div>@endif
                                @if ($m['sin_clasificar'] > 0)<div class="sincl" style="height: {{ $ancho($m['sin_clasificar'], max(1, $m['total'])) }}%;"></div>@endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="ejes">
                @foreach ($tendencia['meses'] as $m)
                    <div class="eje">
                        <span style="{{ $m['periodo'] === $tendencia['mes_mayor'] ? 'font-weight: 700; color: var(--peligro-texto);' : '' }}">{{ $mesesCortos[$m['mes']] ?? $m['mes'] }}</span><br>{{ substr((string) $m['anio'], -2) }}
                        @if ($m['corte_parcial'])<span class="parcial">parcial</span>@endif
                    </div>
                @endforeach
            </div>
            <div class="leyenda">
                <span><i style="background: var(--vol);"></i>Voluntarias</span>
                <span><i style="background: var(--invol);"></i>Involuntarias</span>
                <span><i style="background: var(--sincl);"></i>Sin clasificar</span>
                <span><i style="border: 1px dashed #b5aea2;"></i>Sin datos (no es cero)</span>
                @if ($tendencia['mes_mayor'] !== null)
                    <span><i style="outline: 2px solid var(--peligro-texto); background: #fff;"></i>Mes con más bajas: {{ $etiquetaMes($tendencia['mes_mayor']) }}</span>
                @endif
                <span>Promedio por mes con datos: <b>{{ $decimal($tendencia['promedio']) }}</b></span>
            </div>
        @endif
    </div>

    <div>
        <div class="caja">
            <h2>Distribución por tipo de salida</h2>
            @foreach ([['Voluntarias', 'voluntarias', 'vol'], ['Involuntarias', 'involuntarias', 'invol'], ['Sin clasificar', 'sin_clasificar', 'sincl']] as [$etiqueta, $clave, $clase])
                <div class="barra-fila">
                    <span class="barra-etiqueta">{{ $etiqueta }}</span>
                    <div class="barra-pista"><div class="relleno {{ $clase }}" style="width: {{ $ancho($resumen[$clave]['bajas'], max(1, $resumen['total'])) }}%;"></div></div>
                    <span class="barra-valor"><b>{{ $entero($resumen[$clave]['bajas']) }}</b> · {{ $pct($resumen[$clave]['porcentaje']) }}</span>
                </div>
            @endforeach
        </div>
        <div class="caja">
            <h2>Distribución por antigüedad <small>· % sobre {{ $entero($antiguedad['base']) }} con fecha de ingreso válida</small></h2>
            @foreach ($antiguedad['rangos'] as $r)
                <div class="barra-fila">
                    <span class="barra-etiqueta">{{ $r['etiqueta'] }}</span>
                    <div class="barra-pista"><div class="relleno {{ $r['temprana'] ? 'temprana' : '' }}" style="width: {{ $ancho($r['bajas'], $maxRango) }}%; {{ $r['clave'] === 'sin_fecha' ? 'background: var(--sincl);' : '' }}"></div></div>
                    <span class="barra-valor"><b>{{ $entero($r['bajas']) }}</b> · {{ $r['clave'] === 'sin_fecha' ? 'excluidas' : $pct($r['porcentaje']) }}</span>
                </div>
            @endforeach
            <p class="pie-dato">
                Promedio {{ $antiguedad['promedio_dias'] === null ? '—' : $entero($antiguedad['promedio_dias']).' días' }} ·
                mediana {{ $antiguedad['mediana_dias'] === null ? '—' : $entero($antiguedad['mediana_dias']).' días' }} ·
                antes de 90 días: {{ $entero($antiguedad['antes_90_dias']) }}
                @if ((int) ($antiguedad['sin_desglose'] ?? 0) > 0)
                    · {{ $entero($antiguedad['sin_desglose']) }} del histórico agregado sin antigüedad
                @endif
            </p>
        </div>
    </div>
</div>

<div class="rejilla-2 salto">
    <div class="caja">
        <h2>Ranking de sucursales <small>· {{ count($topSucursales) }} de {{ count($sucursales) }} con bajas</small></h2>
        @if ($topSucursales === [])
            <p class="vacio">Sin bajas en el periodo consultado.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th style="width: 18px;">#</th>
                        <th>Sucursal</th>
                        <th class="num">Bajas</th>
                        <th style="width: 22%;"></th>
                        <th class="num">Participación</th>
                        <th class="num">Índice</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($topSucursales as $i => $s)
                        <tr>
                            <td class="tenue">{{ $i + 1 }}</td>
                            <td>{{ $s['sucursal'] }}</td>
                            <td class="num"><b>{{ $entero($s['bajas']) }}</b></td>
                            <td><div class="mini-barra" style="width: {{ $ancho($s['bajas'], $maxSucursal) }}%;"></div></td>
                            <td class="num">{{ $pct($s['participacion']) }}</td>
                            @if ($s['indice'] === null)
                                <td class="num tenue">{{ $conDetalle ? 'No aplica' : 'Sin datos de plantilla' }}</td>
                            @else
                                <td class="num">{{ $pct($s['indice']) }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="caja">
        <h2>Ranking de causales <small>· % sobre {{ $entero($causales['base']) }} bajas individuales</small></h2>
        @if ($topCausales === [])
            <p class="vacio">Sin bajas individuales con causal en el periodo.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th style="width: 18px;">#</th>
                        <th>Causal homologada</th>
                        <th>Clasificación</th>
                        <th class="num">Bajas</th>
                        <th style="width: 18%;"></th>
                        <th class="num">%</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($topCausales as $i => $c)
                        <tr>
                            <td class="tenue">{{ $i + 1 }}</td>
                            <td>{{ $c['nombre'] }}@if (! empty($c['es_estructural'])) <span class="pastilla sincl">Estructural</span>@endif</td>
                            <td><span class="pastilla {{ $claseClasif($c['clasificacion']) }}">{{ $c['clasificacion_etiqueta'] }}</span></td>
                            <td class="num"><b>{{ $entero($c['bajas']) }}</b></td>
                            <td><div class="mini-barra" style="width: {{ $ancho($c['bajas'], $maxCausal) }}%;"></div></td>
                            <td class="num">{{ $pct($c['porcentaje']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        @if ((int) $causales['sin_desglose'] > 0)
            <p class="pie-dato">{{ $entero($causales['sin_desglose']) }} baja(s) del histórico agregado (Excel) no tienen causal y no entran en este ranking.</p>
        @endif
    </div>
</div>

<div class="rejilla-2">
    <div class="caja">
        <h2>Hallazgos</h2>
        @forelse ($hallazgos as $h)
            <div class="hallazgo {{ $h['tono'] ?? '' }}">
                <b>{{ $h['titulo'] }}</b>
                <span>{{ $h['detalle'] }}</span>
            </div>
        @empty
            <p class="vacio">Sin hallazgos para el periodo consultado.</p>
        @endforelse
    </div>
    <div class="caja">
        <h2>Notas de metodología</h2>
        <ul class="notas">
            <li><b>Índice de rotación</b> = bajas del periodo ÷ promedio de las plantillas promedio mensuales × 100. Sin plantilla se informa «Sin datos de plantilla», nunca 0 %.</li>
            <li><b>Rotación temprana</b> = bajas antes de 6 meses ÷ bajas con fecha de ingreso válida; las demás se excluyen del denominador y se informan.</li>
            <li><b>Total</b> = voluntarias + involuntarias + sin clasificar. Las bajas del histórico agregado (Excel) sin desglose cuentan como «sin clasificar» y no tienen causal ni antigüedad.</li>
            <li>Un mes anterior al sistema sin ninguna fuente es «Sin datos», no cero. La clasificación de las causales es estadística, no una conclusión jurídica.</li>
        </ul>
    </div>
</div>

</body>
</html>
