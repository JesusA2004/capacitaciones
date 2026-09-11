<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo ?? 'Reporte' }}</title>
    <style>
        @page { margin: 34px 36px 46px; }

        * { box-sizing: border-box; }

        body {
            font-family: 'Helvetica', sans-serif;
            color: #1f2937;
            font-size: 11px;
            line-height: 1.4;
        }

        .marca {
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #2dc7d3;
            margin: 0 0 4px;
        }

        h1 { font-size: 20px; margin: 0 0 4px; color: #274754; }

        .meta { font-size: 9.5px; color: #6b7280; margin: 0 0 16px; }

        .encabezado {
            border-bottom: 2px solid #274754;
            padding-bottom: 10px;
            margin-bottom: 16px;
        }

        /* --- Tarjetas KPI --- */
        .kpi-fila { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .kpi-fila td { padding: 0 6px 0 0; vertical-align: top; }
        .kpi-fila td:last-child { padding-right: 0; }
        .kpi-tarjeta {
            border: 1px solid #e5e7eb;
            border-left: 4px solid #64d64b;
            border-radius: 6px;
            padding: 8px 10px;
        }
        .kpi-etiqueta {
            font-size: 8.5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            margin: 0 0 3px;
        }
        .kpi-valor { font-size: 19px; font-weight: bold; color: #274754; margin: 0; }

        /* --- Gráficas --- */
        .grafica { margin: 0 0 18px; }
        .grafica-titulo { font-size: 11.5px; font-weight: bold; color: #274754; margin: 0 0 8px; }
        .grafica-nota { font-size: 8.5px; color: #9ca3af; font-style: italic; margin: 4px 0 0; }

        .barra-apilada {
            width: 100%;
            height: 26px;
            border-collapse: collapse;
            border-radius: 4px;
            overflow: hidden;
        }
        .barra-apilada td { height: 26px; }

        .leyenda { margin-top: 10px; }
        .leyenda-item {
            display: inline-block;
            margin: 0 16px 6px 0;
            font-size: 9.5px;
            color: #374151;
        }
        .leyenda-punto {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 4px;
            margin-right: 5px;
        }
        .leyenda-pct { color: #9ca3af; }

        .grafica-barras { width: 100%; border-collapse: collapse; }
        .grafica-columna { text-align: center; vertical-align: bottom; padding: 0 4px; }
        .grafica-columna-interna { width: 100%; border-collapse: collapse; }
        .barra-celda { vertical-align: bottom; text-align: center; padding: 0 2px; }
        .barra { margin: 0 auto; width: 22px; border-radius: 3px 3px 0 0; }
        .valor-barra { font-size: 8.5px; font-weight: bold; color: #374151; margin-bottom: 2px; }
        .etiqueta-barra {
            text-align: center;
            font-size: 8px;
            color: #6b7280;
            padding-top: 5px;
            border-top: 1px solid #e5e7eb;
        }

        /* --- Tabla de detalle --- */
        .seccion-titulo {
            font-size: 12.5px;
            font-weight: bold;
            color: #274754;
            margin: 4px 0 8px;
            padding-top: 4px;
            border-top: 1px solid #e5e7eb;
        }
        table.detalle { width: 100%; border-collapse: collapse; }
        table.detalle th, table.detalle td {
            border: 1px solid #e5e7eb;
            padding: 5px 7px;
            text-align: left;
            font-size: 9.5px;
        }
        table.detalle th {
            background: #274754;
            color: #ffffff;
            font-weight: bold;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        table.detalle tr:nth-child(even) td { background: #f9fafb; }

        .badge {
            display: inline-block;
            padding: 1px 7px;
            border-radius: 8px;
            font-size: 8.5px;
            font-weight: bold;
        }
        .badge-ok { background: #dcfce7; color: #166534; }
        .badge-warn { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-neutral { background: #f3f4f6; color: #374151; }

        .vacio { color: #9ca3af; font-style: italic; padding: 14px 0; text-align: center; }

        footer {
            position: fixed;
            bottom: -34px;
            left: 0;
            right: 0;
            height: 24px;
            font-size: 8px;
            color: #9ca3af;
            text-align: center;
            border-top: 1px solid #e5e7eb;
            padding-top: 5px;
        }
        footer:after {
            content: "MR. LANA PEOPLE · Página " counter(page);
        }
    </style>
</head>
<body>
    <footer></footer>

    <div class="encabezado">
        <p class="marca">MR. LANA PEOPLE — Reportes</p>
        <h1>{{ $titulo ?? 'Reporte' }}</h1>
        <p class="meta">Generado el {{ now()->format('d/m/Y H:i') }}</p>
    </div>

    {{ $slot }}
</body>
</html>
