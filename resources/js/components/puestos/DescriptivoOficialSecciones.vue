<script setup lang="ts">
import {
    BookOpen,
    ChevronDown,
    Clock3,
    GraduationCap,
    HeartHandshake,
    KeyRound,
    ShieldAlert,
    Sparkles,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import type { DatosDescriptivo } from '@/types';

/**
 * Secciones del descriptivo OFICIAL (lo que trae el PDF, tal cual) en
 * formato moderno: conocimientos, competencias, actividades críticas,
 * facultades, plan de formación y compromisos. En acordeones para no
 * abrumar; las vacías no se muestran.
 */
const props = defineProps<{ datos: DatosDescriptivo }>();

type Seccion = {
    clave: string;
    titulo: string;
    icono: Component;
    total: number;
};

const secciones = computed<Seccion[]>(() =>
    [
        {
            clave: 'conocimientos',
            titulo: 'Conocimientos',
            icono: BookOpen,
            total: props.datos.conocimientos.length,
        },
        {
            clave: 'competencias',
            titulo: 'Competencias',
            icono: Sparkles,
            total: props.datos.competencias.length,
        },
        {
            clave: 'criticas',
            titulo: 'Actividades críticas en ausencia',
            icono: ShieldAlert,
            total: props.datos.actividades_criticas.length,
        },
        {
            clave: 'facultades',
            titulo: 'Facultades',
            icono: KeyRound,
            total: props.datos.facultades.length,
        },
        {
            clave: 'plan',
            titulo: 'Plan de formación',
            icono: GraduationCap,
            total: props.datos.plan_formacion.length,
        },
        {
            clave: 'compromisos',
            titulo: 'Compromisos culturales y de imagen',
            icono: HeartHandshake,
            total: props.datos.compromisos_culturales.length,
        },
    ].filter((s) => s.total > 0),
);

const abiertas = ref<string[]>(['conocimientos']);

function alternar(clave: string) {
    abiertas.value = abiertas.value.includes(clave)
        ? abiertas.value.filter((c) => c !== clave)
        : [...abiertas.value, clave];
}
</script>

<template>
    <div class="flex flex-col gap-2.5">
        <section
            v-for="s in secciones"
            :key="s.clave"
            class="overflow-hidden rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]"
        >
            <button
                type="button"
                class="flex min-h-12 w-full items-center gap-3 px-4 py-3 text-left hover:bg-[var(--mrl-fondo)]/60"
                :aria-expanded="abiertas.includes(s.clave)"
                @click="alternar(s.clave)"
            >
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-[var(--mrl-crema)] text-[var(--mrl-dorado-oscuro)]"
                >
                    <component :is="s.icono" class="size-4" />
                </span>
                <span class="flex-1 font-medium">{{ s.titulo }}</span>
                <span
                    class="rounded-full bg-[var(--mrl-fondo)] px-2 py-0.5 text-xs text-muted-foreground"
                    >{{ s.total }}</span
                >
                <ChevronDown
                    class="size-4 text-muted-foreground transition-transform"
                    :class="abiertas.includes(s.clave) ? 'rotate-180' : ''"
                />
            </button>

            <div
                v-if="abiertas.includes(s.clave)"
                class="border-t border-[var(--mrl-borde)] p-4"
            >
                <!-- Conocimiento · para qué se usa · años -->
                <ul
                    v-if="s.clave === 'conocimientos'"
                    class="grid gap-2 md:grid-cols-2"
                >
                    <li
                        v-for="c in datos.conocimientos"
                        :key="c.conocimiento"
                        class="flex items-start gap-3 rounded-xl bg-[var(--mrl-fondo)]/70 p-3"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="leading-snug font-medium">
                                {{ c.conocimiento }}
                            </p>
                            <p
                                v-if="c.funcion"
                                class="mt-0.5 text-sm text-muted-foreground"
                            >
                                {{ c.funcion }}
                            </p>
                        </div>
                        <span
                            v-if="c.anios"
                            class="inline-flex shrink-0 items-center gap-1 rounded-full bg-[var(--mrl-superficie)] px-2.5 py-1 text-xs font-medium text-[var(--mrl-petroleo)]"
                        >
                            <Clock3 class="size-3" /> {{ c.anios }}
                            {{ c.anios === '1' ? 'año' : 'años' }}
                        </span>
                    </li>
                </ul>

                <!-- Competencia · aplicación -->
                <ul
                    v-else-if="s.clave === 'competencias'"
                    class="grid gap-2 md:grid-cols-2"
                >
                    <li
                        v-for="c in datos.competencias"
                        :key="c.competencia"
                        class="rounded-xl border border-[var(--mrl-petroleo)]/15 bg-[var(--mrl-petroleo)]/[0.04] p-3"
                    >
                        <p class="font-medium text-[var(--mrl-petroleo)]">
                            {{ c.competencia }}
                        </p>
                        <p
                            v-if="c.funcion"
                            class="mt-0.5 text-sm text-muted-foreground"
                        >
                            {{ c.funcion }}
                        </p>
                    </li>
                </ul>

                <!-- Qué se cubre en ausencia · quién lo cubre -->
                <div
                    v-else-if="s.clave === 'criticas'"
                    class="grid gap-4 md:grid-cols-[1fr_auto]"
                >
                    <ul class="flex flex-col gap-1.5 text-sm">
                        <li
                            v-for="a in datos.actividades_criticas"
                            :key="a"
                            class="flex gap-2"
                        >
                            <span
                                class="mt-1.5 size-1.5 shrink-0 rounded-full bg-[var(--mrl-dorado)]"
                            />{{ a }}
                        </li>
                    </ul>
                    <div
                        v-if="datos.responsables_ausencia.length"
                        class="rounded-xl bg-[var(--mrl-crema)] p-3 text-sm md:min-w-56"
                    >
                        <p class="text-xs text-muted-foreground">Lo cubre</p>
                        <p
                            v-for="r in datos.responsables_ausencia"
                            :key="r"
                            class="font-medium"
                        >
                            {{ r }}
                        </p>
                    </div>
                </div>

                <ul
                    v-else-if="s.clave === 'facultades'"
                    class="flex flex-col gap-1.5 text-sm"
                >
                    <li
                        v-for="f in datos.facultades"
                        :key="f"
                        class="flex gap-2"
                    >
                        <KeyRound
                            class="mt-0.5 size-3.5 shrink-0 text-[var(--mrl-dorado-oscuro)]"
                        />{{ f }}
                    </li>
                </ul>

                <ul v-else-if="s.clave === 'plan'" class="flex flex-wrap gap-2">
                    <li
                        v-for="p in datos.plan_formacion"
                        :key="p"
                        class="rounded-full border border-[var(--mrl-borde)] bg-[var(--mrl-fondo)] px-3 py-1 text-sm"
                    >
                        {{ p }}
                    </li>
                </ul>

                <div v-else class="flex flex-col gap-2 text-sm">
                    <p
                        v-for="c in datos.compromisos_culturales"
                        :key="c"
                        class="leading-relaxed"
                    >
                        {{ c }}
                    </p>
                </div>
            </div>
        </section>
    </div>
</template>
