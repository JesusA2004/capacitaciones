@php
    /* Lista de verificación (casillas vacías para marcar). Recibe $titulo y $elementos (list<string>). */
    $c = \App\Mail\Sistemas\PaletaCorreoSistemas::COLORES;
    $f = \App\Mail\Sistemas\PaletaCorreoSistemas::FUENTE;
@endphp
    <tr>
        <td style="padding:26px 40px 0 40px;">
            <p style="margin:0 0 6px 0;{{ $f }}font-size:11px;line-height:16px;letter-spacing:1.2px;text-transform:uppercase;font-weight:bold;color:{{ $c['petroleo'] }};">{{ $titulo }}</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid {{ $c['borde'] }};">
                @foreach ($elementos as $elemento)
                    <tr>
                        <td width="22" valign="top" style="padding:11px 0 0 0;border-bottom:1px solid {{ $c['linea'] }};font-size:0;line-height:0;"><div style="width:11px;height:11px;border:1px solid {{ $c['etiqueta'] }};border-radius:2px;font-size:0;line-height:0;">&nbsp;</div></td>
                        <td valign="top" style="padding:8px 0;border-bottom:1px solid {{ $c['linea'] }};{{ $f }}font-size:14px;line-height:18px;color:{{ $c['texto'] }};">{{ $elemento }}</td>
                    </tr>
                @endforeach
            </table>
        </td>
    </tr>
