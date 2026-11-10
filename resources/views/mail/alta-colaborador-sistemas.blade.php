@php
    /*
     * Correo «Alta en sistemas» (AltaColaboradorSistemasMail).
     * Correo HTML: maquetado con TABLAS, estilos EN LÍNEA, ancho máx. 600 px,
     * sin SVG ni fuentes de íconos (Gmail/Outlook no los muestran): el logo
     * es PNG con URL absoluta y los íconos son emoji. Colores = paleta de
     * marca de resources/css/app.css (un correo no puede leer variables CSS).
     */
    $c = [
        'fondo' => '#fbf8f2',
        'tarjeta' => '#ffffff',
        'crema' => '#f3e8d3',
        'valor' => '#f7f2ea',
        'borde' => '#e7ded1',
        'petroleo' => '#315b59',
        'petroleoClaro' => '#dce8df',
        'oro' => '#c7a66b',
        'texto' => '#303a38',
        'texto2' => '#5f6b68',
        'aviso' => '#f3e3b8',
        'avisoTexto' => '#8a6a36',
    ];
    $f = 'font-family:Arial,Helvetica,sans-serif;';
    $mono = "font-family:Consolas,'Courier New',Courier,monospace;";
    $iconosSeccion = ['persona' => '👤', 'contacto' => '📞', 'puesto' => '💼'];
    $iconos = [
        'Nombre completo' => '👤', 'CURP' => '🪪', 'RFC' => '🧾', 'NSS' => '🏥',
        'Fecha de nacimiento' => '🎂', 'Género' => '🚻', 'Teléfono' => '📱',
        'Correo personal' => '✉️', 'Correo corporativo' => '📧',
        'Número de empleado' => '🔢', 'Puesto' => '💼', 'Departamento' => '🗂️',
        'Sucursal' => '📍', 'Empresa (razón social)' => '🏢', 'Jefe directo' => '👔',
        'Fecha de ingreso' => '📅', 'Tipo de contratación' => '📝', 'Usuario de PEOPLE' => '🔑',
    ];
    $esReingreso = $tipoIngreso === 'Reingreso';
@endphp
<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>Alta en sistemas: {{ $nombreCompleto }}</title>
</head>
<body style="margin:0;padding:0;background-color:{{ $c['fondo'] }};{{ $f }}color:{{ $c['texto'] }};-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">{{ $tipoIngreso }}: {{ $nombreCompleto }} · {{ $puesto }}, {{ $sucursal }}. Ingreso {{ $fechaIngresoCorta }}. Datos para darle de alta en los demás sistemas.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $c['fondo'] }}" style="background-color:{{ $c['fondo'] }};">
<tr>
<td align="center" style="padding:24px 10px;">
<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">

    {{-- Logo --}}
    <tr>
        <td align="center" bgcolor="{{ $c['tarjeta'] }}" style="background-color:{{ $c['tarjeta'] }};padding:20px 24px 14px 24px;border:1px solid {{ $c['borde'] }};border-bottom:0;border-radius:16px 16px 0 0;">
            <img src="{{ $logoUrl }}" width="200" height="60" alt="Mr. Lana PEOPLE" style="display:block;width:200px;max-width:200px;height:auto;border:0;outline:none;text-decoration:none;{{ $f }}font-size:18px;color:{{ $c['petroleo'] }};">
        </td>
    </tr>

    {{-- Encabezado petróleo --}}
    <tr>
        <td bgcolor="{{ $c['petroleo'] }}" style="background-color:{{ $c['petroleo'] }};padding:22px 24px 24px 24px;border-top:4px solid {{ $c['oro'] }};">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td bgcolor="{{ $c['oro'] }}" style="background-color:{{ $c['oro'] }};border-radius:999px;padding:5px 12px;{{ $f }}font-size:12px;font-weight:bold;color:{{ $c['petroleo'] }};letter-spacing:0.5px;">{{ $esReingreso ? '🔁' : '✨' }}&nbsp;{{ mb_strtoupper($tipoIngreso) }}</td>
                    <td style="padding-left:10px;{{ $f }}font-size:12px;color:{{ $c['petroleoClaro'] }};">🖥️ Alta en sistemas</td>
                </tr>
            </table>
            <h1 style="margin:14px 0 4px 0;{{ $f }}font-size:24px;line-height:30px;font-weight:bold;color:#ffffff;">{{ $nombreCompleto }}</h1>
            <p style="margin:0 0 18px 0;{{ $f }}font-size:15px;line-height:21px;color:{{ $c['petroleoClaro'] }};">💼 {{ $puesto }} &nbsp;·&nbsp; 📍 {{ $sucursal }}</p>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                    <td bgcolor="#3e6b68" style="background-color:#3e6b68;border-radius:12px;padding:12px 16px;">
                        <p style="margin:0;{{ $f }}font-size:12px;letter-spacing:1px;text-transform:uppercase;color:{{ $c['petroleoClaro'] }};">📅 Fecha de ingreso</p>
                        <p style="margin:2px 0 0 0;{{ $f }}font-size:26px;line-height:32px;font-weight:bold;color:#ffffff;">{{ $fechaIngresoLarga }}</p>
                        @if ($fechaIngresoCorta !== $fechaIngresoLarga)
                            <p style="margin:0;{{ $mono }}font-size:14px;color:{{ $c['oro'] }};">{{ $fechaIngresoCorta }}</p>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Cuerpo --}}
    <tr>
        <td bgcolor="{{ $c['tarjeta'] }}" style="background-color:{{ $c['tarjeta'] }};padding:22px 24px 8px 24px;border-left:1px solid {{ $c['borde'] }};border-right:1px solid {{ $c['borde'] }};">
            <p style="margin:0;{{ $f }}font-size:15px;line-height:22px;color:{{ $c['texto'] }};">✅ Dale de alta en los demás sistemas y márcalo como <strong>dado de alta</strong> en PEOPLE.</p>
            @if ($faltantes > 0)
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:12px;">
                    <tr>
                        <td bgcolor="{{ $c['aviso'] }}" style="background-color:{{ $c['aviso'] }};border-radius:10px;padding:10px 12px;{{ $f }}font-size:13px;line-height:19px;color:{{ $c['avisoTexto'] }};">⚠️ {{ $faltantes === 1 ? 'Falta 1 dato' : sprintf('Faltan %d datos', $faltantes) }} («Sin capturar»): RH los completa durante la incorporación.</td>
                    </tr>
                </table>
            @endif

            {{-- Botón principal --}}
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:18px 0 6px 0;">
                <tr>
                    <td align="center">
                        <!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" href="{{ $urlDetalle }}" style="height:48px;v-text-anchor:middle;width:300px;" arcsize="20%" stroke="f" fillcolor="{{ $c['petroleo'] }}"><center style="color:#ffffff;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">📋 Abrir y copiar datos</center></v:roundrect><![endif]-->
                        <!--[if !mso]><!-->
                        <a href="{{ $urlDetalle }}" target="_blank" style="display:inline-block;background-color:{{ $c['petroleo'] }};border-bottom:3px solid {{ $c['oro'] }};border-radius:10px;padding:14px 28px;{{ $f }}font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;">📋&nbsp; Abrir y copiar datos</a>
                        <!--<![endif]-->
                        <p style="margin:8px 0 0 0;{{ $f }}font-size:12px;color:{{ $c['texto2'] }};">Cada dato con su botón «Copiar» y «Copiar todo».</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Tarjetas de datos --}}
    @foreach ($secciones as $clave => $seccion)
        <tr>
            <td bgcolor="{{ $c['tarjeta'] }}" style="background-color:{{ $c['tarjeta'] }};padding:12px 24px;border-left:1px solid {{ $c['borde'] }};border-right:1px solid {{ $c['borde'] }};">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid {{ $c['borde'] }};border-radius:12px;border-collapse:separate;">
                    <tr>
                        <td colspan="2" bgcolor="{{ $c['crema'] }}" style="background-color:{{ $c['crema'] }};border-radius:11px 11px 0 0;padding:10px 14px;{{ $f }}font-size:15px;font-weight:bold;color:{{ $c['petroleo'] }};">{{ $iconosSeccion[$clave] ?? '•' }}&nbsp; {{ $seccion['titulo'] }}</td>
                    </tr>
                    @foreach ($seccion['datos'] as $etiqueta => $valor)
                        @php($falta = $valor === 'Sin capturar')
                        <tr>
                            <td width="40%" valign="middle" style="padding:8px 8px 8px 14px;{{ $f }}font-size:13px;line-height:18px;color:{{ $c['texto2'] }};{{ $loop->last ? '' : 'border-bottom:1px solid '.$c['borde'].';' }}">{{ $iconos[$etiqueta] ?? '•' }}&nbsp; {{ $etiqueta }}</td>
                            <td valign="middle" style="padding:6px 14px 6px 0;{{ $loop->last ? '' : 'border-bottom:1px solid '.$c['borde'].';' }}">
                                @if ($falta)
                                    <span style="{{ $f }}font-size:13px;font-style:italic;color:{{ $c['avisoTexto'] }};">Sin capturar</span>
                                @else
                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td bgcolor="{{ $c['valor'] }}" style="background-color:{{ $c['valor'] }};border-radius:6px;padding:6px 9px;{{ $mono }}font-size:14px;line-height:19px;color:{{ $c['texto'] }};word-break:break-word;">{{ $valor }}</td></tr></table>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    @endforeach

    {{-- Para copiar y pegar --}}
    <tr>
        <td bgcolor="{{ $c['tarjeta'] }}" style="background-color:{{ $c['tarjeta'] }};padding:12px 24px 20px 24px;border-left:1px solid {{ $c['borde'] }};border-right:1px solid {{ $c['borde'] }};">
            <p style="margin:0 0 6px 0;{{ $f }}font-size:14px;font-weight:bold;color:{{ $c['petroleo'] }};">📝 Para copiar y pegar</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td bgcolor="{{ $c['valor'] }}" style="background-color:{{ $c['valor'] }};border:1px dashed {{ $c['oro'] }};border-radius:10px;padding:12px 14px;{{ $mono }}font-size:13px;line-height:20px;color:{{ $c['texto'] }};word-break:break-word;">
                        @foreach ($lineasCopiar as $linea)
                            {{ $linea }}<br>
                        @endforeach
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Pie --}}
    <tr>
        <td bgcolor="{{ $c['tarjeta'] }}" style="background-color:{{ $c['tarjeta'] }};padding:16px 24px 22px 24px;border:1px solid {{ $c['borde'] }};border-top:1px solid {{ $c['borde'] }};border-radius:0 0 16px 16px;">
            <p style="margin:0 0 8px 0;{{ $f }}font-size:13px;line-height:20px;">
                @if ($urlBandeja)
                    <a href="{{ $urlBandeja }}" target="_blank" style="color:{{ $c['petroleo'] }};font-weight:bold;">📥 Ver mis pendientes de alta</a> &nbsp;·&nbsp;
                @endif
                <a href="{{ $urlExpediente }}" target="_blank" style="color:{{ $c['petroleo'] }};">📁 Ver expediente en PEOPLE</a>
            </p>
            <p style="margin:0;{{ $f }}font-size:12px;line-height:18px;color:{{ $c['texto2'] }};">ℹ️ Este aviso se envía una sola vez por contratación. Si necesitas que se reenvíe, hazlo desde la bandeja o pídelo a RH.</p>
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:14px 10px 0 10px;{{ $f }}font-size:11px;color:{{ $c['texto2'] }};">{{ config('app.name') }} · Mr. Lana</td>
    </tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td>
</tr>
</table>
</body>
</html>
