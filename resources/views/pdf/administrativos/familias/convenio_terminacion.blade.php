{{-- Convenio de terminación (no renovación): datos del expediente de la baja. --}}
@php $v = $diseno['sections']['visibles']; @endphp

<div class="seccion">
    <p style="text-align: right;">{{ $d['lugar_fecha'] }}</p>
    <p style="text-align: justify;">{{ $d['cuerpo'] }}</p>
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
            <tr>
                <td colspan="2"><span class="lbl">Causal</span><span class="val">{{ $d['causal'] }}</span></td>
            </tr>
        </table>
    </div>
@endif

@if (($v['notas'] ?? true) && $contenido['nota'] !== '')
    <div class="seccion"><p>{{ $contenido['nota'] }}</p></div>
@endif
