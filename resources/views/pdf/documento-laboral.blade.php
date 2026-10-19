@php($pal = config('pdf_identidad'))
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    {{-- Identidad institucional PEOPLE: paleta única en config/pdf_identidad.php. --}}
    <style>
        @page { margin: 48px 52px; }
        * { box-sizing: border-box; }
        body { font-family: 'Helvetica', sans-serif; color: {{ $pal['texto'] }}; font-size: 11px; line-height: 1.5; }
        h1, h2, h3 { color: {{ $pal['petroleo'] }}; }
        table { width: 100%; border-collapse: collapse; }
        .pie { margin-top: 24px; font-size: 8.5px; color: {{ $pal['texto_suave'] }}; border-top: 1px solid {{ $pal['borde'] }}; padding-top: 6px; }
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
