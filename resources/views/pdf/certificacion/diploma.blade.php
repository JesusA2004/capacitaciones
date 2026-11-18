@php
    // Identidad institucional PEOPLE: paleta única en config/pdf_identidad.php.
    $pal = config('pdf_identidad');
    $chrome = ($motor ?? 'browsershot') === 'browsershot';
    $largo = mb_strlen((string) $d['nombre']);
    $tamanoNombre = $largo > 48 ? 21 : ($largo > 34 ? 26 : 34);
    $detalle = array_values(array_filter([$d['puesto'], $d['sucursal'] !== null ? 'Sucursal '.$d['sucursal'] : null]));
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Certificación Mr. Lana — {{ $d['nombre'] }}</title>
    <style>
        @page { size: letter landscape; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { font-family: {!! $chrome ? "'Montserrat', 'Segoe UI', Helvetica, Arial, sans-serif" : "Helvetica, Arial, sans-serif" !!}; color: {{ $pal['texto'] }}; }
        .hoja { position: relative; width: 279.4mm; height: 215.3mm; overflow: hidden; background: {{ $pal['crema'] }}; }
        .banda { position: absolute; left: 0; top: 0; width: 16mm; height: 215.3mm; background: {{ $pal['petroleo'] }}; }
        @if($chrome)
            .banda { background: linear-gradient(180deg, {{ $pal['petroleo_oscuro'] }} 0%, {{ $pal['petroleo'] }} 45%, {{ $pal['verde'] }} 100%); }
            .hoja { background: radial-gradient(circle at 85% 12%, {{ $pal['fondo_total'] }} 0%, {{ $pal['crema'] }} 38%, {{ $pal['superficie'] }} 100%); }
        @endif
        .filete { position: absolute; left: 16mm; top: 0; width: 1.6mm; height: 215.3mm; background: {{ $pal['dorado'] }}; }
        .circulo { position: absolute; border-radius: 50%; }
        .c1 { right: -38mm; top: -42mm; width: 120mm; height: 120mm; border: 14mm solid {{ $pal['salvia'] }}; }
        .c3 { left: 4mm; bottom: 18mm; width: 8mm; height: 8mm; background: {{ $pal['dorado'] }}; }
        .marco { position: absolute; left: 26mm; top: 10mm; width: 243.4mm; height: 195.3mm; border: 0.5mm solid {{ $pal['dorado'] }}; border-radius: 4mm; }
        .contenido { position: absolute; left: 26mm; width: 243.4mm; text-align: center; }
        .logo { position: absolute; left: 36mm; top: 18mm; height: 15mm; }
        .folio-sup { position: absolute; right: 18mm; top: 21mm; text-align: right; font-size: 8pt; letter-spacing: 1.5pt; text-transform: uppercase; color: {{ $pal['texto_tenue'] }}; }
        .folio-sup strong { display: block; font-size: 10pt; letter-spacing: 1pt; color: {{ $pal['petroleo'] }}; }
        .ceja { top: 44mm; font-size: 9pt; font-weight: bold; letter-spacing: 3.5pt; text-transform: uppercase; color: {{ $pal['bronce'] }}; }
        .titulo { top: 52mm; font-size: 29pt; font-weight: bold; color: {{ $pal['petroleo'] }}; letter-spacing: -0.3pt; }
        .otorga { top: 73mm; font-size: 11pt; color: {{ $pal['texto_suave'] }}; }
        .nombre { top: 81mm; font-size: {{ $tamanoNombre }}pt; font-weight: bold; color: {{ $pal['texto'] }}; }
        .linea-nombre { position: absolute; top: 101mm; left: 87.7mm; width: 120mm; height: 0.7mm; background: {{ $pal['dorado'] }}; }
        .detalle { top: 105mm; font-size: 11pt; font-weight: bold; color: {{ $pal['verde'] }}; letter-spacing: 0.4pt; }
        .texto { top: 116mm; left: 62mm; width: 171.4mm; font-size: 10.5pt; line-height: 1.5; color: {{ $pal['texto_suave'] }}; }
        .texto strong { color: {{ $pal['texto'] }}; }
        .pie { position: absolute; top: 146mm; left: 36mm; width: 223.4mm; }
        .pie table { width: 100%; border-collapse: collapse; }
        .pie td { vertical-align: middle; text-align: center; }
        .dato-etiqueta { font-size: 7.5pt; letter-spacing: 1.8pt; text-transform: uppercase; color: {{ $pal['texto_tenue'] }}; }
        .dato-valor { margin-top: 1.5mm; font-size: 12pt; font-weight: bold; color: {{ $pal['petroleo'] }}; }
        .sello { margin: 0 auto; width: 36mm; height: 36mm; border-radius: 50%; background: {{ $pal['petroleo'] }}; border: 1.4mm solid {{ $pal['dorado'] }}; color: {{ $pal['blanco'] }}; text-align: center; }
        .sello .aire { height: 6mm; }
        .sello .palomita { font-family: 'DejaVu Sans', sans-serif; font-size: 15pt; color: {{ $pal['dorado'] }}; line-height: 1; }
        .sello .s1 { margin-top: 1mm; font-size: 8.5pt; font-weight: bold; letter-spacing: 1.6pt; }
        .sello .s2 { margin-top: 0.6mm; font-size: 6.5pt; letter-spacing: 1.4pt; color: {{ $pal['alerta_suave'] }}; }
        .firma-linea { margin: 0 auto; width: 80mm; border-top: 0.35mm solid {{ $pal['texto'] }}; padding-top: 1.8mm; }
        .firma-empresa { font-size: 7.5pt; font-weight: bold; color: {{ $pal['texto'] }}; }
        .firma-cargo { margin-top: 0.6mm; font-size: 8pt; color: {{ $pal['texto_suave'] }}; }
        .verifica { position: absolute; left: 36mm; top: 189mm; width: 190mm; font-size: 7.5pt; color: {{ $pal['texto_tenue'] }}; text-align: left; }
        .verifica strong { color: {{ $pal['petroleo'] }}; }
        .qr { position: absolute; right: 18mm; top: 181mm; width: 21mm; height: 21mm; }
    </style>
</head>
<body>
<div class="hoja">
    <div class="banda"></div>
    <div class="filete"></div>
    <div class="circulo c1"></div>
    <div class="circulo c3"></div>
    <div class="marco"></div>

    @if($d['logo'])
        <img class="logo" src="{{ $d['logo'] }}" alt="Mr. Lana">
    @endif

    <div class="folio-sup">
        Folio
        <strong>{{ $d['folio'] }}</strong>
    </div>

    <div class="contenido ceja">Certificación Mr. Lana · Programa de capacitación</div>
    <div class="contenido titulo">¡Cuentas con tu Certificación Mr. Lana!</div>
    <div class="contenido otorga">Se otorga con orgullo a</div>
    <div class="contenido nombre">{{ $d['nombre'] }}</div>
    <div class="linea-nombre"></div>
    @if($detalle !== [])
        <div class="contenido detalle">{{ implode('  ·  ', $detalle) }}</div>
    @endif
    <div class="contenido texto">
        por acreditar el <strong>Programa de capacitación para Certificación Mr Lana</strong>
        ({{ $d['formato'] }})@if($d['promedio'] !== null) con un promedio de <strong>{{ $d['promedio'] }}</strong>@endif,
        demostrando su dominio de la cultura Mr. Lana, las políticas de crédito, el otorgamiento,
        la cobranza y el trabajo en campo.
    </div>

    <div class="pie">
        <table>
            <tr>
                <td style="width: 36%;">
                    <div class="dato-etiqueta">Fecha de certificación</div>
                    <div class="dato-valor">{{ $d['fecha'] }}</div>
                </td>
                <td style="width: 28%;">
                    <div class="sello">
                        <div class="aire"></div>
                        <div class="palomita">&#10003;</div>
                        <div class="s1">CERTIFICADO</div>
                        <div class="s2">MR. LANA</div>
                    </div>
                </td>
                <td style="width: 36%; padding-top: 10mm;">
                    <div class="firma-linea">
                        <div class="firma-empresa">{{ $d['empresa'] }}</div>
                        <div class="firma-cargo">{{ $d['cargo'] }}</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="verifica">
        Verifica la autenticidad de este certificado con el folio <strong>{{ $d['folio'] }}</strong> en
        {{ $d['url'] }}
    </div>
    @if($d['qr'])
        <img class="qr" src="{{ $d['qr'] }}" alt="QR de verificación">
    @endif
</div>
</body>
</html>
