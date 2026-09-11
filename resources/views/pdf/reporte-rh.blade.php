@php
    use App\Support\Export\ChartData;
    use App\Support\Export\Pdf\ChartRenderer;

    $chartData = ChartData::fromTable($columnas, $filas);

    $totalRegistros = count($filas);
    $totalGeneral = null;
    $categoriaPrincipal = null;

    if ($chartData !== null && count($chartData->series) === 1) {
        $valores = $chartData->series[0]['valores'];
        $totalGeneral = array_sum($valores);

        if ($valores !== []) {
            $indiceMax = array_keys($valores, max($valores))[0];
            $categoriaPrincipal = $chartData->categorias[$indiceMax];
        }
    }

    $mapaBadges = [
        'activo' => 'ok', 'activos' => 'ok', 'completo' => 'ok', 'completos' => 'ok',
        'aprobado' => 'ok', 'aprobados' => 'ok', 'cubierta' => 'ok', 'enviada' => 'ok',
        'generados' => 'ok', 'disponibles' => 'ok',
        'pendiente' => 'warn', 'pendientes' => 'warn', 'en revisión' => 'warn',
        'en proceso' => 'warn', 'requiere corrección' => 'warn', 'abierta' => 'warn',
        'en reclutamiento' => 'warn', 'con candidatos' => 'warn', 'suspendido' => 'warn', 'suspendidos' => 'warn',
        'vencida' => 'danger', 'vencidas' => 'danger', 'rechazado' => 'danger', 'rechazados' => 'danger',
        'incompleto' => 'danger', 'incompletos' => 'danger', 'inactivo' => 'danger', 'inactivos' => 'danger',
    ];

    $claseBadge = function (string $valor) use ($mapaBadges): ?string {
        return $mapaBadges[mb_strtolower(trim($valor))] ?? null;
    };
@endphp

@component('pdf.layout', ['titulo' => $titulo])

    @if ($totalRegistros === 0)
        <p class="vacio">No hay datos para este reporte con los filtros seleccionados.</p>
    @else
        <table class="kpi-fila">
            <tr>
                <td style="width: {{ $totalGeneral !== null ? '33%' : '50%' }};">
                    <div class="kpi-tarjeta">
                        <p class="kpi-etiqueta">Registros</p>
                        <p class="kpi-valor">{{ number_format($totalRegistros, 0, ',', '.') }}</p>
                    </div>
                </td>
                @if ($totalGeneral !== null)
                    <td style="width: 33%;">
                        <div class="kpi-tarjeta" style="border-left-color:#2dc7d3;">
                            <p class="kpi-etiqueta">Total general</p>
                            <p class="kpi-valor">{{ number_format($totalGeneral, 0, ',', '.') }}</p>
                        </div>
                    </td>
                @endif
                @if ($categoriaPrincipal !== null)
                    <td style="width: {{ $totalGeneral !== null ? '33%' : '50%' }};">
                        <div class="kpi-tarjeta" style="border-left-color:#e9c468;">
                            <p class="kpi-etiqueta">Mayor valor</p>
                            <p class="kpi-valor" style="font-size: 13px;">{{ \Illuminate\Support\Str::limit($categoriaPrincipal, 26) }}</p>
                        </div>
                    </td>
                @endif
            </tr>
        </table>

        @if ($chartData !== null)
            {!! ChartRenderer::render($chartData, 'Distribución') !!}
        @endif

        <p class="seccion-titulo">Detalle</p>
        <table class="detalle">
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
                            <td>
                                @php $clase = is_string($valor) ? $claseBadge($valor) : null; @endphp
                                @if ($clase !== null)
                                    <span class="badge badge-{{ $clase }}">{{ $valor }}</span>
                                @else
                                    {{ $valor ?? '—' }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

@endcomponent
