{{--
    RESPONSIVA DE EQUIPO — formato generado por MR. LANA PEOPLE.

    Fuente: docs/responsiva/ResponsivaLaptopPanta.docx (carta, membrete
    Mr. Lana, lugar y fecha, destinatario, declaración del receptor, tabla
    «Nombre de la característica | Descripción detallada», cláusulas y
    firmas Receptor / Otorgante). Las cláusulas se conservan literales; solo
    cambia la denominación («equipo de cómputo» o «equipo de trabajo») según
    el tipo de responsiva. Todo dato sale de $d
    (App\Services\Responsivas\ResponsivaPdfService::datos). Solo tablas y
    bloques: Chrome y DomPDF lo imprimen igual. Sin fuentes remotas.
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
            margin: 14mm 19mm 22mm 19mm;
        }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: Arial, 'Helvetica Neue', Helvetica, sans-serif; font-size: 10pt; line-height: 1.5; color: #1f2933; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        p { margin: 0 0 3mm; text-align: justify; orphans: 3; widows: 3; }
        table { width: 100%; border-collapse: collapse; }

        /* Membrete: logo + franja con los colores de la marca */
        .membrete td { vertical-align: middle; }
        .logo { height: 12mm; }
        .folio { text-align: right; font-size: 8.4pt; color: #5b6b73; line-height: 1.4; }
        .folio strong { display: inline-block; margin-top: 0.8mm; padding: 0.8mm 3mm; border-radius: 3mm; background: #e9f6f8; color: #0d4f5c; font-size: 9pt; letter-spacing: 0.4pt; }
        .franja { height: 1.1mm; margin: 4mm 0 7mm; border-radius: 1mm; background-color: #29a9c2; background-image: linear-gradient(90deg, #29a9c2 0%, #5bb88a 55%, #8cbf3f 100%); }

        /* Marca de agua del membrete */
        .agua { position: fixed; top: 38%; left: 8%; width: 84%; opacity: 0.04; z-index: -1; }
        .agua img { width: 100%; }

        h1 { margin: 0 0 1.5mm; text-align: center; font-size: 14pt; letter-spacing: 2pt; color: #0d3e43; }
        .subtitulo { text-align: center; font-size: 8.6pt; color: #5b6b73; letter-spacing: 0.6pt; margin-bottom: 6mm; }

        /* Ficha del receptor */
        .ficha { margin-bottom: 6mm; border: 0.25mm solid #d7e3e6; border-radius: 2mm; }
        .ficha td { width: 25%; padding: 2.2mm 3mm; vertical-align: top; border-right: 0.25mm solid #e6eef0; }
        .ficha td:last-child { border-right: none; }
        .ficha .etq { display: block; font-size: 6.8pt; letter-spacing: 0.7pt; text-transform: uppercase; color: #7a8a91; }
        .ficha .val { display: block; margin-top: 0.6mm; font-size: 9pt; font-weight: bold; color: #1f2933; }

        .fecha { text-align: right; margin-bottom: 6mm; }
        .destinatario { margin-bottom: 6mm; font-weight: bold; line-height: 1.45; }
        .destinatario div { text-align: left; }

        /* Equipos */
        .equipo { margin: 1mm 0 5mm; border: 0.25mm solid #d7e3e6; border-radius: 2mm; page-break-inside: avoid; }
        .equipo th { padding: 2mm 3mm; text-align: left; font-size: 8pt; letter-spacing: 0.5pt; text-transform: uppercase; color: #0d4f5c; background: #e9f6f8; border-bottom: 0.25mm solid #d7e3e6; }
        .equipo td { padding: 1.7mm 3mm; font-size: 9.4pt; border-bottom: 0.2mm solid #edf2f3; vertical-align: top; }
        .equipo tr:last-child td { border-bottom: none; }
        .equipo .nombre { width: 38%; color: #5b6b73; }
        .equipo .principal td { font-weight: bold; color: #0d3e43; }

        .observaciones { margin: 0 0 6mm; padding: 2.5mm 3.5mm; border-left: 0.8mm solid #8cbf3f; background: #f6faf1; font-size: 9.2pt; page-break-inside: avoid; }
        .observaciones .etq { font-size: 7pt; letter-spacing: 0.7pt; text-transform: uppercase; color: #5b6b73; margin-bottom: 0.8mm; }

        .clausula { margin-bottom: 3.2mm; }
        .fuerte { font-weight: bold; }

        /* Firmas */
        .firmas { margin-top: 12mm; page-break-inside: avoid; }
        .firmas td { width: 50%; padding: 0 8mm; vertical-align: bottom; text-align: center; }
        .firmas .rol { display: inline-block; padding: 0.8mm 4mm; border-radius: 3mm; background: #e9f6f8; color: #0d4f5c; font-size: 8pt; font-weight: bold; letter-spacing: 1pt; text-transform: uppercase; }
        .firmas .espacio { height: 22mm; }
        .firmas .linea { border-top: 0.3mm solid #1f2933; padding-top: 1.8mm; font-weight: bold; font-size: 9.4pt; }
        .firmas .puesto { font-size: 8.4pt; color: #5b6b73; font-weight: bold; }

        /* Vista previa: marca diagonal suave que no tapa el texto */
        .marca { position: fixed; top: 44%; left: 0; width: 100%; text-align: center; transform: rotate(-30deg); font-size: 46pt; font-weight: bold; letter-spacing: 8pt; color: rgba(41, 169, 194, 0.14); z-index: 10; }
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
            <td class="folio">
                Folio<br><strong>{{ $d['folio'] }}</strong>
            </td>
        </tr>
    </table>
    <div class="franja"></div>

    <h1>{{ $d['titulo'] }}</h1>
    <div class="subtitulo">{{ $d['empresa'] }}</div>

    <table class="ficha">
        <tr>
            <td><span class="etq">Receptor</span><span class="val">{{ $d['receptor']['nombre'] }}</span></td>
            <td><span class="etq">No. de empleado</span><span class="val">{{ $d['receptor']['numero_empleado'] ?? 'Sin asignar' }}</span></td>
            <td><span class="etq">Puesto</span><span class="val">{{ $d['receptor']['puesto'] ?? 'Sin puesto' }}</span></td>
            <td><span class="etq">Sucursal</span><span class="val">{{ $d['receptor']['sucursal'] ?? 'Sin sucursal' }}</span></td>
        </tr>
    </table>

    <div class="fecha">{{ $d['lugar'] }}, a {{ $d['fecha'] }}.</div>

    <div class="destinatario">
        <div>A QUIEN CORRESPONDA</div>
        <div>DIRECTOR GENERAL DE</div>
        <div>{{ $d['empresa'] }}</div>
        <div>PRESENTE.</div>
    </div>

    <p>Yo, <strong>{{ $d['receptor']['nombre'] }}</strong>, en mi calidad de receptor, con base en el contrato individual de trabajo, ante usted manifiesto que tengo pleno conocimiento de lo siguiente:</p>

    <p>Que el patrón es propietario del {{ $eq }} que se describe a continuación, mismo que está destinado para cumplir con las actividades de su objeto social:</p>

    @foreach ($d['activos'] as $activo)
        <table class="equipo">
            <thead>
                <tr><th class="nombre">Nombre de la característica</th><th>Descripción detallada</th></tr>
            </thead>
            <tbody>
                @foreach ($activo['filas'] as $i => $fila)
                    <tr @class(['principal' => $i === 0])>
                        <td class="nombre">{{ $fila[0] }}</td>
                        <td>{{ $fila[1] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    @if ($d['observaciones'])
        <div class="observaciones">
            <div class="etq">Observaciones de la entrega</div>
            {!! nl2br(e($d['observaciones'])) !!}
        </div>
    @endif

    <p class="clausula">Que el patrón, de conformidad con lo establecido por el artículo 32, fracción III de la Ley Federal del Trabajo, proporciona al suscrito el equipo descrito, por lo que este acto se entrega en buen estado, con las condiciones descritas en la presente responsiva.</p>

    <p class="clausula">Que el suscrito recibe el {{ $eq }} y se obliga en los términos del artículo 134, fracción VI de la Ley Federal del Trabajo, a conservarlo en buen estado y a devolverlo al patrón cuando ésta así lo requiera o cuando se suspenda o se dé por terminada la relación de trabajo.</p>

    <p class="clausula">Que el equipo proporcionado al suscrito es para el uso exclusivo de mi trabajo y no podré prestarlo a terceros, ni utilizarlo para asuntos personales.</p>

    <p class="clausula">Que, para el correcto uso y el mantenimiento del {{ $eq }}, el suscrito me comprometo a seguir las instrucciones del patrón.</p>

    <p class="clausula">Que está estrictamente prohibido al suscrito modificar o alterar el estado y forma del {{ $eq }}, a menos que haya autorización expresa y por escrito del patrón.</p>

    <p class="clausula">Que el patrón cubrirá los servicios del {{ $eq }}.</p>

    <p class="clausula">Que el suscrito será responsable de los daños o averías del {{ $eq }}, de la pérdida completa de este, de su robo; derivado del uso indebido, error o negligencia. Para ello, deberé pagar la reparación, la sustitución o cualquier otro pago que deba cubrir el patrón, derivado de mi conducta.</p>

    <p class="clausula fuerte">Debido a ello, el suscrito desde este momento autorizo al patrón para que se realicen los descuentos de mi nómina, en los términos de la Ley Federal del Trabajo.</p>

    <p class="clausula">Que cuando por la naturaleza del trabajo que realice el suscrito se vuelva innecesaria el uso del {{ $eq }}, o bien, el patrón establezca diferentes políticas de uso y designación, o a juicio del patrón deba retirarse la designación de manera definitiva, el suscrito deberá entregar el {{ $eq }}.</p>

    <p class="clausula">Que en caso de que el suscrito dejare de prestar mis servicios al patrón, devolveré el {{ $eq }} y cubriré las cantidades que adeudare con motivo de las obligaciones contraídas por la presente responsiva, mismas que podrán ser descontadas del finiquito definitivo que me entregue el patrón, por lo que desde este momento otorgo mi consentimiento para tal efecto.</p>

    <table class="firmas">
        <tr>
            <td><span class="rol">Receptor</span></td>
            <td><span class="rol">Otorgante</span></td>
        </tr>
        <tr>
            <td class="espacio"></td>
            <td class="espacio"></td>
        </tr>
        <tr>
            <td>
                <div class="linea">{{ $d['receptor']['nombre'] }}</div>
                @if ($d['receptor']['puesto'])
                    <div class="puesto">{{ mb_strtoupper($d['receptor']['puesto'], 'UTF-8') }}</div>
                @endif
            </td>
            <td>
                <div class="linea">{{ $d['otorgante']['nombre'] }}</div>
                @if ($d['otorgante']['puesto'])
                    <div class="puesto">{{ $d['otorgante']['puesto'] }}</div>
                @endif
            </td>
        </tr>
    </table>
</body>
</html>
