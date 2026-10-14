@php
    /** Formatos (es-MX). Un dato que no existe se muestra «—», nunca 0 inventado. */
    $dinero = fn ($v) => $v === null ? '—' : '$'.number_format((float) $v, 2);
    $entero = fn ($v) => $v === null ? '—' : number_format((int) $v);
    $pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1).'%';
    $varias = count($campanas) > 1;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <style>
        /* Paleta crema/pastel del sistema (resources/css/app.css → --pastel-*). */
        :root {
            --petroleo: #315b59;
            --esmeralda: #6e9b8f;
            --oro: #c7a66b;
            --bronce: #a98252;
            --crema: #f3e8d3;
            --crema-2: #eadbc4;
            --salvia: #dce8df;
            --menta: #e8f0ea;
            --superficie: #fffdf9;
            --texto: #303a38;
            --texto-2: #707874;
            --borde: #e7ded1;
            --exito: #c7dfc9;
            --alerta: #f3e3b8;
            --peligro: #eecbc7;
            --peligro-texto: #9b4a41;
        }

        @page { size: letter; margin: 14mm 13mm 18mm; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 10px;
            line-height: 1.45;
            color: var(--texto);
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .encabezado {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            border-bottom: 2px solid var(--petroleo);
            padding-bottom: 8px;
            margin-bottom: 14px;
        }
        .marca { font-size: 8.5px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: var(--bronce); margin: 0 0 3px; }
        h1 { font-size: 19px; margin: 0; color: var(--petroleo); }
        .meta { font-size: 9px; color: var(--texto-2); text-align: right; }

        h2 { font-size: 13px; color: var(--petroleo); margin: 18px 0 8px; }
        h3 { font-size: 10.5px; color: var(--petroleo); margin: 0 0 6px; text-transform: uppercase; letter-spacing: .5px; }

        .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 12px; }
        .kpi { background: var(--superficie); border: 1px solid var(--borde); border-left: 4px solid var(--esmeralda); border-radius: 6px; padding: 7px 9px; }
        .kpi.oro { border-left-color: var(--oro); }
        .kpi.peligro { border-left-color: var(--peligro-texto); }
        .kpi .etiqueta { font-size: 7.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: var(--texto-2); margin: 0 0 2px; }
        .kpi .valor { font-size: 16px; font-weight: 700; color: var(--petroleo); margin: 0; }
        .kpi .nota { font-size: 7.5px; color: var(--texto-2); margin: 1px 0 0; }

        .columnas { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; }
        .caja { border: 1px solid var(--borde); border-radius: 8px; padding: 9px 10px; background: var(--superficie); break-inside: avoid; }

        .barra-fila { display: grid; grid-template-columns: 38% 1fr 62px; align-items: center; gap: 6px; margin-bottom: 4px; }
        .barra-etiqueta { font-size: 9px; }
        .barra-pista { height: 12px; background: var(--menta); border-radius: 3px; overflow: hidden; }
        .barra-relleno { height: 100%; background: var(--esmeralda); border-radius: 3px; }
        .barra-relleno.oro { background: var(--oro); }
        .barra-relleno.peligro { background: #d9a199; }
        .barra-valor { font-size: 9px; text-align: right; font-weight: 700; white-space: nowrap; }

        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        th { background: var(--salvia); color: var(--petroleo); font-size: 8px; text-transform: uppercase; letter-spacing: .3px; text-align: left; padding: 5px 6px; }
        td { padding: 4px 6px; border-bottom: 1px solid var(--borde); font-size: 9px; vertical-align: top; }
        tr { break-inside: avoid; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: 700; background: var(--crema); }

        .categoria { display: flex; justify-content: space-between; font-weight: 700; font-size: 9.5px; margin: 6px 0 2px; }
        .categoria:first-of-type { margin-top: 0; }
        .motivo { display: flex; justify-content: space-between; font-size: 9px; color: var(--texto-2); padding-left: 10px; }

        .campana { break-before: page; }
        .campana.primera { break-before: auto; }
        .ficha { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px 12px; background: var(--crema); border-radius: 8px; padding: 8px 10px; margin-bottom: 10px; font-size: 9px; }
        .ficha b { color: var(--petroleo); }

        .pastilla { display: inline-block; border-radius: 999px; padding: 1px 7px; font-size: 8px; font-weight: 700; }
        .pastilla.sigue { background: var(--alerta); }
        .pastilla.salio { background: var(--peligro); color: var(--peligro-texto); }
        .pastilla.contratado { background: var(--exito); color: var(--petroleo); }

        .vacio { color: var(--texto-2); font-style: italic; font-size: 9px; }
        .nota-pie { font-size: 8px; color: var(--texto-2); margin-top: 8px; }
    </style>
</head>
<body>

<header class="encabezado">
    <div>
        <p class="marca">MR. LANA PEOPLE · Reclutamiento</p>
        <h1>{{ $titulo }}</h1>
    </div>
    <div class="meta">
        {{ $periodo }}<br>
        Generado el {{ $generado }}
    </div>
</header>

{{-- ===================== Resumen general ===================== --}}
@if ($varias)
    <div class="kpis">
        <div class="kpi"><p class="etiqueta">Campañas</p><p class="valor">{{ $entero($totales['campanas']) }}</p></div>
        <div class="kpi oro"><p class="etiqueta">Gasto total</p><p class="valor">{{ $dinero($totales['gasto']) }}</p>@if ($totales['presupuesto'] > 0)<p class="nota">Presupuesto {{ $dinero($totales['presupuesto']) }}</p>@endif</div>
        <div class="kpi"><p class="etiqueta">Candidatos que llegaron</p><p class="valor">{{ $entero($totales['llegaron']) }}</p></div>
        <div class="kpi"><p class="etiqueta">Contratados</p><p class="valor">{{ $entero($totales['contratados']) }}</p><p class="nota">Conversión {{ $pct($totales['conversion']) }}</p></div>
        <div class="kpi oro"><p class="etiqueta">Costo por candidato</p><p class="valor">{{ $dinero($totales['costo_por_candidato']) }}</p></div>
        <div class="kpi oro"><p class="etiqueta">Costo por contratado</p><p class="valor">{{ $dinero($totales['costo_por_contratado']) }}</p></div>
        <div class="kpi"><p class="etiqueta">Siguen en proceso</p><p class="valor">{{ $entero($totales['en_proceso']) }}</p></div>
        <div class="kpi peligro"><p class="etiqueta">No continuaron</p><p class="valor">{{ $entero($totales['no_continuaron']) }}</p><p class="nota">Costaron {{ $dinero($totales['gasto_en_no_continuaron']) }}</p></div>
    </div>

    <h2>Comparativo de campañas</h2>
    <table>
        <thead>
            <tr>
                <th>Campaña</th>
                <th>Canal</th>
                <th class="num">Gasto</th>
                <th class="num">Llegaron</th>
                <th class="num">En proceso</th>
                <th class="num">No continuaron</th>
                <th class="num">Contratados</th>
                <th class="num">$ / candidato</th>
                <th class="num">$ / contratado</th>
                <th class="num">Conversión</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($campanas as $c)
                <tr>
                    <td>{{ $c['nombre'] }}<br><span style="color: var(--texto-2); font-size: 8px;">{{ $c['vacante'] }}</span></td>
                    <td>{{ $c['canal'] }}</td>
                    <td class="num">{{ $dinero($c['gasto']) }}</td>
                    <td class="num">{{ $entero($c['llegaron']) }}</td>
                    <td class="num">{{ $entero($c['en_proceso']) }}</td>
                    <td class="num">{{ $entero($c['no_continuaron']) }}</td>
                    <td class="num">{{ $entero($c['contratados']) }}</td>
                    <td class="num">{{ $dinero($c['costo_por_candidato']) }}</td>
                    <td class="num">{{ $dinero($c['costo_por_contratado']) }}</td>
                    <td class="num">{{ $pct($c['conversion']) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">Total</td>
                <td class="num">{{ $dinero($totales['gasto']) }}</td>
                <td class="num">{{ $entero($totales['llegaron']) }}</td>
                <td class="num">{{ $entero($totales['en_proceso']) }}</td>
                <td class="num">{{ $entero($totales['no_continuaron']) }}</td>
                <td class="num">{{ $entero($totales['contratados']) }}</td>
                <td class="num">{{ $dinero($totales['costo_por_candidato']) }}</td>
                <td class="num">{{ $dinero($totales['costo_por_contratado']) }}</td>
                <td class="num">{{ $pct($totales['conversion']) }}</td>
            </tr>
        </tfoot>
    </table>

    <h2>Todas las campañas: embudo y motivos</h2>
    @include('pdf.reporte-campanas-bloques', ['r' => $totales])
@endif

{{-- ===================== Detalle por campaña ===================== --}}
@forelse ($campanas as $i => $c)
    <section class="campana {{ $i === 0 && ! $varias ? 'primera' : '' }}">
        <h2 style="margin-top: 0;">{{ $c['nombre'] }}</h2>

        <div class="ficha">
            <div><b>Canal:</b> {{ $c['canal'] }}</div>
            <div><b>Vacante:</b> {{ $c['vacante'] }}</div>
            <div><b>Responsable:</b> {{ $c['responsable'] ?? '—' }}</div>
            <div><b>Inicio:</b> {{ $c['fecha_inicio'] ?? '—' }}</div>
            <div><b>Fin:</b> {{ $c['fecha_fin'] ?? 'Vigente' }}</div>
            <div><b>Días promedio a contratar:</b> {{ $c['dias_cobertura'] ?? '—' }}</div>
        </div>

        <div class="kpis">
            <div class="kpi oro"><p class="etiqueta">Gasto real</p><p class="valor">{{ $dinero($c['gasto']) }}</p>@if ($c['presupuesto'] !== null)<p class="nota">Presupuesto {{ $dinero($c['presupuesto']) }}</p>@endif</div>
            <div class="kpi"><p class="etiqueta">Llegaron</p><p class="valor">{{ $entero($c['llegaron']) }}</p></div>
            <div class="kpi"><p class="etiqueta">Contratados</p><p class="valor">{{ $entero($c['contratados']) }}</p><p class="nota">Conversión {{ $pct($c['conversion']) }} · Laborando {{ $entero($c['laborando']) }}</p></div>
            <div class="kpi peligro"><p class="etiqueta">No continuaron</p><p class="valor">{{ $entero($c['no_continuaron']) }}</p><p class="nota">En proceso: {{ $entero($c['en_proceso']) }}</p></div>
            <div class="kpi oro"><p class="etiqueta">Costo por candidato</p><p class="valor">{{ $dinero($c['costo_por_candidato']) }}</p></div>
            <div class="kpi oro"><p class="etiqueta">Costo por contratado</p><p class="valor">{{ $dinero($c['costo_por_contratado']) }}</p></div>
            <div class="kpi peligro"><p class="etiqueta">Gasto en los que no siguieron</p><p class="valor">{{ $dinero($c['gasto_en_no_continuaron']) }}</p></div>
            <div class="kpi"><p class="etiqueta">Clics / costo por clic</p><p class="valor">{{ $entero($c['clics']) }}</p><p class="nota">{{ $dinero($c['costo_por_clic']) }} por clic · {{ $entero($c['impresiones']) }} impresiones</p></div>
        </div>

        @include('pdf.reporte-campanas-bloques', ['r' => $c])

        @if ($conCandidatos)
            <h3 style="margin-top: 12px;">Candidatos de la campaña</h3>
            @if (count($c['candidatos']) === 0)
                <p class="vacio">Ningún candidato registrado con esta campaña todavía.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Candidato</th>
                            <th>Registrado</th>
                            <th>Fase alcanzada</th>
                            <th>Situación</th>
                            <th>¿Por qué no continuó?</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($c['candidatos'] as $cand)
                            <tr>
                                <td>{{ $cand['nombre'] }}</td>
                                <td>{{ $cand['registrado'] ?? '—' }}</td>
                                <td>{{ $cand['fase_alcanzada'] }}</td>
                                <td>
                                    <span class="pastilla {{ $cand['salio'] ? 'salio' : ($cand['sigue'] ? 'sigue' : 'contratado') }}">{{ $cand['estado'] }}</span>
                                </td>
                                <td>{{ $cand['motivo'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif
    </section>
@empty
    <p class="vacio">No hay campañas con los filtros elegidos.</p>
@endforelse

<p class="nota-pie">
    Costo por candidato = gasto real ÷ candidatos registrados con la campaña. Costo por contratado = gasto ÷ personas
    contratadas que produjo (misma cifra de la pantalla de Campañas). «Fase alcanzada» es la más avanzada a la que llegó
    el candidato antes de salir del proceso.
</p>

</body>
</html>
