{{--
    Rotación de personal · Reporte detallado (carta vertical, Browsershot).
    Recibe $datos = RotacionExportService::datos() y $detalle =
    HistoricoRotacionService::filas(..., false) o null cuando quien exporta no
    puede ver personas. Todas las cifras salen de $datos/$detalle: aquí solo se
    da formato (y anchos/intensidades como proporción del máximo), así el PDF
    nunca difiere de la pantalla. Un dato que no existe es «—» o «Sin datos»,
    nunca 0. El pie con «Página X de Y» lo dibuja el motor (OpcionesPdf).
--}}
@php
    use Carbon\CarbonImmutable;

    $alcance = $datos['alcance'];
    $resumen = $datos['resumen'];
    $tendencia = $datos['tendencia'];
    $causales = $datos['causales'];
    $antiguedad = $datos['antiguedad'];
    $sucursales = $datos['sucursales'];
    $matriz = $datos['matriz'];
    $calidad = $datos['calidad'];
    $hallazgos = $datos['hallazgos'] ?? [];

    $entero = fn ($v): string => $v === null ? '—' : number_format((int) $v);
    $decimal = fn ($v): string => $v === null ? '—' : number_format((float) $v, 1);
    $pct = fn ($v): string => $v === null ? '—' : number_format((float) $v, 1).' %';
    $fecha = fn (?string $v): string => $v === null || $v === '' ? '—' : CarbonImmutable::parse($v)->format('d/m/Y');
    $mesesLargos = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $etiquetaMes = function (?string $periodo) use ($mesesLargos): string {
        if ($periodo === null || ! str_contains($periodo, '-')) {
            return $periodo ?? '—';
        }
        [$a, $m] = array_map('intval', explode('-', $periodo));

        return ucfirst($mesesLargos[$m] ?? (string) $m).' '.$a;
    };
    $ancho = fn (int|float $v, int|float $max): string => number_format($max > 0 ? min(100, $v * 100 / $max) : 0, 2, '.', '');

    $conDetalle = ($alcance['puesto'] ?? null) !== null || ($alcance['area'] ?? null) !== null
        || ($alcance['clasificacion'] ?? null) !== null || ($alcance['causal'] ?? null) !== null
        || ($alcance['antiguedad'] ?? null) !== null;
    $sinIndice = $conDetalle ? 'No aplica con filtros de detalle' : 'Sin datos de plantilla';

    $rotacion = $resumen['rotacion'] ?? [];
    $temprana = $resumen['temprana'];
    $comparativo = $resumen['comparativo'] ?? null;

    $periodos = ['mes' => 'Mes', 'acumulado' => 'Acumulado del año', 'anio' => 'Año completo', 'rango' => 'Rango de fechas'];
    $tipoPeriodo = $periodos[$alcance['filtros']['periodo'] ?? ''] ?? '—';

    $claseClasif = fn (?string $c): string => match ($c) {
        'voluntaria' => 'vol',
        'involuntaria' => 'invol',
        default => 'sincl',
    };

    $maxMes = max(1, ...array_map(fn (array $m): int => (int) $m['total'], $tendencia['meses'] ?: [['total' => 0]]));
    $maxCausal = max(1, ...array_map(fn (array $c): int => (int) $c['bajas'], $causales['causales'] ?: [['bajas' => 0]]));
    $maxRango = max(1, ...array_map(fn (array $r): int => (int) $r['bajas'], $antiguedad['rangos'] ?: [['bajas' => 0]]));

    // Matriz: la letra se reduce con muchas causales para que quepa a lo ancho.
    $nColumnas = count($matriz['columnas']);
    $letraMatriz = $nColumnas <= 6 ? 8 : ($nColumnas <= 10 ? 7 : ($nColumnas <= 16 ? 6 : 5.2));
    $encabezadoVertical = $nColumnas > 6;
    $maximoMatriz = max(1, (int) $matriz['maximo']);
    $celdaCalor = function (int $n) use ($maximoMatriz): string {
        if ($n <= 0) {
            return '';
        }
        $r = $n / $maximoMatriz;
        $alfa = number_format(0.10 + 0.80 * $r, 3, '.', '');

        return sprintf('background: rgba(49, 91, 89, %s);%s', $alfa, $r > 0.55 ? ' color: #fff;' : '');
    };

    $alertas = [
        'sin_causal' => 'Sin causal',
        'sin_homologar' => 'Sin homologar',
        'sin_fecha' => 'Sin fecha de ingreso',
        'inconsistente' => 'Fechas inconsistentes',
    ];

    $filtrosAplicados = [
        'Empresa' => $alcance['empresa'] ?? null,
        'Sucursal' => $alcance['sucursal'] ?? null,
        'Puesto' => $alcance['puesto'] ?? null,
        'Área' => $alcance['area'] ?? null,
        'Tipo de salida' => $alcance['clasificacion'] ?? null,
        'Causal homologada' => $alcance['causal'] ?? null,
        'Antigüedad' => $alcance['antiguedad'] ?? null,
        'Fuente' => $alcance['fuente'] ?? null,
    ];

    $numCalidad = $detalle !== null ? 12 : 11;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Rotación de personal · Reporte detallado</title>
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

        @page { size: letter portrait; margin: 12mm 12mm 17mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Segoe UI', 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 9.5px; line-height: 1.45; color: var(--texto); -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        /* 1 · Portada */
        .portada { display: flex; border-radius: 12px; overflow: hidden; background: var(--petroleo); color: #fff; margin-bottom: 18px; }
        .portada-filete { width: 9px; background: #af8b51; flex: none; }
        .portada-cuerpo { flex: 1; padding: 26px 24px; }
        .marca { display: flex; align-items: center; gap: 10px; margin-bottom: 36px; }
        .marca strong { display: block; font-family: Georgia, 'Times New Roman', serif; font-size: 17px; }
        .marca span { display: block; font-size: 7px; letter-spacing: 2.2px; text-transform: uppercase; opacity: .78; }
        .portada h1 { margin: 0; font-size: 26px; line-height: 1.15; }
        .portada .sub { margin: 6px 0 0; font-size: 12px; opacity: .9; }
        .portada .periodo { margin: 18px 0 0; font-size: 11px; opacity: .85; }

        .ficha { display: grid; grid-template-columns: 1fr 1fr; gap: 0 18px; border: 1px solid var(--borde); border-radius: 10px; padding: 6px 14px; background: var(--superficie); margin-bottom: 12px; }
        .ficha div { display: flex; justify-content: space-between; gap: 10px; padding: 5px 0; border-bottom: 1px solid var(--borde); font-size: 9.5px; }
        .ficha span { color: var(--texto-2); }
        .ficha b { color: var(--petroleo); text-align: right; }
        .ficha b.tenue { color: #a8a39a; font-weight: 400; }

        .indice-contenido { columns: 2; column-gap: 18px; font-size: 9.5px; margin: 0; padding-left: 18px; }

        /* Secciones */
        .seccion { break-before: page; }
        .seccion.sigue { break-before: auto; margin-top: 18px; }
        h2 { font-size: 14px; color: var(--petroleo); margin: 0 0 4px; padding-bottom: 5px; border-bottom: 2px solid var(--petroleo); }
        h2 .num { color: var(--bronce); margin-right: 6px; }
        h3 { font-size: 10px; color: var(--petroleo); margin: 12px 0 6px; text-transform: uppercase; letter-spacing: .5px; }
        .intro { color: var(--texto-2); font-size: 9px; margin: 4px 0 10px; }

        .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 10px; }
        .kpi { background: var(--superficie); border: 1px solid var(--borde); border-left: 4px solid var(--esmeralda); border-radius: 6px; padding: 6px 8px; break-inside: avoid; }
        .kpi.oro { border-left-color: var(--oro); }
        .kpi.gris { border-left-color: #b5aea2; }
        .kpi.peligro { border-left-color: var(--peligro-texto); }
        .kpi .etiqueta { font-size: 7.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: var(--texto-2); margin: 0 0 2px; }
        .kpi .valor { font-size: 16px; font-weight: 700; color: var(--petroleo); margin: 0; }
        .kpi .valor small { font-size: 9.5px; font-weight: 600; color: var(--texto-2); }
        .kpi .valor.texto { font-size: 10px; color: var(--texto-2); }
        .kpi .nota { font-size: 7.5px; color: var(--texto-2); margin: 2px 0 0; }

        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; page-break-inside: avoid; }
        th { background: var(--salvia); color: var(--petroleo); font-size: 7.5px; text-transform: uppercase; letter-spacing: .3px; text-align: left; padding: 5px 5px; vertical-align: bottom; }
        td { padding: 4px 5px; border-bottom: 1px solid var(--borde); font-size: 8.5px; vertical-align: top; }
        td.num, th.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        td.tenue, span.tenue { color: var(--texto-2); }
        tfoot td { font-weight: 700; background: var(--crema); }
        tr.mayor td { background: #fbefed; }
        tr.sin-datos td { color: #a8a39a; font-style: italic; }
        .tabla-chica td { font-size: 7.5px; padding: 3px 4px; }
        .tabla-chica th { font-size: 6.8px; padding: 4px; }

        .formulas td:first-child { font-weight: 700; color: var(--petroleo); width: 30%; }
        .formulas td:last-child { font-family: Consolas, 'Courier New', monospace; font-size: 8.5px; }

        .barra-fila { display: grid; grid-template-columns: 34% 1fr 90px; align-items: center; gap: 6px; margin-bottom: 3px; break-inside: avoid; }
        .barra-etiqueta { font-size: 8.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .barra-pista { height: 11px; background: var(--menta); border-radius: 3px; overflow: hidden; display: flex; }
        .barra-pista .relleno { height: 100%; background: var(--esmeralda); }
        .barra-pista .vol { background: var(--vol); }
        .barra-pista .invol { background: var(--invol); }
        .barra-pista .sincl { background: var(--sincl); }
        .barra-pista .temprana { background: #d9a199; }
        .barra-pista.sin-datos { background: repeating-linear-gradient(45deg, #f4f1ec, #f4f1ec 3px, #e9e4dc 3px, #e9e4dc 6px); }
        .barra-valor { font-size: 8.5px; text-align: right; white-space: nowrap; }
        .barra-valor b { color: var(--petroleo); }
        .mini-barra { height: 6px; background: var(--esmeralda); border-radius: 2px; margin-top: 3px; }

        .leyenda { display: flex; flex-wrap: wrap; gap: 4px 14px; font-size: 8px; color: var(--texto-2); margin: 4px 0 8px; }
        .leyenda i { display: inline-block; width: 9px; height: 9px; border-radius: 2px; vertical-align: -1px; margin-right: 3px; }

        .pastilla { display: inline-block; border-radius: 999px; padding: 0 6px; font-size: 7.2px; font-weight: 700; white-space: nowrap; }
        .pastilla.vol { background: var(--salvia); color: var(--petroleo); }
        .pastilla.invol { background: var(--alerta); color: var(--alerta-texto); }
        .pastilla.sincl { background: #ece8e1; color: var(--texto-2); }
        .pastilla.peligro { background: var(--peligro); color: var(--peligro-texto); }
        .pastilla.alerta-dato { background: var(--alerta); color: var(--alerta-texto); }

        .columnas { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .caja { border: 1px solid var(--borde); border-radius: 8px; padding: 8px 10px; background: var(--superficie); break-inside: avoid; margin-bottom: 10px; }
        .lista-datos { margin: 0; }
        .lista-datos div { display: flex; justify-content: space-between; gap: 8px; padding: 3px 0; border-bottom: 1px dotted var(--borde); font-size: 9px; }
        .lista-datos div:last-child { border-bottom: none; }
        .lista-datos b { color: var(--petroleo); white-space: nowrap; }

        .aviso { background: var(--alerta); color: var(--alerta-texto); border-radius: 8px; padding: 6px 10px; font-size: 8.5px; margin: 6px 0 10px; }
        .nota { font-size: 8px; color: var(--texto-2); margin: 6px 0 0; }
        .vacio { color: var(--texto-2); font-style: italic; font-size: 9px; padding: 14px; border: 1px dashed var(--borde); border-radius: 8px; text-align: center; }
        ul.puntos { margin: 4px 0; padding-left: 16px; font-size: 9px; }
        ul.puntos li { margin-bottom: 3px; }

        .hallazgo { border: 1px solid var(--borde); border-left: 4px solid var(--esmeralda); border-radius: 6px; padding: 7px 10px; margin-bottom: 7px; break-inside: avoid; background: var(--superficie); }
        .hallazgo.alerta { border-left-color: var(--oro); }
        .hallazgo.peligro { border-left-color: var(--peligro-texto); }
        .hallazgo.neutro { border-left-color: #b5aea2; }
        .hallazgo b { display: block; font-size: 10px; color: var(--petroleo); margin-bottom: 2px; }
        .hallazgo span { font-size: 9px; color: var(--texto-2); }

        /* Matriz */
        .matriz { table-layout: fixed; }
        .matriz th, .matriz td { font-size: {{ $letraMatriz }}px; padding: 3px 2px; text-align: center; border: 1px solid #f0ebe3; }
        .matriz th.suc, .matriz td.suc { text-align: left; width: {{ $nColumnas > 10 ? 22 : 26 }}%; padding-left: 4px; }
        .matriz th.tot, .matriz td.tot { width: {{ $nColumnas > 10 ? 5 : 7 }}%; font-weight: 700; background: var(--crema); }
        .matriz th.vertical { height: 110px; vertical-align: bottom; padding: 4px 1px; }
        .matriz th.vertical span { writing-mode: vertical-rl; transform: rotate(180deg); white-space: nowrap; max-height: 104px; overflow: hidden; text-overflow: ellipsis; display: inline-block; text-align: left; }
        .matriz tfoot td { background: var(--crema); }
    </style>
</head>
<body>

{{-- ================= 1 · Portada y alcance ================= --}}
<div class="portada">
    <div class="portada-filete"></div>
    <div class="portada-cuerpo">
        <div class="marca">
            <svg width="40" height="27" viewBox="0 0 38 26" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <circle cx="9" cy="8" r="4.2" fill="#E9D6B0"/><path d="M2 25c0-6 3-10 7-10s7 4 7 10z" fill="#E9D6B0"/>
                <circle cx="29" cy="8" r="4.2" fill="#7FA79B"/><path d="M22 25c0-6 3-10 7-10s7 4 7 10z" fill="#7FA79B"/>
                <circle cx="19" cy="6" r="4.8" fill="#AF8B51"/><path d="M11 25c0-7 3.5-11 8-11s8 4 8 11z" fill="#AF8B51"/>
            </svg>
            <div><strong>Mr. Lana</strong><span>People · Contigo</span></div>
        </div>
        <h1>Rotación de personal</h1>
        <p class="sub">Reporte detallado · {{ $alcance['empresa'] ?? '—' }} · {{ $alcance['sucursal'] ?? '—' }}</p>
        <p class="periodo">Periodo del {{ $fecha($alcance['desde'] ?? null) }} al {{ $fecha($alcance['hasta'] ?? null) }} · Corte al {{ $fecha($alcance['corte'] ?? null) }}</p>
    </div>
</div>

<h2><span class="num">1</span>Alcance del reporte</h2>
<p class="intro">Filtros con los que se generó este reporte; son los mismos de la pantalla, así que las cifras coinciden con ella.</p>

<div class="ficha">
    <div><span>Tipo de periodo</span><b>{{ $tipoPeriodo }}</b></div>
    <div><span>Desde</span><b>{{ $fecha($alcance['desde'] ?? null) }}</b></div>
    <div><span>Hasta</span><b>{{ $fecha($alcance['hasta'] ?? null) }}</b></div>
    <div><span>Fecha de corte</span><b>{{ $fecha($alcance['corte'] ?? null) }}</b></div>
    @foreach ($filtrosAplicados as $etiqueta => $valor)
        <div><span>{{ $etiqueta }}</span>@if ($valor === null)<b class="tenue">Todos</b>@else<b>{{ $valor }}</b>@endif</div>
    @endforeach
    <div><span>Corte parcial</span><b>{{ ! empty($alcance['corte_parcial']) ? 'Sí, el último mes aún no termina' : 'No' }}</b></div>
    <div><span>Generado</span><b>{{ $alcance['generado'] ?? '—' }}</b></div>
</div>

@if ($conDetalle)
    <div class="aviso">
        Hay filtros de detalle (puesto, área, tipo de salida, causal o antigüedad): solo se cuentan bajas individuales y el índice de rotación no aplica, porque no hay plantilla por esos criterios.
        @if ((int) ($resumen['no_desglosables'] ?? 0) > 0)
            {{ $entero($resumen['no_desglosables']) }} baja(s) del histórico agregado (Excel) no se pudieron desglosar y no se cuentan.
        @endif
    </div>
@endif
@if (! empty($alcance['corte_parcial']))
    <div class="aviso">El último mes del periodo tiene corte parcial: sus cifras pueden cambiar al cerrar el mes.</div>
@endif

<h3>Contenido</h3>
<ol class="indice-contenido">
    <li>Alcance del reporte</li>
    <li>Metodología de cálculo</li>
    <li>Resumen de indicadores</li>
    <li>Evolución mensual</li>
    <li>Clasificación de salidas</li>
    <li>Análisis de causales</li>
    <li>Análisis de antigüedad</li>
    <li>Comparación de sucursales</li>
    <li>Matriz sucursales × causales</li>
    <li>Hallazgos principales</li>
    @if ($detalle !== null)<li>Relación individual de bajas</li>@endif
    <li>Calidad del dato</li>
</ol>

{{-- ================= 2 · Metodología ================= --}}
<section class="seccion">
    <h2><span class="num">2</span>Metodología de cálculo</h2>
    <p class="intro">Definiciones únicas del módulo (docs/ROTACION.md). La pantalla, el Excel y este reporte usan las mismas.</p>

    <table class="formulas">
        <thead><tr><th>Indicador</th><th>Fórmula</th></tr></thead>
        <tbody>
            <tr><td>Participación</td><td>bajas del grupo ÷ bajas del alcance × 100</td></tr>
            <tr><td>Índice mensual</td><td>bajas del mes ÷ plantilla promedio del mes × 100</td></tr>
            <tr><td>Plantilla promedio</td><td>(plantilla al inicio + plantilla al cierre) ÷ 2</td></tr>
            <tr><td>Rotación del periodo</td><td>bajas del periodo ÷ promedio de las plantillas promedio mensuales × 100</td></tr>
            <tr><td>Promedio de índices</td><td>promedio de los índices mensuales con dato</td></tr>
            <tr><td>Promedio mensual de bajas</td><td>bajas ÷ meses considerados (eventos por mes, no %)</td></tr>
            <tr><td>Rotación temprana</td><td>bajas antes de 6 meses ÷ bajas con fecha de ingreso válida</td></tr>
        </tbody>
    </table>

    <h3>Fuentes de información</h3>
    <table>
        <thead><tr><th style="width: 22%;">Fuente</th><th>Qué aporta</th></tr></thead>
        <tbody>
            <tr><td><b>Sistema</b></td><td>Bajas registradas en el módulo de Bajas que cuentan como rotación, en trámite o cerradas, por su último día laborado ya transcurrido. «No procede» y movimientos internos no cuentan.</td></tr>
            <tr><td><b>Histórico individual</b></td><td>Bajas de periodos anteriores cargadas una por una (persona, sucursal, fechas, motivo y causal). Solo cuentan las válidas; las ligadas a una baja del sistema no se cuentan dos veces.</td></tr>
            <tr><td><b>Histórico agregado</b></td><td>Total mensual por sucursal del Excel de rotación (a lo más voluntarias/involuntarias). No trae persona, causal ni antigüedad.</td></tr>
        </tbody>
    </table>

    <h3>Reglas</h3>
    <ul class="puntos">
        <li>En cada mes y sucursal, el total son las bajas individuales; si el Excel trae un total mayor, la diferencia se cuenta como <b>sin desglose</b>. Si no coinciden, la celda se informa como <b>discrepancia</b> en «Calidad del dato».</li>
        <li><b>Total de bajas = voluntarias + involuntarias + sin clasificar.</b> Lo sin desglose del Excel cae en «sin clasificar».</li>
        <li>Un mes anterior al inicio del sistema sin ninguna fuente es <b>«Sin datos»</b>, no cero. Los meses futuros no se muestran.</li>
        <li>Sin plantilla, el índice se informa como <b>«Sin datos de plantilla»</b>, nunca 0 %. El índice global no es la suma de los de cada sucursal: se calcula con las bajas y plantillas del conjunto.</li>
        <li>La antigüedad se mide en meses calendario desde el ingreso de esa relación laboral hasta el último día laborado. Sin fecha de ingreso, o con baja anterior al ingreso, el registro se excluye de los denominadores y se informa.</li>
        <li>Con filtros de detalle (puesto, área, tipo de salida, causal o antigüedad) solo se cuentan bajas individuales y el índice no aplica.</li>
        <li>«Abandono de empleo o faltas» es <b>voluntaria solo en la estadística</b>; en el flujo de Bajas es rescisión. La clasificación analítica no es una conclusión jurídica.</li>
    </ul>
</section>

{{-- ================= 3 · Resumen ================= --}}
<section class="seccion">
    <h2><span class="num">3</span>Resumen de indicadores</h2>
    <p class="intro">Periodo del {{ $fecha($alcance['desde'] ?? null) }} al {{ $fecha($alcance['hasta'] ?? null) }}.</p>

    <div class="kpis">
        <div class="kpi">
            <p class="etiqueta">Total de bajas</p>
            <p class="valor">{{ $entero($resumen['total']) }}</p>
            <p class="nota">{{ $entero($resumen['meses']) }} mes(es) con información</p>
        </div>
        <div class="kpi">
            <p class="etiqueta">Promedio mensual</p>
            <p class="valor">{{ $decimal($resumen['promedio_mensual']) }} <small>bajas/mes</small></p>
            <p class="nota">Eventos por mes, no %</p>
        </div>
        <div class="kpi peligro">
            <p class="etiqueta">Rotación temprana</p>
            @if ($temprana['porcentaje'] === null)
                <p class="valor texto">Sin fechas de ingreso válidas</p>
            @else
                <p class="valor">{{ $pct($temprana['porcentaje']) }}</p>
            @endif
            <p class="nota">{{ $entero($temprana['bajas']) }} de {{ $entero($temprana['base']) }} con fecha de ingreso válida</p>
        </div>
        <div class="kpi">
            <p class="etiqueta">Rotación del periodo</p>
            @if (($rotacion['indice'] ?? null) === null)
                <p class="valor texto">{{ $sinIndice }}</p>
            @else
                <p class="valor">{{ $pct($rotacion['indice']) }}</p>
            @endif
            <p class="nota">Plantilla promedio {{ $decimal($rotacion['plantilla_promedio'] ?? null) }}</p>
        </div>
    </div>

    <table>
        <thead><tr><th>Indicador</th><th class="num">Valor</th><th>Nota</th></tr></thead>
        <tbody>
            <tr><td>Total de bajas</td><td class="num"><b>{{ $entero($resumen['total']) }}</b></td><td class="tenue">{{ ! empty($resumen['identidad']) ? 'Cuadra: voluntarias + involuntarias + sin clasificar = total' : 'No cuadra la suma por tipo de salida: revisar calidad del dato' }}</td></tr>
            <tr><td>Meses considerados</td><td class="num">{{ $entero($resumen['meses']) }}</td><td class="tenue">Meses del rango con alguna fuente o ya cubiertos por el sistema</td></tr>
            <tr><td>Promedio mensual de bajas</td><td class="num">{{ $decimal($resumen['promedio_mensual']) }}</td><td class="tenue">Bajas ÷ meses considerados</td></tr>
            <tr><td>Voluntarias</td><td class="num">{{ $entero($resumen['voluntarias']['bajas']) }}</td><td class="tenue">{{ $pct($resumen['voluntarias']['porcentaje']) }} del total</td></tr>
            <tr><td>Involuntarias</td><td class="num">{{ $entero($resumen['involuntarias']['bajas']) }}</td><td class="tenue">{{ $pct($resumen['involuntarias']['porcentaje']) }} del total</td></tr>
            <tr><td>Sin clasificar</td><td class="num">{{ $entero($resumen['sin_clasificar']['bajas']) }}</td><td class="tenue">{{ $pct($resumen['sin_clasificar']['porcentaje']) }} del total · {{ $entero($resumen['sin_clasificar']['sin_desglose']) }} del histórico sin desglose · {{ $entero($resumen['sin_clasificar']['por_revisar']) }} por revisar una por una</td></tr>
            @if ((int) ($resumen['no_desglosables'] ?? 0) > 0)
                <tr><td>No desglosables</td><td class="num">{{ $entero($resumen['no_desglosables']) }}</td><td class="tenue">Bajas del histórico agregado que los filtros de detalle no pueden responder (no se cuentan)</td></tr>
            @endif
            <tr><td>Rotación temprana</td><td class="num">{{ $pct($temprana['porcentaje']) }}</td><td class="tenue">{{ $entero($temprana['bajas']) }} de {{ $entero($temprana['base']) }} con fecha de ingreso válida · {{ $entero($temprana['excluidas']) }} excluidas del denominador</td></tr>
            <tr><td>Rotación del periodo (índice)</td><td class="num">{{ ($rotacion['indice'] ?? null) === null ? $sinIndice : $pct($rotacion['indice']) }}</td><td class="tenue">Bajas ÷ promedio de las plantillas promedio mensuales × 100</td></tr>
            <tr><td>Plantilla promedio</td><td class="num">{{ ($rotacion['plantilla_promedio'] ?? null) === null ? $sinIndice : $decimal($rotacion['plantilla_promedio']) }}</td><td class="tenue">{{ $entero($rotacion['meses_con_plantilla'] ?? 0) }} mes(es) con plantilla</td></tr>
            <tr><td>Promedio de índices mensuales</td><td class="num">{{ ($rotacion['promedio_indices'] ?? null) === null ? $sinIndice : $pct($rotacion['promedio_indices']) }}</td><td class="tenue">Promedio de los índices mensuales con dato</td></tr>
            <tr><td>Salidas estructurales</td><td class="num">{{ $entero($resumen['estructurales'] ?? 0) }}</td><td class="tenue">Reestructura, cierre o fin de contrato; siguen dentro del total</td></tr>
        </tbody>
    </table>

    <h3>Comparativo con el periodo anterior</h3>
    @if ($comparativo === null)
        <p class="vacio">Sin periodo anterior comparable con información.</p>
    @else
        <table>
            <thead><tr><th>Concepto</th><th class="num">Periodo anterior</th><th class="num">Periodo actual</th><th class="num">Diferencia</th></tr></thead>
            <tbody>
                <tr>
                    <td>Periodo</td>
                    <td class="num">{{ $fecha($comparativo['desde']) }} – {{ $fecha($comparativo['hasta']) }}</td>
                    <td class="num">{{ $fecha($alcance['desde'] ?? null) }} – {{ $fecha($alcance['hasta'] ?? null) }}</td>
                    <td class="num">—</td>
                </tr>
                <tr>
                    <td>Bajas</td>
                    <td class="num">{{ $entero($comparativo['total']) }}</td>
                    <td class="num">{{ $entero($resumen['total']) }}</td>
                    <td class="num">{{ $comparativo['delta_total'] > 0 ? '+' : '' }}{{ $entero($comparativo['delta_total']) }}</td>
                </tr>
                <tr>
                    <td>Índice de rotación</td>
                    <td class="num">{{ $comparativo['indice'] === null ? $sinIndice : $pct($comparativo['indice']) }}</td>
                    <td class="num">{{ ($rotacion['indice'] ?? null) === null ? $sinIndice : $pct($rotacion['indice']) }}</td>
                    <td class="num">{{ $comparativo['delta_indice'] === null ? '—' : ($comparativo['delta_indice'] > 0 ? '+' : '').number_format((float) $comparativo['delta_indice'], 1).' pts' }}</td>
                </tr>
            </tbody>
        </table>
    @endif
</section>

{{-- ================= 4 · Evolución mensual ================= --}}
<section class="seccion">
    <h2><span class="num">4</span>Evolución mensual</h2>
    <p class="intro">
        Promedio por mes con datos: <b>{{ $decimal($tendencia['promedio']) }}</b> bajas.
        @if ($tendencia['mes_mayor'] !== null) Mes con más bajas: <b>{{ $etiquetaMes($tendencia['mes_mayor']) }}</b> (resaltado). @endif
    </p>

    @if ($tendencia['meses'] === [])
        <p class="vacio">Sin meses en el periodo consultado.</p>
    @else
        <table class="tabla-chica">
            <thead>
                <tr>
                    <th>Mes</th>
                    <th class="num">Total</th>
                    <th class="num">Volunt.</th>
                    <th class="num">Involunt.</th>
                    <th class="num">Sin clasif.</th>
                    <th class="num">Sin desglose</th>
                    <th class="num">Plantilla prom.</th>
                    <th class="num">Índice</th>
                    <th>Notas</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tendencia['meses'] as $m)
                    @php
                        $notas = array_values(array_filter([
                            $m['sin_datos'] ? 'Sin datos (no es cero)' : null,
                            $m['corte_parcial'] ? 'Corte parcial' : null,
                            $m['en_curso'] && ! $m['corte_parcial'] ? 'Mes en curso' : null,
                            (int) $m['discrepancias'] > 0 ? sprintf('%d discrepancia(s)', $m['discrepancias']) : null,
                            (int) ($m['no_desglosables'] ?? 0) > 0 && $conDetalle ? sprintf('%d no desglosable(s)', $m['no_desglosables']) : null,
                            in_array('individual', $m['fuentes'] ?? [], true) && in_array('agregado', $m['fuentes'] ?? [], true) ? 'Individual + agregado' : (in_array('agregado', $m['fuentes'] ?? [], true) ? 'Solo agregado' : null),
                        ]));
                    @endphp
                    <tr class="{{ $m['sin_datos'] ? 'sin-datos' : '' }} {{ $m['periodo'] === $tendencia['mes_mayor'] ? 'mayor' : '' }}">
                        <td>{{ $etiquetaMes($m['periodo']) }}</td>
                        @if ($m['sin_datos'])
                            <td class="num">—</td><td class="num">—</td><td class="num">—</td><td class="num">—</td><td class="num">—</td>
                            <td class="num">—</td><td class="num">Sin datos</td>
                        @else
                            <td class="num"><b>{{ $entero($m['total']) }}</b></td>
                            <td class="num">{{ $entero($m['voluntarias']) }}</td>
                            <td class="num">{{ $entero($m['involuntarias']) }}</td>
                            <td class="num">{{ $entero($m['sin_clasificar']) }}</td>
                            <td class="num">{{ $entero($m['sin_desglose']) }}</td>
                            <td class="num">{{ $m['plantilla_promedio'] === null ? '—' : $decimal($m['plantilla_promedio']) }}</td>
                            <td class="num">{!! $m['indice'] === null ? '<span class="tenue">'.e($conDetalle ? 'No aplica' : 'Sin datos de plantilla').'</span>' : e($pct($m['indice'])) !!}</td>
                        @endif
                        <td>{{ $notas === [] ? '' : implode(' · ', $notas) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h3>Bajas por mes y tipo de salida</h3>
        <div class="leyenda">
            <span><i style="background: var(--vol);"></i>Voluntarias</span>
            <span><i style="background: var(--invol);"></i>Involuntarias</span>
            <span><i style="background: var(--sincl);"></i>Sin clasificar</span>
            <span><i style="background: repeating-linear-gradient(45deg, #f4f1ec, #f4f1ec 2px, #d9d3c9 2px, #d9d3c9 4px);"></i>Sin datos</span>
        </div>
        @foreach ($tendencia['meses'] as $m)
            <div class="barra-fila">
                <span class="barra-etiqueta" style="{{ $m['periodo'] === $tendencia['mes_mayor'] ? 'font-weight: 700; color: var(--peligro-texto);' : '' }}">{{ $etiquetaMes($m['periodo']) }}{{ $m['corte_parcial'] ? ' (parcial)' : '' }}</span>
                @if ($m['sin_datos'])
                    <div class="barra-pista sin-datos"></div>
                    <span class="barra-valor tenue">Sin datos</span>
                @else
                    <div class="barra-pista">
                        <div style="display: flex; width: {{ $ancho($m['total'], $maxMes) }}%;">
                            @if ($m['voluntarias'] > 0)<div class="relleno vol" style="width: {{ $ancho($m['voluntarias'], max(1, $m['total'])) }}%;"></div>@endif
                            @if ($m['involuntarias'] > 0)<div class="relleno invol" style="width: {{ $ancho($m['involuntarias'], max(1, $m['total'])) }}%;"></div>@endif
                            @if ($m['sin_clasificar'] > 0)<div class="relleno sincl" style="width: {{ $ancho($m['sin_clasificar'], max(1, $m['total'])) }}%;"></div>@endif
                        </div>
                    </div>
                    <span class="barra-valor"><b>{{ $entero($m['total']) }}</b>{{ $m['indice'] !== null ? ' · '.$pct($m['indice']) : '' }}</span>
                @endif
            </div>
        @endforeach
    @endif
</section>

{{-- ================= 5 · Clasificación de salidas ================= --}}
<section class="seccion">
    <h2><span class="num">5</span>Clasificación de salidas</h2>
    <p class="intro">Clasificación analítica de cada baja según su causal homologada (o su forma de salida cuando no está homologada).</p>

    <table>
        <thead><tr><th>Tipo de salida</th><th class="num">Bajas</th><th class="num">% del total</th><th style="width: 40%;"></th></tr></thead>
        <tbody>
            @foreach ([['Voluntarias', 'voluntarias', 'vol'], ['Involuntarias', 'involuntarias', 'invol'], ['Sin clasificar', 'sin_clasificar', 'sincl']] as [$etiqueta, $clave, $clase])
                <tr>
                    <td><span class="pastilla {{ $clase }}">{{ $etiqueta }}</span></td>
                    <td class="num"><b>{{ $entero($resumen[$clave]['bajas']) }}</b></td>
                    <td class="num">{{ $pct($resumen[$clave]['porcentaje']) }}</td>
                    <td><div class="barra-pista"><div class="relleno {{ $clase }}" style="width: {{ $ancho($resumen[$clave]['bajas'], max(1, $resumen['total'])) }}%;"></div></div></td>
                </tr>
            @endforeach
        </tbody>
        <tfoot><tr><td>Total</td><td class="num">{{ $entero($resumen['total']) }}</td><td class="num">{{ (int) $resumen['total'] > 0 ? '100.0 %' : '—' }}</td><td></td></tr></tfoot>
    </table>
    <p class="nota">
        De las sin clasificar, {{ $entero($resumen['sin_clasificar']['sin_desglose']) }} vienen del histórico agregado sin desglose (no se pueden revisar una por una) y {{ $entero($resumen['sin_clasificar']['por_revisar']) }} son bajas individuales por revisar.
    </p>

    {{-- ================= 6 · Causales ================= --}}
    <div class="seccion sigue">
        <h2><span class="num">6</span>Análisis de causales</h2>
        <p class="intro">Causales homologadas sobre {{ $entero($causales['base']) }} bajas individuales, de mayor a menor. El motivo original capturado se conserva en la relación individual.</p>

        @if ($causales['causales'] === [])
            <p class="vacio">Sin bajas individuales en el periodo consultado.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th style="width: 18px;">#</th>
                        <th>Causal homologada</th>
                        <th>Clasificación</th>
                        <th class="num">Bajas</th>
                        <th class="num">%</th>
                        <th class="num">Sucursales</th>
                        <th style="width: 22%;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($causales['causales'] as $i => $c)
                        <tr>
                            <td class="tenue">{{ $i + 1 }}</td>
                            <td>{{ $c['nombre'] }}@if (! empty($c['es_estructural'])) <span class="pastilla sincl">Estructural</span>@endif</td>
                            <td><span class="pastilla {{ $claseClasif($c['clasificacion']) }}">{{ $c['clasificacion_etiqueta'] }}</span></td>
                            <td class="num"><b>{{ $entero($c['bajas']) }}</b></td>
                            <td class="num">{{ $pct($c['porcentaje']) }}</td>
                            <td class="num">{{ $entero($c['sucursales']) }}</td>
                            <td><div class="mini-barra" style="width: {{ $ancho($c['bajas'], $maxCausal) }}%;"></div></td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot><tr><td></td><td>Total con causal</td><td></td><td class="num">{{ $entero($causales['base']) }}</td><td></td><td></td><td></td></tr></tfoot>
            </table>
        @endif
        @if ((int) $causales['sin_desglose'] > 0)
            <p class="nota">{{ $entero($causales['sin_desglose']) }} baja(s) del histórico agregado (Excel) no tienen causal: se informan aparte y no se cuentan como una causal.</p>
        @endif
    </div>
</section>

{{-- ================= 7 · Antigüedad ================= --}}
<section class="seccion">
    <h2><span class="num">7</span>Análisis de antigüedad</h2>
    <p class="intro">Meses calendario desde el ingreso de esa relación laboral hasta el último día laborado. Porcentajes sobre {{ $entero($antiguedad['base']) }} bajas con fecha de ingreso válida.</p>

    <table>
        <thead><tr><th>Rango</th><th class="num">Bajas</th><th class="num">%</th><th style="width: 40%;"></th></tr></thead>
        <tbody>
            @foreach ($antiguedad['rangos'] as $r)
                <tr>
                    <td>{{ $r['etiqueta'] }}@if ($r['temprana']) <span class="pastilla peligro">Temprana</span>@endif</td>
                    <td class="num"><b>{{ $entero($r['bajas']) }}</b></td>
                    <td class="num">{{ $r['clave'] === 'sin_fecha' ? 'Excluidas' : $pct($r['porcentaje']) }}</td>
                    <td><div class="barra-pista"><div class="relleno {{ $r['temprana'] ? 'temprana' : ($r['clave'] === 'sin_fecha' ? 'sincl' : '') }}" style="width: {{ $ancho($r['bajas'], $maxRango) }}%;"></div></div></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="columnas" style="margin-top: 10px;">
        <div class="caja">
            <h3 style="margin-top: 0;">Salidas tempranas</h3>
            <div class="lista-datos">
                <div><span>Antes de 90 días</span><b>{{ $entero($antiguedad['antes_90_dias']) }}</b></div>
                <div><span>Antes de 6 meses</span><b>{{ $entero($antiguedad['antes_6_meses']) }}</b></div>
                <div><span>Durante el primer año</span><b>{{ $entero($antiguedad['primer_anio']) }}</b></div>
                <div><span>Base (con fecha de ingreso válida)</span><b>{{ $entero($antiguedad['base']) }}</b></div>
            </div>
        </div>
        <div class="caja">
            <h3 style="margin-top: 0;">Antigüedad al salir</h3>
            <div class="lista-datos">
                <div><span>Promedio</span><b>{{ $antiguedad['promedio_dias'] === null ? '—' : $entero($antiguedad['promedio_dias']).' días' }}</b></div>
                <div><span>Mediana</span><b>{{ $antiguedad['mediana_dias'] === null ? '—' : $entero($antiguedad['mediana_dias']).' días' }}</b></div>
                <div><span>Excluidas (sin fecha de ingreso válida)</span><b>{{ $entero($antiguedad['excluidas']) }}</b></div>
                <div><span>Con fechas inconsistentes</span><b>{{ $entero($antiguedad['inconsistentes']) }}</b></div>
            </div>
        </div>
    </div>
    @if ((int) ($antiguedad['sin_desglose'] ?? 0) > 0)
        <p class="nota">{{ $entero($antiguedad['sin_desglose']) }} baja(s) del histórico agregado (Excel) no tienen antigüedad y no entran en este análisis.</p>
    @endif
</section>

{{-- ================= 8 · Sucursales ================= --}}
<section class="seccion">
    <h2><span class="num">8</span>Comparación de sucursales</h2>
    <p class="intro">Cada índice se calcula con las bajas y la plantilla de esa sucursal. Solo aparecen sucursales con bajas en el periodo.</p>

    @if ($sucursales === [])
        <p class="vacio">Sin bajas en el periodo consultado.</p>
    @else
        <table class="tabla-chica">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Sucursal</th>
                    <th class="num">Bajas</th>
                    <th class="num">Partic.</th>
                    <th class="num">Plantilla prom.</th>
                    <th class="num">Índice</th>
                    <th class="num">Vol.</th>
                    <th class="num">Invol.</th>
                    <th class="num">Sin clas.</th>
                    <th class="num">Sin desgl.</th>
                    <th class="num">Temprana</th>
                    <th>Causal principal</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sucursales as $i => $s)
                    <tr>
                        <td class="tenue">{{ $i + 1 }}</td>
                        <td>
                            <b>{{ $s['sucursal'] }}</b>@if (! empty($s['es_corporativo'])) <span class="pastilla sincl">Corporativo</span>@endif
                            @if (($s['empresa'] ?? null) !== null)<br><span class="tenue">{{ $s['empresa'] }}</span>@endif
                        </td>
                        <td class="num"><b>{{ $entero($s['bajas']) }}</b></td>
                        <td class="num">{{ $pct($s['participacion']) }}</td>
                        <td class="num">{{ $s['plantilla_promedio'] === null ? '—' : $decimal($s['plantilla_promedio']) }}</td>
                        <td class="num">{!! $s['indice'] === null ? '<span class="tenue">'.e($conDetalle ? 'No aplica' : 'Sin datos de plantilla').'</span>' : e($pct($s['indice'])) !!}</td>
                        <td class="num">{{ $entero($s['voluntarias']) }}</td>
                        <td class="num">{{ $entero($s['involuntarias']) }}</td>
                        <td class="num">{{ $entero($s['sin_clasificar']) }}</td>
                        <td class="num">{{ $entero($s['sin_desglose']) }}</td>
                        <td class="num">{{ $pct($s['temprana']) }}</td>
                        <td>{{ $s['causal_principal'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td></td><td>Total del alcance</td>
                    <td class="num">{{ $entero($resumen['total']) }}</td>
                    <td class="num">{{ (int) $resumen['total'] > 0 ? '100.0 %' : '—' }}</td>
                    <td class="num">{{ ($rotacion['plantilla_promedio'] ?? null) === null ? '—' : $decimal($rotacion['plantilla_promedio']) }}</td>
                    <td class="num">{{ ($rotacion['indice'] ?? null) === null ? '—' : $pct($rotacion['indice']) }}</td>
                    <td class="num">{{ $entero($resumen['voluntarias']['bajas']) }}</td>
                    <td class="num">{{ $entero($resumen['involuntarias']['bajas']) }}</td>
                    <td class="num">{{ $entero($resumen['sin_clasificar']['bajas']) }}</td>
                    <td class="num">{{ $entero($resumen['sin_clasificar']['sin_desglose']) }}</td>
                    <td class="num">{{ $pct($temprana['porcentaje']) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        <p class="nota">El índice global no es la suma de los índices de cada sucursal: se calcula con las bajas y plantillas del conjunto. «Temprana» = bajas antes de 6 meses ÷ bajas de la sucursal con fecha de ingreso válida.</p>
    @endif
</section>

{{-- ================= 9 · Matriz ================= --}}
<section class="seccion">
    <h2><span class="num">9</span>Matriz sucursales × causales</h2>
    <p class="intro">Solo bajas individuales ({{ $entero($matriz['total']) }}). El color se intensifica según el número de bajas de la celda respecto al máximo ({{ $entero($matriz['maximo']) }}).</p>

    @if ($matriz['filas'] === [] || $matriz['columnas'] === [])
        <p class="vacio">Sin bajas individuales en el periodo consultado.</p>
    @else
        <table class="matriz">
            <thead>
                <tr>
                    <th class="suc">Sucursal</th>
                    @foreach ($matriz['columnas'] as $col)
                        @if ($encabezadoVertical)
                            <th class="vertical" title="{{ $col['nombre'] }}"><span>{{ $col['nombre'] }}</span></th>
                        @else
                            <th>{{ $col['nombre'] }}</th>
                        @endif
                    @endforeach
                    <th class="tot">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($matriz['filas'] as $fila)
                    <tr>
                        <td class="suc">{{ $fila['sucursal'] }}</td>
                        @foreach ($matriz['columnas'] as $col)
                            @php $n = (int) ($fila['celdas'][$col['clave']] ?? 0); @endphp
                            <td style="{{ $celdaCalor($n) }}">{{ $n > 0 ? $entero($n) : '' }}</td>
                        @endforeach
                        <td class="tot">{{ $entero($fila['total']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td class="suc">Total</td>
                    @foreach ($matriz['columnas'] as $col)
                        <td>{{ $entero($col['total']) }}</td>
                    @endforeach
                    <td class="tot">{{ $entero($matriz['total']) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif
    @if ((int) ($matriz['sin_desglose'] ?? 0) > 0)
        <p class="nota">{{ $entero($matriz['sin_desglose']) }} baja(s) del histórico agregado (Excel) no tienen causal y no aparecen en la matriz.</p>
    @endif
</section>

{{-- ================= 10 · Hallazgos ================= --}}
<section class="seccion">
    <h2><span class="num">10</span>Hallazgos principales</h2>
    <p class="intro">Patrones calculados con las mismas cifras de este reporte para que RH los investigue; no son conclusiones sobre las causas.</p>

    @forelse ($hallazgos as $h)
        <div class="hallazgo {{ $h['tono'] ?? '' }}">
            <b>{{ $h['titulo'] }}</b>
            <span>{{ $h['detalle'] }}</span>
        </div>
    @empty
        <p class="vacio">Sin hallazgos para el periodo consultado.</p>
    @endforelse
</section>

{{-- ================= 11 · Relación individual ================= --}}
@if ($detalle !== null)
    <section class="seccion">
        <h2><span class="num">11</span>Relación individual de bajas</h2>
        <p class="intro">{{ $entero(count($detalle)) }} baja(s) individual(es) con los filtros aplicados. Las bajas del histórico agregado (Excel) no tienen persona y no aparecen aquí.</p>

        @if ($detalle === [])
            <p class="vacio">Sin bajas individuales con los filtros aplicados.</p>
        @else
            <table class="tabla-chica">
                <thead>
                    <tr>
                        <th>Fecha de baja</th>
                        <th>Nombre</th>
                        <th>Sucursal</th>
                        <th>Puesto</th>
                        <th>Ingreso</th>
                        <th class="num">Antig. (días)</th>
                        <th>Motivo original</th>
                        <th>Causal homologada</th>
                        <th>Clasificación</th>
                        <th>Origen</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($detalle as $fila)
                        <tr>
                            <td style="white-space: nowrap;">{{ $fecha($fila['fecha_baja'] ?? null) }}</td>
                            <td>
                                {{ $fila['nombre'] ?? '—' }}
                                @if (($fila['numero_empleado'] ?? null) !== null && $fila['numero_empleado'] !== '')<br><span class="tenue">No. {{ $fila['numero_empleado'] }}</span>@endif
                                @foreach ($fila['alertas'] ?? [] as $alerta)
                                    <br><span class="pastilla alerta-dato">{{ $alertas[$alerta] ?? $alerta }}</span>
                                @endforeach
                            </td>
                            <td>{{ ($fila['sucursal'] ?? '') !== '' ? $fila['sucursal'] : '—' }}</td>
                            <td>{{ $fila['puesto'] ?? '—' }}</td>
                            <td style="white-space: nowrap;">{{ $fecha($fila['fecha_ingreso'] ?? null) }}</td>
                            <td class="num">{{ $entero($fila['antiguedad_dias'] ?? null) }}</td>
                            <td>{{ ($fila['motivo_original'] ?? '') !== '' ? $fila['motivo_original'] : '—' }}</td>
                            <td>{{ $fila['causal'] ?? '—' }}</td>
                            <td><span class="pastilla {{ $claseClasif($fila['clasificacion'] ?? null) }}">{{ $fila['clasificacion_etiqueta'] ?? '—' }}</span></td>
                            <td>{{ $fila['fuente_etiqueta'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endif

{{-- ================= Calidad del dato ================= --}}
<section class="seccion">
    <h2><span class="num">{{ $numCalidad }}</span>Calidad del dato</h2>
    <p class="intro">Pendientes del periodo y alcance completos (sin filtros de detalle). Se informan para corregirlos; nunca se ocultan.</p>

    <div class="caja">
        <div class="lista-datos">
            <div><span>Bajas sin causal registrada</span><b>{{ $entero($calidad['sin_causal']) }}</b></div>
            <div><span>Bajas sin homologar (causal del catálogo sin categoría)</span><b>{{ $entero($calidad['sin_homologar']) }}</b></div>
            <div><span>Bajas sin fecha de ingreso</span><b>{{ $entero($calidad['sin_fecha_ingreso']) }}</b></div>
            <div><span>Bajas con fechas inconsistentes (baja antes del ingreso)</span><b>{{ $entero($calidad['fecha_inconsistente']) }}</b></div>
            <div><span>Posibles duplicados por revisar (histórico vs. sistema)</span><b>{{ $entero($calidad['posibles_duplicados']) }}</b></div>
            <div><span>Históricas ya ligadas como duplicadas (no se cuentan)</span><b>{{ $entero($calidad['duplicadas_ligadas']) }}</b></div>
            <div><span>Descuadres con el histórico agregado</span><b>{{ $entero(count($calidad['discrepancias'])) }}</b></div>
        </div>
    </div>

    @if (! empty($calidad['meses_recortados']))
        <p class="nota">
            Totales mensuales del Excel que no entraron porque el mes no cae completo en el rango:
            {{ implode(', ', array_map(fn ($m) => $etiquetaMes((string) $m), $calidad['meses_recortados'])) }}.
        </p>
    @endif

    <h3>Descuadres entre bajas individuales y el histórico agregado</h3>
    @if ($calidad['discrepancias'] === [])
        <p class="vacio">Sin descuadres en el periodo.</p>
    @else
        <table class="tabla-chica">
            <thead>
                <tr><th>Mes</th><th>Sucursal</th><th class="num">Total del Excel</th><th class="num">Bajas individuales</th><th class="num">Diferencia</th></tr>
            </thead>
            <tbody>
                @foreach ($calidad['discrepancias'] as $d)
                    <tr>
                        <td>{{ $etiquetaMes($d['mes'] ?? null) }}</td>
                        <td>{{ ($d['sucursal'] ?? '') !== '' ? $d['sucursal'] : sprintf('Sucursal #%d', $d['sucursal_id'] ?? 0) }}</td>
                        <td class="num">{{ $d['agregado'] === null ? 'Sin total' : $entero($d['agregado']) }}</td>
                        <td class="num">{{ $entero($d['individuales']) }}</td>
                        <td class="num">{{ $d['diferencia'] > 0 ? '+' : '' }}{{ $entero($d['diferencia']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="nota">Diferencia = total del Excel − bajas individuales. Una diferencia positiva se cuenta como «sin desglose».</p>
    @endif
</section>

</body>
</html>
