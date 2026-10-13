<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    BarChart3,
    BriefcaseBusiness,
    CalendarClock,
    CheckCircle2,
    ChevronDown,
    FolderOpen,
    UserRoundSearch,
} from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import TableroRh from '@/components/Dashboard/TableroRh.vue';
import { Button } from '@/components/ui/button';
import { index as candidatosIndex } from '@/routes/rh/candidatos';
import { index as expedientesIndex } from '@/routes/rh/expedientes';
import { index as pendientesIndex } from '@/routes/rh/pendientes';
import { index as vacantesIndex } from '@/routes/rh/vacantes';
import type { InicioOperativoDatos, TableroRhDatos } from '@/types';

/**
 * Inicio operativo: primero QUÉ NECESITA ATENCIÓN (una acción: Ver
 * pendientes), luego cuatro cifras que ayudan a decidir. El tablero
 * completo (8 indicadores, embudo, tiempos, rotación) queda a un clic en
 * «Ver indicadores» — no se pinta todo a la vez.
 */
const props = defineProps<{
    inicio: InicioOperativoDatos;
    tablero: TableroRhDatos;
}>();

const CLAVE = 'inicio.indicadores.abiertos';
const indicadoresAbiertos = ref(false);

onMounted(() => {
    try {
        indicadoresAbiertos.value =
            window.localStorage.getItem(CLAVE) === '1' ||
            window.location.search.includes('tablero_');
    } catch {
        indicadoresAbiertos.value = false;
    }
});

watch(indicadoresAbiertos, (abierto) => {
    try {
        window.localStorage.setItem(CLAVE, abierto ? '1' : '0');
    } catch {
        // Solo una preferencia de vista.
    }
});

const saludo = computed(() => {
    const hora = new Date().getHours();
    const parte =
        hora < 12 ? 'Buenos días' : hora < 19 ? 'Buenas tardes' : 'Buenas noches';

    return props.inicio.nombre ? `${parte}, ${props.inicio.nombre}` : parte;
});

const tarjetas = computed(() =>
    [
        {
            clave: 'vacantes',
            titulo: 'Vacantes reales',
            valor: props.inicio.indicadores.vacantes,
            nota: 'plazas autorizadas sin cubrir',
            icono: BriefcaseBusiness,
            href: vacantesIndex.url(),
        },
        {
            clave: 'proceso',
            titulo: 'Contrataciones en proceso',
            valor: props.inicio.indicadores.en_proceso,
            nota: 'candidatos avanzando',
            icono: UserRoundSearch,
            href: candidatosIndex.url(),
        },
        props.inicio.indicadores.expedientes_incompletos !== null
            ? {
                  clave: 'expedientes',
                  titulo: 'Expedientes incompletos',
                  valor: props.inicio.indicadores.expedientes_incompletos,
                  nota: 'con datos contractuales faltantes',
                  icono: FolderOpen,
                  href: expedientesIndex.url({ query: { datos: 'incompletos' } }),
              }
            : null,
        {
            clave: 'vencer',
            titulo: 'Contratos por vencer',
            valor: props.inicio.indicadores.por_vencer,
            nota: 'periodos de prueba · próximos 30 días',
            icono: CalendarClock,
            href: pendientesIndex.url(),
        },
    ].filter((t) => t !== null),
);
</script>

<template>
    <div class="flex flex-col gap-5">
        <section
            class="flex flex-wrap items-center gap-4 rounded-2xl border bg-card p-5 shadow-xs"
            aria-live="polite"
        >
            <div class="mr-auto min-w-0">
                <h1 class="text-xl font-semibold tracking-tight">{{ saludo }}</h1>
                <p
                    v-if="inicio.pendientes > 0"
                    class="mt-0.5 text-sm text-muted-foreground"
                >
                    <span class="font-medium text-foreground tabular-nums">{{
                        inicio.pendientes
                    }}</span>
                    {{
                        inicio.pendientes === 1
                            ? 'cosa necesita tu atención'
                            : 'cosas necesitan tu atención'
                    }}<template v-if="inicio.vencidos > 0">
                        ·
                        <span class="font-medium text-danger tabular-nums">{{
                            inicio.vencidos
                        }}</span>
                        {{ inicio.vencidos === 1 ? 'vencida' : 'vencidas' }}</template
                    >
                </p>
                <p
                    v-else
                    class="mt-0.5 flex items-center gap-1.5 text-sm text-muted-foreground"
                >
                    <CheckCircle2 class="size-4 text-success" /> No tienes
                    pendientes por atender.
                </p>
            </div>
            <Button v-if="inicio.pendientes > 0" as-child>
                <Link :href="pendientesIndex.url()">
                    Ver pendientes <ArrowRight class="size-4" />
                </Link>
            </Button>
        </section>

        <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <li
                v-for="(t, i) in tarjetas"
                :key="t.clave"
                class="animate-in fade-in slide-in-from-bottom-1 fill-mode-both duration-200 motion-reduce:animate-none"
                :style="{ animationDelay: `${i * 40}ms` }"
            >
                <Link
                    :href="t.href"
                    class="tarjeta-interactiva flex h-full flex-col gap-1 rounded-2xl border bg-card p-4"
                >
                    <span
                        class="flex items-center justify-between text-sm text-muted-foreground"
                    >
                        {{ t.titulo }}
                        <component :is="t.icono" class="size-4" />
                    </span>
                    <span class="text-2xl font-semibold tabular-nums">{{
                        t.valor
                    }}</span>
                    <span class="text-xs text-muted-foreground">{{ t.nota }}</span>
                </Link>
            </li>
        </ul>

        <section class="rounded-2xl border bg-card">
            <button
                type="button"
                class="flex w-full items-center gap-2 rounded-2xl px-5 py-3.5 text-left text-sm font-medium transition-colors duration-150 hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :aria-expanded="indicadoresAbiertos"
                aria-controls="tablero-indicadores"
                @click="indicadoresAbiertos = !indicadoresAbiertos"
            >
                <BarChart3 class="size-4 text-muted-foreground" />
                <span class="mr-auto">
                    Indicadores del mes
                    <span class="font-normal text-muted-foreground">
                        · rotación, embudo, costos y tiempos</span
                    >
                </span>
                <ChevronDown
                    class="size-4 transition-transform duration-200 motion-reduce:transition-none"
                    :class="indicadoresAbiertos && 'rotate-180'"
                />
            </button>
            <div
                v-if="indicadoresAbiertos"
                id="tablero-indicadores"
                class="animate-in fade-in border-t px-5 pt-4 pb-5 duration-200 motion-reduce:animate-none"
            >
                <TableroRh :tablero="tablero" />
            </div>
        </section>
    </div>
</template>
