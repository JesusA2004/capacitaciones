<script setup lang="ts">
import { BadgeCheck, Eye, Hourglass, Printer } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { PermisoResumen } from '@/types';

/**
 * Permiso con el formato oficial: el PDF SOLO existe cuando Recursos
 * Humanos ya autorizó (el backend responde 403 antes). Se conservan las tres
 * firmas físicas; aquí solo se previsualiza o se imprime.
 */
const props = defineProps<{
    permiso: PermisoResumen;
    pdfUrl: string;
}>();

const previsualizar = ref(false);

function imprimir() {
    const ventana = window.open(props.pdfUrl, '_blank', 'noopener');
    ventana?.addEventListener('load', () => ventana.print());
}

const fecha = (valor: string | null) =>
    valor
        ? new Date(valor).toLocaleString('es-MX', {
              day: 'numeric',
              month: 'long',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '';
</script>

<template>
    <section class="flex flex-col gap-4 rounded-2xl border border-border/60 bg-card p-5 shadow-sm">
        <div class="flex flex-wrap gap-2">
            <span class="rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">
                {{ permiso.tipo_etiqueta }}
            </span>
            <span class="rounded-full bg-muted px-3 py-1 text-xs font-medium">
                {{ permiso.goce_etiqueta }}
            </span>
            <span
                v-if="permiso.causal_etiqueta"
                class="rounded-full bg-[#e9d6b0]/50 px-3 py-1 text-xs font-medium text-[#754711] dark:bg-[#c9a876]/15 dark:text-[#e9d6b0]"
            >
                Causal: {{ permiso.causal_etiqueta }}
            </span>
            <span v-if="permiso.hora_salida" class="rounded-full bg-muted px-3 py-1 text-xs">
                Sale a las {{ permiso.hora_salida }}
            </span>
            <span v-if="permiso.hora_entrada" class="rounded-full bg-muted px-3 py-1 text-xs">
                Entra a las {{ permiso.hora_entrada }}
            </span>
            <span v-if="permiso.tipo === 'faltar' && permiso.dias" class="rounded-full bg-muted px-3 py-1 text-xs">
                {{ permiso.dias }} día(s)
            </span>
        </div>

        <div
            v-if="permiso.autorizado_por_rh"
            class="flex flex-col gap-3 rounded-xl border border-[#2f5937]/30 bg-[#2f5937]/5 p-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex items-start gap-3">
                <BadgeCheck class="mt-0.5 size-5 shrink-0 text-[#2f5937] dark:text-[#a9d6b1]" />
                <div>
                    <p class="text-sm font-semibold">Autorizado por Recursos Humanos</p>
                    <p class="text-xs text-muted-foreground">
                        {{ permiso.autorizado_por }} · {{ fecha(permiso.autorizado_en) }}
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" @click="previsualizar = true">
                    <Eye class="size-4" /> Previsualizar permiso
                </Button>
                <Button @click="imprimir">
                    <Printer class="size-4" /> Imprimir permiso
                </Button>
            </div>
        </div>

        <div v-else class="flex items-start gap-3 rounded-xl border border-dashed border-border p-4">
            <Hourglass class="mt-0.5 size-5 shrink-0 text-muted-foreground" />
            <p class="text-sm text-muted-foreground">
                El formato oficial de permiso se libera cuando
                <strong class="text-foreground">Recursos Humanos</strong> lo
                autoriza. Antes no se puede descargar ni imprimir.
            </p>
        </div>
    </section>

    <Dialog v-model:open="previsualizar">
        <DialogContent class="h-[90vh] w-[calc(100vw-2rem)] max-w-none gap-2 p-3 sm:w-[min(92vw,900px)]">
            <DialogHeader class="px-2">
                <DialogTitle>Solicitud de permiso</DialogTitle>
            </DialogHeader>
            <iframe :src="pdfUrl" title="Formato de permiso" class="h-full w-full rounded-lg border" />
        </DialogContent>
    </Dialog>
</template>
