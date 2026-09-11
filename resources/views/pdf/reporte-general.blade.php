@php
    use App\Support\Export\ChartData;
    use App\Support\Export\Pdf\ChartRenderer;

    $cards = $metricas['cards'];
    $g = $metricas['graficas'];

    $bloques = [
        ['Sucursal', 'Colaboradores por sucursal', collect($g['colaboradoresPorSucursal'])->map(fn (array $f) => [$f['etiqueta'], $f['valor']])->all()],
        ['Departamento', 'Colaboradores por departamento', collect($g['colaboradoresPorDepartamento'])->map(fn (array $f) => [$f['etiqueta'], $f['valor']])->all()],
        ['Puesto', 'Colaboradores por puesto', collect($g['colaboradoresPorPuesto'])->map(fn (array $f) => [$f['etiqueta'], $f['valor']])->all()],
        ['Estado', 'Expedientes por estado', collect($g['expedientesEstado'])->map(fn (array $f) => [$f['etiqueta'], $f['valor']])->all()],
        ['Estado', 'Documentos por estado', collect($g['documentosPorEstado'])->map(fn (array $f) => [$f['etiqueta'], $f['valor']])->all()],
        ['Módulo', 'Reclutamiento y solicitudes', collect($otrosModulos)->map(fn (array $f) => [$f['etiqueta'], $f['valor']])->all()],
    ];

    $graficas = collect($bloques)
        ->map(fn (array $b) => ['titulo' => $b[1], 'datos' => ChartData::fromTable([$b[0], 'Total'], $b[2])])
        ->filter(fn (array $b) => $b['datos'] !== null)
        ->values();
@endphp

@component('pdf.layout', ['titulo' => 'Reporte general — Portal RH'])

    <table class="kpi-fila">
        <tr>
            <td style="width: 25%;">
                <div class="kpi-tarjeta">
                    <p class="kpi-etiqueta">Colaboradores activos</p>
                    <p class="kpi-valor">{{ number_format($cards['colaboradores_activos'], 0, ',', '.') }}</p>
                </div>
            </td>
            <td style="width: 25%;">
                <div class="kpi-tarjeta" style="border-left-color:#ef4444;">
                    <p class="kpi-etiqueta">Bajas del mes</p>
                    <p class="kpi-valor">{{ number_format($cards['bajas_del_mes'], 0, ',', '.') }}</p>
                </div>
            </td>
            <td style="width: 25%;">
                <div class="kpi-tarjeta" style="border-left-color:#2dc7d3;">
                    <p class="kpi-etiqueta">Expedientes completos</p>
                    <p class="kpi-valor">{{ number_format($cards['expedientes_completos'], 0, ',', '.') }}</p>
                </div>
            </td>
            <td style="width: 25%;">
                <div class="kpi-tarjeta" style="border-left-color:#e9c468;">
                    <p class="kpi-etiqueta">Documentos pendientes</p>
                    <p class="kpi-valor">{{ number_format($cards['documentos_pendientes'], 0, ',', '.') }}</p>
                </div>
            </td>
        </tr>
    </table>

    @if ($graficas->isEmpty())
        <p class="vacio">Todavía no hay datos suficientes para graficar.</p>
    @else
        @foreach ($graficas->chunk(2) as $fila)
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    @foreach ($fila as $bloque)
                        <td style="width: {{ $fila->count() > 1 ? '50%' : '100%' }}; vertical-align: top; padding-right: 14px;">
                            {!! ChartRenderer::render($bloque['datos'], $bloque['titulo']) !!}
                        </td>
                    @endforeach
                </tr>
            </table>
        @endforeach
    @endif

@endcomponent
