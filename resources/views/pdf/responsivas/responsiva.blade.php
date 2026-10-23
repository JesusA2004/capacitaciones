{{--
    RESPONSIVA — formato generado por MR. LANA PEOPLE.

    Fuente: docs/responsiva/*.docx (tres familias: bien mueble, equipo
    electrónico, vehículo). Encabezado PRODUCTOS Y SERVICIOS MR LANA, S.A.P.I.
    DE C.V., fundamento (artículos 132, 134 y 135 LFT), declaración y firmas
    (razón social + «Representante legal» / trabajador) del formato; cada familia imprime
    sus propias secciones (sección 1 «DATOS DE QUIEN RECIBE» es común).

    Todo dato sale de $d (App\Services\Responsivas\ResponsivaPdfService::datos),
    congelado en el snapshot. Solo tablas y bloques: Chrome y DomPDF lo imprimen
    igual, sin fuentes remotas. Paleta institucional (sin azul genérico).
--}}
@php
    $eq = $d['denominacion'];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $d['titulo'] }} {{ $d['folio'] }}</title>
    <style>
        @page {
            @if ($motor === 'browsershot') size: Letter portrait; @endif
            margin: 14mm 18mm 22mm 18mm;
        }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: Arial, 'Helvetica Neue', Helvetica, sans-serif; font-size: 10pt; line-height: 1.5; color: #27332f; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        p { margin: 0 0 3mm; text-align: justify; orphans: 3; widows: 3; }
        table { width: 100%; border-collapse: collapse; }

        /* Membrete */
        .membrete td { vertical-align: middle; }
        .logo { height: 13mm; }
        .meta { text-align: right; font-size: 8.2pt; color: #5f6f69; line-height: 1.5; }
        .meta strong { display: inline-block; margin-top: 0.8mm; padding: 0.7mm 3mm; border-radius: 3mm; background: #eef3ef; color: #315B59; font-size: 9pt; letter-spacing: 0.4pt; }
        .franja { height: 1.2mm; margin: 3.5mm 0 6mm; border-radius: 1mm; background-color: #315B59; background-image: linear-gradient(90deg, #315B59 0%, #3F7558 60%, #C7A66B 100%); }

        /* Marca de agua */
        .agua { position: fixed; top: 40%; left: 10%; width: 80%; opacity: 0.035; z-index: -1; }
        .agua img { width: 100%; }

        h1 { margin: 0 0 1.5mm; text-align: center; font-size: 15pt; letter-spacing: 1.6pt; color: #315B59; }
        .subtitulo { text-align: center; font-size: 8.6pt; color: #5f6f69; letter-spacing: 0.5pt; margin-bottom: 6mm; text-transform: uppercase; }

        .seccion { margin: 0 0 5mm; page-break-inside: avoid; }
        .seccion > .titulo { margin-bottom: 2mm; padding: 1.6mm 3mm; font-size: 8.4pt; font-weight: bold; letter-spacing: 0.6pt; text-transform: uppercase; color: #ffffff; background: #315B59; border-radius: 1.5mm; }

        /* Ficha de campos (etiqueta + valor) */
        .campos { border: 0.25mm solid #e0e7e2; border-radius: 1.5mm; background: #FBF8F2; }
        .campos td { padding: 1.8mm 3mm; font-size: 9.2pt; border-bottom: 0.2mm solid #eef3ef; vertical-align: top; }
        .campos tr:last-child td { border-bottom: none; }
        .campos .etq { width: 38%; color: #5f6f69; font-size: 8.4pt; text-transform: uppercase; letter-spacing: 0.4pt; }
        .campos .val { font-weight: bold; color: #27332f; }

        /* Opciones tipo casilla */
        .opciones { margin: 1mm 0 2mm; }
        .opciones .grupo-etq { display: block; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.4pt; color: #5f6f69; margin: 1mm 0; }
        .opc { display: inline-block; margin: 0.8mm 5mm 0.8mm 0; font-size: 9pt; }
        .cb { display: inline-block; width: 2.9mm; height: 2.9mm; margin-right: 1.4mm; border: 0.3mm solid #315B59; border-radius: 0.6mm; vertical-align: -0.4mm; }
        .cb.on { background: #315B59; }
        .opc.on { font-weight: bold; color: #315B59; }

        .texto-bloque { margin: 1mm 0 2mm; padding: 2mm 3mm; border-left: 0.8mm solid #C7A66B; background: #FBF8F2; font-size: 9pt; }
        .texto-bloque .etq { display: block; font-size: 7.6pt; text-transform: uppercase; letter-spacing: 0.4pt; color: #5f6f69; margin-bottom: 0.6mm; }

        .clausula { margin-bottom: 3mm; }
        .declaracion { margin: 2mm 0 5mm; padding: 3mm 3.5mm; border: 0.25mm solid #d7e0da; border-radius: 1.5mm; background: #f3f7f3; page-break-inside: avoid; }
        .declaracion .etq { display: block; font-size: 8.4pt; font-weight: bold; letter-spacing: 0.6pt; text-transform: uppercase; color: #315B59; margin-bottom: 1.5mm; }

        /* Firmas */
        .firmas { margin-top: 14mm; page-break-inside: avoid; }
        .firmas td { width: 50%; padding: 0 8mm; vertical-align: bottom; text-align: center; }
        .firmas .espacio { height: 20mm; }
        .firmas tr.roles td { vertical-align: bottom; height: 7mm; }
        .firmas tr.lineas td { vertical-align: top; }
        .firmas .rol { display: inline-block; padding: 0.7mm 4mm; border-radius: 3mm; background: #eef3ef; color: #315B59; font-size: 7.6pt; font-weight: bold; letter-spacing: 1pt; text-transform: uppercase; }
        .firmas .linea { border-top: 0.3mm solid #27332f; padding-top: 1.6mm; font-weight: bold; font-size: 8.8pt; white-space: normal; }
        .firmas .puesto { font-size: 8.2pt; color: #5f6f69; font-weight: bold; }

        .marca { position: fixed; top: 44%; left: 0; width: 100%; text-align: center; transform: rotate(-30deg); font-size: 46pt; font-weight: bold; letter-spacing: 8pt; color: rgba(49, 91, 89, 0.12); z-index: 10; }
    </style>
</head>
<body>
    @if ($d['logo'])
        <div class="agua"><img src="{{ $d['logo'] }}" alt=""></div>
    @endif
    @if ($borrador)
        <div class="marca">VISTA PREVIA</div>
    @endif

    <table class="membrete">
        <tr>
            <td>
                @if ($d['logo'])
                    <img class="logo" src="{{ $d['logo'] }}" alt="Mr. Lana">
                @endif
            </td>
            <td class="meta">
                RESPONSIVA No.<br><strong>{{ $d['folio'] }}</strong><br>
                <span style="display:inline-block;margin-top:1mm;">Fecha de entrega: <strong style="background:none;color:#27332f;padding:0;">{{ $d['fecha'] }}</strong></span>
            </td>
        </tr>
    </table>
    <div class="franja"></div>

    <h1>{{ $d['titulo'] }}</h1>
    <div class="subtitulo">{{ $d['empresa'] }}</div>

    <p class="clausula">La persona que suscribe manifiesta recibir el {{ $eq }} identificado para el desempeño de sus funciones laborales y se obliga a utilizarlo para los fines autorizados, conservarlo en buen estado y devolverlo cuando le sea requerido, conforme a las disposiciones internas de la empresa y a la legislación laboral aplicable.</p>

    <p class="clausula">La presente responsiva se expide con fundamento en los artículos 132, fracción III; 134, fracciones I, III, IV y VI; y 135, fracción IX, de la Ley Federal del Trabajo, relativos a la obligación del patrón de proporcionar los instrumentos necesarios para el trabajo y a las obligaciones de la persona trabajadora respecto de su uso, cuidado, conservación y restitución.</p>

    <p class="clausula">La presente responsiva tiene como finalidad dejar constancia de la entrega, identificación, estado, custodia y devolución del {{ $eq }}, sin que implique responsabilidad automática de la persona trabajadora por el desgaste natural, caso fortuito, fuerza mayor o cualquier circunstancia que legalmente no le sea imputable.</p>

    {{-- Sección 1: común a las tres familias --}}
    <div class="seccion">
        <div class="titulo">1. Datos de quien recibe</div>
        <table class="campos">
            <tr><td class="etq">Nombre completo</td><td class="val">{{ $d['receptor']['nombre'] }}</td></tr>
            <tr><td class="etq">Puesto</td><td class="val">{{ $d['receptor']['puesto'] ?? '—' }}</td></tr>
            <tr><td class="etq">Área / Gerencia</td><td class="val">{{ $d['receptor']['area'] ?? '—' }}</td></tr>
            <tr><td class="etq">Sucursal / Centro de trabajo</td><td class="val">{{ $d['receptor']['sucursal'] ?? '—' }}</td></tr>
        </table>
    </div>

    {{-- Secciones específicas de la familia --}}
    @foreach ($d['secciones'] as $seccion)
        <div class="seccion">
            <div class="titulo">{{ $seccion['numero'] }}. {{ $seccion['titulo'] }}</div>
            @foreach ($seccion['bloques'] as $bloque)
                @if ($bloque['tipo'] === 'campos')
                    <table class="campos">
                        @foreach ($bloque['campos'] as $campo)
                            <tr><td class="etq">{{ $campo['etiqueta'] }}</td><td class="val">{{ $campo['valor'] }}</td></tr>
                        @endforeach
                    </table>
                @elseif ($bloque['tipo'] === 'opciones')
                    <div class="opciones">
                        @if (!empty($bloque['etiqueta']))
                            <span class="grupo-etq">{{ $bloque['etiqueta'] }}</span>
                        @endif
                        @foreach ($bloque['opciones'] as $opcion)
                            <span class="opc @if ($opcion['marcada']) on @endif"><span class="cb @if ($opcion['marcada']) on @endif"></span>{{ $opcion['etiqueta'] }}</span>
                        @endforeach
                    </div>
                @elseif ($bloque['tipo'] === 'texto')
                    <div class="texto-bloque">
                        <span class="etq">{{ $bloque['etiqueta'] }}</span>
                        {!! nl2br(e($bloque['valor'])) !!}
                    </div>
                @endif
            @endforeach
        </div>
    @endforeach

    @if ($d['observaciones'])
        <div class="texto-bloque">
            <span class="etq">Observaciones de la entrega</span>
            {!! nl2br(e($d['observaciones'])) !!}
        </div>
    @endif

    <div class="declaracion">
        <span class="etq">Declaración</span>
        {{ $d['declaracion'] }}
    </div>

    {{-- Firmas simétricas: a la izquierda el patrón (cargo «Representante
         legal» + razón social legal, nunca el nombre comercial); a la derecha
         el trabajador. Las etiquetas de rol van ARRIBA, al mismo nivel, y las
         líneas de firma quedan EXACTAMENTE al mismo nivel (misma fila). --}}
    <table class="firmas">
        <tr class="roles">
            <td><span class="rol">{{ $d['firma_patron_cargo'] }}</span></td>
            <td><span class="rol">Trabajador</span></td>
        </tr>
        <tr>
            <td class="espacio"></td>
            <td class="espacio"></td>
        </tr>
        <tr class="lineas">
            <td>
                <div class="linea">{{ $d['empresa'] }}</div>
            </td>
            <td>
                <div class="linea">{{ $d['receptor']['nombre'] }}</div>
                @if ($d['receptor']['puesto'])
                    <div class="puesto">{{ mb_strtoupper($d['receptor']['puesto'], 'UTF-8') }}</div>
                @endif
            </td>
        </tr>
    </table>
</body>
</html>
