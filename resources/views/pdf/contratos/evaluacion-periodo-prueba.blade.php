{{--
    EVALUACIÓN DE TÉRMINO DE PERIODO DE PRUEBA · PERSONAL OPERATIVO
    (FO-RH-EPP-001, versión 02 · octubre 2026) — ya contestada, para firma.

    Fuente: docs/onboarding/Evaluacion_Periodo_de_Prueba_Operativo.pdf. Mismo
    orden que el formato: datos del colaborador, Paso 1 (califique el
    desempeño 4-3-2-1), Paso 2 (preguntas filtro), Paso 3 (resultado),
    comentarios del jefe inmediato y firmas EVALUÓ / VALIDÓ / ENTERADO.

    Todo sale de $d (EvaluacionPeriodoPruebaPdfService::datos); las
    respuestas son las GUARDADAS en la evaluación. Paleta única de
    config/pdf_identidad.php. Solo tablas y bloques: Chrome y DomPDF lo
    imprimen igual. Sin fuentes remotas ni glifos especiales (las casillas
    son cuadros con «X»).
--}}
@php
    $pal = config('pdf_identidad');
    $r = $d['r'];
    $c = $d['colaborador'];
    $num = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $niveles = [
        4 => ['Muy bueno', $pal['exito']],
        3 => ['Bueno', $pal['esmeralda']],
        2 => ['Regular', $pal['alerta']],
        1 => ['Deficiente', $pal['peligro']],
    ];
    $renueva = $r['recomienda_renovar'] === true;
    $noRenueva = $r['recomienda_renovar'] === false;
    $motivos = $r['motivos_no_renueva'];
    $minimo = $r['total_minimo_renovar'];
    $decision = $r['decision'];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Evaluación de término de periodo de prueba · {{ $c['nombre'] }}</title>
    <style>
        @page {
            @if ($motor === 'browsershot') size: Letter portrait; @endif
            margin: 12mm 14mm 16mm 14mm;
        }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: Arial, 'Helvetica Neue', Helvetica, 'DejaVu Sans', sans-serif; font-size: 9pt; line-height: 1.35; color: {{ $pal['texto'] }}; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        p { margin: 0 0 1.5mm; }
        table { width: 100%; border-collapse: collapse; }

        .encabezado td { vertical-align: top; }
        .encabezado .logo { height: 13mm; }
        .encabezado .marca-texto { font-size: 16pt; font-weight: bold; color: {{ $pal['petroleo'] }}; }
        .encabezado .folio { text-align: right; font-size: 8pt; color: {{ $pal['texto_suave'] }}; line-height: 1.4; }
        .encabezado .folio strong { color: {{ $pal['petroleo'] }}; font-size: 8.6pt; }

        .titulo { text-align: center; margin: 3mm 0 2mm; }
        .titulo h1 { margin: 0; font-size: 14pt; letter-spacing: 0.4pt; color: {{ $pal['petroleo'] }}; }
        .titulo .sub { color: {{ $pal['bronce'] }}; font-weight: bold; font-size: 10.5pt; margin-top: 0.5mm; }
        .proposito { font-size: 8.8pt; margin-bottom: 2.5mm; }
        .empresa { text-align: center; font-size: 8pt; color: {{ $pal['texto_suave'] }}; margin-bottom: 1mm; }

        .ficha td { border: 0.25mm solid {{ $pal['borde_claro'] }}; padding: 1.6mm 2.2mm; font-size: 8.8pt; vertical-align: middle; }
        .ficha .etq { width: 17%; background: {{ $pal['salvia'] }}; color: {{ $pal['petroleo'] }}; font-weight: bold; }
        .ficha .val { width: 33%; }
        .ficha .rh { color: {{ $pal['texto_tenue'] }}; font-style: italic; font-size: 7.6pt; float: right; }

        .paso { margin-top: 4mm; page-break-after: avoid; }
        .paso td { padding: 1.6mm 3mm; vertical-align: middle; }
        .paso .n { width: 22mm; background: {{ $pal['petroleo'] }}; color: {{ $pal['blanco'] }}; text-align: center; font-weight: bold; font-size: 10pt; }
        .paso .t { background: {{ $pal['fondo_suave'] }}; color: {{ $pal['petroleo'] }}; font-weight: bold; font-size: 10.5pt; }
        .indicacion { color: {{ $pal['texto_suave'] }}; font-size: 8.2pt; margin: 1.2mm 0 1.8mm; }
        .regla { color: {{ $pal['peligro_texto'] }}; font-weight: bold; }

        .criterios th { color: {{ $pal['blanco'] }}; font-size: 8.2pt; padding: 1.6mm 1mm; text-align: center; border: 0.25mm solid {{ $pal['blanco'] }}; }
        .criterios th.que { background: {{ $pal['petroleo_oscuro'] }}; text-align: left; padding-left: 2.4mm; }
        .criterios th .mini { display: block; font-size: 6.6pt; font-weight: normal; }
        .criterios td { border: 0.25mm solid {{ $pal['borde_claro'] }}; padding: 1.5mm 1.5mm; font-size: 8.6pt; vertical-align: middle; }
        .criterios tr { page-break-inside: avoid; }
        .criterios td.nombre { font-weight: bold; color: {{ $pal['petroleo'] }}; background: {{ $pal['fondo_suave'] }}; }
        .criterios td.celda { width: 15mm; text-align: center; }
        .criterios td.numero { width: 17mm; text-align: center; font-size: 12pt; font-weight: bold; color: {{ $pal['petroleo'] }}; }
        .criterios td.uno { color: {{ $pal['peligro_texto'] }}; }
        .criterios .total td { background: {{ $pal['petroleo'] }}; color: {{ $pal['blanco'] }}; font-weight: bold; text-align: right; padding: 2mm 3mm; }
        .criterios .total td.numero { background: {{ $pal['fondo_total'] }}; color: {{ $pal['petroleo'] }}; text-align: center; font-size: 12pt; }
        .comentario-criterio { display: block; font-weight: normal; font-size: 7.6pt; color: {{ $pal['texto_suave'] }}; margin-top: 0.6mm; }

        .marca { display: inline-block; width: 4.2mm; height: 4.2mm; line-height: 3.8mm; border: 0.3mm solid {{ $pal['texto_suave'] }}; text-align: center; font-weight: bold; font-size: 8pt; vertical-align: middle; }
        .marca.si { border-color: {{ $pal['petroleo'] }}; background: {{ $pal['petroleo'] }}; color: {{ $pal['blanco'] }}; }
        .marca.rojo { border-color: {{ $pal['peligro'] }}; background: {{ $pal['peligro'] }}; color: {{ $pal['blanco'] }}; }

        .filtros th { font-size: 8.2pt; padding: 1.6mm 2mm; color: {{ $pal['blanco'] }}; background: {{ $pal['petroleo_oscuro'] }}; }
        .filtros th.si { width: 22mm; background: {{ $pal['peligro'] }}; text-align: center; }
        .filtros th.no { width: 16mm; text-align: center; }
        .filtros th .mini { display: block; font-size: 6.6pt; font-weight: normal; }
        .filtros td { border: 0.25mm solid {{ $pal['borde_claro'] }}; padding: 1.6mm 2.2mm; font-size: 8.8pt; }
        .filtros td.si { background: {{ $pal['peligro_suave'] }}; text-align: center; }
        .filtros td.no { text-align: center; }

        .franjas td { padding: 2mm; text-align: center; font-weight: bold; font-size: 9pt; }
        .franjas .total { background: {{ $pal['fondo_suave'] }}; color: {{ $pal['petroleo'] }}; text-align: left; width: 34%; border: 0.25mm solid {{ $pal['borde_claro'] }}; }
        .franjas .total strong { font-size: 12pt; }
        .franjas .baja { background: {{ $pal['peligro_suave'] }}; color: {{ $pal['peligro_texto'] }}; }
        .franjas .alta { background: {{ $pal['exito_suave'] }}; color: {{ $pal['exito_texto'] }}; }
        .franjas .mini { display: block; font-size: 7pt; font-weight: normal; }

        .resultado { margin-top: 2.5mm; }
        .resultado td { width: 50%; vertical-align: top; padding: 2.5mm 3mm; font-size: 8.6pt; }
        .resultado .renueva { border: 0.5mm solid {{ $pal['exito'] }}; background: {{ $pal['exito_suave'] }}; }
        .resultado .no-renueva { border: 0.5mm solid {{ $pal['peligro'] }}; background: {{ $pal['peligro_suave'] }}; }
        .resultado .inactiva { opacity: 0.55; }
        .resultado h3 { margin: 0 0 1.5mm; font-size: 11pt; }
        .resultado .renueva h3 { color: {{ $pal['exito_texto'] }}; }
        .resultado .no-renueva h3 { color: {{ $pal['peligro_texto'] }}; }
        .resultado .linea { margin: 0.8mm 0; }
        .resultado .nota { font-style: italic; color: {{ $pal['texto_suave'] }}; font-size: 7.8pt; margin-top: 1.2mm; }

        .comentarios { margin-top: 3mm; page-break-inside: avoid; }
        .comentarios h2 { margin: 0; font-size: 10pt; color: {{ $pal['petroleo'] }}; }
        .comentarios .caja { border: 0.25mm solid {{ $pal['borde_claro'] }}; min-height: 16mm; padding: 2.2mm 3mm; white-space: pre-line; font-size: 9pt; }
        .comentarios .caja.vacia { color: {{ $pal['texto_tenue'] }}; font-style: italic; }

        .decision { margin-top: 2.5mm; padding: 2mm 3mm; background: {{ $pal['salvia'] }}; border-left: 0.8mm solid {{ $pal['petroleo'] }}; font-size: 8.6pt; page-break-inside: avoid; }
        .decision strong { color: {{ $pal['petroleo'] }}; }

        .firmas { margin-top: 13mm; page-break-inside: avoid; }
        .firmas td { width: 33.3%; padding: 0 4mm; vertical-align: bottom; text-align: center; }
        .firmas .rol { color: {{ $pal['esmeralda'] }}; font-weight: bold; font-size: 8.6pt; letter-spacing: 0.4pt; padding-bottom: 10mm; }
        .firmas .nombre-firma { font-size: 8.6pt; min-height: 4mm; }
        .firmas .linea { border-top: 0.35mm solid {{ $pal['petroleo'] }}; padding-top: 1.2mm; font-weight: bold; color: {{ $pal['petroleo'] }}; font-size: 8.8pt; }
        .firmas .det { font-size: 7.8pt; color: {{ $pal['texto_suave'] }}; }
        .leyenda { margin-top: 3mm; text-align: center; font-style: italic; font-size: 7.6pt; color: {{ $pal['texto_suave'] }}; }
    </style>
</head>
<body>
    <table class="encabezado">
        <tr>
            <td style="width: 50%">
                @if ($d['logo'])
                    <img class="logo" src="{{ $d['logo'] }}" alt="Mr. Lana">
                @else
                    <span class="marca-texto">Mr. Lana</span>
                @endif
            </td>
            <td class="folio">
                <strong>{{ $d['clave'] }} · Personal Operativo</strong><br>
                Versión 02 · Octubre 2026
            </td>
        </tr>
    </table>

    <div class="titulo">
        <h1>EVALUACIÓN DE TÉRMINO DE PERIODO DE PRUEBA</h1>
        <div class="sub">PERSONAL OPERATIVO</div>
    </div>
    <div class="empresa">{{ $d['empresa'] }}</div>
    <p class="proposito"><strong>¿Para qué sirve?</strong> Decidir con hechos si el colaborador pasa a contrato indeterminado o termina al vencer su contrato. Se entrega a RH <strong>15 días hábiles antes</strong> de la fecha de término.</p>

    <table class="ficha">
        <tr>
            <td class="etq">Nombre</td><td class="val">{{ $c['nombre'] }}@if ($c['numero_empleado']) <span style="color: {{ $pal['texto_suave'] }}">· #{{ $c['numero_empleado'] }}</span>@endif</td>
            <td class="etq">Puesto</td><td class="val">{{ $c['puesto'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="etq">Sucursal / Área</td><td class="val">{{ $c['sucursal'] ?? '' }}</td>
            <td class="etq">Jefe inmediato</td><td class="val">{{ $d['jefe'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="etq">Fecha de ingreso</td><td class="val">{{ $c['fecha_ingreso'] ?? '' }}</td>
            <td class="etq">Término de contrato</td><td class="val">{{ $d['termino_contrato'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="etq">Faltas sin justificar</td><td class="val"><span class="rh">(dato de RH)</span>&nbsp;</td>
            <td class="etq">Retardos</td><td class="val"><span class="rh">(dato de RH)</span>&nbsp;</td>
        </tr>
    </table>

    {{-- PASO 1 --}}
    <table class="paso"><tr><td class="n">PASO 1</td><td class="t">CALIFIQUE EL DESEMPEÑO</td></tr></table>
    @if ($r['es_oficial'])
        <p class="indicacion">El jefe inmediato eligió, por renglón, la descripción que más se parece a lo que vio (4, 3, 2 o 1). <span class="regla">Si hay un 1 en cualquier renglón, el resultado es NO SE RENUEVA.</span></p>
        <table class="criterios">
            <thead>
                <tr>
                    <th class="que">¿Qué se evalúa?</th>
                    @foreach ($niveles as $valor => [$etiqueta, $color])
                        <th style="background: {{ $color }}">{{ $valor }} {{ $etiqueta }}@if ($valor === 1)<span class="mini">= NO SE RENUEVA</span>@endif</th>
                    @endforeach
                    <th class="que" style="text-align: center; padding-left: 1mm">Número</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($r['criterios'] as $i => $criterio)
                    @php $valor = (int) ($criterio['calificacion'] ?? 0); @endphp
                    <tr>
                        <td class="nombre">{{ $i + 1 }}. {{ $criterio['etiqueta'] }}@if ($criterio['comentario'])<span class="comentario-criterio">{{ $criterio['comentario'] }}</span>@endif</td>
                        @foreach ($niveles as $nivel => $info)
                            <td class="celda">
                                @if ($valor === $nivel)
                                    <span class="marca {{ $nivel === 1 ? 'rojo' : 'si' }}">X</span>
                                @endif
                            </td>
                        @endforeach
                        <td class="numero {{ $valor === 1 ? 'uno' : '' }}">{{ $valor > 0 ? $valor : '—' }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td colspan="5">TOTAL <span style="font-weight: normal; font-size: 7.8pt">(suma de los {{ count($r['criterios']) }} números · máximo {{ $r['total_maximo'] }} puntos)</span></td>
                    <td class="numero">{{ $num($r['total']) }}</td>
                </tr>
            </tbody>
        </table>

        {{-- PASO 2 --}}
        <table class="paso"><tr><td class="n">PASO 2</td><td class="t">PREGUNTAS FILTRO</td></tr></table>
        <p class="indicacion">SÍ solo si el hecho ocurrió y está documentado (acta, reporte o queja por escrito). <span class="regla">Un solo SÍ significa NO SE RENUEVA.</span></p>
        <table class="filtros">
            <thead>
                <tr>
                    <th style="text-align: left">Durante el periodo de prueba, el colaborador…</th>
                    <th class="si">SÍ<span class="mini">= NO SE RENUEVA</span></th>
                    <th class="no">NO</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($r['filtros'] as $filtro)
                    <tr>
                        <td>{{ $filtro['etiqueta'] }}</td>
                        <td class="si"><span class="marca {{ $filtro['respuesta'] ? 'rojo' : '' }}">{{ $filtro['respuesta'] ? 'X' : '' }}</span></td>
                        <td class="no"><span class="marca {{ $filtro['respuesta'] ? '' : 'si' }}">{{ $filtro['respuesta'] ? '' : 'X' }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- PASO 3 --}}
        <div style="page-break-inside: avoid">
            <table class="paso"><tr><td class="n">PASO 3</td><td class="t">RESULTADO</td></tr></table>
            <table class="franjas" style="margin-top: 1.5mm">
                <tr>
                    <td class="total">Total del Paso 1: <strong>{{ $num($r['total']) }}</strong> puntos</td>
                    <td class="baja">{{ count($r['criterios']) }} a {{ $minimo !== null ? $num($minimo - 1) : '—' }} puntos<span class="mini">NO SE RENUEVA</span></td>
                    <td class="alta">{{ $minimo !== null ? $num($minimo) : '—' }} a {{ $r['total_maximo'] }} puntos<span class="mini">ALCANZA EL MÍNIMO</span></td>
                </tr>
            </table>
            <table class="resultado">
                <tr>
                    <td class="renueva {{ $renueva ? '' : 'inactiva' }}">
                        <h3><span class="marca {{ $renueva ? 'si' : '' }}">{{ $renueva ? 'X' : '' }}</span> SÍ SE RENUEVA</h3>
                        <div class="linea"><strong>Solo si se cumplen las 3:</strong></div>
                        <div class="linea">– Ningún 1 en el Paso 1</div>
                        <div class="linea">– Ningún SÍ en el Paso 2</div>
                        <div class="linea">– Total de {{ $minimo !== null ? $num($minimo) : '—' }} puntos o más</div>
                        <div class="nota">Pasa a contrato por tiempo indeterminado.</div>
                    </td>
                    <td class="no-renueva {{ $noRenueva ? '' : 'inactiva' }}">
                        <h3><span class="marca {{ $noRenueva ? 'rojo' : '' }}">{{ $noRenueva ? 'X' : '' }}</span> NO SE RENUEVA</h3>
                        <div class="linea"><strong>Motivo:</strong></div>
                        @php
                            $opciones = [
                                'Tiene al menos un 1 en el Paso 1',
                                'Tiene al menos un SÍ en el Paso 2',
                                sprintf('Su total es menor de %s puntos', $minimo !== null ? $num($minimo) : '—'),
                            ];
                        @endphp
                        @foreach ($opciones as $opcion)
                            @php $marcado = $noRenueva && in_array($opcion, $motivos, true); @endphp
                            <div class="linea"><span class="marca {{ $marcado ? 'rojo' : '' }}">{{ $marcado ? 'X' : '' }}</span> {{ $opcion }}</div>
                        @endforeach
                        <div class="nota">La relación laboral termina al vencer el contrato.</div>
                    </td>
                </tr>
            </table>
        </div>
    @else
        {{-- Evaluación capturada con el formato libre anterior (antes del FO-RH-EPP-001 v02): se imprime tal cual se guardó. --}}
        <p class="indicacion">Evaluación capturada con el formato anterior (criterios de 0 a 10). Se imprime tal como se guardó.</p>
        <table class="criterios">
            <thead>
                <tr>
                    <th class="que">Criterio</th>
                    <th class="que" style="text-align: center; width: 24mm">Calificación (0–10)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($r['criterios'] as $i => $criterio)
                    <tr>
                        <td class="nombre">{{ $i + 1 }}. {{ $criterio['etiqueta'] }}@if ($criterio['comentario'])<span class="comentario-criterio">{{ $criterio['comentario'] }}</span>@endif</td>
                        <td class="numero">{{ $num($criterio['calificacion']) }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td>CALIFICACIÓN</td>
                    <td class="numero">{{ $num($r['total']) }}</td>
                </tr>
            </tbody>
        </table>
        <p style="margin-top: 2mm"><strong>Recomendación del jefe:</strong> {{ $renueva ? 'Renovar' : ($noRenueva ? 'No renovar' : 'Sin recomendación') }}</p>
    @endif

    <div class="comentarios">
        <h2>COMENTARIOS DEL JEFE INMEDIATO</h2>
        <p class="indicacion" style="margin-top: 0.5mm">Obligatorios si calificó con 1 o 2, o si marcó SÍ en el Paso 2. Si se renueva, en qué debe mejorar.</p>
        @if (filled($r['observaciones']))
            <div class="caja">{{ $r['observaciones'] }}</div>
        @else
            <div class="caja vacia">Sin comentarios.</div>
        @endif
    </div>

    @if ($decision['renovar'] !== null)
        <div class="decision">
            <strong>Decisión de Recursos Humanos:</strong>
            {{ $decision['renovar'] ? 'Se renueva (contrato por tiempo indeterminado).' : 'No se renueva.' }}
            @if ($decision['autorizada_en'])
                Autorizada el {{ \Illuminate\Support\Carbon::parse($decision['autorizada_en'])->timezone('America/Mexico_City')->settings(['locale' => 'es'])->isoFormat('D [de] MMMM [de] YYYY') }}.
            @endif
            @if (filled($decision['comentario']))
                <br>{{ $decision['comentario'] }}
            @endif
        </div>
    @endif

    <table class="firmas">
        <tr>
            <td>
                <div class="rol">EVALUÓ</div>
                <div class="nombre-firma">{{ $d['jefe'] ?? '' }}</div>
                <div class="linea">Nombre y firma</div>
                <div class="det">Jefe inmediato</div>
            </td>
            <td>
                <div class="rol">VALIDÓ</div>
                <div class="nombre-firma">&nbsp;</div>
                <div class="linea">Nombre y firma</div>
                <div class="det">Gerente de Recursos Humanos</div>
            </td>
            <td>
                <div class="rol">ENTERADO</div>
                <div class="nombre-firma">{{ $c['nombre'] }}</div>
                <div class="linea">Nombre y firma</div>
                <div class="det">Colaborador evaluado</div>
            </td>
        </tr>
    </table>
    <p class="leyenda">La firma del colaborador confirma que conoció el resultado, no que esté de acuerdo. Toda excepción requiere autorización escrita de Dirección General.@if ($d['fecha_evaluacion']) Fecha de la evaluación: {{ $d['fecha_evaluacion'] }}.@endif</p>
</body>
</html>
