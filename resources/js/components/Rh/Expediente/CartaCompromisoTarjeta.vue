<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { FileSignature } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { formatearFecha } from '@/lib/fechas';

/**
 * Carta compromiso de documentos pendientes del colaborador (la más
 * reciente): folio, estado, fecha límite, documentos comprometidos y su
 * PDF. La arma App\Services\Expedientes\CartaCompromisoService::paraExpediente
 * como prop `cartaCompromiso` de la página del expediente.
 */
export type CartaCompromisoExpediente = {
    id: number;
    folio: string | null;
    estado: 'vigente' | 'cumplida' | 'vencida' | 'sustituida';
    estado_etiqueta: string;
    fecha_limite: string;
    dias_restantes: number;
    documentos: { document_type_id: number; nombre: string }[];
    generada_en: string | null;
    cumplida_en: string | null;
    pdf_url: string;
};

const page = usePage<{ cartaCompromiso?: CartaCompromisoExpediente | null }>();
const carta = computed(() => page.props.cartaCompromiso ?? null);

const claseEstado = computed(() => {
    switch (carta.value?.estado) {
        case 'cumplida':
            return 'bg-success-soft text-success';
        case 'vencida':
            return 'bg-danger-soft text-danger';
        case 'vigente':
            return 'bg-warning-soft text-warning';
        default:
            return 'bg-muted text-muted-foreground';
    }
});

const plazo = computed(() => {
    if (!carta.value || carta.value.estado !== 'vigente') {
        return null;
    }

    return carta.value.dias_restantes === 0
        ? 'Vence hoy'
        : `Faltan ${carta.value.dias_restantes} día(s)`;
});
</script>

<template>
    <Card v-if="carta" class="rounded-2xl border-border/60 bg-crema">
        <CardContent class="flex flex-col gap-3 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 items-start gap-3">
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-salvia text-primary"
                    >
                        <FileSignature class="size-4" />
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold">
                            Carta compromiso de documentos
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            Folio {{ carta.folio ?? '—' }} · Fecha límite
                            {{ formatearFecha(carta.fecha_limite) }}
                            <template v-if="plazo"> · {{ plazo }}</template>
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span
                        class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                        :class="claseEstado"
                    >
                        {{ carta.estado_etiqueta }}
                    </span>
                    <Button variant="outline" size="sm" as-child>
                        <a :href="carta.pdf_url" target="_blank" rel="noopener"
                            >Ver PDF</a
                        >
                    </Button>
                </div>
            </div>
            <ul class="flex flex-wrap gap-2">
                <li
                    v-for="documento in carta.documentos"
                    :key="documento.document_type_id"
                    class="rounded-lg border border-border/60 bg-card px-2.5 py-1 text-xs"
                >
                    {{ documento.nombre }}
                </li>
            </ul>
            <p
                v-if="carta.estado === 'vencida'"
                class="text-xs text-muted-foreground"
            >
                El plazo venció con documentos pendientes: en la app solo puede
                subir esos documentos hasta completarlos.
            </p>
        </CardContent>
    </Card>
</template>
