<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import { index as tableroRotacion } from '@/routes/reportes/rotacion/tablero';
import rutasBajas from '@/routes/rh/bajas';
import rutasFiniquitos from '@/routes/rh/bajas/finiquitos';
import { index as cierresIndex } from '@/routes/rh/cierres';
import type { PestanasBajas } from '@/types';

/**
 * Un solo apartado «Bajas» con cuatro pestañas (PDF Etapa 1, Pantalla 4). Solo
 * muestra lo que el backend permite (BajaConsultaService::pestanas); cada
 * pantalla vuelve a autorizar.
 */
const props = defineProps<{
    pestanas: PestanasBajas;
    actual?:
        'bajas' | 'finiquitos' | 'documentos' | 'rotacion' | 'causales' | null;
}>();

const lista = computed(() =>
    [
        props.pestanas.bajas && {
            clave: 'bajas',
            etiqueta: 'Bajas',
            conteo: props.pestanas.conteo_bajas,
            href: rutasBajas.index(),
        },
        props.pestanas.finiquitos && {
            clave: 'finiquitos',
            etiqueta: 'Finiquitos',
            conteo: props.pestanas.conteo_finiquitos,
            href: rutasFiniquitos.index(),
        },
        // «Documentos por persona» (rh.cierres.index): la recopilación de
        // documentos de salida, ahora dentro de las pestañas de Bajas.
        props.pestanas.documentos && {
            clave: 'documentos',
            etiqueta: 'Documentos',
            conteo: null,
            href: cierresIndex(),
        },
        props.pestanas.rotacion && {
            clave: 'rotacion',
            etiqueta: 'Rotación',
            conteo: null,
            href: tableroRotacion(),
        },
        props.pestanas.causales && {
            clave: 'causales',
            etiqueta: 'Catálogo de causales',
            conteo: null,
            href: rutasBajas.causales(),
        },
    ].filter((p) => p !== false),
);
</script>

<template>
    <nav
        class="scroll-x-limpio flex max-w-full border-b"
        aria-label="Secciones de bajas"
    >
        <Link
            v-for="p in lista"
            :key="p.clave"
            :href="p.href"
            :aria-current="actual === p.clave ? 'page' : undefined"
            :class="
                cn(
                    '-mb-px inline-flex h-11 shrink-0 items-center gap-1.5 border-b-2 px-3 text-sm font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/50',
                    actual === p.clave
                        ? 'border-[var(--mrl-dorado)] text-foreground'
                        : 'border-transparent text-muted-foreground hover:text-foreground',
                )
            "
        >
            {{ p.etiqueta }}
            <span
                v-if="p.conteo !== null"
                class="rounded-full bg-muted px-1.5 text-xs tabular-nums"
                >{{ p.conteo }}</span
            >
        </Link>
    </nav>
</template>
