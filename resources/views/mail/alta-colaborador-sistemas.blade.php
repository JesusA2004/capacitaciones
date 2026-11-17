@extends('mail.sistemas.layout')
{{--
    Correo «Alta en sistemas» (AltaColaboradorSistemasMail). Layout y
    parciales compartidos con el de baja: resources/views/mail/sistemas/*.
    El detalle con «Copiar» por dato vive en PEOPLE (botón principal); la
    versión de texto plano lista cada dato en un renglón para copiar y pegar.
--}}
@php
    $c = \App\Mail\Sistemas\PaletaCorreoSistemas::COLORES;
    $f = \App\Mail\Sistemas\PaletaCorreoSistemas::FUENTE;
@endphp

@section('titulo-documento', sprintf('Alta en sistemas: %s', $nombreCompleto))
@section('preheader', sprintf('%s: %s, %s, %s. Ingreso %s.', $tipoIngreso, $nombreCompleto, $puesto, $sucursal, $fechaIngresoCorta))
@section('etiqueta', mb_strtoupper(sprintf('Alta en sistemas · %s', $tipoIngreso)))
@section('nombre', $nombreCompleto)
@section('resumen')
{{ $puesto }} · {{ $sucursal }} · Ingreso {{ $fechaIngresoLarga }}
@endsection

@section('cuerpo')
    <tr>
        <td style="padding:22px 40px 0 40px;">
            <p style="margin:0;{{ $f }}font-size:15px;line-height:23px;color:{{ $c['texto'] }};">Da de alta a esta persona en los demás sistemas y márcala como dada de alta en PEOPLE.</p>
            @if ($faltantes > 0)
                <p style="margin:10px 0 0 0;{{ $f }}font-size:13px;line-height:19px;color:{{ $c['oroTexto'] }};">{{ $faltantes === 1 ? 'Falta 1 dato por capturar' : sprintf('Faltan %d datos por capturar', $faltantes) }}; RH {{ $faltantes === 1 ? 'lo' : 'los' }} completa durante la incorporación.</p>
            @endif
        </td>
    </tr>
    @include('mail.sistemas._boton', ['url' => $urlDetalle, 'texto' => 'Abrir y copiar datos'])
    @foreach ($secciones as $seccion)
        @include('mail.sistemas._seccion', ['titulo' => $seccion['titulo'], 'datos' => $seccion['datos']])
    @endforeach
@endsection

@section('pie')
    <p style="margin:0 0 6px 0;">
        @if ($urlBandeja)
            <a href="{{ $urlBandeja }}" target="_blank" style="color:{{ $c['petroleo'] }};text-decoration:underline;">Ver mis pendientes de alta</a> &nbsp;·&nbsp;
        @endif
        <a href="{{ $urlExpediente }}" target="_blank" style="color:{{ $c['petroleo'] }};text-decoration:underline;">Ver expediente en PEOPLE</a>
    </p>
    <p style="margin:0;">Este aviso se envía una sola vez por contratación. Para reenviarlo, usa la bandeja de pendientes o pídelo a RH.</p>
@endsection
