<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    BarChart3,
    CheckCircle2,
    ClipboardList,
    UserMinus,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { claseForma } from '@/lib/bajas';
import { formatearFecha } from '@/lib/fechas';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as tableroRotacion } from '@/routes/reportes/rotacion/tablero';
import rutasBajas from '@/routes/rh/bajas';
import type { FilaBaja } from '@/types';

/**
 * Bandeja de bajas (especificación de RH §5): RH entra a «En revisión RH»,
 * Jurídico a «En revisión Jurídico», el gerente ve las suyas. Folio,
 * colaborador, sucursal, forma, causal, días en espera y semáforo de
 * documentos (completos / incompletos); filtros por estatus, sucursal,
 * forma y texto. El alcance lo aplica el backend (BajaConsultaService).
 */
type Paginador = {
    data: FilaBaja[];
    current_page: number;
    last_page: number;
    per_page: number;
    from?: number | null;
    to?: number | null;
    total: number;
    links?: { url: string | null; label: string; active: boolean }[];
};

const props = defineProps<{
    bajas: Paginador;
    filtros: {
        estatus: string;
        sucursal_id: number | null;
        forma: string | null;
        busqueda: string;
    };
    estatus: { value: string; label: string }[];
    formas: { value: string; label: string; token: string }[];
    sucursales: { value: number; label: string }[];
    puede: { solicitar: boolean; gestionar: boolean; tablero: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Solicitudes de baja', href: '' },
        ],
    },
});

const termino = ref(props.filtros.busqueda);
const estatusElegido = ref(props.filtros.estatus);
const sucursal = ref<number | null>(props.filtros.sucursal_id);
const forma = ref(props.filtros.forma ?? '');

function filtrar() {
    router.get(
        rutasBajas.index.url(),
        {
            busqueda: termino.value || undefined,
            estatus: estatusElegido.value || undefined,
            sucursal_id: sucursal.value ?? undefined,
            forma: forma.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(termino, useDebounceFn(filtrar, 300));
watch([estatusElegido, sucursal, forma], filtrar);

const varianteEstatus = (e: string) =>
    (
        ({
            borrador: 'secondary',
            en_revision_rh: 'info',
            en_revision_juridico: 'warning',
            rechazada: 'destructive',
            cerrada: 'success',
            cancelada: 'outline',
        }) as Record<
            string,
            | 'secondary'
            | 'info'
            | 'warning'
            | 'destructive'
            | 'success'
            | 'outline'
        >
    )[e] ?? 'secondary';
</script>

<template>
    <Head title="Solicitudes de baja" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Solicitudes de baja"
            descripcion="Cada baja con su forma de salida, causal, documentos y bitácora. RH valida y cierra; Jurídico revisa las que lo requieren."
            :icono="ClipboardList"
        >
            <Button v-if="puede.tablero" variant="outline" as-child>
                <Link :href="tableroRotacion()"
                    ><BarChart3 class="size-4" />Tablero de rotación</Link
                >
            </Button>
            <Button v-if="puede.solicitar" as-child>
                <Link :href="rutasBajas.create()"
                    ><UserMinus class="size-4" />Solicitar baja</Link
                >
            </Button>
        </CrudPageHeader>

        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
            <div>
                <Label class="mb-1.5 text-xs text-[var(--mrl-texto-suave)]"
                    >Buscar</Label
                >
                <Input
                    v-model="termino"
                    placeholder="Folio, nombre o número de empleado"
                />
            </div>
            <div>
                <Label class="mb-1.5 text-xs text-[var(--mrl-texto-suave)]"
                    >Estatus</Label
                >
                <SelectSimple
                    v-model="estatusElegido"
                    :opciones="props.estatus"
                />
            </div>
            <div>
                <Label class="mb-1.5 text-xs text-[var(--mrl-texto-suave)]"
                    >Sucursal</Label
                >
                <SelectSimple
                    v-model="sucursal"
                    :opciones="sucursales"
                    numerico
                    opcion-vacia="Todas"
                    placeholder="Todas"
                />
            </div>
            <div>
                <Label class="mb-1.5 text-xs text-[var(--mrl-texto-suave)]"
                    >Forma de salida</Label
                >
                <SelectSimple
                    v-model="forma"
                    :opciones="formas"
                    opcion-vacia="Todas"
                    placeholder="Todas"
                />
            </div>
        </div>

        <p
            v-if="!props.bajas.data.length"
            class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-4 py-8 text-center text-sm text-[var(--mrl-texto-suave)]"
        >
            No hay bajas con estos filtros.
        </p>

        <template v-else>
            <div
                class="hidden tabla-scroll rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] md:block"
            >
                <table class="w-full text-left text-sm">
                    <thead
                        class="border-b border-[var(--mrl-borde)] text-xs text-[var(--mrl-texto-suave)]"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Folio</th>
                            <th class="px-4 py-3 font-medium">Colaborador</th>
                            <th class="px-4 py-3 font-medium">Sucursal</th>
                            <th class="px-4 py-3 font-medium">
                                Forma · causal
                            </th>
                            <th class="px-4 py-3 font-medium">Estatus</th>
                            <th class="px-4 py-3 font-medium">
                                Días en espera
                            </th>
                            <th class="px-4 py-3 font-medium">Documentos</th>
                            <th class="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="b in props.bajas.data"
                            :key="b.id"
                            class="border-b border-[var(--mrl-borde)] last:border-0"
                        >
                            <td class="px-4 py-3 font-mono text-xs">
                                {{ b.folio }}
                            </td>
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ b.colaborador }}</p>
                                <p
                                    class="text-xs text-[var(--mrl-texto-suave)]"
                                >
                                    {{ b.numero_empleado ?? '—' }} · Baja
                                    {{ formatearFecha(b.fecha_baja) }}
                                </p>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                {{ b.sucursal ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <p class="flex items-center gap-1.5">
                                    <span
                                        :class="
                                            cn(
                                                'size-2.5 shrink-0 rounded-full',
                                                claseForma(b.forma_token).fondo,
                                            )
                                        "
                                    />
                                    {{ b.forma_etiqueta ?? 'Sin forma' }}
                                </p>
                                <p class="text-[var(--mrl-texto-suave)]">
                                    {{ b.causal ?? 'Causal pendiente' }}
                                </p>
                                <Badge
                                    v-if="b.causal_migrada"
                                    variant="warning"
                                    class="mt-1"
                                    >Causal migrada: revisar</Badge
                                >
                            </td>
                            <td class="px-4 py-3">
                                <Badge :variant="varianteEstatus(b.estatus)">{{
                                    b.estatus_etiqueta
                                }}</Badge>
                            </td>
                            <td class="px-4 py-3 text-xs tabular-nums">
                                {{
                                    b.dias_en_espera === null
                                        ? '—'
                                        : `${b.dias_en_espera} ${b.dias_en_espera === 1 ? 'día' : 'días'}`
                                }}
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <span
                                    v-if="b.documentos_completos"
                                    class="inline-flex items-center gap-1 text-success"
                                    ><CheckCircle2
                                        class="size-4"
                                    />Completos</span
                                >
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1 text-warning"
                                    ><AlertTriangle class="size-4" />Faltan
                                    {{ b.documentos_faltantes }}</span
                                >
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Button size="sm" variant="outline" as-child>
                                    <Link :href="rutasBajas.show.url(b.id)"
                                        >Abrir<ArrowRight class="size-3.5"
                                    /></Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <ul class="flex flex-col gap-3 md:hidden">
                <li v-for="b in props.bajas.data" :key="b.id">
                    <Link
                        :href="rutasBajas.show.url(b.id)"
                        class="block tarjeta-interactiva rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 text-sm"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-xs">{{ b.folio }}</span>
                            <Badge :variant="varianteEstatus(b.estatus)">{{
                                b.estatus_etiqueta
                            }}</Badge>
                        </div>
                        <p class="mt-1 font-medium">{{ b.colaborador }}</p>
                        <p class="text-xs text-[var(--mrl-texto-suave)]">
                            {{ b.sucursal ?? '—' }} ·
                            {{ b.forma_etiqueta ?? 'Sin forma' }} ·
                            {{ b.causal ?? 'Causal pendiente' }}
                        </p>
                        <p class="mt-1 text-xs">
                            {{
                                b.documentos_completos
                                    ? 'Documentos completos'
                                    : `Faltan ${b.documentos_faltantes} documento(s)`
                            }}
                            <template v-if="b.dias_en_espera !== null">
                                · {{ b.dias_en_espera }} días en
                                espera</template
                            >
                        </p>
                    </Link>
                </li>
            </ul>

            <PaginacionTabla :datos="props.bajas" />
        </template>
    </div>
</template>
