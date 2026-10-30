<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    BarChart3,
    CheckCircle2,
    ClipboardCheck,
    FileWarning,
    FolderOpen,
    Hourglass,
    Lock,
    Undo2,
    UserMinus,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import type { Component } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { parametrosPorPaginaActuales } from '@/composables/usePaginacion';
import { claseForma, estatusBaja } from '@/lib/bajas';
import { formatearFecha } from '@/lib/fechas';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as tableroRotacion } from '@/routes/reportes/rotacion/tablero';
import rutasBajas from '@/routes/rh/bajas';
import { index as cierresIndex } from '@/routes/rh/cierres';
import type { FilaBaja, ResumenBajas } from '@/types';

/**
 * «Bajas»: solo gerentes (su personal), RH y super admin; Jurídico y
 * Contraloría de lectura. La baja es directa: se registra y queda aplicada;
 * aquí se sigue su trámite (documentos, Jurídico, cierre). El alcance lo
 * aplica el backend (BajaConsultaService).
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
    resumen: ResumenBajas;
    puede: {
        solicitar: boolean;
        gestionar: boolean;
        tablero: boolean;
        recopilacion: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Bajas', href: '' },
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
            ...parametrosPorPaginaActuales(),
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

// Flujo sin Jurídico (propuesta Etapa 1): las tres primeras tarjetas son cubos
// de las bajas abiertas (En trámite / Faltan documentos / Listas para cerrar).
// Solo filtran por estatus las que corresponden a un estatus real; «Faltan
// documentos» y «Listas para cerrar» son informativas (filtro null).
const metricas: {
    etiqueta: string;
    campo: keyof ResumenBajas;
    icono: Component;
    tono: 'info' | 'alerta' | 'exito' | 'neutro';
    filtro: string | null;
}[] = [
    {
        etiqueta: 'En trámite',
        campo: 'en_tramite',
        icono: Hourglass,
        tono: 'info',
        filtro: 'en_revision_rh',
    },
    {
        etiqueta: 'Faltan documentos',
        campo: 'faltan_documentos',
        icono: FileWarning,
        tono: 'alerta',
        filtro: null,
    },
    {
        etiqueta: 'Listas para cerrar',
        campo: 'lista_para_cerrar',
        icono: ClipboardCheck,
        tono: 'info',
        filtro: null,
    },
    {
        etiqueta: 'Cerradas',
        campo: 'cerradas',
        icono: Lock,
        tono: 'exito',
        filtro: 'cerrada',
    },
    {
        etiqueta: 'No procede',
        campo: 'no_procede',
        icono: Undo2,
        tono: 'neutro',
        filtro: 'cancelada',
    },
];

function elegirMetrica(filtro: string) {
    estatusElegido.value = estatusElegido.value === filtro ? 'abiertas' : filtro;
}

function avance(b: FilaBaja): string {
    if (b.documentos_total === 0) {
        return 'No exige';
    }

    return `${Math.max(0, b.documentos_total - b.documentos_faltantes)}/${b.documentos_total}`;
}
</script>

<template>
    <Head title="Bajas" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Bajas"
            descripcion="Se registran y quedan aplicadas al momento. Aquí sigues sus documentos y el cierre."
            :icono="UserMinus"
        >
            <Button v-if="puede.recopilacion" variant="outline" as-child>
                <Link :href="cierresIndex()"
                    ><FolderOpen class="size-4" />Documentos y finiquitos</Link
                >
            </Button>
            <Button v-if="puede.tablero" variant="outline" as-child>
                <Link :href="tableroRotacion()"
                    ><BarChart3 class="size-4" />Rotación</Link
                >
            </Button>
            <Button v-if="puede.solicitar" as-child>
                <Link :href="rutasBajas.create()"
                    ><UserMinus class="size-4" />Registrar baja</Link
                >
            </Button>
        </CrudPageHeader>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <TarjetaMetrica
                v-for="m in metricas"
                :key="m.campo"
                :etiqueta="m.etiqueta"
                :valor="resumen[m.campo]"
                :icono="m.icono"
                :tono="m.tono"
                :clicable="m.filtro !== null"
                :activa="m.filtro !== null && estatusElegido === m.filtro"
                @click="m.filtro && elegirMetrica(m.filtro)"
            />
        </div>

        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
            <div>
                <Label class="mb-1.5 text-xs text-muted-foreground"
                    >Buscar</Label
                >
                <Input
                    v-model="termino"
                    placeholder="Nombre, número, folio, puesto o sucursal"
                />
            </div>
            <div>
                <Label class="mb-1.5 text-xs text-muted-foreground"
                    >Estatus</Label
                >
                <SelectSimple
                    v-model="estatusElegido"
                    :opciones="props.estatus"
                />
            </div>
            <div>
                <Label class="mb-1.5 text-xs text-muted-foreground"
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
                <Label class="mb-1.5 text-xs text-muted-foreground"
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
            class="rounded-2xl border border-border/60 bg-card px-4 py-10 text-center text-sm text-muted-foreground"
        >
            No hay bajas con estos filtros.
        </p>

        <template v-else>
            <div
                class="hidden tabla-scroll rounded-2xl border border-border/60 bg-card shadow-sm xl:block"
            >
                <table class="w-full text-left text-sm">
                    <thead
                        class="border-b border-border/60 text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="min-w-56 px-4 py-3 font-medium">
                                Colaborador
                            </th>
                            <th class="px-4 py-3 font-medium">Sucursal</th>
                            <th class="min-w-56 px-4 py-3 font-medium">
                                Forma · causal
                            </th>
                            <th class="px-4 py-3 font-medium">Estatus</th>
                            <th class="px-4 py-3 font-medium">Documentos</th>
                            <th class="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="b in props.bajas.data"
                            :key="b.id"
                            class="border-b border-border/60 last:border-0"
                        >
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ b.colaborador }}</p>
                                <p class="text-xs text-muted-foreground">
                                    <span class="font-mono">{{ b.folio }}</span>
                                    · Último día
                                    {{ formatearFecha(b.fecha_baja) }}
                                </p>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                {{ b.sucursal ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <p
                                    class="flex items-center gap-1.5 font-medium"
                                >
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
                                <p class="text-muted-foreground">
                                    {{ b.causal ?? 'Causal pendiente' }}
                                </p>
                                <ChipEstado
                                    v-if="b.causal_migrada"
                                    class="mt-1"
                                    etiqueta="Causal migrada: revisar"
                                    tono="alerta"
                                />
                            </td>
                            <td class="px-4 py-3">
                                <ChipEstado
                                    :etiqueta="b.estatus_etiqueta"
                                    :tono="estatusBaja(b.estatus).tono"
                                    :icono="estatusBaja(b.estatus).icono"
                                />
                                <p
                                    v-if="b.dias_en_espera !== null"
                                    class="mt-1 text-xs text-muted-foreground tabular-nums"
                                >
                                    {{ b.dias_en_espera }}
                                    {{
                                        b.dias_en_espera === 1 ? 'día' : 'días'
                                    }}
                                </p>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <span
                                    v-if="b.documentos_completos"
                                    class="inline-flex items-center gap-1 font-medium text-success"
                                    ><CheckCircle2 class="size-4" />{{
                                        avance(b)
                                    }}</span
                                >
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1 font-medium text-warning"
                                    ><AlertTriangle class="size-4" />{{
                                        avance(b)
                                    }}</span
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

            <ul class="grid gap-3 md:grid-cols-2 xl:hidden">
                <li v-for="b in props.bajas.data" :key="b.id">
                    <Link
                        :href="rutasBajas.show.url(b.id)"
                        class="flex h-full tarjeta-interactiva flex-col gap-1 rounded-2xl border border-border/60 bg-card p-4 text-sm shadow-sm"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold break-words">
                                    {{ b.colaborador }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    <span class="font-mono">{{ b.folio }}</span>
                                    · {{ b.sucursal ?? '—' }}
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1">
                                <ChipEstado
                                    :etiqueta="b.estatus_etiqueta"
                                    :tono="estatusBaja(b.estatus).tono"
                                    :icono="estatusBaja(b.estatus).icono"
                                />
                                <span
                                    v-if="b.dias_en_espera !== null"
                                    class="text-xs text-muted-foreground tabular-nums"
                                    >{{ b.dias_en_espera }}
                                    {{
                                        b.dias_en_espera === 1 ? 'día' : 'días'
                                    }}</span
                                >
                            </div>
                        </div>
                        <p class="mt-1 flex items-start gap-1.5 text-xs">
                            <span
                                :class="
                                    cn(
                                        'mt-1 size-2.5 shrink-0 rounded-full',
                                        claseForma(b.forma_token).fondo,
                                    )
                                "
                            />
                            <span class="min-w-0"
                                ><span class="font-medium">{{
                                    b.forma_etiqueta ?? 'Sin forma'
                                }}</span>
                                ·
                                {{ b.causal ?? 'Causal pendiente' }}</span
                            >
                        </p>
                        <ChipEstado
                            v-if="b.causal_migrada"
                            class="w-fit"
                            etiqueta="Causal migrada: revisar"
                            tono="alerta"
                        />
                        <div
                            class="mt-auto flex flex-wrap items-center justify-between gap-2 border-t border-border/60 pt-2 text-xs"
                        >
                            <span class="text-muted-foreground"
                                >Último día
                                {{ formatearFecha(b.fecha_baja) }}</span
                            >
                            <span
                                class="inline-flex items-center gap-1 font-medium"
                                :class="
                                    b.documentos_completos
                                        ? 'text-success'
                                        : 'text-warning'
                                "
                                ><CheckCircle2
                                    v-if="b.documentos_completos"
                                    class="size-4"
                                /><AlertTriangle
                                    v-else
                                    class="size-4"
                                />Documentos {{ avance(b) }}</span
                            >
                        </div>
                    </Link>
                </li>
            </ul>

            <PaginacionTabla :datos="props.bajas" />
        </template>
    </div>
</template>
