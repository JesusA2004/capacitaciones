{{-- Finiquito (formato oficial «Formato_Finiquito.docx»): conceptos y montos vienen de FiniquitoService (cálculo revisado + ajustes autorizados). --}}
@php
    $v = $diseno['sections']['visibles'];
    $co = $d['colaborador'];
    $perc = $d['percepciones'];
    $dedu = ($v['deducciones'] ?? true) ? $d['deducciones'] : [];
    $filas = max(count($perc), count($dedu), 1);
@endphp

@if ($v['datos_colaborador'] ?? true)
    <div class="seccion">
        <table class="grid">
            <tr><td class="banda" colspan="3">Datos del trabajador</td></tr>
            <tr>
                <td colspan="2"><span class="lbl">Nombre completo</span><span class="val">{{ $co['nombre'] }}</span></td>
                <td style="width: 25%;"><span class="lbl">No. de empleado</span><span class="val">{{ $co['numero_empleado'] }}</span></td>
            </tr>
            <tr>
                <td style="width: 25%;"><span class="lbl">RFC</span><span class="val">{{ $co['rfc'] }}</span></td>
                <td><span class="lbl">CURP</span><span class="val">{{ $co['curp'] }}</span></td>
                <td><span class="lbl">NSS (IMSS)</span><span class="val">{{ $co['nss'] }}</span></td>
            </tr>
            <tr>
                <td><span class="lbl">Último puesto</span><span class="val">{{ $co['puesto'] }}</span></td>
                <td><span class="lbl">Sucursal</span><span class="val">{{ $co['sucursal'] }}</span></td>
                <td><span class="lbl">Salario diario</span><span class="val fuerte">{{ $d['sueldo_diario'] }}</span></td>
            </tr>
        </table>
    </div>
@endif

@if ($v['percepciones'] ?? true)
    <div class="seccion">
        <table class="conceptos">
            <thead>
                <tr><th class="banda" colspan="4">Percepciones</th><th class="banda" colspan="3">Deducciones</th></tr>
                <tr>
                    <th class="col clave">Clave</th><th class="col">Concepto</th><th class="col num" style="text-align: center;">Días</th><th class="col monto" style="text-align: right;">Importe</th>
                    <th class="col clave">Clave</th><th class="col">Concepto</th><th class="col monto" style="text-align: right;">Importe</th>
                </tr>
            </thead>
            <tbody>
                @for ($i = 0; $i < $filas; $i++)
                    @php($pc = $perc[$i] ?? null)
                    @php($dd = $dedu[$i] ?? null)
                    <tr>
                        <td class="clave">{{ $pc['clave'] ?? '' }}</td>
                        <td>{{ $pc['concepto'] ?? '' }}</td>
                        <td class="num">{{ $pc['dias'] ?? '' }}</td>
                        <td class="monto">{{ $pc['importe'] ?? '' }}</td>
                        <td class="clave">{{ $dd['clave'] ?? '' }}</td>
                        <td>{{ $dd['concepto'] ?? '' }}</td>
                        <td class="monto">{{ $dd['importe'] ?? '' }}</td>
                    </tr>
                @endfor
                <tr class="total">
                    <td colspan="3" style="text-align: right;">TOTAL PERCEPCIONES</td>
                    <td class="monto">{{ $d['total_percepciones'] }}</td>
                    <td colspan="2" style="text-align: right;">TOTAL DEDUCCIONES</td>
                    <td class="monto">{{ $d['total_deducciones'] }}</td>
                </tr>
            </tbody>
        </table>
    </div>
@endif

@if ($v['neto'] ?? true)
    <div class="seccion">
        <table class="pagar">
            <tr>
                <td class="etq">TOTAL A PAGAR</td>
                <td class="imp">{{ $d['neto'] }}</td>
                <td class="letra"><span class="lbl">Importe con letra</span><span class="val">({{ $d['neto_letra'] }})</span></td>
            </tr>
        </table>
    </div>
@endif

@if (($v['observaciones'] ?? true) && $d['observaciones'] !== '')
    <div class="seccion">
        <table class="grid"><tr><td><span class="lbl">Comentarios del ajuste</span><span class="val">{{ $d['observaciones'] }}</span></td></tr></table>
    </div>
@endif

@if (($v['notas'] ?? true) && $contenido['nota'] !== '')
    <div class="seccion">
        <table class="grid"><tr><td><span class="lbl">Notas</span><span class="val">{{ $contenido['nota'] }}</span></td></tr></table>
    </div>
@endif
