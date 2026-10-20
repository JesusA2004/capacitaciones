{{--
    Acta administrativa circunstanciada — formato PROPIO y ÚNICO de MR. LANA
    PEOPLE (un solo tipo de acta, decisión 2026-10-15). Documento
    administrativo FACTUAL: no cita artículos de ley ni cláusulas, y no
    imprime metadata técnica (usuario interno, timestamps de sistema,
    «autorizado por»). Todo sale de $d (ActaPdfService::datos()); la vista
    previa y el final usan esta misma vista con los mismos datos.
    Solo tablas y bloques simples: Chrome y DomPDF lo imprimen igual.
--}}
@php($pal = config('pdf_identidad'))
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $d['titulo'] }} {{ $d['folio'] }}</title>
    {{-- Identidad institucional PEOPLE: paleta única en config/pdf_identidad.php. --}}
    <style>
        @page {
            @if ($motor === 'browsershot') size: letter portrait; @endif
            margin: 16mm 18mm 20mm 18mm;
        }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 10.5pt; line-height: 1.45; color: {{ $pal['texto'] }}; }
        table { width: 100%; border-collapse: collapse; }
        .encabezado td { vertical-align: middle; }
        .logo { height: 15mm; }
        .titulo { text-align: right; }
        .titulo h1 { margin: 0; font-size: 15pt; letter-spacing: 1.5pt; color: {{ $pal['petroleo'] }}; }
        .titulo .folio { font-size: 9.5pt; color: {{ $pal['texto_suave'] }}; margin-top: 1mm; }
        .barra { height: 1.2mm; background: {{ $pal['dorado'] }}; margin: 3mm 0 4mm; }
        .datos td { padding: 1.2mm 2mm; border: 0.3mm solid {{ $pal['borde_claro'] }}; font-size: 9.5pt; vertical-align: top; }
        .datos .etiqueta { width: 22%; background: {{ $pal['salvia'] }}; color: {{ $pal['texto_suave'] }}; font-weight: bold; }
        h2 { font-size: 10pt; letter-spacing: 1pt; color: {{ $pal['petroleo'] }}; border-bottom: 0.4mm solid {{ $pal['petroleo'] }}; padding-bottom: 1mm; margin: 6mm 0 2mm; text-transform: uppercase; page-break-after: avoid; }
        .texto { white-space: pre-line; text-align: justify; margin: 0; }
        .manifestacion { margin: 0 0 2mm; }
        .manifestacion strong { display: block; margin-bottom: 0.5mm; }
        .lista { margin: 0; padding-left: 5mm; }
        .firmas { margin-top: 6mm; page-break-inside: avoid; }
        .firmas td { width: 50%; padding: 13mm 6mm 1mm; text-align: center; vertical-align: bottom; font-size: 9.5pt; }
        .linea { border-top: 0.3mm solid #1f2933; padding-top: 1.5mm; }
        .cargo { color: #4b5563; font-size: 8.5pt; }
        .bloque { page-break-inside: avoid; }
    </style>
</head>
<body>
    <table class="encabezado">
        <tr>
            <td style="width: 45%">
                @if ($d['logo'])
                    <img class="logo" src="{{ $d['logo'] }}" alt="MR. LANA">
                @else
                    <strong>{{ $d['empresa'] }}</strong>
                @endif
            </td>
            <td class="titulo">
                <h1>{{ $d['titulo'] }}</h1>
                <div class="folio">Folio {{ $d['folio'] }}</div>
            </td>
        </tr>
    </table>
    <div class="barra"></div>

    <table class="datos">
        <tr>
            <td class="etiqueta">Empresa</td><td>{{ $d['empresa'] }}</td>
            <td class="etiqueta">Fecha</td><td>{{ $d['fecha'] }}@if ($d['hora']), {{ $d['hora'] }} h @endif</td>
        </tr>
        <tr>
            <td class="etiqueta">Sucursal</td><td>{{ $d['sucursal'] ?? '—' }}</td>
            <td class="etiqueta">Ciudad</td><td>{{ $d['ciudad'] ?? '—' }}</td>
        </tr>
        @if ($d['lugar'])
            <tr><td class="etiqueta">Lugar</td><td colspan="3">{{ $d['lugar'] }}</td></tr>
        @endif
        <tr>
            <td class="etiqueta">Colaborador</td>
            <td>{{ $d['colaborador']['nombre'] }}@if ($d['colaborador']['numero_empleado']) ({{ $d['colaborador']['numero_empleado'] }})@endif</td>
            <td class="etiqueta">Puesto</td><td>{{ $d['colaborador']['puesto'] ?? '—' }}</td>
        </tr>
        <tr><td class="etiqueta">Jefe inmediato</td><td colspan="3">{{ $d['colaborador']['jefe'] ?? '—' }}</td></tr>
    </table>

    @if (filled($d['motivo']))
        <div class="bloque">
            <h2>Motivo</h2>
            <p class="texto">{{ $d['motivo'] }}</p>
        </div>
    @endif

    <h2>Hechos y circunstancias</h2>
    <p class="texto">{{ $d['hechos'] }}</p>

    <div class="bloque">
            <h2>Personas presentes</h2>
            <ul class="lista">
                <li>{{ $d['colaborador']['nombre'] }}@if ($d['colaborador']['puesto']) — {{ $d['colaborador']['puesto'] }}@endif</li>
                @foreach ($d['testigos'] as $t)
                    <li>{{ $t['nombre'] }}@if ($t['cargo']) — {{ $t['cargo'] }}@endif (testigo)</li>
                @endforeach
                {{-- Por la empresa: su representante legal, nombre a pluma (nunca la cuenta que capturó). --}}
                <li>{{ $d['empresa'] }} — {{ $d['levanta']['cargo'] }}</li>
            </ul>
    </div>

    @if (count($d['anexos']) > 0)
        <div class="bloque">
            <h2>Evidencias y anexos</h2>
            <ul class="lista">
                @foreach ($d['anexos'] as $anexo)
                    <li>{{ $anexo }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="bloque">
        <h2>Manifestación del colaborador</h2>
        <p class="texto">{{ $d['manifestacion_colaborador'] }}</p>
    </div>

    @if (count($d['otras_manifestaciones']) > 0)
        <div class="bloque">
            <h2>Otras manifestaciones</h2>
            @foreach ($d['otras_manifestaciones'] as $m)
                <div class="manifestacion">
                    <strong>{{ $m['persona'] }}</strong>
                    <p class="texto">{{ $m['declaracion'] }}</p>
                </div>
            @endforeach
        </div>
    @endif

    <div class="bloque">
        <h2>Testigos</h2>
        <ul class="lista">
            @foreach ($d['testigos'] as $t)
                <li>{{ $t['nombre'] }}@if ($t['cargo']) — {{ $t['cargo'] }}@endif</li>
            @endforeach
        </ul>
    </div>

    <table class="firmas">
        <tr>
            <td>
                <div class="linea">{{ $d['colaborador']['nombre'] }}</div>
                <div class="cargo">Colaborador</div>
            </td>
            <td>
                <div class="linea">&nbsp;</div>
                <div class="cargo">{{ $d['levanta']['cargo'] }}</div>
                <div class="cargo">{{ $d['empresa'] }}</div>
            </td>
        </tr>
        <tr>
            @foreach ($d['testigos'] as $i => $t)
                <td>
                    <div class="linea">{{ $t['nombre'] }}</div>
                    <div class="cargo">Testigo {{ $i + 1 }}{{ $t['cargo'] ? ' · '.$t['cargo'] : '' }}</div>
                </td>
            @endforeach
        </tr>
    </table>
</body>
</html>
