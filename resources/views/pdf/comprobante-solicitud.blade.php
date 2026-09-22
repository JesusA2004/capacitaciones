<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <style>
        @page { margin: 40px 44px; }
        body { font-family: 'Helvetica', sans-serif; color: #1f2937; font-size: 11px; line-height: 1.45; }
        .marca { font-size: 10px; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; color: #2dc7d3; margin: 0 0 4px; }
        h1 { font-size: 18px; margin: 0 0 4px; color: #274754; }
        .meta { font-size: 9.5px; color: #6b7280; margin: 0 0 16px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        td.etiqueta { color: #6b7280; width: 35%; }
        .leyenda { margin-top: 18px; padding: 10px 12px; background: #f3f4f6; border-radius: 6px; font-size: 9.5px; color: #374151; }
    </style>
</head>
<body>
    <p class="marca">MR. LANA PEOPLE</p>
    <h1>{{ $titulo }}</h1>
    <p class="meta">Generado el {{ now()->format('d/m/Y H:i') }}</p>

    {{-- Comprobante interno de datos: no contiene cláusulas jurídicas. --}}
    <table>
        <tr><td class="etiqueta">Folio</td><td>{{ $solicitud->folio }}</td></tr>
        <tr><td class="etiqueta">Tipo</td><td>{{ $solicitud->tipo->etiqueta() }}</td></tr>
        <tr><td class="etiqueta">Colaborador</td><td>{{ $colaborador->nombreCompleto() }} ({{ $colaborador->numero_empleado ?? 'sin número' }})</td></tr>
        @if ($solicitud->fecha_inicio)
            <tr><td class="etiqueta">Fecha inicial</td><td>{{ $solicitud->fecha_inicio->format('d/m/Y') }}</td></tr>
        @endif
        @if ($solicitud->fecha_fin)
            <tr><td class="etiqueta">Fecha final</td><td>{{ $solicitud->fecha_fin->format('d/m/Y') }}</td></tr>
        @endif
        @if ($solicitud->dias_solicitados)
            <tr><td class="etiqueta">Días</td><td>{{ $solicitud->dias_solicitados }}</td></tr>
        @endif
        <tr><td class="etiqueta">Motivo</td><td>{{ $solicitud->motivo }}</td></tr>
        <tr><td class="etiqueta">Estado</td><td>{{ $solicitud->estado->etiqueta() }}</td></tr>
        <tr><td class="etiqueta">Aprobada por</td><td>{{ $solicitud->revisadoPor ? trim($solicitud->revisadoPor->name.' '.$solicitud->revisadoPor->apellidos) : '—' }}</td></tr>
        <tr><td class="etiqueta">Fecha de aprobación</td><td>{{ $solicitud->revisado_en?->format('d/m/Y H:i') ?? '—' }}</td></tr>
    </table>

    <p class="leyenda">Comprobante interno generado por MR. LANA PEOPLE a partir de la solicitud aprobada. El historial completo de la solicitud queda en el sistema.</p>
</body>
</html>
