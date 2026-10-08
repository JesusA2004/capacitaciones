{{-- Recibo de nómina (formato oficial «Recibo_de_Nomina con logo.docx»): los montos vienen del recibo (snapshot del lote). --}}
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
            <tr><td class="banda" colspan="4">Datos del trabajador</td></tr>
            <tr>
                <td colspan="3"><span class="lbl">Nombre completo</span><span class="val">{{ $co['nombre'] }}</span></td>
                <td style="width: 25%;"><span class="lbl">No. de empleado</span><span class="val">{{ $co['numero_empleado'] }}</span></td>
            </tr>
            <tr>
                <td style="width: 25%;"><span class="lbl">RFC</span><span class="val">{{ $co['rfc'] }}</span></td>
                <td colspan="2"><span class="lbl">CURP</span><span class="val">{{ $co['curp'] }}</span></td>
                <td><span class="lbl">NSS (IMSS)</span><span class="val">{{ $co['nss'] }}</span></td>
            </tr>
            <tr>
                <td><span class="lbl">Puesto</span><span class="val">{{ $co['puesto'] }}</span></td>
                <td style="width: 25%;"><span class="lbl">Departamento / Sucursal</span><span class="val">{{ $co['departamento_sucursal'] }}</span></td>
                <td style="width: 25%;"><span class="lbl">Fecha de ingreso</span><span class="val">{{ $co['fecha_ingreso'] }}</span></td>
                <td><span class="lbl">Salario diario</span><span class="val">{{ $co['salario_diario'] }}</span></td>
            </tr>
        </table>
    </div>
@endif

@if ($v['dias_periodo'] ?? true)
    <div class="seccion">
        <table class="grid">
            <tr><td class="banda" colspan="3">Días del periodo</td></tr>
            <tr>
                <td class="centro" style="width: 33.3%;"><span class="lbl">Días pagados</span><span class="val">{{ $d['dias']['pagados'] }}</span></td>
                <td class="centro" style="width: 33.3%;"><span class="lbl">Días de falta</span><span class="val">{{ $d['dias']['falta'] }}</span></td>
                <td class="centro"><span class="lbl">Días de incapacidad</span><span class="val">{{ $d['dias']['incapacidad'] }}</span></td>
            </tr>
        </table>
    </div>
@endif

@if ($v['percepciones'] ?? true)
    <div class="seccion">
        <table class="conceptos">
            <thead>
                <tr><th class="banda" colspan="3">Percepciones</th><th class="banda" colspan="3">Deducciones</th></tr>
                <tr>
                    <th class="col clave">Clave</th><th class="col">Concepto</th><th class="col monto" style="text-align: right;">Importe</th>
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
                        <td class="monto">{{ $pc['importe'] ?? '' }}</td>
                        <td class="clave">{{ $dd['clave'] ?? '' }}</td>
                        <td>{{ $dd['concepto'] ?? '' }}</td>
                        <td class="monto">{{ $dd['importe'] ?? '' }}</td>
                    </tr>
                @endfor
                <tr class="total">
                    <td colspan="2" style="text-align: right;">TOTAL PERCEPCIONES</td>
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
                <td class="etq">NETO A PAGAR</td>
                <td class="imp">{{ $d['neto'] }}</td>
                <td class="letra"><span class="lbl">Importe con letra</span><span class="val">({{ $d['neto_letra'] }})</span></td>
            </tr>
        </table>
    </div>
@endif

@if (($v['observaciones'] ?? true) && $d['observaciones'] !== '')
    <div class="seccion">
        <table class="grid"><tr><td><span class="lbl">Observaciones</span><span class="val">{{ $d['observaciones'] }}</span></td></tr></table>
    </div>
@endif
