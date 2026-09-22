<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Finiquito — {{ $finiquito->colaborador->name }} {{ $finiquito->colaborador->apellidos }}</title>
    <style>
        @page { margin: 40px 44px; }
        * { box-sizing: border-box; }
        body { font-family: 'Helvetica', sans-serif; color: #1f2937; font-size: 11px; line-height: 1.4; }
        .marca { font-size: 10px; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; color: #2dc7d3; margin: 0 0 4px; }
        h1 { font-size: 20px; margin: 0 0 4px; color: #274754; }
        .meta { font-size: 9.5px; color: #6b7280; margin: 0 0 16px; }
        .encabezado { border-bottom: 2px solid #274754; padding-bottom: 10px; margin-bottom: 16px; }
        .datos { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .datos td { padding: 3px 0; font-size: 10.5px; vertical-align: top; width: 50%; }
        .datos .etiqueta { color: #6b7280; }
        .seccion-titulo { font-size: 12.5px; font-weight: bold; color: #274754; margin: 12px 0 8px; padding-top: 6px; border-top: 1px solid #e5e7eb; }
        table.conceptos { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.conceptos th, table.conceptos td { border: 1px solid #e5e7eb; padding: 6px 8px; font-size: 10px; }
        table.conceptos th { background: #274754; color: #fff; text-align: left; text-transform: uppercase; font-size: 8.5px; letter-spacing: 0.3px; }
        table.conceptos td.monto { text-align: right; }
        table.conceptos tr.total td { font-weight: bold; background: #f9fafb; }
        table.conceptos tr.total-final td { font-weight: bold; background: #dcfce7; color: #166534; font-size: 12px; }
        .leyenda { margin-top: 18px; padding: 10px 12px; background: #fef3c7; color: #92400e; border-radius: 6px; font-size: 9.5px; }
        .firma { margin-top: 60px; text-align: center; font-size: 10px; color: #374151; }
        .firma .linea { border-top: 1px solid #9ca3af; width: 260px; margin: 0 auto 6px; }
    </style>
</head>
<body>
    <div class="encabezado">
        <p class="marca">MR. LANA PEOPLE — Finiquito de baja</p>
        <h1>Cálculo de finiquito</h1>
        <p class="meta">Folio {{ $finiquito->solicitudInterna->folio }} · Generado el {{ now()->format('d/m/Y H:i') }}</p>
    </div>

    <table class="datos">
        <tr>
            <td><span class="etiqueta">Colaborador:</span> {{ $finiquito->colaborador->name }} {{ $finiquito->colaborador->apellidos }}</td>
            <td><span class="etiqueta">Fecha de ingreso:</span> {{ $finiquito->fecha_ingreso->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td><span class="etiqueta">Fecha de baja:</span> {{ $finiquito->fecha_baja->format('d/m/Y') }}</td>
            <td><span class="etiqueta">Antigüedad:</span> {{ $finiquito->antiguedad_anios }} año(s), {{ $finiquito->antiguedad_meses }} mes(es)</td>
        </tr>
        <tr>
            <td><span class="etiqueta">Sueldo mensual:</span> ${{ number_format((float) $finiquito->sueldo_mensual, 2) }}</td>
            <td><span class="etiqueta">Sueldo diario:</span> ${{ number_format((float) $finiquito->sueldo_diario, 2) }}</td>
        </tr>
        <tr>
            <td><span class="etiqueta">Calculado por:</span> {{ $finiquito->calculadoPor->name }} {{ $finiquito->calculadoPor->apellidos }}</td>
            <td><span class="etiqueta">Revisado por:</span> {{ $finiquito->revisadoPor?->name }} {{ $finiquito->revisadoPor?->apellidos }}</td>
        </tr>
    </table>

    <p class="seccion-titulo">Conceptos</p>
    <table class="conceptos">
        <thead>
            <tr><th>Concepto</th><th style="text-align:right">Monto</th></tr>
        </thead>
        <tbody>
            <tr><td>Sueldo pendiente</td><td class="monto">${{ number_format((float) $finiquito->sueldo_pendiente, 2) }}</td></tr>
            <tr><td>Prima vacacional ({{ $finiquito->vacaciones_pendientes }} días pendientes)</td><td class="monto">${{ number_format((float) $finiquito->prima_vacacional, 2) }}</td></tr>
            <tr><td>Aguinaldo proporcional ({{ $finiquito->dias_trabajados_periodo }} días trabajados del periodo)</td><td class="monto">${{ number_format((float) $finiquito->aguinaldo_proporcional, 2) }}</td></tr>
            <tr><td>Indemnización</td><td class="monto">${{ number_format((float) $finiquito->indemnizacion, 2) }}</td></tr>
            <tr class="total"><td>Total calculado</td><td class="monto">${{ number_format((float) $finiquito->total_calculado, 2) }}</td></tr>
            <tr><td>Bonos extra</td><td class="monto">+ ${{ number_format((float) $finiquito->bonos_extra, 2) }}</td></tr>
            <tr><td>Descuentos</td><td class="monto">- ${{ number_format((float) $finiquito->descuentos, 2) }}</td></tr>
            <tr><td>Adeudos</td><td class="monto">- ${{ number_format((float) $finiquito->adeudos, 2) }}</td></tr>
            @foreach ($finiquito->otros_conceptos ?? [] as $concepto => $monto)
                <tr><td>{{ ucfirst(str_replace('_', ' ', (string) $concepto)) }}</td><td class="monto">${{ number_format((float) $monto, 2) }}</td></tr>
            @endforeach
            @foreach (collect($desglose ?? [])->where('origen', 'manual') as $renglon)
                <tr><td>{{ $renglon['concepto'] }} ({{ $renglon['tipo'] === 'deduccion' ? 'deducción' : 'percepción' }}){{ $renglon['observaciones'] ? ' — '.$renglon['observaciones'] : '' }}</td><td class="monto">{{ $renglon['tipo'] === 'deduccion' ? '-' : '+' }} ${{ number_format((float) $renglon['importe'], 2) }}</td></tr>
            @endforeach
            <tr><td>Total percepciones</td><td class="monto">${{ number_format((float) $finiquito->total_percepciones, 2) }}</td></tr>
            <tr><td>Total deducciones</td><td class="monto">- ${{ number_format((float) $finiquito->total_deducciones, 2) }}</td></tr>
            <tr class="total-final"><td>Neto a pagar</td><td class="monto">${{ number_format((float) $finiquito->total_ajustado, 2) }}</td></tr>
        </tbody>
    </table>

    @if ($finiquito->comentarios_ajuste)
        <p class="seccion-titulo">Comentarios del ajuste</p>
        <p>{{ $finiquito->comentarios_ajuste }}</p>
    @endif

    <p class="leyenda">
        Cálculo editable y sujeto a validación de RH/contabilidad. Este documento no sustituye una revisión legal o
        contable formal antes de su entrega y pago.
    </p>

    <div class="firma">
        <div class="linea"></div>
        <p>Firma de recibido del colaborador</p>
    </div>
</body>
</html>
