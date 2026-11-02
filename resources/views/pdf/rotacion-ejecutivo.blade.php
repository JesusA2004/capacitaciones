{{--
    Rotación de personal · Dashboard ejecutivo (carta horizontal, Browsershot).
    Recibe $datos = RotacionExportService::datos() y $detalle (no se usa aquí).
    La página 1 replica el Dashboard de la pantalla (Reportes → Rotación →
    Dashboard, components/rotacion/SeccionResumen.vue): encabezado, seis
    indicadores y cinco gráficas con los mismos colores de la paleta. Las
    páginas siguientes llevan la composición mensual, los rankings y los
    hallazgos. Todas las cifras salen de $datos: aquí solo se da formato.
    Los % son participación sobre el total de bajas (salvo «antes de 6
    meses», sobre las bajas con fecha de ingreso válida). Un dato que no
    existe se muestra «—» o «Sin datos», nunca 0. El pie con «Página X de Y»
    lo dibuja el motor (OpcionesPdf).
--}}
@php
    use Carbon\CarbonImmutable;

    $alcance = $datos['alcance'];
    $resumen = $datos['resumen'];
    $tendencia = $datos['tendencia'];
    $causales = $datos['causales'];
    $antiguedad = $datos['antiguedad'];
    $sucursales = $datos['sucursales'];
    $calidad = $datos['calidad'] ?? [];
    $hallazgos = $datos['hallazgos'] ?? [];
    $filtros = $alcance['filtros'] ?? [];

    $entero = fn ($v): string => $v === null ? '—' : number_format((int) $v);
    $decimal = fn ($v): string => $v === null ? '—' : number_format((float) $v, 1);
    $pct = fn ($v): string => $v === null ? '—' : number_format((float) $v, 1).' %';
    $fecha = fn (?string $v): string => $v === null || $v === '' ? '—' : CarbonImmutable::parse($v)->format('d/m/Y');
    $plural = fn (int $n, string $uno, string $varios): string => number_format($n).' '.($n === 1 ? $uno : $varios);
    $mesesCortos = [1 => 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    $mesesLargos = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    $etiquetaMes = function (?string $periodo) use ($mesesLargos): string {
        if ($periodo === null) {
            return '—';
        }
        [$a, $m] = array_map('intval', explode('-', $periodo));

        return mb_strtolower($mesesLargos[$m] ?? $periodo).' '.$a;
    };
    $ancho = fn (int|float $v, int|float $max): string => number_format($max > 0 ? min(100, $v * 100 / $max) : 0, 2, '.', '');
    $total = (int) $resumen['total'];
    $partTotal = fn (int $n): ?float => $total > 0 ? round($n * 100 / $total, 1) : null;

    // --- Encabezado (mismo texto que la pantalla) ---------------------------
    $desde = CarbonImmutable::parse($alcance['desde']);
    $hasta = CarbonImmutable::parse($alcance['hasta']);
    $anioTitulo = $desde->year === $hasta->year ? (string) $hasta->year : $desde->year.'–'.$hasta->year;
    $textoPeriodo = match (true) {
        $desde->format('Y-m') === $hasta->format('Y-m') => $mesesLargos[$hasta->month].' '.$hasta->year,
        $desde->year === $hasta->year => $mesesLargos[$desde->month].' – '.$mesesLargos[$hasta->month].' '.$hasta->year,
        default => $mesesLargos[$desde->month].' '.$desde->year.' – '.$mesesLargos[$hasta->month].' '.$hasta->year,
    };

    $empresasConteo = array_count_values(array_filter(array_map(fn (array $s): ?string => $s['empresa'] ?? null, $sucursales)));
    arsort($empresasConteo);
    $empresaPrincipal = array_key_first($empresasConteo);
    $empresaEncabezado = ($filtros['empresa_id'] ?? null) !== null ? ($alcance['empresa'] ?? 'Mr. Lana') : ($empresaPrincipal ?? 'Mr. Lana');

    $partesAlcance = [];
    if (($filtros['sucursal_id'] ?? null) !== null) {
        $partesAlcance[] = $alcance['sucursal'];
    } elseif (($filtros['empresa_id'] ?? null) !== null) {
        $partesAlcance[] = 'Todas las sucursales de '.$alcance['empresa'];
    } else {
        $partesAlcance[] = 'Todas las sucursales';
    }
    foreach (['area', 'puesto', 'clasificacion', 'causal', 'antiguedad'] as $k) {
        if (($alcance[$k] ?? null) !== null) {
            $partesAlcance[] = $alcance[$k];
        }
    }
    if (($filtros['fuente'] ?? 'todas') === 'sistema') {
        $partesAlcance[] = 'solo sistema';
    } elseif (($filtros['fuente'] ?? 'todas') === 'historico') {
        $partesAlcance[] = 'solo histórico';
    }

    $inicioSistema = (string) config('bajas.tablero.mes_inicio_sistema', '2026-10');
    $fuentes = [];
    foreach ($tendencia['meses'] as $m) {
        if ($m['sin_datos'] || (int) $m['total'] === 0) {
            continue;
        }
        if (in_array('individual', $m['fuentes'], true)) {
            $fuentes[$m['periodo'] >= $inicioSistema ? 'sistema' : 'histórico individual'] = true;
        }
        if (in_array('agregado', $m['fuentes'], true)) {
            $fuentes['totales del Excel'] = true;
        }
    }
    $textoFuente = implode(' + ', array_values(array_filter(['sistema', 'histórico individual', 'totales del Excel'], fn (string $x): bool => isset($fuentes[$x])))) ?: 'sin datos';

    // --- Indicadores --------------------------------------------------------
    $ordenadas = $causales['causales'];
    usort($ordenadas, fn (array $a, array $b): int => $b['bajas'] <=> $a['bajas']);
    $reales = array_values(array_filter($ordenadas, fn (array $c): bool => $c['id'] !== null && $c['clave'] !== 'sin_causal'));
    $causalPrincipal = null;
    if ($reales !== [] && (int) $reales[0]['bajas'] > 0) {
        $empatadas = array_values(array_filter($reales, fn (array $c): bool => $c['bajas'] === $reales[0]['bajas']));
        $corto = function (array $c, int $i): string {
            $base = trim(preg_split('/\s+o\s+|\s*[\/(]/u', $c['nombre'])[0]);

            return $i === 0 ? $base : mb_strtolower(mb_substr($base, 0, 1)).mb_substr($base, 1);
        };
        $nombres = array_map($corto, $empatadas, array_keys($empatadas));
        $causalPrincipal = [
            'bajas' => (int) $reales[0]['bajas'],
            'empate' => count($empatadas) > 1,
            'nombre' => count($empatadas) > 1
                ? implode(', ', array_slice($nombres, 0, -1)).' y '.end($nombres).' (empate)'
                : $reales[0]['nombre'],
            'porcentaje' => $partTotal((int) $reales[0]['bajas']),
        ];
    }
    $temprana = $resumen['temprana'];

    // --- Gráficas -----------------------------------------------------------
    $escala = function (float $max): array {
        $max = max(1, $max);
        $paso = collect([1, 2, 4, 5, 8, 10, 20, 25, 50, 100, 200, 250, 500, 1000])->first(fn (int $p): bool => $max / $p <= 5) ?? (int) ceil($max / 5);
        $tope = (int) (ceil($max / $paso) * $paso);

        return ['tope' => $tope, 'marcas' => range(0, $tope, $paso)];
    };

    $promedio = $resumen['promedio_mensual'];
    $escalaMes = $escala(max((float) ($promedio ?? 0), ...array_map(fn (array $m): int => (int) $m['total'], $tendencia['meses'] ?: [['total' => 0]])));

    $topSucursales = $sucursales;
    usort($topSucursales, fn (array $a, array $b): int => $b['bajas'] <=> $a['bajas']);
    $topSucursales = array_slice($topSucursales, 0, 12);
    $maxSucursal = max(1, ...array_map(fn (array $s): int => (int) $s['bajas'], $topSucursales ?: [['bajas' => 0]]));
    $colorSucursal = fn (array $s): string => ! empty($s['es_corporativo']) ? 'var(--esmeralda)'
        : (! empty($s['unidad_externa']) || ($s['empresa'] ?? null) !== null && $empresaPrincipal !== null && $s['empresa'] !== $empresaPrincipal ? 'var(--oro)' : 'var(--petroleo)');
    $hayCorporativo = collect($topSucursales)->contains(fn (array $s): bool => ! empty($s['es_corporativo']));
    $hayExternas = collect($topSucursales)->contains(fn (array $s): bool => $colorSucursal($s) === 'var(--oro)');

    // Un color por causal dentro de la familia de su tipo (igual que la pantalla).
    $familias = [
        'voluntaria' => ['#6e9b8f', '#315b59', '#9fb38f', 'rgb(61 111 113 / .75)', 'rgb(110 155 143 / .55)', 'rgb(49 91 89 / .55)', 'rgb(63 117 88 / .7)'],
        'involuntaria' => ['rgb(176 82 74 / .75)', '#c7a66b', '#a98252', 'rgb(138 106 54 / .7)', 'rgb(199 166 107 / .55)', 'rgb(169 130 82 / .55)'],
    ];
    $usados = ['voluntaria' => 0, 'involuntaria' => 0];
    $barrasCausal = [];
    foreach ($ordenadas as $c) {
        $clasif = $c['clasificacion'];
        $color = 'rgb(112 120 116 / .35)';
        if ($clasif === 'voluntaria' || $clasif === 'involuntaria') {
            $color = $familias[$clasif][$usados[$clasif]++ % count($familias[$clasif])];
        }
        $barrasCausal[] = ['nombre' => $c['nombre'], 'bajas' => (int) $c['bajas'], 'porcentaje' => $partTotal((int) $c['bajas']), 'color' => $color];
    }
    if ((int) $causales['sin_desglose'] > 0) {
        $barrasCausal[] = ['nombre' => 'Totales del Excel sin desglose', 'bajas' => (int) $causales['sin_desglose'], 'porcentaje' => $partTotal((int) $causales['sin_desglose']), 'color' => 'rgb(112 120 116 / .2)'];
    }
    $barrasCausal = array_slice($barrasCausal, 0, 13);
    $maxCausal = max(1, ...array_map(fn (array $c): int => $c['bajas'], $barrasCausal ?: [['bajas' => 0]]));

    $segmentos = [];
    $acumulado = 0.0;
    $sumaTipos = (int) $resumen['voluntarias']['bajas'] + (int) $resumen['involuntarias']['bajas'] + (int) $resumen['sin_clasificar']['bajas'];
    foreach ([['Voluntaria', 'voluntarias', 'var(--petroleo)'], ['Involuntaria', 'involuntarias', 'var(--oro)'], ['Sin clasificar', 'sin_clasificar', 'var(--sincl)']] as [$etq, $clave, $color]) {
        $n = (int) $resumen[$clave]['bajas'];
        $largo = $sumaTipos > 0 ? $n * 100 / $sumaTipos : 0;
        $segmentos[] = ['etiqueta' => $etq, 'bajas' => $n, 'color' => $color, 'largo' => $largo, 'inicio' => $acumulado];
        $acumulado += $largo;
    }

    $etiquetasRango = ['menos_3_meses' => '< 3 m', 'de_3_a_6_meses' => '3–6 m', 'de_6_a_12_meses' => '6–12 m', 'de_1_a_3_anios' => '1–3 a', 'mas_3_anios' => '> 3 a'];
    $rangos = array_values(array_filter($antiguedad['rangos'], fn (array $r): bool => $r['clave'] !== 'sin_fecha'));
    $sinFecha = (int) (collect($antiguedad['rangos'])->firstWhere('clave', 'sin_fecha')['bajas'] ?? 0);
    $escalaRango = $escala((float) max(0, ...array_map(fn (array $r): int => (int) $r['bajas'], $rangos ?: [['bajas' => 0]])));

    // --- Nota al pie (mismo texto que la pantalla) --------------------------
    $notas = ['Porcentajes = participación sobre el total de bajas del periodo ('.$entero($total).').'];
    if ((int) $temprana['base'] > 0) {
        $notas[] = '«Antes de 6 meses» se calcula sobre '.$plural((int) $temprana['base'], 'baja', 'bajas').' con fecha de ingreso válida.';
    }
    $notas[] = (int) ($calidad['duplicadas_ligadas'] ?? 0) > 0
        ? 'Se depuraron '.$plural((int) $calidad['duplicadas_ligadas'], 'duplicado', 'duplicados').' entre el histórico y el sistema.'
        : 'Se depuran los duplicados entre histórico y sistema.';
    $notas[] = 'Un reingreso que vuelve a causar baja cuenta como evento adicional.';
    if ($sinFecha > 0) {
        $notas[] = $plural($sinFecha, 'baja', 'bajas').' sin fecha de ingreso válida (fuera de la gráfica de antigüedad).';
    }
    if ((int) $causales['sin_desglose'] > 0) {
        $notas[] = $plural((int) $causales['sin_desglose'], 'baja', 'bajas').' de los totales del Excel no traen causal ni antigüedad.';
    }
    $corporativo = collect($sucursales)->first(fn (array $s): bool => ! empty($s['es_corporativo']));
    if ($corporativo !== null) {
        $notas[] = 'Corporativo concentra '.$plural((int) $corporativo['bajas'], 'baja', 'bajas').'.';
    }
    if ((int) ($calidad['posibles_duplicados'] ?? 0) > 0) {
        $notas[] = $plural((int) $calidad['posibles_duplicados'], 'posible duplicado', 'posibles duplicados').' por revisar en Histórico.';
    }

    // --- Páginas siguientes -------------------------------------------------
    $conDetalle = ($alcance['puesto'] ?? null) !== null || ($alcance['area'] ?? null) !== null
        || ($alcance['clasificacion'] ?? null) !== null || ($alcance['causal'] ?? null) !== null
        || ($alcance['antiguedad'] ?? null) !== null;
    $rotacion = $resumen['rotacion'] ?? [];
    $maxMes = max(1, ...array_map(fn (array $m): int => (int) $m['total'], $tendencia['meses'] ?: [['total' => 0]]));
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
    <title>Dashboard de Rotación de Personal {{ $anioTitulo }}</title>
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
            --rejilla: #efe8dc;
            --alerta: #f3e3b8;
            --alerta-texto: #754711;
            --peligro: #b0524a;
            --sincl: #cfcbc4;
        }

        @page { size: letter landscape; margin: 9mm 10mm 14mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Segoe UI', 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 9px; line-height: 1.35; color: var(--texto); -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        /* Encabezado del dashboard. */
        .banda { display: flex; justify-content: space-between; align-items: center; gap: 16px; background: var(--salvia); border-bottom: 3px solid var(--oro); border-radius: 10px; padding: 9px 16px; margin-bottom: 7px; }
        .banda h1 { margin: 0; font-family: Georgia, 'Times New Roman', serif; font-size: 19px; line-height: 1.15; color: var(--petroleo); }
        .banda .sub { margin: 2px 0 0; font-size: 10.5px; font-weight: 600; color: var(--esmeralda); }
        .banda .sub b { color: var(--bronce); }
        .banda .der { text-align: right; font-size: 8.5px; color: var(--texto-2); line-height: 1.5; flex: none; }
        .banda .der b { color: var(--texto); font-weight: 600; }

        .kpis { display: grid; grid-template-columns: repeat(6, 1fr); gap: 6px; margin-bottom: 7px; }
        .kpi { background: var(--superficie); border: 1px solid var(--borde); border-left: 4px solid var(--petroleo); border-radius: 7px; padding: 6px 9px; }
        .kpi .valor { font-family: Georgia, 'Times New Roman', serif; font-size: 20px; font-weight: 700; color: var(--petroleo); margin: 0; line-height: 1.1; }
        .kpi .etq { font-size: 8px; color: var(--texto-2); margin: 2px 0 0; line-height: 1.3; }

        .fila { display: grid; gap: 7px; margin-bottom: 7px; }
        .fila-1 { grid-template-columns: 1.15fr 1fr; }
        .fila-2 { grid-template-columns: 1.45fr .8fr 1fr; }
        .caja { border: 1px solid var(--borde); border-radius: 8px; padding: 7px 10px 8px; background: var(--superficie); break-inside: avoid; }
        .cab { display: flex; justify-content: space-between; align-items: flex-end; gap: 8px; border-bottom: 2px solid rgb(110 155 143 / .7); padding-bottom: 3px; margin-bottom: 5px; }
        .cab h2 { margin: 0; font-size: 11px; color: var(--petroleo); }
        .cab h2 small { font-weight: 400; color: var(--texto-2); font-size: 9px; }
        .ley { display: flex; gap: 8px; font-size: 7.5px; color: var(--texto-2); }
        .ley i { display: inline-block; width: 7px; height: 7px; border-radius: 2px; margin-right: 3px; vertical-align: -0.5px; }
        .ley .prom { color: var(--bronce); font-weight: 600; }
        .ley .prom i { width: 14px; height: 0; border-top: 2px dashed var(--bronce); border-radius: 0; vertical-align: 2px; }

        /* Barras verticales. */
        .vbarras { display: flex; gap: 4px; padding-top: 12px; }
        .vbarras .eje-y { position: relative; width: 16px; flex: none; }
        .vbarras .eje-y span { position: absolute; right: 0; transform: translateY(50%); font-size: 7px; color: var(--texto-2); line-height: 1; }
        .vbarras .area { position: relative; flex: 1; }
        .vbarras .rej { position: absolute; left: 0; right: 0; border-top: 1px solid var(--rejilla); }
        .vbarras .prom-linea { position: absolute; left: 0; right: 0; border-top: 2px dashed rgb(169 130 82 / .8); }
        .vbarras .cols { position: absolute; inset: 0; display: flex; align-items: flex-end; justify-content: space-around; gap: 5px; }
        .vbarras .col { flex: 1; max-width: 40px; height: 100%; display: flex; flex-direction: column; justify-content: flex-end; align-items: stretch; }
        .vbarras .bar { position: relative; border-radius: 3px 3px 0 0; min-height: 1px; }
        .vbarras .bar b { position: absolute; bottom: 100%; left: -6px; right: -6px; text-align: center; font-size: 8.5px; color: var(--texto); margin-bottom: 1px; }
        .vbarras .sd { height: 33%; border: 1px dashed #b5aea2; border-radius: 3px; font-size: 6.5px; color: var(--texto-2); display: flex; align-items: center; justify-content: center; text-align: center; }
        .eje-x { display: flex; gap: 4px; margin-top: 3px; }
        .eje-x .hueco { width: 16px; flex: none; }
        .eje-x .etqs { flex: 1; display: flex; justify-content: space-around; gap: 5px; }
        .eje-x .etqs span { flex: 1; max-width: 40px; text-align: center; font-size: 7.5px; color: var(--texto-2); white-space: nowrap; }

        /* Barras horizontales. */
        .hfila { display: flex; align-items: center; gap: 6px; height: 16.5px; }
        .hfila .n { flex: none; text-align: right; font-size: 7.5px; color: var(--texto); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .hfila .n.may { text-transform: uppercase; letter-spacing: .3px; }
        .hfila .pista { flex: 1; display: flex; align-items: center; gap: 5px; min-width: 0; padding-right: 66px; }
        .hfila .b { height: 9px; border-radius: 3px; flex: none; }
        .hfila .v { font-size: 7.5px; white-space: nowrap; color: var(--texto-2); }
        .hfila .v b { color: var(--texto); }

        /* Dona. */
        .dona { display: flex; flex-direction: column; align-items: center; justify-content: center; height: calc(100% - 22px); gap: 6px; }
        .dona .anillo { position: relative; width: 150px; height: 150px; }
        .dona svg { width: 100%; height: 100%; transform: rotate(-90deg); }
        .dona .centro { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .dona .centro b { font-family: Georgia, 'Times New Roman', serif; font-size: 18px; color: var(--petroleo); line-height: 1; }
        .dona .centro span { font-size: 7.5px; color: var(--texto-2); margin-top: 2px; }
        .dona .ley { flex-wrap: wrap; justify-content: center; font-size: 8px; color: var(--texto); }
        .dona .ley b { font-weight: 700; }

        .pie-nota { font-size: 7.5px; color: var(--texto-2); margin: 0; line-height: 1.45; }

        /* Páginas siguientes. */
        .salto { break-before: page; }
        h3 { font-size: 11px; color: var(--petroleo); margin: 0 0 6px; }
        h3 small { font-weight: 400; color: var(--texto-2); font-size: 8.5px; }
        .grafica { display: flex; align-items: flex-end; gap: 4px; height: 170px; border-bottom: 1px solid var(--borde); padding-top: 14px; }
        .grafica .c { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; min-width: 0; }
        .grafica .cv { font-size: 8px; font-weight: 700; color: var(--petroleo); margin-bottom: 1px; }
        .pila { width: 70%; max-width: 38px; display: flex; flex-direction: column-reverse; border-radius: 3px 3px 0 0; overflow: hidden; }
        .pila div { display: flex; align-items: center; justify-content: center; font-size: 7px; color: #fff; font-weight: 600; overflow: hidden; }
        .pila .vol { background: var(--petroleo); }
        .pila .invol { background: var(--oro); }
        .pila .sincl { background: var(--sincl); color: var(--texto); }
        .ejes { display: flex; gap: 4px; margin-top: 2px; }
        .ejes span { flex: 1; text-align: center; font-size: 7.5px; color: var(--texto-2); }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        th { background: var(--salvia); color: var(--petroleo); font-size: 7.5px; text-align: left; padding: 4px 5px; }
        td { padding: 3px 5px; border-bottom: 1px solid var(--borde); font-size: 8px; vertical-align: middle; }
        td.num, th.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        td.tenue { color: var(--texto-2); font-size: 7px; }
        tfoot td { font-weight: 700; background: var(--menta); }
        .pastilla { display: inline-block; border-radius: 999px; padding: 0 6px; font-size: 7px; font-weight: 700; white-space: nowrap; }
        .pastilla.vol { background: var(--salvia); color: var(--petroleo); }
        .pastilla.invol { background: var(--alerta); color: var(--alerta-texto); }
        .pastilla.sincl { background: #ece8e1; color: var(--texto-2); }
        .rejilla-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 8px; }
        .notas { margin: 0; padding-left: 14px; font-size: 7.5px; color: var(--texto-2); }
        .notas li { margin-bottom: 1px; }
        .hallazgo { border-left: 3px solid var(--esmeralda); padding: 2px 0 2px 7px; margin-bottom: 5px; }
        .hallazgo.alerta { border-left-color: var(--oro); }
        .hallazgo.peligro { border-left-color: var(--peligro); }
        .hallazgo.neutro { border-left-color: #b5aea2; }
        .hallazgo b { display: block; font-size: 8.5px; color: var(--petroleo); }
        .hallazgo span { font-size: 8px; color: var(--texto-2); }
        .vacio { color: var(--texto-2); font-style: italic; font-size: 8px; margin: 2px 0; }
    </style>
</head>
<body>

{{-- ===================== Página 1: Dashboard ===================== --}}
<div class="banda">
    <div>
        <h1>Dashboard de Rotación de Personal {{ $anioTitulo }}</h1>
        <p class="sub"><b>{{ $textoPeriodo }}</b> · {{ implode(' · ', $partesAlcance) }}</p>
    </div>
    <div class="der">
        <b>{{ $empresaEncabezado }} · Gerencia de Recursos Humanos</b><br>
        Corte: {{ $fecha($alcance['corte'] ?? null) }} · Fuente: {{ $textoFuente }}
        @if (! empty($alcance['corte_parcial']))
            <br><span style="color: var(--alerta-texto);">Corte parcial: el último mes aún no termina</span>
        @endif
    </div>
</div>

@if ($total === 0)
    <p class="vacio">No hay bajas de rotación con estos filtros.</p>
@else
    <div class="kpis">
        <div class="kpi">
            <p class="valor">{{ $entero($total) }}</p>
            <p class="etq">Bajas totales {{ $anioTitulo }}</p>
        </div>
        <div class="kpi" style="border-left-color: var(--esmeralda);">
            <p class="valor">{{ $decimal($resumen['promedio_mensual']) }}</p>
            <p class="etq">Promedio mensual</p>
        </div>
        <div class="kpi">
            <p class="valor">{{ $entero($resumen['voluntarias']['bajas']) }}</p>
            <p class="etq">Voluntarias · {{ $pct($resumen['voluntarias']['porcentaje']) }}</p>
        </div>
        <div class="kpi" style="border-left-color: var(--oro);">
            <p class="valor">{{ $entero($resumen['involuntarias']['bajas']) }}</p>
            <p class="etq">Involuntarias · {{ $pct($resumen['involuntarias']['porcentaje']) }}</p>
        </div>
        <div class="kpi" style="border-left-color: var(--esmeralda);">
            <p class="valor">{{ $causalPrincipal ? $entero($causalPrincipal['bajas']) : '—' }}</p>
            <p class="etq">
                @if ($causalPrincipal)
                    {{ $causalPrincipal['nombre'] }} · {{ $pct($causalPrincipal['porcentaje']) }}{{ $causalPrincipal['empate'] ? ' c/u' : '' }}
                @else
                    Causal principal: sin causales registradas
                @endif
            </p>
        </div>
        <div class="kpi" style="border-left-color: var(--peligro);">
            <p class="valor">{{ $pct($temprana['porcentaje']) }}</p>
            <p class="etq">Bajas antes de 6 meses</p>
        </div>
    </div>

    <div class="fila fila-1">
        <div class="caja">
            <div class="cab">
                <h2>Bajas por mes</h2>
                @if ($promedio !== null)
                    <span class="ley"><span class="prom"><i></i>Promedio {{ $decimal($promedio) }}</span></span>
                @endif
            </div>
            <div class="vbarras" style="height: 225px;">
                <div class="eje-y">
                    @foreach ($escalaMes['marcas'] as $m)
                        <span style="bottom: {{ $ancho($m, $escalaMes['tope']) }}%;">{{ $m }}</span>
                    @endforeach
                </div>
                <div class="area">
                    @foreach ($escalaMes['marcas'] as $m)
                        <div class="rej" style="bottom: {{ $ancho($m, $escalaMes['tope']) }}%;"></div>
                    @endforeach
                    <div class="cols">
                        @foreach ($tendencia['meses'] as $m)
                            <div class="col">
                                @if ($m['sin_datos'])
                                    <div class="sd">Sin datos</div>
                                @else
                                    <div class="bar" style="height: {{ $ancho($m['total'], $escalaMes['tope']) }}%; background: {{ $m['periodo'] === $tendencia['mes_mayor'] && $m['total'] > 0 ? 'var(--esmeralda)' : 'var(--petroleo)' }};"><b>{{ $entero($m['total']) }}</b></div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if ($promedio !== null && $promedio > 0)
                        <div class="prom-linea" style="bottom: {{ $ancho($promedio, $escalaMes['tope']) }}%;"></div>
                    @endif
                </div>
            </div>
            <div class="eje-x">
                <span class="hueco"></span>
                <div class="etqs">
                    @foreach ($tendencia['meses'] as $m)
                        <span>{{ $mesesCortos[$m['mes']] ?? $m['mes'] }}</span>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="caja">
            <div class="cab">
                <h2>Bajas por sucursal / área <small>(top 12)</small></h2>
                @if ($hayCorporativo || $hayExternas)
                    <span class="ley">
                        @if ($hayCorporativo)<span><i style="background: var(--esmeralda);"></i>Corporativo</span>@endif
                        @if ($hayExternas)<span><i style="background: var(--oro);"></i>Fuera de {{ $empresaPrincipal ?? 'la empresa' }}</span>@endif
                    </span>
                @endif
            </div>
            @foreach ($topSucursales as $s)
                <div class="hfila">
                    <span class="n may" style="width: 110px;">{{ $s['sucursal'] }}</span>
                    <span class="pista">
                        <span class="b" style="width: {{ $ancho($s['bajas'], $maxSucursal) }}%; min-width: 3px; background: {{ $colorSucursal($s) }};"></span>
                        <span class="v"><b>{{ $entero($s['bajas']) }}</b> · {{ $pct($s['participacion']) }}</span>
                    </span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="fila fila-2">
        <div class="caja">
            <div class="cab">
                <h2>Causales de baja</h2>
                <span class="ley">
                    <span><i style="background: var(--esmeralda);"></i>Voluntaria</span>
                    <span><i style="background: var(--oro);"></i>Involuntaria</span>
                </span>
            </div>
            @forelse ($barrasCausal as $c)
                <div class="hfila">
                    <span class="n" style="width: 170px;">{{ $c['nombre'] }}</span>
                    <span class="pista">
                        <span class="b" style="width: {{ $ancho($c['bajas'], $maxCausal) }}%; min-width: 3px; background: {{ $c['color'] }};"></span>
                        <span class="v"><b>{{ $entero($c['bajas']) }}</b> · {{ $pct($c['porcentaje']) }}</span>
                    </span>
                </div>
            @empty
                <p class="vacio">Sin causales registradas en el periodo.</p>
            @endforelse
        </div>

        <div class="caja">
            <div class="cab"><h2>Tipo de baja</h2></div>
            <div class="dona">
                <div class="anillo">
                    <svg viewBox="0 0 120 120" aria-hidden="true">
                        <circle cx="60" cy="60" r="46" fill="none" stroke="#f5efe4" stroke-width="17"/>
                        @foreach ($segmentos as $sg)
                            @if ($sg['largo'] > 0)
                                @php $l = max(0, $sg['largo'] - 0.6); @endphp
                                <circle cx="60" cy="60" r="46" fill="none" stroke="{{ $sg['color'] }}" stroke-width="17" pathLength="100"
                                        stroke-dasharray="{{ number_format($l, 2, '.', '') }} {{ number_format(100 - $l, 2, '.', '') }}"
                                        stroke-dashoffset="{{ number_format(-$sg['inicio'], 2, '.', '') }}"/>
                            @endif
                        @endforeach
                    </svg>
                    <div class="centro"><b>{{ $pct($resumen['voluntarias']['porcentaje']) }}</b><span>voluntarias</span></div>
                </div>
                <div class="ley">
                    @foreach ($segmentos as $sg)
                        <span><i style="background: {{ $sg['color'] }};"></i>{{ $sg['etiqueta'] }} <b>{{ $entero($sg['bajas']) }}</b></span>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="caja">
            <div class="cab"><h2>Antigüedad al causar baja</h2></div>
            <div class="vbarras" style="height: 212px;">
                <div class="eje-y">
                    @foreach ($escalaRango['marcas'] as $m)
                        <span style="bottom: {{ $ancho($m, $escalaRango['tope']) }}%;">{{ $m }}</span>
                    @endforeach
                </div>
                <div class="area">
                    @foreach ($escalaRango['marcas'] as $m)
                        <div class="rej" style="bottom: {{ $ancho($m, $escalaRango['tope']) }}%;"></div>
                    @endforeach
                    <div class="cols">
                        @foreach ($rangos as $r)
                            <div class="col">
                                <div class="bar" style="height: {{ $ancho($r['bajas'], $escalaRango['tope']) }}%; background: {{ $r['clave'] === 'menos_3_meses' ? 'rgb(176 82 74 / .8)' : 'var(--petroleo)' }};"><b>{{ $entero($r['bajas']) }}</b></div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="eje-x">
                <span class="hueco"></span>
                <div class="etqs">
                    @foreach ($rangos as $r)
                        <span>{{ $etiquetasRango[$r['clave']] ?? $r['etiqueta'] }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <p class="pie-nota">{{ implode(' ', $notas) }}</p>
@endif

{{-- ============== Página 2: composición mensual y rankings ============== --}}
<div class="salto">
    <div class="caja">
        <h3>Composición mensual por tipo de baja</h3>
        @if ($tendencia['meses'] === [])
            <p class="vacio">Sin meses en el periodo consultado.</p>
        @else
            <div class="grafica">
                @foreach ($tendencia['meses'] as $m)
                    <div class="c">
                        @if ($m['sin_datos'])
                            <div class="sd" style="width: 70%; max-width: 38px; height: 33%; border: 1px dashed #b5aea2; border-radius: 3px; font-size: 6.5px; color: var(--texto-2); display: flex; align-items: center; justify-content: center;">Sin datos</div>
                        @else
                            <div class="cv">{{ $entero($m['total']) }}</div>
                            <div class="pila" style="height: {{ $ancho($m['total'], $maxMes) }}%;">
                                @foreach ([['voluntarias', 'vol'], ['involuntarias', 'invol'], ['sin_clasificar', 'sincl']] as [$k, $cl])
                                    @if ($m[$k] > 0)<div class="{{ $cl }}" style="height: {{ $ancho($m[$k], max(1, $m['total'])) }}%;">{{ $ancho($m[$k], $maxMes) >= 8 ? $m[$k] : '' }}</div>@endif
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="ejes">
                @foreach ($tendencia['meses'] as $m)
                    <span>{{ $mesesCortos[$m['mes']] ?? $m['mes'] }}{{ $m['corte_parcial'] ? ' (parcial)' : '' }}</span>
                @endforeach
            </div>
            <div class="ley" style="margin-top: 5px;">
                <span><i style="background: var(--petroleo);"></i>Voluntaria</span>
                <span><i style="background: var(--oro);"></i>Involuntaria</span>
                <span><i style="background: var(--sincl);"></i>Sin clasificar</span>
                <span><i style="border: 1px dashed #b5aea2;"></i>Sin datos (no es cero)</span>
                @if ($tendencia['mes_mayor'] !== null)
                    <span>Mes con más bajas: <b>{{ $etiquetaMes($tendencia['mes_mayor']) }}</b></span>
                @endif
            </div>
        @endif
    </div>

    <div class="rejilla-2">
        <div class="caja">
            <h3>Bajas por sucursal / área <small>· {{ count($sucursales) }} con bajas</small></h3>
            @if ($sucursales === [])
                <p class="vacio">Sin bajas en el periodo consultado.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th style="width: 18px;">#</th>
                            <th>Sucursal / área</th>
                            <th class="num">Bajas</th>
                            <th class="num">% del total</th>
                            <th class="num">Vol. / Invol. / Sin clasif.</th>
                            <th class="num">Índice s/plantilla</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sucursales as $i => $s)
                            <tr>
                                <td class="tenue">{{ $i + 1 }}</td>
                                <td>{{ $s['sucursal'] }}</td>
                                <td class="num"><b>{{ $entero($s['bajas']) }}</b></td>
                                <td class="num">{{ $pct($s['participacion']) }}</td>
                                <td class="num">{{ $entero($s['voluntarias']) }} / {{ $entero($s['involuntarias']) }} / {{ $entero($s['sin_clasificar']) }}</td>
                                <td class="num tenue">{{ $s['indice'] === null ? ($conDetalle ? 'No aplica' : 'Sin plantilla') : $pct($s['indice']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr><td></td><td>Total</td><td class="num">{{ $entero($total) }}</td><td class="num">100 %</td><td></td><td></td></tr>
                    </tfoot>
                </table>
            @endif
        </div>

        <div class="caja">
            <h3>Causales de baja <small>· % sobre el total de bajas</small></h3>
            @if ($ordenadas === [])
                <p class="vacio">Sin bajas individuales con causal en el periodo.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Causal</th>
                            <th>Tipo</th>
                            <th class="num">Bajas</th>
                            <th class="num">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ordenadas as $c)
                            <tr>
                                <td>{{ $c['nombre'] }}</td>
                                <td><span class="pastilla {{ $claseClasif($c['clasificacion']) }}">{{ $c['clasificacion_etiqueta'] }}</span></td>
                                <td class="num"><b>{{ $entero($c['bajas']) }}</b></td>
                                <td class="num">{{ $pct($partTotal((int) $c['bajas'])) }}</td>
                            </tr>
                        @endforeach
                        @if ((int) $causales['sin_desglose'] > 0)
                            <tr>
                                <td class="tenue">Totales del Excel sin desglose</td>
                                <td><span class="pastilla sincl">Sin clasificar</span></td>
                                <td class="num"><b>{{ $entero($causales['sin_desglose']) }}</b></td>
                                <td class="num">{{ $pct($partTotal((int) $causales['sin_desglose'])) }}</td>
                            </tr>
                        @endif
                    </tbody>
                    <tfoot>
                        <tr><td>Total</td><td></td><td class="num">{{ $entero($total) }}</td><td class="num">100 %</td></tr>
                    </tfoot>
                </table>
            @endif
        </div>
    </div>

    <div class="rejilla-2">
        <div class="caja">
            <h3>Hallazgos <small>· patrones para investigar, no conclusiones</small></h3>
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
            <h3>Notas de metodología</h3>
            <ul class="notas">
                <li><b>Participación</b> = bajas del grupo ÷ total de bajas del periodo × 100. Es la base de todos los % del dashboard.</li>
                <li><b>Bajas antes de 6 meses</b> = bajas con menos de 6 meses de antigüedad ÷ bajas con fecha de ingreso válida; las demás se excluyen del denominador y se informan.</li>
                <li><b>Índice de rotación sobre plantilla</b>
                    @if ($conDetalle)
                        no aplica con filtros de detalle (no hay plantilla por causal, puesto o antigüedad).
                    @elseif (($rotacion['indice'] ?? null) === null)
                        sin datos de plantilla para el periodo (no se calcula como 0 %).
                    @else
                        del periodo: <b>{{ $pct($rotacion['indice']) }}</b> (plantilla promedio {{ $decimal($rotacion['plantilla_promedio'] ?? null) }}).
                    @endif
                    = bajas ÷ promedio de las plantillas promedio mensuales × 100; requiere plantilla.</li>
                <li><b>Total</b> = voluntarias + involuntarias + sin clasificar. Las bajas de los totales del Excel sin desglose cuentan como «sin clasificar» y no tienen causal ni antigüedad.</li>
                <li>Un mes anterior al sistema sin ninguna fuente es «Sin datos», no cero. La clasificación de las causales es estadística, no una conclusión jurídica.</li>
                <li>Generado: {{ $alcance['generado'] ?? '—' }} · Fuente: {{ $alcance['fuente'] ?? '—' }}.</li>
            </ul>
        </div>
    </div>
</div>

</body>
</html>
