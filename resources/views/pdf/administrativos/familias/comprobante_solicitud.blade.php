{{-- Comprobante de vacaciones autorizadas: datos de la solicitud aprobada (mismo estilo que los formatos oficiales). --}}
@php $v = $diseno['sections']['visibles']; @endphp

@if ($v['datos_colaborador'] ?? true)
    <div class="seccion">
        <table class="grid">
            <tr><td class="banda" colspan="2">Datos del colaborador</td></tr>
            <tr>
                <td style="width: 50%;"><span class="lbl">Nombre del colaborador</span><span class="val">{{ $d['colaborador']['nombre'] }}</span></td>
                <td><span class="lbl">No. de empleado</span><span class="val">{{ $d['colaborador']['numero_empleado'] }}</span></td>
            </tr>
            <tr>
                <td><span class="lbl">Puesto</span><span class="val">{{ $d['colaborador']['puesto'] }}</span></td>
                <td><span class="lbl">Departamento / Sucursal</span><span class="val">{{ $d['colaborador']['departamento_sucursal'] }}</span></td>
            </tr>
        </table>
    </div>
@endif

@if ($v['detalle'] ?? true)
    <div class="seccion">
        <table class="grid">
            <tr><td class="banda" colspan="2">Detalle de la solicitud</td></tr>
            <tr>
                <td style="width: 50%;"><span class="lbl">Folio</span><span class="val">{{ $d['folio'] }}</span></td>
                <td><span class="lbl">Periodo</span><span class="val">{{ $d['periodo'] }}</span></td>
            </tr>
            @if ($d['dias'] !== '' || $d['dias_detalle'] !== '')
                <tr>
                    <td><span class="lbl">Días</span><span class="val">{{ $d['dias'] }}</span></td>
                    <td><span class="lbl">Días elegidos</span><span class="val">{{ $d['dias_detalle'] }}</span></td>
                </tr>
            @endif
            <tr><td colspan="2"><span class="lbl">Motivo</span><span class="val">{{ $d['motivo'] }}</span></td></tr>
            <tr>
                <td><span class="lbl">Autorizó</span><span class="val">{{ $d['autorizo'] }}</span></td>
                <td><span class="lbl">Fecha de autorización</span><span class="val">{{ $d['fecha_autorizacion'] }}</span></td>
            </tr>
        </table>
    </div>
@endif

@if (($v['observaciones'] ?? true) && $d['observaciones'] !== '')
    <div class="seccion">
        <table class="grid"><tr><td><span class="lbl">Observaciones</span><span class="val">{{ $d['observaciones'] }}</span></td></tr></table>
    </div>
@endif
