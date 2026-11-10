ALTA EN SISTEMAS — {!! mb_strtoupper($tipoIngreso) !!}

{!! $nombreCompleto !!}
{!! $puesto !!} · {!! $sucursal !!}
Fecha de ingreso: {!! $fechaIngresoLarga !!}@if ($fechaIngresoCorta !== $fechaIngresoLarga) ({!! $fechaIngresoCorta !!})@endif


Dale de alta en los demás sistemas y márcalo como dado de alta en PEOPLE.
@if ($faltantes > 0)
{!! $faltantes === 1 ? 'Falta 1 dato' : sprintf('Faltan %d datos', $faltantes) !!} («Sin capturar»): RH los completa durante la incorporación.
@endif

Abrir y copiar datos: {!! $urlDetalle !!}

@foreach ($secciones as $seccion)
{!! mb_strtoupper($seccion['titulo']) !!}
@foreach ($seccion['datos'] as $etiqueta => $valor)
- {!! $etiqueta !!}: {!! $valor !!}
@endforeach

@endforeach
PARA COPIAR Y PEGAR
@foreach ($lineasCopiar as $linea)
{!! $linea !!}
@endforeach

@if ($urlBandeja)
Mis pendientes de alta: {!! $urlBandeja !!}
@endif
Expediente en PEOPLE: {!! $urlExpediente !!}

Este aviso se envía una sola vez por contratación.
{!! config('app.name') !!}
