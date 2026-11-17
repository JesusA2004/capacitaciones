@php
    /* Botón principal del correo (único). VML para Outlook de escritorio. Recibe $url y $texto. */
    $c = \App\Mail\Sistemas\PaletaCorreoSistemas::COLORES;
    $f = \App\Mail\Sistemas\PaletaCorreoSistemas::FUENTE;
@endphp
    <tr>
        <td style="padding:24px 40px 4px 40px;">
            <!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" href="{{ $url }}" style="height:44px;v-text-anchor:middle;width:240px;" arcsize="10%" stroke="f" fillcolor="{{ $c['petroleo'] }}"><center style="color:#ffffff;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;">{{ $texto }}</center></v:roundrect><![endif]-->
            <!--[if !mso]><!-->
            <a href="{{ $url }}" target="_blank" style="display:inline-block;background-color:{{ $c['petroleo'] }};border-radius:4px;padding:13px 26px;{{ $f }}font-size:15px;line-height:18px;font-weight:bold;color:#ffffff;text-decoration:none;">{{ $texto }}</a>
            <!--<![endif]-->
        </td>
    </tr>
