<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowDown, ArrowRight, BookOpen, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { ETIQUETA_CANAL } from '@/composables/useGuia';
import { articulo, FLUJO, NOMBRE_ROL } from '@/lib/guia';
import type { BloqueFlujo } from '@/lib/guia/tipos';

/**
 * Diagrama general: de la plantilla autorizada al contrato indeterminado.
 * Cada bloque es clicable y abre su explicación (quién, dónde, qué pasa y
 * el artículo completo). Agrupado por etapa; en celular se apila.
 */
const ETAPAS: { nombre: BloqueFlujo['etapa']; tono: string; punto: string }[] =
    [
        { nombre: 'Reclutamiento', tono: 'bg-crema/60', punto: 'bg-oro' },
        { nombre: 'Contratación', tono: 'bg-salvia/60', punto: 'bg-esmeralda' },
        {
            nombre: 'Incorporación',
            tono: 'bg-olivo/60',
            punto: 'bg-[var(--mrl-verde-secundario)]',
        },
        {
            nombre: 'Periodo de prueba',
            tono: 'bg-[var(--pastel-menta)]',
            punto: 'bg-[var(--mrl-petroleo)]',
        },
    ];

const numerado = FLUJO.map((bloque, i) => ({ ...bloque, numero: i + 1 }));

const porEtapa = computed(() =>
    ETAPAS.map((etapa) => ({
        ...etapa,
        bloques: numerado.filter((b) => b.etapa === etapa.nombre),
    })),
);

const activo = ref<(typeof numerado)[number] | null>(null);
const articuloActivo = computed(() =>
    activo.value ? articulo(activo.value.articulo) : undefined,
);
const siguiente = computed(() =>
    activo.value ? numerado[activo.value.numero] : undefined,
);
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-4">
        <section
            v-for="(etapa, i) in porEtapa"
            :key="etapa.nombre"
            :class="['relative rounded-3xl p-4', etapa.tono]"
            :aria-label="`Etapa: ${etapa.nombre}`"
        >
            <header class="mb-3 flex items-center gap-2">
                <span :class="['size-2.5 rounded-full', etapa.punto]" />
                <h3
                    class="text-xs font-semibold tracking-[0.14em] text-[var(--mrl-texto)] uppercase"
                >
                    {{ etapa.nombre }}
                </h3>
            </header>

            <ol class="flex flex-col items-stretch">
                <template v-for="(bloque, j) in etapa.bloques" :key="bloque.id">
                    <li>
                        <button
                            type="button"
                            class="flex w-full tarjeta-interactiva items-center gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-3 py-2.5 text-left"
                            :aria-label="`Paso ${bloque.numero}: ${bloque.titulo}. Ver explicación`"
                            @click="activo = bloque"
                        >
                            <span
                                class="flex size-7 shrink-0 items-center justify-center rounded-full bg-[var(--mrl-petroleo)] text-xs font-semibold text-white tabular-nums"
                            >
                                {{ bloque.numero }}
                            </span>
                            <span class="min-w-0">
                                <span
                                    class="block text-sm font-semibold tracking-wide text-[var(--mrl-texto)] uppercase"
                                >
                                    {{ bloque.titulo }}
                                </span>
                                <span
                                    class="block truncate text-[11px] text-[var(--mrl-texto-suave)]"
                                >
                                    {{
                                        bloque.quien
                                            .map((r) => NOMBRE_ROL[r])
                                            .join(' · ')
                                    }}
                                </span>
                            </span>
                        </button>
                    </li>
                    <li
                        v-if="j < etapa.bloques.length - 1"
                        class="flex justify-center py-0.5 text-oro"
                        aria-hidden="true"
                    >
                        <ArrowDown class="size-4" />
                    </li>
                </template>
            </ol>

            <span
                v-if="i < porEtapa.length - 1"
                class="absolute top-1/2 -right-3 z-10 hidden size-6 -translate-y-1/2 items-center justify-center rounded-full border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] text-oro shadow-sm lg:flex"
                aria-hidden="true"
            >
                <ArrowRight class="size-3.5" />
            </span>
        </section>
    </div>

    <Sheet
        :open="activo !== null"
        @update:open="(v: boolean) => !v && (activo = null)"
    >
        <SheetContent
            class="flex w-full flex-col gap-0 overflow-y-auto sm:max-w-md"
        >
            <SheetHeader v-if="activo">
                <p
                    class="text-xs font-medium tracking-wide text-[var(--mrl-texto-suave)] uppercase"
                >
                    Paso {{ activo.numero }} de {{ numerado.length }} ·
                    {{ activo.etapa }}
                </p>
                <SheetTitle class="text-xl">{{ activo.titulo }}</SheetTitle>
                <SheetDescription>{{ activo.resumen }}</SheetDescription>
            </SheetHeader>

            <div v-if="activo" class="flex flex-col gap-5 px-4 pb-6">
                <div class="flex flex-wrap items-center gap-2">
                    <Users class="size-4 text-[var(--mrl-texto-suave)]" />
                    <Badge
                        v-for="r in activo.quien"
                        :key="r"
                        variant="secondary"
                        class="bg-salvia text-[var(--mrl-texto)]"
                    >
                        {{ NOMBRE_ROL[r] }}
                    </Badge>
                    <Badge variant="outline">{{
                        ETIQUETA_CANAL[activo.canal]
                    }}</Badge>
                </div>

                <section
                    v-if="articuloActivo"
                    class="rounded-2xl bg-crema/50 p-4 text-sm"
                >
                    <p class="mb-2 font-semibold">
                        {{ articuloActivo.titulo }}
                    </p>
                    <p class="text-pretty text-[var(--mrl-texto-suave)]">
                        {{ articuloActivo.queEs }}
                    </p>
                    <p
                        v-if="articuloActivo.despues.length"
                        class="mt-3 text-xs font-semibold tracking-wide text-[var(--mrl-texto-suave)] uppercase"
                    >
                        Qué pasa después
                    </p>
                    <ul class="mt-1 list-disc space-y-1 pl-5">
                        <li
                            v-for="d in articuloActivo.despues.slice(0, 3)"
                            :key="d"
                        >
                            {{ d }}
                        </li>
                    </ul>
                </section>

                <div class="flex flex-col gap-2">
                    <Button as-child>
                        <Link
                            :href="`/ayuda/articulo/${activo.articulo}`"
                            @click="activo = null"
                        >
                            <BookOpen class="size-4" /> Leer la guía completa
                        </Link>
                    </Button>
                    <Button
                        v-if="siguiente"
                        variant="outline"
                        @click="activo = siguiente"
                    >
                        Siguiente paso: {{ siguiente.titulo }}
                        <ArrowRight class="size-4" />
                    </Button>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
