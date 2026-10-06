{{-- Comprobante de solicitud (vacaciones/permisos): datos de la solicitud aprobada. --}}
@php $v = $diseno['sections']['visibles']; @endphp

@if ($v['datos_colaborador'] ?? true)
    <div class="seccion">
        <table class="datos">
            <tr>
                <td><span class="etiqueta">Colaborador:</span> {{ $d['colaborador']['nombre'] }}</td>
                <td><span class="etiqueta">Número de empleado:</span> {{ $d['colaborador']['numero_empleado'] }}</td>
            </tr>
            <tr>
                <td><span class="etiqueta">Puesto:</span> {{ $d['colaborador']['puesto'] }}</td>
                <td><span class="etiqueta">Sucursal:</span> {{ $d['colaborador']['sucursal'] }}</td>
            </tr>
        </table>
    </div>
@endif

@if ($v['detalle'] ?? true)
    <div class="seccion">
        <p class="seccion-titulo">Detalle de la solicitud</p>
        <table class="conceptos">
            <tbody>
                <tr><td>Folio</td><td>{{ $d['folio'] }}</td></tr>
                <tr><td>Tipo</td><td>{{ $d['tipo'] }}</td></tr>
                <tr><td>Periodo</td><td>{{ $d['periodo'] }}</td></tr>
                @if ($d['dias'] !== '')
                    <tr><td>Días</td><td>{{ $d['dias'] }}</td></tr>
                @endif
                @if ($d['dias_detalle'] !== '')
                    <tr><td>Días elegidos</td><td>{{ $d['dias_detalle'] }}</td></tr>
                @endif
                <tr><td>Motivo</td><td>{{ $d['motivo'] }}</td></tr>
                <tr><td>Autorizó</td><td>{{ $d['autorizo'] }}</td></tr>
                <tr><td>Fecha de autorización</td><td>{{ $d['fecha_autorizacion'] }}</td></tr>
            </tbody>
        </table>
    </div>
@endif

@if (($v['observaciones'] ?? true) && $d['observaciones'] !== '')
    <div class="seccion">
        <p class="seccion-titulo">Observaciones</p>
        <p>{{ $d['observaciones'] }}</p>
    </div>
@endif
