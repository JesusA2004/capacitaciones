{!! $etiquetaEvento !!}

{!! $nombreCompleto !!}
{!! $puesto !!} · {!! $sucursal !!} · Baja efectiva {!! $fechaBajaLarga !!}

{!! $instruccion !!}

Ver detalle de la baja: {!! $urlDetalle !!}

@if ($plataformas !== [])
{!! mb_strtoupper($tituloLista) !!}
@foreach ($plataformas as $plataforma)
[ ] {!! $plataforma !!}
@endforeach

@endif
@foreach ($secciones as $seccion)
{!! mb_strtoupper($seccion['titulo']) !!}
@foreach ($seccion['datos'] as $etiqueta => $valor)
{!! $etiqueta !!}: {!! $valor !!}
@endforeach

@endforeach
Pendientes de baja en sistemas: {!! $urlBandeja !!}

Este aviso se envía una sola vez por baja.
{!! config('app.name') !!}
