{{--
    CARTA COMPROMISO DE DOCUMENTOS PENDIENTES — MR. LANA PEOPLE.

    Una sola carta con todos los documentos que la persona marcó «No tengo
    este documento» al registrarse en la app. Datos en $d
    (App\Services\Expedientes\CartaCompromisoService::datos): solo texto, sin
    IDs ni el nombre de quien capturó. Las piezas vacías (CURP, número…) se
    omiten. Fondo blanco; la paleta institucional (config/pdf_identidad.php)
    solo como acento. Chrome y DomPDF lo imprimen igual (sin fuentes remotas).
--}}
@php
    $c = config('pdf_identidad');
    $identificacion = array_values(array_filter([
        $d['puesto'] ? ['con el puesto de', $d['puesto']] : null,
        $d['sucursal'] ? ['en la sucursal', $d['sucursal']] : null,
        $d['numero_empleado'] ? ['número de colaborador', $d['numero_empleado']] : null,
        $d['curp'] ? ['CURP', $d['curp']] : null,
    ]));
    // «con el puesto de X, en la sucursal Y, número de colaborador N y CURP C»
    // (cada valor escapado; solo las piezas que existen).
    $partes = array_map(fn (array $p): string => sprintf('%s <strong>%s</strong>', e($p[0]), e($p[1])), $identificacion);
    $ultima = array_pop($partes);
    $identificacionHtml = $ultima === null ? '' : ($partes === [] ? $ultima : sprintf('%s y %s', implode(', ', $partes), $ultima));

    // Redacción explícita según cuántos documentos cubre la carta (1 o 2 por
    // regla; se conserva una redacción general para cartas anteriores).
    $documentos = array_values($d['documentos']);
    $total = count($documentos);
    $numeros = [2 => 'dos', 3 => 'tres', 4 => 'cuatro', 5 => 'cinco', 6 => 'seis', 7 => 'siete', 8 => 'ocho', 9 => 'nueve', 10 => 'diez'];
    $introDocumentos = $total === 1
        ? 'el siguiente documento'
        : sprintf('los siguientes %s documentos', $numeros[$total] ?? (string) $total);
    // «Acta de nacimiento» / «Acta de nacimiento» y «Comprobante de domicilio».
    $nombres = array_map(fn (string $n): string => sprintf('<strong>«%s»</strong>', e($n)), $documentos);
    $ultimoNombre = array_pop($nombres);
    $nombresHtml = $nombres === [] ? (string) $ultimoNombre : sprintf('%s y %s', implode(', ', $nombres), $ultimoNombre);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Carta compromiso {{ $d['folio'] }}</title>
    <style>
        @page {
            @if ($motor === 'browsershot') size: Letter portrait; @endif
            margin: 16mm 24mm 22mm 24mm;
        }
        * { box-sizing: border-box; }
        html { background: {{ $c['blanco'] }}; }
        body { margin: 0; padding: 0; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 10.3pt; line-height: 1.55; color: {{ $c['texto'] }}; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        p { margin: 0 0 3.6mm; text-align: justify; orphans: 3; widows: 3; }
        strong { color: #111111; }
        table { width: 100%; border-collapse: collapse; }

        /* Marca de agua: el mismo logo, grande y casi transparente, detrás del texto. */
        .agua { position: fixed; top: 50%; left: 50%; width: 125mm; margin-left: -62.5mm; margin-top: -21mm; opacity: 0.06; z-index: -1; }
        .agua img { width: 100%; }

        /* Membrete */
        .membrete td { vertical-align: middle; padding: 0; }
        .logo { height: 15mm; }
        .folio { text-align: right; }
        .folio .etq { display: block; font-size: 7pt; font-variant: small-caps; letter-spacing: 1.4pt; color: {{ $c['texto_tenue'] }}; text-transform: lowercase; }
        .folio .valor { display: block; margin-top: 0.4mm; font-size: 10pt; font-weight: bold; letter-spacing: 0.8pt; color: {{ $c['petroleo'] }}; }
        .filete { margin: 5mm 0 0; border-top: 0.35mm solid {{ $c['petroleo'] }}; }
        .filete-dorado { width: 22mm; margin: -0.35mm 0 0; border-top: 0.9mm solid {{ $c['dorado'] }}; }

        /* Título */
        h1 { margin: 7mm 0 0; text-align: center; font-size: 19pt; font-weight: 800; letter-spacing: 4.5pt; text-transform: uppercase; color: #111111; }
        .acento { width: 14mm; margin: 3.2mm auto 2.6mm; border-top: 0.8mm solid {{ $c['dorado'] }}; }
        .subtitulo { text-align: center; font-size: 8pt; letter-spacing: 1.6pt; text-transform: uppercase; color: {{ $c['texto_suave'] }}; }

        .lugar-fecha { margin: 5mm 0 4.5mm; text-align: right; font-size: 10pt; color: {{ $c['texto'] }}; }

        /* Lista de documentos */
        .documentos { margin: 1mm 0 5mm; }
        .documentos td { padding: 1.7mm 0; border-bottom: 0.2mm solid {{ $c['borde_claro'] }}; vertical-align: top; font-size: 10.2pt; }
        .documentos tr:first-child td { border-top: 0.2mm solid {{ $c['borde_claro'] }}; }
        .documentos .num { width: 11mm; font-weight: bold; font-size: 8.6pt; letter-spacing: 0.6pt; color: {{ $c['petroleo'] }}; padding-top: 2mm; }
        .documentos .nombre { color: #111111; }

        /* Plazo */
        .plazo { margin: 0 0 5.5mm; }
        .plazo td { width: 50%; padding: 1.8mm 4mm; vertical-align: top; border-left: 0.6mm solid {{ $c['dorado'] }}; }
        .plazo td + td { border-left: 0.6mm solid {{ $c['petroleo'] }}; }
        .plazo .etq { display: block; font-size: 7pt; letter-spacing: 1.3pt; text-transform: uppercase; color: {{ $c['texto_tenue'] }}; }
        .plazo .valor { display: block; margin-top: 0.6mm; font-size: 10.4pt; font-weight: bold; color: #111111; }

        /* Firma y huella */
        .firma { margin-top: 2mm; page-break-inside: avoid; }
        .firma .atentamente { text-align: center; font-size: 8pt; letter-spacing: 3pt; text-transform: uppercase; color: {{ $c['texto_suave'] }}; margin-bottom: 3mm; }
        .firma td { vertical-align: bottom; }
        .firma .bloque { width: 62%; padding-right: 12mm; text-align: center; }
        .firma .linea { border-top: 0.3mm solid #111111; padding-top: 2mm; font-weight: bold; font-size: 10pt; color: #111111; letter-spacing: 0.4pt; }
        .firma .rol { margin-top: 0.8mm; font-size: 7.6pt; letter-spacing: 1.2pt; text-transform: uppercase; color: {{ $c['texto_suave'] }}; }
        .firma .huella { width: 38%; text-align: center; }
        .caja-huella { width: 22mm; height: 26mm; margin: 0 auto; border: 0.3mm solid {{ $c['borde'] }}; border-radius: 2.5mm; }
        .firma .huella .rol { margin-top: 2mm; }

        .marca { position: fixed; top: 46%; left: 0; width: 100%; text-align: center; transform: rotate(-30deg); font-size: 46pt; font-weight: bold; letter-spacing: 8pt; color: rgba(49, 91, 89, 0.12); z-index: 10; }
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
                <span class="etq">Folio</span>
                <span class="valor">{{ $d['folio'] }}</span>
            </td>
        </tr>
    </table>
    <div class="filete"></div>
    <div class="filete-dorado"></div>

    <h1>Carta compromiso</h1>
    <div class="acento"></div>
    <div class="subtitulo">Entrega de documentos del expediente laboral</div>

    <div class="lugar-fecha">{{ $d['lugar_fecha'] }}</div>

    <p>
        Yo, <strong>{{ $d['nombre'] }}</strong>, {{ $d['tratamiento'] }} de <strong>{{ $d['empresa'] }}</strong>{!! $identificacionHtml !== '' ? ', '.$identificacionHtml : '' !!}, me comprometo a entregar, a más tardar el <strong>{{ $d['fecha_limite'] }}</strong>, {{ $introDocumentos }} para integrar mi expediente laboral:
    </p>

    <table class="documentos">
        @foreach ($documentos as $i => $nombre)
            <tr>
                <td class="num">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</td>
                <td class="nombre">{{ $nombre }}</td>
            </tr>
        @endforeach
    </table>

    <table class="plazo">
        <tr>
            <td>
                <span class="etq">Plazo de entrega</span>
                <span class="valor">{{ $d['dias_plazo'] }} días naturales</span>
            </td>
            <td>
                <span class="etq">Fecha límite</span>
                <span class="valor">{{ $d['fecha_limite'] }}</span>
            </td>
        </tr>
    </table>

    <p>
        @if ($total === 1)
            Reconozco que este documento es necesario para que la empresa cumpla con sus obligaciones laborales, fiscales y de seguridad social. En caso de no entregar {!! $nombresHtml !!} dentro del plazo señalado, acepto que mi acceso a la aplicación MR. LANA PEOPLE quede restringido únicamente a la carga de ese documento, hasta que mi expediente quede completo.
        @elseif ($total === 2)
            Reconozco que estos documentos son necesarios para que la empresa cumpla con sus obligaciones laborales, fiscales y de seguridad social. En caso de no entregar {!! $nombresHtml !!} dentro del plazo señalado, acepto que mi acceso a la aplicación MR. LANA PEOPLE quede restringido únicamente a la carga de esos documentos, hasta que mi expediente quede completo.
        @else
            Reconozco que estos documentos son necesarios para que la empresa cumpla con sus obligaciones laborales, fiscales y de seguridad social. En caso de no entregarlos dentro del plazo señalado, acepto que mi acceso a la aplicación MR. LANA PEOPLE quede restringido únicamente a la carga de los documentos pendientes, hasta que mi expediente quede completo.
        @endif
    </p>

    <p>
        Manifiesto que he leído y comprendido el contenido de la presente carta y que la firmo de conformidad, de manera libre y voluntaria.
    </p>

    <div class="firma">
        <div class="atentamente">Atentamente</div>
        <table>
            <tr>
                <td class="bloque">
                    <div class="linea">{{ $d['nombre'] }}</div>
                    <div class="rol">Nombre y firma</div>
                </td>
                <td class="huella">
                    <div class="caja-huella"></div>
                    <div class="rol">Huella digital</div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
