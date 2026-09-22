<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recibo de nómina — {{ $colaborador->name }} {{ $colaborador->apellidos }}</title>
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
        table.conceptos tr.total td { font-weight: bold; background: #dcfce7; color: #166534; font-size: 12px; }
        .leyenda { margin-top: 18px; padding: 10px 12px; background: #fef3c7; color: #92400e; border-radius: 6px; font-size: 9.5px; }
        .firma { margin-top: 60px; text-align: center; font-size: 10px; color: #374151; }
        .firma .linea { border-top: 1px solid #9ca3af; width: 260px; margin: 0 auto 6px; }
    </style>
</head>
<body>
    <div class="encabezado">
        <p class="marca">MR. LANA PEOPLE</p>
        <h1>RECIBO INTERNO DE NÓMINA - NO FISCAL</h1>
        <p class="meta">
            @if ($recibo->folio) Folio {{ $recibo->folio }} · @endif
            @if ($recibo->tipo_periodo === 'semanal' && $recibo->numero_periodo) Semana {{ $recibo->numero_periodo }}/{{ $recibo->ejercicio }} · @endif
            Periodo {{ $periodo_inicio->format('d/m/Y') }} — {{ $periodo_fin->format('d/m/Y') }} ·
            Fecha de pago {{ $fecha_pago->format('d/m/Y') }} ·
            Generado el {{ now()->format('d/m/Y H:i') }}
        </p>
    </div>

    <table class="datos">
        <tr>
            <td><span class="etiqueta">Colaborador:</span> {{ $colaborador->name }} {{ $colaborador->apellidos }}</td>
            <td><span class="etiqueta">Número de empleado:</span> {{ $colaborador->numero_empleado ?? '—' }}</td>
        </tr>
        <tr>
            <td><span class="etiqueta">Puesto:</span> {{ $colaborador->puesto?->nombre ?? '—' }}</td>
            <td><span class="etiqueta">Sucursal:</span> {{ $colaborador->sucursalPrincipal?->nombre ?? '—' }}</td>
        </tr>
        <tr>
            <td><span class="etiqueta">Fecha de ingreso:</span> {{ $colaborador->fecha_ingreso?->format('d/m/Y') ?? '—' }}</td>
            <td><span class="etiqueta">NSS:</span> {{ $colaborador->nss ?? '—' }}</td>
        </tr>
    </table>

    <p class="seccion-titulo">Percepciones</p>
    <table class="conceptos">
        <thead>
            <tr><th>Concepto</th><th style="text-align:right">Monto</th></tr>
        </thead>
        <tbody>
            @foreach ($percepciones as $concepto)
                <tr><td>{{ $concepto['concepto'] }}</td><td class="monto">${{ number_format($concepto['monto'], 2) }}</td></tr>
            @endforeach
            <tr><td><strong>Total percepciones</strong></td><td class="monto"><strong>${{ number_format($total_percepciones, 2) }}</strong></td></tr>
        </tbody>
    </table>

    @if (count($deducciones) > 0)
        <p class="seccion-titulo">Deducciones</p>
        <table class="conceptos">
            <thead>
                <tr><th>Concepto</th><th style="text-align:right">Monto</th></tr>
            </thead>
            <tbody>
                @foreach ($deducciones as $concepto)
                    <tr><td>{{ $concepto['concepto'] }}</td><td class="monto">- ${{ number_format($concepto['monto'], 2) }}</td></tr>
                @endforeach
                <tr><td><strong>Total deducciones</strong></td><td class="monto"><strong>- ${{ number_format($total_deducciones, 2) }}</strong></td></tr>
            </tbody>
        </table>
    @endif

    <table class="conceptos">
        <tr class="total"><td>Neto a pagar</td><td class="monto">${{ number_format($neto, 2) }}</td></tr>
    </table>

    @if ($recibo->observaciones)
        <p class="seccion-titulo">Observaciones</p>
        <p>{{ $recibo->observaciones }}</p>
    @endif

    <p class="leyenda">
        <strong>RECIBO INTERNO DE NÓMINA - NO FISCAL.</strong>
        Comprobante interno informativo. No es CFDI, no está timbrado y no sustituye al sistema de nómina.
        No es un cálculo de ISR/IMSS — para efectos fiscales, la nómina oficial se procesa con el proveedor
        certificado de la empresa.
    </p>

    <div class="firma">
        <div class="linea"></div>
        <p>Firma de recibido del colaborador</p>
    </div>
</body>
</html>
