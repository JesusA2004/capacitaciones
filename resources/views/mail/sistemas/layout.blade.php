@php
    /*
     * Layout de los correos a Sistemas (alta: AltaColaboradorSistemasMail;
     * baja: BajaColaboradorSistemasMail). Una sola tarjeta de 600 px:
     * logo, franja delgada petróleo/oro, etiqueta en versalitas, nombre y
     * línea de resumen; luego el cuerpo de cada correo y un pie breve.
     *
     * HTML de correo: tablas solo donde son imprescindibles, estilos en
     * línea, sin SVG, sin emojis, sin hojas de estilo (Outlook/Gmail las
     * descartan). Paleta: App\Mail\Sistemas\PaletaCorreoSistemas.
     *
     * Secciones: titulo-documento, preheader, etiqueta, nombre, resumen,
     * cuerpo (filas <tr> de la tarjeta) y pie.
     */
    $c = \App\Mail\Sistemas\PaletaCorreoSistemas::COLORES;
    $f = \App\Mail\Sistemas\PaletaCorreoSistemas::FUENTE;
@endphp
<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>@yield('titulo-documento')</title>
</head>
<body style="margin:0;padding:0;background-color:{{ $c['fondo'] }};{{ $f }}color:{{ $c['texto'] }};-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">@yield('preheader')</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $c['fondo'] }}" style="background-color:{{ $c['fondo'] }};">
<tr>
<td align="center" style="padding:32px 12px;">
<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $c['tarjeta'] }}" style="max-width:600px;width:100%;background-color:{{ $c['tarjeta'] }};border:1px solid {{ $c['borde'] }};border-radius:6px;">
    <tr>
        <td style="padding:28px 40px 22px 40px;">
            <img src="{{ $logoUrl }}" width="150" alt="Mr. Lana PEOPLE" style="display:block;width:150px;max-width:150px;height:auto;border:0;outline:none;text-decoration:none;{{ $f }}font-size:16px;font-weight:bold;color:{{ $c['petroleo'] }};">
        </td>
    </tr>
    <tr>
        <td height="3" bgcolor="{{ $c['petroleo'] }}" style="height:3px;line-height:3px;font-size:0;background-color:{{ $c['petroleo'] }};border-bottom:1px solid {{ $c['oro'] }};">&nbsp;</td>
    </tr>
    <tr>
        <td style="padding:30px 40px 0 40px;">
            <p style="margin:0 0 10px 0;{{ $f }}font-size:11px;line-height:16px;letter-spacing:1.5px;font-weight:bold;color:{{ $c['oroTexto'] }};">@yield('etiqueta')</p>
            <h1 style="margin:0;{{ $f }}font-size:22px;line-height:28px;font-weight:bold;color:{{ $c['petroleoOscuro'] }};">@yield('nombre')</h1>
            <p style="margin:6px 0 0 0;{{ $f }}font-size:14px;line-height:21px;color:{{ $c['texto2'] }};">@yield('resumen')</p>
        </td>
    </tr>
@yield('cuerpo')
    <tr>
        <td style="padding:28px 40px 30px 40px;">
            <div style="border-top:1px solid {{ $c['linea'] }};padding-top:18px;{{ $f }}font-size:12px;line-height:19px;color:{{ $c['texto2'] }};">@yield('pie')</div>
        </td>
    </tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
<p style="margin:14px 0 0 0;{{ $f }}font-size:11px;line-height:16px;color:{{ $c['etiqueta'] }};">{{ config('app.name') }}</p>
</td>
</tr>
</table>
</body>
</html>
