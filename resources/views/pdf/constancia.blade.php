<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Constancia — {{ $certificado->curso->titulo }}</title>
    <style>
        @page { margin: 60px 50px; }
        body { font-family: 'Helvetica', sans-serif; color: #111111; text-align: center; }
        .marco { border: 4px solid #64D64B; padding: 40px; height: 620px; }
        .marca { font-size: 14px; letter-spacing: 4px; text-transform: uppercase; color: #2DC7D3; margin-bottom: 30px; }
        h1 { font-size: 30px; margin: 0 0 10px; }
        .subtitulo { font-size: 14px; color: #555555; margin-bottom: 40px; }
        .nombre { font-size: 26px; font-weight: bold; margin: 20px 0; border-bottom: 1px solid #cccccc; display: inline-block; padding-bottom: 8px; }
        .curso { font-size: 20px; margin: 20px 0; color: #111111; }
        .detalle { font-size: 13px; color: #555555; margin-top: 40px; }
        .folio { position: absolute; bottom: 40px; left: 0; right: 0; font-size: 11px; color: #888888; }
    </style>
</head>
<body>
    <div class="marco">
        <p class="marca">Mr. Lana — Portal de Capacitación</p>
        <h1>Constancia de Finalización</h1>
        <p class="subtitulo">Se otorga la presente constancia a:</p>

        <p class="nombre">{{ $certificado->usuario->nombreCompleto() }}</p>

        <p class="subtitulo">por haber completado satisfactoriamente el curso:</p>
        <p class="curso">«{{ $certificado->curso->titulo }}»</p>

        <p class="detalle">
            Fecha de finalización: {{ $certificado->emitido_en->format('d/m/Y') }}<br>
            @if($certificado->curso->duracion_estimada_minutos)
                Duración estimada: {{ $certificado->curso->duracion_estimada_minutos }} minutos<br>
            @endif
        </p>

        <p class="folio">
            Folio verificable: {{ $certificado->folio }} — {{ $urlVerificacion }}
        </p>
    </div>
</body>
</html>
