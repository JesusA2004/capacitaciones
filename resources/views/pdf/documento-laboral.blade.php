<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <style>
        @page { margin: 48px 52px; }
        * { box-sizing: border-box; }
        body { font-family: 'Helvetica', sans-serif; color: #111827; font-size: 11px; line-height: 1.5; }
        h1, h2, h3 { color: #111827; }
        table { width: 100%; border-collapse: collapse; }
        .pie { margin-top: 24px; font-size: 8.5px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 6px; }
    </style>
</head>
<body>
    {{-- Cuerpo capturado por RH/Jurídico en la plantilla (DocumentTemplate::contenido_html)
         con las variables ya sustituidas y escapadas por MotorDocumentalService. --}}
    {!! $cuerpo !!}

    <p class="pie">
        {{ $titulo }} · Folio interno {{ $folio }} · Plantilla {{ $clave }} v{{ $version }} · Generado el {{ $generadoEn }}
    </p>
</body>
</html>
