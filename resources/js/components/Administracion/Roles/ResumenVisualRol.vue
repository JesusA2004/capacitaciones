<script setup lang="ts">
import { computed } from 'vue';
import AlcanceMiniMapa from '@/components/Administracion/Roles/AlcanceMiniMapa.vue';
import { iconoEtapa } from '@/lib/rolesIconos';
import { moduloActivo, nivelDe } from '@/lib/rolesPermisos';
import type { CatalogoPermisos } from '@/types/roles';

/**
 * Resumen visual de un rol (o de una persona): por etapa del ciclo, cuántos
 * módulos tiene y, por módulo, cuántas acciones y qué alcance. Un clic lleva
 * a la tarjeta del módulo en la matriz.
 */
const props = defineProps<{
    catalogo: CatalogoPermisos;
    permisos: Set<string>;
}>();

const etapas = computed(() =>
    props.catalogo.etapas.map((etapa) => {
        const activos = etapa.modulos.filter((m) =>
            moduloActivo(m, props.permisos),
        );

        return {
            clave: etapa.clave,
            etiqueta: etapa.etiqueta,
            total: etapa.modulos.length,
            activos: activos.length,
            modulos: activos.map((m) => ({
                clave: m.clave,
                etiqueta: m.etiqueta,
                acciones: m.acciones.filter((a) => props.permisos.has(a.permiso))
                    .length,
                totalAcciones: m.acciones.length,
                conAlcance: Object.keys(m.alcances).length > 0,
                nivel: nivelDe(m, props.permisos),
            })),
        };
    }),
);

function irA(clave: string) {
    document
        .querySelector(`[data-modulo="${clave}"]`)
        ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
</script>

<template>
    <div class="grid gap-2 md:grid-cols-2 2xl:grid-cols-3">
        <section
            v-for="etapa in etapas"
            :key="etapa.clave"
            class="flex flex-col gap-2 rounded-xl border border-border/60 bg-card p-3"
            :class="etapa.activos === 0 ? 'opacity-60' : ''"
        >
            <header class="flex items-center gap-2">
                <span
                    class="flex size-7 shrink-0 items-center justify-center rounded-full bg-crema"
                >
                    <component :is="iconoEtapa(etapa.clave)" class="size-3.5" />
                </span>
                <span class="min-w-0 flex-1 truncate text-sm font-medium">{{
                    etapa.etiqueta
                }}</span>
                <span class="text-xs text-muted-foreground"
                    >{{ etapa.activos }}/{{ etapa.total }}</span
                >
            </header>
            <div
                class="h-1.5 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                :aria-valuenow="etapa.activos"
                :aria-valuemax="etapa.total"
                :aria-label="`${etapa.etiqueta}: ${etapa.activos} de ${etapa.total} módulos`"
            >
                <div
                    class="h-full rounded-full bg-primary/60 transition-all"
                    :style="{
                        width: `${etapa.total === 0 ? 0 : (etapa.activos / etapa.total) * 100}%`,
                    }"
                />
            </div>
            <div v-if="etapa.modulos.length > 0" class="flex flex-wrap gap-1">
                <button
                    v-for="m in etapa.modulos"
                    :key="m.clave"
                    type="button"
                    class="inline-flex presionable items-center gap-1.5 rounded-full bg-salvia/60 px-2 py-0.5 text-[11px] transition-colors hover:bg-salvia focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :title="`Ir a ${m.etiqueta}`"
                    @click="irA(m.clave)"
                >
                    {{ m.etiqueta }}
                    <span
                        v-if="m.totalAcciones > 0"
                        class="text-muted-foreground"
                        >{{ m.acciones }}/{{ m.totalAcciones }}</span
                    >
                    <AlcanceMiniMapa v-if="m.conAlcance" :nivel="m.nivel" />
                </button>
            </div>
            <p v-else class="text-[11px] text-muted-foreground">Sin acceso</p>
        </section>
    </div>
</template>
