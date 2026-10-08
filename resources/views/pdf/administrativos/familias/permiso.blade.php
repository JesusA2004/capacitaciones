{{--
    Solicitud de permiso (formato oficial «Formato_Permiso.docx»), llena con
    los datos de la solicitud AUTORIZADA por RH: casillas marcadas según la
    modalidad, el tipo (goce/sin goce/especial) y la causal. Las tres firmas
    físicas se conservan; «Autorizado en PEOPLE por» es solo una referencia.
--}}
@php
    $v = $diseno['sections']['visibles'];
    $co = $d['colaborador'];
    $pm = $d['permiso'];
    $marca = fn (bool $si) => $si ? 'X' : '';
@endphp

@if ($v['datos_colaborador'] ?? true)
    <div class="seccion">
        <table class="grid">
            <tr><td class="banda" colspan="2">Datos del colaborador</td></tr>
            <tr>
                <td style="width: 50%;"><span class="lbl">Nombre del colaborador</span><span class="val">{{ $co['nombre'] }}</span></td>
                <td><span class="lbl">Departamento / Sucursal</span><span class="val">{{ $co['departamento_sucursal'] }}</span></td>
            </tr>
        </table>
    </div>
@endif

@if ($v['permiso_solicitado'] ?? true)
    <div class="seccion">
        <table class="grid">
            <tr><td class="banda" colspan="6">Permiso solicitado <span style="font-weight: 400;">(marque una opción)</span></td></tr>
            <tr>
                <td colspan="2" style="width: 33.3%;">
                    <span class="chk">{{ $marca($pm['tipo'] === 'faltar') }}</span><span class="opcion">Permiso para faltar</span><br>
                    <span class="nota-italica">Día(s): {{ $pm['tipo'] === 'faltar' ? $pm['dias'] : '____________' }}</span>
                </td>
                <td colspan="2" style="width: 33.3%;">
                    <span class="chk">{{ $marca($pm['tipo'] === 'salir_temprano') }}</span><span class="opcion">Permiso para salir temprano</span><br>
                    <span class="nota-italica">Horas: {{ $pm['tipo'] === 'salir_temprano' ? $pm['horas'] : '____________' }}</span>
                </td>
                <td colspan="2">
                    <span class="chk">{{ $marca($pm['tipo'] === 'llegar_tarde') }}</span><span class="opcion">Permiso para llegar tarde</span><br>
                    <span class="nota-italica">Horas: {{ $pm['tipo'] === 'llegar_tarde' ? $pm['horas'] : '____________' }}</span>
                </td>
            </tr>
            <tr>
                <td colspan="3" class="centro"><span class="lbl">Hora de salida</span><span class="val fuerte">{{ $pm['hora_salida'] }}</span></td>
                <td colspan="3" class="centro"><span class="lbl">Hora de entrada</span><span class="val fuerte">{{ $pm['hora_entrada'] }}</span></td>
            </tr>
        </table>
    </div>
@endif

@if ($v['tipo_permiso'] ?? true)
    <div class="seccion">
        <table class="grid">
            <tr><td class="banda" colspan="15">Tipo de permiso <span style="font-weight: 400;">(marque una opción)</span></td></tr>
            <tr>
                <td colspan="5" style="width: 33.3%;"><span class="chk">{{ $marca($pm['goce'] === 'con_goce') }}</span><span class="opcion">Con goce de sueldo</span></td>
                <td colspan="5" style="width: 33.3%;"><span class="chk">{{ $marca($pm['goce'] === 'sin_goce') }}</span><span class="opcion">Sin goce de sueldo</span></td>
                <td colspan="5">
                    <span class="chk">{{ $marca($pm['goce'] === 'especial') }}</span><span class="opcion">Permiso especial</span><br>
                    <span class="nota-italica">Indique la causal abajo</span>
                </td>
            </tr>
            <tr><td class="suave" colspan="15"><span class="lbl" style="color: inherit;">Causal del permiso especial <span style="font-weight: 400;">(solo si marcó «Permiso especial»)</span></span></td></tr>
            <tr>
                @foreach (['paternidad' => 'Paternidad', 'luto' => 'Luto', 'lactancia' => 'Lactancia', 'cumpleanos' => 'Cumpleaños', 'productividad' => 'Productividad'] as $clave => $texto)
                    <td colspan="3" style="width: 20%;"><span class="chk">{{ $marca($pm['goce'] === 'especial' && $pm['causal'] === $clave) }}</span><span class="opcion">{{ $texto }}</span></td>
                @endforeach
            </tr>
        </table>
    </div>
@endif

@if ($v['observaciones'] ?? true)
    <div class="seccion">
        <table class="grid">
            <tr><td style="height: 34mm;"><span class="lbl">Observaciones</span><span class="val">{{ $d['observaciones'] }}</span></td></tr>
        </table>
    </div>
@endif

@if (($v['autorizacion'] ?? true) && $d['autorizo'] !== '')
    <p class="autorizado">Autorizado en MR. LANA PEOPLE por {{ $d['autorizo'] }} · {{ $d['fecha_autorizacion'] }} · Folio {{ $d['folio'] }}</p>
@endif
