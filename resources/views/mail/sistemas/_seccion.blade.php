@php
    /*
     * Una sección de datos: título discreto en versalitas y una tabla de dos
     * columnas (etiqueta gris pequeña / valor). Recibe $titulo y $datos
     * (etiqueta => valor). «Sin capturar» se muestra atenuado.
     */
    $c = \App\Mail\Sistemas\PaletaCorreoSistemas::COLORES;
    $f = \App\Mail\Sistemas\PaletaCorreoSistemas::FUENTE;
@endphp
    <tr>
        <td style="padding:26px 40px 0 40px;">
            <p style="margin:0 0 6px 0;{{ $f }}font-size:11px;line-height:16px;letter-spacing:1.2px;text-transform:uppercase;font-weight:bold;color:{{ $c['petroleo'] }};">{{ $titulo }}</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid {{ $c['borde'] }};">
                @foreach ($datos as $etiqueta => $valor)
                    <tr>
                        <td width="38%" valign="top" style="padding:9px 12px 9px 0;border-bottom:1px solid {{ $c['linea'] }};{{ $f }}font-size:12px;line-height:18px;color:{{ $c['etiqueta'] }};">{{ $etiqueta }}</td>
                        @if ($valor === \App\Services\Notificaciones\AvisoAltaSistemasService::SIN_CAPTURAR)
                            <td valign="top" style="padding:9px 0;border-bottom:1px solid {{ $c['linea'] }};{{ $f }}font-size:13px;line-height:18px;font-style:italic;color:{{ $c['oroTexto'] }};">Sin capturar</td>
                        @else
                            <td valign="top" style="padding:9px 0;border-bottom:1px solid {{ $c['linea'] }};{{ $f }}font-size:14px;line-height:18px;color:{{ $c['texto'] }};word-break:break-word;">{{ $valor }}</td>
                        @endif
                    </tr>
                @endforeach
            </table>
        </td>
    </tr>
