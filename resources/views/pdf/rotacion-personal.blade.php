@php
    use App\Support\Export\ChartData;
    use App\Support\Export\Pdf\ChartRenderer;

    $tendencia = collect($datos['tendenciaMensual']);
    $tendenciaAltas = ChartData::fromTable(['Mes', 'Altas'], $tendencia->map(fn (array $m) => [$m['mes'], $m['altas']])->all());
    $tendenciaBajas = ChartData::fromTable(['Mes', 'Bajas'], $tendencia->map(fn (array $m) => [$m['mes'], $m['bajas']])->all());
    $genero = ChartData::fromTable(['Género', 'Total'], collect($datos['genero'])->map(fn (array $f) => [$f['etiqueta'], $f['valor']])->all());
@endphp

@component('pdf.layout', ['titulo' => 'Rotación de personal'])

    <p style="color:#6b7280; font-size: 11px; margin-bottom: 10px;">
        Periodo: {{ $datos['periodo']['desde'] }} — {{ $datos['periodo']['hasta'] }}
    </p>

    <table class="kpi-fila">
        <tr>
            <td style="width: 20%;">
                <div class="kpi-tarjeta">
                    <p class="kpi-etiqueta">Plantilla actual</p>
                    <p class="kpi-valor">{{ number_format($datos['plantilla_actual'], 0, ',', '.') }}</p>
                </div>
            </td>
            <td style="width: 20%;">
                <div class="kpi-tarjeta" style="border-left-color:#10b981;">
                    <p class="kpi-etiqueta">Altas</p>
                    <p class="kpi-valor">{{ number_format($datos['altas'], 0, ',', '.') }}</p>
                </div>
            </td>
            <td style="width: 20%;">
                <div class="kpi-tarjeta" style="border-left-color:#ef4444;">
                    <p class="kpi-etiqueta">Bajas</p>
                    <p class="kpi-valor">{{ number_format($datos['bajas'], 0, ',', '.') }}</p>
                </div>
            </td>
            <td style="width: 20%;">
                <div class="kpi-tarjeta" style="border-left-color:#e9c468;">
                    <p class="kpi-etiqueta">% Rotación</p>
                    <p class="kpi-valor">{{ $datos['rotacion_porcentaje'] }}%</p>
                </div>
            </td>
            <td style="width: 20%;">
                <div class="kpi-tarjeta" style="border-left-color:#2dc7d3;">
                    <p class="kpi-etiqueta">Cumplimiento headcount</p>
                    <p class="kpi-valor">{{ $datos['eficiencia']['cumplimiento'] }}%</p>
                </div>
            </td>
        </tr>
    </table>

    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            @if ($tendenciaAltas)
                <td style="width: 33%; vertical-align: top; padding-right: 14px;">
                    {!! ChartRenderer::render($tendenciaAltas, 'Altas por mes') !!}
                </td>
            @endif
            @if ($tendenciaBajas)
                <td style="width: 33%; vertical-align: top; padding-right: 14px;">
                    {!! ChartRenderer::render($tendenciaBajas, 'Bajas por mes') !!}
                </td>
            @endif
            @if ($genero)
                <td style="width: 33%; vertical-align: top;">
                    {!! ChartRenderer::render($genero, 'Composición por género') !!}
                </td>
            @endif
        </tr>
    </table>

    <table style="width: 100%; border-collapse: collapse; margin-top: 14px;">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-right: 14px;">
                <p style="font-weight: bold; font-size: 12px; margin-bottom: 6px;">Altas por sucursal</p>
                <table style="width: 100%; font-size: 10px; border-collapse: collapse;">
                    @foreach ($datos['altasPorSucursal'] as $fila)
                        <tr>
                            <td style="padding: 3px 0; border-bottom: 1px solid #eee;">{{ $fila['etiqueta'] }}</td>
                            <td style="padding: 3px 0; border-bottom: 1px solid #eee; text-align: right;">{{ $fila['valor'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </td>
            <td style="width: 50%; vertical-align: top;">
                <p style="font-weight: bold; font-size: 12px; margin-bottom: 6px;">Bajas por sucursal</p>
                <table style="width: 100%; font-size: 10px; border-collapse: collapse;">
                    @foreach ($datos['bajasPorSucursal'] as $fila)
                        <tr>
                            <td style="padding: 3px 0; border-bottom: 1px solid #eee;">{{ $fila['etiqueta'] }}</td>
                            <td style="padding: 3px 0; border-bottom: 1px solid #eee; text-align: right;">{{ $fila['valor'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>

@endcomponent
