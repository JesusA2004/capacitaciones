<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <style>
        @page { margin: 40px; }
        body { font-family: 'Helvetica', sans-serif; color: #111111; font-size: 11px; }
        .marca { font-size: 11px; letter-spacing: 2px; text-transform: uppercase; color: #2DC7D3; margin-bottom: 4px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .meta { font-size: 10px; color: #555555; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #dddddd; padding: 6px 8px; text-align: left; }
        th { background: #f3f4f6; font-weight: bold; }
        tr:nth-child(even) td { background: #fafafa; }
        .vacio { color: #888888; font-style: italic; padding: 12px 0; }
    </style>
</head>
<body>
    <p class="marca">MR. LANA PEOPLE — Reportes RH</p>
    <h1>{{ $titulo }}</h1>
    <p class="meta">Generado el {{ now()->format('d/m/Y H:i') }}</p>

    @if (count($filas) === 0)
        <p class="vacio">No hay datos para este reporte con los filtros seleccionados.</p>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($columnas as $columna)
                        <th>{{ $columna }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($filas as $fila)
                    <tr>
                        @foreach ($fila as $valor)
                            <td>{{ $valor ?? '—' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
