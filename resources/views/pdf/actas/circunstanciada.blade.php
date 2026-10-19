{{--
    ACTA ADMINISTRATIVA CIRCUNSTANCIADA — formato generado por MR. LANA PEOPLE.

    Fuente estructural: docs/acta/ACTA ADMINISTRATIVA CIRCUNSTANCIADA.docx
    (apartados I–XIII y su texto institucional, que se conserva literal).
    Las «INSTRUCCIÓN DE LLENADO» del DOCX son reglas del formulario: NUNCA se
    imprimen aquí. Sin guiones bajos ni casillas: todo dato que PEOPLE conoce
    o que RH capturó se imprime; lo que no aplica se resuelve con una frase
    neutral o se omite. Solo las firmas quedan para papel.

    Todo sale de $d (ActaPdfService::datosCircunstanciada). La vista previa
    usa esta MISMA vista con $borrador = true (marca «VISTA PREVIA ·
    BORRADOR»); el PDF final, sin marca. Solo tablas y bloques: Chrome y
    DomPDF lo imprimen igual. Sin fuentes remotas.
--}}
@php
    $romano = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII', 'XIII'];
    $ficha = fn (?string $puesto, ?string $area) => collect([$puesto, $area])->filter()->implode(' · ');
    $firmas = [];
    $firmas[] = ['nombre' => $d['responsable']['nombre'], 'puesto' => $d['responsable']['puesto'], 'caracter' => 'Responsable del levantamiento'.($d['jefe'] && $d['jefe']['es_responsable'] ? ' y jefe inmediato' : '')];
    $firmas[] = ['nombre' => $d['trabajador']['nombre'], 'puesto' => $d['trabajador']['puesto'], 'caracter' => 'Trabajador involucrado'];
    if ($d['jefe'] && ! $d['jefe']['es_responsable']) {
        $firmas[] = ['nombre' => $d['jefe']['nombre'], 'puesto' => $d['jefe']['puesto'], 'caracter' => 'Jefe inmediato o superior jerárquico'];
    }
    foreach ($d['testigos_hechos'] as $i => $t) {
        $firmas[] = ['nombre' => $t['nombre'], 'puesto' => $t['puesto'], 'caracter' => sprintf('Testigo de hechos %d', $i + 1)];
    }
    foreach ($d['testigos_asistencia'] as $i => $t) {
        $firmas[] = ['nombre' => $t['nombre'], 'puesto' => $t['puesto'], 'caracter' => sprintf('Testigo de asistencia %d', $i + 1)];
    }
    $parrafos = fn (?string $texto) => collect(preg_split("/\R{2,}/u", trim((string) $texto)) ?: [])->filter(fn ($p) => trim($p) !== '');
@endphp
@php($pal = config('pdf_identidad'))
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Acta administrativa circunstanciada {{ $d['folio'] }}</title>
    {{-- Identidad institucional PEOPLE: paleta única en config/pdf_identidad.php. --}}
    <style>
        @page {
            @if ($motor === 'browsershot') size: A4 portrait; @endif
            margin: 17mm 17mm 20mm 17mm;
        }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: Arial, 'Helvetica Neue', Helvetica, sans-serif; font-size: 10pt; line-height: 1.5; color: #1f2933; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        p { margin: 0 0 2.2mm; text-align: justify; orphans: 3; widows: 3; }
        table { width: 100%; border-collapse: collapse; }

        /* Encabezado de la primera hoja */
        .encabezado td { vertical-align: top; }
        .logo { height: 14mm; }
        .titulo { text-align: right; }
        .titulo h1 { margin: 0; font-size: 14.5pt; line-height: 1.2; letter-spacing: 1.4pt; color: {{ $pal['petroleo'] }}; font-weight: bold; }
        .meta { margin-top: 2mm; font-size: 8.6pt; color: {{ $pal['texto_suave'] }}; line-height: 1.45; }
        .meta strong { color: {{ $pal['texto'] }}; font-weight: bold; }
        .filete { height: 0.5mm; background: {{ $pal['dorado'] }}; margin: 4mm 0 5mm; }

        /* Datos del patrón */
        .patron td { padding: 0.8mm 0; font-size: 9pt; vertical-align: top; }
        .patron .etq { width: 34%; color: {{ $pal['texto_suave'] }}; }

        /* Secciones */
        .seccion { margin-top: 6.5mm; }
        h2 { margin: 0 0 2.5mm; padding-bottom: 1.2mm; font-size: 10pt; letter-spacing: 0.6pt; text-transform: uppercase; color: {{ $pal['petroleo'] }}; border-bottom: 0.25mm solid {{ $pal['borde_claro'] }}; page-break-after: avoid; }
        h2 .num { color: {{ $pal['dorado'] }}; margin-right: 2mm; }
        h3 { margin: 4mm 0 1.5mm; font-size: 9.4pt; color: {{ $pal['petroleo'] }}; page-break-after: avoid; }
        .nota { color: {{ $pal['texto_suave'] }}; font-size: 9pt; }

        /* Tabla de identificación */
        .ficha td { padding: 1.4mm 2mm; border-bottom: 0.2mm solid {{ $pal['borde_claro'] }}; font-size: 9.4pt; vertical-align: top; }
        .ficha .etq { width: 30%; color: {{ $pal['texto_suave'] }}; }

        .persona { margin-bottom: 2.5mm; page-break-inside: avoid; }
        .persona .nombre { font-weight: bold; }
        .persona .det { color: {{ $pal['texto_suave'] }}; font-size: 9pt; }

        .manifestacion { margin: 0 0 4mm; page-break-inside: avoid; }
        .manifestacion .quien { margin-bottom: 1mm; }

        .anexo { margin: 0 0 3mm; padding-left: 3mm; border-left: 0.6mm solid {{ $pal['borde_claro'] }}; page-break-inside: avoid; }
        .anexo .det { color: {{ $pal['texto_suave'] }}; font-size: 9pt; }

        /* Firmas: espacio real para pluma, sin cajas */
        .firmas { margin-top: 2mm; }
        .firmas td { width: 50%; padding: 15mm 7mm 1.5mm; vertical-align: bottom; text-align: center; page-break-inside: avoid; }
        .firmas .linea { border-top: 0.25mm solid {{ $pal['texto'] }}; padding-top: 1.5mm; font-weight: bold; font-size: 9.4pt; }
        .firmas .puesto { font-size: 8.8pt; }
        .firmas .caracter { font-size: 8.2pt; color: {{ $pal['texto_suave'] }}; }

        .control td { padding: 1.2mm 2mm; border-bottom: 0.2mm solid {{ $pal['borde_claro'] }}; font-size: 9.2pt; }
        .control .etq { width: 45%; color: {{ $pal['texto_suave'] }}; }

        /* Vista previa: marca diagonal suave (petróleo institucional) que no tapa el texto */
        .marca { position: fixed; top: 42%; left: 0; width: 100%; text-align: center; transform: rotate(-30deg); font-size: 40pt; font-weight: bold; letter-spacing: 6pt; color: rgba(49, 91, 89, 0.13); z-index: 0; }
        /* En línea y sin padding vertical: la vista previa pagina exactamente igual que el acta definitiva. */
        .insignia { margin-left: 1.5mm; white-space: nowrap; padding: 0 1.6mm; border: 0.25mm solid {{ $pal['dorado'] }}; border-radius: 1mm; color: {{ $pal['alerta_texto'] }}; font-size: 7.6pt; letter-spacing: 1pt; }
    </style>
</head>
<body>
    @if ($borrador)
        <div class="marca">VISTA PREVIA · BORRADOR</div>
    @endif

    <table class="encabezado">
        <tr>
            <td style="width: 42%">
                @if ($d['logo'])
                    <img class="logo" src="{{ $d['logo'] }}" alt="MR. LANA">
                @else
                    <strong>{{ $d['empresa'] }}</strong>
                @endif
            </td>
            <td class="titulo">
                <h1>ACTA ADMINISTRATIVA<br>CIRCUNSTANCIADA</h1>
                <div class="meta">
                    Folio <strong>{{ $d['folio'] }}</strong>@if ($borrador) <span class="insignia">VISTA PREVIA · BORRADOR</span>@endif<br>
                    {{ $d['fecha_levantamiento'] }}@if ($d['centro']) · {{ $d['centro'] }}@endif
                </div>
            </td>
        </tr>
    </table>
    <div class="filete"></div>

    <table class="patron">
        <tr><td class="etq">Razón social del patrón</td><td>{{ $d['empresa'] }}</td></tr>
        <tr><td class="etq">Centro de trabajo</td><td>{{ $d['centro'] ?? 'No registrado en PEOPLE' }}</td></tr>
        @if ($d['domicilio'])
            <tr><td class="etq">Domicilio del centro de trabajo</td><td>{{ $d['domicilio'] }}</td></tr>
        @endif
    </table>

    <div class="seccion">
        <p>
            En {{ $d['lugar'] ?? ($d['centro'] ? 'las instalaciones de '.$d['centro'] : 'las instalaciones del centro de trabajo') }}@if (! $d['lugar'] && $d['domicilio']), ubicadas en {{ $d['domicilio'] }}@endif,
            @if ($d['hora_levantamiento']) siendo las {{ $d['hora_levantamiento'] }} horas del {{ $d['fecha_levantamiento'] }}, @else el {{ $d['fecha_levantamiento'] }}, @endif
            se reúnen las personas que se identifican e intervienen en la presente acta administrativa circunstanciada, con la finalidad de documentar los hechos que se describen en este instrumento, recabar las manifestaciones correspondientes y relacionar los elementos disponibles que guarden relación con la incidencia.
        </p>
    </div>

    {{-- I --}}
    <div class="seccion">
        <h2><span class="num">{{ $romano[0] }}.</span>Datos de identificación del trabajador</h2>
        <table class="ficha">
            <tr><td class="etq">Nombre completo</td><td>{{ $d['trabajador']['nombre'] }}</td></tr>
            @if ($d['trabajador']['numero_empleado'])<tr><td class="etq">Número de empleado</td><td>{{ $d['trabajador']['numero_empleado'] }}</td></tr>@endif
            @if ($d['trabajador']['puesto'])<tr><td class="etq">Puesto</td><td>{{ $d['trabajador']['puesto'] }}</td></tr>@endif
            @if ($d['trabajador']['area'])<tr><td class="etq">Área de adscripción</td><td>{{ $d['trabajador']['area'] }}</td></tr>@endif
            @if ($d['trabajador']['centro'])<tr><td class="etq">Centro de trabajo</td><td>{{ $d['trabajador']['centro'] }}</td></tr>@endif
            @if ($d['trabajador']['fecha_ingreso'])<tr><td class="etq">Fecha de ingreso</td><td>{{ $d['trabajador']['fecha_ingreso'] }}</td></tr>@endif
            @if ($d['trabajador']['jefe'])<tr><td class="etq">Jefe inmediato</td><td>{{ $d['trabajador']['jefe'] }}</td></tr>@endif
        </table>
    </div>

    {{-- II --}}
    <div class="seccion">
        <h2><span class="num">{{ $romano[1] }}.</span>Fundamento y objeto del acta</h2>
        <p>La presente acta se levanta con fundamento en las disposiciones aplicables de la Ley Federal del Trabajo, así como en las obligaciones laborales que correspondan conforme al contrato individual de trabajo y, en su caso, al Reglamento Interior de Trabajo aplicable.</p>
        <p>Su objeto es dejar constancia circunstanciada de los hechos, acontecimientos o incidencias relacionados con la prestación de servicios del trabajador antes identificado, registrar las manifestaciones de las personas que intervengan y relacionar la documentación y los demás elementos disponibles para su posterior valoración por las áreas competentes.</p>
        <p>El levantamiento de esta acta tiene una finalidad documental y de investigación interna, por lo que su elaboración no implica, por sí misma, una determinación definitiva sobre la existencia de una infracción laboral o la responsabilidad del trabajador involucrado.</p>
        @if ($d['disposiciones'])
            <h3>Disposiciones específicas relacionadas con los hechos</h3>
            @foreach ($parrafos($d['disposiciones']) as $p)<p>{!! nl2br(e($p)) !!}</p>@endforeach
        @else
            <p class="nota">No se incorporaron disposiciones específicas.</p>
        @endif
    </div>

    {{-- III --}}
    <div class="seccion">
        <h2><span class="num">{{ $romano[2] }}.</span>Clasificación de la incidencia</h2>
        <table class="ficha">
            <tr><td class="etq">Tipo de incidencia</td><td>{{ $d['incidencia']['tipo'] }}</td></tr>
            <tr><td class="etq">Fecha o período de los hechos</td><td>{{ $d['incidencia']['fecha'] }}</td></tr>
            @if ($d['incidencia']['hora'])<tr><td class="etq">Hora o período aproximado</td><td>{{ $d['incidencia']['hora'] }}</td></tr>@endif
            @if ($d['incidencia']['lugar'])<tr><td class="etq">Lugar en que ocurrieron</td><td>{{ $d['incidencia']['lugar'] }}</td></tr>@endif
        </table>
    </div>

    {{-- IV --}}
    <div class="seccion">
        <h2><span class="num">{{ $romano[3] }}.</span>Descripción circunstanciada de los hechos</h2>
        <p>Se hace constar que los hechos que motivan el levantamiento de la presente acta son los siguientes:</p>
        @foreach ($parrafos($d['hechos']) as $p)<p>{!! nl2br(e($p)) !!}</p>@endforeach
    </div>

    {{-- V --}}
    <div class="seccion">
        <h2><span class="num">{{ $romano[4] }}.</span>Personas que intervienen</h2>

        <h3>A. Responsable del levantamiento</h3>
        <div class="persona">
            <div class="nombre">{{ $d['responsable']['nombre'] }}</div>
            <div class="det">{{ $ficha($d['responsable']['puesto'], $d['responsable']['area']) }}@if ($d['responsable']['caracter']) · Interviene con el carácter de {{ $d['responsable']['caracter'] }}@endif</div>
        </div>

        @if ($d['jefe'] && ! $d['jefe']['es_responsable'])
            <h3>B. Jefe inmediato o superior jerárquico</h3>
            <div class="persona">
                <div class="nombre">{{ $d['jefe']['nombre'] }}</div>
                <div class="det">{{ $ficha($d['jefe']['puesto'], $d['jefe']['area']) }}</div>
            </div>
        @elseif ($d['jefe'] && $d['jefe']['es_responsable'])
            <p class="nota">El responsable del levantamiento interviene también como jefe inmediato del trabajador.</p>
        @endif

        <h3>{{ $d['jefe'] && ! $d['jefe']['es_responsable'] ? 'C' : 'B' }}. Testigos de hechos</h3>
        @forelse ($d['testigos_hechos'] as $i => $t)
            <div class="persona">
                <div class="nombre">{{ $i === 0 ? 'Primer testigo' : 'Segundo testigo' }}: {{ $t['nombre'] }}</div>
                <div class="det">{{ $ficha($t['puesto'], $t['area']) }}@if ($t['relacion']) · {{ $t['relacion'] }}@endif</div>
            </div>
        @empty
            <p class="nota">No se registraron testigos de hechos.</p>
        @endforelse

        <h3>{{ $d['jefe'] && ! $d['jefe']['es_responsable'] ? 'D' : 'C' }}. Testigos de asistencia</h3>
        @forelse ($d['testigos_asistencia'] as $i => $t)
            <div class="persona">
                <div class="nombre">{{ $i === 0 ? 'Primer testigo de asistencia' : 'Segundo testigo de asistencia' }}: {{ $t['nombre'] }}</div>
                <div class="det">{{ $ficha($t['puesto'], $t['area']) }}</div>
            </div>
        @empty
            <p class="nota">No intervinieron testigos de asistencia.</p>
        @endforelse
    </div>

    {{-- VI --}}
    <div class="seccion">
        <h2><span class="num">{{ $romano[5] }}.</span>Declaraciones y manifestaciones</h2>

        @php $letra = 'A'; @endphp
        @if ($d['responsable']['manifestacion'] || ($d['jefe'] && $d['jefe']['manifestacion']))
            <h3>{{ $letra++ }}. Manifestaciones del responsable del levantamiento{{ $d['jefe'] && ! $d['jefe']['es_responsable'] ? ' y del jefe inmediato' : '' }}</h3>
            @if ($d['responsable']['manifestacion'])
                <div class="manifestacion">
                    <p class="quien">En uso de la palabra, {{ $d['responsable']['nombre'] }}, quien se desempeña como {{ $d['responsable']['puesto'] ?? 'responsable del levantamiento' }}{{ $d['jefe'] && $d['jefe']['es_responsable'] ? ' y es jefe inmediato del trabajador' : '' }}, manifiesta lo siguiente:</p>
                    @foreach ($parrafos($d['responsable']['manifestacion']) as $p)<p>{!! nl2br(e($p)) !!}</p>@endforeach
                </div>
            @endif
            @if ($d['jefe'] && ! $d['jefe']['es_responsable'] && $d['jefe']['manifestacion'])
                <div class="manifestacion">
                    <p class="quien">En uso de la palabra, {{ $d['jefe']['nombre'] }}, quien se desempeña como {{ $d['jefe']['puesto'] ?? 'jefe inmediato' }}, manifiesta lo siguiente:</p>
                    @foreach ($parrafos($d['jefe']['manifestacion']) as $p)<p>{!! nl2br(e($p)) !!}</p>@endforeach
                </div>
            @endif
        @endif

        <h3>{{ $letra++ }}. Manifestaciones del trabajador involucrado</h3>
        <div class="manifestacion">
            @if ($d['trabajador_manifesto'] && $d['trabajador_manifestacion'])
                <p class="quien">En uso de la palabra, {{ $d['trabajador']['nombre'] }}, en su carácter de trabajador involucrado, manifiesta lo siguiente respecto de los hechos que se le han comunicado:</p>
                @foreach ($parrafos($d['trabajador_manifestacion']) as $p)<p>{!! nl2br(e($p)) !!}</p>@endforeach
            @else
                <p>Comunicados los hechos, {{ $d['trabajador']['nombre'] }}, en su carácter de trabajador involucrado, manifestó que no deseaba formular manifestación alguna. Su silencio no se interpreta como aceptación de los hechos ni como reconocimiento de responsabilidad.</p>
            @endif
            @if ($d['trabajador_elementos'])
                <p class="quien">Asimismo, manifiesta que desea aportar o señalar los siguientes documentos, personas u otros elementos relacionados con los hechos:</p>
                @foreach ($parrafos($d['trabajador_elementos']) as $p)<p>{!! nl2br(e($p)) !!}</p>@endforeach
            @endif
        </div>

        @foreach ($d['testigos_hechos'] as $i => $t)
            <h3>{{ $letra++ }}. Manifestaciones del {{ $i === 0 ? 'primer' : 'segundo' }} testigo de hechos</h3>
            <div class="manifestacion">
                @if ($t['manifestacion'])
                    <p class="quien">En uso de la palabra, {{ $t['nombre'] }}, quien se desempeña como {{ $t['puesto'] ?? 'colaborador' }}, manifiesta:</p>
                    @foreach ($parrafos($t['manifestacion']) as $p)<p>{!! nl2br(e($p)) !!}</p>@endforeach
                @else
                    <p>{{ $t['nombre'] }} no formuló manifestación.</p>
                @endif
            </div>
        @endforeach

        @php $asistenciaConDicho = collect($d['testigos_asistencia'])->filter(fn ($t) => $t['manifestacion']); @endphp
        @if ($asistenciaConDicho->isNotEmpty())
            <h3>{{ $letra++ }}. Manifestaciones de los testigos de asistencia</h3>
            @foreach ($asistenciaConDicho as $t)
                <div class="manifestacion">
                    <p class="quien">En uso de la palabra, {{ $t['nombre'] }}, en su carácter de testigo de asistencia, manifiesta lo siguiente respecto de las circunstancias del levantamiento de la presente acta:</p>
                    @foreach ($parrafos($t['manifestacion']) as $p)<p>{!! nl2br(e($p)) !!}</p>@endforeach
                </div>
            @endforeach
        @endif
    </div>

    {{-- VII --}}
    <div class="seccion">
        <h2><span class="num">{{ $romano[6] }}.</span>Documentación y elementos relacionados con los hechos</h2>
        @if (count($d['anexos']) > 0)
            <p>Se hace constar que, para efectos de integrar el expediente correspondiente, se relacionan los siguientes documentos, registros, archivos y demás elementos disponibles:</p>
            @foreach ($d['anexos'] as $a)
                <div class="anexo">
                    <strong>Anexo {{ $a['numero'] }}.</strong> {{ $a['descripcion'] }}
                    @if ($a['identificacion'])<div class="det">Fecha o identificación: {{ $a['identificacion'] }}</div>@endif
                    @if ($a['relacion'])<div class="det">Relación con los hechos: {{ $a['relacion'] }}</div>@endif
                </div>
            @endforeach
            <p>Los elementos antes relacionados se integran al expediente en los términos en que fueron recabados. Su autenticidad, integridad, pertinencia y alcance estarán sujetos a la valoración que corresponda.</p>
        @else
            <p>No se incorporaron anexos a la presente acta.</p>
        @endif
    </div>

    {{-- VIII --}}
    <div class="seccion">
        <h2><span class="num">{{ $romano[7] }}.</span>Incidencias durante el levantamiento</h2>
        <p>Durante el desarrollo de la presente diligencia se hace constar lo siguiente:</p>
        @if ($d['incidencias'] || $d['negativa_firma'])
            @if ($d['incidencias'])@foreach ($parrafos($d['incidencias']) as $p)<p>{!! nl2br(e($p)) !!}</p>@endforeach @endif
            @if ($d['negativa_firma'])<p>{{ $d['negativa_firma'] }}</p>@endif
        @else
            <p>No se registraron incidencias adicionales durante el levantamiento.</p>
        @endif
        <p>La negativa a firmar o a formular manifestaciones no se interpretará, por sí misma, como aceptación de los hechos o reconocimiento de responsabilidad.</p>
    </div>

    {{-- IX --}}
    <div class="seccion">
        <h2><span class="num">{{ $romano[8] }}.</span>Determinación y seguimiento</h2>
        <p>La presente acta se integra como constancia documental de los hechos y manifestaciones recabados. Su contenido y los elementos relacionados serán remitidos, cuando corresponda, al área de Recursos Humanos y/o al área Jurídica para su revisión y valoración.</p>
        <p>La determinación sobre la procedencia de alguna medida laboral, en su caso, deberá realizarse de manera independiente, atendiendo a las circunstancias acreditadas, las disposiciones legales aplicables y los procedimientos correspondientes.</p>
    </div>

    {{-- X --}}
    <div class="seccion">
        <h2><span class="num">{{ $romano[9] }}.</span>Cierre del acta</h2>
        <p>Una vez asentados los hechos, las manifestaciones de las personas que intervinieron y los elementos relacionados con la incidencia, se da por concluida la presente diligencia, siendo las {{ $d['cierre']['hora'] }} horas del {{ $d['cierre']['fecha'] }}.</p>
        <p>Se hace constar que el contenido de esta acta corresponde a la información recabada durante su elaboración y que las manifestaciones atribuidas a cada participante se registran en los términos asentados en el documento.</p>
        <p>Las firmas que aparecen al calce acreditan la intervención de las personas firmantes en los términos que legalmente correspondan, sin que su sola suscripción implique aceptación de hechos, reconocimiento de responsabilidad o conformidad con las manifestaciones de otras personas.</p>
        <p>La presente acta, junto con los anexos que se relacionan en ella, quedará integrada al expediente laboral correspondiente para los efectos administrativos y legales que procedan.</p>
    </div>

    {{-- XI --}}
    <div class="seccion">
        <h2><span class="num">{{ $romano[10] }}.</span>Firmas de los intervinientes</h2>
        <table class="firmas">
            @foreach (array_chunk($firmas, 2) as $fila)
                <tr>
                    @foreach ($fila as $f)
                        <td>
                            <div class="linea">{{ $f['nombre'] }}</div>
                            @if ($f['puesto'])<div class="puesto">{{ $f['puesto'] }}</div>@endif
                            <div class="caracter">{{ $f['caracter'] }}</div>
                        </td>
                    @endforeach
                    @if (count($fila) === 1)<td></td>@endif
                </tr>
            @endforeach
        </table>
    </div>

    {{-- XII --}}
    <div class="seccion">
        <h2><span class="num">{{ $romano[11] }}.</span>Constancia de entrega de copia al trabajador</h2>
        @if (($d['entrega']['estado'] ?? 'pendiente') === 'entregada')
            <p>Se hace constar que se entregó copia de la presente acta y de sus anexos al trabajador.@if (! empty($d['entrega']['por'])) La entrega la realizó {{ $d['entrega']['por'] }}.@endif</p>
        @elseif (($d['entrega']['estado'] ?? 'pendiente') === 'no_posible')
            <p>Se hace constar que no fue posible realizar la entrega de la copia por las siguientes circunstancias: {{ $d['entrega']['motivo'] }}</p>
        @else
            <p class="nota">Constancia de entrega: pendiente de formalización.</p>
        @endif
    </div>

    {{-- XIII --}}
    <div class="seccion" style="page-break-inside: avoid">
        <h2><span class="num">{{ $romano[12] }}.</span>Control documental</h2>
        <table class="control">
            <tr><td class="etq">Folio del acta</td><td>{{ $d['folio'] }}</td></tr>
            <tr><td class="etq">Número de hojas que integran el acta</td><td>{{ $d['control']['hojas'] ?? '—' }}</td></tr>
            <tr><td class="etq">Número de anexos</td><td>{{ $d['control']['anexos'] }}</td></tr>
            <tr><td class="etq">Fecha de integración al expediente</td><td>{{ $d['control']['fecha_integracion'] ?? 'Al generar el acta definitiva' }}</td></tr>
            <tr><td class="etq">Responsable de la integración</td><td>{{ $d['control']['integrado_por'] ?? 'Al generar el acta definitiva' }}</td></tr>
        </table>
        <p style="margin-top: 2.5mm">La presente hoja de firmas y los anexos identificados forman parte integrante del acta administrativa circunstanciada identificada con el folio indicado en este documento.</p>
    </div>
</body>
</html>
