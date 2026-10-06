{{-- Constancia laboral: texto de la constancia con los datos laborales vigentes. --}}
@php $v = $diseno['sections']['visibles']; @endphp

<div class="seccion">
    <p>{{ $d['lugar_fecha'] }}</p>
</div>

<div class="seccion">
    <p class="seccion-titulo">A quien corresponda</p>
    <p>{{ $d['cuerpo'] }}</p>
</div>

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
            <tr>
                <td><span class="etiqueta">Fecha de ingreso:</span> {{ $d['fecha_ingreso'] }}</td>
                <td><span class="etiqueta">Empresa:</span> {{ $d['empresa'] }}</td>
            </tr>
        </table>
    </div>
@endif

@if (($v['notas'] ?? true) && $contenido['nota'] !== '')
    <div class="seccion"><p>{{ $contenido['nota'] }}</p></div>
@endif
