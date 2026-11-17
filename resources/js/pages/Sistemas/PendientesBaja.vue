<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ChevronRight,
    Info,
    MonitorX,
    Search,
    UserRoundCheck,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import CambioSuave from '@/components/ui/CambioSuave.vue';
import { Input } from '@/components/ui/input';
import SelectorVista from '@/components/ui/SelectorVista.vue';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/sistemas/pendientes-baja';
import type {
    FiltroPendientesAlta,
    PendienteBajaSistemas,
    TotalesPendientesBaja,
} from '@/types';

/**
 * Bandeja «Pendientes de baja en sistemas»: a quién hay que darle de baja
 * (o reactivar, si su baja se canceló) los usuarios en los demás sistemas.
 * PendientesBajaSistemasService.
 */
const props = defineProps<{
    pendientes: {
        data: PendienteBajaSistemas[];
        current_page: number;
        last_page: number;
        per_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    totales: TotalesPendientesBaja;
    filtros: { filtro: FiltroPendientesAlta; buscar: string };
    correoActivo: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Pendientes de baja en sistemas', href: index.url() },
        ],
    },
});

const filtro = ref<FiltroPendientesAlta>(props.filtros.filtro);
const buscar = ref(props.filtros.buscar ?? '');

const opciones = computed(() => [
    {
        valor: 'pendientes' as const,
        etiqueta: 'Pendientes',
        contador: props.totales.pendientes,
    },
    { valor: 'atendidos' as const, etiqueta: 'Atendidos' },
    { valor: 'todos' as const, etiqueta: 'Todos' },
]);

function visitar(): void {
    router.get(
        index.url({
            query: {
                filtro: filtro.value,
                ...(buscar.value.trim() !== ''
                    ? { buscar: buscar.value.trim() }
                    : {}),
            },
        }),
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const buscarConPausa = useDebounceFn(visitar, 350);

watch(filtro, visitar);
watch(buscar, () => buscarConPausa());

function estadoFila(fila: PendienteBajaSistemas): {
    texto: string;
    clases: string;
} {
    if (fila.cancelado) {
        return { texto: 'Baja cancelada', clases: 'bg-crema text-bronce' };
    }

    if (fila.atendido) {
        return { texto: 'Atendido', clases: 'bg-success-soft text-success' };
    }

    return fila.dias >= 3
        ? {
              texto: `Pendiente hace ${fila.dias} días`,
              clases: 'bg-warning-soft text-warning',
          }
        : { texto: 'Pendiente', clases: 'bg-info-soft text-info' };
}
</script>

<template>
    <Head title="Pendientes de baja en sistemas" />

    <div class="pagina-ancha flex flex-col gap-4">
        <CrudPageHeader
            titulo="Pendientes de baja en sistemas"
            :icono="MonitorX"
        />

        <p
            v-if="!correoActivo"
            class="flex items-start gap-2 rounded-xl bg-warning-soft px-3 py-2 text-sm text-warning"
        >
            <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            El correo a Sistemas está apagado en este servidor. La bandeja
            funciona igual.
        </p>

        <div class="flex flex-wrap items-center gap-3">
            <SelectorVista
                v-model="filtro"
                :opciones="opciones"
                etiqueta="Qué mostrar"
            />
            <div class="relative w-full sm:w-72">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    v-model="buscar"
                    type="search"
                    class="pl-9"
                    placeholder="Nombre o número de empleado"
                    aria-label="Buscar persona"
                />
            </div>
        </div>

        <CambioSuave>
            <CrudEmptyState
                v-if="pendientes.data.length === 0"
                key="vacio"
                :icono="filtro === 'pendientes' ? UserRoundCheck : MonitorX"
                :titulo="
                    filtro === 'pendientes'
                        ? 'Sin bajas pendientes'
                        : 'Sin resultados'
                "
                descripcion="Cuando se aplique una baja aparecerá aquí."
            />
            <ul v-else key="lista" class="flex flex-col gap-2">
                <li v-for="fila in pendientes.data" :key="fila.id">
                    <Link
                        :href="show.url(fila.id)"
                        class="flex tarjeta-interactiva flex-wrap items-center gap-3 rounded-2xl border bg-card p-3 shadow-xs"
                    >
                        <ColaboradorAvatar
                            :nombre="fila.colaborador.nombre"
                            :foto-url="fila.colaborador.foto_url"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">
                                {{ fila.colaborador.nombre }}
                            </span>
                            <span class="block text-sm text-muted-foreground">
                                {{ fila.colaborador.puesto ?? 'Sin puesto' }}
                                ·
                                {{
                                    fila.colaborador.sucursal ?? 'Sin sucursal'
                                }}
                                · Baja {{ fila.fecha_baja ?? 'sin fecha' }}
                            </span>
                        </span>
                        <span
                            class="rounded-full bg-crema px-2.5 py-0.5 text-xs font-semibold text-bronce"
                        >
                            {{ fila.evento_etiqueta }}
                        </span>
                        <span
                            class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                            :class="estadoFila(fila).clases"
                        >
                            {{ estadoFila(fila).texto }}
                        </span>
                        <ChevronRight
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </Link>
                </li>
            </ul>
        </CambioSuave>

        <PaginacionTabla v-if="pendientes.total > 0" :datos="pendientes" />
    </div>
</template>
