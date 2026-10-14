{{-- Embudo + fase donde se detuvieron + motivos de un grupo de candidatos ($r). --}}
@php
    $maxEmbudo = max(1, ...array_map(fn ($f) => $f['total'], $r['embudo']));
    $maxFase = max(1, 0, ...array_map(fn ($f) => $f['total'], $r['detenidos_por_fase']));
@endphp

<div class="caja" style="margin-bottom: 10px;">
    <h3>Embudo: ¿hasta dónde llegaron?</h3>
    @foreach ($r['embudo'] as $fila)
        <div class="barra-fila">
            <span class="barra-etiqueta">{{ $fila['etapa'] }}</span>
            <div class="barra-pista"><div class="barra-relleno" style="width: {{ round($fila['total'] / $maxEmbudo * 100, 1) }}%;"></div></div>
            <span class="barra-valor">{{ $fila['total'] }}@if ($fila['porcentaje'] !== null) · {{ number_format($fila['porcentaje'], 0) }}%@endif</span>
        </div>
    @endforeach
</div>

<div class="columnas">
    <div class="caja">
        <h3>¿En qué fase se detuvieron?</h3>
        @forelse ($r['detenidos_por_fase'] as $fila)
            <div class="barra-fila">
                <span class="barra-etiqueta">{{ $fila['fase'] }}</span>
                <div class="barra-pista"><div class="barra-relleno peligro" style="width: {{ round($fila['total'] / $maxFase * 100, 1) }}%;"></div></div>
                <span class="barra-valor">{{ $fila['total'] }}</span>
            </div>
        @empty
            <p class="vacio">Nadie ha salido del proceso.</p>
        @endforelse

        @if (count($r['en_proceso_por_fase']) > 0)
            <h3 style="margin-top: 10px;">Siguen en proceso</h3>
            @foreach ($r['en_proceso_por_fase'] as $fila)
                <div class="motivo" style="padding-left: 0;"><span>{{ $fila['fase'] }}</span><span>{{ $fila['total'] }}</span></div>
            @endforeach
        @endif
    </div>

    <div class="caja">
        <h3>¿Por qué no continuaron?</h3>
        @forelse ($r['motivos'] as $grupo)
            <div class="categoria"><span>{{ $grupo['categoria'] }}</span><span>{{ $grupo['total'] }}</span></div>
            @foreach ($grupo['motivos'] as $m)
                <div class="motivo"><span>{{ $m['motivo'] }}</span><span>{{ $m['total'] }}</span></div>
            @endforeach
        @empty
            <p class="vacio">Sin salidas registradas.</p>
        @endforelse
    </div>
</div>
