{{-- Aviso de rescisión: causal, fundamento, hechos circunstanciados, testigos y entrega capturados en el expediente. --}}
@php
    $v = $diseno['sections']['visibles'];
    $h = is_array($d['hechos'] ?? null) ? $d['hechos'] : [];
@endphp

<div class="seccion">
    <p style="text-align: right;">{{ $d['lugar_fecha'] }}</p>
    <p style="text-align: justify;">{{ $d['cuerpo'] }}</p>
    <p><strong>Fundamento:</strong> {{ $d['fundamento'] }}</p>
</div>

@if ($v['datos_colaborador'] ?? true)
    <div class="seccion">
        <table class="grid">
            <tr><td class="banda" colspan="2">Datos del colaborador</td></tr>
            <tr>
                <td style="width: 50%;"><span class="lbl">Nombre</span><span class="val">{{ $d['colaborador']['nombre'] }}</span></td>
                <td><span class="lbl">No. de empleado</span><span class="val">{{ $d['colaborador']['numero_empleado'] }}</span></td>
            </tr>
            <tr>
                <td><span class="lbl">Puesto</span><span class="val">{{ $d['colaborador']['puesto'] }}</span></td>
                <td><span class="lbl">Departamento / Sucursal</span><span class="val">{{ $d['colaborador']['departamento_sucursal'] }}</span></td>
            </tr>
            <tr>
                <td><span class="lbl">Fecha de ingreso</span><span class="val">{{ $d['fecha_ingreso'] }}</span></td>
                <td><span class="lbl">Último día laborado</span><span class="val">{{ $d['fecha_baja'] }}</span></td>
            </tr>
        </table>
    </div>
@endif

@if (($v['hechos'] ?? true) && $h !== [])
    <div class="seccion">
        <table class="grid">
            <tr><td class="banda" colspan="2">Hechos</td></tr>
            <tr>
                <td style="width: 50%;"><span class="lbl">Fecha y hora</span><span class="val">{{ $h['cuando'] }}</span></td>
                <td><span class="lbl">Lugar</span><span class="val">{{ $h['lugar'] }}</span></td>
            </tr>
            <tr><td colspan="2"><span class="lbl">Qué ocurrió</span><span class="val">{{ $h['que_ocurrio'] }}</span></td></tr>
            <tr><td colspan="2"><span class="lbl">Quién lo constató</span><span class="val">{{ $h['constataron'] }}</span></td></tr>
            <tr><td colspan="2"><span class="lbl">Relato circunstanciado</span><span class="val">{{ $h['relato'] }}</span></td></tr>
            <tr><td colspan="2"><span class="lbl">Testigos</span><span class="val">{{ implode('; ', $h['testigos']) }}</span></td></tr>
        </table>
    </div>
@endif

@if (($v['entrega'] ?? true) && $h !== [])
    <div class="seccion">
        <table class="grid">
            <tr><td class="banda" colspan="3">Entrega del aviso</td></tr>
            <tr>
                <td><span class="lbl">Fecha del aviso</span><span class="val">{{ $d['fecha_aviso'] }}</span></td>
                <td><span class="lbl">Método</span><span class="val">{{ $h['entrega'] }}</span></td>
                <td><span class="lbl">Fecha de entrega o negativa</span><span class="val">{{ $h['fecha_entrega'] }}</span></td>
            </tr>
        </table>
    </div>
@endif

@if (($d['texto_adicional'] ?? '') !== '')
    <div class="seccion"><p style="text-align: justify; white-space: pre-line;">{{ $d['texto_adicional'] }}</p></div>
@endif

@if (($v['notas'] ?? true) && $contenido['nota'] !== '')
    <div class="seccion"><p>{{ $contenido['nota'] }}</p></div>
@endif
