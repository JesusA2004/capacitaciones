ALTA EN SISTEMAS · {!! mb_strtoupper($tipoIngreso) !!}

{!! $nombreCompleto !!}
{!! $puesto !!} · {!! $sucursal !!} · Ingreso {!! $fechaIngresoLarga !!}

Da de alta a esta persona en los demás sistemas y márcala como dada de alta en PEOPLE.
@if ($faltantes > 0)
{!! $faltantes === 1 ? 'Falta 1 dato por capturar' : sprintf('Faltan %d datos por capturar', $faltantes) !!}; RH {!! $faltantes === 1 ? 'lo' : 'los' !!} completa durante la incorporación.
@endif

Abrir y copiar datos: {!! $urlDetalle !!}

@foreach ($secciones as $seccion)
{!! mb_strtoupper($seccion['titulo']) !!}
@foreach ($seccion['datos'] as $etiqueta => $valor)
{!! $etiqueta !!}: {!! $valor !!}
@endforeach

@endforeach
@if ($urlBandeja)
Mis pendientes de alta: {!! $urlBandeja !!}
@endif
Expediente en PEOPLE: {!! $urlExpediente !!}

Este aviso se envía una sola vez por contratación.
{!! config('app.name') !!}
