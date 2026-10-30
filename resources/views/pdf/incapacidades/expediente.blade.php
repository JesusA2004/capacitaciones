@component('pdf.layout', ['titulo' => $titulo])
    <p><strong>Colaborador:</strong> {{ $colaborador->nombreCompleto() }}<br>
        <strong>Puesto:</strong> {{ $colaborador->puesto?->nombre ?? '—' }} · <strong>Sucursal:</strong> {{ $colaborador->sucursalPrincipal?->nombre ?? '—' }}<br>
        <strong>Tipo:</strong> {{ $cadena['tipo_etiqueta'] }}@if ($cadena['subtipo_etiqueta']) · {{ $cadena['subtipo_etiqueta'] }}@endif · <strong>Estatus:</strong> {{ $cadena['estatus_etiqueta'] }}<br>
        <strong>Total de días:</strong> {{ $cadena['dias_totales'] }}
        @if ($cadena['cuenta_siniestralidad'] === false)
            <br>Accidente en trayecto: se identifica aparte de la siniestralidad.
        @endif
    </p>

    @if ($cadena['descripcion_evento'])
        <p><strong>Descripción del evento:</strong> {{ $cadena['descripcion_evento'] }}</p>
    @endif

    <h3>Línea de tiempo</h3>
    <table class="tabla">
        <thead><tr><th>Fecha</th><th>Evento</th><th>Detalle</th></tr></thead>
        <tbody>
            @foreach ($cadena['linea_de_tiempo'] as $evento)
                <tr>
                    <td>{{ $evento['fecha'] !== '' ? \Illuminate\Support\Carbon::parse($evento['fecha'])->format('d/m/Y') : 'Pendiente' }}</td>
                    <td>{{ $evento['titulo'] }}</td>
                    <td>{{ $evento['detalle'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h3>Incapacidades</h3>
    <table class="tabla">
        <thead><tr><th>Secuencia</th><th>Serie y folio</th><th>Inicio</th><th>Días</th><th>Término</th><th>Estatus</th></tr></thead>
        <tbody>
            @foreach ($cadena['incapacidades'] as $i)
                <tr>
                    <td>{{ $i['secuencia_etiqueta'] }}</td>
                    <td>{{ $i['serie_folio_imss'] }}</td>
                    <td>{{ \Illuminate\Support\Carbon::parse($i['fecha_inicio'])->format('d/m/Y') }}</td>
                    <td>{{ $i['dias'] }}</td>
                    <td>{{ \Illuminate\Support\Carbon::parse($i['fecha_fin'])->format('d/m/Y') }}</td>
                    <td>{{ $i['estatus_etiqueta'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h3>Documentos</h3>
    <table class="tabla">
        <thead><tr><th>Documento</th><th>Archivo</th><th>Cargó</th><th>Vigente</th></tr></thead>
        <tbody>
            @foreach ($cadena['documentos'] as $d)
                <tr>
                    <td>{{ $d['etiqueta'] }}</td>
                    <td>{{ $d['nombre_original'] }}</td>
                    <td>{{ $d['cargado_por'] ?? '—' }}</td>
                    <td>{{ $d['vigente'] ? 'Sí' : 'Reemplazado' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endcomponent
