<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Número que cuenta hasta su valor al aparecer y cada vez que cambia
 * (indicadores, totales). Si `valor` no es un número (p. ej. «12 / 40» o
 * «—») se pinta tal cual, sin animar. Con «reducir movimiento» o la
 * preferencia «sin animaciones» muestra el valor final de inmediato.
 * Pensado para ir dentro de un texto con `tabular-nums`.
 */
const props = withDefaults(
    defineProps<{
        valor: number | string;
        /** Milisegundos que tarda el conteo. */
        duracion?: number;
        decimales?: number;
        prefijo?: string;
        sufijo?: string;
    }>(),
    { duracion: 450, decimales: 0, prefijo: '', sufijo: '' },
);

const formato = new Intl.NumberFormat('es-MX', {
    minimumFractionDigits: props.decimales,
    maximumFractionDigits: props.decimales,
});

function esNumero(valor: number | string): valor is number {
    return typeof valor === 'number' && Number.isFinite(valor);
}

const mostrado = ref(esNumero(props.valor) ? 0 : props.valor);
let cuadro = 0;

function sinMovimiento(): boolean {
    return (
        document.documentElement.classList.contains('sin-animaciones') ||
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

function contarHasta(destino: number | string): void {
    cancelAnimationFrame(cuadro);

    if (!esNumero(destino)) {
        mostrado.value = destino;

        return;
    }

    const origen = esNumero(mostrado.value) ? mostrado.value : 0;

    if (origen === destino || sinMovimiento()) {
        mostrado.value = destino;

        return;
    }

    const inicio = performance.now();

    const paso = (ahora: number): void => {
        const avance = Math.min(1, (ahora - inicio) / props.duracion);
        // Desacelera al final (ease-out cúbico).
        const suavizado = 1 - Math.pow(1 - avance, 3);

        mostrado.value = origen + (destino - origen) * suavizado;

        if (avance < 1) {
            cuadro = requestAnimationFrame(paso);
        } else {
            mostrado.value = destino;
        }
    };

    cuadro = requestAnimationFrame(paso);
}

function texto(valor: number | string): string {
    return esNumero(valor)
        ? `${props.prefijo}${formato.format(valor)}${props.sufijo}`
        : valor;
}

onMounted(() => contarHasta(props.valor));
watch(() => props.valor, contarHasta);
onBeforeUnmount(() => cancelAnimationFrame(cuadro));
</script>

<template>
    <!-- El lector de pantalla lee el valor final, no cada cuadro del conteo. -->
    <span>
        <span aria-hidden="true">{{ texto(mostrado) }}</span>
        <span class="sr-only">{{ texto(valor) }}</span>
    </span>
</template>
