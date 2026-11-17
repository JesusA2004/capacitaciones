<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { Layers, Receipt } from '@lucide/vue';
import { ref } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useAlertas } from '@/composables/useAlertas';
import { formatearFecha } from '@/lib/fechas';
import {
    descargar as descargarRecibo,
    regenerarPdf as regenerarPdfReciboRoute,
} from '@/routes/rh/expedientes/recibos-nomina';
import { index as recibosDeNomina } from '@/routes/rh/nomina';
import type {
    ExpedienteColaborador,
    PrestamoItem,
    ReciboNominaItem,
} from '@/types';

/**
 * Recibos de nómina del expediente: solo consulta, descarga y reparación de
 * PDF. Regla vigente (docs/NOMINA_QUINCENAL.md): todo recibo nace como
 * borrador DENTRO de un lote; el alta manual desde el expediente se retiró
 * de la interfaz para no crear recibos sueltos, sin lote ni trazabilidad.
 */
const props = defineProps<{
    colaborador: ExpedienteColaborador;
    /** Solo RH (nomina.recibos.crear) gestiona recibos, nunca el propio. */
    puedeGenerarRecibos?: boolean;
    puedeVerSueldo: boolean;
    /** null = quien mira no puede ver recibos (el colaborador nunca ve los suyos: se entregan impresos). */
    recibosNomina: ReciboNominaItem[] | null;
    prestamos: PrestamoItem[];
}>();

const { mostrarExito, mostrarError } = useAlertas();

function moneda(valor: number): string {
    return valor.toLocaleString('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    });
}

const formRegenerarPdf = useForm({});

const reciboRegenerandoId = ref<number | null>(null);

function regenerarPdfRecibo(reciboId: number) {
    reciboRegenerandoId.value = reciboId;

    formRegenerarPdf.post(regenerarPdfReciboRoute.url(reciboId), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('PDF regenerado correctamente.'),
        onError: () => mostrarError('No fue posible regenerar el PDF.'),
        onFinish: () => (reciboRegenerandoId.value = null),
    });
}

function descargarReciboUrl(reciboId: number): string {
    return descargarRecibo.url(reciboId);
}
</script>

<template>
    <Card class="rounded-2xl border-border/60">
        <CardHeader
            class="flex flex-row flex-wrap items-center justify-between gap-2"
        >
            <CardTitle class="flex items-center gap-2 text-base">
                <Receipt class="size-4" />
                Recibos de nómina
            </CardTitle>
            <Button
                v-if="puedeGenerarRecibos"
                size="sm"
                variant="outline"
                as-child
            >
                <Link
                    :href="
                        recibosDeNomina({
                            query: { colaborador: props.colaborador.id },
                        })
                    "
                >
                    <Layers class="size-4" />
                    Ver en Recibos de nómina
                </Link>
            </Button>
        </CardHeader>
        <CardContent class="flex flex-col gap-2">
            <p
                v-if="puedeGenerarRecibos"
                class="text-sm text-muted-foreground"
            >
                Los recibos se preparan dentro del lote de su periodo en
                «Recibos de nómina»; ahí se revisan, se ajustan y se emiten.
            </p>
            <p
                v-if="puedeVerSueldo && !colaborador.sueldo_mensual"
                class="text-sm text-muted-foreground"
            >
                Captura el sueldo mensual en «Datos laborales» para que entre
                en el siguiente lote de nómina.
            </p>
            <div
                v-for="recibo in recibosNomina ?? []"
                :key="recibo.id"
                class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border/60 p-3 text-sm"
            >
                <div class="min-w-0">
                    <p class="font-medium">
                        {{ recibo.periodo_inicio }} —
                        {{ recibo.periodo_fin }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Pago:
                        {{ formatearFecha(recibo.fecha_pago) }}
                        · Neto:
                        {{ moneda(recibo.neto) }}
                    </p>
                </div>
                <a
                    v-if="recibo.tiene_pdf"
                    :href="descargarReciboUrl(recibo.id)"
                    target="_blank"
                    class="inline-flex items-center gap-1.5 enlace-animado text-sm font-medium text-primary"
                >
                    <Receipt class="size-3.5" />
                    Descargar
                </a>
                <div
                    v-else
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                >
                    <span>Recibo guardado, PDF no disponible.</span>
                    <Button
                        v-if="puedeGenerarRecibos"
                        size="sm"
                        variant="outline"
                        type="button"
                        :disabled="
                            formRegenerarPdf.processing &&
                            reciboRegenerandoId === recibo.id
                        "
                        @click="regenerarPdfRecibo(recibo.id)"
                    >
                        Regenerar PDF
                    </Button>
                </div>
            </div>
            <EmptyState
                v-if="!recibosNomina?.length"
                :icono="Receipt"
                titulo="Sin recibos generados"
                descripcion="Los recibos de nómina de este colaborador aparecerán aquí cuando se emita el lote de su periodo."
            />
        </CardContent>
    </Card>
</template>
