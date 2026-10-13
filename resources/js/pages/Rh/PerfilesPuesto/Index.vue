<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ClipboardList, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import { show } from '@/routes/rh/perfiles-puesto';
import type { PuestoConPerfil } from '@/types';

const props = defineProps<{
    puestos: PuestoConPerfil[];
    puedeEditar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Perfiles de puesto', href: '' },
        ],
    },
});

const busqueda = ref('');

function plegar(texto: string): string {
    return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

const visibles = computed(() => {
    const q = plegar(busqueda.value.trim());

    return q === ''
        ? props.puestos
        : props.puestos.filter((p) =>
              plegar(`${p.nombre} ${p.departamento ?? ''}`).includes(q),
          );
});

const pendientes = computed(
    () => props.puestos.filter((p) => p.avance === 0).length,
);
</script>

<template>
    <Head title="Perfiles de puesto" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Perfiles de puesto"
            descripcion="Qué debe tener quien se postula a cada puesto y cuál es el deber ser del puesto."
            :icono="ClipboardList"
        />

        <section
            class="flex flex-wrap items-center gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
        >
            <div class="relative min-w-[220px] flex-1">
                <Search
                    class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="busqueda"
                    placeholder="Buscar puesto o departamento…"
                    class="pl-9"
                />
            </div>
            <p v-if="pendientes" class="text-xs text-muted-foreground">
                {{ pendientes }} puesto(s) sin perfil todavía
            </p>
        </section>

        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="p in visibles"
                :key="p.id"
                :href="show.url(p.id)"
                class="flex tarjeta-interactiva flex-col gap-2 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold text-[var(--mrl-texto)]">
                            {{ p.nombre }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ p.departamento ?? 'Sin departamento' }}
                            <template v-if="p.reporta_a">
                                · reporta a {{ p.reporta_a }}</template
                            >
                        </p>
                    </div>
                    <span
                        class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium"
                        :class="
                            p.avance === 0
                                ? 'bg-warning/15 text-warning'
                                : p.avance === 100
                                  ? 'bg-success-soft text-[var(--mrl-verde)]'
                                  : 'bg-[var(--mrl-fondo)] text-muted-foreground'
                        "
                    >
                        {{ p.avance === 0 ? 'Sin perfil' : `${p.avance} %` }}
                    </span>
                </div>
                <div
                    class="h-1.5 overflow-hidden rounded-full bg-[var(--mrl-fondo)]"
                >
                    <div
                        class="h-full rounded-full bg-[var(--mrl-petroleo)]"
                        :style="{ width: `${p.avance}%` }"
                    />
                </div>
                <p class="text-xs text-muted-foreground">
                    {{ p.ocupantes }} persona(s) en el puesto
                </p>
            </Link>

            <p
                v-if="visibles.length === 0"
                class="col-span-full py-10 text-center text-sm text-muted-foreground"
            >
                No hay puestos con esa búsqueda.
            </p>
        </section>
    </div>
</template>
