@php
    use App\Support\Export\ChartData;
    use App\Support\Export\Pdf\ChartRenderer;

    $chartSucursal = ChartData::fromTable(
        ['Sucursal', 'Cumplimiento %'],
        collect($porSucursal)->map(fn (array $f) => [$f['sucursal'], $f['porcentaje']])->all(),
    );
    $chartDepartamento = ChartData::fromTable(
        ['Departamento', 'Cumplimiento %'],
        collect($porDepartamento)->map(fn (array $f) => [$f['departamento'], $f['porcentaje']])->all(),
    );

    $claseCumplimiento = function (int $porcentaje): string {
        return match (true) {
            $porcentaje >= 90 => 'ok',
            $porcentaje >= 50 => 'warn',
            default => 'danger',
        };
    };
@endphp

@component('pdf.layout', ['titulo' => 'Reporte de cumplimiento'])

    <table class="kpi-fila">
        <tr>
            <td style="width: 25%;">
                <div class="kpi-tarjeta">
                    <p class="kpi-etiqueta">Asignaciones</p>
                    <p class="kpi-valor">{{ number_format($resumen['total_asignaciones'], 0, ',', '.') }}</p>
                </div>
            </td>
            <td style="width: 25%;">
                <div class="kpi-tarjeta" style="border-left-color:#2dc7d3;">
                    <p class="kpi-etiqueta">Completadas</p>
                    <p class="kpi-valor">{{ number_format($resumen['completadas'], 0, ',', '.') }}</p>
                </div>
            </td>
            <td style="width: 25%;">
                <div class="kpi-tarjeta" style="border-left-color:#ef4444;">
                    <p class="kpi-etiqueta">Vencidas</p>
                    <p class="kpi-valor">{{ number_format($resumen['vencidas'], 0, ',', '.') }}</p>
                </div>
            </td>
            <td style="width: 25%;">
                <div class="kpi-tarjeta" style="border-left-color:#e9c468;">
                    <p class="kpi-etiqueta">Cumplimiento</p>
                    <p class="kpi-valor">{{ number_format($resumen['porcentaje_cumplimiento'], 1) }}%</p>
                </div>
            </td>
        </tr>
    </table>

    @if ($chartSucursal !== null || $chartDepartamento !== null)
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                @if ($chartSucursal !== null)
                    <td style="width: {{ $chartDepartamento !== null ? '50%' : '100%' }}; vertical-align: top; padding-right: 14px;">
                        {!! ChartRenderer::render($chartSucursal, 'Cumplimiento por sucursal') !!}
                    </td>
                @endif
                @if ($chartDepartamento !== null)
                    <td style="width: {{ $chartSucursal !== null ? '50%' : '100%' }}; vertical-align: top;">
                        {!! ChartRenderer::render($chartDepartamento, 'Cumplimiento por departamento') !!}
                    </td>
                @endif
            </tr>
        </table>
    @endif

    <p class="seccion-titulo">Detalle por colaborador</p>

    @if (count($colaboradores) === 0)
        <p class="vacio">No hay colaboradores para mostrar con estos filtros.</p>
    @else
        <table class="detalle">
            <thead>
                <tr>
                    <th>Colaborador</th>
                    <th>Sucursal</th>
                    <th>Departamento</th>
                    <th>Asignadas</th>
                    <th>Completadas</th>
                    <th>Vencidas</th>
                    <th>Cumplimiento</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($colaboradores as $fila)
                    <tr>
                        <td>{{ $fila['colaborador'] }}</td>
                        <td>{{ $fila['sucursal'] }}</td>
                        <td>{{ $fila['departamento'] }}</td>
                        <td>{{ $fila['total'] }}</td>
                        <td>{{ $fila['completadas'] }}</td>
                        <td>{{ $fila['vencidas'] }}</td>
                        <td><span class="badge badge-{{ $claseCumplimiento($fila['porcentaje']) }}">{{ $fila['porcentaje'] }}%</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

@endcomponent
