@extends('mail.sistemas.layout')
{{--
    Correo de baja a Sistemas (BajaColaboradorSistemasMail): dar de baja
    accesos / baja concluida / baja cancelada. Layout y parciales
    compartidos con el de alta: resources/views/mail/sistemas/*.
--}}
@php
    $c = \App\Mail\Sistemas\PaletaCorreoSistemas::COLORES;
    $f = \App\Mail\Sistemas\PaletaCorreoSistemas::FUENTE;
@endphp

@section('titulo-documento', sprintf('%s: %s', $evento->etiqueta(), $nombreCompleto))
@section('preheader', sprintf('%s: %s, %s, %s. Baja efectiva %s.', $evento->etiqueta(), $nombreCompleto, $puesto, $sucursal, $fechaBajaLarga))
@section('etiqueta', $etiquetaEvento)
@section('nombre', $nombreCompleto)
@section('resumen')
{{ $puesto }} · {{ $sucursal }} · Baja efectiva {{ $fechaBajaLarga }}
@endsection

@section('cuerpo')
    <tr>
        <td style="padding:22px 40px 0 40px;">
            <p style="margin:0;{{ $f }}font-size:15px;line-height:23px;color:{{ $c['texto'] }};">{{ $instruccion }}</p>
        </td>
    </tr>
    @include('mail.sistemas._boton', ['url' => $urlDetalle, 'texto' => 'Ver detalle de la baja'])
    @if ($plataformas !== [])
        @include('mail.sistemas._lista', ['titulo' => $tituloLista, 'elementos' => $plataformas])
    @endif
    @foreach ($secciones as $seccion)
        @include('mail.sistemas._seccion', ['titulo' => $seccion['titulo'], 'datos' => $seccion['datos']])
    @endforeach
@endsection

@section('pie')
    <p style="margin:0 0 6px 0;">
        <a href="{{ $urlBandeja }}" target="_blank" style="color:{{ $c['petroleo'] }};text-decoration:underline;">Ver pendientes de baja en sistemas</a>
    </p>
    <p style="margin:0;">Este aviso se envía una sola vez por baja. Para reenviarlo, usa la bandeja de pendientes de baja.</p>
@endsection
