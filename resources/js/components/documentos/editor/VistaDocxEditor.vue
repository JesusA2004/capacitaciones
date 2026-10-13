<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import type { CampoEditor, ParrafoDocx } from '@/types';

/**
 * Vista del editor para Word (DOCX): el texto REAL del documento, párrafo
 * por párrafo, con cada espacio para datos como una ficha numerada que se
 * pinta según su estado (verde confirmado, amarillo por confirmar, rojo
 * sin dato, gris firma). En Word el dato hereda la tipografía del texto
 * del formato, así que aquí solo se decide QUÉ va en cada espacio.
 */
const props = defineProps<{
    parrafos: ParrafoDocx[];
    campos: CampoEditor[];
    seleccion: string[];
    editable: boolean;
    resaltado: string | null;
}>();

const emit = defineEmits<{
    seleccionar: [fieldKey: string | null, aditivo: boolean];
    'soltar-variable': [fieldKey: string, clave: string];
}>();

const porClave = computed(
    () => new Map(props.campos.map((c, i) => [c.field_key, { campo: c, numero: i + 1 }])),
);

function color(campo: CampoEditor | undefined): string {
    if (!campo || campo.zona !== 'dato') {
        return 'border-muted-foreground/50 bg-muted text-muted-foreground';
    }

    if (campo.estado === 'confirmado' && (campo.source_key || campo.expression)) {
        return 'border-success bg-success-soft text-success';
    }

    return campo.estado === 'sugerido'
        ? 'border-warning bg-warning-soft text-warning'
        : 'border-danger bg-danger-soft text-danger';
}

function alSoltar(evento: DragEvent, fieldKey: string) {
    const clave = evento.dataTransfer?.getData('text/x-variable');

    if (clave && props.editable) {
        emit('soltar-variable', fieldKey, clave);
    }
}

// Solo los párrafos con algún espacio, más un poco de contexto alrededor:
// el resto del contrato es texto jurídico fijo que no se edita aquí.
const visibles = computed(() => {
    const conCampo = new Set<number>();

    props.parrafos.forEach((p, i) => {
        if (p.segmentos.some((s) => s.tipo !== 'texto')) {
            conCampo.add(i);
        }
    });

    return props.parrafos
        .map((p, i) => ({ p, i }))
        .filter(({ i }) => conCampo.has(i) || conCampo.has(i - 1) || conCampo.has(i + 1));
});
</script>

<template>
    <div class="mx-auto flex max-w-3xl flex-col gap-3 p-6">
        <p
            v-if="visibles.length === 0"
            class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            No se detectaron espacios para datos en este documento.
        </p>
        <template v-for="({ p, i }, n) in visibles" :key="p.referencia">
            <div
                v-if="n > 0 && visibles[n - 1].i !== i - 1"
                class="text-center text-xs tracking-widest text-muted-foreground"
                aria-hidden="true"
            >
                · · ·
            </div>
            <p
                :class="
                    cn(
                        'rounded-lg bg-white px-4 py-2 text-sm leading-7 text-foreground shadow-xs ring-1 ring-border',
                        p.en_tabla && 'bg-crema/40 font-medium',
                    )
                "
            >
                <template v-for="(s, k) in p.segmentos" :key="k">
                    <span v-if="s.tipo === 'texto'" class="whitespace-pre-wrap">{{ s.texto }}</span>
                    <span
                        v-else-if="s.tipo === 'firma'"
                        class="mx-0.5 inline-flex items-center rounded border border-dashed border-muted-foreground/50 bg-muted px-1.5 text-xs text-muted-foreground"
                        title="Línea de firma: se queda en blanco"
                        >Firma</span
                    >
                    <button
                        v-else
                        type="button"
                        :class="
                            cn(
                                'mx-0.5 inline-flex max-w-[16rem] items-center gap-1 rounded border-2 px-1.5 align-baseline text-xs font-semibold transition-shadow',
                                color(porClave.get(s.field_key)?.campo),
                                seleccion.includes(s.field_key) && 'ring-2 ring-primary ring-offset-1',
                                resaltado === s.field_key && 'animate-pulse ring-2 ring-danger',
                            )
                        "
                        :title="s.original"
                        @click="emit('seleccionar', s.field_key, $event.shiftKey)"
                        @dragover.prevent
                        @drop.prevent="alSoltar($event, s.field_key)"
                    >
                        <span
                            class="flex size-4 shrink-0 items-center justify-center rounded-full bg-current/15 text-[10px]"
                            >{{ porClave.get(s.field_key)?.numero ?? '?' }}</span
                        >
                        <span class="truncate">{{
                            porClave.get(s.field_key)?.campo.label ?? 'Sin dato'
                        }}</span>
                    </button>
                </template>
            </p>
        </template>
    </div>
</template>
