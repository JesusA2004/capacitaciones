<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    CalendarRange,
    ChevronRight,
    PackageCheck,
    RefreshCw,
} from '@lucide/vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import MonthPicker from '@/components/Common/MonthPicker.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import NumeroAnimado from '@/components/ui/NumeroAnimado.vue';
import { dashboard } from '@/routes';
import { generar, show } from '@/routes/rh/recepcion-documental';
import type {
    CorteRecepcion,
    EstadoCorteRecepcion,
    ReglaResumenRecepcion,
} from '@/types';

const props = defineProps<{
    cortes: {
        data: CorteRecepcion[];
        current_page: number;
        last_page: number;
        per_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    periodoActual: { clave: string; etiqueta: string };
    puedeGenerar: boolean;
    reglas: ReglaResumenRecepcion[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Recepción documental', href: '' },
        ],
    },
});

const form = useForm({ periodo: props.periodoActual.clave });

function calcular() {
    form.post(generar.url(), { preserveScroll: true });
}

const VARIANTE_ESTADO: Record<
    EstadoCorteRecepcion,
    'secondary' | 'info' | 'warning' | 'success' | 'outline'
> = {
    en_preparacion: 'secondary',
    abierto: 'info',
    con_pendientes: 'warning',
    completo: 'success',
    cerrado: 'outline',
};
</script>

<template>
    <Head title="Recepción documental" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Recepción documental RH"
            descripcion="Control mensual de los originales firmados que llegan por paquetería. Lo esperado lo calcula el sistema desde eventos reales: altas, bajas, recibos emitidos, vacaciones y préstamos."
            :icono="PackageCheck"
        />

        <section
            v-if="puedeGenerar"
            class="flex flex-col gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 sm:flex-row sm:items-end"
        >
            <div class="flex flex-col gap-1.5">
                <label for="periodo" class="text-sm font-medium"
                    >Mes a conciliar</label
                >
                <MonthPicker
                    id="periodo"
                    v-model="form.periodo"
                    aria-label="Mes a conciliar"
                    class="w-full sm:w-52"
                />
                <p v-if="form.errors.periodo" class="text-xs text-destructive">
                    {{ form.errors.periodo }}
                </p>
            </div>
            <Button :disabled="form.processing" @click="calcular">
                <RefreshCw
                    class="size-4"
                    :class="{ 'animate-spin': form.processing }"
                />
                Calcular / actualizar corte
            </Button>
            <p class="text-xs text-muted-foreground sm:ml-auto sm:max-w-md">
                Calcular es seguro: nunca duplica ni borra lo ya recibido. El
                corte del mes en curso se actualiza solo cada mañana.
            </p>
        </section>

        <section
            class="overflow-hidden rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]"
        >
            <ul
                v-if="cortes.data.length"
                class="entrada-escalonada divide-y divide-[var(--mrl-borde)]"
            >
                <li v-for="c in cortes.data" :key="c.id">
                    <Link
                        :href="show.url(c.id)"
                        class="group flex fila-interactiva flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3"
                    >
                        <span
                            class="flex min-w-0 flex-1 basis-48 items-center gap-3"
                        >
                            <CalendarRange
                                class="size-5 shrink-0 text-muted-foreground"
                            />
                            <span>
                                <span class="block font-medium">{{
                                    c.etiqueta
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ c.dias }} días · {{ c.fecha_inicio }} al
                                    {{ c.fecha_fin }}</span
                                >
                            </span>
                        </span>
                        <span
                            class="grid w-full grid-cols-4 gap-2 text-center text-xs text-muted-foreground sm:w-auto sm:gap-4"
                        >
                            <span>
                                <span
                                    class="block text-base font-semibold text-foreground tabular-nums"
                                    ><NumeroAnimado
                                        :valor="c.metricas.esperados"
                                /></span>
                                Esperados
                            </span>
                            <span>
                                <span
                                    class="block text-base font-semibold text-success tabular-nums"
                                    ><NumeroAnimado
                                        :valor="c.metricas.recibidos"
                                /></span>
                                Recibidos
                            </span>
                            <span>
                                <span
                                    class="block text-base font-semibold text-warning tabular-nums"
                                    ><NumeroAnimado
                                        :valor="c.metricas.pendientes"
                                /></span>
                                Pendientes
                            </span>
                            <span>
                                <span
                                    class="block text-base font-semibold text-foreground tabular-nums"
                                    ><NumeroAnimado
                                        :valor="c.metricas.cumplimiento"
                                        sufijo="%"
                                /></span>
                                Cumplimiento
                            </span>
                        </span>
                        <Badge :variant="VARIANTE_ESTADO[c.estado]">{{
                            c.estado_etiqueta
                        }}</Badge>
                        <ChevronRight
                            class="hidden size-4 shrink-0 icono-avanza text-muted-foreground sm:block"
                        />
                    </Link>
                </li>
            </ul>
            <EmptyState
                v-else
                :icono="PackageCheck"
                titulo="Todavía no hay cortes"
                :descripcion="
                    puedeGenerar
                        ? 'Elige el mes arriba y presiona «Calcular / actualizar corte» para armar el primero.'
                        : 'Cuando Recursos Humanos calcule el corte de un mes, aparecerá aquí.'
                "
            />
        </section>

        <PaginacionTabla :datos="cortes" />

        <section
            class="rounded-2xl border border-[var(--mrl-borde)] bg-crema/60 p-4 text-sm"
        >
            <h2 class="mb-2 font-medium">Reglas que calculan lo esperado</h2>
            <ul class="grid gap-1.5 sm:grid-cols-2">
                <li
                    v-for="r in reglas"
                    :key="r.clave"
                    class="flex items-start gap-2"
                >
                    <Badge :variant="r.activa ? 'success' : 'secondary'">{{
                        r.activa ? 'Activa' : 'Pendiente de definir'
                    }}</Badge>
                    <span>
                        <span class="font-medium">{{ r.etiqueta }}</span>
                        <span
                            v-if="r.pendiente"
                            class="block text-xs text-muted-foreground"
                            >{{ r.pendiente }}</span
                        >
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>
