<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Download, ReceiptText } from '@lucide/vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Button } from '@/components/ui/button';
import { index as indexPortal, recibos as misRecibos } from '@/routes/portal';
import type { RespuestaPaginada } from '@/types';

/**
 * Recibos de nómina del colaborador (solo emitidos): consulta y descarga
 * del PDF, igual que en la app.
 */
type Recibo = {
    id: number;
    folio: string | null;
    periodo_inicio: string;
    periodo_fin: string;
    fecha_pago: string;
    total_percepciones: string;
    total_deducciones: string;
    neto: string;
    pdf_url: string | null;
    conceptos: {
        tipo: 'percepcion' | 'deduccion';
        concepto: string;
        importe: string;
    }[];
};

defineProps<{ recibos: RespuestaPaginada<Recibo> }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Mi portal', href: indexPortal() },
            { title: 'Mis recibos de nómina', href: misRecibos() },
        ],
    },
});

const dinero = (valor: number | string) =>
    Number(valor).toLocaleString('es-MX', {
        style: 'currency',
        currency: 'MXN',
    });

const fecha = (valor: string) =>
    new Date(`${valor}T12:00:00`).toLocaleDateString('es-MX', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

function irAPagina(pagina: number) {
    router.get(misRecibos.url(), { page: pagina }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Mis recibos de nómina" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Mis recibos de nómina"
            descripcion="Tus recibos de cada quincena. Descárgalos cuando los necesites."
            :icono="ReceiptText"
        />

        <EmptyState
            v-if="recibos.data.length === 0"
            :icono="ReceiptText"
            titulo="Todavía no tienes recibos"
            descripcion="Cuando RH emita tu recibo de la quincena aparecerá aquí y te llegará un aviso."
            class="rounded-2xl border border-dashed border-border/60 bg-card"
        />

        <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article
                v-for="recibo in recibos.data"
                :key="recibo.id"
                class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-5"
            >
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="font-semibold">
                            {{ fecha(recibo.periodo_inicio) }} –
                            {{ fecha(recibo.periodo_fin) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Pago: {{ fecha(recibo.fecha_pago) }} ·
                            {{ recibo.folio }}
                        </p>
                    </div>
                    <p class="text-lg font-bold text-primary">
                        {{ dinero(recibo.neto) }}
                    </p>
                </div>

                <ul class="flex flex-col gap-1 text-sm">
                    <li
                        v-for="(c, i) in recibo.conceptos"
                        :key="i"
                        class="flex justify-between gap-2"
                    >
                        <span class="text-muted-foreground">{{
                            c.concepto
                        }}</span>
                        <span
                            class="tabular-nums"
                            :class="
                                c.tipo === 'deduccion' ? 'text-destructive' : ''
                            "
                            >{{ c.tipo === 'deduccion' ? '−' : ''
                            }}{{ dinero(c.importe) }}</span
                        >
                    </li>
                </ul>

                <Button
                    v-if="recibo.pdf_url"
                    as-child
                    variant="outline"
                    class="mt-auto"
                >
                    <a :href="recibo.pdf_url" target="_blank" rel="noopener">
                        <Download class="size-4" />
                        Descargar PDF
                    </a>
                </Button>
            </article>
        </div>

        <div
            v-if="recibos.last_page > 1"
            class="flex items-center justify-end gap-2"
        >
            <Button
                size="sm"
                variant="outline"
                :disabled="recibos.current_page <= 1"
                @click="irAPagina(recibos.current_page - 1)"
            >
                Anterior
            </Button>
            <Button
                size="sm"
                variant="outline"
                :disabled="recibos.current_page >= recibos.last_page"
                @click="irAPagina(recibos.current_page + 1)"
            >
                Siguiente
            </Button>
        </div>
    </div>
</template>
