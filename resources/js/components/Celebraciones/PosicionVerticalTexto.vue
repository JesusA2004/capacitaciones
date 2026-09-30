<script setup lang="ts">
import { GripVertical } from '@lucide/vue';
import { computed, ref } from 'vue';

/**
 * Marcador arrastrable sobre la vista previa REAL de la tarjeta: deja a RH
 * mover con el dedo/mouse en qué altura empieza UN bloque de texto
 * (título, nombre o frase/mensaje — cada uno el suyo, independiente de los
 * otros dos). Varias instancias conviven sobre la misma imagen (ver
 * Rh/Cumpleanos/Configuracion.vue y Rh/Aniversarios/Configuracion.vue), por
 * eso cada `variante` tiene su propio color y se ancla a un lado distinto
 * (izquierda/centro/derecha) para poder agarrar la correcta aunque dos
 * queden a la misma altura.
 *
 * `modelValue` es una fracción 0–1 de la altura de la tarjeta (misma unidad
 * que guarda el backend en texto_titulo_y/texto_nombre_y/texto_frase_y, ver
 * CelebracionConfiguracion).
 */
const props = withDefaults(
    defineProps<{
        modelValue: number;
        variante: 'titulo' | 'nombre' | 'frase';
        minimo?: number;
        maximo?: number;
    }>(),
    {
        minimo: 0.02,
        maximo: 0.9,
    },
);

const emit = defineEmits<{
    'update:modelValue': [valor: number];
}>();

const CONFIG: Record<
    typeof props.variante,
    { etiqueta: string; posicion: string; claseFondo: string; claseBorde: string }
> = {
    titulo: {
        etiqueta: 'Título',
        posicion: 'left-2',
        claseFondo: 'bg-[var(--brand-primary)]',
        claseBorde: 'border-[var(--brand-primary)]',
    },
    nombre: {
        etiqueta: 'Nombre',
        posicion: 'left-1/2 -translate-x-1/2',
        claseFondo: 'bg-sky-600',
        claseBorde: 'border-sky-600',
    },
    frase: {
        etiqueta: 'Frase',
        posicion: 'right-2',
        claseFondo: 'bg-emerald-600',
        claseBorde: 'border-emerald-600',
    },
};

const config = computed(() => CONFIG[props.variante]);

const arrastrando = ref(false);

function acotar(valor: number): number {
    return Math.min(props.maximo, Math.max(props.minimo, valor));
}

function posicionDesdeEvento(evento: PointerEvent, contenedor: HTMLElement): number {
    const rect = contenedor.getBoundingClientRect();

    return acotar((evento.clientY - rect.top) / rect.height);
}

function iniciarArrastre(evento: PointerEvent) {
    const marcador = evento.currentTarget as HTMLElement;
    // El contenedor de referencia para calcular % es la fila completa
    // (mismo ancho/alto que la imagen), no el pill que se arrastra.
    const fila = marcador.closest('[data-posicion-fila]') as HTMLElement | null;
    const contenedor = fila?.parentElement;

    if (!contenedor) {
        return;
    }

    arrastrando.value = true;
    marcador.setPointerCapture(evento.pointerId);

    function mover(e: PointerEvent) {
        emit('update:modelValue', posicionDesdeEvento(e, contenedor as HTMLElement));
    }

    function soltar() {
        arrastrando.value = false;
        marcador.removeEventListener('pointermove', mover);
        marcador.removeEventListener('pointerup', soltar);
    }

    marcador.addEventListener('pointermove', mover);
    marcador.addEventListener('pointerup', soltar);
}
</script>

<template>
    <div
        data-posicion-fila
        class="absolute inset-x-0 border-t-2 border-dashed opacity-70 transition-[top] select-none"
        :class="[config.claseBorde, arrastrando ? '' : 'duration-150']"
        :style="{ top: `${modelValue * 100}%` }"
    >
        <button
            type="button"
            class="absolute flex -translate-y-1/2 cursor-grab touch-none items-center gap-1 rounded-full px-2 py-1 text-[0.65rem] font-semibold text-white shadow-md"
            :class="[config.posicion, config.claseFondo, arrastrando ? 'cursor-grabbing' : '']"
            :aria-label="`Mover el bloque de ${config.etiqueta.toLowerCase()}`"
            @pointerdown="iniciarArrastre"
        >
            <GripVertical class="size-3.5" />
            {{ config.etiqueta }}
        </button>
    </div>
</template>
