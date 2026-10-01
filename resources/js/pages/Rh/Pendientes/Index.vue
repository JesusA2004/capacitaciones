<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { AlarmClock, ChevronRight, Inbox } from '@lucide/vue';
import { reactive, watch } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { dashboard } from '@/routes';
import { index } from '@/routes/rh/pendientes';
import type { TareaBandeja } from '@/types';

type Paginado<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

const props = defineProps<{
    tareas: Paginado<TareaBandeja & { url: string | null }>;
    conteos: { abiertas: number; no_leidas: number; vencidas: number };
    filtros: { etapa?: string | null; sucursal_id?: string | number | null; urgencia?: string | null; tipo?: string | null };
    opciones: {
        etapas: { value: string; etiqueta: string }[];
        sucursales: { id: number; nombre: string }[];
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mis pendientes', href: '' },
        ],
    },
});

const filtros = reactive({
    etapa: props.filtros.etapa ?? '',
    sucursal_id: props.filtros.sucursal_id ? String(props.filtros.sucursal_id) : '',
    urgencia: props.filtros.urgencia ?? '',
});

watch(filtros, () => {
    router.get(index.url(), { ...filtros }, { preserveState: true, preserveScroll: true, replace: true });
});

const colorPrioridad: Record<string, string> = {
    urgente: 'bg-[var(--mrl-rojo)] text-white',
    alta: 'bg-[var(--mrl-dorado)]/20 text-[var(--mrl-dorado-oscuro)]',
    media: 'bg-[var(--mrl-cyan)]/15 text-[var(--mrl-navy)]',
    baja: 'bg-[var(--mrl-fondo)] text-[var(--mrl-texto-suave)]',
};
</script>

<template>
    <Head title="Mis pendientes" />

    <div class="pagina-media flex flex-col gap-5">
        <CrudPageHeader
            titulo="¿Qué me toca hacer?"
            descripcion="Pendientes reales del ciclo laboral dentro de tu alcance, ordenados por prioridad."
            :icono="Inbox"
        />

        <div class="grid grid-cols-3 gap-3">
            <div class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4">
                <p class="text-xs text-[var(--mrl-texto-suave)]">Abiertos</p>
                <p class="text-2xl font-semibold text-[var(--mrl-petroleo)]">{{ conteos.abiertas }}</p>
            </div>
            <div class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4">
                <p class="text-xs text-[var(--mrl-texto-suave)]">Sin leer</p>
                <p class="text-2xl font-semibold text-[var(--mrl-navy)]">{{ conteos.no_leidas }}</p>
            </div>
            <div class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4">
                <p class="text-xs text-[var(--mrl-texto-suave)]">Vencidos</p>
                <p class="text-2xl font-semibold text-[var(--mrl-rojo)]">{{ conteos.vencidas }}</p>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <select v-model="filtros.etapa" class="h-9 rounded-md border bg-[var(--mrl-superficie)] px-3 text-sm" aria-label="Etapa">
                <option value="">Todas las etapas</option>
                <option v-for="e in opciones.etapas" :key="e.value" :value="e.value">{{ e.etiqueta }}</option>
            </select>
            <select v-if="opciones.sucursales.length > 1" v-model="filtros.sucursal_id" class="h-9 rounded-md border bg-[var(--mrl-superficie)] px-3 text-sm" aria-label="Sucursal">
                <option value="">Todas las sucursales</option>
                <option v-for="s in opciones.sucursales" :key="s.id" :value="String(s.id)">{{ s.nombre }}</option>
            </select>
            <select v-model="filtros.urgencia" class="h-9 rounded-md border bg-[var(--mrl-superficie)] px-3 text-sm" aria-label="Urgencia">
                <option value="">Cualquier urgencia</option>
                <option value="urgentes">Urgentes / por vencer</option>
                <option value="vencidas">Vencidos</option>
            </select>
        </div>

        <p v-if="!tareas.data.length" class="rounded-2xl border border-dashed border-[var(--mrl-borde)] p-10 text-center text-sm text-[var(--mrl-texto-suave)]">
            No tienes pendientes con estos filtros. 🎉
        </p>

        <ul class="flex flex-col gap-3">
            <li v-for="tarea in tareas.data" :key="tarea.id">
                <component
                    :is="tarea.url ? Link : 'div'"
                    :href="tarea.url ?? undefined"
                    class="flex items-center gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 transition hover:border-[var(--mrl-petroleo)]/40"
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="colorPrioridad[tarea.prioridad] ?? colorPrioridad.media">{{ tarea.prioridad_etiqueta }}</span>
                            <span v-if="tarea.etapa_etiqueta" class="text-xs text-[var(--mrl-texto-suave)]">{{ tarea.etapa_etiqueta }}</span>
                            <span v-if="tarea.vencida" class="inline-flex items-center gap-1 text-xs font-medium text-[var(--mrl-rojo)]"><AlarmClock class="size-3" /> Vencido</span>
                        </div>
                        <p class="mt-1 truncate font-medium text-[var(--mrl-texto)]">{{ tarea.titulo }}</p>
                        <p class="text-xs text-[var(--mrl-texto-suave)]">
                            {{ tarea.candidato?.nombre ?? tarea.colaborador?.nombre ?? '—' }}
                            <template v-if="tarea.sucursal"> · {{ tarea.sucursal }}</template>
                            · hace {{ tarea.antiguedad_dias }} día{{ tarea.antiguedad_dias === 1 ? '' : 's' }}
                        </p>
                        <p v-if="tarea.descripcion" class="mt-1 line-clamp-2 text-sm text-[var(--mrl-texto)]/80">{{ tarea.descripcion }}</p>
                    </div>
                    <ChevronRight v-if="tarea.url" class="size-5 shrink-0 text-[var(--mrl-texto-suave)]" />
                </component>
            </li>
        </ul>

        <nav v-if="tareas.last_page > 1" class="flex flex-wrap justify-center gap-1" aria-label="Paginación">
            <template v-for="link in tareas.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md px-3 py-1 text-sm"
                    :class="link.active ? 'bg-[var(--mrl-petroleo)] text-white' : 'hover:bg-[var(--mrl-fondo)]'"
                    ><span v-html="link.label"
                /></Link>
            </template>
        </nav>
    </div>
</template>
