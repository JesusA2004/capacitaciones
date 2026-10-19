<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, DoorOpen } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatearFecha } from '@/lib/fechas';
import { formatoMoneda } from '@/lib/utils';
import { dashboard } from '@/routes';
import { ciclo as cicloColaborador } from '@/routes/rh/colaboradores';

type Fila = {
    id: number;
    colaborador: {
        id: number;
        nombre: string;
        numero_empleado: string | null;
        puesto: string | null;
        sucursal: string | null;
    };
    tipo_baja: string;
    tipo_baja_etiqueta: string;
    fecha_efectiva: string;
    estado: string;
    estado_etiqueta: string;
    solicitado_por: string | null;
    finiquito: { estado: string; estado_etiqueta: string; neto: string } | null;
    siguiente_accion: string;
};

type Paginador = {
    data: Fila[];
    current_page: number;
    last_page: number;
    per_page: number;
    from?: number | null;
    to?: number | null;
    total: number;
    links?: { url: string | null; label: string; active: boolean }[];
};

const props = defineProps<{
    cierres: Paginador;
    filtros: { estado: string | null; busqueda: string };
    estados: { value: string; label: string }[];
    puede_ver_finiquito: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Bajas y finiquitos', href: '' },
        ],
    },
});

const termino = ref(props.filtros.busqueda);
const estado = ref(props.filtros.estado ?? '');

function filtrar() {
    router.get(
        window.location.pathname,
        {
            busqueda: termino.value || undefined,
            estado: estado.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(termino, useDebounceFn(filtrar, 300));
watch(estado, filtrar);

function neto(fila: Fila): string {
    return fila.finiquito ? formatoMoneda(Number(fila.finiquito.neto)) : '—';
}
</script>

<template>
    <Head title="Bajas y finiquitos" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Bajas y finiquitos"
            descripcion="Cierres laborales de los colaboradores de tu alcance y el estado de su finiquito. Cada baja se gestiona en la ficha del colaborador."
            :icono="DoorOpen"
        />

        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
            <div class="flex-1">
                <Label class="mb-1.5 text-xs text-[var(--mrl-texto-suave)]"
                    >Buscar colaborador</Label
                >
                <Input
                    v-model="termino"
                    placeholder="Nombre o número de empleado"
                />
            </div>
            <div class="w-full sm:w-64">
                <Label class="mb-1.5 text-xs text-[var(--mrl-texto-suave)]"
                    >Estado del cierre</Label
                >
                <SelectSimple
                    v-model="estado"
                    :opciones="[
                        { value: 'abiertos', label: 'Solo abiertos' },
                        ...estados.map((e) => ({
                            value: e.value,
                            label: e.label,
                        })),
                    ]"
                    opcion-vacia="Todos"
                    placeholder="Todos"
                />
            </div>
        </div>

        <p
            v-if="!cierres.data.length"
            class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-4 py-8 text-center text-sm text-[var(--mrl-texto-suave)]"
        >
            No hay cierres laborales en tu alcance con estos filtros.
        </p>

        <template v-else>
            <!-- Tabla (pantallas anchas) -->
            <div
                class="hidden tabla-scroll rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] md:block"
            >
                <table class="w-full text-left text-sm">
                    <thead
                        class="border-b border-[var(--mrl-borde)] text-xs text-[var(--mrl-texto-suave)]"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Colaborador</th>
                            <th class="px-4 py-3 font-medium">
                                Puesto / Sucursal
                            </th>
                            <th class="px-4 py-3 font-medium">Tipo de baja</th>
                            <th class="px-4 py-3 font-medium">
                                Fecha efectiva
                            </th>
                            <th class="px-4 py-3 font-medium">
                                Estado del cierre
                            </th>
                            <th
                                v-if="puede_ver_finiquito"
                                class="px-4 py-3 font-medium"
                            >
                                Finiquito
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Acción siguiente
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="c in cierres.data"
                            :key="c.id"
                            class="border-b border-[var(--mrl-borde)] last:border-0"
                        >
                            <td class="px-4 py-3">
                                <p class="font-medium">
                                    {{ c.colaborador.nombre }}
                                </p>
                                <p
                                    class="text-xs text-[var(--mrl-texto-suave)]"
                                >
                                    {{ c.colaborador.numero_empleado ?? '—' }}
                                </p>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                {{ c.colaborador.puesto ?? '—' }}<br />
                                <span class="text-[var(--mrl-texto-suave)]">{{
                                    c.colaborador.sucursal ?? '—'
                                }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                {{ c.tipo_baja_etiqueta }}
                            </td>
                            <td class="px-4 py-3 text-xs">
                                {{ formatearFecha(c.fecha_efectiva) }}
                            </td>
                            <td class="px-4 py-3">
                                <Badge variant="info">{{
                                    c.estado_etiqueta
                                }}</Badge>
                            </td>
                            <td
                                v-if="puede_ver_finiquito"
                                class="px-4 py-3 text-xs"
                            >
                                <template v-if="c.finiquito">
                                    {{ c.finiquito.estado_etiqueta }}<br />
                                    <span class="font-medium tabular-nums">{{
                                        neto(c)
                                    }}</span>
                                </template>
                                <span
                                    v-else
                                    class="text-[var(--mrl-texto-suave)]"
                                    >Sin calcular</span
                                >
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Button size="sm" variant="outline" as-child>
                                    <Link
                                        :href="
                                            cicloColaborador.url(
                                                c.colaborador.id,
                                            )
                                        "
                                    >
                                        {{ c.siguiente_accion }}
                                        <ArrowRight class="size-3.5" />
                                    </Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Tarjetas (pantallas estrechas): una columna -->
            <ul class="flex flex-col gap-3 md:hidden">
                <li
                    v-for="c in cierres.data"
                    :key="c.id"
                    class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 text-sm"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{
                            c.colaborador.nombre
                        }}</span>
                        <Badge variant="info">{{ c.estado_etiqueta }}</Badge>
                    </div>
                    <p class="mt-0.5 text-xs text-[var(--mrl-texto-suave)]">
                        {{ c.colaborador.puesto ?? '—' }} ·
                        {{ c.colaborador.sucursal ?? '—' }}
                    </p>
                    <p class="mt-1 text-xs">
                        {{ c.tipo_baja_etiqueta }} · Baja
                        {{ formatearFecha(c.fecha_efectiva) }}
                    </p>
                    <p v-if="puede_ver_finiquito" class="mt-1 text-xs">
                        Finiquito:
                        <template v-if="c.finiquito">
                            {{ c.finiquito.estado_etiqueta }} ·
                            <span class="font-medium tabular-nums">{{
                                neto(c)
                            }}</span>
                        </template>
                        <span v-else class="text-[var(--mrl-texto-suave)]"
                            >sin calcular</span
                        >
                    </p>
                    <Button size="sm" variant="outline" class="mt-3" as-child>
                        <Link :href="cicloColaborador.url(c.colaborador.id)">
                            {{ c.siguiente_accion }}
                            <ArrowRight class="size-3.5" />
                        </Link>
                    </Button>
                </li>
            </ul>

            <PaginacionTabla :datos="cierres" />
        </template>
    </div>
</template>
