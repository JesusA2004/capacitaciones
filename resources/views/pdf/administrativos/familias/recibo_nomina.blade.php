{{-- Recibo de nómina: los montos vienen de ReciboNominaService (snapshot del recibo). --}}
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
            <tr>
                <td><span class="etiqueta">Periodo:</span> {{ $d['periodo']['inicio'] }} — {{ $d['periodo']['fin'] }}</td>
                <td><span class="etiqueta">Fecha de pago:</span> {{ $d['fecha_pago'] }}</td>
            </tr>
            <tr>
                <td><span class="etiqueta">Folio:</span> {{ $d['folio'] }}</td>
                <td><span class="etiqueta">NSS:</span> {{ $d['colaborador']['nss'] }}</td>
            </tr>
        </table>
    </div>
@endif

@if ($v['percepciones'] ?? true)
    <div class="seccion">
        <p class="seccion-titulo">Percepciones</p>
        <table class="conceptos">
            <thead><tr><th>Concepto</th><th class="monto">Importe</th></tr></thead>
            <tbody>
                @foreach ($d['percepciones'] as $concepto)
                    <tr><td>{{ $concepto['concepto'] }}</td><td class="monto">{{ $concepto['importe'] }}</td></tr>
                @endforeach
                <tr class="subtotal"><td>Total percepciones</td><td class="monto">{{ $d['total_percepciones'] }}</td></tr>
            </tbody>
        </table>
    </div>
@endif

@if (($v['deducciones'] ?? true) && count($d['deducciones']) > 0)
    <div class="seccion">
        <p class="seccion-titulo">Deducciones</p>
        <table class="conceptos">
            <thead><tr><th>Concepto</th><th class="monto">Importe</th></tr></thead>
            <tbody>
                @foreach ($d['deducciones'] as $concepto)
                    <tr><td>{{ $concepto['concepto'] }}</td><td class="monto">- {{ $concepto['importe'] }}</td></tr>
                @endforeach
                <tr class="subtotal"><td>Total deducciones</td><td class="monto">- {{ $d['total_deducciones'] }}</td></tr>
            </tbody>
        </table>
    </div>
@endif

@if ($v['neto'] ?? true)
    <div class="seccion">
        <table class="conceptos">
            <tbody><tr class="total"><td>Neto a pagar</td><td class="monto">{{ $d['neto'] }}</td></tr></tbody>
        </table>
    </div>
@endif

@if (($v['observaciones'] ?? true) && $d['observaciones'] !== '')
    <div class="seccion">
        <p class="seccion-titulo">Observaciones</p>
        <p>{{ $d['observaciones'] }}</p>
    </div>
@endif
