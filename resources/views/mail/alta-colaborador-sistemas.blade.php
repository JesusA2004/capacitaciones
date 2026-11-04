<x-mail::message>
# Alta para asignar usuarios

**{{ $nombreCompleto }}** entró a contratación ({{ $origen }}). Por favor asígnale sus usuarios en las demás plataformas.

## Datos personales

<x-mail::table>
| Dato | Valor |
|:-----|:------|
@foreach ($personales as $etiqueta => $valor)
| {{ $etiqueta }} | {{ str_replace('|', '/', $valor) }} |
@endforeach
</x-mail::table>

## Datos laborales

<x-mail::table>
| Dato | Valor |
|:-----|:------|
@foreach ($laborales as $etiqueta => $valor)
| {{ $etiqueta }} | {{ str_replace('|', '/', $valor) }} |
@endforeach
</x-mail::table>

Los datos marcados como «Sin capturar» aún no están en PEOPLE: la persona los completa durante su incorporación.

<x-mail::button :url="$urlExpediente">
Ver expediente en PEOPLE
</x-mail::button>

Este aviso se envía una sola vez por contratación. Si necesitas que se reenvíe, pídelo a RH.

{{ config('app.name') }}
</x-mail::message>
