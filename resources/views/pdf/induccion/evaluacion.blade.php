{{--
    EVALUACIÓN DEL PROGRAMA DE INDUCCIÓN (FO-RH-EI) — revisión de un intento.

    Fuente: docs/onboarding/Evaluacion_Induccion_Mr_Lana 2026.pdf. Mismo
    orden y numeración que el formato (A 1–12, B 13–20, C 21–25, D 26–27,
    E opinión, resultado y firmas), con lo que contestó el colaborador, si
    acertó, los puntos por sección, la rúbrica de los casos prácticos y el
    comentario de RH. Lleva la clave de respuestas: documento SOLO de RH.

    Todo sale de $d (EvaluacionInduccionPdfService::datos). Paleta de los
    PDF de actas (pdf/actas/*) y estados de pdf/layout. Solo tablas y
    bloques: Chrome y DomPDF lo imprimen igual. Sin fuentes remotas.
--}}
@php
    // Identidad institucional PEOPLE: paleta única en config/pdf_identidad.php.
    $pal = config('pdf_identidad');
    $r = $d['r'];
    $letra = fn (int $i) => chr(97 + $i);
    $puntos = fn (?float $p) => $p === null ? '—' : rtrim(rtrim(number_format($p, 2), '0'), '.');
    $vf = fn (?bool $v) => $v === null ? '—' : ($v ? 'V' : 'F');
    $opinion = (array) ($r['opinion'] ?? []);
    $tema = trim((string) ($opinion['tema_reforzar'] ?? ''));
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Evaluación del Programa de Inducción · {{ $d['colaborador']['nombre'] }}</title>
    <style>
        @page {
            @if ($motor === 'browsershot') size: A4 portrait; @endif
            margin: 15mm 15mm 20mm 15mm;
        }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: Arial, 'Helvetica Neue', Helvetica, 'DejaVu Sans', sans-serif; font-size: 9.6pt; line-height: 1.45; color: #1f2933; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        p { margin: 0 0 2mm; }
        table { width: 100%; border-collapse: collapse; }

        /* Banda institucional (como el formato oficial) */
        .banda { background: {{ $pal['petroleo'] }}; }
        .banda td { padding: 3mm 4mm; vertical-align: middle; }
        .banda .logo { height: 11mm; }
        .banda .marca-texto { color: #ffffff; font-size: 14pt; font-weight: bold; letter-spacing: 0.6pt; }
        .banda .empresa { text-align: right; color: #ffffff; font-size: 8pt; line-height: 1.4; }
        .banda .empresa strong { color: {{ $pal['dorado'] }}; font-size: 8.4pt; }

        .titulo { text-align: center; margin: 6mm 0 1mm; }
        .titulo h1 { margin: 0; font-size: 15pt; letter-spacing: 0.8pt; color: {{ $pal['petroleo'] }}; }
        .titulo .sub { color: {{ $pal['alerta_texto'] }}; font-style: italic; font-size: 9.6pt; margin-top: 1mm; }
        .titulo .clave { margin-top: 1.5mm; font-size: 8.4pt; color: #52606d; }
        .titulo .clave strong { color: #1f2933; }
        .filete { height: 0.5mm; background: {{ $pal['dorado'] }}; margin: 3mm 0 4mm; }

        /* Datos del colaborador */
        .ficha td { border: 0.25mm solid #d8dee4; padding: 1.8mm 2.4mm; font-size: 9.2pt; vertical-align: top; }
        .ficha .etq { width: 19%; background: {{ $pal['salvia'] }}; color: {{ $pal['petroleo'] }}; font-weight: bold; }
        .ficha .val { width: 31%; }

        /* Encabezado de sección (A, B, C, D, E) */
        .seccion { margin-top: 6mm; }
        .cabeza { margin-bottom: 1.5mm; page-break-after: avoid; }
        .cabeza td { padding: 2mm 3mm; vertical-align: middle; }
        .cabeza .letra { width: 11mm; background: {{ $pal['dorado'] }}; color: #ffffff; text-align: center; font-weight: bold; font-size: 12pt; }
        .cabeza .nombre { background: {{ $pal['petroleo'] }}; color: #ffffff; font-weight: bold; font-size: 10.6pt; }
        .cabeza .valor { width: 34mm; background: {{ $pal['salvia'] }}; color: {{ $pal['petroleo'] }}; text-align: center; font-weight: bold; }
        .indicacion { color: #52606d; font-style: italic; font-size: 8.8pt; margin: 0 0 3mm; }

        /* Preguntas de opción múltiple */
        .pregunta { margin: 0 0 3.5mm; page-break-inside: avoid; }
        .pregunta .enunciado { font-weight: bold; margin-bottom: 1mm; }
        .pregunta .enunciado .num { color: {{ $pal['petroleo'] }}; }
        .opciones td { padding: 0.6mm 2mm; font-size: 9.2pt; vertical-align: top; }
        .opciones .casilla { width: 9mm; text-align: center; white-space: nowrap; color: #52606d; }
        .opciones .elegida { font-weight: bold; }
        .opciones .correcta-fila td { background: {{ $pal['exito_suave'] }}; }
        .opciones .error-fila td { background: {{ $pal['peligro_suave'] }}; }
        .opciones .nota-op { width: 30mm; text-align: right; font-size: 8pt; color: #52606d; }

        .badge { display: inline-block; padding: 0.3mm 2mm; border-radius: 1mm; font-size: 7.8pt; font-weight: bold; white-space: nowrap; }
        .ok { background: {{ $pal['exito_suave'] }}; color: {{ $pal['exito_texto'] }}; }
        .mal { background: {{ $pal['peligro_suave'] }}; color: {{ $pal['peligro_texto'] }}; }
        .pend { background: {{ $pal['alerta_suave'] }}; color: {{ $pal['alerta_texto'] }}; }

        /* Tablas de sección */
        .tabla th { background: {{ $pal['petroleo'] }}; color: #ffffff; font-size: 8.6pt; padding: 1.8mm 2mm; text-align: left; }
        .tabla td { border: 0.25mm solid #d8dee4; padding: 1.6mm 2mm; font-size: 9pt; vertical-align: middle; }
        .tabla tr { page-break-inside: avoid; }
        .tabla .num { width: 9mm; text-align: center; font-weight: bold; color: {{ $pal['petroleo'] }}; }
        .tabla .centro { text-align: center; white-space: nowrap; }
        .tabla tr.par td { background: #f9fafb; }

        .descripciones td { padding: 1.2mm 2mm; font-size: 8.8pt; border-bottom: 0.2mm solid #e4e9eb; vertical-align: top; }
        .descripciones .let { width: 7mm; font-weight: bold; color: {{ $pal['dorado'] }}; }

        /* Casos prácticos */
        .caso { margin: 0 0 5mm; page-break-inside: avoid; }
        .caso .enunciado { margin-bottom: 1.5mm; text-align: justify; }
        .caso .enunciado .num { color: {{ $pal['petroleo'] }}; font-weight: bold; }
        .caso .enunciado .pts { color: {{ $pal['dorado'] }}; font-weight: bold; }
        .respuesta { border-left: 0.8mm solid {{ $pal['dorado'] }}; background: #f9fafb; padding: 2.2mm 3mm; margin-bottom: 2mm; white-space: pre-line; }
        .respuesta.vacia { color: #6b7280; font-style: italic; }
        .rubrica td { padding: 1mm 2mm; font-size: 8.8pt; vertical-align: top; border-bottom: 0.2mm solid #e4e9eb; }
        .rubrica .pt { width: 12mm; color: {{ $pal['alerta_texto'] }}; font-weight: bold; white-space: nowrap; }
        .rubrica .estado { width: 24mm; text-align: right; }
        .subtotal { text-align: right; font-size: 9pt; margin-top: 1.2mm; color: {{ $pal['petroleo'] }}; font-weight: bold; }

        .comentario { margin-top: 2mm; padding: 2.5mm 3mm; background: {{ $pal['salvia'] }}; border-left: 0.8mm solid {{ $pal['petroleo'] }}; page-break-inside: avoid; }
        .comentario .t { font-weight: bold; color: {{ $pal['petroleo'] }}; margin-bottom: 1mm; }

        /* Resultado */
        .resultado th { background: {{ $pal['salvia'] }}; color: {{ $pal['petroleo'] }}; font-size: 8.8pt; padding: 1.8mm; border: 0.25mm solid #d8dee4; text-align: center; }
        .resultado th.total { background: {{ $pal['petroleo'] }}; color: #ffffff; }
        .resultado td { border: 0.25mm solid #d8dee4; padding: 3mm 1.8mm; text-align: center; font-size: 12pt; font-weight: bold; color: #1f2933; }
        .resultado td.total { color: {{ $pal['petroleo'] }}; font-size: 13pt; }
        .veredicto { margin-top: 3mm; font-weight: bold; }
        .veredicto .op { margin-right: 8mm; white-space: nowrap; }
        .veredicto .op.activa { color: {{ $pal['petroleo'] }}; }
        .veredicto .op.inactiva { color: #9ca3af; font-weight: normal; }

        /* Firmas */
        .firmas { margin-top: 16mm; page-break-inside: avoid; }
        .firmas td { width: 33.3%; padding: 0 4mm; vertical-align: bottom; text-align: center; }
        .firmas .linea { border-top: 0.3mm solid {{ $pal['petroleo'] }}; padding-top: 1.5mm; font-weight: bold; color: {{ $pal['petroleo'] }}; font-size: 9.2pt; }
        .firmas .nombre-firma { font-size: 8.8pt; color: #1f2933; min-height: 4mm; }
        .firmas .det { font-size: 8pt; color: #6b7280; }
    </style>
</head>
<body>
    <table class="banda">
        <tr>
            <td style="width: 40%">
                @if ($d['logo'])
                    <img class="logo" src="{{ $d['logo'] }}" alt="Mr. Lana">
                @else
                    <span class="marca-texto">Mr. Lana</span>
                @endif
            </td>
            <td class="empresa">
                <strong>{{ $d['empresa'] }}</strong><br>
                Gerencia de Recursos Humanos · Programa de Inducción
            </td>
        </tr>
    </table>

    <div class="titulo">
        <h1>EVALUACIÓN DEL PROGRAMA DE INDUCCIÓN</h1>
        <div class="sub">Comprobación de aprendizaje para personal de nuevo ingreso</div>
        <div class="clave">Formato <strong>{{ $d['clave'] }}</strong>@if ($d['intento'] > 1) · Intento <strong>{{ $d['intento'] }}</strong>@endif</div>
    </div>
    <div class="filete"></div>

    <table class="ficha">
        <tr>
            <td class="etq">Nombre completo</td><td class="val">{{ $d['colaborador']['nombre'] }}</td>
            <td class="etq">Puesto</td><td class="val">{{ $d['colaborador']['puesto'] ?? 'Sin puesto registrado' }}</td>
        </tr>
        <tr>
            <td class="etq">Sucursal / Área</td><td class="val">{{ $d['colaborador']['sucursal'] ?? 'Sin sucursal registrada' }}</td>
            <td class="etq">Fecha de ingreso</td><td class="val">{{ $d['colaborador']['fecha_ingreso'] ?? 'Sin fecha registrada' }}</td>
        </tr>
        <tr>
            <td class="etq">Fecha de evaluación</td><td class="val">{{ $d['fecha_evaluacion'] }}</td>
            <td class="etq">Aplicó (RH)</td><td class="val">{{ $d['aplico'] ?? 'Pendiente de calificar' }}</td>
        </tr>
    </table>

    {{-- A · Opción múltiple --}}
    <div class="seccion">
        <table class="cabeza"><tr>
            <td class="letra">A</td><td class="nombre">Opción múltiple</td>
            <td class="valor">{{ $puntos($r['puntos']['a']) }} / {{ $d['maximos']['a'] }} puntos</td>
        </tr></table>
        <p class="indicacion">Marca con una «X» la respuesta correcta. Cada respuesta vale 1 punto.</p>

        @foreach ($r['seccion_a'] as $p)
            <div class="pregunta">
                <div class="enunciado">
                    <span class="num">{{ $p['numero'] }}.</span> {{ $p['pregunta'] }}
                    @if ($p['elegida'] === null)
                        <span class="badge pend">Sin respuesta</span>
                    @elseif ($p['acierto'])
                        <span class="badge ok">Correcta</span>
                    @else
                        <span class="badge mal">Incorrecta</span>
                    @endif
                </div>
                <table class="opciones">
                    @foreach ($p['opciones'] as $i => $opcion)
                        @php
                            $esElegida = $p['elegida'] === $i;
                            $esCorrecta = $p['correcta'] === $i;
                        @endphp
                        <tr class="{{ $esCorrecta ? 'correcta-fila' : ($esElegida ? 'error-fila' : '') }}">
                            <td class="casilla">( {{ $esElegida ? 'X' : ' ' }} )</td>
                            <td class="{{ $esElegida ? 'elegida' : '' }}">{{ $letra($i) }}) {{ $opcion }}</td>
                            <td class="nota-op">
                                @if ($esCorrecta && ! $esElegida) Respuesta correcta @elseif ($esElegida) Su respuesta @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endforeach
    </div>

    {{-- B · Verdadero o falso --}}
    <div class="seccion">
        <table class="cabeza"><tr>
            <td class="letra">B</td><td class="nombre">Verdadero o falso</td>
            <td class="valor">{{ $puntos($r['puntos']['b']) }} / {{ $d['maximos']['b'] }} puntos</td>
        </tr></table>
        <p class="indicacion">Escribe V si la afirmación es verdadera o F si es falsa. Cada respuesta vale 1 punto.</p>

        <table class="tabla">
            <thead><tr><th class="num">No.</th><th>Afirmación</th><th class="centro">Respuesta</th><th class="centro">Clave</th><th class="centro">Resultado</th></tr></thead>
            <tbody>
                @foreach ($r['seccion_b'] as $i => $p)
                    <tr class="{{ $i % 2 ? 'par' : '' }}">
                        <td class="num">{{ $p['numero'] }}</td>
                        <td>{{ $p['afirmacion'] }}</td>
                        <td class="centro"><strong>{{ $vf($p['elegida']) }}</strong></td>
                        <td class="centro">{{ $vf($p['correcto']) }}</td>
                        <td class="centro">
                            @if ($p['elegida'] === null)<span class="badge pend">Sin respuesta</span>@elseif ($p['acierto'])<span class="badge ok">Correcta</span>@else<span class="badge mal">Incorrecta</span>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- C · Relaciona columnas --}}
    <div class="seccion">
        <table class="cabeza"><tr>
            <td class="letra">C</td><td class="nombre">Relaciona columnas · Principios y cultura</td>
            <td class="valor">{{ $puntos($r['puntos']['c']) }} / {{ $d['maximos']['c'] }} puntos</td>
        </tr></table>
        <p class="indicacion">Escribe dentro del paréntesis la letra de la descripción que corresponde a cada concepto. Cada respuesta vale 1 punto.</p>

        <table class="tabla" style="margin-bottom: 3mm">
            <thead><tr><th class="num">No.</th><th>Concepto</th><th class="centro">Respuesta</th><th class="centro">Clave</th><th class="centro">Resultado</th></tr></thead>
            <tbody>
                @foreach ($r['seccion_c']['conceptos'] as $i => $p)
                    <tr class="{{ $i % 2 ? 'par' : '' }}">
                        <td class="num">{{ $p['numero'] }}</td>
                        <td><strong>{{ $p['concepto'] }}</strong></td>
                        <td class="centro"><strong>( {{ $p['elegida'] ?? ' ' }} )</strong></td>
                        <td class="centro">{{ $p['correcta'] }}</td>
                        <td class="centro">
                            @if ($p['elegida'] === null)<span class="badge pend">Sin respuesta</span>@elseif ($p['acierto'])<span class="badge ok">Correcta</span>@else<span class="badge mal">Incorrecta</span>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <table class="descripciones">
            @foreach ($r['seccion_c']['descripciones'] as $l => $descripcion)
                <tr><td class="let">{{ $l }}.</td><td>{{ $descripcion }}</td></tr>
            @endforeach
        </table>
    </div>

    {{-- D · Casos prácticos --}}
    <div class="seccion">
        <table class="cabeza"><tr>
            <td class="letra">D</td><td class="nombre">Casos prácticos</td>
            <td class="valor">
                @if ($r['puntos']['d'] === null) Pendiente / {{ $d['maximos']['d'] }} @else {{ $puntos($r['puntos']['d']) }} / {{ $d['maximos']['d'] }} puntos @endif
            </td>
        </tr></table>
        <p class="indicacion">Lee cada situación y responde con tus propias palabras. Se otorga el punto cuando la idea es correcta, aunque el colaborador la exprese con sus propias palabras.</p>

        @foreach ($r['seccion_d'] as $caso)
            <div class="caso">
                <div class="enunciado"><span class="num">{{ $caso['numero'] }}.</span> <span class="pts">({{ $caso['puntos_max'] }} puntos)</span> {{ $caso['enunciado'] }}</div>
                @if (trim($caso['respuesta']) !== '')
                    <div class="respuesta">{{ $caso['respuesta'] }}</div>
                @else
                    <div class="respuesta vacia">Sin respuesta.</div>
                @endif
                <table class="rubrica">
                    @foreach ($caso['rubrica'] as $criterio)
                        <tr>
                            <td class="pt">{{ $criterio['puntos'] }} pt</td>
                            <td>{{ $criterio['criterio'] }}</td>
                            <td class="estado">
                                @if ($criterio['otorgado'] === null)<span class="badge pend">Por calificar</span>@elseif ($criterio['otorgado'])<span class="badge ok">Otorgado</span>@else<span class="badge mal">No otorgado</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </table>
                <div class="subtotal">
                    @if ($caso['puntos_obtenidos'] === null) Pendiente de calificar por Recursos Humanos @else {{ $caso['puntos_obtenidos'] }} de {{ $caso['puntos_max'] }} puntos @endif
                </div>
            </div>
        @endforeach

        @if ($r['comentario_rh'])
            <div class="comentario">
                <div class="t">Comentario de Recursos Humanos</div>
                <div style="white-space: pre-line">{{ $r['comentario_rh'] }}</div>
            </div>
        @endif
    </div>

    {{-- E · Opinión (no califica) --}}
    <div class="seccion" style="page-break-inside: avoid">
        <table class="cabeza"><tr>
            <td class="letra">E</td><td class="nombre">Tu opinión sobre la inducción</td>
            <td class="valor">No califica</td>
        </tr></table>
        <p class="indicacion">Marca con una «X» del 1 (muy en desacuerdo) al 5 (totalmente de acuerdo).</p>

        <table class="tabla">
            <thead>
                <tr>
                    <th>Aspecto</th>
                    @for ($n = $d['escala_min']; $n <= $d['escala_max']; $n++)
                        <th class="centro" style="width: 10mm">{{ $n }}</th>
                    @endfor
                </tr>
            </thead>
            <tbody>
                @foreach ($d['opinion_aspectos'] as $i => $aspecto)
                    @php $valor = isset($opinion[$aspecto['clave']]) ? (int) $opinion[$aspecto['clave']] : null; @endphp
                    <tr class="{{ $i % 2 ? 'par' : '' }}">
                        <td>{{ $aspecto['texto'] }}</td>
                        @for ($n = $d['escala_min']; $n <= $d['escala_max']; $n++)
                            <td class="centro"><strong>{{ $valor === $n ? 'X' : '' }}</strong></td>
                        @endfor
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p style="margin-top: 3mm"><strong>{{ $d['opinion_pregunta'] }}</strong></p>
        @if ($tema !== '')
            <div class="respuesta">{{ $tema }}</div>
        @else
            <div class="respuesta vacia">Sin respuesta.</div>
        @endif
        @if ($opinion === [])
            <p class="indicacion" style="margin-top: 1mm">El colaborador no registró su opinión.</p>
        @endif
    </div>

    {{-- Resultado --}}
    <div class="seccion" style="page-break-inside: avoid">
        <table class="cabeza"><tr>
            <td class="letra">&nbsp;</td><td class="nombre">Resultado (llenado por Recursos Humanos)</td>
            <td class="valor">Total: {{ $d['maximos']['total'] }}</td>
        </tr></table>
        <table class="resultado">
            <tr>
                <th>Sección A ({{ $d['maximos']['a'] }})</th>
                <th>Sección B ({{ $d['maximos']['b'] }})</th>
                <th>Sección C ({{ $d['maximos']['c'] }})</th>
                <th>Sección D ({{ $d['maximos']['d'] }})</th>
                <th class="total">TOTAL ({{ $d['maximos']['total'] }})</th>
            </tr>
            <tr>
                <td>{{ $puntos($r['puntos']['a']) }}</td>
                <td>{{ $puntos($r['puntos']['b']) }}</td>
                <td>{{ $puntos($r['puntos']['c']) }}</td>
                <td>{{ $puntos($r['puntos']['d']) }}</td>
                <td class="total">{{ $puntos($r['puntos']['total']) }}</td>
            </tr>
        </table>

        <div class="veredicto">
            Resultado:
            @if ($r['calificado'])
                <span class="op {{ $r['aprobado'] ? 'activa' : 'inactiva' }}">( {{ $r['aprobado'] ? 'X' : ' ' }} ) Aprobado ({{ $d['aprobatoria'] }} a {{ $d['maximos']['total'] }} puntos)</span>
                <span class="op {{ $r['aprobado'] ? 'inactiva' : 'activa' }}">( {{ $r['aprobado'] ? ' ' : 'X' }} ) Requiere reforzamiento (menos de {{ $d['aprobatoria'] }} puntos)</span>
                <div style="margin-top: 1.5mm">
                    <span class="badge {{ $r['aprobado'] ? 'ok' : 'mal' }}">{{ $r['nivel_etiqueta'] }}</span>
                </div>
            @else
                <span class="badge pend">Pendiente: Recursos Humanos aún no califica los casos prácticos</span>
            @endif
        </div>
        <p class="indicacion" style="margin-top: 2.5mm">Si el resultado requiere reforzamiento, Recursos Humanos repasa los temas con respuestas incorrectas y aplica nuevamente la evaluación en un plazo máximo de {{ $d['dias_reevaluacion'] }} días hábiles.</p>

        <table class="firmas">
            <tr>
                <td>
                    <div class="linea">Colaborador(a)</div>
                    <div class="nombre-firma">{{ $d['colaborador']['nombre'] }}</div>
                    <div class="det">Nombre y firma</div>
                </td>
                <td>
                    <div class="linea">Aplicó · Recursos Humanos</div>
                    <div class="nombre-firma">&nbsp;</div>
                    <div class="det">Nombre y firma</div>
                </td>
                <td>
                    <div class="linea">Jefe(a) inmediato(a)</div>
                    <div class="nombre-firma">{{ $d['colaborador']['jefe'] ?? '' }}</div>
                    <div class="det">Enterado · Nombre y firma</div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
